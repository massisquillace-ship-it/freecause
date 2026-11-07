<?php

namespace App\Models;

use App\Models\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class Petition extends Model
{
    use HasFactory, HasTranslations;

    public const GOALS = [50, 100, 1000, 5000, 10000, 50000, 100000, 500000, 1000000, 10000000];

    protected $fillable = [
        'goal_signatures',
        'category_id',
        'target',
        'tags',
        'city',
        'country',
        'community',
        'community_url',
        'youtube_url',
        'image_url',
        'cover_image',
    ];

    protected $casts = [
        'last_notified_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function causes()
    {
        return $this->belongsToMany(Cause::class, 'cause_petition');
    }

    public function signatures()
    {
        return $this->hasMany(Signature::class);
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    protected function translationTable(): string
    {
        return 'petition_translations';
    }

    protected function translationForeignKey(): string
    {
        return 'petition_id';
    }

    protected function translationModel(): string
    {
        return PetitionTranslation::class;
    }

    /** The 11-char YouTube video ID from a bare ID or a youtube.com / youtube-nocookie.com / youtu.be link, else null. */
    public static function youtubeId(?string $value): ?string
    {
        if (! $value) {
            return null;
        }

        if (preg_match('/^([\w-]{11})\z/', $value, $matches)) {
            return $matches[1];
        }

        $pattern = '~^https?://(?:(?:www\.|m\.)?youtube\.com/(?:watch\?(?:[^#]*&)?v=|shorts/|embed/|live/)|(?:www\.)?youtube-nocookie\.com/embed/|youtu\.be/)([\w-]{11})(?:[?&#]|\z)~i';

        return preg_match($pattern, $value, $matches) ? $matches[1] : null;
    }

    public function hasCover(): bool
    {
        return $this->resolvedCoverUrl() !== null;
    }

    private function resolvedCoverUrl(): ?string
    {
        if ($this->cover_image) {
            if (str_starts_with($this->cover_image, 'http://') || str_starts_with($this->cover_image, 'https://')) {
                return $this->cover_image;
            }

            $path = Storage::disk('public')->path($this->cover_image);
            if (file_exists($path)) {
                return Storage::disk('public')->url($this->cover_image);
            }
        }

        if ($this->image_url) {
            if (str_starts_with($this->image_url, 'http://') || str_starts_with($this->image_url, 'https://')) {
                return $this->image_url;
            }

            $path = Storage::disk('public')->path("petitions/{$this->image_url}");
            if (file_exists($path)) {
                return Storage::disk('public')->url("petitions/{$this->image_url}");
            }
        }

        return null;
    }

    public function coverUrl(): string
    {
        $resolved = $this->resolvedCoverUrl();
        if ($resolved !== null) {
            return $resolved;
        }

        $n = ($this->id ? ($this->id % 7) : 0) + 1;
        $legacyPath = public_path("legacy/images/pic{$n}.jpg");

        if (file_exists($legacyPath)) {
            return asset("legacy/images/pic{$n}.jpg");
        }

        return asset('legacy/images/demo-featured.jpg');
    }

    public function scopeVisible($q)
    {
        return $q->where('status', 'published')
            ->where('is_active', 1);
    }
}
