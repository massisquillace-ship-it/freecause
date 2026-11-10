<?php

namespace Tests\Feature;

use App\Mail\VerifyAccountMail;
use App\Models\Category;
use App\Models\CategoryTranslation;
use App\Models\Petition;
use App\Models\PetitionTranslation;
use App\Models\Signature;
use App\Models\User;
use App\Support\Settings;
use Database\Seeders\LanguageSeeder;
use Database\Seeders\UserLevelSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PetitionTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([UserLevelSeeder::class, LanguageSeeder::class]);

        $this->user = User::factory()->create([
            'verified' => true,
        ]);

        $this->category = Category::factory()->create(['is_active' => true]);
        CategoryTranslation::create([
            'category_id' => $this->category->id,
            'locale' => 'en',
            'name' => 'Test Category',
            'slug' => 'test-category',
        ]);
    }

    public function test_petition_index_page_loads(): void
    {
        $response = $this->get('/en/petitions');
        $response->assertStatus(200);
    }

    public function test_petition_search_filters_published_petitions(): void
    {
        $matching = Petition::factory()->create(['status' => 'published', 'is_active' => true]);
        $other = Petition::factory()->create(['status' => 'published', 'is_active' => true]);

        PetitionTranslation::create([
            'petition_id' => $matching->id,
            'locale' => 'en',
            'title' => 'Protect Local Forests',
            'slug' => 'protect-local-forests',
            'description' => 'Description',
        ]);
        PetitionTranslation::create([
            'petition_id' => $other->id,
            'locale' => 'en',
            'title' => 'Improve Public Transit',
            'slug' => 'improve-public-transit',
            'description' => 'Description',
        ]);

        $this->get('/en/petitions?q=Forest')
            ->assertOk()
            ->assertSee('Protect Local')
            ->assertSee('<mark>Forest</mark>', false)
            ->assertDontSee('Improve Public Transit');
    }

    public function test_listing_sort_by_most_signed_orders_by_signature_count(): void
    {
        $low = Petition::factory()->create(['status' => 'published', 'is_active' => true, 'signature_count' => 5]);
        $high = Petition::factory()->create(['status' => 'published', 'is_active' => true, 'signature_count' => 9000]);

        PetitionTranslation::create([
            'petition_id' => $low->id,
            'locale' => 'en',
            'title' => 'Lowly Signed Cause',
            'slug' => 'lowly-signed-cause',
            'description' => 'Description',
        ]);
        PetitionTranslation::create([
            'petition_id' => $high->id,
            'locale' => 'en',
            'title' => 'Hugely Signed Cause',
            'slug' => 'hugely-signed-cause',
            'description' => 'Description',
        ]);

        $html = $this->get('/en/petitions?sort=signed')->assertOk()->getContent();

        $this->assertLessThan(
            strpos($html, 'Lowly Signed Cause'),
            strpos($html, 'Hugely Signed Cause'),
            'Most-signed petition should appear before the less-signed one when sort=signed'
        );
    }

    public function test_listing_rejects_invalid_sort_and_still_loads(): void
    {
        Petition::factory()->create(['status' => 'published', 'is_active' => true]);

        $this->get('/en/petitions?sort=nonsense')->assertOk();
    }

    public function test_listing_sort_by_trending_orders_by_recent_signatures(): void
    {
        $quiet = Petition::factory()->create(['status' => 'published', 'is_active' => true, 'signature_count' => 50000]);
        $hot = Petition::factory()->create(['status' => 'published', 'is_active' => true, 'signature_count' => 10]);

        PetitionTranslation::create([
            'petition_id' => $quiet->id,
            'locale' => 'en',
            'title' => 'Quiet Megapetition',
            'slug' => 'quiet-megapetition',
            'description' => 'Description',
        ]);
        PetitionTranslation::create([
            'petition_id' => $hot->id,
            'locale' => 'en',
            'title' => 'Hot Newcomer',
            'slug' => 'hot-newcomer',
            'description' => 'Description',
        ]);

        Signature::factory()->count(5)->create([
            'petition_id' => $hot->id,
            'confirmed' => true,
            'created_at' => now()->subDay(),
        ]);

        Signature::factory()->count(2)->create([
            'petition_id' => $quiet->id,
            'confirmed' => true,
            'created_at' => now()->subDays(90),
        ]);

        $html = $this->get('/en/petitions?sort=trending')->assertOk()->getContent();

        $this->assertNotFalse(strpos($html, 'Quiet Megapetition'), 'Trending must still list petitions without recent signatures');
        $this->assertLessThan(
            strpos($html, 'Quiet Megapetition'),
            strpos($html, 'Hot Newcomer'),
            'Petition with the most recent signatures should rank first when sort=trending'
        );
    }

    public function test_category_search_filters_only_that_category(): void
    {
        $matching = Petition::factory()->create([
            'category_id' => $this->category->id,
            'status' => 'published',
            'is_active' => true,
        ]);
        $other = Petition::factory()->create([
            'category_id' => $this->category->id,
            'status' => 'published',
            'is_active' => true,
        ]);

        PetitionTranslation::create([
            'petition_id' => $matching->id,
            'locale' => 'en',
            'title' => 'Protect Test Animals',
            'slug' => 'protect-test-animals',
            'description' => 'Description',
        ]);
        PetitionTranslation::create([
            'petition_id' => $other->id,
            'locale' => 'en',
            'title' => 'Build Safer Roads',
            'slug' => 'build-safer-roads',
            'description' => 'Description',
        ]);

        $this->get("/en/petitions/category-test-category-{$this->category->id}?q=Animals")
            ->assertOk()
            ->assertSee('Protect Test')
            ->assertSee('<mark>Animals</mark>', false)
            ->assertDontSee('Build Safer Roads');

        $this->get("/en/petitions/category-test-category-{$this->category->id}?q=Missing")
            ->assertOk()
            ->assertSee('No petitions found.');
    }

    public function test_category_index_page_loads(): void
    {
        $this->get("/en/petitions/category-test-category-{$this->category->id}")
            ->assertOk();
    }

    public function test_deep_pagination_page_is_clamped(): void
    {
        Petition::factory()->create(['status' => 'published', 'is_active' => true]);

        $this->get('/en/petitions?page=99999')
            ->assertOk();
        $this->get("/en/petitions/category-test-category-{$this->category->id}?page=99999")
            ->assertOk();
    }

    public function test_shoutbox_and_signatures_deep_pagination_is_clamped(): void
    {
        $petition = Petition::factory()->create(['status' => 'published', 'is_active' => true]);
        PetitionTranslation::create([
            'petition_id' => $petition->id,
            'locale' => 'en',
            'title' => 'Deep Pagination Petition',
            'slug' => 'deep-pagination-petition',
            'description' => 'Description',
        ]);

        $this->get("/en/petition/deep-pagination-petition/{$petition->id}?shoutbox=99999")
            ->assertOk();
        $this->get("/en/petition/deep-pagination-petition/{$petition->id}/signatures?page=99999")
            ->assertOk();
    }

    public function test_petition_search_pagination_preserves_query(): void
    {
        foreach (range(1, 16) as $number) {
            $petition = Petition::factory()->create(['status' => 'published', 'is_active' => true]);
            PetitionTranslation::create([
                'petition_id' => $petition->id,
                'locale' => 'en',
                'title' => "Forest Campaign {$number}",
                'slug' => "forest-campaign-{$number}",
                'description' => 'Description',
            ]);
        }

        $this->get('/en/petitions?q=Forest')
            ->assertOk()
            ->assertSee('page=2', false)
            ->assertSee('q=Forest', false);
    }

    public function test_can_view_published_petition(): void
    {
        $petition = Petition::factory()->create([
            'status' => 'published',
            'is_active' => true,
        ]);

        PetitionTranslation::create([
            'petition_id' => $petition->id,
            'locale' => 'en',
            'title' => 'Test Petition',
            'slug' => 'test-petition',
            'description' => '<p>Test description</p>',
        ]);

        $response = $this->get("/en/petition/test-petition/{$petition->id}");
        $response->assertStatus(200);
    }

    public function test_shoutbox_only_contains_real_signing_comments(): void
    {
        $petition = Petition::factory()->create([
            'status' => 'published',
            'is_active' => true,
        ]);

        PetitionTranslation::create([
            'petition_id' => $petition->id,
            'locale' => 'en',
            'title' => 'Shoutbox Petition',
            'slug' => 'shoutbox-petition',
            'description' => 'Description',
        ]);

        Signature::factory()->create([
            'petition_id' => $petition->id,
            'user_id' => $this->user->id,
            'name' => 'Visible Supporter',
            'text' => 'This cause matters to our community.',
            'confirmed' => true,
            'is_spam' => false,
        ]);

        Signature::factory()->create([
            'petition_id' => $petition->id,
            'text' => 'I support this petition',
            'confirmed' => true,
            'is_spam' => false,
        ]);

        $this->get("/en/petition/shoutbox-petition/{$petition->id}")
            ->assertOk()
            ->assertSee('This cause matters to our community.')
            ->assertViewHas('shoutbox', fn ($comments) => $comments->pluck('text')->all() === [
                'This cause matters to our community.',
            ]);
    }

    public function test_petition_view_is_counted_once_per_session(): void
    {
        $petition = Petition::factory()->create(['status' => 'published', 'is_active' => true]);
        PetitionTranslation::create([
            'petition_id' => $petition->id,
            'locale' => 'en',
            'title' => 'Viewed Petition',
            'slug' => 'viewed-petition',
            'description' => 'Description',
        ]);

        $withSession = fn () => $this->withUnencryptedCookie(config('session.cookie'), 'viewer-session');

        $withSession()->get("/en/petition/viewed-petition/{$petition->id}")->assertOk();
        $withSession()->get("/en/petition/viewed-petition/{$petition->id}")->assertOk();

        $this->assertDatabaseHas('petitions', ['id' => $petition->id, 'views' => 1]);
        $this->assertDatabaseHas('petition_views', ['petition_id' => $petition->id, 'views' => 1]);
    }

    public function test_stateless_petition_render_counts_a_view_each_time(): void
    {
        $petition = Petition::factory()->create(['status' => 'published', 'is_active' => true]);
        PetitionTranslation::create([
            'petition_id' => $petition->id,
            'locale' => 'en',
            'title' => 'Stateless Petition',
            'slug' => 'stateless-petition',
            'description' => 'Description',
        ]);

        $this->get("/en/petition/stateless-petition/{$petition->id}")->assertOk();
        $this->get("/en/petition/stateless-petition/{$petition->id}")->assertOk();

        $this->assertDatabaseHas('petitions', ['id' => $petition->id, 'views' => 2]);
    }

    public function test_token_shaped_petition_slug_returns_404(): void
    {
        $this->get('/en/petition/'.rawurlencode('{CONTACTS_URL}').'/1')->assertNotFound();
    }

    public function test_petition_page_looks_up_translations_at_most_once(): void
    {
        $petition = Petition::factory()->create(['status' => 'published', 'is_active' => true]);
        PetitionTranslation::create([
            'petition_id' => $petition->id,
            'locale' => 'en',
            'title' => 'Lookup Once',
            'slug' => 'lookup-once',
            'description' => 'Description',
        ]);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->get("/en/petition/lookup-once/{$petition->id}")->assertOk();

        $lookups = collect(DB::getQueryLog())
            ->filter(fn (array $q) => preg_match('/select .?locale.?, .?slug.? from .petition_translations/i', $q['query']))
            ->count();

        $this->assertSame(1, $lookups);
    }

    public function test_inactive_published_petition_shows_offline_page(): void
    {
        $petition = Petition::factory()->create(['status' => 'published', 'is_active' => false]);
        PetitionTranslation::create([
            'petition_id' => $petition->id,
            'locale' => 'en',
            'title' => 'Offline Petition',
            'slug' => 'offline-petition',
            'description' => 'Description',
        ]);

        $this->get("/en/petition/offline-petition/{$petition->id}")
            ->assertOk()
            ->assertSee('This petition is currently offline.');
    }

    public function test_owner_can_withdraw_petition_and_it_goes_offline(): void
    {
        $petition = Petition::factory()->create(['status' => 'published', 'is_active' => true, 'user_id' => $this->user->id]);
        PetitionTranslation::create([
            'petition_id' => $petition->id,
            'locale' => 'en',
            'title' => 'Withdrawable Petition',
            'slug' => 'withdrawable-petition',
            'description' => 'Description',
        ]);

        $this->get('/en/petitions')->assertSee('Withdrawable Petition');
        $this->actingAs($this->user)->get("/en/petition/withdrawable-petition/{$petition->id}")
            ->assertSee('data-petition-withdraw', false);
        $this->actingAs($this->user)->get('/en/my-petitions')
            ->assertSee("/withdrawable-petition/{$petition->id}/withdraw", false);

        $this->actingAs($this->user)
            ->post("/en/petition/withdrawable-petition/{$petition->id}/withdraw")
            ->assertRedirect(lroute('account.petitions'))
            ->assertSessionHas('success');

        $this->assertSame(0, (int) $petition->fresh()->is_active);
        $this->get("/en/petition/withdrawable-petition/{$petition->id}")
            ->assertOk()
            ->assertSee('This petition is currently offline.');
        $this->get('/en/petitions')->assertDontSee('Withdrawable Petition');
        $this->actingAs($this->user)->get('/en/my-petitions')
            ->assertSee('Withdrawable Petition')
            ->assertDontSee("/withdrawable-petition/{$petition->id}/withdraw", false);
    }

    public function test_non_owner_cannot_withdraw_petition(): void
    {
        $petition = Petition::factory()->create(['status' => 'published', 'is_active' => true]);

        $this->actingAs($this->user)
            ->post("/en/petition/any-slug/{$petition->id}/withdraw")
            ->assertForbidden();

        $this->assertSame(1, (int) $petition->fresh()->is_active);
    }

    public function test_owner_cannot_withdraw_draft_petition(): void
    {
        $petition = Petition::factory()->create(['status' => 'draft', 'is_active' => true, 'user_id' => $this->user->id]);

        $this->actingAs($this->user)
            ->post("/en/petition/any-slug/{$petition->id}/withdraw")
            ->assertForbidden();

        $this->assertSame(1, (int) $petition->fresh()->is_active);
    }

    public function test_guest_cannot_withdraw_petition(): void
    {
        $petition = Petition::factory()->create(['status' => 'published', 'is_active' => true]);

        $this->post("/en/petition/any-slug/{$petition->id}/withdraw")
            ->assertRedirectContains('login');

        $this->assertSame(1, (int) $petition->fresh()->is_active);
    }

    public function test_authed_signature_shows_nickname_when_identify_mode_is_nick(): void
    {
        $user = User::factory()->create([
            'verified' => true,
            'name' => 'Charles Reponsible',
            'first_name' => 'Charles',
            'last_name' => 'Reponsible',
            'nickname' => 'charlesrep',
            'identify_mode' => 'nick',
        ]);

        $petition = Petition::factory()->create(['status' => 'published', 'is_active' => true]);
        PetitionTranslation::create([
            'petition_id' => $petition->id,
            'locale' => 'en',
            'title' => 'Nick Petition',
            'slug' => 'nick-petition',
            'description' => 'Description',
        ]);

        $this->actingAs($user)->post("/en/petition/nick-petition/{$petition->id}/sign", [
            'agree1' => 'agree',
            'agree2' => 'agree',
            'agree3' => 'agree',
            'comment' => 'I support this petition',
        ])->assertRedirect();

        $resp = $this->get("/en/petition/nick-petition/{$petition->id}/signatures")->assertOk();
        $resp->assertSee('charlesrep');
        $resp->assertDontSee('Charles Reponsible');

        // live: switching pref to full flips the displayed name with no re-sign
        $user->update(['identify_mode' => 'full']);
        $full = $this->get("/en/petition/nick-petition/{$petition->id}/signatures")->assertOk();
        $full->assertSee('Charles Reponsible');
    }

    public function test_guest_signing_uses_confirmation_step(): void
    {
        $petition = Petition::factory()->create(['status' => 'published', 'is_active' => true]);
        PetitionTranslation::create([
            'petition_id' => $petition->id,
            'locale' => 'en',
            'title' => 'Confirm Petition',
            'slug' => 'confirm-petition',
            'description' => 'Description',
        ]);

        $confirmation = $this->post("/en/petition/confirm-petition/{$petition->id}/sign/confirm", [
            'name' => 'Guest',
            'email' => 'guest-confirm@example.com',
            'comment' => 'I support this petition',
            'agree1' => '1',
            'agree2' => '1',
            'agree3' => '1',
        ]);

        $confirmation->assertOk()->assertSee('Support and share your cause.');

        $this->assertDatabaseMissing('users', ['email' => 'guest-confirm@example.com']);

        $response = $this->post("/en/petition/confirm-petition/{$petition->id}/sign", [
            'name' => 'Guest',
            'email' => 'guest-confirm@example.com',
            'agree1' => '1',
            'agree2' => '1',
            'agree3' => '1',
            'comment' => 'I support this petition',
        ]);

        $response->assertRedirect("/en/petition/confirm-petition/{$petition->id}/thanks");
        $this->followRedirects($response)->assertSee('Thank you for having signed:');
        $this->assertDatabaseHas('users', ['email' => 'guest-confirm@example.com']);
        $this->assertDatabaseHas('signatures', ['petition_id' => $petition->id, 'email' => 'guest-confirm@example.com']);
    }

    public function test_guest_confirm_form_carries_everything_the_final_post_needs(): void
    {
        $petition = Petition::factory()->create(['status' => 'published', 'is_active' => true]);
        PetitionTranslation::create([
            'petition_id' => $petition->id,
            'locale' => 'en',
            'title' => 'Hidden Petition',
            'slug' => 'hidden-petition',
            'description' => 'Description',
        ]);

        $html = $this->post("/en/petition/hidden-petition/{$petition->id}/sign/confirm", [
            'name' => 'Guest',
            'email' => 'hidden-guest@example.com',
            'comment' => 'I support this petition',
            'agree1' => '1',
            'agree2' => '1',
            'agree3' => '1',
        ])->assertOk()->getContent();

        preg_match_all('/<input type="hidden" name="([^"]+)" value="([^"]*)"/', $html, $m);
        $fields = array_combine($m[1], array_map('html_entity_decode', $m[2]));
        unset($fields['_token']);

        $this->assertSame('Guest', $fields['name'] ?? null);
        $this->assertSame('hidden-guest@example.com', $fields['email'] ?? null);

        $this->post("/en/petition/hidden-petition/{$petition->id}/sign", $fields)
            ->assertSessionHasNoErrors()
            ->assertRedirect("/en/petition/hidden-petition/{$petition->id}/thanks");
        $this->assertDatabaseHas('signatures', ['petition_id' => $petition->id, 'email' => 'hidden-guest@example.com']);
    }

    public function test_guest_signing_blocked_when_turnstile_fails(): void
    {
        Settings::set('turnstile_enabled', true, 'global');
        Settings::set('turnstile_site_key', 'site-key', 'global');
        Settings::set('turnstile_secret', 'secret-key', 'global');

        Http::fake([
            'challenges.cloudflare.com/*' => Http::response(['success' => false], 200),
        ]);

        $petition = Petition::factory()->create(['status' => 'published', 'is_active' => true]);
        PetitionTranslation::create([
            'petition_id' => $petition->id,
            'locale' => 'en',
            'title' => 'Turnstile Petition',
            'slug' => 'turnstile-petition',
            'description' => 'Description',
        ]);

        $response = $this->post("/en/petition/turnstile-petition/{$petition->id}/sign", [
            'name' => 'Bot',
            'email' => 'bot@example.com',
            'comment' => 'I support this petition',
            'agree1' => '1',
            'agree2' => '1',
            'agree3' => '1',
            'cf-turnstile-response' => 'bad-token',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseMissing('signatures', ['petition_id' => $petition->id, 'email' => 'bot@example.com']);
        $this->assertDatabaseMissing('users', ['email' => 'bot@example.com']);
    }

    public function test_guest_signing_passes_when_turnstile_succeeds(): void
    {
        Settings::set('turnstile_enabled', true, 'global');
        Settings::set('turnstile_site_key', 'site-key', 'global');
        Settings::set('turnstile_secret', 'secret-key', 'global');

        Http::fake([
            'challenges.cloudflare.com/*' => Http::response(['success' => true], 200),
        ]);

        $petition = Petition::factory()->create(['status' => 'published', 'is_active' => true]);
        PetitionTranslation::create([
            'petition_id' => $petition->id,
            'locale' => 'en',
            'title' => 'Turnstile Pass',
            'slug' => 'turnstile-pass',
            'description' => 'Description',
        ]);

        $response = $this->post("/en/petition/turnstile-pass/{$petition->id}/sign", [
            'name' => 'Human',
            'email' => 'human@example.com',
            'comment' => 'I support this petition',
            'agree1' => '1',
            'agree2' => '1',
            'agree3' => '1',
            'cf-turnstile-response' => 'good-token',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('signatures', ['petition_id' => $petition->id, 'email' => 'human@example.com']);
    }

    public function test_guest_turnstile_token_from_confirm_page_reaches_sign(): void
    {
        Settings::set('turnstile_enabled', true, 'global');
        Settings::set('turnstile_site_key', 'site-key', 'global');
        Settings::set('turnstile_secret', 'secret-key', 'global');

        Http::fake([
            'challenges.cloudflare.com/*' => Http::response(['success' => true], 200),
        ]);

        $petition = Petition::factory()->create(['status' => 'published', 'is_active' => true]);
        PetitionTranslation::create([
            'petition_id' => $petition->id,
            'locale' => 'en',
            'title' => 'Turnstile Confirm',
            'slug' => 'turnstile-confirm',
            'description' => 'Description',
        ]);

        $confirmUrl = "/en/petition/turnstile-confirm/{$petition->id}/sign/confirm";

        // The old bug put the widget on this first guest form, whose token sign/confirm drops.
        $this->get($confirmUrl)->assertOk()->assertDontSee('cf-turnstile');

        $fields = [
            'name' => 'Human',
            'email' => 'human@example.com',
            'comment' => 'I support this petition',
            'agree1' => '1',
            'agree2' => '1',
            'agree3' => '1',
        ];

        $this->post($confirmUrl, $fields)->assertOk()->assertSeeInOrder([
            "/en/petition/turnstile-confirm/{$petition->id}/sign\" method=\"POST\"",
            'cf-turnstile',
            'data-sitekey="site-key"',
            '</form>',
        ], false);

        $response = $this->post("/en/petition/turnstile-confirm/{$petition->id}/sign", session("sign.{$petition->id}") + [
            'cf-turnstile-response' => 'good-token',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('signatures', ['petition_id' => $petition->id, 'email' => 'human@example.com']);
    }

    public function test_thanks_page_uses_the_signed_petition_locale(): void
    {
        $petition = Petition::factory()->create([
            'status' => 'published',
            'is_active' => true,
        ]);
        PetitionTranslation::create([
            'petition_id' => $petition->id,
            'locale' => 'pt',
            'title' => 'Proteja a rede elétrica',
            'slug' => 'proteja-a-rede-eletrica',
            'description' => 'Descrição da petição em português.',
        ]);

        $suggestion = Petition::factory()->create([
            'status' => 'published',
            'is_active' => true,
            'signature_count' => 50,
        ]);
        PetitionTranslation::create([
            'petition_id' => $suggestion->id,
            'locale' => 'pt',
            'title' => 'Melhore os transportes públicos',
            'slug' => 'melhore-os-transportes-publicos',
            'description' => 'Descrição da sugestão em português.',
        ]);

        $englishSuggestion = Petition::factory()->create([
            'status' => 'published',
            'is_active' => true,
            'signature_count' => 100,
        ]);
        PetitionTranslation::create([
            'petition_id' => $englishSuggestion->id,
            'locale' => 'en',
            'title' => 'English-only recommendation',
            'slug' => 'english-only-recommendation',
            'description' => 'English description.',
        ]);

        $response = $this->post("/en/petition/proteja-a-rede-eletrica/{$petition->id}/sign", [
            'name' => 'Visitante',
            'email' => 'visitante-portugues@example.com',
            'comment' => 'Apoio esta petição.',
            'agree1' => '1',
            'agree2' => '1',
            'agree3' => '1',
        ]);

        $thanksUrl = "/pt/petition/proteja-a-rede-eletrica/{$petition->id}/thanks";

        $response->assertRedirect($thanksUrl);

        $this->get($thanksUrl)
            ->assertOk()
            ->assertSee('Obrigado por assinar:')
            ->assertSee('Petições que poderão interessar-lhe')
            ->assertSee('Melhore os transportes públicos')
            ->assertDontSee('English-only recommendation')
            ->assertDontSee('Thank you for having signed:')
            ->assertDontSee('Petitions you might like');

        $this->get("/en/petition/proteja-a-rede-eletrica/{$petition->id}/thanks")
            ->assertRedirect($thanksUrl);
    }

    public function test_thanks_page_share_button_uses_mailto_fallback_with_petition_details(): void
    {
        $petition = Petition::factory()->create([
            'status' => 'published',
            'is_active' => true,
        ]);
        PetitionTranslation::create([
            'petition_id' => $petition->id,
            'locale' => 'en',
            'title' => 'Protect Trees & Rivers',
            'slug' => 'protect-trees-rivers',
            'description' => 'Description',
        ]);

        $petitionUrl = route('petition.show', [
            'locale' => 'en',
            'slug' => 'protect-trees-rivers',
            'id' => $petition->id,
        ]);
        $mailUrl = 'mailto:?'.http_build_query([
            'subject' => 'Protect Trees & Rivers',
            'body' => "Protect Trees & Rivers\r\n\r\n{$petitionUrl}",
        ], '', '&', PHP_QUERY_RFC3986);

        $this->get("/en/petition/protect-trees-rivers/{$petition->id}/thanks")
            ->assertOk()
            ->assertSee('data-share-petition', false)
            ->assertSee('data-share-title="Protect Trees &amp; Rivers"', false)
            ->assertSee('data-share-url="'.e($petitionUrl).'"', false)
            ->assertSee('href="'.e($mailUrl).'"', false);
    }

    public function test_thanks_page_next_milestone_reaches_the_largest_allowed_goal(): void
    {
        $petition = Petition::factory()->create([
            'status' => 'published',
            'is_active' => true,
        ]);
        $petition->forceFill(['signature_count' => 1500000])->save();
        PetitionTranslation::create([
            'petition_id' => $petition->id,
            'locale' => 'en',
            'title' => 'Big Goal',
            'slug' => 'big-goal',
            'description' => 'Description',
        ]);

        $this->get("/en/petition/big-goal/{$petition->id}/thanks")
            ->assertOk()
            ->assertSee('reach '.number_format(max(Petition::GOALS)));
    }

    public function test_existing_email_sign_mirrors_guest_success(): void
    {
        $user = User::factory()->create([
            'email' => 'existing@example.com',
            'password' => Hash::make('password123'),
            'verified' => false,
        ]);
        $petition = Petition::factory()->create(['status' => 'published', 'is_active' => true]);
        PetitionTranslation::create([
            'petition_id' => $petition->id,
            'locale' => 'en',
            'title' => 'Existing User Petition',
            'slug' => 'existing-user-petition',
            'description' => 'Description',
        ]);

        $usersBefore = User::count();

        $this->post("/en/petition/existing-user-petition/{$petition->id}/sign", [
            'name' => 'Existing',
            'email' => $user->email,
            'agree1' => '1',
            'agree2' => '1',
            'agree3' => '1',
        ])->assertRedirect("/en/petition/existing-user-petition/{$petition->id}/thanks");

        $this->assertDatabaseMissing('signatures', ['petition_id' => $petition->id, 'user_id' => $user->id]);
        $this->assertSame($usersBefore, User::count());
    }

    public function test_petition_widget_script_and_render_endpoints_work(): void
    {
        $petition = Petition::factory()->create(['status' => 'published', 'is_active' => true]);
        PetitionTranslation::create([
            'petition_id' => $petition->id,
            'locale' => 'en',
            'title' => 'Widget Petition',
            'slug' => 'widget-petition',
            'description' => 'Description',
        ]);

        $this->get("/widgets/pbadge/{$petition->id}/728x90")
            ->assertOk()
            ->assertHeader('Content-Type', 'text/javascript; charset=UTF-8')
            ->assertSee("/widget-render/pbadge/{$petition->id}/728x90", false);

        $this->get("/widget-render/pbadge/{$petition->id}/728x90")
            ->assertOk()
            ->assertSee('Widget Petition');
    }

    public function test_widget_render_can_be_framed_by_other_sites(): void
    {
        $petition = Petition::factory()->create([
            'status' => 'published',
            'is_active' => true,
        ]);

        PetitionTranslation::create([
            'petition_id' => $petition->id,
            'locale' => 'en',
            'title' => 'Frameable Petition',
            'slug' => 'frameable-petition',
            'description' => 'Description',
        ]);

        $response = $this->get("/widget-render/pbadge/{$petition->id}/728x90")
            ->assertOk()
            ->assertHeaderMissing('X-Frame-Options');

        $this->assertStringContainsString('frame-ancestors *', $response->headers->get('Content-Security-Policy'));

        $this->get('/')
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN');
    }

    public function test_cannot_sign_draft_petition(): void
    {
        $petition = Petition::factory()->create([
            'status' => 'draft',
            'is_active' => false,
        ]);

        PetitionTranslation::create([
            'petition_id' => $petition->id,
            'locale' => 'en',
            'title' => 'Draft Petition',
            'slug' => 'draft-petition',
            'description' => 'Draft petition description.',
        ]);

        $response = $this->post("/en/petition/draft-petition/{$petition->id}/sign", [
            'name' => 'Test User',
            'surname' => 'Last',
            'email' => 'test@example.com',
            'password' => 'password123',
            'agree1' => 'agree',
            'agree2' => 'agree',
            'agree3' => 'agree',
        ]);

        $response->assertStatus(404);
    }

    public function test_cannot_sign_inactive_petition(): void
    {
        $petition = Petition::factory()->create([
            'status' => 'published',
            'is_active' => false,
        ]);

        PetitionTranslation::create([
            'petition_id' => $petition->id,
            'locale' => 'en',
            'title' => 'Inactive Petition',
            'slug' => 'inactive-petition',
            'description' => 'Inactive petition description.',
        ]);

        $response = $this->post("/en/petition/inactive-petition/{$petition->id}/sign", [
            'name' => 'Test User',
            'surname' => 'Last',
            'email' => 'test@example.com',
            'password' => 'password123',
            'agree1' => 'agree',
            'agree2' => 'agree',
            'agree3' => 'agree',
        ]);

        $response->assertStatus(404);
    }

    public function test_authenticated_user_can_sign_petition(): void
    {
        $petition = Petition::factory()->create([
            'status' => 'published',
            'is_active' => true,
            'signature_count' => 0,
        ]);

        PetitionTranslation::create([
            'petition_id' => $petition->id,
            'locale' => 'en',
            'title' => 'Test Petition',
            'slug' => 'test-petition',
            'description' => 'Test petition description.',
        ]);

        $response = $this->actingAs($this->user)->post("/en/petition/test-petition/{$petition->id}/sign", [
            'comment' => 'I support this!',
            'agree1' => 'agree',
            'agree2' => 'agree',
            'agree3' => 'agree',
        ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('signatures', [
            'petition_id' => $petition->id,
            'email' => $this->user->email,
            'confirmed' => true,
        ]);

        $response->assertRedirect("/en/petition/test-petition/{$petition->id}/thanks");

        $petition->refresh();
        $this->assertEquals(1, $petition->signature_count);
    }

    public function test_guest_can_sign_petition_and_creates_account(): void
    {
        $petition = Petition::factory()->create([
            'status' => 'published',
            'is_active' => true,
            'signature_count' => 0,
        ]);

        PetitionTranslation::create([
            'petition_id' => $petition->id,
            'locale' => 'en',
            'title' => 'Test Petition',
            'slug' => 'test-petition',
            'description' => 'Test petition description.',
        ]);

        $response = $this->post("/en/petition/test-petition/{$petition->id}/sign", [
            'name' => 'Test',
            'email' => 'newuser@example.com',
            'comment' => 'I fully support this cause!',
            'agree1' => '1',
            'agree2' => '1',
            'agree3' => '1',
        ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('users', [
            'email' => 'newuser@example.com',
            'password' => null,
        ]);

        $this->assertDatabaseHas('signatures', [
            'petition_id' => $petition->id,
            'email' => 'newuser@example.com',
        ]);
    }

    public function test_verifying_guest_confirms_signature_and_updates_counter(): void
    {
        Settings::set('require_email_verification', true);

        $petition = Petition::factory()->create([
            'status' => 'published',
            'is_active' => true,
            'signature_count' => 0,
        ]);
        PetitionTranslation::create([
            'petition_id' => $petition->id,
            'locale' => 'en',
            'title' => 'Verification Petition',
            'slug' => 'verification-petition',
            'description' => 'Test petition description.',
        ]);

        $this->post("/en/petition/verification-petition/{$petition->id}/sign", [
            'name' => 'Pending',
            'email' => 'pending@example.com',
            'agree1' => '1',
            'agree2' => '1',
            'agree3' => '1',
        ])->assertRedirect("/en/petition/verification-petition/{$petition->id}/thanks");

        $user = User::where('email', 'pending@example.com')->firstOrFail();
        $this->assertNotNull($user->verification_token_sent_at);
        $this->assertDatabaseHas('signatures', [
            'petition_id' => $petition->id,
            'user_id' => $user->id,
            'confirmed' => false,
        ]);
        $this->assertDatabaseHas('petitions', ['id' => $petition->id, 'signature_count' => 0]);

        $this->post('/en/verify/'.$this->knownVerificationToken($user), [])->assertRedirect('/en/email-verified');

        $this->assertDatabaseHas('users', ['id' => $user->id, 'verified' => true]);
        $this->assertDatabaseHas('signatures', [
            'petition_id' => $petition->id,
            'user_id' => $user->id,
            'confirmed' => true,
        ]);
        $this->assertDatabaseHas('petitions', ['id' => $petition->id, 'signature_count' => 1]);
    }

    public function test_guest_sign_with_existing_unverified_account_counts_after_verify(): void
    {
        Settings::set('require_email_verification', true);

        $user = User::factory()->create([
            'email' => 'existing@example.com',
            'verified' => false,
            'verification_token' => Str::random(64),
            'verification_token_sent_at' => now(),
        ]);
        $petition = $this->makePublishedPetition('existing-account-petition');
        $payload = [
            'name' => 'Existing',
            'email' => 'existing@example.com',
            'agree1' => '1',
            'agree2' => '1',
            'agree3' => '1',
        ];

        $this->post("/en/petition/existing-account-petition/{$petition->id}/sign", $payload)
            ->assertRedirect("/en/petition/existing-account-petition/{$petition->id}/thanks");

        $this->assertSame(1, Signature::where('petition_id', $petition->id)->count());
        $this->assertDatabaseHas('signatures', [
            'petition_id' => $petition->id,
            'user_id' => $user->id,
            'confirmed' => false,
        ]);

        $this->post("/en/petition/existing-account-petition/{$petition->id}/sign", $payload)
            ->assertRedirect("/en/petition/existing-account-petition/{$petition->id}/thanks");
        $this->assertSame(1, Signature::where('petition_id', $petition->id)->count());

        $this->post('/en/verify/'.$this->knownVerificationToken($user), [])->assertRedirect('/en/email-verified');

        $this->assertDatabaseHas('signatures', [
            'petition_id' => $petition->id,
            'user_id' => $user->id,
            'confirmed' => true,
        ]);
        $this->assertSame(1, (int) $petition->fresh()->signature_count);
    }

    public function test_guest_sign_with_email_of_account_that_already_signed_returns_thanks(): void
    {
        Settings::set('require_email_verification', true);

        $user = User::factory()->create(['email' => 'signed-before@example.com', 'verified' => false]);
        $petition = $this->makePublishedPetition('already-signed-account-petition');
        Signature::create([
            'petition_id' => $petition->id,
            'user_id' => $user->id,
            'email' => 'other-address@example.com',
            'name' => 'Earlier',
            'locale' => 'en',
            'text' => 'I support this petition',
            'confirmed' => false,
        ]);

        $this->post("/en/petition/already-signed-account-petition/{$petition->id}/sign", [
            'name' => 'Again',
            'email' => 'signed-before@example.com',
            'agree1' => '1',
            'agree2' => '1',
            'agree3' => '1',
        ])->assertRedirect("/en/petition/already-signed-account-petition/{$petition->id}/thanks")
            ->assertSessionHasNoErrors();

        $this->assertSame(1, Signature::where('petition_id', $petition->id)->count());
    }

    public function test_guest_verify_link_resolves_on_non_default_locales(): void
    {
        Settings::set('require_email_verification', true);

        foreach (['it', 'pt'] as $locale) {
            $user = User::factory()->create([
                'verified' => false,
                'locale' => $locale,
            ]);
            $token = $user->issueVerificationToken();
            $user->save();
            $url = (new VerifyAccountMail($user, $locale))->render();
            $this->assertStringContainsString('/'.$locale.'/verify/'.$token, $url);
            $this->post('/'.$locale.'/verify/'.$token)->assertRedirect('/'.$locale.'/email-verified');
        }
    }

    public function test_authenticated_confirm_page_signs_with_its_rendered_values(): void
    {
        $petition = Petition::factory()->create([
            'status' => 'published',
            'is_active' => true,
        ]);

        PetitionTranslation::create([
            'petition_id' => $petition->id,
            'locale' => 'en',
            'title' => 'Confirm Authed',
            'slug' => 'confirm-authed',
            'description' => 'Description',
        ]);

        $page = $this->actingAs($this->user)
            ->get("/en/petition/confirm-authed/{$petition->id}/sign/confirm")
            ->assertOk();

        preg_match_all('/name="(agree[123])" value="([^"]+)"/', $page->getContent(), $matches);
        $agreements = array_combine($matches[1], $matches[2]);

        $this->assertCount(3, $agreements);

        $response = $this->post("/en/petition/confirm-authed/{$petition->id}/sign", $agreements);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect("/en/petition/confirm-authed/{$petition->id}/thanks");
        $this->assertDatabaseHas('signatures', [
            'petition_id' => $petition->id,
            'user_id' => $this->user->id,
        ]);
    }

    public function test_guest_signing_accepts_the_consent_value_the_form_submits(): void
    {
        $petition = Petition::factory()->create([
            'status' => 'published',
            'is_active' => true,
        ]);

        PetitionTranslation::create([
            'petition_id' => $petition->id,
            'locale' => 'en',
            'title' => 'Consent Value',
            'slug' => 'consent-value',
            'description' => 'Description',
        ]);

        $form = $this->get("/en/petition/consent-value/{$petition->id}/sign/confirm")->assertOk();

        preg_match_all('/name="(agree[123])" value="([^"]+)"/', $form->getContent(), $matches);
        $agreements = array_combine($matches[1], $matches[2]);

        $this->assertCount(3, $agreements);

        $confirmation = $this->post("/en/petition/consent-value/{$petition->id}/sign/confirm", array_merge($agreements, [
            'name' => 'Consent Guest',
            'email' => 'consent-guest@example.com',
            'comment' => 'I support this petition',
        ]));

        $confirmation->assertSessionHasNoErrors();
        $confirmation->assertOk();
        $confirmation->assertSee("/en/petition/consent-value/{$petition->id}/sign\"", false);

        $response = $this->post("/en/petition/consent-value/{$petition->id}/sign", array_merge($agreements, [
            'name' => 'Consent Guest',
            'email' => 'consent-guest@example.com',
            'comment' => 'I support this petition',
        ]));

        $response->assertSessionHasNoErrors();
        $response->assertRedirect("/en/petition/consent-value/{$petition->id}/thanks");
        $this->assertDatabaseHas('signatures', [
            'petition_id' => $petition->id,
            'email' => 'consent-guest@example.com',
        ]);
    }

    public function test_cannot_sign_same_petition_twice(): void
    {
        $petition = Petition::factory()->create([
            'status' => 'published',
            'is_active' => true,
        ]);

        PetitionTranslation::create([
            'petition_id' => $petition->id,
            'locale' => 'en',
            'title' => 'Test Petition',
            'slug' => 'test-petition',
            'description' => 'Test petition description.',
        ]);

        Signature::create([
            'petition_id' => $petition->id,
            'user_id' => $this->user->id,
            'email' => $this->user->email,
            'name' => $this->user->name,
            'locale' => 'en',
        ]);

        $response = $this->actingAs($this->user)->post("/en/petition/test-petition/{$petition->id}/sign", [
            'comment' => 'I support this!',
            'agree1' => 'agree',
            'agree2' => 'agree',
            'agree3' => 'agree',
        ]);

        $response->assertSessionHas('toast.type', 'info');
    }

    public function test_guest_create_page_shows_tabbed_authentication_without_petition_form(): void
    {
        $this->get('/en/create-petition')
            ->assertOk()
            ->assertSee('id="create-login-tab"', false)
            ->assertSee('id="create-register-tab"', false)
            ->assertSee('id="create-login-pane"', false)
            ->assertDontSee('data-petition-form', false);
    }

    public function test_authenticated_create_page_shows_petition_form_without_authentication_tabs(): void
    {
        $this->actingAs($this->user)->get('/en/create-petition')
            ->assertOk()
            ->assertSee('data-petition-form', false)
            ->assertSee('class="text-center max-md:[&_.fcui-btn]:w-full"', false)
            ->assertDontSee('position: fixed;', false)
            ->assertDontSee('id="create-login-tab"', false)
            ->assertDontSee('id="create-register-tab"', false);
    }

    public function test_create_page_offers_one_goal_option_per_petition_goal(): void
    {
        $html = $this->actingAs($this->user)->get('/en/create-petition')->assertOk()->getContent();

        preg_match('/<select id="field-goal_signatures".*?<\/select>/s', $html, $select);
        preg_match_all('/<option value="(\d+)"/', $select[0], $values);

        $this->assertSame(Petition::GOALS, array_map('intval', $values[1]));
    }

    public function test_petition_creation_requires_authentication(): void
    {
        $response = $this->post('/en/create-petition', [
            'title' => 'Test Petition Title Here',
            'description' => '<p>This is a test petition with enough content to pass validation.</p>',
            'goal_signatures' => 100,
            'category_id' => $this->category->id,
        ]);

        $response->assertRedirect('/en/login');
    }

    public function test_authenticated_user_can_create_petition(): void
    {
        $response = $this->actingAs($this->user)->post('/en/create-petition', [
            'title' => 'Test Petition Title Here',
            'description' => '<p>This is a test petition with enough content to pass validation and be accepted.</p>',
            'goal_signatures' => 100,
            'category_id' => $this->category->id,
        ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('petitions', [
            'user_id' => $this->user->id,
            'status' => 'published',
        ]);
    }

    public function test_inactive_category_cannot_be_used_for_creation(): void
    {
        $inactive = Category::factory()->create(['is_active' => false]);

        $this->actingAs($this->user)->post('/en/create-petition', [
            'title' => 'Test Petition Title Here',
            'description' => '<p>This description contains enough meaningful content for validation.</p>',
            'goal_signatures' => 100,
            'category_id' => $inactive->id,
        ])->assertSessionHasErrors('category_id');
    }

    public function test_description_length_is_capped_at_legacy_text_limit_on_create_and_update(): void
    {
        $create = fn (int $length) => $this->actingAs($this->user)->post('/en/create-petition', [
            'title' => 'Test Petition Title Here',
            'description' => str_repeat('a', $length),
            'goal_signatures' => 100,
            'category_id' => $this->category->id,
        ]);

        $create(65536)->assertSessionHasErrors([
            'description' => 'The petition text may not be longer than 65535 characters.',
        ]);
        $this->assertDatabaseMissing('petitions', ['user_id' => $this->user->id]);

        $create(65535)->assertSessionHasNoErrors()->assertRedirect();
        $petition = Petition::where('user_id', $this->user->id)->sole();
        $slug = $petition->translations()->value('slug');

        $update = fn (int $length) => $this->actingAs($this->user)->post("/en/petition/{$slug}/{$petition->id}/edit", [
            'title' => 'Test Petition Title Here',
            'description' => str_repeat('b', $length),
            'category_id' => $this->category->id,
        ]);

        $update(65536)->assertSessionHasErrors('description');
        $update(65535)->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame(65535, strlen($petition->translations()->value('description')));
    }

    public function test_creation_accepts_legacy_youtube_id_and_extensionless_image_url(): void
    {
        $this->actingAs($this->user)->post('/en/create-petition', [
            'title' => 'Test Petition Title Here',
            'description' => '<p>This description contains enough meaningful content for validation.</p>',
            'goal_signatures' => 100,
            'category_id' => $this->category->id,
            'youtube' => 'dQw4w9WgXcQ',
            'image_url' => 'https://images.example.com/render?id=123',
        ])->assertRedirect();

        $this->assertDatabaseHas('petitions', [
            'user_id' => $this->user->id,
            'youtube_url' => 'dQw4w9WgXcQ',
            'image_url' => 'https://images.example.com/render?id=123',
        ]);
    }

    public function test_youtube_id_parser_accepts_youtube_links_and_rejects_everything_else(): void
    {
        foreach ([
            'dQw4w9WgXcQ',
            'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            'http://youtube.com/watch?v=dQw4w9WgXcQ',
            'https://www.youtube.com/watch?v=dQw4w9WgXcQ&t=10',
            'https://www.youtube.com/watch?feature=share&v=dQw4w9WgXcQ',
            'https://m.youtube.com/watch?v=dQw4w9WgXcQ',
            'https://www.youtube.com/shorts/dQw4w9WgXcQ',
            'https://www.youtube.com/embed/dQw4w9WgXcQ',
            'https://www.youtube.com/live/dQw4w9WgXcQ',
            'https://youtu.be/dQw4w9WgXcQ',
            'https://youtu.be/dQw4w9WgXcQ?si=abc',
            'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ',
            'https://youtube-nocookie.com/embed/dQw4w9WgXcQ?start=5',
        ] as $value) {
            $this->assertSame('dQw4w9WgXcQ', Petition::youtubeId($value), $value);
        }

        foreach ([
            null,
            '',
            'dQw4w9WgXc',
            'dQw4w9WgXcQQ',
            'https://www.youtube.com/watch?v=dQw4w9WgXc',
            'https://www.youtube.com/watch?v=dQw4w9WgXcQQ',
            'https://www.youtube.com/watch?xv=dQw4w9WgXcQ',
            'https://evil.example.com/watch?v=dQw4w9WgXcQ',
            'https://youtube.com.evil.example/watch?v=dQw4w9WgXcQ',
            'https://notyoutu.be/dQw4w9WgXcQ',
            'javascript:alert(1)//youtu.be/dQw4w9WgXcQ',
            'ftp://youtu.be/dQw4w9WgXcQ',
            'https://www.youtube.com/channel/dQw4w9WgXcQ',
            "dQw4w9WgXcQ\n",
            "https://youtu.be/dQw4w9WgXcQ\n",
            'https://youtube-nocookie.com.evil.example/embed/dQw4w9WgXcQ',
            'https://www.youtube-nocookie.com/watch?v=dQw4w9WgXcQ',
        ] as $value) {
            $this->assertNull(Petition::youtubeId($value), (string) $value);
        }
    }

    public function test_creation_accepts_common_youtube_links_and_stores_the_id(): void
    {
        foreach ([
            'https://www.youtube.com/watch?v=dQw4w9WgXcQ&t=10',
            'https://youtu.be/dQw4w9WgXcQ?si=abc',
            'https://m.youtube.com/watch?v=dQw4w9WgXcQ',
            'https://www.youtube.com/shorts/dQw4w9WgXcQ',
        ] as $i => $link) {
            $this->actingAs($this->user)->post('/en/create-petition', [
                'title' => "Test Petition Title Here {$i}",
                'description' => '<p>This description contains enough meaningful content for validation.</p>',
                'goal_signatures' => 100,
                'category_id' => $this->category->id,
                'youtube' => $link,
            ])->assertSessionHasNoErrors()->assertRedirect();
        }

        $this->assertSame(4, Petition::where('youtube_url', 'dQw4w9WgXcQ')->count());
    }

    public function test_creation_rejects_a_non_youtube_video_link(): void
    {
        $this->actingAs($this->user)->post('/en/create-petition', [
            'title' => 'Test Petition Title Here',
            'description' => '<p>This description contains enough meaningful content for validation.</p>',
            'goal_signatures' => 100,
            'category_id' => $this->category->id,
            'youtube' => 'https://vimeo.com/dQw4w9WgXcQ',
        ])->assertSessionHasErrors('youtube');
    }

    public function test_show_page_embeds_the_youtube_video_only_when_the_stored_value_parses(): void
    {
        foreach ([
            'dQw4w9WgXcQ' => true,
            'https://www.youtube.com/watch?v=dQw4w9WgXcQ' => true,
            'not a video <script>' => false,
            '' => false,
        ] as $stored => $embeds) {
            $petition = Petition::factory()->create(['status' => 'published', 'is_active' => true, 'youtube_url' => $stored ?: null]);
            $slug = 'video-petition-'.$petition->id;
            PetitionTranslation::create([
                'petition_id' => $petition->id,
                'locale' => 'en',
                'title' => 'Video Petition',
                'slug' => $slug,
                'description' => 'Description',
            ]);

            $response = $this->get("/en/petition/{$slug}/{$petition->id}")->assertOk();
            if ($embeds) {
                $response->assertSee('<iframe src="https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ" title="Video: Video Petition"', false);
            } else {
                $response->assertDontSee('youtube-nocookie.com/embed', false);
            }
        }
    }

    public function test_uploaded_image_keeps_format_and_aspect_ratio(): void
    {
        Storage::fake('public');

        $this->actingAs($this->user)->post('/en/create-petition', [
            'title' => 'Test Petition Title Here',
            'description' => '<p>This description contains enough meaningful content for validation.</p>',
            'goal_signatures' => 100,
            'category_id' => $this->category->id,
            'image' => UploadedFile::fake()->image('portrait.png', 400, 1200),
        ])->assertRedirect();

        $petition = Petition::where('user_id', $this->user->id)->latest('id')->firstOrFail();
        [$width, $height, $type] = getimagesize(Storage::disk('public')->path($petition->cover_image));

        $this->assertSame(267, $width);
        $this->assertSame(800, $height);
        $this->assertSame(IMAGETYPE_PNG, $type);
    }

    public function test_webp_image_is_stored_and_linked_to_created_petition(): void
    {
        Storage::fake('public');

        $response = $this->actingAs($this->user)->post('/en/create-petition', [
            'title' => 'Test Petition Title Here',
            'description' => '<p>This description contains enough meaningful content for validation.</p>',
            'goal_signatures' => 100,
            'category_id' => $this->category->id,
            'image' => UploadedFile::fake()->image('petition.webp', 1200, 600),
        ]);

        $response->assertSessionHasNoErrors()->assertRedirect();

        $petition = Petition::where('user_id', $this->user->id)->latest('id')->firstOrFail();
        $this->assertNotNull($petition->cover_image);
        Storage::disk('public')->assertExists($petition->cover_image);
        $this->assertSame(IMAGETYPE_WEBP, getimagesize(Storage::disk('public')->path($petition->cover_image))[2]);
    }

    public function test_oversized_image_does_not_create_petition(): void
    {
        Storage::fake('public');

        $this->actingAs($this->user)->post('/en/create-petition', [
            'title' => 'Test Petition Title Here',
            'description' => '<p>This description contains enough meaningful content for validation.</p>',
            'goal_signatures' => 100,
            'category_id' => $this->category->id,
            'image' => UploadedFile::fake()->create('large.webp', 4097, 'image/webp'),
        ])->assertSessionHasErrors('image');

        $this->assertDatabaseMissing('petitions', ['user_id' => $this->user->id]);
    }

    public function test_php_rejected_upload_does_not_create_petition(): void
    {
        $rejectedUpload = new UploadedFile(
            '',
            'large.webp',
            'image/webp',
            UPLOAD_ERR_INI_SIZE,
            true,
        );

        $this->actingAs($this->user)->post('/en/create-petition', [
            'title' => 'Test Petition Title Here',
            'description' => '<p>This description contains enough meaningful content for validation.</p>',
            'goal_signatures' => 100,
            'category_id' => $this->category->id,
            'image' => $rejectedUpload,
        ])->assertSessionHasErrors('image');

        $this->assertDatabaseMissing('petitions', ['user_id' => $this->user->id]);
    }

    public function test_image_file_and_external_url_cannot_be_submitted_together(): void
    {
        Storage::fake('public');

        $this->actingAs($this->user)->post('/en/create-petition', [
            'title' => 'Test Petition Title Here',
            'description' => '<p>This description contains enough meaningful content for validation.</p>',
            'goal_signatures' => 100,
            'category_id' => $this->category->id,
            'image' => UploadedFile::fake()->image('petition.webp'),
            'image_url' => 'https://images.example.com/petition.webp',
        ])->assertSessionHasErrors(['image', 'image_url']);

        $this->assertDatabaseMissing('petitions', ['user_id' => $this->user->id]);
    }

    public function test_owner_cannot_edit_after_another_user_signed(): void
    {
        $petition = Petition::factory()->create([
            'user_id' => $this->user->id,
            'category_id' => $this->category->id,
        ]);
        PetitionTranslation::create([
            'petition_id' => $petition->id,
            'locale' => 'en',
            'title' => 'Original Petition Title',
            'slug' => 'original-petition-title',
            'description' => 'Original petition description.',
        ]);
        $signer = User::factory()->create();
        Signature::create([
            'petition_id' => $petition->id,
            'user_id' => $signer->id,
            'name' => $signer->name,
            'email' => $signer->email,
            'locale' => 'en',
        ]);

        $this->actingAs($this->user)
            ->get("/en/petition/original-petition-title/{$petition->id}/edit")
            ->assertForbidden();
    }

    public function test_show_hides_edit_link_once_another_user_signed(): void
    {
        $petition = $this->ownedPublishedPetition();
        $this->actingAs($this->user)
            ->get("/en/petition/original-petition-title/{$petition->id}")
            ->assertOk()
            ->assertSee("/en/petition/original-petition-title/{$petition->id}/edit", false);

        Signature::factory()->create(['petition_id' => $petition->id, 'user_id' => User::factory()->create()->id, 'confirmed' => true, 'is_spam' => false]);

        $this->actingAs($this->user)
            ->get("/en/petition/original-petition-title/{$petition->id}")
            ->assertOk()
            ->assertDontSee("/en/petition/original-petition-title/{$petition->id}/edit", false);
    }

    public function test_show_keeps_edit_link_when_only_owner_signed(): void
    {
        $petition = $this->ownedPublishedPetition();
        Signature::factory()->create(['petition_id' => $petition->id, 'user_id' => $this->user->id, 'confirmed' => true, 'is_spam' => false]);

        $this->actingAs($this->user)
            ->get("/en/petition/original-petition-title/{$petition->id}")
            ->assertOk()
            ->assertSee("/en/petition/original-petition-title/{$petition->id}/edit", false);
    }

    private function ownedPublishedPetition(): Petition
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
            'description' => 'Original petition description.',
        ]);

        return $petition;
    }

    #[DataProvider('nonCountingSignatures')]
    public function test_owner_can_still_edit_when_only_spam_or_unconfirmed_signatures_exist(array $state): void
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
            'description' => 'Original petition description.',
        ]);
        Signature::factory()->create(['petition_id' => $petition->id, 'user_id' => User::factory()->create()->id] + $state);

        $this->actingAs($this->user)
            ->get("/en/petition/original-petition-title/{$petition->id}/edit")
            ->assertOk();

        $this->actingAs($this->user)->post("/en/petition/original-petition-title/{$petition->id}/edit", [
            'title' => 'Updated Petition Title',
            'description' => '<p>This updated description contains enough meaningful content.</p>',
            'category_id' => $this->category->id,
        ])->assertRedirect("/en/petition/updated-petition-title/{$petition->id}");
    }

    public static function nonCountingSignatures(): array
    {
        return [
            'spam' => [['confirmed' => true, 'is_spam' => true]],
            'unconfirmed' => [['confirmed' => false, 'is_spam' => false]],
        ];
    }

    public function test_edit_syncs_identical_copied_translations_only(): void
    {
        $petition = Petition::factory()->create([
            'user_id' => $this->user->id,
            'category_id' => $this->category->id,
            'status' => 'published',
            'is_active' => true,
        ]);
        foreach (['en' => 'Original', 'it' => 'Original', 'fr' => 'Titre different', 'es' => 'ORIGINAL'] as $locale => $title) {
            PetitionTranslation::create([
                'petition_id' => $petition->id,
                'locale' => $locale,
                'title' => $title.' Petition Title',
                'slug' => $locale.'-original',
                'description' => $locale === 'fr' ? 'Description differente.' : 'Original petition description.',
            ]);
        }

        $this->actingAs($this->user)->post("/en/petition/en-original/{$petition->id}/edit", [
            'title' => 'Updated Petition Title',
            'description' => '<p>This updated description contains enough meaningful content.</p>',
            'category_id' => $this->category->id,
        ])->assertRedirect("/en/petition/updated-petition-title/{$petition->id}");

        $it = PetitionTranslation::where('petition_id', $petition->id)->where('locale', 'it')->first();
        $this->assertSame('Updated Petition Title', $it->title);
        $this->assertSame('<p>This updated description contains enough meaningful content.</p>', $it->description);
        $this->assertSame('it-original', $it->slug);

        $fr = PetitionTranslation::where('petition_id', $petition->id)->where('locale', 'fr')->first();
        $this->assertSame('Titre different Petition Title', $fr->title);
        $this->assertSame('Description differente.', $fr->description);

        $es = PetitionTranslation::where('petition_id', $petition->id)->where('locale', 'es')->first();
        $this->assertSame('ORIGINAL Petition Title', $es->title);
    }

    public function test_edit_updates_slug_but_preserves_goal(): void
    {
        $petition = Petition::factory()->create([
            'user_id' => $this->user->id,
            'category_id' => $this->category->id,
            'goal_signatures' => 100,
            'status' => 'published',
            'is_active' => true,
        ]);
        PetitionTranslation::create([
            'petition_id' => $petition->id,
            'locale' => 'en',
            'title' => 'Original Petition Title',
            'slug' => 'original-petition-title',
            'description' => 'Original petition description.',
        ]);

        $this->actingAs($this->user)->post("/en/petition/original-petition-title/{$petition->id}/edit", [
            'title' => 'Updated Petition Title',
            'description' => '<p>This updated description contains enough meaningful content.</p>',
            'category_id' => $this->category->id,
            'goal_signatures' => 500,
        ])->assertRedirect("/en/petition/updated-petition-title/{$petition->id}");

        $this->assertDatabaseHas('petitions', ['id' => $petition->id, 'goal_signatures' => 100]);
        $this->assertDatabaseHas('petition_translations', [
            'petition_id' => $petition->id,
            'slug' => 'updated-petition-title',
        ]);
    }

    public function test_edit_keeps_an_unchanged_legacy_youtube_value_and_validates_a_changed_one(): void
    {
        $petition = Petition::factory()->create([
            'user_id' => $this->user->id,
            'category_id' => $this->category->id,
            'status' => 'published',
            'is_active' => true,
            'youtube_url' => 'abc',
        ]);
        PetitionTranslation::create([
            'petition_id' => $petition->id,
            'locale' => 'en',
            'title' => 'Original Petition Title',
            'slug' => 'original-petition-title',
            'description' => 'Original petition description.',
        ]);
        $payload = [
            'title' => 'Original Petition Title',
            'description' => '<p>This updated description contains enough meaningful content.</p>',
            'category_id' => $this->category->id,
        ];

        $this->actingAs($this->user)
            ->post("/en/petition/original-petition-title/{$petition->id}/edit", ['youtube' => 'abc', 'target' => 'The mayor'] + $payload)
            ->assertSessionHasNoErrors();
        $this->assertDatabaseHas('petitions', ['id' => $petition->id, 'youtube_url' => 'abc', 'target' => 'The mayor']);

        $this->actingAs($this->user)
            ->post("/en/petition/original-petition-title/{$petition->id}/edit", ['youtube' => 'junk'] + $payload)
            ->assertSessionHasErrors('youtube');
        $this->assertDatabaseHas('petitions', ['id' => $petition->id, 'youtube_url' => 'abc']);

        $this->actingAs($this->user)
            ->post("/en/petition/original-petition-title/{$petition->id}/edit", ['youtube' => 'https://youtu.be/dQw4w9WgXcQ?si=abc'] + $payload)
            ->assertSessionHasNoErrors();
        $this->assertDatabaseHas('petitions', ['id' => $petition->id, 'youtube_url' => 'dQw4w9WgXcQ']);
    }

    public function test_edit_keeps_an_unchanged_legacy_image_filename_and_validates_a_changed_one(): void
    {
        $petition = Petition::factory()->create([
            'user_id' => $this->user->id,
            'category_id' => $this->category->id,
            'status' => 'published',
            'is_active' => true,
            'image_url' => 'abc123.jpg',
        ]);
        PetitionTranslation::create([
            'petition_id' => $petition->id,
            'locale' => 'en',
            'title' => 'Original Petition Title',
            'slug' => 'original-petition-title',
            'description' => 'Original petition description.',
        ]);
        $payload = [
            'title' => 'Original Petition Title',
            'description' => '<p>This updated description contains enough meaningful content.</p>',
            'category_id' => $this->category->id,
        ];

        $this->actingAs($this->user)
            ->post("/en/petition/original-petition-title/{$petition->id}/edit", ['image_url' => 'abc123.jpg'] + $payload)
            ->assertRedirect()
            ->assertSessionHasNoErrors();
        $this->assertDatabaseHas('petitions', ['id' => $petition->id, 'image_url' => 'abc123.jpg']);
        $this->assertStringContainsString('This updated description', PetitionTranslation::where('petition_id', $petition->id)->value('description'));

        $this->actingAs($this->user)
            ->post("/en/petition/original-petition-title/{$petition->id}/edit", ['image_url' => 'other.jpg'] + $payload)
            ->assertSessionHasErrors('image_url');
        $this->assertDatabaseHas('petitions', ['id' => $petition->id, 'image_url' => 'abc123.jpg']);

        $this->actingAs($this->user)
            ->post("/en/petition/original-petition-title/{$petition->id}/edit", ['image_url' => null] + $payload)
            ->assertSessionHasNoErrors();
        $this->assertDatabaseHas('petitions', ['id' => $petition->id, 'image_url' => null]);
    }

    public function test_edit_can_remove_current_image_without_orphaning_file(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('petitions/original.png', 'image');

        $petition = Petition::factory()->create([
            'user_id' => $this->user->id,
            'category_id' => $this->category->id,
            'cover_image' => 'petitions/original.png',
            'status' => 'published',
            'is_active' => true,
        ]);
        PetitionTranslation::create([
            'petition_id' => $petition->id,
            'locale' => 'en',
            'title' => 'Original Petition Title',
            'slug' => 'original-petition-title',
            'description' => 'Original petition description.',
        ]);

        $this->actingAs($this->user)->post("/en/petition/original-petition-title/{$petition->id}/edit", [
            'title' => 'Original Petition Title',
            'description' => '<p>This updated description contains enough meaningful content.</p>',
            'category_id' => $this->category->id,
            'remove_image' => 1,
        ])->assertRedirect();

        Storage::disk('public')->assertMissing('petitions/original.png');
        $this->assertDatabaseHas('petitions', ['id' => $petition->id, 'cover_image' => null]);
    }

    public function test_edit_with_new_image_link_replaces_uploaded_cover(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('petitions/original.png', 'image');

        $petition = Petition::factory()->create([
            'user_id' => $this->user->id,
            'category_id' => $this->category->id,
            'cover_image' => 'petitions/original.png',
            'status' => 'published',
            'is_active' => true,
        ]);
        PetitionTranslation::create([
            'petition_id' => $petition->id,
            'locale' => 'en',
            'title' => 'Original Petition Title',
            'slug' => 'original-petition-title',
            'description' => 'Original petition description.',
        ]);

        $this->actingAs($this->user)->post("/en/petition/original-petition-title/{$petition->id}/edit", [
            'title' => 'Original Petition Title',
            'description' => '<p>This updated description contains enough meaningful content.</p>',
            'category_id' => $this->category->id,
            'image_url' => 'https://example.com/new.jpg',
        ])->assertRedirect();

        $this->assertDatabaseHas('petitions', ['id' => $petition->id, 'cover_image' => null, 'image_url' => 'https://example.com/new.jpg']);
        $this->assertSame('https://example.com/new.jpg', $petition->fresh()->coverUrl());
        Storage::disk('public')->assertMissing('petitions/original.png');
    }

    public function test_edit_with_unchanged_image_link_keeps_uploaded_cover(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('petitions/original.png', 'image');

        $petition = Petition::factory()->create([
            'user_id' => $this->user->id,
            'category_id' => $this->category->id,
            'cover_image' => 'petitions/original.png',
            'image_url' => 'https://example.com/old.jpg',
            'status' => 'published',
            'is_active' => true,
        ]);
        PetitionTranslation::create([
            'petition_id' => $petition->id,
            'locale' => 'en',
            'title' => 'Original Petition Title',
            'slug' => 'original-petition-title',
            'description' => 'Original petition description.',
        ]);

        $this->actingAs($this->user)->post("/en/petition/original-petition-title/{$petition->id}/edit", [
            'title' => 'Original Petition Title',
            'description' => '<p>This updated description contains enough meaningful content.</p>',
            'category_id' => $this->category->id,
            'image_url' => 'https://example.com/old.jpg',
        ])->assertRedirect();

        $this->assertDatabaseHas('petitions', ['id' => $petition->id, 'cover_image' => 'petitions/original.png', 'image_url' => 'https://example.com/old.jpg']);
        Storage::disk('public')->assertExists('petitions/original.png');
    }

    public function test_petition_title_requires_minimum_words(): void
    {
        $response = $this->actingAs($this->user)->post('/en/create-petition', [
            'title' => 'Short',
            'description' => '<p>This is a test petition with enough content to pass validation and be accepted.</p>',
            'goal_signatures' => 100,
            'category_id' => $this->category->id,
        ]);

        $response->assertSessionHasErrors('title');
    }

    public function test_petition_description_requires_minimum_length(): void
    {
        $response = $this->actingAs($this->user)->post('/en/create-petition', [
            'title' => 'Test Petition Title Here',
            'description' => '<p>Too short</p>',
            'goal_signatures' => 100,
            'category_id' => $this->category->id,
        ]);

        $response->assertSessionHasErrors('description');
    }

    public function test_goal_signatures_must_be_valid_value(): void
    {
        $response = $this->actingAs($this->user)->post('/en/create-petition', [
            'title' => 'Test Petition Title Here',
            'description' => '<p>This is a test petition with enough content to pass validation and be accepted.</p>',
            'goal_signatures' => 500,
            'category_id' => $this->category->id,
        ]);

        $response->assertSessionHasErrors('goal_signatures');
    }

    public function test_spam_content_is_rejected(): void
    {
        $petition = Petition::factory()->create([
            'status' => 'published',
            'is_active' => true,
        ]);

        PetitionTranslation::create([
            'petition_id' => $petition->id,
            'locale' => 'en',
            'title' => 'Test Petition',
            'slug' => 'test-petition',
            'description' => 'Test petition description.',
        ]);

        $response = $this->post("/en/petition/test-petition/{$petition->id}/sign", [
            'name' => 'Test',
            'email' => 'spam@example.com',
            'comment' => 'Buy v1agra now! http://spam.com',
            'agree1' => '1',
            'agree2' => '1',
            'agree3' => '1',
        ]);

        $response->assertSessionHas('toast.type', 'error');
        $this->assertDatabaseMissing('signatures', ['petition_id' => $petition->id, 'email' => 'spam@example.com']);
    }

    public function test_guest_sign_with_spam_keyword_in_email_is_rejected(): void
    {
        $petition = Petition::factory()->create(['status' => 'published', 'is_active' => true]);

        PetitionTranslation::create([
            'petition_id' => $petition->id,
            'locale' => 'en',
            'title' => 'Test Petition',
            'slug' => 'test-petition',
            'description' => 'Test petition description.',
        ]);

        $response = $this->post("/en/petition/test-petition/{$petition->id}/sign", [
            'name' => 'Test',
            'email' => 'viagra@example.com',
            'agree1' => '1',
            'agree2' => '1',
            'agree3' => '1',
        ]);

        $response->assertSessionHas('toast.type', 'error');
        $this->assertDatabaseMissing('users', ['email' => 'viagra@example.com']);
        $this->assertDatabaseMissing('signatures', ['petition_id' => $petition->id, 'email' => 'viagra@example.com']);
        $this->assertSame(0, (int) $petition->fresh()->signature_count);
    }

    #[DataProvider('commentSpamCases')]
    public function test_signing_comment_uses_review_owned_ignore_list(bool $authed, string $comment, bool $accepted): void
    {
        $petition = Petition::factory()->create(['status' => 'published', 'is_active' => true]);
        PetitionTranslation::create([
            'petition_id' => $petition->id,
            'locale' => 'en',
            'title' => 'Comment Petition',
            'slug' => 'comment-petition',
            'description' => 'Description',
        ]);

        // authed rules want 'agree', guest rules accept '1'
        $agree = $authed ? 'agree' : '1';
        $fields = ['comment' => $comment, 'agree1' => $agree, 'agree2' => $agree, 'agree3' => $agree];
        if ($authed) {
            $this->actingAs($this->user);
        } else {
            $fields += ['name' => 'Guest', 'email' => 'comment-guest@example.com'];
        }

        $this->post("/en/petition/comment-petition/{$petition->id}/sign", $fields)->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame($accepted ? 1 : 0, Signature::query()->where('petition_id', $petition->id)->count());
    }

    public static function commentSpamCases(): array
    {
        return [
            'guest casino' => [false, 'Stop the new casino in our town', true],
            'authed casino' => [true, 'Stop the new casino in our town', true],
            'guest viagra' => [false, 'Buy viagra here', false],
            'authed viagra' => [true, 'Buy viagra here', false],
            'guest casino and viagra' => [false, 'Stop the casino, buy viagra', false],
            'authed casino and viagra' => [true, 'Stop the casino, buy viagra', false],
        ];
    }

    public function test_shoutbox_accepts_review_owned_keyword(): void
    {
        $petition = Petition::factory()->create(['status' => 'published', 'is_active' => true]);
        PetitionTranslation::create([
            'petition_id' => $petition->id,
            'locale' => 'en',
            'title' => 'Shout Petition',
            'slug' => 'shout-petition',
            'description' => 'Description',
        ]);
        $sig = Signature::factory()->create([
            'petition_id' => $petition->id,
            'user_id' => $this->user->id,
            'confirmed' => true,
            'is_spam' => false,
        ]);

        $this->actingAs($this->user)
            ->post("/en/petition/shout-petition/{$petition->id}/shoutbox", ['comment' => 'Stop the new casino in our town'])
            ->assertSessionHas('toast.type', 'success');

        $this->assertSame('Stop the new casino in our town', $sig->fresh()->text);
    }

    public function test_signature_rate_limited(): void
    {
        $petition = Petition::factory()->create([
            'status' => 'published',
            'is_active' => true,
        ]);

        PetitionTranslation::create([
            'petition_id' => $petition->id,
            'locale' => 'en',
            'title' => 'Test Petition',
            'slug' => 'test-petition',
            'description' => 'Test petition description.',
        ]);

        for ($i = 0; $i < 10; $i++) {
            $this->post("/en/petition/test-petition/{$petition->id}/sign", [
                'name' => 'Test',
                'surname' => 'User',
                'email' => "user{$i}@example.com",
                'password' => 'password123',
                'comment' => 'I support this!',
                'agree1' => 'agree',
                'agree2' => 'agree',
                'agree3' => 'agree',
            ]);
        }

        $response = $this->post("/en/petition/test-petition/{$petition->id}/sign", [
            'name' => 'Test',
            'surname' => 'User',
            'email' => 'toomany@example.com',
            'password' => 'password123',
            'comment' => 'I support this!',
            'agree1' => 'agree',
            'agree2' => 'agree',
            'agree3' => 'agree',
        ]);

        $response->assertRedirect();
        $response->assertSessionHasErrors();
    }

    public function test_agree_checkboxes_required(): void
    {
        $petition = $this->makePublishedPetition('agree-required-petition');

        $response = $this->post("/en/petition/agree-required-petition/{$petition->id}/sign", [
            'name' => 'Agree',
            'email' => 'agree-required@example.com',
            'comment' => 'I support this petition',
            'agree1' => '1',
            'agree3' => '1',
        ]);

        $response->assertStatus(302)->assertSessionHasErrors('agree2');
        $this->assertDatabaseMissing('signatures', ['petition_id' => $petition->id]);
        $this->assertDatabaseMissing('users', ['email' => 'agree-required@example.com']);
    }

    public function test_legacy_agree_value_rejected(): void
    {
        $petition = $this->makePublishedPetition('legacy-agree-petition');

        $response = $this->post("/en/petition/legacy-agree-petition/{$petition->id}/sign", [
            'name' => 'Legacy',
            'email' => 'legacy-agree@example.com',
            'comment' => 'I support this petition',
            'agree1' => 'agree',
            'agree2' => '1',
            'agree3' => '1',
        ]);

        $response->assertStatus(302)->assertSessionHasErrors('agree1');
        $this->assertDatabaseMissing('signatures', ['petition_id' => $petition->id]);
    }

    public function test_double_submit_race_gets_friendly_error(): void
    {
        $petition = $this->makePublishedPetition('double-submit-petition');
        $url = "/en/petition/double-submit-petition/{$petition->id}/sign";
        $payload = [
            'name' => 'Double',
            'email' => 'double-submit@example.com',
            'comment' => 'I support this petition',
            'agree1' => '1',
            'agree2' => '1',
            'agree3' => '1',
        ];

        $this->post($url, $payload)->assertRedirect();

        $second = $this->post($url, $payload);
        $second->assertRedirect("/en/petition/double-submit-petition/{$petition->id}/thanks");

        $this->assertSame(1, Signature::where('petition_id', $petition->id)->count());
        $this->assertSame(1, User::where('email', 'double-submit@example.com')->count());
    }

    public function test_shoutbox_upserts_comment_on_double_sign(): void
    {
        $petition = $this->makePublishedPetition('shoutbox-upsert-petition');

        Signature::create([
            'petition_id' => $petition->id,
            'user_id' => $this->user->id,
            'email' => $this->user->email,
            'name' => $this->user->name,
            'locale' => 'en',
            'text' => 'First comment',
            'confirmed' => true,
        ]);

        $response = $this->actingAs($this->user)->post("/en/petition/shoutbox-upsert-petition/{$petition->id}/shoutbox", [
            'comment' => 'Updated comment',
        ]);

        $response->assertRedirect();
        $this->assertStringContainsString('#petition-comments', $response->headers->get('Location'));
        $this->assertSame(1, Signature::where('petition_id', $petition->id)->count());
        $this->assertDatabaseHas('signatures', [
            'petition_id' => $petition->id,
            'user_id' => $this->user->id,
            'text' => 'Updated comment',
        ]);
    }

    private function makePublishedPetition(string $slug): Petition
    {
        $petition = Petition::factory()->create([
            'status' => 'published',
            'is_active' => true,
            'signature_count' => 0,
        ]);

        PetitionTranslation::create([
            'petition_id' => $petition->id,
            'locale' => 'en',
            'title' => ucfirst(str_replace('-', ' ', $slug)),
            'slug' => $slug,
            'description' => 'Description',
        ]);

        return $petition;
    }

    public function test_search_highlight_does_not_break_entities_or_inject_html(): void
    {
        $petition = Petition::factory()->create(['status' => 'published', 'is_active' => true]);
        PetitionTranslation::create([
            'petition_id' => $petition->id,
            'locale' => 'en',
            'title' => 'Fish & Chips <b>amp</b>',
            'slug' => 'fish-and-chips',
            'description' => 'Description',
        ]);

        $this->get('/en/petitions?q=amp')
            ->assertOk()
            ->assertSee('Fish &amp; Chips &lt;b&gt;<mark>amp</mark>&lt;/b&gt;', false);

        $this->get('/en/petitions?q='.urlencode('&'))
            ->assertOk()
            ->assertSee('Fish <mark>&amp;</mark> Chips', false);
    }

    private function ownedPetition(array $attributes = []): Petition
    {
        $petition = Petition::factory()->create([
            'user_id' => $this->user->id,
            'category_id' => $this->category->id,
            'is_active' => true,
        ] + $attributes);
        PetitionTranslation::create([
            'petition_id' => $petition->id,
            'locale' => 'en',
            'title' => 'Held Petition Title',
            'slug' => 'held-petition-title',
            'description' => 'Original petition description.',
        ]);

        return $petition;
    }

    private function editPayload(string $body): array
    {
        return [
            'title' => 'Held Petition Title',
            'description' => $body,
            'category_id' => $this->category->id,
        ];
    }

    public function test_update_does_not_auto_publish_a_draft_held_by_admin(): void
    {
        $petition = $this->ownedPetition(['status' => 'draft', 'held_by_admin' => true]);

        $this->actingAs($this->user)
            ->post("/en/petition/held-petition-title/{$petition->id}/edit", $this->editPayload('<p>This updated description contains enough meaningful content.</p>'))
            ->assertSessionHasNoErrors();

        $this->assertSame('draft', $petition->fresh()->status);
    }

    public function test_update_with_a_stale_slug_saves_instead_of_redirecting_to_the_edit_form(): void
    {
        $petition = $this->ownedPetition(['status' => 'published', 'held_by_admin' => false]);
        $petition->translations()->where('locale', 'en')->update(['slug' => 'renamed-petition-title']);

        $this->actingAs($this->user)
            ->post("/en/petition/held-petition-title/{$petition->id}/edit", ['title' => 'Fresh Petition Title'] + $this->editPayload('<p>This updated description contains enough meaningful content.</p>'))
            ->assertRedirect("/en/petition/fresh-petition-title/{$petition->id}");

        $this->assertDatabaseHas('petition_translations', ['petition_id' => $petition->id, 'locale' => 'en', 'title' => 'Fresh Petition Title']);
    }

    public function test_update_with_an_image_is_rejected_before_storing_when_another_user_signed(): void
    {
        Storage::fake('public');
        $petition = $this->ownedPetition(['status' => 'published', 'held_by_admin' => false]);
        Signature::factory()->create([
            'petition_id' => $petition->id,
            'user_id' => User::factory()->create()->id,
            'confirmed' => true,
            'is_spam' => false,
        ]);

        $this->actingAs($this->user)
            ->post("/en/petition/held-petition-title/{$petition->id}/edit", [
                'image' => UploadedFile::fake()->image('petition.png', 400, 300),
            ] + $this->editPayload('<p>This updated description contains enough meaningful content.</p>'))
            ->assertForbidden();

        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_review_keyword_edit_drafts_a_published_petition_and_removing_it_publishes_again(): void
    {
        $petition = $this->ownedPetition(['status' => 'published', 'held_by_admin' => false]);
        $url = "/en/petition/held-petition-title/{$petition->id}/edit";

        $this->actingAs($this->user)
            ->post($url, $this->editPayload('<p>Stop casino advertising near schools in our town today.</p>'))
            ->assertSessionHasNoErrors();
        $this->assertSame('draft', $petition->fresh()->status);

        $this->actingAs($this->user)
            ->post($url, $this->editPayload('<p>Stop noisy advertising near schools in our town today.</p>'))
            ->assertSessionHasNoErrors();
        $this->assertSame('published', $petition->fresh()->status);
    }

    public function test_same_title_petitions_get_distinct_slugs(): void
    {
        foreach ([1, 2] as $_) {
            $this->actingAs($this->user)->post('/en/create-petition', [
                'title' => 'Identical Petition Title',
                'description' => '<p>This is a test petition with enough content to pass validation.</p>',
                'goal_signatures' => 100,
                'category_id' => $this->category->id,
            ])->assertSessionHasNoErrors();
        }

        $slugs = PetitionTranslation::where('title', 'Identical Petition Title')->pluck('slug');
        $this->assertCount(2, $slugs);
        $this->assertCount(2, $slugs->unique());
    }

    public function test_failed_validation_does_not_consume_the_daily_create_limit(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);

        foreach (range(1, 11) as $_) {
            $this->actingAs($this->user)->post('/en/create-petition', ['title' => ''])->assertSessionHasErrors('title');
        }

        $this->actingAs($this->user)->post('/en/create-petition', [
            'title' => 'Valid After Failures',
            'description' => '<p>This is a test petition with enough content to pass validation.</p>',
            'goal_signatures' => 100,
            'category_id' => $this->category->id,
        ])->assertSessionHasNoErrors()->assertRedirect();
    }

    public function test_eleventh_successful_create_in_a_day_is_rate_limited(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);

        foreach (range(1, 10) as $i) {
            $this->actingAs($this->user)->post('/en/create-petition', [
                'title' => 'Daily Limit Petition '.$i,
                'description' => '<p>This is a test petition with enough content to pass validation.</p>',
                'goal_signatures' => 100,
                'category_id' => $this->category->id,
            ])->assertRedirect();
        }

        $this->actingAs($this->user)->post('/en/create-petition', [
            'title' => 'Daily Limit Petition 11',
            'description' => '<p>This is a test petition with enough content to pass validation.</p>',
            'goal_signatures' => 100,
            'category_id' => $this->category->id,
        ])->assertStatus(429);
    }
}
