<?php

namespace Tests\Feature;

use Database\Seeders\LanguageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class UiA11yLabelsLocalizedTest extends TestCase
{
    use RefreshDatabase;

    public function test_list_page_a11y_labels_and_offline_text_are_localized(): void
    {
        $this->seed(LanguageSeeder::class);

        $this->get('/de/petitions')->assertOk()
            ->assertSee('>'.__('ui.skip_to_content', [], 'de').'</a>', false)
            ->assertSee('aria-label="'.__('ui.breadcrumb', [], 'de').'"', false)
            ->assertSee('aria-label="'.__('ui.back_to_top', [], 'de').'"', false)
            ->assertSee('data-offline-text="'.__('ui.offline', [], 'de').'"', false)
            ->assertDontSee('Skip to content')
            ->assertDontSee('aria-label="Back to top"', false)
            ->assertDontSee('aria-label="breadcrumb"', false);
    }

    public function test_pagination_prev_and_next_links_have_translated_names(): void
    {
        $paginator = new LengthAwarePaginator([], 90, 10, 3, ['path' => '/x']);

        $html = Blade::render('<x-ui.pagination :paginator="$p" />', ['p' => $paginator]);

        $this->assertStringContainsString('rel="prev" aria-label="'.__('ui.previous_page').'"', $html);
        $this->assertStringContainsString('rel="next" aria-label="'.__('ui.next_page').'"', $html);
    }

    public function test_every_locale_defines_the_ui_a11y_keys(): void
    {
        $keys = ['skip_to_content', 'advertisement', 'close_advertisement', 'breadcrumb', 'pagination', 'previous_page', 'next_page', 'back_to_top', 'offline'];

        foreach (glob(lang_path('*'), GLOB_ONLYDIR) as $dir) {
            $locale = basename($dir);
            if ($locale === 'vendor') {
                continue;
            }
            foreach ($keys as $key) {
                $this->assertNotSame('', trim((string) __('ui.'.$key, [], $locale)), "$locale ui.$key");
                $this->assertNotSame('ui.'.$key, __('ui.'.$key, [], $locale), "$locale ui.$key");
            }
        }
    }
}
