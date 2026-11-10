<?php

namespace Tests\Feature;

use App\Mail\PetitionDigestMail;
use App\Mail\PetitionMilestoneMail;
use App\Mail\PetitionNewSignaturesMail;
use App\Models\Petition;
use App\Models\User;
use Database\Seeders\LanguageSeeder;
use Database\Seeders\UserLevelSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

class ListUnsubscribeHeadersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([UserLevelSeeder::class, LanguageSeeder::class]);
    }

    public function test_product_mails_carry_one_click_unsubscribe_headers(): void
    {
        $petition = Petition::factory()->create();
        $user = User::factory()->create(['locale' => 'en', 'verified' => true]);

        $mails = [
            new PetitionDigestMail($user, collect([$petition])),
            new PetitionMilestoneMail($petition, $user, 100),
            new PetitionNewSignaturesMail($petition, $user, 5, collect()),
        ];

        foreach ($mails as $mail) {
            $mail->build();
            $message = new Email;
            foreach ($mail->callbacks as $callback) {
                $callback($message);
            }

            $this->assertStringContainsString('/unsubscribe/'.$user->fresh()->unsubscribe_token, $message->getHeaders()->getHeaderBody('List-Unsubscribe'), get_class($mail));
            $this->assertStringStartsWith('<', $message->getHeaders()->getHeaderBody('List-Unsubscribe'));
            $this->assertSame('List-Unsubscribe=One-Click', $message->getHeaders()->getHeaderBody('List-Unsubscribe-Post'), get_class($mail));
        }
    }

    public function test_unsubscribe_post_skips_csrf_and_unsubscribes(): void
    {
        $user = User::factory()->create(['locale' => 'en', 'notify_product' => true, 'unsubscribed_at' => null]);
        $token = $user->ensureUnsubscribeToken();

        $this->app['env'] = 'production';

        $this->post(lroute('preferences.unsubscribe', ['locale' => 'en', 'token' => $token]), ['List-Unsubscribe' => 'One-Click'])->assertOk();

        $this->assertNotNull($user->fresh()->unsubscribed_at);
    }
}
