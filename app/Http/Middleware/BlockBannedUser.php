<?php

namespace App\Http\Middleware;

use App\Models\UserLevel;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class BlockBannedUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user) {
            if ($user->banned_until !== null && now()->gt($user->banned_until)) {
                $attributes = ['banned_reason' => null, 'banned_until' => null];
                $userLevel = UserLevel::where('name', 'user')->value('id');
                if ($userLevel) {
                    $attributes['level_id'] = $userLevel;
                }
                $user->forceFill($attributes)->saveQuietly();

                return $next($request);
            }

            if ($user->isBanned()) {
                auth()->guard('web')->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                $locale = app()->getLocale();
                toast(__('auth.err_suspended'), 'error');

                return redirect()->to("/{$locale}");
            }
        }

        return $next($request);
    }
}
