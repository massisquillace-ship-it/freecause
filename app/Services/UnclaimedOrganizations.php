<?php

namespace App\Services;

use App\Jobs\PurgeOrganizationAttribution;
use App\Models\Cause;
use App\Models\Organization;
use App\Models\Petition;
use App\Support\Countries;
use Database\Seeders\OrganizationCauseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UnclaimedOrganizations
{
    public static function key(string $name, string $country): string
    {
        $name = preg_replace('/\s+/u', ' ', $name);

        return hash('sha256', mb_strtolower(trim($name)).'|'.strtoupper(trim($country)));
    }

    /** Only explicit, staff-prepared records; never infer identity from petition text. */
    public function create(array $input, string $source, ?int $actor = null, bool $dryRun = false): array
    {
        $data = Validator::make($input, [
            'name' => ['required', 'string', 'max:120', 'regex:/^[^\p{Cc}\p{Cs}]+$/u'],
            'country' => ['required', Rule::in(Countries::codes())],
            'causes' => ['required', 'array', 'min:1', 'max:3'],
            'causes.*' => ['required', 'string', 'distinct', Rule::in(Cause::whereIn('slug', array_keys(OrganizationCauseSeeder::CAUSES))->pluck('slug')->all())],
            'petition_ids' => ['present', 'array', 'max:200'],
            'petition_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
        ])->validate();
        if (! in_array($source, ['admin', 'petition_import'], true)) {
            throw new \InvalidArgumentException('Unsupported organization source.');
        }
        $key = self::key($data['name'], $data['country']);
        try {
            return DB::transaction(function () use ($data, $key, $source, $actor, $dryRun) {
                // Also recognize older application rows without changing/backfilling them.
                $existing = Organization::where('country', $data['country'])->get(['id', 'name', 'country'])
                    ->first(fn ($org) => self::key($org->name, $org->country) === $key);
                if ($existing) {
                    return ['created' => false, 'organization' => $existing];
                }
                $petitions = Petition::visible()->whereIn('id', $data['petition_ids'])->orderBy('id')->lockForUpdate()->get();
                if ($petitions->count() !== count($data['petition_ids']) || $petitions->contains(fn ($petition) => $petition->organization_id !== null)) {
                    throw ValidationException::withMessages(['petition_ids' => __('organizations.import_petitions_invalid')]);
                }
                if ($dryRun) {
                    return ['created' => true, 'organization' => null];
                }
                do {
                    $slug = substr(Str::slug($data['name']) ?: 'organization', 0, 60).'-'.Str::lower(Str::random(8));
                } while (Organization::where('slug', $slug)->exists());
                $org = Organization::create([
                    'name' => trim(preg_replace('/\s+/u', ' ', $data['name'])), 'country' => $data['country'],
                    'slug' => $slug, 'type' => 'other', 'verification_reference' => '',
                    'status' => 'unclaimed', 'source' => $source, 'catalog_key' => $key,
                ]);
                $org->causes()->sync(Cause::whereIn('slug', $data['causes'])->pluck('id'));
                foreach ($petitions as $petition) {
                    // Attribution only: personal author, signatures and their consent remain unchanged.
                    $petition->organization_id = $org->id;
                    $petition->save();
                    PurgeOrganizationAttribution::dispatch($org->id, $petition->id)->afterCommit();
                }
                app(OrganizationLifecycle::class)->record($org, $actor, 'unclaimed_created', ['source' => $source, 'petition_ids' => $data['petition_ids']]);

                return ['created' => true, 'organization' => $org];
            }, 3);
        } catch (QueryException $exception) {
            // A concurrent import may have committed the same unique catalog key.
            if ($existing = Organization::where('catalog_key', $key)->first()) {
                return ['created' => false, 'organization' => $existing];
            }
            throw $exception;
        }
    }
}
