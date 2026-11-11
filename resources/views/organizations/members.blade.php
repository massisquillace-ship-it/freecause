@extends('layouts.organization-private')
@section('title', __('organization_members.title'))
@section('content')
@php
    $memberUrl = function (string $action = '', array $params = []) use ($org) {
        if (request()->routeIs('admin.*')) return route('admin.organizations.members'.($action ? '.'.$action : ''), array_merge(['slug'=>$org->slug], $params));
        return lroute($action === 'deletion' ? 'organizations.deletion-request' : 'organizations.members'.($action ? '.'.$action : ''), array_merge(['slug'=>$org->slug], $params));
    };
    $policy = app(\App\Policies\OrganizationPolicy::class);
    $myRole = $org->roleFor($actor);
    $canTransfer = $policy->transferOwnership($actor, $org);
@endphp
<a class="underline" href="{{ (request()->routeIs('admin.*') ? route('admin.organizations.show', ['organization'=>$org->id]) : lroute('organizations.manage', ['slug'=>$org->slug])) }}">{{ __('organizations.manage') }}</a>
<h1 class="text-3xl font-bold mt-4 mb-3 break-words">{{ $org->name }} — {{ __('organization_members.title') }}</h1>
<p class="mb-6">{{ __('organization_members.role_label') }}: {{ __('organization_members.'.($myRole ?? 'admin')) }}</p>
@if($canManage)
    @if(session('invitation_link'))
        <section class="border border-border rounded-lg p-5 mb-6" aria-labelledby="invitation-link-title">
            <h2 id="invitation-link-title" class="text-xl font-semibold mb-3">{{ __('organization_members.invitation_link') }}</h2>
            <p class="mb-3">{{ __('organization_members.copy_help') }}</p>
            <label for="private-invitation-link" class="sr-only">{{ __('organization_members.invitation_link') }}</label>
            <textarea id="private-invitation-link" class="fcui-input w-full font-mono text-sm" rows="4" readonly>{{ session('invitation_link') }}</textarea>
        </section>
    @endif
    <section class="border border-border rounded-lg p-5 mb-6" aria-labelledby="members-roster-title">
        <h2 id="members-roster-title" class="text-2xl font-bold mb-4">{{ __('organization_members.title') }}</h2>
        <ul class="space-y-5">
        @foreach($members as $member)
            <li class="border-b border-border pb-5 break-words">
                <p class="font-semibold">{{ $member->user?->display_name ?? __('organization_members.deleted_member') }}</p>
                <p class="text-sm break-all">{{ $member->user?->email }}</p>
                <p class="text-sm text-muted mt-1">{{ __('organization_members.'.$member->role) }} · {{ __('organization_members.joined') }} {{ $member->joined_at->format('Y-m-d') }}</p>
                @if($member->role !== 'owner')
                    <div class="flex flex-wrap items-end gap-4 mt-3">
                        <form method="post" action="{{ $memberUrl('role', ['member'=>$member->id]) }}" class="flex flex-wrap items-end gap-2">@csrf
                            <div><label class="block text-sm mb-1" for="member-role-{{ $member->id }}">{{ __('organization_members.role_label') }}</label><select id="member-role-{{ $member->id }}" name="role" class="fcui-input"><option value="admin" @selected($member->role === 'admin')>{{ __('organization_members.admin') }}</option><option value="editor" @selected($member->role === 'editor')>{{ __('organization_members.editor') }}</option></select></div>
                            <x-ui.button variant="secondary">{{ __('organization_members.change_role') }}</x-ui.button>
                        </form>
                        <form method="post" action="{{ $memberUrl('remove', ['member'=>$member->id]) }}">@csrf
                            <label class="block text-sm mb-2"><input type="checkbox" name="confirm" value="1" required> {{ __('organization_members.remove_confirm') }}</label><x-ui.button variant="ghost">{{ __('organization_members.remove') }}</x-ui.button>
                        </form>
                    </div>
                @endif
            </li>
        @endforeach
        </ul>
    </section>
    @if(in_array($org->status, ['pending','verified'], true) && (!$org->claimed_at || $org->status === 'verified'))
    <section class="border border-border rounded-lg p-5 mb-6" aria-labelledby="invite-member-title">
        <h2 id="invite-member-title" class="text-2xl font-bold mb-4">{{ __('organization_members.invite') }}</h2>
        <form method="post" action="{{ $memberUrl('invite') }}" class="space-y-4">@csrf
            <div><label class="block mb-1" for="invite-email">{{ __('organization_members.email') }}</label><input id="invite-email" name="email" type="email" maxlength="254" value="{{ old('email') }}" class="fcui-input w-full" required></div>
            <div><label class="block mb-1" for="invite-role">{{ __('organization_members.role_label') }}</label><select id="invite-role" name="role" class="fcui-input w-full"><option value="editor">{{ __('organization_members.editor') }}</option><option value="admin">{{ __('organization_members.admin') }}</option></select></div>
            <x-ui.button>{{ __('organization_members.invite') }}</x-ui.button>
        </form>
    </section>
    @else<p class="mb-6">{{ __('organization_members.invites_unavailable') }}</p>@endif
    <section class="border border-border rounded-lg p-5 mb-6" aria-labelledby="pending-invitations-title">
        <h2 id="pending-invitations-title" class="text-2xl font-bold mb-4">{{ __('organization_members.pending_invites') }}</h2>
        <ul class="space-y-4">@forelse($invitations as $invitation)
            <li class="break-words"><p class="break-all">{{ $invitation->email }} — {{ __('organization_members.'.$invitation->role) }}</p><p class="text-sm text-muted">{{ __('organization_members.expires') }} {{ $invitation->expires_at->format('Y-m-d') }}</p>
                <div class="flex flex-wrap gap-3 mt-2">
                    @if(in_array($org->status, ['pending','verified'], true))<form method="post" action="{{ $memberUrl('resend', ['invitation'=>$invitation->id]) }}">@csrf<x-ui.button variant="secondary">{{ __('organization_members.resend') }}</x-ui.button></form>@endif
                    <form method="post" action="{{ $memberUrl('revoke', ['invitation'=>$invitation->id]) }}">@csrf<x-ui.button variant="ghost">{{ __('organization_members.revoke') }}</x-ui.button></form>
                </div>
            </li>
        @empty<li>{{ __('organization_members.no_pending') }}</li>@endforelse</ul>
    </section>
    @if($canTransfer)
    <section class="border border-border rounded-lg p-5 mb-6" aria-labelledby="transfer-owner-title">
        <h2 id="transfer-owner-title" class="text-2xl font-bold mb-4">{{ __('organization_members.transfer') }}</h2>
        @if($members->where('role','admin')->isNotEmpty())
        <form method="post" action="{{ $memberUrl('transfer') }}" class="space-y-4">@csrf
            <div><label class="block mb-1" for="new-owner">{{ __('organization_members.new_owner') }}</label><select id="new-owner" name="member_id" class="fcui-input w-full" required>@foreach($members->where('role','admin') as $member)<option value="{{ $member->id }}">{{ $member->user?->display_name }} — {{ $member->user?->email }}</option>@endforeach</select></div>
            <label class="block"><input type="checkbox" name="confirm" value="1" required> {{ __('organization_members.transfer_confirm') }}</label>
            <x-ui.button variant="secondary">{{ __('organization_members.transfer') }}</x-ui.button>
        </form>
        @else<p>{{ __('organization_members.admin_required') }}</p>@endif
    </section>
    @endif
    <section class="border border-border rounded-lg p-5 mb-6" aria-labelledby="members-audit-title">
        <h2 id="members-audit-title" class="text-2xl font-bold mb-4">{{ __('organization_members.audit_title') }}</h2>
        <ul class="space-y-2 text-sm">@forelse($events as $event)<li>{{ $event->created_at->format('Y-m-d H:i') }} — {{ __('organization_members.events.'.$event->event) }}</li>@empty<li>{{ __('organization_members.audit_empty') }}</li>@endforelse</ul>
    </section>
@endif
@if($myRole === 'owner')<p class="mb-6">{{ __('organization_members.owner_leave') }}</p>
@elseif(in_array($myRole, ['admin','editor'], true))
    <form method="post" action="{{ $memberUrl('leave') }}" class="border border-border rounded-lg p-5 mb-6">@csrf
        <label class="block mb-3"><input type="checkbox" name="confirm" value="1" required> {{ __('organization_members.leave_confirm') }}</label><x-ui.button variant="ghost">{{ __('organization_members.leave') }}</x-ui.button>
    </form>
@endif
@if($policy->requestDeletion($actor, $org))
    <form method="post" action="{{ $memberUrl('deletion') }}" class="border border-border rounded-lg p-5">@csrf
        <h2 class="text-xl font-semibold mb-3">{{ __('organization_members.deletion_request') }}</h2><label class="block mb-3"><input type="checkbox" name="confirm" value="1" required> {{ __('organization_members.deletion_confirm') }}</label><x-ui.button variant="ghost">{{ __('organization_members.deletion_request') }}</x-ui.button>
    </form>
@endif
@endsection
