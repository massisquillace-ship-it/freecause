<?php

namespace App\Http\Controllers;

use App\Http\Requests\DeleteAccountRequest;
use App\Http\Requests\EmailRequest;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Http\Requests\ResetPasswordRequest;
use App\Http\Requests\UpdateProfileRequest;
use App\Jobs\SendWelcomeEmail;
use App\Mail\VerifyAccountMail;
use App\Models\Language;
use App\Models\User;
use App\Models\UserLevel;
use App\Services\OrganizationLifecycle;
use App\Services\PetitionSigning;
use App\Services\WordPressUserDeletionService;
use App\Support\AppLog;
use App\Support\Countries;
use App\Support\Locale;
use App\Support\MailQuota;
use App\Support\Settings;
use App\Support\Spam;
use App\Support\WpSso;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(private readonly WordPressUserDeletionService $wordPressUserDeletion) {}

    public function showLogin(string $locale)
    {
        if (Auth::check()) {
            return redirect()->to("/{$locale}");
        }

        return view('auth.login');
    }

    public function showRegister(string $locale)
    {
        if (Auth::check()) {
            return redirect()->to("/{$locale}");
        }

        return view('auth.register', compact('locale'));
    }

    public function profile()
    {
        $languages = Language::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['code', 'name']);

        return view('auth.profile', [
            'countries' => Countries::all(),
            'languages' => $languages,
        ]);
    }

    public function register(RegisterRequest $request, string $locale)
    {
        $data = $request->validated();

        $fullName = trim($data['name'].' '.$data['surname']);

        $userLevel = UserLevel::where('name', 'user')->first();

        if (Spam::isSpam($data['email'])) {
            Spam::log('register', $data['email']);

            AppLog::warning(
                'Spam registration attempt',
                'Email: '.$data['email'],
                'auth.register'
            );

            toast(__('auth.err_spam'), 'error');

            return back()->withInput();
        }

        $smtpEnabled = Settings::get('smtp_enabled', false);
        $requireVerif = Settings::get('require_email_verification', false, 'global');

        $needsVerif = (bool) $requireVerif;
        $token = $needsVerif ? Str::random(64) : null;

        try {
            $user = User::create([
                'name' => $fullName,
                'first_name' => $data['name'],
                'last_name' => $data['surname'],
                'email' => strtolower(trim($data['email'])),
                'password' => Hash::make($data['password']),
                'password_changed_at' => now(),
                'locale' => $locale,
                'ip' => $request->ip(),
                'level_id' => $userLevel?->id,
                'nickname' => $data['nickname'] ?? null,
                'identify_mode' => filled($data['nickname'] ?? null) ? 'nick' : 'full', // legacy: nickname given => display nickname
                'city' => $data['city'] ?? null,

                'welcome_email_pending' => true,
                'verified' => $needsVerif ? false : true,
                'verification_token' => $token,
                'verification_token_sent_at' => $needsVerif ? now() : null,
            ]);
        } catch (\Throwable $e) {
            AppLog::error(
                'User registration failed',
                'Email: '.$data['email'].' | '.$e->getMessage(),
                'auth.register'
            );

            return back()->withInput()->withErrors([
                'email' => __('auth.register_failed'),
            ]);
        }

        AppLog::info(
            'User registered',
            'User ID: '.$user->id.' | Email: '.$user->email,
            'auth.register'
        );

        if ($needsVerif && $smtpEnabled) {
            try {
                Mail::to($user->email)->queue(new VerifyAccountMail($user, $locale));
            } catch (\Exception $e) {
                AppLog::error(
                    'Mail sending failed',
                    $e->getMessage(),
                    'auth.register'
                );

                $this->markPendingVerification($request, $user, $locale);

                return redirect()
                    ->route('verification.notice', ['locale' => $locale])
                    ->with('error', __('auth.register_failed'));
            }

            $this->markPendingVerification($request, $user, $locale);

            return redirect()
                ->route('verification.notice', ['locale' => $locale])
                ->with('success', __('auth.register_check_email'));
        }

        if ($needsVerif && ! $smtpEnabled) {
            $this->markPendingVerification($request, $user, $locale);

            return redirect()
                ->route('verification.notice', ['locale' => $locale])
                ->with('success', __('auth.register_pending'));
        }

        auth()->login($user);
        $request->session()->regenerate();

        toast(__('auth.register_success'), 'success');

        $dest = self::sanitizeRedirectPath($request->input('redirect'), "/{$locale}");

        if (! $user->hasCompleteProfile()) {
            $dest = self::profileCompletionTarget($locale, $dest);
        }

        return redirect()->to(WpSso::loginUrl($user->email, $user->name, $dest));
    }

    public function login(LoginRequest $request, string $locale)
    {
        $credentials = $request->validated();

        $remember = $request->boolean('remember');

        $user = User::where('email', $credentials['email'])->first();

        if ($user) {
            if ($user->isBanned()) {
                AppLog::warning(
                    'Banned user attempted login',
                    'User ID: '.$user->id.' | IP: '.$request->ip(),
                    'auth.login'
                );
                toast(__('auth.err_suspended'), 'error');

                return back()->withInput();
            }
        }

        $legacyHash = $user && filled($user->password) && str_contains($user->password, ':');

        try {
            $loggedIn = ! $legacyHash
                && Auth::guard('web')->attempt($credentials, $remember);
        } catch (\RuntimeException) {
            $loggedIn = false;
        }

        if (! $loggedIn && $legacyHash && $user->upgradeLegacyPassword($credentials['password'])) {
            Auth::guard('web')->login($user, $remember);
            $loggedIn = true;
        }

        if ($loggedIn) {
            if (! $user->verified) {
                Auth::guard('web')->logout();

                $request->session()->put('verify_email', $user->email);

                return redirect()
                    ->route('verification.notice', ['locale' => $locale])
                    ->withErrors(['email' => __('auth.err_verify_first')]);
            }

            $request->session()->regenerate();

            $user = Auth::user();
            $user->ip = $request->ip();
            if (filled($user->password) && $user->password_changed_at === null) {
                $user->password_changed_at = now();
            }
            $user->save();

            $dest = self::sanitizeRedirectPath($request->input('redirect'), '');
            if ($dest === '') {
                $intended = (string) $request->session()->pull('url.intended', '');
                $path = (string) parse_url($intended, PHP_URL_PATH);
                $query = parse_url($intended, PHP_URL_QUERY);
                $dest = self::sanitizeRedirectPath($path.(filled($query) ? '?'.$query : ''), '');
            }
            if ($dest === '') {
                $dest = '/'.Locale::toShort($user->locale);
            }

            if (! $user->hasCompleteProfile()) {
                $dest = self::profileCompletionTarget($locale, $dest);
            }

            return redirect()->to(WpSso::loginUrl($user->email, $user->name, $dest));
        }

        return back()->withErrors(['email' => __('auth.err_invalid_credentials')])->onlyInput('email', 'auth_form');
    }

    public function logout(Request $request, string $locale)
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->to(WpSso::logoutUrl("/{$locale}"));
    }

    public function updateProfile(UpdateProfileRequest $request, string $locale)
    {
        $u = Auth::guard('web')->user();

        $data = $request->validated();

        $first = trim($data['first_name']);
        $last = trim($data['last_name']);

        $u->first_name = $first;
        $u->last_name = $last;
        $u->name = trim($first.' '.$last);

        $u->nickname = $data['nickname'] ?? null;
        $u->city = $data['city'] ?? null;
        $u->country = $data['country'];
        $u->gender = $data['gender'];
        $u->locale = Locale::toFull($data['locale']);
        $u->preferred_language_confirmed_at = now();
        $u->identify_mode = $data['identify_mode'] ?? ($u->identify_mode ?? 'full');

        $newEmail = strtolower(trim($data['new_email'] ?? ''));
        $emailChanged = false;

        if ($newEmail !== '' && $newEmail !== strtolower((string) $u->email)) {
            if (! Settings::get('smtp_enabled', false)) {

                return back()
                    ->withInput()
                    ->withErrors(['new_email' => __('profile.email_needs_mail_system')]);
            }

            // No mail would go out, so don't reserve the address.
            if (MailQuota::tripped()) {
                return back()
                    ->withInput()
                    ->withErrors(['new_email' => __('auth.email_try_later')]);
            }

            // email/verified stay until the link proves the new address (see verify()).
            $u->pending_email = $newEmail;
            $u->verification_token = Str::random(64);
            $u->verification_token_sent_at = now();
            $emailChanged = true;
        }

        if (! empty($data['new_password'])) {
            if (! $u->hasLocalPassword()) {
                return back()->withInput()->withErrors(['profile' => __('profile.google_only_note')]);
            }

            $u->password = Hash::make($data['new_password']);
            $u->password_changed_at = now();
        }

        try {
            DB::transaction(fn () => $u->save());
        } catch (\Throwable $e) {
            AppLog::error(
                'Profile update failed',
                'User ID: '.$u->id.' | '.$e->getMessage(),
                'auth.profile'
            );

            return back()
                ->withInput()
                ->withErrors(['profile' => __('profile.update_failed')]);
        }

        if ($u->welcome_email_pending && $u->verified && $u->hasCompleteProfile()) {
            SendWelcomeEmail::dispatch($u->id)->afterCommit();
        }

        if ($emailChanged) {
            try {
                Mail::to($u->pending_email)->queue(new VerifyAccountMail($u, $locale));
            } catch (\Throwable $e) {
                AppLog::error(
                    'Mail sending failed (profile update)',
                    'User ID: '.$u->id.' | '.$e->getMessage(),
                    'auth.profile'
                );
            }

            toast(__('profile.email_change_pending'), 'info');
        } else {
            toast(__('profile.updated_success'), 'success');
        }

        $return = $request->query('return');

        return redirect()->to(
            self::sanitizeRedirectPath(is_string($return) ? $return : null, "/{$data['locale']}/profile")
        );
    }

    public function delete(DeleteAccountRequest $request, string $locale)
    {
        $u = Auth::guard('web')->user();

        if ($u->isGoogleOnly()) {
            $proof = $request->session()->pull('account_delete_google_verified');
            $verifiedAt = is_array($proof) ? (int) ($proof['verified_at'] ?? 0) : 0;
            $proofIsValid = is_array($proof)
                && (int) ($proof['user_id'] ?? 0) === (int) $u->id
                && $verifiedAt >= now()->subMinutes(5)->timestamp;

            if (! $proofIsValid) {
                throw ValidationException::withMessages([
                    'google_delete' => __('profile.account_delete_verify_google'),
                ]);
            }
        }

        $userId = $u->id;
        $userEmail = $u->email;

        try {
            $this->wordPressUserDeletion->delete($u);
        } catch (\Throwable $e) {
            Log::error('Account deletion aborted because WordPress cleanup failed: '.$e->getMessage());

            return back()->withErrors([
                'delete_account' => __('profile.account_delete_failed'),
            ]);
        }

        try {
            DB::transaction(function () use ($u) {
                app(OrganizationLifecycle::class)->suspendForDeletedOwner($u);
                User::query()->whereKey($u->getKey())->delete();
            }, 3);
        } catch (\Throwable $e) {
            AppLog::error(
                'Account deletion failed at local delete step',
                'User ID: '.$userId.' | '.$e->getMessage(),
                'auth.delete'
            );

            return back()->withErrors([
                'delete_account' => __('profile.account_delete_failed'),
            ]);
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        AppLog::warning(
            'User account deleted',
            'User ID: '.$userId.' | Email: '.$userEmail,
            'auth.delete'
        );

        return redirect()->to("/{$locale}")->with('success', __('profile.account_deleted_success'));
    }

    public function verify(Request $request, string $locale, string $token)
    {
        $user = User::where('verification_token', $token)->first();

        if (! $user) {
            toast(__('auth.verify_invalid'), 'error');

            return redirect()->to("/{$locale}");
        }

        if ($user->pending_email) {
            $expired = $user->verification_token_sent_at && $user->verification_token_sent_at->lt(now()->subHours(48));
            // The address may have been registered or claimed since the link was sent.
            $taken = ! $expired && User::where('email', strtolower($user->pending_email))->whereKeyNot($user->id)->exists();

            if (! $expired && ! $taken) {
                $user->email = $user->pending_email;
            }

            $user->pending_email = null;
            $user->verification_token = null;
            $user->save();

            // A still-unverified account that just proved its (new) address falls through to markVerified().
            if ($expired || $taken || $user->verified) {
                $request->session()->forget(['verify_email', 'verify_intended', 'verify_autologin']);

                if ($expired) {
                    toast(__('auth.verify_expired'), 'error');

                    return redirect()->to($user->verified ? "/{$locale}/profile" : "/{$locale}/resend-verification");
                }

                toast($taken ? __('profile.email_taken') : __('auth.verify_success'), $taken ? 'error' : 'success');

                return redirect()->to("/{$locale}");
            }
        }

        if ($user->verified) {
            toast(__('auth.verify_already'), 'info');

            return redirect()->to("/{$locale}");
        }

        if ($user->verification_token_sent_at && $user->verification_token_sent_at->lt(now()->subHours(48))) {
            toast(__('auth.verify_expired'), 'error');

            return redirect()->to("/{$locale}/resend-verification");
        }

        PetitionSigning::markVerified($user);

        if ($request->session()->pull('verify_autologin')) {
            $user->refresh();

            if (! $user->verified) {
                toast(__('auth.verify_invalid'), 'error');

                return redirect()->to("/{$locale}");
            }

            auth()->login($user);
            $request->session()->regenerate();
            $request->session()->forget('verify_email');

            toast(__('auth.verify_success'), 'success');

            $intended = $request->session()->pull('verify_intended');

            if ($intended && ! $user->hasCompleteProfile()) {
                $intended = self::profileCompletionTarget($locale, $intended);
            }

            if (! $user->hasCompleteProfile()) {
                $request->session()->flash('profile_completion', __('profile.complete_required'));
            }

            return redirect()->to($intended ?: "/{$locale}");
        }

        $request->session()->forget(['verify_email', 'verify_intended']);

        return redirect()
            ->route('verification.verified', ['locale' => $locale])
            ->with('success', __('auth.verify_success'));
    }

    public function unlinkGoogle(Request $request)
    {
        $user = auth()->user();

        if (! $user->hasLocalPassword()) {
            return back()->withErrors(['google_unlink' => __('profile.google_unlink_needs_password')]);
        }

        try {
            $user->google_id = null;
            DB::transaction(fn () => $user->save());
        } catch (\Throwable $e) {
            AppLog::error(
                'Google unlink failed',
                'User ID: '.$user->id.' | '.$e->getMessage(),
                'auth.google'
            );

            return back()->withErrors(['google_unlink' => __('profile.google_unlink_failed')]);
        }

        return back()->with('success', __('profile.google_unlinked_success'));
    }

    public function showForgotPassword(string $locale)
    {
        return view('auth.forgot-password');
    }

    public function sendResetLink(EmailRequest $request, string $locale)
    {
        if (MailQuota::tripped()) {
            return back()->withInput()->withErrors(['email' => __('auth.email_try_later')]);
        }

        try {
            $status = Password::sendResetLink(
                $request->only('email')
            );
        } catch (\Throwable $e) {
            if (! MailQuota::causedBy($e)) {
                throw $e;
            }

            MailQuota::trip();
            AppLog::error('Password reset mail refused', $e->getMessage(), 'auth.password');

            return back()->withInput()->withErrors(['email' => __('auth.email_try_later')]);
        }

        return $status === Password::RESET_LINK_SENT
            ? back()->with('status', __($status))
            : back()->withErrors(['email' => __($status)]);
    }

    public function showResetForm(string $locale, string $token)
    {
        return view('auth.reset-password', [
            'token' => $token,
        ]);
    }

    public function resetPassword(ResetPasswordRequest $request, string $locale)
    {
        try {
            $status = Password::reset(
                $request->only('email', 'password', 'password_confirmation', 'token'),
                function ($user, $password) {
                    $user->password = Hash::make($password);
                    $user->setRememberToken(Str::random(60));
                    $user->password_changed_at = now();
                    $user->save();

                    event(new PasswordReset($user));
                }
            );
        } catch (\Throwable $e) {
            $status = Password::INVALID_TOKEN;
        }

        if ($status !== Password::PASSWORD_RESET) {
            return back()->withErrors(['email' => __($status)]);
        }

        $user = User::where('email', strtolower(trim((string) $request->input('email'))))->first();

        if (! $user) {
            return redirect("/{$locale}/login")->with('success', __($status));
        }

        // The reset link came by email, so using it proves inbox ownership.
        if (! $user->verified && ! $user->isBanned()) {
            PetitionSigning::markVerified($user);
            $user->refresh();
        }

        auth()->login($user);
        $request->session()->regenerate();

        toast(__($status), 'success');

        $dest = self::sanitizeRedirectPath($request->input('redirect'), "/{$locale}");

        if (! $user->hasCompleteProfile()) {
            $dest = self::profileCompletionTarget($locale, $dest);
        }

        return redirect()->to($dest);
    }

    public function showResendVerification(string $locale)
    {
        return view('auth.resend-verification', compact('locale'));
    }

    public function resendVerification(EmailRequest $request, string $locale)
    {
        if (! Settings::get('smtp_enabled', false)) {
            return back()->withErrors([
                'email' => __('auth.resend_disabled'),
            ]);
        }

        // Verification mail is dropped while the quota is out, so don't claim it was sent.
        if (MailQuota::tripped()) {
            return back()->withInput()->withErrors(['email' => __('auth.email_try_later')]);
        }

        $user = User::where('email', strtolower(trim($request->email)))->first();

        // Same reply as a sent mail, so the cooldown can't be told apart; a resend also voids the earlier link.
        if (! $user || $user->verified || $user->verification_token_sent_at?->gt(now()->subMinutes(10))) {
            return back()->with('success', __('auth.resend_sent'));
        }

        try {
            // This token goes to the current address, so it must not confirm a pending change.
            $user->pending_email = null;
            $user->verification_token = Str::random(64);
            $user->verification_token_sent_at = now();
            $user->save();
        } catch (\Throwable $e) {
            AppLog::error(
                'Verification token rotation failed',
                'User ID: '.$user->id.' | '.$e->getMessage(),
                'auth.verification'
            );

            return back()->withInput()->withErrors([
                'email' => __('auth.update_failed'),
            ]);
        }

        try {
            Mail::to($user->email)->queue(
                new VerifyAccountMail($user, $locale)
            );

            AppLog::info(
                'Verification email resent',
                'User ID: '.$user->id.' | Email: '.$user->email,
                'auth.verification'
            );
        } catch (\Exception $e) {

            AppLog::error(
                'Verification resend failed',
                $e->getMessage(),
                'auth.verification'
            );

            return back()->withErrors([
                'email' => __('auth.resend_failed'),
            ]);
        }

        return back()->with('success', __('auth.resend_sent'));
    }

    private function markPendingVerification(Request $request, User $user, string $locale): void
    {
        $request->session()->put('verify_email', $user->email);
        $request->session()->put('verify_autologin', true);
        $request->session()->put(
            'verify_intended',
            self::sanitizeRedirectPath($request->input('redirect'), "/{$locale}")
        );
    }

    public function showVerifyNotice(Request $request, string $locale)
    {
        $email = (string) $request->session()->get('verify_email');

        return view('auth.verify-email', [
            'locale' => $locale,
            'email' => $email !== '' ? $email : null,
            'maskedEmail' => $this->maskEmail($email),
            'smtpEnabled' => (bool) Settings::get('smtp_enabled', false),
        ]);
    }

    public function showVerified(string $locale)
    {
        if (Auth::check()) {
            return redirect()->to("/{$locale}");
        }

        return view('auth.verified', compact('locale'));
    }

    private function maskEmail(?string $email): ?string
    {
        if (! $email || ! str_contains($email, '@')) {
            return null;
        }

        [$name, $domain] = explode('@', $email, 2);

        if ($name === '') {
            return null;
        }

        return mb_substr($name, 0, 1).str_repeat('•', max(3, mb_strlen($name) - 1)).'@'.$domain;
    }
}
