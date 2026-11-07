<?php

namespace App\Services;

use App\Mail\VerifyAccountMail;
use App\Models\Petition;
use App\Models\Signature;
use App\Models\User;
use App\Models\UserLevel;
use App\Support\AppLog;
use App\Support\Settings;
use App\Support\Spam;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class PetitionSigning
{
    /**
     * Single source of truth for guest sign-form validation rules.
     * The guest form posts the agreement checkboxes as 'on'/'1'.
     *
     * @return array<string,array<int,string>>
     */
    public static function guestRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:60'],
            'email' => ['required', 'email', 'max:190'],
            'comment' => ['nullable', 'string', 'max:500'],
            'agree1' => ['required', 'in:on,1'],
            'agree2' => ['required', 'in:on,1'],
            'agree3' => ['required', 'in:on,1'],
        ];
    }

    /**
     * Single source of truth for authenticated sign-form validation rules.
     * The authed form posts the agreement checkboxes as 'agree'.
     *
     * @return array<string,array<int,string>>
     */
    public static function authedRules(): array
    {
        return [
            'comment' => ['nullable', 'string', 'max:500'],
            'agree1' => ['required', 'in:agree'],
            'agree2' => ['required', 'in:agree'],
            'agree3' => ['required', 'in:agree'],
        ];
    }

    public static function signAsGuest(Request $request, Petition $petition, string $locale): RedirectResponse
    {
        $tr = $petition->translationOrFallback($locale, default_locale());

        $data = $request->validate(self::guestRules());

        if (! Spam::verifyTurnstile($request->input('cf-turnstile-response'))) {
            toast(__('sign.captcha_failed'), 'error');

            return back()->withInput();
        }

        $identity = ($data['name'] ?? '').' '.($data['email'] ?? '');

        if (Spam::isSpam($data['comment'] ?? '', Spam::reviewOwned()) || Spam::isSpam($identity, Spam::reviewOwned(), true)) {
            Spam::log('signature', json_encode($data));

            toast(__('sign.suspicious'), 'error');

            return back()->withInput();
        }

        $email = strtolower(trim($data['email']));

        $existingUser = User::where('email', $email)->first();
        $needsVerification = (bool) Settings::get('require_email_verification', false, 'global');
        $thanks = function () use ($petition, $tr, $needsVerification) {
            session()->forget("sign.{$petition->id}");

            return redirect()->route('petition.thanks', [
                'locale' => $tr->locale,
                'slug' => $tr->slug,
                'id' => $petition->id,
            ])->with('petition_verified', ! $needsVerification);
        };

        if ($existingUser) {
            // Only an unverified account with verification on gets a row: it stays
            // unconfirmed until the inbox owner verifies. Verified accounts get nothing, since a guest form
            // can't prove email ownership. Every outcome returns the same thanks
            // redirect so the response never reveals account or signature state.
            $alreadySigned = Signature::where('petition_id', $petition->id)
                ->where(fn ($q) => $q->where('email', $email)->orWhere('user_id', $existingUser->id))
                ->exists();

            if (! $existingUser->verified && $needsVerification && ! $alreadySigned) {
                try {
                    Signature::create([
                        'petition_id' => $petition->id,
                        'email' => $email,
                        'user_id' => $existingUser->id,
                        'name' => $data['name'],
                        'locale' => $locale,
                        'text' => $data['comment'] ?? 'I support this petition',
                        'confirmed' => false,
                        'ip_address' => request()->ip(),
                    ]);
                } catch (QueryException $e) {
                    if (($e->errorInfo[0] ?? null) !== '23000') {
                        throw $e;
                    }
                }
            }

            $sentAt = $existingUser->verification_token_sent_at;
            // Re-send at most once per token lifetime (48h, see AuthController::verify).
            if (! $existingUser->verified && $needsVerification && Settings::get('smtp_enabled', false)
                && (! $sentAt || $sentAt->lt(now()->subHours(48)))) {
                try {
                    $existingUser->forceFill([
                        'pending_email' => null, // token goes to the current address, not a pending one
                        'verification_token' => Str::random(64),
                        'verification_token_sent_at' => now(),
                    ])->save();
                    Mail::to($existingUser->email)->queue(new VerifyAccountMail($existingUser, $locale));
                } catch (\Throwable $e) {
                    AppLog::error('Verification email failed', $e->getMessage(), 'petition.sign');
                }
            }

            return $thanks();
        }

        $alreadySigned = Signature::where('petition_id', $petition->id)
            ->where('email', $email)
            ->exists();

        if ($alreadySigned) {
            return $thanks();
        }

        try {
            $counted = false;
            $previousCount = null;
            $mailUser = null;

            $response = DB::transaction(function () use ($data, $email, $locale, $petition, $tr, $needsVerification, &$counted, &$previousCount, &$mailUser) {
                $userLevel = UserLevel::where('name', 'user')->first();

                $user = User::create([
                    'name' => $data['name'],
                    'first_name' => $data['name'],
                    'last_name' => null,
                    'email' => $email,
                    'locale' => $locale,
                    'ip' => request()->ip(),
                    'level_id' => $userLevel?->id,
                    'verified' => ! $needsVerification,
                    'verification_token' => Str::random(64),
                    'verification_token_sent_at' => now(),
                ]);

                if ($needsVerification && Settings::get('smtp_enabled', false)) {
                    $mailUser = $user->fresh();
                }

                $sig = Signature::firstOrCreate(
                    ['petition_id' => $petition->id, 'email' => $email],
                    [
                        'user_id' => $user->id,
                        'name' => $data['name'],
                        'locale' => $locale,
                        'text' => $data['comment'] ?? 'I support this petition',
                        'confirmed' => $user->verified,
                        'ip_address' => request()->ip(),
                    ]
                );

                if ($sig->wasRecentlyCreated && $sig->confirmed) {
                    $counted = true;
                    $previousCount = PetitionStats::incrementMany([$petition->id => 1])[$petition->id];
                }

                AppLog::info(
                    'Petition signed',
                    'Petition ID: '.$petition->id.' | User: '.$email.' | IP: '.request()->ip(),
                    'petition.sign'
                );

                session()->forget("sign.{$petition->id}");

                return redirect()->route('petition.thanks', [
                    'locale' => $tr->locale,
                    'slug' => $tr->slug,
                    'id' => $petition->id,
                ])->with('petition_verified', (bool) $user->verified);
            });
        } catch (QueryException $e) {
            if (($e->errorInfo[0] ?? null) === '23000') {
                AppLog::error('Guest sign duplicate', $e->getMessage(), 'petition.sign');

                return $thanks();
            }

            throw $e;
        }

        if ($counted) {
            PetitionStats::notifyMilestoneIfCrossed($petition->id, $previousCount);
        }

        if ($mailUser) {
            $capKey = 'mail:verify-guest:'.today()->toDateString();
            Cache::add($capKey, 0, now()->endOfDay());
            $sentToday = Cache::increment($capKey);
            $cap = (int) Settings::get('verify_guest_daily_cap', 200, 'global');

            if ($sentToday > $cap) {
                if ($sentToday === $cap + 1) {
                    AppLog::error('Guest verification mail daily cap reached', 'cap '.$cap, 'petition.sign');
                }

                return $response;
            }

            try {
                Mail::to($mailUser->email)->queue(new VerifyAccountMail($mailUser, $locale));
            } catch (\Throwable $e) {
                AppLog::error('Verification email failed', $e->getMessage(), 'petition.sign');
            }
        }

        return $response;
    }

    public static function signAsAuthed(Request $request, string $locale, Petition $petition): RedirectResponse
    {
        $data = $request->validate(self::authedRules());

        $identity = ($data['name'] ?? '').' '.($data['email'] ?? '');

        if (Spam::isSpam($data['comment'] ?? '', Spam::reviewOwned()) || Spam::isSpam($identity, Spam::reviewOwned(), true)) {
            Spam::log('signature', json_encode($data));

            toast(__('sign.suspicious'), 'error');

            return back()->withInput();
        }

        $u = auth()->user();

        try {
            $counted = false;
            $previousCount = null;

            $response = DB::transaction(function () use ($data, $locale, $petition, $u, &$counted, &$previousCount) {
                $dup = Signature::where('petition_id', $petition->id)->where(fn ($q) => $q->where('email', $u->email)->orWhere('user_id', $u->id))->exists();
                if ($dup) {
                    $tr = $petition->translationOrFallback($locale);
                    abort_if(! $tr, 404);
                    toast(__('sign.already_signed'), 'info');

                    return redirect()->route('petition.thanks', ['locale' => $tr->locale, 'slug' => $tr->slug, 'id' => $petition->id])->with('petition_verified', true);
                }
                $sig = Signature::create(
                    [
                        'petition_id' => $petition->id,
                        'email' => $u->email,
                        'user_id' => $u->id,
                        'name' => $u->display_name, // frozen snapshot; live display via Signature::display_name
                        'locale' => $locale,
                        'text' => $data['comment'] ?? 'I support this petition',
                        'ip_address' => request()->ip(),
                    ]
                );

                $counted = true;
                $previousCount = PetitionStats::incrementMany([$petition->id => 1])[$petition->id];

                session()->forget("sign.{$petition->id}");

                $tr = $petition->translationOrFallback($locale);

                abort_if(! $tr, 404);

                AppLog::info(
                    'Petition signed',
                    'Petition ID: '.$petition->id.' | User: '.$u->email.' | IP: '.request()->ip(),
                    'petition.sign'
                );

                toast(__('sign.thanks_toast'), 'success');

                return redirect()->route('petition.thanks', [
                    'locale' => $tr->locale,
                    'slug' => $tr->slug,
                    'id' => $petition->id,
                ])->with('petition_verified', true);
            });
        } catch (QueryException $e) {

            if (($e->errorInfo[0] ?? null) !== '23000' && ! str_contains($e->getMessage(), 'UNIQUE')) {
                throw $e;
            }

            $sig = Signature::where('petition_id', $petition->id)
                ->where(function ($q) use ($u) {
                    $q->where('email', $u->email)->orWhere('user_id', $u->id);
                })
                ->first();

            if ($sig) {
                toast(__('sign.already_signed'), 'info');
            } else {
                throw $e;
            }

            $tr = $petition->translationOrFallback($locale, default_locale());

            abort_if(! $tr, 404);

            return redirect()->route('petition.thanks', [
                'locale' => $tr->locale,
                'slug' => $tr->slug,
                'id' => $petition->id,
            ])->with('petition_verified', true);
        }

        if ($counted) {
            PetitionStats::notifyMilestoneIfCrossed($petition->id, $previousCount);
        }

        return $response;
    }

    /**
     * Mark the account verified and confirm its pending signatures. Shared by
     * the email link, Google sign-in and the admin drawer, and the only writer
     * of verified=true on that transition. A no-op once the user is verified.
     * Admin rejections are is_spam=1, so the is_spam=false filters below skip them.
     */
    public static function markVerified(User $user): void
    {
        $previousCounts = DB::transaction(function () use ($user) {

            $locked = User::whereKey($user->id)->where('verified', false)->lockForUpdate()->first();

            if (! $locked) {
                return [];
            }

            $pending = DB::table('signatures')
                ->where('user_id', $user->id)
                ->where('confirmed', false)
                ->where('is_spam', false)
                ->lockForUpdate()
                ->selectRaw('petition_id, COUNT(*) as total')
                ->groupBy('petition_id')
                ->get();

            $locked->verified = true;
            $locked->verification_token = null;
            $locked->save();

            DB::table('signatures')
                ->where('user_id', $user->id)
                ->where('confirmed', false)
                ->where('is_spam', false)
                ->update(['confirmed' => true, 'updated_at' => now()]);

            $increments = [];
            foreach ($pending as $row) {
                $increments[(int) $row->petition_id] = (int) $row->total;
            }

            return PetitionStats::incrementMany($increments);
        });

        // After commit: notify owners of any milestone crossed by this
        // bulk confirmation (e.g. 98 -> 103 crosses 100). Previously no
        // milestone email fired on bulk confirms at all.
        foreach ($previousCounts as $petitionId => $previousCount) {
            PetitionStats::notifyMilestoneIfCrossed($petitionId, $previousCount);
        }
    }
}
