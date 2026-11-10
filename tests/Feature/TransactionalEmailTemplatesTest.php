<?php

namespace Tests\Feature;

use App\Jobs\SyncEmailTemplates;
use App\Mail\PetitionDigestMail;
use App\Mail\PetitionMilestoneMail;
use App\Mail\PetitionNewSignaturesMail;
use App\Mail\VerifyAccountMail;
use App\Mail\WelcomeAccountMail;
use App\Models\PageContent;
use App\Models\Petition;
use App\Models\PetitionTranslation;
use App\Models\User;
use App\Models\UserLevel;
use App\Services\EmailTemplateTranslator;
use App\Support\Locale;
use App\Support\MailText;
use App\Support\Settings;
use Database\Seeders\LanguageSeeder;
use Database\Seeders\UserLevelSeeder;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class TransactionalEmailTemplatesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([LanguageSeeder::class, UserLevelSeeder::class]);
        Http::preventStrayRequests();
    }

    private function admin(): User
    {
        return User::factory()->create(['level_id' => UserLevel::where('name', 'admin')->value('id'), 'verified' => true]);
    }

    public function test_every_user_email_renders_in_the_saved_language(): void
    {
        $petition = Petition::factory()->create();
        foreach (Locale::supported() as $locale) {
            $user = User::factory()->create(['locale' => Locale::toFull($locale), 'verified' => true]);
            $user->issueVerificationToken();
            $mails = [new VerifyAccountMail($user, 'en'), new WelcomeAccountMail($locale),
                new PetitionMilestoneMail($petition, $user, 100),
                new PetitionNewSignaturesMail($petition, $user, 5, collect()),
                new PetitionDigestMail($user, collect([$petition]))];
            foreach ($mails as $mail) {
                $html = $mail->render();
                $this->assertStringContainsString('lang="'.$locale.'"', $html, get_class($mail));
                $this->assertStringNotContainsString('emails.', $html);
                $this->assertStringNotContainsString('profile.welcome_', $html);
                $this->assertStringNotContainsString(':hours', $html);
                $this->assertStringNotContainsString(':count', $html);
            }
            $reset = (new ResetPassword('synthetic-reset'))->toMail($user);
            $html = view($reset->view, $reset->viewData)->render();
            $this->assertStringContainsString('lang="'.$locale.'"', $html);
            $this->assertStringContainsString('/'.$locale.'/reset-password', $html);
            $this->assertStringContainsString(e(MailText::get('reset_subject', [], $locale)), $html);
        }
    }

    public function test_stored_admin_text_is_used_in_the_outgoing_email(): void
    {
        PageContent::create(['page' => 'emails', 'locale' => 'it', 'key' => 'welcome_subject', 'value' => 'Benvenuto nella nostra comunità']);
        $html = (new WelcomeAccountMail('it'))->render();
        $this->assertStringContainsString('Benvenuto nella nostra comunità', $html);
        $this->assertStringNotContainsString('Benvenuto nella nostra comunità', (new WelcomeAccountMail('fr'))->render());
    }

    public function test_legacy_account_must_confirm_language_before_signing(): void
    {
        $owner = User::factory()->create(['verified' => true]);
        $petition = Petition::factory()->create(['user_id' => $owner->id, 'status' => 'published', 'is_active' => true]);
        PetitionTranslation::create(['petition_id' => $petition->id, 'locale' => 'en', 'title' => 'Legacy', 'slug' => 'legacy', 'description' => '<p>Description.</p>']);

        $user = User::factory()->create([
            'verified' => true,
            'country' => 'IT',
            'gender' => 'female',
            'locale' => 'it_IT',
            'preferred_language_confirmed_at' => null,
            'welcome_email_pending' => false,
        ]);

        $this->assertFalse($user->hasCompleteProfile());

        $response = $this->actingAs($user)->post('/en/petition/legacy/'.$petition->id.'/sign', [
            'agree1' => 'agree', 'agree2' => 'agree', 'agree3' => 'agree',
        ]);
        $response->assertRedirectContains('/en/profile');
        $this->assertDatabaseMissing('signatures', ['petition_id' => $petition->id, 'user_id' => $user->id]);

        $user->forceFill(['preferred_language_confirmed_at' => now()])->save();
        $this->assertTrue($user->fresh()->hasCompleteProfile());
    }

    public function test_incomplete_legacy_account_cannot_use_sign_confirmation_or_shoutbox(): void
    {
        $user = User::factory()->create(['verified' => true, 'preferred_language_confirmed_at' => null, 'country' => null, 'gender' => null]);
        $this->actingAs($user)->get('/en/petition/test/1/sign/confirm')->assertRedirectContains('/en/profile');
        $this->actingAs($user)->post('/en/petition/test/1/shoutbox', ['text' => 'Blocked until complete'])->assertRedirectContains('/en/profile');
        $this->actingAs($user)->get('/en/profile')->assertOk();
    }

    public function test_admin_can_store_email_defaults_but_must_keep_placeholders(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin, 'admin')->post('/admin/options/language', ['locale' => 'it', 'email_templates' => ['welcome_subject' => 'Benvenuto personalizzato']])
            ->assertSessionHasNoErrors();
        $this->assertSame('Benvenuto personalizzato', MailText::get('welcome_subject', [], 'it'));
        $this->actingAs($admin, 'admin')->post('/admin/options/language', ['locale' => 'it', 'email_templates' => ['milestone_subject' => 'Manca il numero']])
            ->assertSessionHasErrors('email_templates.milestone_subject');
        $this->actingAs($admin, 'admin')->post('/admin/options/language', ['locale' => 'it', 'email_templates' => ['unknown' => 'Do not save']])
            ->assertSessionHasErrors('email_templates');
    }

    public function test_api_key_is_encrypted_and_never_rendered(): void
    {
        $admin = $this->admin();
        $key = 'synthetic-deepl-key:fx';
        $this->actingAs($admin, 'admin')->post('/admin/options/language/translation-key', ['locale' => 'en', 'deepl_api_key' => $key])->assertSessionHasNoErrors();
        $stored = Settings::get('deepl_api_key');
        $this->assertNotSame($key, $stored);
        $this->assertSame($key, Crypt::decryptString($stored));
        $this->actingAs($admin, 'admin')->get('/admin/options/language?locale=en')->assertOk()->assertDontSee($key)->assertDontSee($stored);
    }

    public function test_sync_queues_one_job_for_each_other_supported_language(): void
    {
        Queue::fake();
        Settings::set('deepl_api_key', Crypt::encryptString('synthetic-key:fx'));
        $this->actingAs($this->admin(), 'admin')->post('/admin/options/language', ['locale' => 'en', 'translate_all' => '1', 'email_templates' => ['welcome_subject' => 'A new welcome']])->assertSessionHasNoErrors();
        Queue::assertPushed(SyncEmailTemplates::class, count(Locale::supported()) - 1);
    }

    public function test_provider_preserves_placeholders_and_uses_server_side_authentication(): void
    {
        Settings::set('deepl_api_key', Crypt::encryptString('synthetic-key:fx'));
        Http::fake(['api-free.deepl.com/*' => Http::response(['translations' => [['text' => '<text>Ciao <keep>__FC_VAR_0__</keep></text>']]])]);
        $result = app(EmailTemplateTranslator::class)->translate(['greeting' => 'Hello :name'], 'en', 'it');
        $this->assertSame('Ciao :name', $result['greeting']);
        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'DeepL-Auth-Key synthetic-key:fx') && $request['ignore_tags'] === ['keep'] && $request['target_lang'] === 'IT' && $request['text'] === ['<text>Hello <keep>__FC_VAR_0__</keep></text>']);
    }

    public function test_opaque_markers_keep_count_from_being_interpreted_as_a_verb(): void
    {
        Settings::set('deepl_api_key', Crypt::encryptString('synthetic-key:fx'));
        Http::fake(['*' => Http::response(['translations' => [['text' => '<text><keep>__FC_VAR_0__</keep> nouvelles signatures</text>']]])]);
        $result = app(EmailTemplateTranslator::class)->translate(['heading' => ':count new signatures'], 'en', 'fr');
        $this->assertSame(':count nouvelles signatures', $result['heading']);
        Http::assertSent(fn ($request) => ! str_contains(implode('', $request['text']), ':count'));
    }

    public function test_repeated_placeholders_can_be_reordered_without_losing_their_identity(): void
    {
        Settings::set('deepl_api_key', Crypt::encryptString('synthetic-key:fx'));
        Http::fake(['*' => Http::response(['translations' => [['text' => '<text><keep>__FC_VAR_1__</keep>: <keep>__FC_VAR_0__</keep> / <keep>__FC_VAR_2__</keep></text>']]])]);
        $result = app(EmailTemplateTranslator::class)->translate(['heading' => ':count signatures on :title (:count)'], 'en', 'fr');
        $this->assertSame(':title: :count / :count', $result['heading']);
    }

    public function test_provider_output_with_a_changed_marker_is_rejected(): void
    {
        Settings::set('deepl_api_key', Crypt::encryptString('synthetic-key:fx'));
        Http::fake(['*' => Http::response(['translations' => [['text' => '<text>Ciao <keep>__FC_VAR_9__</keep></text>']]])]);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('protected placeholder');
        app(EmailTemplateTranslator::class)->translate(['greeting' => 'Hello :name'], 'en', 'it');
    }

    public function test_quota_exhaustion_does_not_overwrite_saved_translations(): void
    {
        Settings::set('deepl_api_key', Crypt::encryptString('synthetic-key:fx'));
        PageContent::create(['page' => 'emails', 'locale' => 'it', 'key' => 'welcome_subject', 'value' => 'Keep this Italian welcome']);
        Http::fake(['*' => Http::response([], 456)]);
        try {
            (new SyncEmailTemplates('en', 'it', MailText::catalog('en')))->handle(app(EmailTemplateTranslator::class));
            $this->fail('Expected quota error');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('quota exhausted', $e->getMessage());
        }
        $this->assertSame('Keep this Italian welcome', MailText::get('welcome_subject', [], 'it'));
    }

    public function test_changed_source_does_not_publish_a_stale_translation(): void
    {
        $job = new SyncEmailTemplates('en', 'it', MailText::catalog('en'));
        PageContent::create(['page' => 'emails', 'locale' => 'en', 'key' => 'welcome_subject', 'value' => 'Newer source']);
        MailText::clear('en');
        $job->handle(app(EmailTemplateTranslator::class));
        Http::assertNothingSent();
    }

    public function test_seed_command_preserves_existing_admin_edits(): void
    {
        PageContent::create(['page' => 'emails', 'locale' => 'it', 'key' => 'welcome_subject', 'value' => 'Custom Italian welcome']);
        $this->artisan('emails:seed-templates')->assertSuccessful();
        $this->assertSame('Custom Italian welcome', MailText::get('welcome_subject', [], 'it'));
        foreach (Locale::supported() as $locale) {
            $this->assertTrue(PageContent::where('page', 'emails')->where('locale', $locale)->where('key', 'reset_subject')->exists());
        }
    }

    public function test_unchanged_source_reuses_saved_translations_without_a_provider_request(): void
    {
        $texts = MailText::catalog('en');
        foreach ($texts as $field => $value) {
            PageContent::create(['page' => 'emails', 'locale' => 'it', 'key' => $field, 'value' => $value]);
        }
        $hashes = array_map(fn ($text) => hash('sha256', $text), $texts);
        (new SyncEmailTemplates('en', 'it', $texts, $hashes))->handle(app(EmailTemplateTranslator::class));
        Http::assertNothingSent();
        $this->assertSame('completed', Settings::get('sync_it', [], 'email_templates')['state']);
    }

    public function test_provider_output_that_loses_a_placeholder_is_rejected(): void
    {
        Settings::set('deepl_api_key', Crypt::encryptString('synthetic-key:fx'));
        Http::fake(['*' => Http::response(['translations' => [['text' => '<text>Ciao</text>']]])]);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('placeholder');
        app(EmailTemplateTranslator::class)->translate(['greeting' => 'Hello :name'], 'en', 'it');
    }
}
