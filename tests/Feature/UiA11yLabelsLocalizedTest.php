<?php

namespace Tests\Feature;

use Database\Seeders\LanguageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_every_locale_defines_the_ui_a11y_keys(): void
    {
        $keys = ['skip_to_content', 'advertisement', 'close_advertisement', 'breadcrumb', 'pagination', 'back_to_top', 'offline'];

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
