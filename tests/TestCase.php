<?php

namespace Tests;

use App\Models\User;
use App\Support\Settings;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Settings::forget();

        $this->withoutVite();
    }

    protected function knownVerificationToken(User $user): string
    {
        $token = 'known-token-'.$user->id;
        User::whereKey($user->id)->update(['verification_token' => User::hashVerificationToken($token)]);

        return $token;
    }
}
