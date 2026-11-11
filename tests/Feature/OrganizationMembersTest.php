<?php

namespace Tests\Feature;

use App\Jobs\SendOrganizationInvitation;
use App\Mail\OrganizationInvitationMail;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\Petition;
use App\Models\Signature;
use App\Models\User;
use App\Models\UserLevel;
use App\Policies\PetitionPolicy;
use App\Services\OrganizationLifecycle;
use App\Services\OrganizationMemberships;
use App\Support\Locale;
use App\Support\MailText;
use App\Support\Settings;
use Database\Seeders\LanguageSeeder;
use Database\Seeders\OrganizationCauseSeeder;
use Database\Seeders\UserLevelSeeder;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class OrganizationMembersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed([UserLevelSeeder::class, LanguageSeeder::class, OrganizationCauseSeeder::class]);
        Queue::fake();
        config(['organizations.public_launch' => true]);
    }

    private function user(array $data = []): User
    {
        return User::factory()->create(array_replace(['verified' => true, 'locale' => 'en', 'gender' => 'none'], $data));
    }

    private function organization(?User $owner = null, array $data = []): Organization
    {
        return Organization::create(array_replace([
            'owner_user_id' => ($owner ?? $this->user())->id, 'name' => 'Private Test Organization',
            'slug' => 'member-test-'.bin2hex(random_bytes(5)), 'status' => 'verified', 'country' => 'US', 'type' => 'committee',
            'verified_at' => now(), 'verification_reference' => 'Private evidence', 'terms_version' => 'previous-version',
            'terms_accepted_at' => now(), 'representation_confirmed_at' => now(),
        ], $data));
    }

    private function member(Organization $org, string $role): User
    {
        $user = $this->user();
        $org->members()->create(['user_id' => $user->id, 'role' => $role, 'joined_at' => now()]);

        return $user;
    }

    private function petition(User $author, ?Organization $org = null): Petition
    {
        $petition = Petition::factory()->create(['user_id' => $author->id, 'organization_id' => $org?->id, 'status' => 'published', 'is_active' => true, 'signature_count' => 0, 'cover_image' => null]);
        $petition->translations()->create(['locale' => 'en', 'title' => 'Protect our local public park', 'slug' => 'member-petition-'.$petition->id, 'description' => 'A meaningful description about protecting our local public park.']);

        return $petition;
    }

    private function membersUrl(Organization $org, string $suffix = ''): string
    {
        return '/en/org/'.$org->slug.'/manage/members'.$suffix;
    }

    private function invite(Organization $org, User $owner, string $email = 'new-member@example.test', string $role = 'editor'): array
    {
        return app(OrganizationMemberships::class)->invite($org, $owner, $email, $role);
    }

    public function test_owner_mirror_is_created_and_database_rejects_a_second_owner(): void
    {
        $org = $this->organization();
        $this->assertSame('owner', $org->roleFor($org->owner));
        $this->assertSame(1, $org->members()->where('role', 'owner')->count());
        $other = $this->user();
        $this->expectException(QueryException::class);
        DB::table('organization_members')->insert(['organization_id' => $org->id, 'user_id' => $other->id, 'role' => 'owner', 'joined_at' => now()]);
    }

    public function test_role_matrix_and_private_roster_on_actual_routes(): void
    {
        $owner = $this->user();
        $org = $this->organization($owner);
        $admin = $this->member($org, 'admin');
        $editor = $this->member($org, 'editor');
        $stranger = $this->user();
        foreach ([$owner, $admin, $editor] as $user) {
            $this->actingAs($user)->get('/en/org/'.$org->slug.'/manage')->assertOk()->assertSee('Members and roles');
        }
        $this->actingAs($admin)->get($this->membersUrl($org))->assertOk()->assertSee($owner->email)->assertSee($editor->email);
        $this->actingAs($editor)->get($this->membersUrl($org))->assertOk()->assertDontSee($owner->email)->assertDontSee($admin->email)->assertDontSee('Pending invitations')->assertDontSee('Recent member activity');
        $this->get('/en/org/'.$org->slug.'/manage/profile')->assertForbidden();
        $this->actingAs($admin)->get('/en/org/'.$org->slug.'/manage/profile')->assertOk();
        $this->actingAs($stranger)->get($this->membersUrl($org))->assertForbidden();
        $this->get('/en/org/'.$org->slug)->assertOk()->assertDontSee($admin->email)->assertDontSee($editor->email);
    }

    public function test_personal_petitions_stay_private_and_org_admin_edit_access_disappears_on_detach(): void
    {
        $org = $this->organization();
        $admin = $this->member($org, 'admin');
        $editor = $this->member($org, 'editor');
        $personal = $this->petition($editor);
        $attributed = $this->petition($editor, $org);
        $this->actingAs($admin)->get('/en/petition/member-petition-'.$personal->id.'/'.$personal->id.'/edit')->assertForbidden();
        $this->get('/en/petition/member-petition-'.$attributed->id.'/'.$attributed->id.'/edit')->assertOk();
        $this->post('/en/org/'.$org->slug.'/manage/petitions/'.$attributed->id.'/detach', ['confirm' => 1])->assertRedirect();
        $this->assertNull($attributed->fresh()->organization_id);
        $this->get('/en/petition/member-petition-'.$attributed->id.'/'.$attributed->id.'/edit')->assertForbidden();
    }

    public function test_editor_cannot_manage_others_org_petitions_or_personal_petitions(): void
    {
        $org = $this->organization();
        $editor = $this->member($org, 'editor');
        $other = $this->member($org, 'editor');
        $own = $this->petition($editor, $org);
        $others = $this->petition($other, $org);
        $personal = $this->petition($other);
        $policy = app(PetitionPolicy::class);
        $this->assertTrue($policy->update($editor, $own));
        $this->assertFalse($policy->update($editor, $others));
        $this->assertFalse($policy->update($editor, $personal));
        $this->actingAs($editor)->post('/en/org/'.$org->slug.'/manage/petitions/'.$others->id.'/detach', ['confirm' => 1])->assertForbidden();
        $this->post($this->membersUrl($org, '/invite'), ['email' => 'forged@example.test', 'role' => 'admin'])->assertForbidden();
        $this->post('/en/org/'.$org->slug.'/manage/resubmit')->assertForbidden();
        $this->post('/en/org/'.$org->slug.'/manage/deletion-request', ['confirm' => 1])->assertForbidden();
    }

    public function test_invitation_hash_single_use_and_email_mismatch_confirmation(): void
    {
        $owner = $this->user();
        $org = $this->organization($owner);
        $acceptor = $this->user();
        [$invitation, $link] = $this->invite($org, $owner, '  Invited@Example.Test  ', 'admin');
        $this->assertSame('invited@example.test', $invitation->email);
        $this->assertSame(64, strlen($invitation->token_hash));
        $this->assertStringNotContainsString($invitation->token_hash, $link);
        $this->actingAs($acceptor)->get($link)->assertOk()->assertSee('invited@example.test')->assertSee('confirm_email')->assertDontSee('googletagmanager')->assertDontSee('data-category="analytics"', false);
        $this->post($link, ['confirm' => 1])->assertSessionHasErrors('confirm_email');
        $this->assertNull($org->roleFor($acceptor));
        $this->post($link, ['confirm' => 1, 'confirm_email' => 1])->assertRedirect('/en/org/'.$org->slug.'/manage');
        $this->assertSame('admin', $org->roleFor($acceptor));
        $this->assertNotNull($invitation->fresh()->accepted_at);
        $this->post($link, ['confirm' => 1, 'confirm_email' => 1])->assertStatus(410);
        $this->assertSame(1, $org->members()->where('user_id', $acceptor->id)->count());
    }

    public function test_expired_revoked_and_suspended_invitations_cannot_be_accepted(): void
    {
        foreach (['expired', 'revoked', 'suspended'] as $state) {
            $owner = $this->user();
            $org = $this->organization($owner);
            $user = $this->user();
            [$invite, $link] = $this->invite($org, $owner, $user->email);
            match ($state) {
                'expired' => $invite->update(['expires_at' => now()->subMinute()]),
                'revoked' => $invite->update(['revoked_at' => now()]),
                'suspended' => $org->update(['status' => 'suspended']),
            };
            $this->actingAs($user)->get($link)->assertStatus(410)->assertSee('Ask the organization for a new invitation.');
            $this->post($link, ['confirm' => 1])->assertStatus(410);
            $this->assertNull($org->roleFor($user));
        }
    }

    public function test_anonymous_login_and_registration_keep_the_invitation_return_url(): void
    {
        $owner = $this->user();
        $org = $this->organization($owner);
        [, $link] = $this->invite($org, $owner);
        $this->get($link)->assertRedirect('/en/login')->assertSessionHas('url.intended', $link);
        $this->get('/en/login')->assertOk()->assertSee('name="redirect"', false)->assertDontSee('googletagmanager');
        $this->get('/en/register')->assertOk()->assertSee('name="redirect"', false)->assertDontSee('googletagmanager');
    }

    public function test_incomplete_profile_preserves_the_signed_return_url(): void
    {
        $owner = $this->user();
        $org = $this->organization($owner);
        $user = $this->user(['country' => null]);
        [, $link] = $this->invite($org, $owner, $user->email);
        $response = $this->actingAs($user)->get($link)->assertRedirect();
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
        $return = $query['return'] ?? '';
        $this->assertStringContainsString('signature=', $return);
        $this->assertStringContainsString('/organization-invitations/', $return);
    }

    public function test_invitation_rate_limit_is_per_organization_includes_resends_and_uses_one_active_email_slot(): void
    {
        $owner = $this->user();
        $org = $this->organization($owner);
        for ($i = 0; $i < 20; $i++) {
            [$invite] = $this->invite($org, $owner, 'same@example.test');
        }
        $this->assertSame(1, $org->invitations()->whereNull('revoked_at')->whereNull('accepted_at')->count());
        $this->assertSame(20, $org->invitations()->count());
        $this->actingAs($owner)->post($this->membersUrl($org, '/invitations/'.$invite->id.'/resend'))->assertSessionHasErrors('email');
        $other = $this->organization($owner);
        $this->post($this->membersUrl($other, '/invite'), ['email' => 'new@example.test', 'role' => 'editor'])->assertSessionHasNoErrors();
    }

    public function test_database_rejects_a_second_active_invitation_for_the_same_normalized_email(): void
    {
        $owner = $this->user();
        $org = $this->organization($owner);
        [$invite] = $this->invite($org, $owner);
        $this->expectException(QueryException::class);
        OrganizationInvitation::create(['organization_id' => $org->id, 'email' => $invite->email, 'role' => 'editor', 'token_hash' => hash('sha256', 'another token'), 'expires_at' => now()->addDay(), 'invited_by_user_id' => $owner->id]);
    }

    public function test_admin_cannot_demote_remove_or_transfer_owner_and_owner_cannot_leave(): void
    {
        $owner = $this->user();
        $org = $this->organization($owner);
        $admin = $this->member($org, 'admin');
        $ownerRow = $org->members()->where('user_id', $owner->id)->firstOrFail();
        $adminRow = $org->members()->where('user_id', $admin->id)->firstOrFail();
        $this->actingAs($admin)->post($this->membersUrl($org, '/'.$ownerRow->id.'/role'), ['role' => 'editor'])->assertForbidden();
        $this->post($this->membersUrl($org, '/'.$ownerRow->id.'/remove'), ['confirm' => 1])->assertForbidden();
        $this->post($this->membersUrl($org, '/transfer'), ['confirm' => 1, 'member_id' => $adminRow->id])->assertForbidden();
        $this->actingAs($owner)->post($this->membersUrl($org, '/leave'), ['confirm' => 1])->assertForbidden();
        $this->assertSame('owner', $org->roleFor($owner));
    }

    public function test_owner_transfer_is_atomic_updates_mirror_and_audit_and_old_owner_becomes_admin(): void
    {
        $owner = $this->user();
        $org = $this->organization($owner);
        $admin = $this->member($org, 'admin');
        $row = $org->members()->where('user_id', $admin->id)->firstOrFail();
        $this->actingAs($owner)->post($this->membersUrl($org, '/transfer'), ['member_id' => $row->id])->assertSessionHasErrors('confirm');
        $this->post($this->membersUrl($org, '/transfer'), ['member_id' => $row->id, 'confirm' => 1])->assertRedirect();
        $this->assertSame($admin->id, $org->fresh()->owner_user_id);
        $this->assertSame('owner', $org->roleFor($admin));
        $this->assertSame('admin', $org->roleFor($owner));
        $this->assertSame(1, $org->members()->where('role', 'owner')->count());
        $this->assertSame(1, $org->events()->where('event', 'ownership_transferred')->count());
    }

    public function test_failed_transfer_rolls_back_both_roles_and_mirror(): void
    {
        $owner = $this->user();
        $org = $this->organization($owner);
        $admin = $this->member($org, 'admin');
        DB::statement("CREATE TRIGGER fail_member_transfer BEFORE INSERT ON organization_events WHEN NEW.event = 'ownership_transferred' BEGIN SELECT RAISE(ABORT, 'Test rollback'); END");
        try {
            app(OrganizationMemberships::class)->transfer($org, $owner, $org->members()->where('user_id', $admin->id)->value('id'));
            $this->fail('Transfer must fail when its audit cannot be written.');
        } catch (QueryException) {
            $this->assertSame($owner->id, $org->fresh()->owner_user_id);
            $this->assertSame('owner', $org->roleFor($owner));
            $this->assertSame('admin', $org->roleFor($admin));
        }
    }

    public function test_leave_and_removal_do_not_change_petitions_and_revoke_membership_edit_rights(): void
    {
        $owner = $this->user();
        $org = $this->organization($owner);
        $editor = $this->member($org, 'editor');
        $petition = $this->petition($editor, $org);
        $before = $petition->fresh()->getAttributes();
        $this->actingAs($editor)->post($this->membersUrl($org, '/leave'), ['confirm' => 1])->assertRedirect('/en/organizations');
        $this->assertSame($before, $petition->fresh()->getAttributes());
        $this->assertFalse(app(PetitionPolicy::class)->update($editor, $petition->fresh()));
        $admin = $this->member($org, 'admin');
        $row = $org->members()->where('user_id', $admin->id)->firstOrFail();
        $this->actingAs($owner)->post($this->membersUrl($org, '/'.$row->id.'/remove'), ['confirm' => 1])->assertRedirect();
        $this->assertNull($org->roleFor($admin));
    }

    public function test_platform_reassignment_keeps_legacy_revocation_and_synchronizes_member_rows(): void
    {
        $owner = $this->user();
        $org = $this->organization($owner);
        $new = $this->user();
        $platform = $this->user(['level_id' => UserLevel::where('name', 'admin')->value('id')]);
        $this->actingAs($platform, 'admin')->post('/admin/organizations/'.$org->id.'/reassign', ['confirm' => 1, 'owner_user_id' => $new->id])->assertSessionHasNoErrors();
        $this->assertSame('owner', $org->roleFor($new));
        $this->assertNull($org->roleFor($owner));
        $this->assertSame(1, $org->members()->where('role', 'owner')->count());
    }

    public function test_deleted_owner_suspends_preserves_admins_and_does_not_invent_a_new_owner(): void
    {
        $owner = $this->user();
        $org = $this->organization($owner);
        $admin = $this->member($org, 'admin');
        DB::transaction(fn () => app(OrganizationLifecycle::class)->suspendForDeletedOwner($owner));
        $this->assertNull($org->fresh()->owner_user_id);
        $this->assertSame('suspended', $org->fresh()->status);
        $this->assertSame(0, $org->members()->where('role', 'owner')->count());
        $this->assertSame('admin', $org->roleFor($admin));
    }

    public function test_claimed_pending_org_cannot_add_other_members_before_verification(): void
    {
        $owner = $this->user();
        $org = $this->organization($owner, ['status' => 'pending', 'claimed_at' => now(), 'claimed_by_user_id' => $owner->id]);
        $this->actingAs($owner)->post($this->membersUrl($org, '/invite'), ['email' => 'invite@example.test', 'role' => 'editor'])->assertStatus(409);
        $this->assertSame(1, $org->members()->count());
    }

    public function test_publish_as_uses_all_verified_memberships_and_rejects_a_forged_id(): void
    {
        $user = $this->user();
        $allowed = $this->organization();
        $other = $this->organization();
        $allowed->members()->create(['user_id' => $user->id, 'role' => 'editor', 'joined_at' => now()]);
        $this->actingAs($user)->get('/en/create-petition')->assertOk()->assertSee($allowed->name);
        $this->post('/en/create-petition', ['organization_id' => $other->id])->assertSessionHasErrors('organization_id');
        $allowed->update(['status' => 'suspended']);
        $html = $this->get('/en/create-petition')->assertOk()->getContent();
        $document = new \DOMDocument;
        @$document->loadHTML($html);
        $this->assertSame(0, (new \DOMXPath($document))->query('//select[@name="organization_id"]/option[@value="'.$allowed->id.'"]')->length);
    }

    public function test_existing_member_acceptance_never_silently_changes_their_role(): void
    {
        $owner = $this->user();
        $org = $this->organization($owner);
        $user = $this->user();
        [$invite, $link] = $this->invite($org, $owner, $user->email, 'admin');
        $org->members()->create(['user_id' => $user->id, 'role' => 'editor', 'joined_at' => now()]);
        $this->actingAs($user)->post($link, ['confirm' => 1])->assertRedirect();
        $this->assertSame('editor', $org->roleFor($user));
    }

    public function test_invitation_email_uses_recipient_language_admin_defaults_and_skips_revoked_tokens(): void
    {
        Mail::fake();
        config(['organizations.notifications_enabled' => true]);
        Settings::set('smtp_enabled', true);
        $owner = $this->user();
        $org = $this->organization($owner);
        $user = $this->user(['locale' => 'it']);
        [$invite, $link] = $this->invite($org, $owner, $user->email);
        $job = new SendOrganizationInvitation($invite->id, $link);
        $job->handle();
        $job->handle();
        Mail::assertSent(OrganizationInvitationMail::class, fn ($mail) => $mail->emailLocale === 'it' && $mail->hasTo($user->email));
        Mail::assertSentCount(1);
        $this->assertNotEmpty(MailText::defaults('it')['organization_members_subject']);
        [$revoked, $revokedLink] = $this->invite($org, $owner, 'revoked@example.test');
        $revoked->update(['revoked_at' => now()]);
        (new SendOrganizationInvitation($revoked->id, $revokedLink))->handle();
        Mail::assertSentCount(1);
    }

    public function test_platform_admin_can_manage_members_without_a_web_login(): void
    {
        $owner = $this->user();
        $org = $this->organization($owner);
        $editor = $this->member($org, 'editor');
        $platform = $this->user(['level_id' => UserLevel::where('name', 'admin')->value('id')]);
        $url = '/admin/organizations/'.$org->slug.'/members';
        $row = $org->members()->where('user_id', $editor->id)->firstOrFail();
        $this->actingAs($platform, 'admin')->get($url)->assertOk()->assertSee($editor->email);
        $this->post($url.'/'.$row->id.'/role', ['role' => 'admin'])->assertRedirect();
        $this->assertSame('admin', $org->roleFor($editor));
        $this->post($url.'/invite', ['email' => 'staff-invited@example.test', 'role' => 'editor'])->assertSessionHasNoErrors()->assertSessionHas('invitation_link');
        $this->post($url.'/transfer', ['member_id' => $row->id, 'confirm' => 1])->assertRedirect();
        $this->assertSame($editor->id, $org->fresh()->owner_user_id);
        $this->assertSame('admin', $org->roleFor($owner));
        $this->assertNull($org->roleFor($platform));
    }

    public function test_cross_organization_member_ids_and_invitation_ids_are_rejected(): void
    {
        $owner = $this->user();
        $org = $this->organization($owner);
        $foreign = $this->organization();
        $user = $this->member($foreign, 'admin');
        $row = $foreign->members()->where('user_id', $user->id)->firstOrFail();
        [$invite] = $this->invite($foreign, $foreign->owner);
        $this->actingAs($owner)->post($this->membersUrl($org, '/'.$row->id.'/role'), ['role' => 'editor'])->assertNotFound();
        $this->post($this->membersUrl($org, '/'.$row->id.'/remove'), ['confirm' => 1])->assertNotFound();
        $this->post($this->membersUrl($org, '/invitations/'.$invite->id.'/revoke'))->assertNotFound();
        $this->assertSame('admin', $foreign->roleFor($user));
    }

    public function test_pending_application_acceptance_and_resend_invalidates_old_link(): void
    {
        $owner = $this->user();
        $org = $this->organization($owner, ['status' => 'pending', 'verified_at' => null]);
        $user = $this->user();
        [$old, $oldLink] = $this->invite($org, $owner, $user->email);
        [, $newLink] = app(OrganizationMemberships::class)->invite($org, $owner, $user->email, 'editor', $old->id);
        $this->assertNotNull($old->fresh()->revoked_at);
        $this->actingAs($user)->get($oldLink)->assertStatus(410);
        $this->post($newLink, ['confirm' => 1])->assertRedirect();
        $this->assertSame('editor', $org->roleFor($user));
    }

    public function test_membership_never_grants_signature_exports_or_bypasses_signature_integrity(): void
    {
        $org = $this->organization();
        $admin = $this->member($org, 'admin');
        $author = $this->member($org, 'editor');
        $petition = $this->petition($author, $org);
        $this->actingAs($admin);
        foreach (['txt', 'csv', 'pdf'] as $format) {
            $this->get(lroute('petition.download.'.$format, ['slug' => 'member-petition-'.$petition->id, 'id' => $petition->id]))->assertForbidden();
        }
        Signature::factory()->create(['petition_id' => $petition->id, 'user_id' => $this->user()->id, 'confirmed' => true, 'is_spam' => false]);
        $this->get('/en/petition/member-petition-'.$petition->id.'/'.$petition->id.'/edit')->assertForbidden();
    }

    public function test_org_admin_can_view_org_draft_but_not_a_personal_draft(): void
    {
        $org = $this->organization();
        $admin = $this->member($org, 'admin');
        $author = $this->member($org, 'editor');
        $attributed = $this->petition($author, $org);
        $attributed->forceFill(['status' => 'draft'])->save();
        $personal = $this->petition($author);
        $personal->forceFill(['status' => 'draft'])->save();
        $this->actingAs($admin)->get('/en/petition/member-petition-'.$attributed->id.'/'.$attributed->id)->assertOk();
        $this->get('/en/petition/member-petition-'.$personal->id.'/'.$personal->id)->assertNotFound();
    }

    public function test_expired_signature_and_forged_owner_invitation_are_rejected(): void
    {
        $owner = $this->user();
        $org = $this->organization($owner);
        $user = $this->user();
        $this->actingAs($owner)->post($this->membersUrl($org, '/invite'), ['email' => $user->email, 'role' => 'owner'])->assertSessionHasErrors('role');
        [, $link] = $this->invite($org, $owner, $user->email);
        $this->travel(8)->days();
        $this->actingAs($user)->get($link)->assertStatus(410);
        $this->assertNull($org->roleFor($user));
        $this->travelBack();
    }

    public function test_mail_templates_and_members_clause_exist_in_every_supported_language(): void
    {
        $legal = json_decode(file_get_contents(resource_path('legal/organizations/2026-11-11-members.json')), true, 512, JSON_THROW_ON_ERROR);
        foreach (Locale::supported() as $locale) {
            $defaults = MailText::defaults($locale);
            $this->assertNotEmpty($defaults['organization_members_subject']);
            $this->assertStringContainsString(':name', $defaults['organization_members_body']);
            $this->assertStringContainsString(':role', $defaults['organization_members_body']);
            $this->assertStringContainsString('14.', $legal['documents'][$locale]);
        }
        $this->assertInstanceOf(ShouldBeEncrypted::class, new SendOrganizationInvitation(1, 'https://example.test/private'));
    }

    public function test_private_form_redirects_ignore_speculative_previous_url_without_a_referer(): void
    {
        $owner = $this->user();
        $org = $this->organization($owner);
        $url = $this->membersUrl($org);
        $this->actingAs($owner)->withSession(['_previous' => ['url' => '/speculationrules.json']])
            ->post($url.'/invite', ['email' => 'private-invite@example.test', 'role' => 'editor'])
            ->assertRedirect($url)->assertSessionHas('invitation_link');
        $this->withSession(['_previous' => ['url' => '/speculationrules.json']])
            ->post($url.'/invite', ['email' => 'invalid', 'role' => 'editor'])
            ->assertRedirect($url)->assertSessionHasErrors('email');
    }
}
