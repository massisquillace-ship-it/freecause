<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\UserLevel;
use App\Services\PetitionSigning;
use App\Support\AppLog;
use App\Support\Locale;
use App\Support\WpSso;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;

class GoogleAuthController extends Controller
{
    public function redirectForAccountDeletion(Request $request, string $locale)
    {
        $user = Auth::guard('web')->user();

        if (! $user?->isGoogleOnly()) {
            return redirect("/{$locale}/profile")->withErrors([
                'google_delete' => __('profile.google_delete_only_google'),
            ]);
        }

        if (! config('services.google.client_id') ||
            ! config('services.google.client_secret') ||
            ! config('services.google.redirect')) {
            return redirect("/{$locale}/profile")->withErrors([
                'google_delete' => __('profile.google_delete_unavailable'),
            ]);
        }

        $request->session()->forget('account_delete_google_verified');
        $request->session()->put('oauth_ctx', [
            'flow' => 'delete_account',
            'locale' => $locale,
            'user_id' => $user->id,
        ]);

        return Socialite::driver('google')
            ->with(['prompt' => 'login'])
            ->redirect();
    }

    public function redirect(Request $request, string $locale)
    {
        if (! config('services.google.client_id') ||
            ! config('services.google.client_secret') ||
            ! config('services.google.redirect')) {
            AppLog::warning(
                'Google OAuth unavailable',
                'Google OAuth credentials are not configured.',
                'auth.google'
            );

            return back()->withErrors([
                'oauth' => __('auth.google_unavailable'),
            ]);
        }

        $flow = $request->query('flow', 'register');

        if ($flow === 'link' && Auth::check()) {
            $request->session()->put('oauth_ctx', [
                'flow' => 'link',
                'locale' => $locale,
                'user_id' => Auth::id(),
            ]);
        } elseif ($flow === 'petition') {
            $request->session()->put('oauth_ctx', [
                'flow' => 'petition',
                'locale' => $locale,
                'petition_id' => (int) $request->query('petition_id'),
                'slug' => (string) $request->query('slug'),
            ]);
        } else {
            $redirect = self::sanitizeRedirectPath($request->query('redirect'), '');

            $request->session()->put('oauth_ctx', [
                'flow' => in_array($flow, ['login', 'register'], true) ? $flow : 'register',
                'locale' => $locale,
                'redirect' => $redirect,
            ]);
        }

        return Socialite::driver('google')->redirect();
    }

