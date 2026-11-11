<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrganizationInvitation extends Model
{
    protected $guarded = ['id', 'active_email'];

    protected $hidden = ['token_hash'];

    protected $casts = ['expires_at' => 'datetime', 'accepted_at' => 'datetime', 'revoked_at' => 'datetime', 'sent_at' => 'datetime'];

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function usable(): bool
    {
        return ! $this->accepted_at && ! $this->revoked_at && $this->expires_at->isFuture();
    }
}
