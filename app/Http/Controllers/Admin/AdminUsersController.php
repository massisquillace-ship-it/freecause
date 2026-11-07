<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\InteractsWithAdminLists;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AdminUserBanRequest;
use App\Http\Requests\Admin\AdminUserBulkRequest;
use App\Http\Requests\Admin\AdminUserSaveRequest;
use App\Mail\VerifyAccountMail;
use App\Models\Language;
use App\Models\User;
use App\Models\UserLevel;
use App\Services\OrganizationLifecycle;
use App\Services\PetitionSigning;
use App\Services\WordPressUserDeletionService;
use App\Support\AppLog;
use App\Support\ApproxRows;
use App\Support\Locale;
use App\Support\Settings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminUsersController extends Controller
{
    use ApproxRows;
    use InteractsWithAdminLists;

    // Each delete boots WordPress in a child process (up to 20 s), so keep one request well under the timeout.
    private const BULK_DELETE_MAX = 25;

    public function index(Request $request)
    {
        $filters = $this->filters($request);

        $users = $this->filteredQuery(User::with('level'), $filters)
            ->orderByDesc('id')
            ->simplePaginate(25)
            ->withQueryString();
        $approxTotal = $this->approxTableRows('users');

        $levels = UserLevel::pluck('name', 'name')->toArray();
        $levels = ['' => 'any level'] + $levels;
        $editableLevels = array_filter($levels, fn ($name, $code) => $code !== 'banned', ARRAY_FILTER_USE_BOTH);

        $languages = Language::where('is_active', true)
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();

        $locales = ['' => 'any language'];

        foreach ($languages as $lang) {
            $locales[$lang->code] = $lang->name;
        }

        $selectedUser = null;

        if ($request->query('select')) {
            $selectedUser = User::with('level')
                ->find((int) $request->query('select'));
        }

        return view('admin.users.index', compact(
            'users',
            'filters',
            'levels',
            'editableLevels',
            'locales',
            'approxTotal',
            'selectedUser'
        ));
    }

    public function export(Request $request): StreamedResponse
    {
        $filters = $this->filters($request);

        $ids = $this->filteredQuery(User::query(), $filters)
            ->orderByDesc('id')
            ->limit(10000)
            ->pluck('id')
            ->all();

        return response()->streamDownload(function () use ($ids) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['id', 'email', 'name', 'locale', 'level', 'verified', 'created_at']);
            User::query()
                ->whereIn('id', $ids)
                ->orderByDesc('id')
                ->chunk(500, function ($rows) use ($handle) {

                    $levelNames = UserLevel::whereIn('id', $rows->pluck('level_id')->filter()->unique()->all())
                        ->pluck('name', 'id');

                    foreach ($rows as $row) {
                        fputcsv($handle, [
                            $row->id,
                            csv_safe($row->email),
                            csv_safe($row->name),
                            csv_safe($row->locale),
                            $levelNames[$row->level_id] ?? '',
                            $row->verified ? 1 : 0,
                            $row->created_at,
                        ]);
                    }
                });
            fclose($handle);
        }, 'users-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv']);
    }

    private function filters(Request $request): array
    {
        return [
            'id' => trim((string) $request->query('id', '')),
            'first_name' => trim((string) $request->query('first_name', '')),
            'last_name' => trim((string) $request->query('last_name', '')),
            'nickname' => trim((string) $request->query('nickname', '')),
            'email' => trim((string) $request->query('email', '')),
            'email_mode' => trim((string) $request->query('email_mode', 'prefix')),
            'ip' => trim((string) $request->query('ip', '')),
            'level' => trim((string) $request->query('level', '')),
            'locale' => trim((string) $request->query('locale', '')),
            'status' => trim((string) $request->query('status', '')),
            'registered_from' => trim((string) $request->query('registered_from', '')),
            'registered_to' => trim((string) $request->query('registered_to', '')),
        ];
    }

    private function filteredQuery(Builder $q, array $filters): Builder
    {
        if ($filters['id'] !== '') {
            $q->where('id', (int) $filters['id']);
        }

        if ($filters['first_name'] !== '') {
            $q->where('first_name', 'like', "{$filters['first_name']}%");
        }

        if ($filters['last_name'] !== '') {
            $q->where('last_name', 'like', "{$filters['last_name']}%");
        }

        if ($filters['nickname'] !== '') {
            $q->where('nickname', 'like', "{$filters['nickname']}%");
        }

        if ($filters['email'] !== '') {
            if ($filters['email_mode'] === 'domain') {
                $domain = strtolower(trim($filters['email']));
                $domain = ltrim(str_contains($domain, '@') ? substr($domain, strrpos($domain, '@') + 1) : $domain, '@');
                if ($domain !== '') {
                    $q->where('email_domain', $domain);
                } else {
                    $q->where('email', 'like', "{$filters['email']}%");
                }
            } else {
                $q->where('email', 'like', "{$filters['email']}%");
            }
        }

        if ($filters['locale'] !== '') {
            $fullLocale = Locale::toFull($filters['locale']);
            $q->where('locale', $fullLocale);
        }

        if ($filters['ip'] !== '') {
            $q->where('ip', 'like', "{$filters['ip']}%");
        }

        if ($filters['level'] !== '') {
            $q->whereHas('level', function ($query) use ($filters) {
                $query->where('name', $filters['level']);
            });
        }

        if ($filters['status'] === 'verified') {
            $q->where('verified', 1);
        } elseif ($filters['status'] === 'unverified') {
            $q->where('verified', 0);
        }

        if ($filters['registered_from'] !== '') {
            $q->whereDate('created_at', '>=', $filters['registered_from']);
        }

        if ($filters['registered_to'] !== '') {
            $q->whereDate('created_at', '<=', $filters['registered_to']);
        }

        return $q;
    }

    public function save(AdminUserSaveRequest $request)
    {
        $data = $request->validated();

        $user = User::with('level')->find($data['id']);

        if (! $user) {
            return back()->withErrors(['id' => 'User not found']);
        }

        $isSelf = admin_user()?->id === $user->id;

        if ($user->level?->is_system && ! (admin_user()?->loadMissing('level')?->level?->is_system ?? false)) {
            return back()->withErrors(['id' => 'You cannot edit a system-level user.'])->withInput();
        }

        $emailChanged = array_key_exists('email', $data)
            && strtolower(trim((string) $data['email'])) !== strtolower(trim((string) $user->email));

        if (! $isSelf && ($emailChanged || ! empty($data['password'])) && $user->canAccessAdmin()
            && ! (admin_user()?->loadMissing('level')?->level?->is_system ?? false)) {
            return back()->withErrors(['id' => 'Only a system-level admin can change the email or password of another admin.'])->withInput();
        }

        if ($isSelf && $request->filled('level') && $data['level'] !== ($user->level->name ?? null)) {
            return back()->withErrors(['level' => 'You cannot change your own role.'])->withInput();
        }

        $levelChangesRequested = $request->filled('level') && $data['level'] !== ($user->level->name ?? null);

        if ($levelChangesRequested) {
            $actorLevel = admin_user()?->loadMissing('level')?->level;

            if (! ($actorLevel?->is_system ?? false)) {
                return back()->withErrors(['level' => 'You do not have permission to change user roles.'])->withInput();
            }

            if ($user->level?->is_system) {
                return back()->withErrors(['level' => 'You cannot change the role of a system-level user.'])->withInput();
            }
        }

        if ($isSelf && $request->has('verified') && ! $request->boolean('verified')) {
            return back()->withErrors(['verified' => 'You cannot un-verify your own account.'])->withInput();
        }

        $wasVerified = (bool) $user->verified;
        $nowVerified = $request->has('verified') ? $request->boolean('verified') : $wasVerified;

        $needsReverification = ($wasVerified && ! $nowVerified) || ($emailChanged && $nowVerified);

        if ($needsReverification) {
            $nowVerified = false;
        }

        if ($needsReverification && ! Settings::get('smtp_enabled', false)) {
            return back()->withErrors([
                'verified' => $emailChanged
                    ? 'Changing a verified user\'s email needs a verification email, but sending is off. Enable SMTP first.'
                    : 'Un-verifying needs a verification email, but email sending is disabled. Enable SMTP first, or suspend the account instead.',
            ])->withInput();
        }

        try {
            DB::transaction(function () use ($request, $data, $user, $levelChangesRequested, $needsReverification, $nowVerified, $wasVerified, $emailChanged) {
                if ($levelChangesRequested) {
                    $level = UserLevel::where('name', $data['level'])->first();
                    if ($level) {
                        $user->level_id = $level->id;
                    }
                }

                if (! empty($data['password'])) {
                    $user->password = Hash::make($data['password']);
                    $user->password_changed_at = now();
                    $user->setRememberToken(Str::random(60));
                }

                $user->name = $data['username'] ?? $user->name;
                $user->first_name = $request->has('first_name') ? $data['first_name'] : $user->first_name;
                $user->last_name = $request->has('last_name') ? $data['last_name'] : $user->last_name;
                $user->nickname = $request->has('nickname') ? $data['nickname'] : $user->nickname;
                $user->city = $request->has('city') ? $data['city'] : $user->city;
                $user->identify_mode = $data['identify_mode'] ?? $user->identify_mode;
                $user->email = $data['email'] ?? $user->email;
                $user->locale = isset($data['locale']) ? Locale::toFull($data['locale']) : $user->locale;
                // A false -> true flip is left to markVerified() after commit, so verified
                // and the pending-signature confirm land in one transaction.
                $user->verified = $nowVerified && $wasVerified;

                // An old self-service link must not overwrite an admin-set address or verification.
                if ($needsReverification || $emailChanged || $nowVerified) {
                    $user->pending_email = null;
                }

                if ($needsReverification) {
                    $user->verification_token = Str::random(64);
                    $user->verification_token_sent_at = now();
                } elseif ($nowVerified) {
                    $user->verification_token = null;
                    $user->verification_token_sent_at = null;
                }

                $user->save();
            });
        } catch (\Throwable $e) {
            return back()->withInput()->withErrors(['id' => 'Could not save user.']);
        }

        if (! $wasVerified && $nowVerified) {
            try {
                PetitionSigning::markVerified($user);
            } catch (\Throwable $e) {
                return back()->withInput()->withErrors(['id' => 'Could not save user.']);
            }
        }

        if ($needsReverification && Settings::get('smtp_enabled', false)) {
            try {
                Mail::to($user->email)->queue(new VerifyAccountMail($user, $user->locale ?: default_locale()));
            } catch (\Throwable $e) {
                AppLog::error('Re-verification email failed', 'User ID: '.$user->id.' | '.$e->getMessage(), 'admin.users');
            }
        }

        if ($user->wasChanged('level_id')) {
            Cache::forget('level:user_counts');
        }

        return redirect()
            ->route('admin.users', ['select' => $user->id])
            ->with('success', 'saved');
    }

    public function resendVerification(User $user)
    {
        if ($user->verified) {
            return back()->with('error', 'This account is already verified.');
        }

        if (! Settings::get('smtp_enabled', false)) {
            return back()->with('error', 'Email sending is disabled. Enable SMTP in site settings first.');
        }

        try {
            $user->pending_email = null; // token goes to the current address, not a pending one
            $user->verification_token = Str::random(64);
            $user->verification_token_sent_at = now();
            $user->save();

            Mail::to($user->email)->queue(new VerifyAccountMail($user, $user->locale ?: default_locale()));

            AppLog::info(
                'Verification email resent by admin',
                'User ID: '.$user->id.' | Admin ID: '.(admin_user()?->id ?? 0),
                'admin.users'
            );
        } catch (\Throwable $e) {
            AppLog::error(
                'Admin verification resend failed',
                'User ID: '.$user->id.' | '.$e->getMessage(),
                'admin.users'
            );

            return back()->with('error', 'Verification email could not be sent.');
        }

        return redirect()
            ->route('admin.users', array_filter([
                'select' => $user->id,
                'locale' => request()->filled('locale') ? request('locale') : null,
            ]))
            ->with('success', 'saved');
    }

    public function bulkBan(AdminUserBanRequest $request)
    {
        $data = $request->validated();

        $ids = $this->adminBulkIds($request, 'users');
        if (empty($ids)) {
            return response()->json(['ok' => false]);
        }

        $banned = $this->banIds($ids, $data);
        if ($banned === null) {
            return response()->json(['ok' => false, 'error' => 'banned role missing']);
        }

        $skipped = count($ids) - $banned;

        if ($banned === 0) {
            return response()->json([
                'ok' => false,
                'error' => 'No eligible accounts: already banned, your own and admin accounts cannot be banned.',
                'skipped' => $skipped,
            ]);
        }

        return response()->json([
            'ok' => true,
            'banned' => $banned,
            'skipped' => $skipped,
            'msg' => $skipped > 0
                ? "Banned {$banned} user(s); {$skipped} skipped (already banned, your own or admin accounts)."
                : "Banned {$banned} user(s).",
        ]);
    }

    public function bulkUnban(Request $request)
    {
        $ids = $this->adminBulkIds($request, 'users');
        if (empty($ids)) {
            return response()->json(['ok' => false]);
        }

        if ($this->unbanIds($ids) === null) {
            return response()->json(['ok' => false, 'error' => 'role missing']);
        }

        return response()->json(['ok' => true]);
    }

    /** Returns the number of users banned, or null when the banned role is missing. */
    private function banIds(array $ids, array $data): ?int
    {
        $bannedLevel = UserLevel::where('name', 'banned')->first();
        if (! $bannedLevel) {
            return null;
        }

        $days = (int) ($data['days'] ?? 0);
        $reason = trim((string) ($data['reason'] ?? '')) ?: null;
        $until = $days > 0 ? now()->addDays($days) : null;

        // Skip anyone who can reach the admin panel (same rule as User::canAccessAdmin()).
        $banned = User::whereIn('id', $ids)
            ->where('id', '!=', admin_user()?->id)
            ->where(function ($q) use ($bannedLevel) {
                $q->whereNull('level_id')->orWhere('level_id', '!=', $bannedLevel->id);
            })
            ->whereDoesntHave('level', function ($q) {
                $q->where('is_system', true)->orWhereHas('permissions');
            })
            ->update([
                'level_id' => $bannedLevel->id,
                'banned_reason' => $reason,
                'banned_until' => $until,
            ]);

        if ($banned > 0) {
            Cache::forget('level:user_counts');
        }

        return $banned;
    }

    /** Returns the number of users unbanned, or null when a role is missing. */
    private function unbanIds(array $ids): ?int
    {
        $userLevel = UserLevel::where('name', 'user')->first();
        $bannedLevelId = UserLevel::where('name', 'banned')->value('id');

        if (! $userLevel || ! $bannedLevelId) {
            return null;
        }

        $unbanned = User::whereIn('id', $ids)
            ->where('id', '!=', admin_user()?->id)
            ->where('level_id', $bannedLevelId)
            ->whereDoesntHave('level', fn ($q) => $q->where('is_system', true))
            ->update([
                'level_id' => $userLevel->id,
                'banned_reason' => null,
                'banned_until' => null,
            ]);

        if ($unbanned > 0) {
            Cache::forget('level:user_counts');
        }

        return $unbanned;
    }

    public function bulkDelete(Request $request)
    {
        if (! admin_can('users', 'delete')) {
            return response()->json(['ok' => false, 'error' => 'You do not have permission to delete users.'], 403);
        }

        $ids = $this->adminBulkIds($request, 'users');
        if (empty($ids)) {
            return response()->json(['ok' => false]);
        }

        if (count($ids) > self::BULK_DELETE_MAX) {
            return response()->json(['ok' => false, 'error' => 'Select at most '.self::BULK_DELETE_MAX.' users per delete.'], 422);
        }

        $adminLevelId = UserLevel::where('name', 'admin')->value('id');
        if (! $adminLevelId) {
            return response()->json(['ok' => false, 'error' => 'admin role missing']);
        }

        $bannedLevelId = UserLevel::where('name', 'banned')->value('id');

        $query = User::whereIn('id', $ids)
            ->where('id', '!=', admin_user()?->id)
            ->where(function ($q) use ($adminLevelId, $bannedLevelId) {
                $q->whereNull('level_id')
                    ->orWhereNotIn('level_id', array_filter([$adminLevelId, $bannedLevelId]));
            })
            // Same rule as banIds(): skip anyone who can reach the admin panel.
            ->whereDoesntHave('level', fn ($q) => $q->where('is_system', true)->orWhereHas('permissions'));

        $users = $query->get();

        if ($users->isEmpty()) {
            return response()->json([
                'ok' => false,
                'error' => 'No eligible accounts: banned users and anyone with admin access cannot be deleted here.',
                'requested' => count($ids),
            ]);
        }

        $deleted = 0;
        $failed = 0;

        foreach ($users as $user) {
            try {
                app(WordPressUserDeletionService::class)->delete($user);
            } catch (\Throwable $e) {
                $failed++;
                AppLog::error('Bulk delete skipped: WordPress cleanup failed', 'User ID: '.$user->id.' | '.$e->getMessage(), 'admin.users');

                continue;
            }

            $deleted += DB::transaction(function () use ($user) {
                app(OrganizationLifecycle::class)->suspendForDeletedOwner($user);

                return $user->delete() ? 1 : 0;
            }, 3);
        }

        if ($deleted > 0) {
            Cache::forget('level:user_counts');
        }

        return response()->json([
            'ok' => true,
            'deleted' => $deleted,
            'skipped' => count($ids) - $deleted,
            'msg' => "Deleted {$deleted} user(s)".(count($ids) > $users->count() ? '; protected accounts (banned or admin access) were skipped' : '').($failed ? "; {$failed} failed WordPress cleanup and were kept" : ''),
        ]);
    }

    public function bulkAction(AdminUserBulkRequest $request)
    {
        $data = $request->validated();

        return match ($data['action']) {
            'ban' => $this->bulkBan(app(AdminUserBanRequest::class)),
            'unban' => $this->bulkUnban($request),
            'delete' => $this->bulkDelete($request),
            default => response()->json(['ok' => false, 'error' => 'Unknown action'], 400),
        };
    }

    public function ban(AdminUserBanRequest $request, User $user)
    {
        $banned = $this->banIds([$user->id], $request->validated());

        if ($banned === null) {
            return response()->json(['ok' => false, 'error' => 'banned role missing'], 422);
        }

        if ($banned === 0) {
            return $user->level?->name === 'banned'
                ? response()->json(['ok' => false, 'error' => 'User is already banned.'], 409)
                : response()->json(['ok' => false, 'error' => 'You cannot ban yourself or an admin account.'], 403);
        }

        return response()->json(['ok' => true]);
    }

    public function unban(Request $request, User $user)
    {
        $unbanned = $this->unbanIds([$user->id]);

        if ($unbanned === null) {
            return response()->json(['ok' => false, 'error' => 'role missing'], 422);
        }

        if ($unbanned === 0 && $user->level?->name === 'banned') {
            return response()->json(['ok' => false, 'error' => 'You cannot unban yourself or a system-level user.'], 403);
        }

        return response()->json(['ok' => true]);
    }
}
