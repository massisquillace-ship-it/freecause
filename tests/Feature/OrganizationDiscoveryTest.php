<?php

namespace Tests\Feature;

use App\Mail\VerifyAccountMail;
use App\Models\Category;
use App\Models\Cause;
use App\Models\Organization;
use App\Models\Petition;
use App\Models\Signature;
use App\Models\User;
use App\Services\OrganizationConsent;
use App\Services\PetitionSigning;
use App\Support\Settings;
use Database\Seeders\LanguageSeeder;
use Database\Seeders\OrganizationCauseSeeder;
use Database\Seeders\UserLevelSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class OrganizationDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['organizations.public_launch' => true, 'organizations.min_directory_count' => 1]);
        $this->seed([UserLevelSeeder::class, LanguageSeeder::class, OrganizationCauseSeeder::class]);
        config(['organizations.consent_enabled' => true]);
    }

    private function owner(): User
    {
        return User::factory()->create(['verified' => true, 'locale' => 'en', 'gender' => 'none']);
    }

    private function org(?User $owner = null, array $overrides = []): Organization
    {
        return Organization::create(array_replace([
            'owner_user_id' => ($owner ?? $this->owner())->id, 'slug' => 'test-'.bin2hex(random_bytes(5)),
            'name' => 'Alpha Organization', 'type' => 'association', 'country' => 'IT',
            'status' => 'verified', 'verified_at' => now(), 'representation_confirmed_at' => now(), 'terms_accepted_at' => now(), 'terms_version' => 'test-only', 'verification_reference' => 'Private reference',
        ], $overrides));
    }

    private function petition(Organization $org, array $overrides = []): Petition
    {
        $petition = Petition::factory()->create(array_replace(['organization_id' => $org->id, 'user_id' => $org->owner_user_id, 'status' => 'published', 'is_active' => 1, 'signature_count' => 5], $overrides));
        $petition->translations()->create(['locale' => 'en', 'title' => 'Help protect our local park', 'slug' => 'protect-park-'.$petition->id, 'description' => 'A meaningful petition description for our test campaign.']);

        return $petition;
    }

    public function test_directory_combines_filters_escapes_wildcards_and_excludes_private_states(): void
    {
        $cause = Cause::first();
        $match = $this->org(null, ['name' => 'Literal %_! match']);
        $match->causes()->attach($cause);
        $other = $this->org(null, ['name' => 'Literal other match', 'country' => 'FR']);
        $other->causes()->attach($cause);
        $this->org(null, ['name' => 'Literal %_! private', 'status' => 'suspended']);
        $this->org(null, ['name' => 'Literal %_! ownerless', 'owner_user_id' => null]);
        $response = $this->get('/en/organizations/explore?'.http_build_query(['q' => '%_!', 'country' => 'IT', 'cause' => $cause->slug]));
        $response->assertOk()->assertSee('Literal %_! match')->assertDontSee('Literal other match')->assertDontSee('Literal %_! private')->assertDontSee('Literal %_! ownerless');
        $this->assertCount(1, $response->viewData('organizations'));
        $this->get('/en/organizations/explore?country=FR&cause='.$cause->slug)->assertSee($other->name)->assertDontSee($match->name);
    }

    public function test_directory_orders_stably_and_totals_use_public_petition_counters_only(): void
    {
        $older = $this->org(null, ['name' => 'Same name', 'verified_at' => now()->subDay()]);
        $newer = $this->org(null, ['name' => 'Same name']);
        $this->petition($older, ['signature_count' => 7]);
        $this->petition($older, ['signature_count' => 500, 'status' => 'draft']);
        $this->petition($older, ['signature_count' => 600, 'is_active' => false]);
        $this->petition($newer, ['signature_count' => 8]);
        DB::enableQueryLog();
        $response = $this->get('/en/organizations/explore?sort=signatures');
        $this->assertSame([$newer->id, $older->id], $response->viewData('organizations')->pluck('id')->all());
        $this->assertSame(7, (int) $response->viewData('organizations')->last()->signatures_total);
        $this->assertFalse(collect(DB::getQueryLog())->contains(fn ($q) => str_contains(strtolower($q['query']), 'from "signatures"')));
        DB::disableQueryLog();
        $this->assertSame([$older->id, $newer->id], $this->get('/en/organizations/explore')->viewData('organizations')->pluck('id')->all());
        $this->assertSame([$newer->id, $older->id], $this->get('/en/organizations/explore?sort=newest')->viewData('organizations')->pluck('id')->all());
        $this->get('/en/org/'.$older->slug)->assertOk()->assertViewHas('org', fn ($org) => (int) $org->signatures_total === 7 && $org->public_petitions_count === 1);
    }

    public function test_pagination_retains_filters_and_cause_ids_are_closed(): void
    {
        for ($i = 0; $i < 13; $i++) {
            $this->org(null, ['name' => 'Search '.$i]);
        }
        $this->get('/en/organizations/explore?q=Search&country=IT&sort=newest')->assertOk()->assertSee('q=Search', false)->assertSee('country=IT', false)->assertSee('sort=newest', false);
        $this->get('/en/organizations/explore?cause=unknown')->assertSessionHasErrors('cause');
    }

    public function test_attach_detach_preserves_authorship_and_historical_consents(): void
    {
        $org = $this->org();
        $petition = $this->petition($org, ['organization_id' => null]);
        $signature = Signature::factory()->create(['petition_id' => $petition->id]);
        $url = '/en/org/'.$org->slug.'/manage/petitions';
        $this->actingAs($org->owner)->post($url.'/attach', ['petition_id' => $petition->id])->assertSessionHasErrors('confirm');
        $this->post($url.'/attach', ['petition_id' => $petition->id, 'confirm' => 1])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame($org->id, $petition->fresh()->organization_id);
        $this->assertSame($org->owner_user_id, $petition->fresh()->user_id);
        $this->assertNull($signature->fresh()->org_optin);
        $this->post($url.'/'.$petition->id.'/detach', ['confirm' => 1])->assertRedirect();
        $this->assertNull($petition->fresh()->organization_id);
    }

    public function test_attribution_rejects_foreign_petitions_existing_links_and_suspended_organizations(): void
    {
        $org = $this->org();
        $foreign = $this->petition($this->org());
        $own = $this->petition($org);
        $url = '/en/org/'.$org->slug.'/manage/petitions';
        $this->actingAs($org->owner)->post($url.'/attach', ['petition_id' => $foreign->id, 'confirm' => 1])->assertForbidden();
        $this->post($url.'/attach', ['petition_id' => $own->id, 'confirm' => 1])->assertStatus(409);
        $org->update(['status' => 'suspended']);
        $this->post($url.'/'.$own->id.'/detach', ['confirm' => 1])->assertForbidden();
    }

    public function test_reassignment_grants_org_draft_management_but_keeps_personal_drafts_private(): void
    {
        $org = $this->org();
        $draft = $this->petition($org, ['status' => 'draft']);
        $personal = $this->petition($org, ['status' => 'draft', 'organization_id' => null]);
        $personal->translations()->update(['title' => 'Unshared personal draft']);
        $newOwner = $this->owner();
        $org->update(['owner_user_id' => $newOwner->id]);
        $url = '/en/org/'.$org->slug.'/manage/petitions';
        $this->actingAs($newOwner)->get($url)->assertOk()->assertSee($draft->translations->first()->title)->assertDontSee('Unshared personal draft');
        $this->get('/en/petition/protect-park-'.$personal->id.'/'.$personal->id.'/edit')->assertForbidden();
        $this->post($url.'/'.$draft->id.'/detach', ['confirm' => 1])->assertRedirect();
        $this->assertNull($draft->fresh()->organization_id);
        $this->assertSame($personal->user_id, $draft->fresh()->user_id);
    }

    private function recordConsent(Petition $petition, bool $accepted, ?string $proof = null): array
    {
        $presentation = OrganizationConsent::presentation($petition->fresh(), 'en');
        $request = Request::create('/', 'POST', ['organization_optin' => $accepted ? '1' : '0', 'organization_consent_proof' => $proof ?? $presentation['proof']]);
        $signature = Signature::factory()->create(['petition_id' => $petition->id, 'confirmed' => true]);
        OrganizationConsent::record($signature, $request, $petition, 'en');

        return [$signature->fresh(), $request->attributes->get('organization_consent_links', [])];
    }

    public function test_consent_preserves_null_false_true_and_hashes_opaque_tokens(): void
    {
        $petition = $this->petition($this->org());
        $historical = Signature::factory()->create(['petition_id' => $petition->id]);
        [$unchecked, $links] = $this->recordConsent($petition, false);
        $this->assertFalse($unchecked->org_optin);
        $this->assertSame('2026-11', $unchecked->org_optin_text_version);
        $this->assertNull($unchecked->org_optin_at);
        $this->assertNull($unchecked->org_optin_revoke_token);
        $this->assertSame([], $links);
        [$accepted, $links] = $this->recordConsent($petition, true);
        $this->assertTrue($accepted->org_optin);
        $this->assertNotNull($accepted->org_optin_at);
        preg_match('~/organization-consent/([a-f0-9]{64})/revoke~', $links[0]['url'], $match);
        $this->assertSame(hash('sha256', $match[1]), $accepted->org_optin_revoke_token);
        $this->assertArrayNotHasKey('org_optin_revoke_token', $accepted->toArray());
        $this->assertNull($historical->fresh()->org_optin);
        [$other] = $this->recordConsent($petition, true);
        $this->assertNotSame($accepted->org_optin_revoke_token, $other->org_optin_revoke_token);
    }

    public function test_stale_tampered_and_unavailable_presentations_store_no_consent(): void
    {
        $org = $this->org();
        $petition = $this->petition($org);
        $proof = OrganizationConsent::presentation($petition, 'en')['proof'];
        [$tampered] = $this->recordConsent($petition, true, $proof.'x');
        $this->assertNull($tampered->org_optin);
        $org->update(['status' => 'suspended']);
        [$suspended] = $this->recordConsent($petition, true, $proof);
        $this->assertNull($suspended->org_optin);
        $org->update(['status' => 'verified']);
        $petition->organization_id = $this->org()->id;
        $petition->save();
        [$relinked] = $this->recordConsent($petition, true, $proof);
        $this->assertNull($relinked->org_optin);
    }

    public function test_signed_revocation_get_is_safe_post_is_idempotent_and_preserves_historical_recipient(): void
    {
        $org = $this->org();
        $petition = $this->petition($org);
        [$signature, $links] = $this->recordConsent($petition, true);
        $url = $links[0]['url'];
        $petition->update(['organization_id' => $this->org()->id]);
        $this->get($url)->assertOk()->assertDontSee($signature->email)->assertDontSee($signature->name)->assertDontSee('googletagmanager')->assertDontSee('adsterra');
        $this->assertNull($signature->fresh()->org_optin_revoked_at);
        $this->post($url)->assertOk()->assertSee('withdrawn');
        $revoked = $signature->fresh()->org_optin_revoked_at;
        $this->travel(1)->days();
        $this->post($url)->assertOk();
        $this->assertTrue($revoked->equalTo($signature->fresh()->org_optin_revoked_at));
        $this->assertSame($org->id, $signature->fresh()->org_optin_organization_id);
        $this->assertTrue($signature->fresh()->confirmed);
        $this->get(str_replace('signature=', 'signature=x', $url))->assertForbidden();
        $this->get(URL::signedRoute('organizations.consent.revoke', ['locale' => 'en', 'token' => str_repeat('a', 64)]))->assertNotFound();
    }

    public function test_only_confirmed_nonspam_unrevoked_historical_consents_appear_in_private_counts(): void
    {
        $org = $this->org();
        $petition = $this->petition($org);
        [$valid] = $this->recordConsent($petition, true);
        [$old] = $this->recordConsent($petition, true);
        $old->forceFill(['org_optin_at' => now()->subDays(31)])->save();
        [$spam] = $this->recordConsent($petition, true);
        $spam->forceFill(['is_spam' => true])->save();
        [$pending] = $this->recordConsent($petition, true);
        $pending->forceFill(['confirmed' => false])->save();
        [$revoked] = $this->recordConsent($petition, true);
        $revoked->forceFill(['org_optin_revoked_at' => now()])->save();
        $petition->update(['organization_id' => null]);
        $this->actingAs($org->owner)->get('/en/org/'.$org->slug.'/manage')->assertOk()->assertViewHas('consentTotal', 2)->assertViewHas('consentRecent', 1);
        $this->actingAs($this->owner())->get('/en/org/'.$org->slug.'/manage')->assertForbidden();
    }

    private function createPayload(array $extra = []): array
    {
        $category = Category::factory()->create(['is_active' => true]);

        return array_replace(['title' => 'Protect our local green park', 'description' => 'A sufficiently detailed campaign description asking our community to protect this local green park.', 'goal_signatures' => 100, 'category_id' => $category->id], $extra);
    }

    public function test_publish_as_defaults_to_latest_but_explicit_myself_and_no_selector_stay_personal(): void
    {
        $owner = $this->owner();
        $first = $this->org($owner, ['verified_at' => now()->subDay()]);
        $latest = $this->org($owner);
        $response = $this->actingAs($owner)->get('/en/create-petition')->assertOk();
        $this->assertMatchesRegularExpression('/value="'.$latest->id.'"\s+selected/', $response->getContent());
        $this->post('/en/create-petition', $this->createPayload(['organization_id' => '', 'causes' => [Cause::first()->id]]))->assertRedirect()->assertSessionHasNoErrors();
        $personal = Petition::latest('id')->first();
        $this->assertNull($personal->organization_id);
        $this->assertCount(1, $personal->causes);
        $this->post('/en/create-petition', $this->createPayload(['organization_id' => $first->id]))->assertSessionHasNoErrors();
        $this->assertSame($first->id, Petition::latest('id')->first()->organization_id);
        $this->post('/en/create-petition', $this->createPayload())->assertSessionHasNoErrors();
        $this->assertNull(Petition::latest('id')->first()->organization_id);
    }

    public function test_forged_publication_attribution_and_invalid_cause_selection_are_rejected(): void
    {
        $owner = $this->owner();
        $foreign = $this->org();
        $suspended = $this->org($owner, ['status' => 'suspended']);
        $this->actingAs($owner)->post('/en/create-petition', $this->createPayload(['organization_id' => $foreign->id]))->assertSessionHasErrors('organization_id');
        $this->post('/en/create-petition', $this->createPayload(['organization_id' => $suspended->id]))->assertSessionHasErrors('organization_id');
        $this->post('/en/create-petition', $this->createPayload(['causes' => [1, 2, 3, 4]]))->assertSessionHasErrors('causes');
        $this->post('/en/create-petition', $this->createPayload(['causes' => [1, 1]]))->assertSessionHasErrors('causes.0');
        $this->post('/en/create-petition', $this->createPayload(['causes' => [99999]]))->assertSessionHasErrors('causes.0');
    }

    public function test_draft_edits_keep_attribution_and_edit_permissions_personal(): void
    {
        $org = $this->org();
        $draft = $this->petition($org, ['status' => 'draft']);
        $url = '/en/petition/protect-park-'.$draft->id.'/'.$draft->id.'/edit';
        $payload = $this->createPayload();
        $this->actingAs($org->owner)->post($url, $payload)->assertSessionHasNoErrors();
        $this->assertSame($org->id, $draft->fresh()->organization_id);
        $payload['organization_id'] = $this->org($org->owner)->id;
        $this->post($url, $payload)->assertSessionHasErrors('organization_id');
    }

    public function test_authenticated_signing_carries_explicit_choice_to_confirmation_and_stores_consent(): void
    {
        $petition = $this->petition($this->org());
        $signer = $this->owner();
        $proof = OrganizationConsent::presentation($petition, 'en')['proof'];
        $data = ['agree1' => 'agree', 'agree2' => 'agree', 'agree3' => 'agree', 'organization_optin' => 1, 'organization_consent_proof' => $proof];
        $base = '/en/petition/protect-park-'.$petition->id.'/'.$petition->id;
        $response = $this->actingAs($signer)->post($base.'/sign/confirm', $data)->assertOk();
        $this->assertMatchesRegularExpression('/name="organization_optin" value="1"\s+checked/', $response->getContent());
        $this->post($base.'/sign', $data)->assertRedirect()->assertSessionHas('organization_consent_links');
        $signature = Signature::where('user_id', $signer->id)->firstOrFail();
        $this->assertTrue($signature->org_optin);
        $this->assertSame($petition->organization_id, $signature->org_optin_organization_id);
        $this->assertTrue($signature->confirmed);
    }

    public function test_guest_verification_mail_includes_revocation_and_pending_consent_is_not_usable(): void
    {
        Mail::fake();
        Settings::set('smtp_enabled', true, 'global');
        Settings::set('require_email_verification', true, 'global');
        $petition = $this->petition($this->org());
        $proof = OrganizationConsent::presentation($petition, 'en')['proof'];
        $this->post('/en/petition/protect-park-'.$petition->id.'/'.$petition->id.'/sign', [
            'name' => 'Consent Test', 'email' => 'consent-test@example.org', 'agree1' => 1, 'agree2' => 1, 'agree3' => 1,
            'organization_optin' => 1, 'organization_consent_proof' => $proof,
        ])->assertRedirect()->assertSessionHasNoErrors();
        $signature = Signature::where('email', 'consent-test@example.org')->firstOrFail();
        $this->assertTrue($signature->org_optin);
        $this->assertFalse($signature->confirmed);
        Mail::assertQueued(VerifyAccountMail::class, function ($mail) {
            $this->assertCount(1, $mail->organizationConsentLinks);
            $this->assertStringContainsString('/organization-consent/', $mail->render());

            return true;
        });
        PetitionSigning::markVerified($signature->user);
        $this->assertTrue($signature->fresh()->confirmed);
    }

    public function test_presentation_starts_unchecked_and_expired_or_disabled_consent_does_not_block_signing(): void
    {
        $petition = $this->petition($this->org());
        $presentation = OrganizationConsent::presentation($petition, 'de');
        $this->assertFalse($presentation['selected']);
        $english = OrganizationConsent::presentation($petition, 'en');
        $this->travel(3)->hours();
        [$expired] = $this->recordConsent($petition, true, $english['proof']);
        $this->assertNull($expired->org_optin);
        config(['organizations.consent_enabled' => false]);
        $this->assertNull(OrganizationConsent::presentation($petition, 'en'));
        $signer = $this->owner();
        $this->actingAs($signer)->post('/en/petition/protect-park-'.$petition->id.'/'.$petition->id.'/sign', [
            'agree1' => 'agree', 'agree2' => 'agree', 'agree3' => 'agree', 'organization_optin' => 1, 'organization_consent_proof' => $english['proof'],
        ])->assertRedirect();
        $this->assertNull(Signature::where('user_id', $signer->id)->firstOrFail()->org_optin);
    }

    public function test_campaign_edit_link_respects_existing_external_signature_lock(): void
    {
        $org = $this->org();
        $petition = $this->petition($org);
        $url = '/en/org/'.$org->slug.'/manage/petitions';
        $edit = '/en/petition/protect-park-'.$petition->id.'/'.$petition->id.'/edit';
        $this->actingAs($org->owner)->get($url)->assertOk()->assertSee($edit, false);
        Signature::factory()->create(['petition_id' => $petition->id, 'confirmed' => true, 'is_spam' => false]);
        $this->get($url)->assertOk()->assertDontSee($edit, false);
        $this->get($edit)->assertForbidden();
    }

    public function test_public_attribution_and_causes_hide_unverified_organization_without_hiding_petition(): void
    {
        $org = $this->org();
        $petition = $this->petition($org);
        $petition->causes()->attach(Cause::where('slug', 'environment')->firstOrFail());
        $url = '/en/petition/protect-park-'.$petition->id.'/'.$petition->id;
        $this->get($url)->assertOk()->assertSee('Launched by')->assertSee('/en/org/'.$org->slug, false)->assertSee('organizations/explore?cause=environment', false);
        $org->update(['status' => 'suspended']);
        $this->get($url)->assertOk()->assertDontSee('Launched by')->assertDontSee('/en/org/'.$org->slug, false);
        $this->assertSame('published', $petition->fresh()->status);
    }

    public function test_a_changed_recipient_or_locale_cannot_inherit_the_previous_checkbox_choice(): void
    {
        $org = $this->org();
        $petition = $this->petition($org);
        $proof = OrganizationConsent::presentation($petition, 'en')['proof'];
        $previous = ['organization_optin' => 1, 'organization_consent_proof' => $proof];
        $this->assertTrue(OrganizationConsent::presentation($petition, 'en', $previous)['selected']);
        $this->assertFalse(OrganizationConsent::presentation($petition, 'it', $previous)['selected']);
        $petition->organization_id = $this->org()->id;
        $petition->save();
        $this->assertFalse(OrganizationConsent::presentation($petition->fresh(), 'en', $previous)['selected']);
        $request = Request::create('/', 'POST', ['organization_optin' => 1, 'organization_consent_proof' => $proof]);
        $signature = Signature::factory()->create(['petition_id' => $petition->id]);
        OrganizationConsent::record($signature, $request, $petition, 'it');
        $this->assertNull($signature->fresh()->org_optin);
    }
}