    public function callback(Request $request, string $locale)
    {
        $ctx = $request->session()->pull('oauth_ctx', [
            'flow' => 'register',
            'locale' => $locale,
        ]);

        // The callback URL is always /en/, so flash messages follow the locale the flow started in.
        if (in_array($ctx['locale'] ?? null, Locale::supported(), true)) {
            app()->setLocale($ctx['locale']);
        }

        try {
            $google = Socialite::driver('google')->user();
        } catch (InvalidStateException $e) {
            AppLog::warning(
                'Google OAuth state mismatch',
                'Session expired or replay detected',
                'auth.google'
            );

            $destination = ($ctx['flow'] ?? null) === 'delete_account' && Auth::check()
                ? "/{$locale}/profile"
                : "/{$locale}/login";

            return redirect($destination)->withErrors([
                'oauth' => __('auth.google_session_expired'),
            ]);
        }

        $email = strtolower(trim($google->getEmail() ?? ''));

        $fullName = trim((string) ($google->getName() ?: 'Google User'));

        $parts = preg_split('/\s+/', $fullName, -1, PREG_SPLIT_NO_EMPTY);
        $first = $parts[0] ?? $fullName;
        $last = count($parts) > 1 ? implode(' ', array_slice($parts, 1)) : null;

        $destLocale = $ctx['locale'] ?? $locale;

        if (($ctx['flow'] ?? null) === 'delete_account') {
            $currentUser = Auth::guard('web')->user();
            $matchesCurrentUser = $currentUser
                && (int) ($ctx['user_id'] ?? 0) === (int) $currentUser->id
                && filled($currentUser->google_id)
                && hash_equals((string) $currentUser->google_id, (string) $google->getId())
                && hash_equals(strtolower(trim((string) $currentUser->email)), $email);

            if (! $matchesCurrentUser) {
                $request->session()->forget('account_delete_google_verified');

                return redirect("/{$destLocale}/profile")->withErrors([
                    'google_delete' => __('profile.google_delete_mismatch'),
                ]);
            }

            $request->session()->put('account_delete_google_verified', [
                'user_id' => $currentUser->id,
                'verified_at' => now()->timestamp,
            ]);

            return redirect("/{$destLocale}/profile")
                ->with('success', __('profile.google_delete_verified'));
        }

        if (($ctx['flow'] ?? null) === 'link') {
            $currentUser = Auth::guard('web')->user();

            if (! $currentUser || (int) ($ctx['user_id'] ?? 0) !== (int) $currentUser->id) {
                return redirect("/{$destLocale}/login")->withErrors([
                    'oauth' => __('profile.google_link_session_lost'),
                ]);
            }

            if (! $email) {
                return redirect("/{$destLocale}/profile")->withErrors([
                    'google_link' => __('profile.google_link_no_email'),
                ]);
            }

            $takenByOther = User::where('google_id', $google->getId())
                ->where('id', '!=', $currentUser->id)
                ->exists();

            $emailOnOther = User::where('email', $email)
                ->where('id', '!=', $currentUser->id)
                ->exists();

            if ($takenByOther || $emailOnOther) {
                AppLog::warning(
                    'Google link rejected — identity already in use',
                    'User ID: '.$currentUser->id.' | Email: '.$email,
                    'auth.google'
                );

                return redirect("/{$destLocale}/profile")->withErrors([
                    'google_link' => __('profile.google_link_in_use'),
                ]);
            }

            abort_unless(hash_equals(strtolower(trim((string) $currentUser->email)), $email), 403);

            try {
                $currentUser->google_id = $google->getId();
                DB::transaction(fn () => $currentUser->save());
            } catch (\Throwable $e) {
                AppLog::error(
                    'Google link failed to save',
                    'User ID: '.$currentUser->id.' | '.$e->getMessage(),
                    'auth.google'
                );

                return redirect("/{$destLocale}/profile")->withErrors([
                    'google_link' => __('profile.google_link_failed'),
                ]);
            }

            AppLog::info(
                'Google account linked from profile',
                'User ID: '.$currentUser->id.' | Email: '.$email,
                'auth.google'
            );

            return redirect("/{$destLocale}/profile")
                ->with('success', __('profile.google_link_success'));
        }

        if (! $email) {
            AppLog::warning(
                'Google OAuth missing email',
                'Google ID: '.$google->getId(),
                'auth.google'
            );

            return redirect("/{$destLocale}/register")->withErrors(['email' => __('auth.google_no_email')]);
        }

        $user = User::where('google_id', $google->getId())->first() ?? User::where('email', $email)->first();
        $userLevel = UserLevel::where('name', 'user')->first();

        $newUser = false;

        if ($user && empty($user->google_id)) {

            $hasNoRealPassword = is_null($user->password_changed_at)
                && blank($user->password);

            if ($hasNoRealPassword) {
                AppLog::info(
                    'Google OAuth re-linked (no password account)',
                    'User ID: '.$user->id.' | Email: '.$email,
                    'auth.google'
                );
            } else {
                AppLog::warning(
                    'Google OAuth email conflict — existing account not linked',
                    'Email: '.$email.' | Google ID: '.$google->getId(),
                    'auth.google'
                );

                return redirect("/{$destLocale}/login")
                    ->withErrors(['email' => __('auth.google_email_exists')])
                    ->withInput(['email' => $email]);
            }
        }

        if ($user && filled($user->google_id) && $user->google_id !== $google->getId()) {
            AppLog::warning(
                'Google OAuth refused — account linked to another Google ID',
                'User ID: '.$user->id.' | Email: '.$email.' | Google ID: '.$google->getId(),
                'auth.google'
            );

            return redirect("/{$destLocale}/login")->withErrors(['oauth' => __('auth.google_signin_failed')]);
        }

        if (! $user) {
            $newUser = true;

            $user = User::create([
                'name' => $fullName,
                'first_name' => $first,
                'last_name' => $last,
                'email' => $email,
                'password' => null,
                'locale' => Locale::toFull($destLocale),
                'ip' => $request->ip(),
                'level_id' => $userLevel?->id,
                'welcome_email_pending' => true,
                'verified' => true,
            ]);

            AppLog::info(
                'User registered via Google',
                'User ID: '.$user->id.' | Email: '.$email,
                'auth.google'
            );
        }

        if ($user->isBanned()) {
            AppLog::warning(
                'Banned user attempted Google login',
                'User ID: '.$user->id.' | Email: '.$email,
                'auth.google'
            );

            Auth::logout();

            return redirect("/{$destLocale}/login")->withErrors([
                'oauth' => __('auth.err_suspended'),
            ]);
        }

        $user->ip = $request->ip();

        if (! $user->first_name) {
            $user->first_name = $first;
        }
        if (! $user->last_name) {
            $user->last_name = $last;
        }

        // markVerified() flips verified and confirms pending signatures in one
        // transaction; if it fails the user stays unverified and the next login retries.
        $wasUnverified = $user->verified === false;

        $user->google_id = $google->getId();

        $user->save();

        if ($wasUnverified) {
            PetitionSigning::markVerified($user);
            $user->refresh();
        }
        Auth::login($user);

        if (! $newUser) {
            AppLog::info(
                'User login via Google',
                'User ID: '.$user->id.' | Email: '.$email,
                'auth.google'
            );
        }

        $request->session()->regenerate();

        if (($ctx['flow'] ?? '') === 'petition' && ! empty($ctx['petition_id']) && ! empty($ctx['slug'])) {
            $dest = route('petition.sign.page', [
                'locale' => $ctx['locale'],
                'slug' => $ctx['slug'],
                'id' => $ctx['petition_id'],
            ]);
            $dest = parse_url($dest, PHP_URL_PATH).'?'.http_build_query(['oauth_logged_in' => 1]);

            if (! $user->hasCompleteProfile()) {
                $dest = self::profileCompletionTarget($destLocale, $dest);
            }

            return redirect()->to(WpSso::loginUrl($user->email, $user->name, $dest));
        }

        $dest = $ctx['redirect'] ?? '';
        if ($dest === '') {
            $dest = '/'.Locale::toShort($user->locale);
        }

        if (! $user->hasCompleteProfile()) {
            $dest = self::profileCompletionTarget($destLocale, $dest);
        }

        return redirect()->to(WpSso::loginUrl($user->email, $user->name, $dest));
    }
}
