<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\CategoryTranslation;
use App\Models\Petition;
use App\Models\PetitionTranslation;
use App\Models\Signature;
use App\Models\User;
use Database\Seeders\LanguageSeeder;
use Database\Seeders\UserLevelSeeder;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PDOException;
use Tests\TestCase;

class PetitionCreateFlowTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Category $category;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([UserLevelSeeder::class, LanguageSeeder::class]);
        $this->user = User::factory()->create(['verified' => true]);
        $this->category = Category::factory()->create(['is_active' => true]);
        CategoryTranslation::create([
            'category_id' => $this->category->id,
            'locale' => 'en',
            'name' => 'Test Category',
            'slug' => 'test-category',
        ]);
    }

    private function payload(array $over = []): array
    {
        return array_merge([
            'title' => 'Test Petition Title Here',
            'description' => '<p>This is a test petition with enough content to pass validation and be accepted.</p>',
            'goal_signatures' => 100,
            'category_id' => $this->category->id,
        ], $over);
    }

    public function test_valid_store_redirects_and_creates_petition(): void
    {
        $response = $this->actingAs($this->user)->post('/en/create-petition', $this->payload());
        $petition = Petition::latest('id')->firstOrFail();
        $response->assertRedirectToRoute('petition.thanks', ['locale' => 'en', 'slug' => 'test-petition-title-here', 'id' => $petition->id]);
        $this->assertDatabaseHas('petition_translations', ['petition_id' => $petition->id, 'locale' => 'en']);
    }

    public function test_daily_petition_limit_applies_per_user(): void
    {
        for ($i = 1; $i <= 10; $i++) {
            $this->actingAs($this->user)
                ->postJson('/en/create-petition', $this->payload(['title' => "Test Petition Title {$i}"]))
                ->assertRedirect();

            $this->travel(61)->seconds();
        }

        $this->actingAs($this->user)
            ->postJson('/en/create-petition', $this->payload(['title' => 'Test Petition Title 11']))
            ->assertTooManyRequests();

        $other = User::factory()->create(['verified' => true]);
        $this->actingAs($other)
            ->postJson('/en/create-petition', $this->payload(['title' => 'Other User Petition']))
            ->assertRedirect();
    }

    public function test_spam_blocked_submits_count_toward_daily_limit(): void
    {
        for ($i = 1; $i <= 10; $i++) {
            $this->actingAs($this->user)
                ->post('/en/create-petition', $this->payload(['title' => 'Cheap viagra and betting offers']))
                ->assertSessionHasErrors(['title' => __('create.err_suspicious')]);

            $this->travel(61)->seconds();
        }

        $this->actingAs($this->user)
            ->postJson('/en/create-petition', $this->payload())
            ->assertTooManyRequests();

        $this->assertDatabaseCount('petitions', 0);
    }

    public function test_short_description_fails_validation(): void
    {
        $this->actingAs($this->user)->post('/en/create-petition', $this->payload(['description' => '<p>Too short</p>']))
            ->assertSessionHasErrors('description');
    }

    public function test_validation_failure_renders_plain_linked_error_summary(): void
    {
        $response = $this->actingAs($this->user)
            ->followingRedirects()
            ->from('/en/create-petition')
            ->post('/en/create-petition', $this->payload(['description' => '<p>Too short</p>']));
        $response->assertOk()
            ->assertSee('href="#field-petition_description"', false)
            ->assertDontSee('data-alert-variant="error"', false);
    }

    public function test_phone_sized_photo_is_accepted_and_scaled_down(): void
    {
        $file = UploadedFile::fake()->image('phone.jpg', 4032, 3024);

        $this->actingAs($this->user)->post('/en/create-petition', $this->payload(['image' => $file]))
            ->assertSessionHasNoErrors();

        $petition = Petition::latest('id')->first();
        $size = getimagesize(Storage::disk('public')->path($petition->cover_image));
        $this->assertLessThanOrEqual(800, max($size[0], $size[1]));
    }

    public function test_five_mb_cover_is_accepted(): void
    {
        $file = UploadedFile::fake()->image('phone.jpg', 1000, 800)->size(5120);

        $this->actingAs($this->user)->post('/en/create-petition', $this->payload(['image' => $file]))
            ->assertSessionHasNoErrors();

        $this->assertNotNull(Petition::latest('id')->first()->cover_image);
    }

    public function test_nine_mb_cover_is_rejected(): void
    {
        $file = UploadedFile::fake()->image('big.jpg', 1000, 800)->size(9216);

        $this->actingAs($this->user)->post('/en/create-petition', $this->payload(['image' => $file]))
            ->assertSessionHasErrors('image');
    }

    public function test_image_over_pixel_cap_is_rejected_with_translated_message(): void
    {
        $png = "\x89PNG\r\n\x1a\n".pack('N', 13).'IHDR'.pack('NN', 7000, 6000)."\x08\x02\x00\x00\x00".pack('N', 0);
        $file = UploadedFile::fake()->createWithContent('huge.png', $png);

        $this->actingAs($this->user)->post('/en/create-petition', $this->payload(['image' => $file]))
            ->assertSessionHasErrors(['image' => __('create.err_image_pixels')]);
    }

    public function test_failed_upload_shows_upload_failed_message(): void
    {
        $file = new UploadedFile(__FILE__, 'cover.jpg', 'image/jpeg', UPLOAD_ERR_INI_SIZE, true);

        $this->actingAs($this->user)->post('/en/create-petition', $this->payload(['image' => $file]))
            ->assertSessionHasErrors(['image' => __('create.err_image_upload_failed')]);
    }

    public function test_invalid_country_fails_validation(): void
    {
        $this->actingAs($this->user)->post('/en/create-petition', $this->payload(['country' => 'ZZ']))
            ->assertSessionHasErrors('country');
    }

    public function test_non_owner_update_is_forbidden(): void
    {
        $other = User::factory()->create(['verified' => true]);
        $petition = Petition::factory()->create(['user_id' => $other->id, 'category_id' => $this->category->id]);
        PetitionTranslation::create(['petition_id' => $petition->id, 'locale' => 'en', 'title' => 'Original Petition Title', 'slug' => 'original-petition-title', 'description' => 'Original description.']);
        $this->actingAs($this->user)->post("/en/petition/original-petition-title/{$petition->id}/edit", $this->payload(['title' => 'Updated Petition Title']))
            ->assertForbidden();
    }

    public function test_owner_cannot_edit_after_another_user_signs(): void
    {
        $petition = Petition::factory()->create(['user_id' => $this->user->id, 'category_id' => $this->category->id, 'status' => 'published', 'is_active' => true]);
        PetitionTranslation::create(['petition_id' => $petition->id, 'locale' => 'en', 'title' => 'Original Petition Title', 'slug' => 'original-petition-title', 'description' => 'Original description.']);
        $signer = User::factory()->create(['verified' => true]);
        Signature::factory()->create(['petition_id' => $petition->id, 'user_id' => $signer->id, 'confirmed' => true, 'is_spam' => false]);

        $this->actingAs($this->user)->post("/en/petition/original-petition-title/{$petition->id}/edit", $this->payload(['title' => 'Updated Petition Title']))
            ->assertForbidden();
    }

    public function test_non_owner_invalid_update_is_forbidden_before_validation(): void
    {
        $other = User::factory()->create(['verified' => true]);
        $petition = Petition::factory()->create(['user_id' => $other->id, 'category_id' => $this->category->id]);
        PetitionTranslation::create(['petition_id' => $petition->id, 'locale' => 'en', 'title' => 'Original Petition Title', 'slug' => 'original-petition-title', 'description' => 'Original description.']);
        $this->actingAs($this->user)->post("/en/petition/original-petition-title/{$petition->id}/edit", ['title' => '', 'image_url' => 'https://example.com/x.png'])
            ->assertForbidden()
            ->assertSessionHasNoErrors();
    }

    public function test_update_of_missing_petition_returns_404(): void
    {
        $this->actingAs($this->user)->post('/en/petition/original-petition-title/999999/edit', ['title' => ''])
            ->assertNotFound();
    }

    public function test_owner_invalid_update_still_gets_validation_errors(): void
    {
        $petition = Petition::factory()->create(['user_id' => $this->user->id, 'category_id' => $this->category->id]);
        PetitionTranslation::create(['petition_id' => $petition->id, 'locale' => 'en', 'title' => 'Original Petition Title', 'slug' => 'original-petition-title', 'description' => 'Original description.']);
        $this->actingAs($this->user)->post("/en/petition/original-petition-title/{$petition->id}/edit", $this->payload(['title' => '']))
            ->assertSessionHasErrors('title');
    }

    public function test_unchanged_title_keeps_legacy_slug_and_new_title_gets_new_slug(): void
    {
        $petition = Petition::factory()->create(['user_id' => $this->user->id, 'category_id' => $this->category->id, 'status' => 'published', 'is_active' => true]);
        PetitionTranslation::create(['petition_id' => $petition->id, 'locale' => 'en', 'title' => 'Save the Old Town', 'slug' => 'save-old-town-4711', 'description' => 'Original description.']);
        $url = "/en/petition/save-old-town-4711/{$petition->id}/edit";

        $this->actingAs($this->user)->post($url, $this->payload(['title' => 'Save the Old Town']))->assertRedirect();
        $this->assertDatabaseHas('petition_translations', ['petition_id' => $petition->id, 'slug' => 'save-old-town-4711']);

        $this->actingAs($this->user)->post($url, $this->payload(['title' => 'Save the Old Town Now']))->assertRedirect();
        $this->assertDatabaseHas('petition_translations', ['petition_id' => $petition->id, 'slug' => 'save-the-old-town-now']);
    }

    public function test_title_update_retries_after_first_slug_collision(): void
    {
        $petition = Petition::factory()->create([
            'user_id' => $this->user->id,
            'category_id' => $this->category->id,
            'status' => 'published',
            'is_active' => true,
        ]);
        PetitionTranslation::create([
            'petition_id' => $petition->id,
            'locale' => 'en',
            'title' => 'Original Petition Title',
            'slug' => 'original-petition-title',
            'description' => 'Original description.',
        ]);

        $firstSave = true;
        Event::listen('eloquent.saving: '.PetitionTranslation::class, function () use (&$firstSave): void {
            if ($firstSave) {
                $firstSave = false;
                $pdoException = new PDOException('UNIQUE constraint failed');
                $pdoException->errorInfo = ['23000', 19, 'UNIQUE constraint failed'];
                throw new QueryException('sqlite', 'insert into petition_translations', [], $pdoException);
            }
        });

        $this->actingAs($this->user)
            ->post('/en/petition/original-petition-title/'.$petition->id.'/edit', $this->payload([
                'title' => 'Updated Petition Title',
            ]))
            ->assertRedirect();

        $this->assertDatabaseHas('petition_translations', [
            'petition_id' => $petition->id,
            'slug' => 'updated-petition-title-1',
            'title' => 'Updated Petition Title',
        ]);
    }

    public function test_edit_form_previews_legacy_bare_image_name_from_storage_url(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('petitions/abc123.jpg', 'image');
        $petition = Petition::factory()->create(['user_id' => $this->user->id, 'category_id' => $this->category->id, 'status' => 'published', 'is_active' => true, 'image_url' => 'abc123.jpg', 'cover_image' => null]);
        PetitionTranslation::create(['petition_id' => $petition->id, 'locale' => 'en', 'title' => 'Legacy Cover Petition', 'slug' => 'legacy-cover-petition', 'description' => 'Original description.']);

        $response = $this->actingAs($this->user)->get("/en/petition/legacy-cover-petition/{$petition->id}/edit");

        $response->assertOk()
            ->assertSee(Storage::disk('public')->url('petitions/abc123.jpg'), false)
            ->assertDontSee('src="abc123.jpg"', false);
    }

    public function test_text_only_update_keeps_cover(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('petitions/keep.png', 'image');
        $petition = Petition::factory()->create(['user_id' => $this->user->id, 'category_id' => $this->category->id, 'status' => 'published', 'is_active' => true, 'cover_image' => 'petitions/keep.png']);
        PetitionTranslation::create(['petition_id' => $petition->id, 'locale' => 'en', 'title' => 'Original Petition Title', 'slug' => 'original-petition-title', 'description' => 'Original description.']);
        $this->actingAs($this->user)->post("/en/petition/original-petition-title/{$petition->id}/edit", [
            'title' => 'Updated Petition Title Words',
            'description' => '<p>This updated description contains enough meaningful content.</p>',
            'category_id' => $this->category->id,
        ])->assertRedirect();
        $this->assertDatabaseHas('petitions', ['id' => $petition->id, 'cover_image' => 'petitions/keep.png']);
        Storage::disk('public')->assertExists('petitions/keep.png');
    }

    public function test_update_succeeds_when_old_cover_cleanup_throws(): void
    {
        $petition = Petition::factory()->create(['user_id' => $this->user->id, 'category_id' => $this->category->id, 'status' => 'published', 'is_active' => true, 'cover_image' => 'petitions/old.png']);
        PetitionTranslation::create(['petition_id' => $petition->id, 'locale' => 'en', 'title' => 'Original Petition Title', 'slug' => 'original-petition-title', 'description' => 'Original description.']);

        $disk = Mockery::mock(Filesystem::class);
        $disk->shouldReceive('delete')->andThrow(new \RuntimeException('disk down'));
        Storage::shouldReceive('disk')->with('public')->andReturn($disk);

        $this->actingAs($this->user)->post("/en/petition/original-petition-title/{$petition->id}/edit", $this->payload(['title' => 'Updated Petition Title', 'remove_image' => 1]))
            ->assertRedirect();

        $this->assertDatabaseHas('petitions', ['id' => $petition->id, 'cover_image' => null]);
    }

    public function test_normal_html_description_is_not_flagged_as_spam(): void
    {
        $this->actingAs($this->user)->post('/en/create-petition', $this->payload([
            'description' => '<p>We <strong>demand</strong> action.</p><ul><li>First point here</li><li>Second point here</li></ul><p>Read more at <a href="https://example.com/info">our page</a>.</p>',
        ]))->assertRedirect();
    }
}
