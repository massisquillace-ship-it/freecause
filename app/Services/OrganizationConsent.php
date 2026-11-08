<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\Petition;
use App\Models\Signature;
use App\Support\Locale;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;

class OrganizationConsent
{
    public const VERSION = '2026-11';

    public static function presentation(Petition $petition, string $locale, array $previous = []): ?array
    {
        if (! config('organizations.consent_enabled') || ! in_array($locale, Locale::supported(), true)) {
            return null;
        }
        $org = $petition->organization;
        if (! $org || $org->status !== 'verified' || ! $org->owner_user_id) {
            return null;
        }

        $selected = false;
        if (! empty($previous['organization_optin']) && is_string($previous['organization_consent_proof'] ?? null)) {
            try {
                $prior = json_decode(Crypt::decryptString($previous['organization_consent_proof']), true, 16, JSON_THROW_ON_ERROR);
                $selected = ($prior['petition'] ?? null) === $petition->id && ($prior['organization'] ?? null) === $org->id
                    && ($prior['name'] ?? null) === $org->name && ($prior['version'] ?? null) === self::VERSION
                    && ($prior['locale'] ?? null) === $locale && ($prior['expires'] ?? 0) >= now()->timestamp;
            } catch (\Throwable) {
                // A stale/tampered choice is never silently applied to a different recipient.
            }
        }

        return ['organization' => $org, 'selected' => $selected, 'proof' => Crypt::encryptString(json_encode([
            'petition' => $petition->id, 'organization' => $org->id, 'name' => $org->name,
            'version' => self::VERSION, 'locale' => $locale, 'expires' => now()->addHours(2)->timestamp,
        ], JSON_THROW_ON_ERROR))];
    }

    /** Only invoked for a newly created signature; historical evidence is never overwritten. */
    public static function record(Signature $signature, Request $request, Petition $petition, string $locale): void
    {
        if (! config('organizations.consent_enabled') || ! $request->filled('organization_consent_proof')) {
            return;
        }
        try {
            $proof = json_decode(Crypt::decryptString($request->string('organization_consent_proof')->toString()), true, 16, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return;
        }
        if (($proof['petition'] ?? null) !== $petition->id || ($proof['version'] ?? null) !== self::VERSION
            || ($proof['locale'] ?? null) !== $locale || ! in_array($locale, Locale::supported(), true)
            || ($proof['expires'] ?? 0) < now()->timestamp) {
            return;
        }
        DB::transaction(function () use ($proof, $signature, $request, $petition, $locale) {
            // Same org -> petition lock order as attribution changes. Recheck both at submit time.
            $org = Organization::verified()->whereKey($proof['organization'] ?? 0)->lockForUpdate()->first();
            $current = Petition::whereKey($petition->id)->lockForUpdate()->first();
            if (! $org || $org->name !== ($proof['name'] ?? '') || ! $current
                || (int) $current->organization_id !== (int) $org->id || $current->status !== 'published' || ! $current->is_active) {
                return;
            }
            $accepted = $request->boolean('organization_optin');
            $raw = $accepted ? bin2hex(random_bytes(32)) : null;
            $signature->forceFill([
                'org_optin' => $accepted, 'org_optin_at' => $accepted ? now() : null,
                'org_optin_text_version' => self::VERSION, 'org_optin_locale' => in_array($locale, ['en', 'fr', 'it'], true) ? $locale : 'en',
                'org_optin_organization_id' => $org->id,
                'org_optin_revoke_token' => $raw ? hash('sha256', $raw) : null,
            ])->save();
            if ($raw) {
                $links = $request->attributes->get('organization_consent_links', []);
                $links[] = ['name' => $org->name, 'url' => URL::signedRoute('organizations.consent.revoke', ['locale' => $locale, 'token' => $raw])];
                $request->attributes->set('organization_consent_links', $links);
                // The raw capability exists only in this confirmation/session and its queued mail, never in database evidence.
                session()->flash('organization_consent_links', $links);
            }
        }, 3);
    }
}
