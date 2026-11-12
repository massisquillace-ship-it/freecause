"""Exercise the committed profile limits against an isolated, loopback-only nginx."""

import concurrent.futures
import http.server
import pathlib
import shutil
import socket
import subprocess
import tempfile
import threading
import time
import urllib.error
import urllib.request


class Upstream(http.server.BaseHTTPRequestHandler):
    def do_GET(self):
        time.sleep(0.3)
        self.send_response(200)
        self.send_header("Content-Length", "2")
        self.end_headers()
        self.wfile.write(b"OK")

    def log_message(self, *args):
        pass


def main():
    nginx = shutil.which("nginx")
    if not nginx:
        raise SystemExit("nginx is required")
    source = pathlib.Path(__file__).resolve().parent
    backend = http.server.ThreadingHTTPServer(("127.0.0.1", 0), Upstream)
    backend.daemon_threads = True
    threading.Thread(target=backend.serve_forever, daemon=True).start()
    with tempfile.TemporaryDirectory(prefix="freecause-profile-limits-") as directory:
        root = pathlib.Path(directory)
        with socket.socket() as reservation:
            reservation.bind(("127.0.0.1", 0))
            port = reservation.getsockname()[1]
        config = root / "nginx.conf"
        config.write_text(f"""
worker_processes 1;
pid {root}/nginx.pid;
error_log {root}/error.log;
events {{ worker_connections 512; }}
http {{
    access_log off;
    client_body_temp_path {root}/body;
    proxy_temp_path {root}/proxy;
    fastcgi_temp_path {root}/fastcgi;
    uwsgi_temp_path {root}/uwsgi;
    scgi_temp_path {root}/scgi;
    limit_req_zone $binary_remote_addr zone=profile_scrape:10m rate=20r/m;
    limit_conn_zone $server_name zone=profile_conc:10m;
    include {source}/profile-limit-zones.conf;
    server {{
        listen 127.0.0.1:{port};
        server_name profile-test;
        root {root}/empty;
        # Test fixture only. No client-controlled identity header is installed live.
        set_real_ip_from 127.0.0.1;
        real_ip_header X-Test-IP;
        include {source}/profile-limits.conf;
        location / {{ proxy_pass http://127.0.0.1:{backend.server_port}; }}
    }}
}}
""")
        subprocess.run([nginx, "-t", "-p", str(root), "-c", str(config)], check=True)
        subprocess.run([nginx, "-p", str(root), "-c", str(config)], check=True)

        def request(ip, path="/en/user/test/1"):
            req = urllib.request.Request(
                f"http://127.0.0.1:{port}{path}", headers={"X-Test-IP": ip}
            )
            try:
                response = urllib.request.urlopen(req, timeout=10)
            except urllib.error.HTTPError as error:
                response = error
            with response:
                response.read()
                if response.status == 429:
                    assert response.headers.get("Retry-After") == "30"
                    assert response.headers.get("Cache-Control") == "private, no-store"
                assert response.status != 503
                return response.status

        def parallel(addresses):
            barrier = threading.Barrier(len(addresses))

            def visit(address):
                barrier.wait(timeout=10)
                return request(address)

            with concurrent.futures.ThreadPoolExecutor(max_workers=len(addresses)) as pool:
                return list(pool.map(visit, addresses))

        try:
            for i, locale in enumerate(["en", "it", "fr", "nl"]):
                assert request(f"192.0.2.{i + 1}", f"/{locale}/user/test/1") == 200
            statuses = parallel(["192.0.2.20"] * 6)
            assert statuses.count(200) == 4 and statuses.count(429) == 2, statuses
            assert request("192.0.2.21") == 200
            statuses = parallel([f"198.51.100.{i + 1}" for i in range(30)])
            assert statuses == [200] * 30, statuses
            statuses = parallel([f"203.0.113.{i + 1}" for i in range(48)])
            assert statuses.count(200) == 40 and statuses.count(429) == 8, statuses
            statuses = [request("192.0.2.30") for _ in range(25)]
            assert 200 in statuses and 429 in statuses, statuses
            # Profile abuse must not consume the visitor's non-profile allowance.
            for path in ["/en", "/en/petitions", "/en/organizations", "/magazine/"]:
                assert request("192.0.2.30", path) == 200
            print("PASS: locale routes, per-IP isolation, 30 visitors, global cap 40, rate limit, retry headers, unrelated paths")
        finally:
            subprocess.run([nginx, "-s", "quit", "-p", str(root), "-c", str(config)], check=True)
            deadline = time.monotonic() + 5
            while (root / "nginx.pid").exists() and time.monotonic() < deadline:
                time.sleep(0.05)
            backend.shutdown()


if __name__ == "__main__":
    main()
