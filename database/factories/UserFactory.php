<?php

namespace Database\Factories;

use App\Models\Language;
use App\Models\User;
use App\Models\UserLevel;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class UserFactory extends Factory
{
    protected $model = User::class;

    protected static ?string $password = null;

    protected static ?array $languageCodes = null;

    protected static ?array $localeMap = null;

    public function definition(): array
    {
        $userLevelId = UserLevel::where('name', 'user')->value('id');
        if (! $userLevelId) {
            throw new \RuntimeException('User levels not seeded.');
        }

        if (! static::$languageCodes) {
            static::$languageCodes = Language::pluck('code')->toArray();
        }

        if (! static::$localeMap) {
            static::$localeMap = config('locales') ?? [];
        }

        $first = $this->faker->firstName();
        $last = $this->faker->lastName();

        $randomCode = $this->faker->randomElement(static::$languageCodes ?? ['en']);
        $locale = static::$localeMap[$randomCode] ?? 'en_US';

        return [
            'first_name' => $first,
            'last_name' => $last,
            'name' => trim($first.' '.$last),
            'nickname' => $this->faker->boolean(50) ? $this->faker->unique()->userName() : null,
            'city' => $this->faker->optional(0.7)->city(),
            'identify_mode' => $this->faker->randomElement(['full', 'name', 'nick']),
            'country' => 'US',
            'gender' => $this->faker->randomElement(['male', 'female', 'prefer_not_to_say', 'none']),
            'email' => $this->faker->unique()->safeEmail(),
            'password' => static::$password ??= Hash::make('password'),
            'locale' => $locale,
            'preferred_language_confirmed_at' => now(),
            'ip' => $this->faker->ipv4(),
            'level_id' => $userLevelId,
            'google_id' => $this->faker->optional(0.2)->uuid(),
            'verified' => $this->faker->boolean(20),
            'remember_token' => Str::random(10),
        ];
    }

    public function languageConfirmed(): static
    {
        return $this->state(fn () => [
            'preferred_language_confirmed_at' => now(),
        ]);
    }
}
