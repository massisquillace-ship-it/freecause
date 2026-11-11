<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\User;
use App\Policies\OrganizationPolicy;
use App\Services\OrganizationLifecycle;
use App\Services\OrganizationMemberships;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rule;

class OrganizationMembersController extends Controller
{
    public function __construct(private OrganizationMemberships $memberships) {}

    private function actor(): User
    {
        return auth(request()->routeIs('admin.*') ? 'admin' : 'web')->user();
    }

    private function membersUrl(Organization $org): string
    {
        return request()->routeIs('admin.*') ? route('admin.organizations.members', ['slug' => $org->slug]) : lroute('organizations.members', ['slug' => $org->slug]);
    }

    private function organization(string $slug): Organization
    {
        $org = Organization::where('slug', $slug)->firstOrFail();
        abort_unless(app(OrganizationPolicy::class)->view($this->actor(), $org), 403);
        if (request()->isMethod('post')) {
            // Private pages omit Referer; speculative requests may replace the session's previous URL.
            request()->session()->setPreviousUrl($this->membersUrl($org));
        }

        return $org;
    }

    public function index(string $locale, string $slug)
    {
        $org = $this->organization($slug);
        $canManage = app(OrganizationPolicy::class)->manageMembers($this->actor(), $org);

        // Editors never receive the roster, member emails, invitation emails or audit data.
        return response(view('organizations.members', [
            'org' => $org, 'canManage' => $canManage, 'actor' => $this->actor(),
            'members' => $canManage ? $org->members()->with('user:id,email,nickname,name,first_name,last_name,identify_mode')->orderBy('id')->get() : collect(),
            'invitations' => $canManage ? $org->invitations()->whereNull('accepted_at')->whereNull('revoked_at')->orderByDesc('id')->get() : collect(),
            'events' => $canManage ? $org->events()->whereIn('event', ['invitation_created', 'invitation_revoked', 'invitation_accepted', 'member_role_changed', 'member_removed', 'member_left', 'ownership_transferred', 'owner_reassigned', 'deletion_requested'])->orderByDesc('id')->limit(20)->get() : collect(),
        ]))->header('Cache-Control', 'private, no-store')->header('X-Robots-Tag', 'noindex, nofollow');
    }

    public function invite(Request $request, string $locale, string $slug)
    {
        $org = $this->organization($slug);
        $data = $request->validate(['email' => ['required', 'email:rfc', 'max:254'], 'role' => ['required', Rule::in(['admin', 'editor'])]]);
        [, $link] = $this->memberships->invite($org, $this->actor(), $data['email'], $data['role']);

        return redirect()->to($this->membersUrl($org))->with('invitation_link', $link)->with('success', __('organization_members.saved'));
    }

    public function resend(Request $request, string $locale, string $slug, int $invitation)
    {
        $org = $this->organization($slug);
        abort_unless(app(OrganizationPolicy::class)->manageMembers($this->actor(), $org), 403);
        $old = $org->invitations()->findOrFail($invitation);
        [, $link] = $this->memberships->invite($org, $this->actor(), $old->email, $old->role, $old->id);

        return redirect()->to($this->membersUrl($org))->with('invitation_link', $link)->with('success', __('organization_members.saved'));
    }

    public function revoke(Request $request, string $locale, string $slug, int $invitation)
    {
        $org = $this->organization($slug);
        $this->memberships->revoke($org, $this->actor(), $invitation);

        return redirect()->to($this->membersUrl($org))->with('success', __('organization_members.saved'));
    }

    public function role(Request $request, string $locale, string $slug, int $member)
    {
        $org = $this->organization($slug);
        $data = $request->validate(['role' => ['required', Rule::in(['admin', 'editor'])]]);
        $this->memberships->changeRole($org, $this->actor(), $member, $data['role']);

        return redirect()->to($this->membersUrl($org))->with('success', __('organization_members.saved'));
    }

