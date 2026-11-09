<?php

namespace Tests\Feature;

use App\Rules\MaxBytes;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class PasswordByteLimitTest extends TestCase
{
    private function passes(string $value): bool
    {
        return Validator::make(['password' => $value], ['password' => [new MaxBytes(72)]])->passes();
    }

    public function test_72_ascii_characters_pass(): void
    {
        $this->assertTrue($this->passes(str_repeat('a', 72)));
    }

    public function test_73_ascii_characters_fail(): void
    {
        $this->assertFalse($this->passes(str_repeat('a', 73)));
    }

    public function test_multibyte_password_under_72_characters_but_over_72_bytes_fails(): void
    {
        $this->assertFalse($this->passes(str_repeat('é', 40)));
    }
}
