<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Signature extends Model
{
    use HasFactory;

    protected $fillable = [
        'petition_id',
        'user_id',
        'name',
        'email',
        'locale',
        'text',
        'confirmed',
        'ip_address',
    ];

    protected $hidden = ['org_optin_revoke_token'];

    protected $casts = [
        'org_optin' => 'boolean', 'org_optin_at' => 'datetime', 'org_optin_revoked_at' => 'datetime',
        'confirmed' => 'boolean',
        'is_spam' => 'boolean',
    ];

    public function petition()
    {
        return $this->belongsTo(Petition::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    // live display: honor signer's current identify_mode; frozen name for guests
    public function getDisplayNameAttribute(): ?string
    {
        return $this->user?->display_name ?? $this->name;
    }
}
