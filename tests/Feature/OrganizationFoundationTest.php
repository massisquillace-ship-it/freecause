<?php

namespace Tests\Feature;

use App\Models\Cause;
use App\Models\Organization;
use App\Models\Petition;
use App\Models\User;
use App\Models\UserLevel;
use App\Services\OrganizationImages;
use App\Services\OrganizationLifecycle;
use App\Services\WordPressUserDeletionService;
use App\Support\ImageVariants;
use Database\Seeders\CategorySeeder;
use Database\Seeders\LanguageSeeder;
use Database\Seeders\OrganizationCauseSeeder;
use Database\Seeders\UserLevelSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class OrganizationFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed([UserLevelSeeder::class, LanguageSeeder::class, OrganizationCauseSeeder::class]);
        config(['organizations.applications_open' => true, 'organizations.terms_version' => 'test-only', 'organizations.terms.en' => 'Test-only terms; not for public use.']);
        Storage::fake('public');
    }

    private function owner(): User
    {
        return User::factory()->create(['verified' => true, 'locale' => 'en', 'gender' => 'none']);
    }

    private function admin(): User
    {
        return User::factory()->create(['level_id' => UserLevel::where('name', 'admin')->value('id'), 'verified' => true]);
    }

    private function payload(array $overrides = []): array
    {
        return array_replace([
            'name' => 'Test Only Association', 'type' => 'association', 'country' => 'IT',
            'verification_reference' => 'Private evidence, test only', 'cover_focal_point' => 'center',
            'causes' => Cause::orderBy('id')->limit(2)->pluck('id')->all(),
            'representation_confirmed' => '1', 'terms_accepted' => '1',
            'tagline' => ['it' => 'Testo italiano', 'en' => 'English introduction'],
        ], $overrides);
    }

    private function organization(?User $owner = null, array $overrides = []): Organization
    {
        $owner ??= $this->owner();

        return Organization::create(array_replace([
            'owner_user_id' => $owner->id, 'slug' => 'test-only-'.bin2hex(random_bytes(4)),
            'name' => 'Test Only Association', 'type' => 'association', 'country' => 'IT',
            'verification_reference' => 'Private evidence, never public', 'cover_focal_point' => 'center',
            'representation_confirmed_at' => now(), 'terms_accepted_at' => now(), 'terms_version' => 'test-only',
        ], $overrides));
    }

    public function test_application_records_declarations_and_closed_causes_without_publishing(): void
    {
        $owner = $this->owner();
        $this->actingAs($owner)->post('/en/organizations/apply', $this->payload())->assertRedirect()->assertSessionHasNoErrors();
        $org = Organization::firstOrFail();
        $this->assertSame('pending', $org->status);
        $this->assertSame($owner->id, $org->owner_user_id);
        $this->assertNotNull($org->terms_accepted_at);
        $this->assertNotNull($org->representation_confirmed_at);
        $this->assertSame('test-only', $org->terms_version);
        $this->assertCount(2, $org->causes);
        $this->get('/en/org/'.$org->slug)->assertNotFound();
        $this->get('/en/org/'.$org->slug.'/manage')->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    public function test_real_applications_remain_closed_without_approved_terms(): void
    {
        config(['organizations.terms.en' => '']);
        $this->actingAs($this->owner())->post('/en/organizations/apply', $this->payload())->assertForbidden();
        $this->assertDatabaseCount('organizations', 0);
    }

    public function test_cause_seeding_is_idempotent_and_does_not_detach_organizations(): void
    {
        $org = $this->organization();
        $org->causes()->attach(Cause::first()->id);
        $this->seed(OrganizationCauseSeeder::class);
        $this->assertDatabaseCount('causes', 20);
        $this->assertCount(1, $org->fresh()->causes);
    }

    public static function invalidApplications(): array
    {
        return [
            'duplicate causes' => [['causes' => [1, 1]], 'causes.0'],
            'unknown cause' => [['causes' => [999999]], 'causes.0'],
            'no cause' => [['causes' => []], 'causes'],
            'four causes' => [['causes' => [1, 2, 3, 4]], 'causes'],
            'no representation' => [['representation_confirmed' => '0'], 'representation_confirmed'],
            'reserved slug' => [['slug' => 'admin'], 'slug'],
            'invalid slug' => [['slug' => 'Not_A_Slug'], 'slug'],
            'invalid country' => [['country' => 'XX'], 'country'],
            'tax code' => [['tax_code' => 'bad!'], 'tax_code'],
            'unapproved social key' => [['social_links' => ['custom' => 'https://example.com']], 'social_links'],
            'social suffix attack' => [['social_links' => ['facebook' => 'https://facebook.com.attacker.example']], 'social_links.facebook'],
            'social prefix attack' => [['social_links' => ['facebook' => 'https://evilfacebook.com']], 'social_links.facebook'],
            'social credentials' => [['social_links' => ['x' => 'https://user:pass@x.com/test']], 'social_links.x'],
            'nested json' => [['social_links' => ['x' => ['url' => 'https://x.com/test']]], 'social_links.x'],
            'donation http' => [['donation_url' => 'http://example.org/donate'], 'donation_url'],
            'donation port' => [['donation_url' => 'https://example.org:444/donate'], 'donation_url'],
            'donation internal' => [['donation_url' => 'https://www.freecause.com/en'], 'donation_url'],
            'unknown language' => [['tagline' => ['xx' => 'Bad']], 'tagline'],
        ];
    }

    #[DataProvider('invalidApplications')]
    public function test_crafted_application_data_is_rejected(array $values, string $field): void
    {
        $this->actingAs($this->owner())->post('/en/organizations/apply', $this->payload($values))->assertSessionHasErrors($field);
        $this->assertDatabaseCount('organizations', 0);
    }

    public function test_social_subdomains_empty_removal_unique_slug_and_locale_fallback(): void
    {
        $owner = $this->owner();
        $data = $this->payload(['social_links' => ['facebook' => 'https://www.facebook.com/test', 'x' => '']]);
        $this->actingAs($owner)->post('/en/organizations/apply', $data)->assertSessionHasNoErrors();
        $this->post('/en/organizations/apply', $data)->assertSessionHasNoErrors();
        $org = Organization::firstOrFail();
        $this->assertCount(1, $org->social_links);
        $this->assertSame('English introduction', $org->localized('tagline', 'de'));
        $this->assertSame('Testo italiano', $org->localized('tagline', 'it'));
        $this->assertSame(2, Organization::distinct()->count('slug'));
    }

    public function test_other_users_cannot_read_or_write_private_profiles(): void
    {
        $org = $this->organization();
        $this->actingAs($this->owner())->get('/en/org/'.$org->slug.'/manage')->assertForbidden();
        $this->post('/en/org/'.$org->slug.'/manage/profile', $this->payload())->assertForbidden();
        $this->post('/en/org/'.$org->slug.'/manage/resubmit')->assertForbidden();
        $this->post('/admin/organizations/'.$org->id.'/verify', ['confirm' => 1, 'reason' => 'test'])->assertRedirect('/admin/login');
    }

    public function test_admin_state_transitions_keep_private_evidence_private(): void
    {
        $org = $this->organization();
        $admin = $this->admin();
        $this->actingAs($admin, 'admin')->post('/admin/organizations/'.$org->id.'/verify', ['confirm' => 1, 'reason' => 'Evidence reviewed'])->assertSessionHasNoErrors();
        $this->assertSame('verified', $org->fresh()->status);
        $firstVerification = $org->fresh()->verified_at;
        $this->get('/en/org/'.$org->slug)->assertOk()->assertDontSee('Private evidence, never public')->assertDontSee('Evidence reviewed')->assertSee('index, follow');
        $this->post('/admin/organizations/'.$org->id.'/suspend', ['confirm' => 1, 'reason' => 'Needs review'])->assertSessionHasNoErrors();
        $this->get('/en/org/'.$org->slug)->assertNotFound();
        $this->post('/admin/organizations/'.$org->id.'/restore', ['confirm' => 1, 'reason' => 'Resolved'])->assertSessionHasNoErrors();
        $this->assertTrue($org->fresh()->verified_at->eq($firstVerification));
        $this->assertCount(3, $org->events);
    }

    public function test_rejection_needs_reason_resubmits_explicitly_and_suspension_is_read_only(): void
    {
        $owner = $this->owner();
        $org = $this->organization($owner);
        $admin = $this->admin();
        $this->actingAs($admin, 'admin')->post('/admin/organizations/'.$org->id.'/reject', ['confirm' => 1])->assertSessionHasErrors('reason');
        $this->post('/admin/organizations/'.$org->id.'/reject', ['confirm' => 1, 'reason' => 'More evidence'])->assertSessionHasNoErrors();
        $this->actingAs($owner, 'web')->post('/en/org/'.$org->slug.'/manage/resubmit')->assertSessionHasNoErrors();
        $this->assertSame('pending', $org->fresh()->status);
        $this->post('/en/org/'.$org->slug.'/manage/resubmit')->assertStatus(409);
        $org->update(['status' => 'suspended']);
        $this->post('/en/org/'.$org->slug.'/manage/profile', $this->payload())->assertForbidden();
        $this->get('/en/org/'.$org->slug.'/manage')->assertOk()->assertDontSee('Edit profile');
    }

    public function test_identity_locked_and_donation_approval_reset_on_owner_edit(): void
    {
        $owner = $this->owner();
        $org = $this->organization($owner, ['status' => 'verified', 'verified_at' => now(), 'donation_url' => 'https://example.org/old', 'donation_url_approved_at' => now()]);
        $this->actingAs($owner)->post('/en/org/'.$org->slug.'/manage/profile', $this->payload(['name' => 'Different name']))->assertSessionHasErrors('name');
        $this->post('/en/org/'.$org->slug.'/manage/profile', $this->payload(['donation_url' => 'https://example.org/new']))->assertSessionHasNoErrors();
        $this->assertNull($org->fresh()->donation_url_approved_at);
        $this->get('/en/org/'.$org->slug)->assertDontSee('https://example.org/new');
    }

    public function test_donation_approval_binds_exact_reviewed_url_and_requires_confirmation(): void
    {
        $org = $this->organization(null, ['status' => 'verified', 'donation_url' => 'https://example.org/donate']);
        $this->actingAs($this->admin(), 'admin')->post('/admin/organizations/'.$org->id.'/donation', ['confirm' => 1, 'reviewed_donation_url' => 'https://stale.example'])->assertSessionHasErrors('reviewed_donation_url');
        $this->post('/admin/organizations/'.$org->id.'/donation', ['confirm' => 1, 'reviewed_donation_url' => $org->donation_url])->assertSessionHasNoErrors();
        $this->get('/en/org/'.$org->slug)->assertSee('https://example.org/donate')->assertSee('noopener noreferrer nofollow sponsored');
    }

    public function test_tax_code_and_founding_recognition_only_on_eligible_public_profile(): void
    {
        $org = $this->organization(null, ['status' => 'verified', 'tax_code' => '12345678901', 'founding_partner' => true]);
        $this->get('/en/org/'.$org->slug)->assertSee('Founding organization')->assertSee('12345678901')->assertSee('mailto:'.Organization::supportEmail())->assertSee('data-share-url', false);
        $org->update(['country' => 'FR']);
        $this->get('/en/org/'.$org->slug)->assertDontSee('12345678901');
        $org->update(['status' => 'pending']);
        $this->get('/en/org/'.$org->slug)->assertNotFound();
    }

    public function test_owner_deletion_suspends_and_preserves_history_atomically(): void
    {
        $owner = $this->owner();
        $org = $this->organization($owner, ['status' => 'verified', 'verified_at' => now()]);
        try {
            DB::transaction(function () use ($owner) {
                app(OrganizationLifecycle::class)->suspendForDeletedOwner($owner);
                throw new RuntimeException('Test rollback');
            });
        } catch (RuntimeException) {
        }
        $this->assertSame('verified', $org->fresh()->status);
        $this->assertSame($owner->id, $org->fresh()->owner_user_id);
        DB::transaction(function () use ($owner) {
            app(OrganizationLifecycle::class)->suspendForDeletedOwner($owner);
            User::whereKey($owner->id)->delete();
        });
        $this->assertSame('suspended', $org->fresh()->status);
        $this->assertNull($org->fresh()->owner_user_id);
        $this->assertDatabaseHas('organization_events', ['organization_id' => $org->id, 'event' => 'owner_account_deleted']);
        $this->get('/en/org/'.$org->slug)->assertNotFound();
        $this->actingAs($this->admin(), 'admin')->post('/admin/organizations/'.$org->id.'/restore', ['confirm' => 1, 'reason' => 'test'])->assertStatus(409);
    }

    public function test_real_account_deletion_calls_organization_lifecycle(): void
    {
        $owner = $this->owner();
        $owner->update(['google_id' => null, 'password_changed_at' => now()]);
        $org = $this->organization($owner);
        $cleanup = Mockery::mock(WordPressUserDeletionService::class);
        $cleanup->shouldReceive('delete')->once();
        $this->app->instance(WordPressUserDeletionService::class, $cleanup);
        $this->actingAs($owner)->post('/en/account/delete', ['password' => 'password', 'confirm_delete' => 1])->assertRedirect('/en');
        $this->assertNull($org->fresh()->owner_user_id);
        $this->assertSame('suspended', $org->fresh()->status);
    }

    public function test_owner_reassignment_revokes_old_access_without_restoring_profile(): void
    {
        $old = $this->owner();
        $new = $this->owner();
        $org = $this->organization($old, ['status' => 'suspended', 'verified_at' => now()]);
        $this->actingAs($this->admin(), 'admin')->post('/admin/organizations/'.$org->id.'/reassign', ['confirm' => 1, 'owner_user_id' => $new->id])->assertSessionHasNoErrors();
        $this->assertSame('suspended', $org->fresh()->status);
        $this->actingAs($old, 'web')->get('/en/org/'.$org->slug.'/manage')->assertForbidden();
        $this->actingAs($new, 'web')->get('/en/org/'.$org->slug.'/manage')->assertOk();
        $event = $org->events()->where('event', 'owner_reassigned')->firstOrFail();
        $this->assertSame($old->id, $event->details['old_owner_id']);
        $this->assertSame($new->id, $event->details['new_owner_id']);
    }

    public static function focalPoints(): array
    {
        return [['top'], ['center'], ['bottom']];
    }

    #[DataProvider('focalPoints')]
    public function test_uploads_reencode_to_correct_dimensions_and_replace_safely(string $focal): void
    {
        $owner = $this->owner();
        $this->actingAs($owner)->post('/en/organizations/apply', $this->payload([
            'logo' => UploadedFile::fake()->image('logo.jpg', 600, 800),
            'cover' => UploadedFile::fake()->image('cover.png', 1800, 1200), 'cover_focal_point' => $focal,
        ]))->assertSessionHasNoErrors();
        $org = Organization::firstOrFail();
        $this->assertSame([1500, 500], array_slice(getimagesize(Storage::disk('public')->path($org->cover_path)), 0, 2));
        $this->assertSame([400, 400], array_slice(getimagesize(Storage::disk('public')->path($org->logo_path)), 0, 2));
        $old = $org->cover_path;
        $variants = ImageVariants::relativeVariantPaths($old);
        foreach ($variants as $variant) {
            Storage::disk('public')->assertExists($variant);
        }
        $this->post('/en/org/'.$org->slug.'/manage/profile', $this->payload(['remove_cover' => 1]))->assertSessionHasNoErrors();
        Storage::disk('public')->assertMissing($old);
        foreach ($variants as $variant) {
            Storage::disk('public')->assertMissing($variant);
        }
        $this->assertNull($org->fresh()->cover_path);
    }

    public function test_svg_and_oversize_files_are_rejected(): void
    {
        $this->actingAs($this->owner())->post('/en/organizations/apply', $this->payload(['logo' => UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>')]))->assertSessionHasErrors('logo');
        $this->post('/en/organizations/apply', $this->payload(['cover' => UploadedFile::fake()->image('cover.jpg')->size(3073)]))->assertSessionHasErrors('cover');
    }

    public function test_private_views_admin_and_localized_landing_render(): void
    {
        $owner = $this->owner();
        $org = $this->organization($owner);
        $this->actingAs($owner)->get('/it/organizations')->assertOk()->assertSee('La tua associazione');
        $this->get('/en/org/'.$org->slug.'/manage/profile')->assertOk()->assertSee('Private verification reference');
        $this->actingAs($this->admin(), 'admin')->get('/admin/organizations')->assertOk()->assertSee($org->name);
        $this->get('/admin/organizations/'.$org->id)->assertOk()->assertSee('Private evidence, never public');
    }

    public function test_admin_restore_of_reassigned_unverified_organization_returns_it_to_pending(): void
    {
        $org = $this->organization(null, ['status' => 'suspended', 'owner_user_id' => null]);
        $new = $this->owner();
        $this->actingAs($this->admin(), 'admin')->post('/admin/organizations/'.$org->id.'/reassign', ['confirm' => 1, 'owner_user_id' => $new->id])->assertSessionHasNoErrors();
        $this->post('/admin/organizations/'.$org->id.'/restore', ['confirm' => 1, 'reason' => 'New applicant assigned'])->assertSessionHasNoErrors();
        $this->assertSame('pending', $org->fresh()->status);
        $this->assertNull($org->fresh()->verified_at);
        $this->get('/en/org/'.$org->slug)->assertNotFound();
    }

    public function test_multiple_organizations_suspend_together_and_personal_petitions_are_not_transferred(): void
    {
        $owner = $this->owner();
        $new = $this->owner();
        $first = $this->organization($owner, ['status' => 'verified', 'verified_at' => now()]);
        $second = $this->organization($owner, ['status' => 'rejected']);
        $this->seed(CategorySeeder::class);
        $petition = Petition::factory()->create(['user_id' => $owner->id, 'status' => 'draft']);
        DB::transaction(fn () => app(OrganizationLifecycle::class)->suspendForDeletedOwner($owner));
        $this->assertNull($first->fresh()->owner_user_id);
        $this->assertNull($second->fresh()->owner_user_id);
        $this->assertSame('suspended', $second->fresh()->status);
        $this->actingAs($this->admin(), 'admin')->post('/admin/organizations/'.$first->id.'/reassign', ['confirm' => 1, 'owner_user_id' => $new->id])->assertSessionHasNoErrors();
        $this->assertSame($owner->id, $petition->fresh()->user_id);
    }

    public function test_focal_anchor_crops_different_vertical_regions_and_strips_exif(): void
    {
        $source = imagecreatetruecolor(600, 900);
        foreach ([[0, 299, 220, 0, 0], [300, 599, 0, 220, 0], [600, 899, 0, 0, 220]] as [$top, $bottom, $r, $g, $b]) {
            imagefilledrectangle($source, 0, $top, 599, $bottom, imagecolorallocate($source, $r, $g, $b));
        }
        ob_start();
        imagejpeg($source);
        $jpeg = ob_get_clean();
        // JPEG APP1 marker representing metadata must disappear in the WebP output.
        $exif = "Exif\0\0TEST-PRIVATE-METADATA";
        $jpeg = substr($jpeg, 0, 2)."\xff\xe1".pack('n', strlen($exif) + 2).$exif.substr($jpeg, 2);
        foreach (['top' => 0, 'center' => 1, 'bottom' => 2] as $focal => $channel) {
            $paths = app(OrganizationImages::class)->store(UploadedFile::fake()->createWithContent('test.jpg', $jpeg), 'cover', $focal);
            $bytes = Storage::disk('public')->get($paths['cover_path']);
            $this->assertStringNotContainsString('TEST-PRIVATE-METADATA', $bytes);
            $output = imagecreatefromstring($bytes);
            $rgb = imagecolorsforindex($output, imagecolorat($output, 750, 250));
            $this->assertGreaterThan(180, $rgb[['red', 'green', 'blue'][$channel]]);
        }
    }

    public function test_unique_slug_conflict_and_resource_limit_are_validated(): void
    {
        $org = $this->organization(null, ['slug' => 'already-used']);
        $this->actingAs($this->owner())->post('/en/organizations/apply', $this->payload(['slug' => $org->slug]))->assertSessionHasErrors('slug');
        // Dimensions can be read before decoding: reject a declared 49 MP raster first.
        $png = UploadedFile::fake()->image('small.png', 1, 1)->get();
        $png = substr_replace($png, pack('NN', 7000, 7000), 16, 8);
        $this->post('/en/organizations/apply', $this->payload(['cover' => UploadedFile::fake()->createWithContent('huge.png', $png)]))->assertSessionHasErrors('cover');
    }
}
