<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrganizationNotification extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['payload'];

    protected $casts = ['payload' => 'array', 'to_admin' => 'boolean', 'sent_at' => 'datetime', 'cancelled_at' => 'datetime'];
}
