<?php

namespace Tests\Feature;

use App\Mail\PetitionMilestoneMail;
use App\Mail\VerifyAccountMail;
use App\Models\Petition;
use App\Models\PetitionTranslation;
use App\Models\User;
use Database\Seeders\LanguageSeeder;
use Database\Seeders\UserLevelSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TransactionalMailLocalizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([UserLevelSeeder::class, LanguageSeeder::class]);
    }

    public function test_verify_mail_uses_the_italian_subject(): void
    {
        $user = User::factory()->create([
            'locale' => 'it',
            'verified' => false,
        ]);

        $user->issueVerificationToken();
        $mail = new VerifyAccountMail($user, 'it');
        $mail->render();

        $this->assertSame('Verifica il tuo account', $mail->subject);
    }

    public function test_milestone_mail_uses_the_italian_subject(): void
    {
        $owner = User::factory()->create(['locale' => 'it']);
        $petition = Petition::factory()->create(['user_id' => $owner->id, 'status' => 'published', 'is_active' => true]);
        PetitionTranslation::create([
            'petition_id' => $petition->id,
            'locale' => 'it',
            'title' => 'Salva il Parco',
            'slug' => 'salva-il-parco',
            'description' => 'Descrizione',
        ]);

        $mail = new PetitionMilestoneMail($petition, $owner, 1000);
        $html = $mail->render();

        $this->assertSame('La tua petizione ha appena raggiunto 1,000 firme', $mail->subject);
        $this->assertStringContainsString('Salva il Parco', $html);
    }

    public function test_verify_mail_email_change_variant_drops_the_joining_line(): void
    {
        $user = User::factory()->create([
            'locale' => 'en',
            'verified' => false,
            'pending_email' => 'new-address@example.com',
        ]);

        $user->issueVerificationToken();
        $html = (new VerifyAccountMail($user, 'en'))->render();

        $this->assertStringNotContainsString('Thank you for joining FreeCause', $html);
        $this->assertStringContainsString('Please confirm this new email address', $html);
    }

    public function test_verify_mail_signup_variant_keeps_the_joining_line(): void
    {
        $user = User::factory()->create([
            'locale' => 'en',
            'verified' => false,
        ]);

        $user->issueVerificationToken();
        $html = (new VerifyAccountMail($user, 'en'))->render();

        $this->assertStringContainsString('Thank you for joining FreeCause', $html);
    }
}
