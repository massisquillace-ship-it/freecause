<?php

namespace App\Http\Controllers;

use App\Models\Signature;
use Illuminate\Http\Request;

class OrganizationConsentController extends Controller
{
    public function show(Request $request, string $locale, string $token)
    {
        $this->signature($token);

        return response()->view('organizations.revoke', ['action' => $request->fullUrl(), 'revoked' => false])
            ->header('Cache-Control', 'private, no-store')->header('X-Robots-Tag', 'noindex, nofollow')->header('Referrer-Policy', 'no-referrer');
    }

    public function revoke(Request $request, string $locale, string $token)
    {
        $signature = $this->signature($token);
        Signature::whereKey($signature->id)->whereNull('org_optin_revoked_at')->update(['org_optin_revoked_at' => now()]);

        return response()->view('organizations.revoke', ['action' => '', 'revoked' => true])
            ->header('Cache-Control', 'private, no-store')->header('X-Robots-Tag', 'noindex, nofollow')->header('Referrer-Policy', 'no-referrer');
    }

    private function signature(string $token): Signature
    {
        abort_unless(preg_match('/\A[a-f0-9]{64}\z/', $token), 404);

        return Signature::where('org_optin_revoke_token', hash('sha256', $token))->where('org_optin', true)->firstOrFail();
    }
}
