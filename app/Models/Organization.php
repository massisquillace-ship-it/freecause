<?php

namespace App\Models;

use App\Jobs\PurgeOrganizationAttribution;
use App\Services\UnclaimedOrganizations;
use App\Support\CacheVersion;
use App\Support\Settings;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class Organization extends Model
{
    public const TYPES = ['ngo', 'association', 'foundation', 'committee', 'union', 'other'];

    public const STATES = ['unclaimed', 'pending', 'verified', 'rejected', 'suspended'];

    public const RESERVED_SLUGS = ['apply', 'terms', 'explore', 'manage', 'admin', 'new', 'edit', 'claim-search'];

    public const IDENTITY_FIELDS = ['slug', 'name', 'legal_name', 'type', 'country'];

    protected $guarded = ['id'];

    protected $hidden = ['verification_reference', 'verification_note', 'rejection_reason', 'suspension_reason', 'terms_accepted_at', 'representation_confirmed_at', 'claimed_by_user_id', 'catalog_key'];

    protected $casts = [
        'social_links' => 'array', 'tagline' => 'array', 'description' => 'array',
        'founding_partner' => 'boolean', 'verified_at' => 'datetime',
        'donation_url_approved_at' => 'datetime', 'terms_accepted_at' => 'datetime',
        'representation_confirmed_at' => 'datetime', 'claimed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saving(function (Organization $org) {
            if ($org->catalog_key && $org->isDirty(['name', 'country'])) {
                $org->catalog_key = UnclaimedOrganizations::key($org->name, $org->country);
            }
        });
        static::saved(function (Organization $org) {
            DB::afterCommit(fn () => CacheVersion::bumpSitemap());
            if ($org->wasChanged(['status', 'owner_user_id', 'name', 'logo_path', 'slug'])) {
                PurgeOrganizationAttribution::dispatch($org->id);
            }
        });
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function causes()
    {
        return $this->belongsToMany(Cause::class, 'cause_organization');
    }

    public function petitions()
    {
        return $this->hasMany(Petition::class);
    }

    public function mainCampaign()
    {
        return $this->hasOne(Petition::class)->ofMany(['signature_count' => 'max', 'id' => 'max'], fn ($q) => $q->visible());
    }

    // Read only denormalized counters; never scan raw signatures on public pages.
    public function scopeWithCampaignTotals($query)
    {
        return $query->addSelect(['signatures_total' => Petition::visible()
            ->selectRaw('COALESCE(SUM(signature_count), 0)')
            ->whereColumn('organization_id', 'organizations.id')])
            ->withCount(['petitions as public_petitions_count' => fn ($q) => $q->visible(), 'petitions as victories_count' => fn ($q) => $q->visible()->where('outcome', 'victory')]);
    }

    public function events()
    {
        return $this->hasMany(OrganizationEvent::class);
    }

    public function scopePublic($query)
    {
        return $query->where(fn ($q) => $q->where(fn ($verified) => $verified->where('status', 'verified')->whereNotNull('owner_user_id'))
            ->orWhere(fn ($unclaimed) => $unclaimed->where('status', 'unclaimed')->whereNull('owner_user_id')));
    }

    public function scopeVerified($query)
    {
        return $query->where('status', 'verified')->whereNotNull('owner_user_id');
    }

    public static function directoryReady(): bool
    {
        try {
            $attributes = request()->attributes;
            if (! $attributes->has('organization_directory_count')) {
                $attributes->set('organization_directory_count', (int) Cache::remember('organizations:directory-count:'.CacheVersion::get('sitemap'), 300, fn () => self::public()->count()));
            }

            return $attributes->get('organization_directory_count') >= max(1, (int) config('organizations.min_directory_count', 20));
        } catch (QueryException) {
            // Error pages must still render when the database is unavailable.
            return false;
        }
    }

    public function localized(string $field, ?string $locale = null): string
    {
        $values = $this->getAttribute($field) ?? [];
        foreach (array_unique([$locale ?? app()->getLocale(), 'en', ...array_keys($values)]) as $language) {
            if (is_string($values[$language] ?? null) && trim($values[$language]) !== '') {
                return $values[$language];
            }
        }

        return '';
    }

    public static function supportEmail(): string
    {
        return (string) (config('organizations.support_email') ?: Settings::get('email_to_staff', '', 'global') ?: config('mail.from.address'));
    }

    public function hasApprovedDonation(): bool
    {
        return $this->status === 'verified' && $this->owner_user_id !== null && filled($this->donation_url) && $this->donation_url_approved_at !== null;
    }
}
