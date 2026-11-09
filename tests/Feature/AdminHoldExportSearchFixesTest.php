<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\CategoryTranslation;
use App\Models\Page;
use App\Models\PageTranslation;
use App\Models\Petition;
use App\Models\PetitionTranslation;
use App\Models\Signature;
use App\Models\User;
use App\Models\UserLevel;
use App\Support\Spam;
use Database\Seeders\LanguageSeeder;
use Database\Seeders\UserLevelSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AdminHoldExportSearchFixesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([UserLevelSeeder::class, LanguageSeeder::class]);
    }

    private function admin(): User
    {
        return User::factory()->create(['level_id' => UserLevel::where('name', 'admin')->value('id'), 'verified' => true]);
    }

    private function petitionFor(User $owner, string $status = 'published', string $slug = 'my-petition'): Petition
    {
        $p = Petition::factory()->create(['user_id' => $owner->id, 'status' => $status, 'is_active' => true]);
        PetitionTranslation::create(['petition_id' => $p->id, 'locale' => 'en', 'title' => 'My petition', 'slug' => $slug, 'description' => '<p>Enough description text here.</p>']);

        return $p;
    }

    private function enablePurge(): void
    {
        config()->set('edge-cache.purge_enabled', true);
        config()->set('edge-cache.api_token', 't');
        config()->set('edge-cache.zone_id', 'z');
        config()->set('edge-cache.base_url', 'https://www.freecause.com');
        Http::fake(['api.cloudflare.com/*' => Http::response(['success' => true])]);
    }

    private function purged(string $url): bool
    {
        return Http::recorded(fn ($r) => in_array($url, $r['files'] ?? [], true))->isNotEmpty();
    }

    public function test_exports_include_every_row_when_created_at_order_differs_from_id_order_and_skip_spam(): void
    {
        $owner = User::factory()->create(['verified' => true]);
        $p = $this->petitionFor($owner);
        $rows = [];
        for ($i = 1; $i <= 1100; $i++) {
            $rows[] = ['petition_id' => $p->id, 'name' => 'n'.$i, 'email' => "e{$i}@x.test", 'locale' => 'en', 'text' => 'c', 'confirmed' => 1, 'is_spam' => 0, 'created_at' => now()->subMinutes($i), 'updated_at' => now()];
        }
        $rows[] = ['petition_id' => $p->id, 'name' => 'unconfirmed', 'email' => 'u@x.test', 'locale' => 'en', 'text' => 'c', 'confirmed' => 0, 'is_spam' => 0, 'created_at' => now(), 'updated_at' => now()];
        $rows[] = ['petition_id' => $p->id, 'name' => 'spammer', 'email' => 's@x.test', 'locale' => 'en', 'text' => 'c', 'confirmed' => 1, 'is_spam' => 1, 'created_at' => now(), 'updated_at' => now()];
        foreach (array_chunk($rows, 200) as $c) {
            DB::table('signatures')->insert($c);
        }

        $csv = $this->actingAs($owner)->get("/en/petition/my-petition/{$p->id}/download/csv")->streamedContent();
        $this->assertSame(1100, preg_match_all('/^\d+,n\d+,/m', $csv));
        $this->assertStringNotContainsString(',unconfirmed,', $csv);
        $this->assertStringNotContainsString('spammer', $csv);

        $txt = $this->actingAs($owner)->get("/en/petition/my-petition/{$p->id}/download/txt")->streamedContent();
        $this->assertSame(1100, preg_match_all('/^\d+\. n\d+ /m', $txt));
        $this->assertStringNotContainsString('unconfirmed', $txt);
        $this->assertStringNotContainsString('spammer', $txt);
    }

    public function test_boolean_terms_strip_operators(): void
    {
        $this->assertSame('', PetitionTranslation::booleanTerms(' + '));
        $this->assertSame('foo bar', PetitionTranslation::booleanTerms('foo@bar'));
        $this->assertSame('bees', PetitionTranslation::booleanTerms('(bees-'));
        $this->assertSame('a b', PetitionTranslation::booleanTerms('+a ~"b*"'));
    }

    public function test_owner_cannot_republish_admin_unpublished_petition_but_keyword_hold_still_clears(): void
    {
        $owner = User::factory()->create(['verified' => true]);
        $cat = Category::factory()->create(['is_active' => true]);
        CategoryTranslation::create(['category_id' => $cat->id, 'locale' => 'en', 'name' => 'C', 'slug' => 'c']);
        $p = $this->petitionFor($owner);
        $payload = ['title' => 'Clean title here', 'description' => '<p>This is a clean description with enough content to pass validation.</p>', 'goal_signatures' => 100, 'category_id' => $cat->id];

        $this->actingAs($this->admin(), 'admin')->postJson('/admin/petitions/bulk-action', ['ids' => [$p->id], 'action' => 'unpublish'])->assertOk();
        $this->assertSame(1, (int) $p->fresh()->held_by_admin);

        $this->actingAs($owner)->post("/en/petition/my-petition/{$p->id}/edit", $payload)->assertRedirect();
        $this->assertSame('draft', $p->fresh()->status);

        $this->actingAs($this->admin(), 'admin')->postJson('/admin/petitions/bulk-action', ['ids' => [$p->id], 'action' => 'publish'])->assertOk();
        $this->assertSame(0, (int) $p->fresh()->held_by_admin);

        $keywordHeld = $this->petitionFor($owner, 'draft', 'kw');
        $slug = $keywordHeld->translations()->value('slug');
        $this->actingAs($owner)->post("/en/petition/{$slug}/{$keywordHeld->id}/edit", $payload)->assertRedirect();
        $this->assertSame('published', $keywordHeld->fresh()->status);
    }

    public function test_csv_safe_prefixes_formula_starts_only(): void
    {
        foreach (['=1+1', '+1', '-1', '@a', "\tx", "\rx"] as $v) {
            $this->assertSame("'".$v, csv_safe($v));
        }
        $this->assertSame('abc', csv_safe('abc'));
        $this->assertSame(5, csv_safe(5));
        $this->assertNull(csv_safe(null));
    }

    public function test_users_export_neutralises_formulas(): void
    {
        User::factory()->create(['name' => '=HYPERLINK("http://x")']);
        $csv = $this->actingAs($this->admin(), 'admin')->get('/admin/users/export')->streamedContent();
        $this->assertStringContainsString("\"'=HYPERLINK(", $csv);
    }

    public function test_admin_petition_actions_purge_show_pages_including_old_slug(): void
    {
        $owner = User::factory()->create();
        $p = $this->petitionFor($owner);
        $this->enablePurge();
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')->postJson('/admin/petitions/bulk-action', ['ids' => [$p->id], 'action' => 'unpublish'])->assertOk();
        $this->assertTrue($this->purged("https://www.freecause.com/en/petition/my-petition/{$p->id}"));

        Http::fake(['api.cloudflare.com/*' => Http::response(['success' => true])]);
        $this->actingAs($admin, 'admin')->post('/admin/petitions/save', ['id' => $p->id, 'locale' => 'en', 'status' => 'published', 'title' => 'New', 'slug' => 'new-slug']);
        $this->assertTrue($this->purged("https://www.freecause.com/en/petition/my-petition/{$p->id}"));
        $this->assertTrue($this->purged("https://www.freecause.com/en/petition/new-slug/{$p->id}"));
    }

    public function test_admin_page_actions_purge_page_urls(): void
    {
        $page = Page::create();
        PageTranslation::create(['page_id' => $page->id, 'locale' => 'en', 'title' => 'About', 'slug' => 'about', 'content' => 'x', 'published' => true]);
        $this->enablePurge();

        $this->actingAs($this->admin(), 'admin')->postJson('/admin/pages/bulk-action', ['ids' => [$page->id], 'action' => 'unpublish'])->assertOk();
        $this->assertTrue($this->purged('https://www.freecause.com/en/about'));
    }

    public function test_spam_matches_word_starts_not_inner_substrings(): void
    {
        $this->assertFalse(Spam::isSpam('Sloan'));
        $this->assertFalse(Spam::isSpam('quick loan today'));
        $this->assertFalse(Spam::isSpam('loans'));
    }

    public function test_authed_sign_and_shoutbox_and_update_reject_spam(): void
    {
        $owner = User::factory()->create(['verified' => true]);
        $p = $this->petitionFor($owner);
        $signer = User::factory()->create(['verified' => true]);

        $this->actingAs($signer)->post("/en/petition/my-petition/{$p->id}/sign", ['comment' => 'cheap viagra now', 'agree1' => 'agree', 'agree2' => 'agree', 'agree3' => 'agree']);
        $this->assertSame(0, Signature::where('petition_id', $p->id)->where('user_id', $signer->id)->count());
        $this->assertDatabaseHas('spam_logs', ['type' => 'signature']);

        $cat = Category::factory()->create(['is_active' => true]);
        $this->actingAs($owner)->post("/en/petition/my-petition/{$p->id}/edit", ['title' => 'Buy viagra today', 'description' => '<p>Enough description text here for validation to pass.</p>', 'goal_signatures' => 100, 'category_id' => $cat->id])
            ->assertSessionHasErrors('title');
        $this->assertSame('My petition', $p->translations()->value('title'));

        $sig = Signature::factory()->create(['petition_id' => $p->id, 'user_id' => $signer->id, 'confirmed' => true, 'text' => 'ok']);
        $this->actingAs($signer)->post("/en/petition/my-petition/{$p->id}/shoutbox", ['comment' => 'free money here']);
        $this->assertSame('ok', $sig->fresh()->text);
    }

    public function test_translate_missing_survives_slug_clash_in_target_locale(): void
    {
        $owner = User::factory()->create();
        $p = $this->petitionFor($owner);
        $other = $this->petitionFor($owner, 'published', 'other');
        PetitionTranslation::where('petition_id', $other->id)->update(['locale' => 'fr', 'slug' => 'my-petition']);

        $this->actingAs($this->admin(), 'admin')->postJson('/admin/petitions/translate-missing', ['id' => $p->id, 'locale' => 'en'])->assertOk();
        $this->assertNotSame('my-petition', PetitionTranslation::where('petition_id', $p->id)->where('locale', 'fr')->value('slug'));
    }
}
