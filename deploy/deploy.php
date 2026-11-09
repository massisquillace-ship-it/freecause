<?php

namespace Deployer;

require 'recipe/laravel.php';

set('application', 'freecause');
set('repository', 'https://github.com/Abdulzizi/freecause.git');
set('keep_releases', 5);
set('ssh_multiplexing', true);
set('git_tty', false);
set('default_timeout', 900);

set('bin/php', 'php8.3');
set('php_fpm_service', 'php8.3-fpm');

set('shared_files', ['.env']);
set('shared_dirs', ['storage']);
add('shared_files', [
    'public/magazine/wp-config.php',
    'public/magazine/.htaccess',
]);
add('shared_dirs', [
    'public/magazine/wp-content/uploads',
    'public/magazine/wp-content/cache',
    'public/magazine/wp-content/upgrade',
    'public/magazine/wp-content/upgrade-temp-backup',
    'public/magazine/wp-content/updraft',
    'public/magazine/wp-content/updraft_backup',
]);

set('writable_dirs', ['bootstrap/cache', 'storage']);
set('manage_services', true);

host('production')
    ->set('hostname', getenv('DEPLOY_HOST') ?: '')
    ->set('remote_user', getenv('DEPLOY_USER') ?: 'root')
    ->set('deploy_path', getenv('DEPLOY_PATH') ?: '/var/www/freecause')
    ->set('identity_file', getenv('DEPLOY_IDENTITY') ?: '~/.ssh/id_deploy')
    ->set('labels', ['stage' => 'production']);

host('rehearsal')
    ->set('hostname', getenv('DEPLOY_HOST') ?: '')
    ->set('remote_user', getenv('DEPLOY_USER') ?: 'root')
    ->set('deploy_path', getenv('REHEARSAL_PATH') ?: '/var/www/freecause-rehearsal')
    ->set('identity_file', getenv('DEPLOY_IDENTITY') ?: '~/.ssh/id_deploy')
    ->set('manage_services', false)
    ->set('labels', ['stage' => 'rehearsal']);

desc('Upload the Vite build produced in CI');
task('deploy:assets', function () {
    // rsync copies the source dir into the destination as its basename, so
    // target public/ (exists) to land files at public/build/.
    upload('public/build', '{{release_path}}/public');
});
after('deploy:update_code', 'deploy:assets');

desc('Record the deployed version for /version');
task('deploy:version', function () {
    // Stamp the ref actually deployed, not the mirror's HEAD (= default branch):
    // a deploy while main has moved made the smoke check roll back good code.
    $commit = run('cd {{deploy_path}}/.dep/repo && git rev-parse --short {{target}}^{commit}');
    // Deploys target a commit (--revision), so label the release with its tag
    // when the commit carries one, otherwise with the branch that shipped it.
    $tag = trim((string) run('cd {{deploy_path}}/.dep/repo && git describe --tags --exact-match {{target}}^{commit} 2>/dev/null || true'));
    $label = $tag !== '' ? $tag : 'main';
    run('{{bin/php}} {{bin/artisan}} app:stamp-version "'.$label.'" --commit="'.$commit.'"');
});
after('deploy:vendors', 'deploy:version');

desc('Cache config and routes; never wipe the shared compiled views');
task('artisan:optimize', function () {
    // Laravel's optimize runs view:cache, which calls view:clear first. The
    // compiled view directory lives in shared/storage, so clearing it deletes
    // files the live release is still including mid-request -> "File does not
    // exist at path .../views/*.blade.php" 500s during every deploy.
    run('{{bin/php}} {{bin/artisan}} config:cache');
    run('{{bin/php}} {{bin/artisan}} route:cache || true');
});

desc('Run migrations with enough time for production-sized indexes');
task('artisan:migrate', function () {
    if (! test('[ -s {{release_or_current_path}}/.env ]')) {
        warning('The environment file is missing; migrations skipped.');

        return;
    }
    run('{{bin/php}} {{bin/artisan}} migrate --force', timeout: 3600);
});

desc('Warm the application caches');
task('cache:warm', function () {
    run('{{bin/php}} {{bin/artisan}} cache:warm');
});

desc('Restart the queue workers');
task('queue:restart', function () {
    if (! get('manage_services')) {
        writeln('manage_services=false: skipping queue restart');

        return;
    }
    run('{{bin/php}} {{bin/artisan}} queue:restart');
});

desc('Reload PHP-FPM, resetting opcache');
task('php-fpm:reload', function () {
    if (! get('manage_services')) {
        writeln('manage_services=false: skipping PHP-FPM reload');

        return;
    }
    run('sudo systemctl reload {{php_fpm_service}}');
});

desc('Install/refresh the Laravel scheduler supervisor program (schedule:work)');
task('scheduler:sync', function () {
    if (! get('manage_services')) {
        writeln('manage_services=false: skipping scheduler sync');

        return;
    }
    // Point supervisor at the committed conf (runs schedule:work from current/).
    // Replaces the legacy per-minute `schedule:run` root crontab that pointed at
    // the retired in-place checkout. Idempotent: cp + reread/update + restart.
    run('sudo cp {{release_path}}/deploy/supervisor/freecause-release-scheduler.conf /etc/supervisor/conf.d/freecause-release-scheduler.conf');
    run('sudo supervisorctl reread && sudo supervisorctl update');
    run('sudo supervisorctl restart freecause-release-scheduler || true');
    // Drop the stale schedule:run cron line if it is still present.
    run('crontab -l 2>/dev/null | grep -v "freecause-release-v2026.09.19/artisan schedule:run" | crontab - || true');
});

