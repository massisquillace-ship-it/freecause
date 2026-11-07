<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Cause extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = ['name' => 'array'];

    public function label(?string $locale = null): string
    {
        return $this->name[$locale ?? app()->getLocale()] ?? $this->name['en'] ?? $this->slug;
    }
}
