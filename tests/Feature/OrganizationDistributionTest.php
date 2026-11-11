<?php

namespace Tests\Feature;

use App\Jobs\SendOrganizationNotification;
use App\Mail\OrganizationStatusMail;
use App\Models\Log;
use App\Models\Organization;
use App\Models\OrganizationNotification;
use App\Models\Petition;
use App\Models\Signature;
use App\Models\User;
use App\Models\UserLevel;
use App\Services\OrganizationLifecycle;
use App\Support\Settings;
use Database\Seeders\LanguageSeeder;
use Database\Seeders\OrganizationCauseSeeder;
use Database\Seeders\UserLevelSeeder;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class OrganizationDistributionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Real commits exercise outbox callbacks; use a fresh in-memory database per test.
        $this->artisan('migrate:fresh');
        $this->withoutVite();
        config(['organizations.public_launch' => true, 'organizations.min_directory_count' => 1]);
        config(['organizations.notifications_enabled' => true]);
        $this->seed([UserLevelSeeder::class, LanguageSeeder::class, OrganizationCauseSeeder::class]);
        Bus::fake();
    }

    private function owner(): User
    {
        return User::factory()->create(['verified' => true, 'locale' => 'it_IT', 'gender' => 'none']);
    }

    private function org(?User $owner = null, array $overrides = []): Organization
    {
        return Organization::create(array_replace([
            'owner_user_id' => ($owner ?? $this->owner())->id, 'slug' => 'org-'.bin2hex(random_bytes(5)),
            'name' => 'Distribution Test Organization', 'type' => 'association', 'country' => 'IT',
            'status' => 'verified', 'verified_at' => now(), 'verification_reference' => 'Private reference',
            'representation_confirmed_at' => now(), 'terms_accepted_at' => now(), 'terms_version' => 'test-only',
        ], $overrides));
    }

    private function petition(Organization $org, array $extra = []): Petition
    {
        $petition = Petition::factory()->create(array_replace(['organization_id' => $org->id, 'user_id' => $org->owner_user_id, 'status' => 'published', 'is_active' => true, 'signature_count' => 17], $extra));
        $petition->translations()->create(['locale' => 'en', 'slug' => 'test-campaign-'.$petition->id, 'title' => 'Protect our local park', 'description' => 'A sufficiently detailed campaign description for our community.']);

        return $petition;
    }

    private function outcomeUrl(Petition $petition): string
    {
        return '/en/petition/test-campaign-'.$petition->id.'/'.$petition->id.'/outcome';
    }

    private function victory(): array
    {
        return ['outcome' => 'victory', 'outcome_at' => now()->toDateString(), 'outcome_summary' => 'The council accepted our request.'];
    }

    public function test_promoter_can_update_outcome_after_signatures_without_changing_editorial_state(): void
    {
        $org = $this->org();
        $petition = $this->petition($org);
        Signature::factory()->create(['petition_id' => $petition->id, 'confirmed' => true, 'is_spam' => false]);
        $this->actingAs($org->owner)->post($this->outcomeUrl($petition), $this->victory())->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('victory', $petition->fresh()->outcome);
        $this->assertSame('published', $petition->fresh()->status);
        $this->assertTrue((bool) $petition->fresh()->is_active);
        $this->assertSame(17, (int) $petition->fresh()->signature_count);
        $this->assertDatabaseCount('petition_outcome_events', 1);
        $this->get('/en/petition/test-campaign-'.$petition->id.'/'.$petition->id)->assertOk()->assertSee('Victory reported by the organizer')->assertSee('The council accepted our request.');
        $petition->forceFill(['is_active' => false])->save();
        $this->get('/en/petition/test-campaign-'.$petition->id.'/'.$petition->id)->assertOk()->assertDontSee('Victory reported by the organizer');
    }

    public function test_reassigned_owner_can_update_org_outcomes_but_not_personal_outcomes(): void
    {
        $org = $this->org();
        $petition = $this->petition($org);
        $personal = $this->petition($org, ['organization_id' => null]);
        $stranger = $this->owner();
        $this->actingAs($stranger)->post($this->outcomeUrl($petition), $this->victory())->assertForbidden();
        $newOwner = $this->owner();
        $org->update(['owner_user_id' => $newOwner->id]);
        $this->actingAs($newOwner)->post($this->outcomeUrl($petition), $this->victory())->assertRedirect();
        $this->get($this->outcomeUrl($petition))->assertOk();
        $this->assertSame('victory', $petition->fresh()->outcome);
        $this->post($this->outcomeUrl($personal), $this->victory())->assertForbidden();
        $this->assertSame('open', $personal->fresh()->outcome);
    }

    public static function invalidOutcomes(): array
    {
        return [
            [['outcome' => 'invented'], 'outcome'],
            [['outcome_at' => '2999-01-01'], 'outcome_at'],
            [['outcome_at' => '2026-02-30'], 'outcome_at'],
            [['outcome_at' => ''], 'outcome_at'],
            [['outcome_summary' => ''], 'outcome_summary'],
            [['outcome_summary' => '<b>Victory</b>'], 'outcome_summary'],
            [['outcome_summary' => str_repeat('a', 501)], 'outcome_summary'],
        ];
    }

    #[DataProvider('invalidOutcomes')]
    public function test_outcome_validation_rejects_invalid_claims(array $extra, string $field): void
    {
        $org = $this->org();
        $petition = $this->petition($org);
        $this->actingAs($org->owner)->post($this->outcomeUrl($petition), array_replace($this->victory(), $extra))->assertSessionHasErrors($field);
        $this->assertSame('open', $petition->fresh()->outcome);
        $this->assertDatabaseCount('petition_outcome_events', 0);
    }

    public function test_owner_outcome_summary_with_hard_spam_keyword_is_rejected_and_logged(): void
    {
        $org = $this->org();
        $petition = $this->petition($org);
        $this->actingAs($org->owner)->post($this->outcomeUrl($petition), array_replace($this->victory(), ['outcome_summary' => 'Buy viagra cheap pills']))->assertSessionHasErrors('outcome_summary');
        $this->assertSame('open', $petition->fresh()->outcome);
        $this->assertDatabaseCount('petition_outcome_events', 0);
        $this->assertDatabaseHas('spam_logs', ['type' => 'petition.outcome']);
    }

    public function test_owner_outcome_summary_with_review_keyword_is_rejected_and_clean_summary_saves(): void
    {
        $org = $this->org();
        $petition = $this->petition($org);
        $this->actingAs($org->owner)->post($this->outcomeUrl($petition), array_replace($this->victory(), ['outcome_summary' => 'We won thanks to the online casino']))->assertSessionHasErrors('outcome_summary');
        $this->assertSame('open', $petition->fresh()->outcome);
        $this->post($this->outcomeUrl($petition), $this->victory())->assertSessionHasNoErrors();
        $this->assertSame('victory', $petition->fresh()->outcome);
    }

    public function test_reopen_needs_confirmation_and_preserves_audit_without_publishing_a_draft(): void
    {
        $org = $this->org();
        $petition = $this->petition($org, ['status' => 'draft']);
        $this->actingAs($org->owner)->post($this->outcomeUrl($petition), $this->victory())->assertSessionHasNoErrors();
        $this->post($this->outcomeUrl($petition), ['outcome' => 'open'])->assertSessionHasErrors('confirm_reopen');
        $this->post($this->outcomeUrl($petition), ['outcome' => 'open', 'confirm_reopen' => 1])->assertRedirect()->assertSessionHasNoErrors();
        $petition->refresh();
        $this->assertSame('draft', $petition->status);
        $this->assertSame('open', $petition->outcome);
        $this->assertNull($petition->outcome_at);
        $this->assertNull($petition->outcome_summary);
        $this->assertDatabaseCount('petition_outcome_events', 2);
        $last = DB::table('petition_outcome_events')->orderByDesc('id')->first();
        $this->assertSame('victory', json_decode($last->before, true)['outcome']);
    }

    public function test_admin_can_update_outcomes_with_existing_permission_and_form_is_private(): void
    {
        $org = $this->org();
        $petition = $this->petition($org);
        $admin = User::factory()->create(['verified' => true, 'level_id' => UserLevel::where('name', 'admin')->value('id')]);
        $this->actingAs($admin, 'admin')->get('/admin/petitions/'.$petition->id.'/outcome')->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->post('/admin/petitions/'.$petition->id.'/outcome', $this->victory())->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame($admin->id, $petition->fresh()->outcome_updated_by);
    }

    public function test_profile_victories_are_public_only_and_sorted_before_newer_open_campaigns(): void
    {
        $org = $this->org();
        $winner = $this->petition($org, ['outcome' => 'victory', 'outcome_at' => now()->toDateString(), 'outcome_summary' => 'Reported result', 'created_at' => now()->subWeek()]);
        $open = $this->petition($org);
        $this->petition($org, ['outcome' => 'victory', 'status' => 'draft']);
        $this->petition($org, ['outcome' => 'victory', 'is_active' => false]);
        $response = $this->get('/en/org/'.$org->slug)->assertOk();
        $this->assertSame(1, $response->viewData('org')->victories_count);
        $this->assertSame([$winner->id, $open->id], $response->viewData('campaigns')->pluck('id')->all());
    }

    public function test_svg_badge_escapes_names_uses_public_counters_and_has_no_cookie_or_external_resource(): void
    {
        $org = $this->org(null, ['name' => '<script>alert(1)</script> & Test']);
        $this->petition($org, ['signature_count' => 19]);
        $this->petition($org, ['signature_count' => 9000, 'status' => 'draft']);
        $response = $this->get('/it/org/'.$org->slug.'/badge.svg')->assertOk()->assertHeader('Content-Type', 'image/svg+xml; charset=UTF-8')->assertSee('19 firme raccolte')->assertDontSee('<script>', false)->assertDontSee('9000');
        $this->assertFalse($response->headers->has('Set-Cookie'));
        $this->assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
        $this->assertStringContainsString("default-src 'none'", $response->headers->get('Content-Security-Policy'));
        $xml = new \DOMDocument;
        $this->assertTrue($xml->loadXML($response->getContent(), LIBXML_NONET));
        $this->assertSame(0, $xml->getElementsByTagName('image')->length);
        $etag = $response->headers->get('ETag');
        $this->withHeader('If-None-Match', $etag)->get('/it/org/'.$org->slug.'/badge.svg')->assertStatus(304);
        $org->update(['status' => 'suspended']);
        $this->withHeader('If-None-Match', $etag)->get('/it/org/'.$org->slug.'/badge.svg')->assertOk()->assertSee('Profilo non disponibile')->assertDontSee('Test')->assertDontSee('19');
        $this->get('/en/org/'.$org->slug)->assertNotFound();
    }

    public function test_embed_uses_canonical_host_locale_and_tagged_profile_without_signer_data(): void
    {
        $org = $this->org();
        $response = $this->actingAs($org->owner)->get('/it/org/'.$org->slug.'/manage')->assertOk();
        $response->assertSee('?source=org-badge')->assertSee('/it/org/'.$org->slug.'/badge.svg')->assertSee('width=&quot;320&quot;', false);
    }

    public function test_sitemap_and_homepage_respect_verification_and_invalidate_after_state_change(): void
    {
        $one = $this->org();
        $two = $this->org();
        $pending = $this->org(null, ['status' => 'pending']);
        $this->get('/en')->assertOk()->assertViewHas('organizations', fn ($orgs) => $orgs->count() === 2);
        $this->get('/sitemap.xml')->assertSee('sitemap-organizations.xml');
        $this->get('/sitemap-organizations.xml')->assertOk()->assertSee('/en/org/'.$one->slug)->assertSee('/it/org/'.$one->slug)->assertDontSee('/de/org/'.$one->slug)->assertDontSee($pending->slug);
        $pending->update(['status' => 'verified', 'verified_at' => now()]);
        $this->get('/sitemap-organizations.xml')->assertSee($pending->slug);
        $this->get('/en')->assertViewHas('organizations', fn ($orgs) => $orgs->count() === 3);
        $pending->update(['status' => 'suspended']);
        $this->get('/sitemap-organizations.xml')->assertDontSee($pending->slug);
        $this->get('/en')->assertViewHas('organizations', fn ($orgs) => $orgs->count() === 2);
    }

    public function test_notification_outbox_dispatches_only_after_commit_and_disappears_on_rollback(): void
    {
        $org = $this->org();
        DB::beginTransaction();
        app(OrganizationLifecycle::class)->record($org, $org->owner_user_id, 'applied');
        $this->assertDatabaseCount('organization_notifications', 2);
        Bus::assertNotDispatched(SendOrganizationNotification::class);
        DB::rollBack();
        $this->assertDatabaseCount('organization_notifications', 0);
        DB::transaction(fn () => app(OrganizationLifecycle::class)->record($org, $org->owner_user_id, 'applied'));
        Bus::assertDispatchedTimes(SendOrganizationNotification::class, 2);
    }

    public function test_localized_status_mail_uses_admin_defaults_and_delivery_is_idempotent(): void
    {
        Mail::fake();
        Settings::set('smtp_enabled', true, 'global');
        $org = $this->org();
        app(OrganizationLifecycle::class)->record($org, $org->owner_user_id, 'verify');
        $notice = OrganizationNotification::firstOrFail();
        $job = new SendOrganizationNotification($notice->id);
        $job->handle();
        $job->handle();
        Mail::assertSent(OrganizationStatusMail::class, function ($mail) use ($org) {
            $this->assertSame('it', $mail->emailLocale);
            $this->assertStringContainsString('è verificata', $mail->render());
            $this->assertStringContainsString('/it/org/'.$org->slug, $mail->render());

            return true;
        });
        Mail::assertSentCount(1);
        $this->assertNotNull($notice->fresh()->sent_at);
    }

    public function test_reassignment_cancels_old_owner_mail_and_transport_failure_keeps_pending_record(): void
    {
        Mail::fake();
        Settings::set('smtp_enabled', true, 'global');
        $org = $this->org();
        app(OrganizationLifecycle::class)->record($org, $org->owner_user_id, 'reject', ['review_note' => 'Private reason']);
        $notice = OrganizationNotification::firstOrFail();
        $org->update(['owner_user_id' => $this->owner()->id]);
        (new SendOrganizationNotification($notice->id))->handle();
        Mail::assertNothingSent();
        $this->assertNotNull($notice->fresh()->cancelled_at);
        app(OrganizationLifecycle::class)->record($org, $org->owner_user_id, 'verify');
        $pending = OrganizationNotification::orderByDesc('id')->firstOrFail();
        Mail::shouldReceive('to')->andThrow(new RuntimeException('Test SMTP failure'));
        try {
            (new SendOrganizationNotification($pending->id))->handle();
            $this->fail('Expected transport failure');
        } catch (RuntimeException $e) {
            $this->assertSame('Test SMTP failure', $e->getMessage());
        }
        $this->assertNull($pending->fresh()->sent_at);
        $this->assertNull($pending->fresh()->claimed_at);
        $this->assertSame('verified', $org->fresh()->status);
    }

    public function test_invalid_admin_address_is_logged_and_job_is_released(): void
    {
        Mail::fake();
        Settings::set('smtp_enabled', true, 'global');
        config(['organizations.notifications_enabled' => true, 'organizations.admin_email' => 'not-an-email']);
        $org = $this->org();
        app(OrganizationLifecycle::class)->record($org, $org->owner_user_id, 'verify');
        $notice = OrganizationNotification::firstOrFail();
        $notice->forceFill(['to_admin' => true])->save();
        $job = (new SendOrganizationNotification($notice->id))->withFakeQueueInteractions();

        $job->handle();

        $job->assertReleased(3600);
        Mail::assertNothingSent();
        $log = Log::where('context', 'organizations.notification')->firstOrFail();
        $this->assertSame('error', $log->level);
        $this->assertStringContainsString((string) $notice->id, $log->content);
        $this->assertStringNotContainsString('not-an-email', $log->content);
    }

    public function test_active_notification_claim_blocks_concurrent_delivery_and_expired_claim_retries(): void
    {
        Mail::fake();
        Settings::set('smtp_enabled', true, 'global');
        $org = $this->org();
        app(OrganizationLifecycle::class)->record($org, $org->owner_user_id, 'verify');
        $notice = OrganizationNotification::firstOrFail();
        $notice->forceFill(['claimed_at' => now(), 'claim_token' => 'active-claim'])->save();

        (new SendOrganizationNotification($notice->id))->handle();
        Mail::assertNothingSent();

        $notice->forceFill(['claimed_at' => now()->subMinutes(11)])->save();
        (new SendOrganizationNotification($notice->id))->handle();
        Mail::assertSentCount(1);
        $this->assertNotNull($notice->fresh()->sent_at);
        $this->assertNull($notice->fresh()->claimed_at);
    }

    public function test_disabled_smtp_keeps_notification_pending_and_recovery_queues_it(): void
    {
        Mail::fake();
        Settings::set('smtp_enabled', false, 'global');
        $org = $this->org();
        app(OrganizationLifecycle::class)->record($org, $org->owner_user_id, 'verify');
        $notice = OrganizationNotification::firstOrFail();
        (new SendOrganizationNotification($notice->id))->handle();
        Mail::assertNothingSent();
        $this->assertNull($notice->fresh()->sent_at);
        $notice->forceFill(['created_at' => now()->subHour()])->save();
        $this->artisan('organizations:retry-notifications')->assertSuccessful();
        Bus::assertDispatched(SendOrganizationNotification::class, fn ($job) => $job->notificationId === $notice->id);
    }

    public function test_directory_and_profile_have_localized_canonical_alternates(): void
    {
        $org = $this->org();
        $this->get('/it/org/'.$org->slug)->assertOk()->assertSee('hreflang="fr"', false)->assertSee('/fr/org/'.$org->slug, false);
        $this->get('/it/organizations/explore')->assertOk()->assertSee('hreflang="fr"', false);
        $this->get('/it/organizations/explore?country=IT')->assertOk()->assertSee('noindex, follow');
    }

    public function test_notification_pause_keeps_outbox_and_recovery_resumes_after_enabled(): void
    {
        config(['organizations.notifications_enabled' => false]);
        $org = $this->org();
        app(OrganizationLifecycle::class)->record($org, $org->owner_user_id, 'verify');
        $notice = OrganizationNotification::firstOrFail();
        Bus::assertNotDispatched(SendOrganizationNotification::class);
        $notice->forceFill(['created_at' => now()->subHour()])->save();
        $this->artisan('organizations:retry-notifications')->assertSuccessful();
        Bus::assertNotDispatched(SendOrganizationNotification::class);
        config(['organizations.notifications_enabled' => true]);
        $this->artisan('organizations:retry-notifications')->assertSuccessful();
        Bus::assertDispatched(SendOrganizationNotification::class, fn ($job) => $job->notificationId === $notice->id);
    }
}