desc('Restart the scheduler supervisor program');
task('scheduler:restart', function () {
    if (! get('manage_services')) {
        writeln('manage_services=false: skipping scheduler restart');

        return;
    }
    run('sudo supervisorctl restart freecause-release-scheduler');
});

desc('Snapshot the database before migrating (recovery point for schema deploys)');
task('db:snapshot', function () {
    // Self-hosted MySQL on the deploy host. Dump to shared/ (persists across
    // releases) BEFORE any ALTER runs, so a failed/locking migration on the
    // large users table can be restored. Reads creds from the deployed .env.
    $envPath = '{{deploy_path}}/shared/.env';

    // Dumping the ~18M-row DB takes ~7 min of silent SSH, which the connection
    // cannot survive; skip entirely when there is nothing to migrate.
    $status = run('cd {{release_path}} && {{bin/php}} {{bin/artisan}} migrate:status --pending --no-ansi 2>&1 || true');
    if (str_contains($status, 'No pending migrations')) {
        writeln('<comment>db:snapshot: no pending migrations; skipping mysqldump.</comment>');

        return;
    }

    if (! test("[ -f {$envPath} ]")) {
        invoke('artisan:down');
        writeln('<comment>db:snapshot: no shared/.env yet (first deploy?); skipping.</comment>');

        return;
    }

    $get = function (string $key) use ($envPath): string {
        // Last matching key wins; strip quotes/CR.
        $raw = run("grep -E '^{$key}=' {$envPath} | tail -n1 | cut -d= -f2- || true");

        return trim($raw, " \t\r\n\"'");
    };

    $conn = $get('DB_CONNECTION');
    if ($conn !== '' && $conn !== 'mysql' && $conn !== 'mariadb') {
        invoke('artisan:down');
        writeln("<comment>db:snapshot: DB_CONNECTION={$conn} is not MySQL; skipping mysqldump.</comment>");

        return;
    }

    $host = $get('DB_HOST') ?: '127.0.0.1';
    $port = $get('DB_PORT') ?: '3306';
    $db = $get('DB_DATABASE');
    $user = $get('DB_USERNAME');
    $pass = $get('DB_PASSWORD');

    if ($db === '' || $user === '') {
        invoke('artisan:down');
        writeln('<comment>db:snapshot: DB_DATABASE/DB_USERNAME missing; skipping.</comment>');

        return;
    }

    $dir = '{{deploy_path}}/shared/db-snapshots';
    run("mkdir -p {$dir}");

    // Require at least 2 GB free before dumping an ~18M-row table.
    $freeKb = (int) run("df -Pk {$dir} | awk 'NR==2 {print \$4}'");
    if ($freeKb > 0 && $freeKb < 2 * 1024 * 1024) {
        throw new \RuntimeException("db:snapshot: only {$freeKb} KB free under {$dir}; refusing to dump. Free space or snapshot out of band.");
    }

    $stamp = date('Ymd-His');
    $file = "{$dir}/pre-migrate-{$stamp}.sql.gz";

    // MYSQL_PWD keeps the password out of mysqldump's argv; %dbpass% is filled
    // in by Deployer's secrets mechanism after logging, so a failing command is
    // echoed into CI logs with the placeholder, not the value (run 38713948217
    // printed it verbatim). pipefail so a mysqldump error cannot be masked by
    // gzip's exit 0. single-transaction keeps it non-locking on InnoDB.
    $portOpt = $port !== '' ? "--port={$port}" : '';
    // An InnoDB single-transaction snapshot does not need a quiet application.
    // Keep non-transactional databases offline, but avoid a multi-minute 503
    // while copying a fully transactional database before an additive migration.
    $nonTransactional = trim(run(sprintf(
        'MYSQL_PWD=%%dbpass%% mysql -N -B -h %s %s -u %s %s -e %s',
        escapeshellarg($host), $portOpt, escapeshellarg($user), escapeshellarg($db),
        escapeshellarg("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND ENGINE IS NOT NULL AND ENGINE <> 'InnoDB'")
    ), secrets: ['dbpass' => escapeshellarg($pass)]));
    if (! ctype_digit($nonTransactional)) {
        throw new \RuntimeException('db:snapshot: cannot verify transactional storage engines.');
    }
    $onlineSnapshot = (int) $nonTransactional === 0;
    if (! $onlineSnapshot) {
        invoke('artisan:down');
    }
    run(sprintf(
        'set -o pipefail; MYSQL_PWD=%%dbpass%% mysqldump --single-transaction --quick --no-tablespaces -h %s %s -u %s %s | gzip > %s',
        escapeshellarg($host),
        $portOpt,
        escapeshellarg($user),
        escapeshellarg($db),
        $file
    ), secrets: ['dbpass' => escapeshellarg($pass)]);

    // Retain the last 7 snapshots.
    run("ls -1t {$dir}/pre-migrate-*.sql.gz 2>/dev/null | tail -n +8 | xargs -r rm -f");

    writeln("<info>db:snapshot: wrote {$file}</info>");
    if ($onlineSnapshot) {
        // Quiesce only for schema changes and the release switch, after the
        // consistent recovery point has finished copying successfully.
        invoke('artisan:down');
    }
});

before('artisan:migrate', 'db:snapshot');
after('deploy:symlink', 'artisan:up');
after('deploy:symlink', 'cache:warm');
after('deploy:symlink', 'queue:restart');
after('deploy:symlink', 'scheduler:sync');
after('deploy:symlink', 'php-fpm:reload');
after('rollback', 'queue:restart');
after('rollback', 'scheduler:restart');

after('deploy:failed', 'artisan:up');
after('deploy:failed', 'deploy:unlock');
