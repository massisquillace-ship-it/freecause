<?php

namespace Tests\Feature;

use App\Models\Petition;
use App\Services\HomepageMagazine;
use Database\Seeders\LanguageSeeder;
use Database\Seeders\UserLevelSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class HomepageMagazineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([UserLevelSeeder::class, LanguageSeeder::class]);
        config(['database.connections.magazine' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => 'abi_']]);
        DB::purge('magazine');
        Schema::connection('magazine')->create('options', function (Blueprint $table) {
            $table->string('option_name');
            $table->text('option_value');
        });
        Schema::connection('magazine')->create('posts', function (Blueprint $table) {
            $table->id('ID');
            $table->string('post_title');
            $table->string('post_name');
            $table->dateTime('post_date');
            $table->string('post_status');
            $table->string('post_type');
        });
        Schema::connection('magazine')->create('postmeta', function (Blueprint $table) {
            $table->id('meta_id');
            $table->unsignedBigInteger('post_id');
            $table->string('meta_key');
            $table->text('meta_value');
        });
    }

    private function editorialPost(string $locale = '', array $data = []): int
    {
        $id = DB::connection('magazine')->table('posts')->insertGetId(array_merge([
            'post_title' => 'Editorial article', 'post_name' => 'editorial-article', 'post_date' => now(),
            'post_status' => 'publish', 'post_type' => 'post',
        ], $data));
        if ($locale !== '') {
            DB::connection('magazine')->table('postmeta')->insert(['post_id' => $id, 'meta_key' => 'freecause_locale', 'meta_value' => $locale]);
        }

        return $id;
    }

    public function test_requires_three_published_posts_in_exact_locale_without_cross_language_fallback(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->editorialPost('en');
        }
        for ($i = 0; $i < 2; $i++) {
            $this->editorialPost('fr');
        }
        $this->editorialPost('fr', ['post_status' => 'draft']);
        $this->assertCount(3, app(HomepageMagazine::class)->posts('en'));
        $this->assertSame([], app(HomepageMagazine::class)->posts('fr'));
        $this->assertSame([], app(HomepageMagazine::class)->posts('it'));
    }

    public function test_legacy_posts_use_wordpress_site_locale_and_locale_mapping_overrides_it(): void
    {
        DB::connection('magazine')->table('options')->insert(['option_name' => 'WPLANG', 'option_value' => 'it_IT']);
        for ($i = 0; $i < 3; $i++) {
            $this->editorialPost();
            $this->editorialPost('en');
        }
        $this->assertCount(3, app(HomepageMagazine::class)->posts('it'));
        $this->assertCount(3, app(HomepageMagazine::class)->posts('en'));
        $this->assertSame([], app(HomepageMagazine::class)->posts('fr'));
    }

    public function test_related_campaign_mapping_never_exposes_unpublished_or_other_language_petitions(): void
    {
        $public = Petition::factory()->create(['status' => 'published', 'is_active' => true]);
        $public->translations()->create(['locale' => 'en', 'title' => 'Related public campaign', 'slug' => 'related-public', 'description' => 'Description']);
        $private = Petition::factory()->create(['status' => 'draft']);
        $private->translations()->create(['locale' => 'en', 'title' => 'Private campaign', 'slug' => 'private-campaign', 'description' => 'Description']);
        $otherLanguage = Petition::factory()->create(['status' => 'published', 'is_active' => true]);
        $otherLanguage->translations()->create(['locale' => 'it', 'title' => 'Altra lingua', 'slug' => 'altra-lingua', 'description' => 'Description']);
        foreach ([$public, $private, $otherLanguage] as $petition) {
            $id = $this->editorialPost('en');
            DB::connection('magazine')->table('postmeta')->insert(['post_id' => $id, 'meta_key' => 'related_petition_id', 'meta_value' => (string) $petition->id]);
        }
        $posts = app(HomepageMagazine::class)->posts('en');
        $this->assertCount(3, $posts);
        $related = collect($posts)->pluck('related_campaign')->filter();
        $this->assertCount(1, $related);
        $this->assertSame($public->id, $related->first()->petition_id);
    }
}
