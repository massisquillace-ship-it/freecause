<?php

namespace App\Models;

use App\Support\CacheVersion;
use App\Support\Locale;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class User extends Authenticatable implements HasLocalePreference
{
    use HasFactory, Notifiable;

    protected static function booted(): void
    {
        static::created(fn (self $user) => Cache::forget('level:user_counts'));
        static::updated(function (self $user) {
            if ($user->wasChanged(['identify_mode', 'nickname', 'first_name', 'last_name', 'name'])) {
                CacheVersion::bumpListings();
            }
            if ($user->wasChanged('level_id')) {
                Cache::forget('level:user_counts');
            }
        });
        static::deleting(function (self $user) {
            Signature::where('user_id', $user->id)->update(['name' => '', 'email' => null, 'ip_address' => null]);
        });
        static::deleted(function (self $user) {
            if ($user->level_id) {
                Cache::forget('level:user_counts');
            }
        });
    }

    public const GENDERS = ['male', 'female', 'prefer_not_to_say', 'none'];

    /**
     * Legacy nickname-only registrations were migrated with a generated
     * <nick>@internetpetition.org address, inflating the users table ~4x.
     * Every user-facing count goes through these two scopes so the mistake
     * cannot reappear in a new query.
     */
    public const SYNTHETIC_DOMAIN = 'internetpetition.org';

    public function scopeReal($query)
    {
        return $query->where('email_domain', '!=', self::SYNTHETIC_DOMAIN);
    }

    public function scopeSynthetic($query)
    {
        return $query->where('email_domain', self::SYNTHETIC_DOMAIN);
    }

    protected $fillable = [
        'name',
        'first_name',
        'last_name',
        'email',
        'password',
        'locale',
        'ip',
        'verified',
        'verification_token',
        'verification_token_sent_at',
        'level_id',
        'identify_mode',
        'city',
        'country',
        'gender',
        'nickname',
        'welcome_email_pending',
        'welcome_email_sent_at',
        'preferred_language_confirmed_at',
        'google_id',
        'password_changed_at',
        'notify_milestones',
        'notify_new_signatures',
        'notify_product',
        'unsubscribed_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    // Product/notification mail is opt-in. The column default is also false
    // on MySQL, but this is the authoritative default for every Eloquent
    // insert path (registration, guest signing, OAuth, factories).
    protected $attributes = [
        'notify_milestones' => false,
        'notify_new_signatures' => false,
        'notify_product' => false,
    ];

    protected $casts = [
        'verification_token_sent_at' => 'datetime',
        'verified' => 'boolean',
        'welcome_email_pending' => 'boolean',
        'welcome_email_sent_at' => 'datetime',
        'welcome_email_claimed_at' => 'datetime',
        'preferred_language_confirmed_at' => 'datetime',
        'banned_until' => 'datetime',
        'notify_milestones' => 'boolean',
        'notify_new_signatures' => 'boolean',
        'notify_product' => 'boolean',
        'unsubscribed_at' => 'datetime',
    ];

    public function petitions()
    {
        return $this->hasMany(Petition::class);
    }

    public function signatures()
    {
        return $this->hasMany(Signature::class);
    }

    public function level()
    {
        return $this->belongsTo(UserLevel::class, 'level_id');
    }

    public function hasLevel(string $name): bool
    {
        return $this->level?->name === $name;
    }

    public function canAccessAdmin(): bool
    {
        $this->loadMissing('level');

        if (! $this->level_id || ! $this->level) {
            return false;
        }

        if ($this->level->is_system) {
            return true;
        }

        return Permission::where('level_id', $this->level_id)->exists();
    }

    public function getDisplayNameAttribute(): string
    {
        return match ($this->identify_mode) {
            'name' => $this->first_name ?: $this->name,
            'nick' => $this->nickname ?: $this->name,
            default => $this->name,
        };
    }

    public function hasLocalPassword(): bool
    {
        return $this->password_changed_at !== null;
    }

    public function isGoogleOnly(): bool
    {
        return filled($this->google_id) && ! $this->hasLocalPassword();
    }

    public function hasCompleteProfile(): bool
    {
        return filled($this->country)
            && filled($this->gender)
            && $this->hasSupportedPreferredLanguage()
            && $this->preferred_language_confirmed_at !== null;
    }

    public function hasSupportedPreferredLanguage(): bool
    {
        $locale = (string) $this->locale;
        $short = array_search($locale, config('language_flags', []), true) ?: $locale;

        return in_array($short, Locale::supported(), true);
    }

    public function preferredLocale(): string
    {
        return Locale::toShort($this->locale);
    }

    public function wantsMilestoneEmails(): bool
    {
        return (bool) $this->notify_milestones && $this->unsubscribed_at === null;
    }

    public function wantsNewSignatureEmails(): bool
    {
        return (bool) $this->notify_new_signatures && $this->unsubscribed_at === null;
    }

    /**
     * Rehash on a correct password stored in the legacy md5(md5(pass).salt):salt format.
     */
    public function upgradeLegacyPassword(string $plain): bool
    {
        if (! filled($this->password) || ! str_contains($this->password, ':')) {
            return false;
        }

        [$hash, $salt] = explode(':', $this->password, 2);

        if (! hash_equals($hash, md5(md5($plain).$salt))) {
            return false;
        }

        $this->password = Hash::make($plain);
        $this->password_changed_at = now();
        $this->save();

        return true;
    }

    public function isBanned(): bool
    {
        if ($this->banned_until !== null && now()->gt($this->banned_until)) {
            return false;
        }

        if ($this->banned_until !== null && $this->banned_until->isFuture()) {
            return true;
        }

        return $this->level?->name === 'banned';
    }

    public function ensureUnsubscribeToken(): string
    {
        if (filled($this->unsubscribe_token)) {
            return $this->unsubscribe_token;
        }

        do {
            $token = Str::random(64);
        } while (static::query()->where('unsubscribe_token', $token)->exists());

        $this->forceFill(['unsubscribe_token' => $token])->save();

        return $token;
    }
}
