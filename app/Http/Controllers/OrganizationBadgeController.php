<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;

class OrganizationBadgeController extends Controller
{
    public function __invoke(Request $request, string $locale, string $slug)
    {
        abort_unless(in_array($locale, active_locales(), true), 404);
        App::setLocale($locale);
        $org = Organization::public()->withCampaignTotals()->where('slug', $slug)->first();
        $svg = view('organizations.badge', compact('org'))->render();
        $etag = '"'.hash('sha256', $svg).'"';
        $response = response($svg, 200, [
            'Content-Type' => 'image/svg+xml; charset=UTF-8', 'ETag' => $etag,
            // Revalidation prevents a saved embed from retaining a suspended identity.
            'Cache-Control' => 'public, max-age=0, must-revalidate',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; sandbox",
            'X-Content-Type-Options' => 'nosniff', 'X-Robots-Tag' => 'noindex',
        ]);
        $response->isNotModified($request);

        return $response;
    }
}
