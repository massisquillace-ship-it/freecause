<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureProfileComplete
{
    private const ALWAYS_ALLOWED = [
        'profile',
        'account.profile.update',
        'account.delete',
        'account.delete.google',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if (in_array($request->route()?->getName(), self::ALWAYS_ALLOWED, true)) {
            return $next($request);
        }

        $user = $request->user();

        if ($user instanceof User && ! $user->hasCompleteProfile()) {
            $return = ($request->isMethod('GET') || $request->routeIs('organization-invitations.accept'))
                ? $request->getRequestUri()
                : (parse_url((string) url()->previous(), PHP_URL_PATH) ?: '/');
            $profileUrl = lroute('profile');
            $params = [];

            if (is_string($return) && $return !== '') {
                $params['return'] = $return;
            }

            $reason = $this->reasonFor($request);

            if ($reason !== '') {
                $params['reason'] = $reason;
            }

            if ($params !== []) {
                $profileUrl .= (str_contains($profileUrl, '?') ? '&' : '?').http_build_query($params);
            }

            return redirect()
                ->to($profileUrl)
                ->with('profile_required', 1);
        }

        return $next($request);
    }

    private function reasonFor(Request $request): string
    {
        $name = (string) $request->route()?->getName();

        return match (true) {
            str_starts_with($name, 'petition.sign') => 'sign',
            str_starts_with($name, 'petition.store') => 'create',
            str_starts_with($name, 'petition.edit'),
            str_starts_with($name, 'petition.update') => 'edit',
            str_starts_with($name, 'report.') => 'report',
            str_starts_with($name, 'preferences.') => 'preferences',
            default => '',
        };
    }
}
