<?php

namespace App\Services;

use App\Support\ImageVariants;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;

class OrganizationImages
{
    public function store(UploadedFile $file, string $kind, string $focal = 'center'): array
    {
        $size = @getimagesize($file->getRealPath());
        if (! $size || $size[0] * $size[1] > 40_000_000 || ! in_array($size['mime'], ['image/jpeg', 'image/png', 'image/webp'], true)) {
            throw ValidationException::withMessages([$kind => __('organizations.invalid_image')]);
        }
        $manager = new ImageManager(new Driver, autoOrientation: true, decodeAnimation: false, strip: true);
        try {
            $image = $manager->read($file->getRealPath())->orient();
            $path = 'organizations/'.Str::uuid().'.webp';
            if ($kind === 'cover') {
                $image->cover(1500, 500, match ($focal) {
                    'top' => 'top', 'bottom' => 'bottom', default => 'center'
                });
            } else {
                $image->cover(400, 400, 'center');
            }
            if (! Storage::disk('public')->put($path, (string) $image->toWebp(85))) {
                throw new \RuntimeException('Image write failed');
            }

            if ($kind === 'cover' && ImageVariants::ensure(Storage::disk('public')->path($path)) === null) {
                throw new \RuntimeException('Responsive cover write failed');
            }

            return [$kind.'_path' => $path];
        } catch (\Throwable $e) {
            if (isset($path)) {
                ImageVariants::purge($path);
                Storage::disk('public')->delete($path);
            }
            throw ValidationException::withMessages([$kind => __('organizations.invalid_image')]);
        }
    }
}