    public function remove(Request $request, string $locale, string $slug, int $member)
    {
        $org = $this->organization($slug);
        $request->validate(['confirm' => ['accepted']]);
        $this->memberships->remove($org, $this->actor(), $member);
        $target = app(OrganizationPolicy::class)->view($this->actor(), $org) ? $this->membersUrl($org) : lroute('organizations');

        return redirect()->to($target)->with('success', __('organization_members.saved'));
    }

    public function transfer(Request $request, string $locale, string $slug)
    {
        $org = $this->organization($slug);
        $data = $request->validate(['member_id' => ['required', 'integer'], 'confirm' => ['accepted']]);
        $this->memberships->transfer($org, $this->actor(), (int) $data['member_id']);

        return redirect()->to($this->membersUrl($org))->with('success', __('organization_members.saved'));
    }

    public function leave(Request $request, string $locale, string $slug)
    {
        $org = $this->organization($slug);
        $request->validate(['confirm' => ['accepted']]);
        $this->memberships->leave($org, $this->actor());

        return redirect()->to(request()->routeIs('admin.*') ? route('admin.organizations') : lroute('organizations'))->with('success', __('organization_members.saved'));
    }

    public function deletion(Request $request, string $locale, string $slug)
    {
        $org = $this->organization($slug);
        $request->validate(['confirm' => ['accepted']]);
        DB::transaction(function () use ($slug) {
            $org = Organization::where('slug', $slug)->lockForUpdate()->firstOrFail();
            abort_unless(app(OrganizationPolicy::class)->requestDeletion($this->actor(), $org), 403);
            app(OrganizationLifecycle::class)->record($org, $this->actor()->id, 'deletion_requested');
        }, 3);

        return redirect()->to($this->membersUrl($org))->with('success', __('organization_members.deletion_saved'));
    }

    private function invitation(Request $request, string $token): ?OrganizationInvitation
    {
        if (! preg_match('/^[a-f0-9]{64}$/D', $token) || ! URL::hasValidSignature($request)) {
            return null;
        }
        $invitation = OrganizationInvitation::with('organization')->where('token_hash', hash('sha256', $token))->first();
        $org = $invitation?->organization;

        return $invitation?->usable() && $org && in_array($org->status, ['verified', 'pending'], true)
            && (! $org->claimed_at || $org->status === 'verified') ? $invitation : null;
    }

    public function showInvitation(Request $request, string $locale, string $token)
    {
        $request->session()->put('organization_invitation_flow', true);
        if (! auth('web')->check()) {
            $request->session()->put('url.intended', $request->fullUrl());

            return redirect()->to(lroute('login'));
        }
        if (! auth('web')->user()->hasCompleteProfile()) {
            return redirect()->to(self::profileCompletionTarget($locale, $request->getRequestUri()));
        }
        $invitation = $this->invitation($request, $token);

        return response(view('organizations.invitation', compact('invitation')), $invitation ? 200 : 410)
            ->header('Cache-Control', 'private, no-store')->header('X-Robots-Tag', 'noindex, nofollow')
            ->header('Referrer-Policy', 'no-referrer');
    }

    public function acceptInvitation(Request $request, string $locale, string $token)
    {
        $request->session()->setPreviousUrl($request->fullUrl());
        $request->validate(['confirm' => ['accepted'], 'confirm_email' => ['sometimes', 'accepted']]);
        $invitation = $this->invitation($request, $token);
        if (! $invitation) {
            return response(view('organizations.invitation', ['invitation' => null]), 410)->header('Cache-Control', 'private, no-store');
        }
        $this->memberships->accept($invitation, $request->user(), $request->boolean('confirm_email'));

        $request->session()->forget(['organization_invitation_flow', 'url.intended']);

        return redirect()->to(lroute('organizations.manage', ['slug' => $invitation->organization->slug]))->with('success', __('organization_members.saved'));
    }
}
