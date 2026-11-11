<?php

namespace App\Support;

class OrganizationPrivateFlow
{
    public static function sensitive(): bool
    {
        $request = request();

        return $request->routeIs('organization-invitations.*', 'organizations.members*')
            || ($request->hasSession() && $request->session()->get('organization_invitation_flow')
                && $request->routeIs('login', 'login.post', 'register', 'register.post', 'profile', 'account.profile.update', 'verification.*'));
    }
}
