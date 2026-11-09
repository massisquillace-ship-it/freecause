<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PetitionSignatureNotification extends Model
{
    protected $fillable = [
        'petition_id',
        'cutoff_at',
        'total',
        'signers',
        'status',
        'failed_at',
    ];

    protected $casts = [
        'cutoff_at' => 'datetime',
        'failed_at' => 'datetime',
        'signers' => 'array',
    ];

    public function petition()
    {
        return $this->belongsTo(Petition::class);
    }
}
