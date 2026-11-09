<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AccountDeletionCleanup extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'completed_at' => 'datetime',
        'last_attempted_at' => 'datetime',
    ];
}
