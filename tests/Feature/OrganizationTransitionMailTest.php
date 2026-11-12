<?php

namespace Tests\Feature;

use App\Jobs\SendOrganizationNotification;
use App\Mail\OrganizationStatusMail;
use App\Models\Organization;
use App\Models\OrganizationNotification;
use App\Models\User;
use App\Models\UserLevel;
use App\Support\Locale;
use App\Support\MailText;
use App\Support\Settings;
use Database\Seeders\LanguageSeeder;
use Database\Seeders\UserLevelSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class OrganizationTransitionMailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed([UserLevelSeeder::class, LanguageSeeder::class]);
        config(['organizations.notifications_enabled' => true, 'organizations.admin_email' => 'admin@unit.test']);
        Settings::set('smtp_enabled', true);
        Bus::fake();
        Mail::fake();
    }

    private function user(array $overrides = []): User
    {
        return User::factory()->create(array_replace(['verified' => true, 'locale' => 'en', 'gender' => 'none'], $overrides));
    }

    private function organization(User $owner): Organization
    {
        return Organization::create(['name' => 'Transition Test Organization', 'slug' => 'transition-test', 'owner_user_id' => $owner->id, 'status' => 'verified', 'country' => 'US', 'type' => 'committee', 'verified_at' => now(), 'verification_reference' => 'Private test evidence']);
    }

    private function admin(): User
    {
        return $this->user(['level_id' => UserLevel::where('name', 'admin')->value('id')]);
    }

    public function test_each_founding_toggle_sends_one_owner_notification_and_replay_does_not_duplicate(): void
    {
        $owner = $this->user();
        $org = $this->organization($owner);
        $this->actingAs($this->admin(), 'admin');
        foreach ([true, false] as $index => $enabled) {
            $this->post('/admin/organizations/'.$org->id.'/founding', ['confirm' => 1])->assertRedirect();
            $this->assertSame($enabled, $org->fresh()->founding_partner);
            $notice = OrganizationNotification::where('event', 'founding')->latest('id')->firstOrFail();
            $this->assertSame($owner->id, $notice->recipient_user_id);
            $this->assertFalse($notice->to_admin);
            (new SendOrganizationNotification($notice->id))->handle();
            (new SendOrganizationNotification($notice->id))->handle();
            Mail::assertSent(OrganizationStatusMail::class, $index + 1);
            Mail::assertSent(OrganizationStatusMail::class, fn ($mail) => $mail->hasTo($owner->email) && str_contains($mail->render(), 'founding recognition'));
        }
    }

    public function test_reassignment_notifies_the_new_owner_in_their_language(): void
    {
        $old = $this->user();
        $replacement = $this->user(['locale' => 'it_IT']);
        $org = $this->organization($old);
        $this->actingAs($this->admin(), 'admin')->post('/admin/organizations/'.$org->id.'/reassign', ['confirm' => 1, 'owner_user_id' => $replacement->id])->assertRedirect();
        $notice = OrganizationNotification::where('event', 'owner_reassigned')->sole();
        $this->assertSame($replacement->id, $notice->recipient_user_id);
        (new SendOrganizationNotification($notice->id))->handle();
        Mail::assertSent(OrganizationStatusMail::class, 1);
        Mail::assertSent(OrganizationStatusMail::class, fn ($mail) => $mail->hasTo($replacement->email) && $mail->emailLocale === 'it' && ! str_contains($mail->render(), ':name'));
        Mail::assertNotSent(OrganizationStatusMail::class, fn ($mail) => $mail->hasTo($old->email));
    }

    public function test_removal_request_email_describes_removal_rather_than_a_new_application(): void
    {
        $owner = $this->user();
        $org = $this->organization($owner);
        $this->actingAs($owner)->post('/en/org/'.$org->slug.'/manage/deletion-request', ['confirm' => 1])->assertRedirect();
        $notice = OrganizationNotification::where('event', 'deletion_requested')->sole();
        (new SendOrganizationNotification($notice->id))->handle();
        Mail::assertSent(OrganizationStatusMail::class, 1);
        Mail::assertSent(OrganizationStatusMail::class, function ($mail) {
            $body = $mail->render();

            return $mail->hasTo('admin@unit.test') && str_contains($body, 'requested removal') && ! str_contains($body, 'application for');
        });
    }

    public function test_ownerless_founding_changes_do_not_create_an_undeliverable_owner_notice(): void
    {
        $org = Organization::create(['name' => 'Unclaimed Test', 'slug' => 'unclaimed-test', 'status' => 'unclaimed', 'country' => 'US', 'type' => 'other', 'source' => 'admin', 'verification_reference' => '']);
        $this->actingAs($this->admin(), 'admin')->post('/admin/organizations/'.$org->id.'/founding', ['confirm' => 1])->assertRedirect();
        $this->assertDatabaseCount('organization_notifications', 0);
    }

    public function test_new_mail_defaults_exist_in_all_supported_languages(): void
    {
        foreach (Locale::supported() as $locale) {
            foreach (['founding', 'owner_reassigned', 'admin_deletion'] as $event) {
                foreach (['subject', 'body'] as $field) {
                    $this->assertNotEmpty(MailText::defaults($locale)['organization_'.$event.'_'.$field] ?? '', $locale.' '.$event.' '.$field);
                }
            }
        }
    }
}
