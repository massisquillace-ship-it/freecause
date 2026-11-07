<?php

namespace Database\Seeders;

use App\Models\Cause;
use App\Models\Organization;
use App\Models\User;
use App\Support\ImageVariants;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

class OrganizationE2eSeeder extends Seeder
{
    public function run(): void
    {
        abort_unless(app()->environment('e2e', 'testing', 'local'), 1);
        $owner = User::where('email', 'owner@e2e.test')->firstOrFail();
        foreach (['verified', 'pending', 'rejected', 'suspended'] as $state) {
            $org = Organization::updateOrCreate(['slug' => 'e2e-organization-'.$state], [
                'owner_user_id' => $owner->id, 'name' => 'Test organization — '.$state,
                'type' => 'association', 'country' => 'IT', 'status' => $state,
                'verification_reference' => 'PRIVATE TEST EVIDENCE',
                'representation_confirmed_at' => now(), 'terms_accepted_at' => now(), 'terms_version' => 'e2e-only',
                'verified_at' => in_array($state, ['verified', 'suspended'], true) ? now() : null,
                'founding_partner' => true, 'tax_code' => '12345678901',
                'tagline' => ['en' => 'A test-only introduction', 'it' => 'Presentazione di prova'],
                'description' => ['en' => 'Test-only profile. No real organization is represented.'],
                'donation_url' => 'https://example.org/support', 'donation_url_approved_at' => now(),
            ]);
            $org->causes()->sync(Cause::orderBy('id')->limit(3)->pluck('id'));
        }
        $image = imagecreatetruecolor(1500, 500);
        imagefill($image, 0, 0, imagecolorallocate($image, 30, 70, 60));
        ob_start();
        imagewebp($image, null, 85);
        $cover = ob_get_clean();
        Storage::disk('public')->put('organizations/e2e-cover.webp', $cover);
        ImageVariants::ensure(Storage::disk('public')->path('organizations/e2e-cover.webp'));
        $image = imagecreatetruecolor(400, 400);
        imagefill($image, 0, 0, imagecolorallocate($image, 180, 200, 190));
        ob_start();
        imagewebp($image, null, 85);
        $logo = ob_get_clean();
        Storage::disk('public')->put('organizations/e2e-logo.webp', $logo);
        Organization::updateOrCreate(['slug' => 'e2e-organization-images'], [
            'owner_user_id' => $owner->id, 'name' => str_repeat('Long organization name ', 5),
            'type' => 'association', 'country' => 'FR', 'status' => 'verified',
            'verification_reference' => 'PRIVATE IMAGE TEST EVIDENCE',
            'representation_confirmed_at' => now(), 'terms_accepted_at' => now(), 'terms_version' => 'e2e-only',
            'verified_at' => now(), 'cover_path' => 'organizations/e2e-cover.webp', 'logo_path' => 'organizations/e2e-logo.webp',
        ]);
    }
}
