<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Permission extends Model
{
    protected $fillable = ['level_id', 'module', 'action'];

    public static function matrix(): array
    {
        return [
            'users' => ['view', 'edit', 'delete'],
            'petitions' => ['view', 'edit', 'delete'],
            'organizations' => ['view', 'edit'],
            'categories' => ['view', 'edit', 'delete'],
            'pages' => ['view', 'edit', 'delete'],
            'signatures' => ['view', 'edit', 'delete'],
            'reports' => ['view', 'edit'],
            'spam' => ['view', 'edit', 'delete'],
            'logs' => ['view', 'delete'],
            'stats' => ['view'],
            'languages' => ['view', 'edit', 'delete'],
            'options' => ['view', 'edit'],
            'system' => ['manage'],
        ];
    }

    public function level()
    {
        return $this->belongsTo(UserLevel::class, 'level_id');
    }
}
