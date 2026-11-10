<?php

namespace Tests\Feature;

use App\Mail\ContactMail;
use Database\Seeders\LanguageSeeder;
use Database\Seeders\UserLevelSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ContactNoJsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([UserLevelSeeder::class, LanguageSeeder::class]);
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    public function test_regular_post_redirects_with_contact_sent_and_hides_form(): void
    {
        Mail::fake();

        $response = $this->from('/en/contacts')->post('/en/contacts', [
            'name' => 'No JS User',
            'email' => 'nojs@example.com',
            'text' => 'Hello from a browser without JS.',
        ]);

        $response->assertRedirect('/en/contacts');
        $response->assertSessionHas('contact_sent', true);

        Mail::assertQueued(ContactMail::class);

        $this->get('/en/contacts')->assertOk()
            ->assertSee(__('contacts.sent', [], 'en'), false)
            ->assertSee(__('contacts.send_another', [], 'en'), false)
            ->assertDontSee('id="contactForm"', false);
    }

    public function test_ajax_post_returns_json_and_no_contact_sent_flag(): void
    {
        Mail::fake();

        $response = $this->postJson('/en/contacts', [
            'name' => 'JS User',
            'email' => 'js@example.com',
            'text' => 'Hello via fetch.',
        ]);

        $response->assertOk()->assertJson(['success' => true]);
        $response->assertJsonPath('message', __('contacts.sent', [], 'en'));
        $response->assertSessionMissing('contact_sent');

        Mail::assertQueued(ContactMail::class);
    }

    public function test_daily_cap_blocks_further_mail(): void
    {
        Mail::fake();

        $payload = [
            'name' => 'Capped User',
            'email' => 'capped@example.com',
            'text' => 'Hello again and again.',
        ];

        for ($i = 0; $i < 30; $i++) {
            $this->from('/en/contacts')->post('/en/contacts', $payload)->assertSessionHas('contact_sent', true);
        }

        Mail::assertQueuedCount(30);

        $this->postJson('/en/contacts', $payload)->assertStatus(500);
        $this->from('/en/contacts')->post('/en/contacts', $payload)->assertSessionHasErrors('email');

        Mail::assertQueuedCount(30);
    }
}
