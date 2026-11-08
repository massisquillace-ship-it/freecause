<?php

namespace App\Console\Commands;

use App\Services\UnclaimedOrganizations;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

class ImportUnclaimedOrganizations extends Command
{
    protected $signature = 'organizations:import-unclaimed {csv} {--dry-run}';

    protected $description = 'Import explicit staff-prepared organization records without inferring identities';

    public function handle(UnclaimedOrganizations $importer): int
    {
        $handle = @fopen($this->argument('csv'), 'r');
        if (! $handle) {
            $this->error('CSV could not be opened.');

            return self::FAILURE;
        }
        $created = $skipped = $errors = 0;
        $seen = [];
        try {
            $header = fgetcsv($handle, 0, ',', '"', '');
            if ($header) {
                $header[0] = ltrim($header[0], "\xEF\xBB\xBF");
            }
            if ($header !== ['name', 'country', 'causes', 'petition_ids']) {
                $this->error('Expected CSV header: name,country,causes,petition_ids');

                return self::FAILURE;
            }
            $line = 1;
            while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
                $line++;
                if ($row === [null]) {
                    continue;
                }
                if (count($row) !== 4) {
                    $errors++;
                    $this->error('Malformed row '.$line.': expected four fields.');

                    continue;
                }
                if (! mb_check_encoding(implode('', $row), 'UTF-8')) {
                    $errors++;
                    $this->error('Invalid row '.$line.': expected UTF-8 text.');

                    continue;
                }
                [$name, $country, $causes, $ids] = array_map('trim', $row);
                $country = strtoupper($country);
                $key = UnclaimedOrganizations::key($name, $country);
                if (isset($seen[$key])) {
                    $skipped++;

                    continue;
                }
                try {
                    $result = $importer->create([
                        'name' => $name, 'country' => $country,
                        'causes' => preg_split('/[|;,\s]+/', $causes, -1, PREG_SPLIT_NO_EMPTY),
                        'petition_ids' => preg_split('/[|;,\s]+/', $ids, -1, PREG_SPLIT_NO_EMPTY),
                    ], 'petition_import', dryRun: (bool) $this->option('dry-run'));
                    $seen[$key] = true;
                    $result['created'] ? $created++ : $skipped++;
                } catch (ValidationException $exception) {
                    $errors++;
                    $this->error('Invalid row '.$line.': '.implode(' ', $exception->validator->errors()->all()));
                }
            }
        } finally {
            fclose($handle);
        }
        $this->info(($this->option('dry-run') ? 'Dry run — ' : '')."Created: {$created}; skipped: {$skipped}; invalid: {$errors}.");

        return $errors ? self::FAILURE : self::SUCCESS;
    }
}
