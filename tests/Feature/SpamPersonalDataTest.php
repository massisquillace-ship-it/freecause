<?php

namespace Tests\Feature;

use App\Models\Petition;
use App\Models\PetitionTranslation;
use Database\Seeders\LanguageSeeder;
use Database\Seeders\UserLevelSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SpamPersonalDataTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([UserLevelSeeder::class, LanguageSeeder::class]);
    }

    private function petition(): Petition
    {
        $petition = Petition::factory()->create(['status' => 'published', 'is_active' => true]);
        PetitionTranslation::create([
            'petition_id' => $petition->id,
            'locale' => 'en',
            'title' => 'Name Petition',
            'slug' => 'name-petition',
            'description' => 'Description',
        ]);

        return $petition;
    }

    private function sign(Petition $petition, array $over = [])
    {
        return $this->post("/en/petition/name-petition/{$petition->id}/sign", array_merge([
            'name' => 'Guest',
            'email' => 'guest@example.com',
            'agree1' => '1',
            'agree2' => '1',
            'agree3' => '1',
            'comment' => 'I support this petition',
        ], $over));
    }

    public function test_guest_with_review_owned_prefix_in_name_and_email_can_sign(): void
    {
        $petition = $this->petition();

        $this->sign($petition, ['name' => 'Pornpimol Sukjai', 'email' => 'pornpimol@example.com'])
            ->assertRedirect("/en/petition/name-petition/{$petition->id}/thanks");

        $this->assertDatabaseHas('signatures', ['petition_id' => $petition->id]);
    }

    public function test_guest_comment_with_derived_review_owned_word_is_rejected(): void
    {
        $petition = $this->petition();

        $this->sign($petition, ['comment' => 'pornographic content'])->assertSessionHasNoErrors();

        $this->assertDatabaseCount('signatures', 0);
    }

    public function test_registration_allows_review_owned_word_in_email(): void
    {
        $this->post('/en/register', [
            'name' => 'Mario',
            'surname' => 'Rossi',
            'email' => 'mario.casino@example.com',
            'password' => 'password123',
            'agree_terms' => 'on',
        ]);

        $this->assertDatabaseHas('users', ['email' => 'mario.casino@example.com']);
    }

    public function test_registration_still_rejects_hard_spam_email(): void
    {
        $this->post('/en/register', [
            'name' => 'Mario',
            'surname' => 'Rossi',
            'email' => 'viagra@example.com',
            'password' => 'password123',
            'agree_terms' => 'on',
        ]);

        $this->assertDatabaseMissing('users', ['email' => 'viagra@example.com']);
    }
}
