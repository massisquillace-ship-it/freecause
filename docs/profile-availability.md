# Public profile availability

The 2026-11-12 investigation found the global `profile_conc 25` pool exhausted
under rotating-IP profile scraping. Normal profile requests received nginx 503
before Laravel. Cloudflare's old broad HTML challenge was disabled. Raising the
origin limit alone would allow the same traffic to overload PHP and MySQL.

## Active policy

- Cloudflare rule `f46e2a5b183841188b77c9430f891db6` is now an enabled
  **profile-only** Managed Challenge. Its expression is recorded in
  `deploy/cloudflare/public-html-challenge.json`. Verified crawlers are excluded
  using Cloudflare's verification, never a user-agent string. Other public
  pages, admin and magazine are outside this rule.
- Origin profile concurrency: **4 per restored client IP**, **40 globally**.
  PHP-FPM currently has 60 workers; this retains a profile-specific ceiling.
- Keep the existing **20 requests/minute/IP**, burst 15, without delay.
- Both limit rejections return **429**, `Retry-After: 30`, and private/no-store.
  Real application errors retain their original status. Limits do not grant
  access, change profile visibility, cache HTML or weaken nickname privacy.
- All already-public profiles remain `index, follow` as released in PR #517.

## Origin installation

These are host configuration files, not application routes. Deploying the
Laravel release does not automatically rewrite nginx. Keep a copy of the
previous vhost and any destination files before installation.

1. Preserve the existing http-context zones in `/etc/nginx/nginx.conf`:
   `profile_scrape` keyed by `$binary_remote_addr` at `20r/m`, and
   `profile_conc` keyed by `$server_name`. Preserve Cloudflare trusted ranges
   and `real_ip_header CF-Connecting-IP`.
2. Install `deploy/nginx/profile-limit-zones.conf` as
   `/etc/nginx/conf.d/freecause-profile-limit-zones.conf` (http context).
3. Install `deploy/nginx/profile-limits.conf` as
   `/etc/nginx/snippets/freecause-profile-limits.conf` (server context).
4. Replace only the old profile location in the canonical vhost with
   `include /etc/nginx/snippets/freecause-profile-limits.conf;`. Keep legacy
   redirects above it. Do not change PHP, WordPress, origin guard, TLS or DNS.
5. Run `nginx -t`; on failure restore the saved files without reloading.
   On success gracefully reload nginx. Confirm the service remains active.

Rollback: restore the saved vhost and destination files, validate and gracefully
reload. The Cloudflare profile challenge may remain active during rollback.
Reverting the Cloudflare rule separately means disabling this specific rule;
do not restore the old whole-site challenge.

## Validation

Run `python3 deploy/nginx/check-profile-limits.py` on a host with nginx.
It starts a separate nginx instance bound to loopback with a synthetic upstream;
it never load-tests the real application. Check per-IP concurrency, the global
ceiling, 30 simultaneous visitors (above the previous global 25), per-IP rate
limiting, retry headers and unaffected non-profile URLs.

After installation check several existing profiles at the origin with the
canonical TLS hostname and local DNS resolution. Public requests from a raw
HTTP client may receive Cloudflare's challenge (403 with `cf-mitigated:
challenge`), which is distinct from an origin 503. Check a browser with normal
challenge handling, other page families, and recent nginx access/error logs.
Do not spoof Googlebot to claim verified-crawler coverage. Monitor rejection
rates and resource use before considering further capacity changes.

References: [nginx connection limits](https://nginx.org/en/docs/http/ngx_http_limit_conn_module.html),
[nginx request limits](https://nginx.org/en/docs/http/ngx_http_limit_req_module.html).
