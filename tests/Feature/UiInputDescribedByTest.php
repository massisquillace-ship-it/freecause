<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class UiInputDescribedByTest extends TestCase
{
    public function test_hint_and_error_describe_only_the_error(): void
    {
        $html = Blade::render('<x-ui.input name="email" hint="Use work mail" error="Invalid" />');

        $this->assertStringContainsString('aria-describedby="email-error"', $html);
        $this->assertStringNotContainsString('email-hint', $html);
    }

    public function test_hint_without_error_describes_the_hint(): void
    {
        $html = Blade::render('<x-ui.input name="email" hint="Use work mail" />');

        $this->assertStringContainsString('aria-describedby="email-hint"', $html);
        $this->assertStringContainsString('id="email-hint"', $html);
    }
}
