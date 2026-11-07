<?php

namespace App\Models;

use App\Support\Settings;
use Illuminate\Database\Eloquent\Model;

class Organization extends Model
{
    public const TYPES = ['ngo', 'association', 'foundation', 'committee', 'union', 'other'];

    public const STATES = ['pending', 'verified', 'rejected', 'suspended'];

    public const RESERVED_SLUGS = ['apply', 'terms', 'explore', 'manage', 'admin', 'new', 'edit'];

    public const IDENTITY_FIELDS = ['slug', 'name', 'legal_name', 'type', 'country'];

    protected $guarded = ['id'];

    protected $hidden = ['verification_reference', 'verification_note', 'rejection_reason', 'suspension_reason', 'terms_accepted_at', 'representation_confirmed_at'];

    protected $casts = [
        'social_links' => 'array', 'tagline' => 'array', 'description' => 'array',
        'founding_partner' => 'boolean', 'verified_at' => 'datetime',
        'donation_url_approved_at' => 'datetime', 'terms_accepted_at' => 'datetime',
        'representation_confirmed_at' => 'datetime',
    ];

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function causes()
    {
        return $this->belongsToMany(Cause::class, 'cause_organization');
    }

    public function events()
    {
        return $this->hasMany(OrganizationEvent::class);
    }

    public function scopePublic($query)
    {
        return $query->where('status', 'verified')->whereNotNull('owner_user_id');
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
