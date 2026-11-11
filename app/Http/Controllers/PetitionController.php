<?php

namespace App\Http\Controllers;

use App\Http\Middleware\ConditionalLogging;
use App\Http\Requests\PetitionRequest;
use App\Models\Category;
use App\Models\Language;
use App\Models\Organization;
use App\Models\Petition;
use App\Models\PetitionTranslation;
use App\Models\Signature;
use App\Policies\PetitionPolicy;
use App\Services\PageContentService;
use App\Services\PetitionSigning;
use App\Services\PetitionStats;
use App\Support\AppLog;
use App\Support\CacheKeys;
use App\Support\CacheVersion;
use App\Support\DefaultComments;
use App\Support\EdgeCachePurge;
use App\Support\ImageVariants;
use App\Support\ReviewKeywords;
use App\Support\Slug;
use App\Support\Spam;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Intervention\Image\Laravel\Facades\Image;

class PetitionController extends Controller
{
    private function requireOwner(Petition $petition): void
    {
        if ($petition->organization_id && DB::transactionLevel() > 0) {
            $petition->setRelation('organization', Organization::whereKey($petition->organization_id)->lockForUpdate()->first());
        }

        abort_unless(auth()->check() && app(PetitionPolicy::class)->update(auth()->user(), $petition), 403);
    }

    /**
     * Export authorization: the petition owner OR a logged-in admin.
     * Matches the legacy rule (owner_id == user || VALID_ADMIN) for
     * signature downloads. Kept separate from requireOwner(), which uses organization roles for edit/update.
     */
    private function requireOwnerOrAdmin(Petition $petition): void
    {
        $isOwner = auth()->check() && (int) $petition->user_id === (int) auth()->id();
        $isAdmin = admin_can('signatures', 'view');

        abort_unless($isOwner || $isAdmin, 403);
    }

    private function requireEditable(Petition $petition): void
    {
        $this->requireOwner($petition);
        abort_unless($this->isEditable($petition), 403, 'Petition can no longer be edited after another person has signed.');
    }

    private function isEditable(Petition $petition): bool
    {
        return $petition->is_active
            && in_array($petition->status, ['published', 'draft'], true)
            && ! $petition->signatures()->where('confirmed', true)->where('is_spam', false)->where(fn ($q) => $q->where('user_id', '!=', $petition->user_id)->orWhereNull('user_id'))->exists();
    }

    private function resolveTrOrRedirect(string $routeName, string $locale, string $slug, Petition $petition, array $extra = [])
    {
        $tr = $petition->translationOrFallback($locale, default_locale());

        abort_if(! $tr, 404);

        if ($tr->slug !== $slug || $tr->locale !== $locale) {
            return redirect()->route($routeName, array_merge([
                'locale' => $tr->locale,
                'slug' => $tr->slug,
                'id' => $petition->id,
            ], $extra), 301);
        }

        return $tr;
    }

    private function hasSigned(Petition $petition): bool
    {
        $u = auth()->user();

        return Signature::where('petition_id', $petition->id)
            ->where(fn ($q) => $q->where('email', $u->email)->orWhere('user_id', $u->id))
            ->exists();
    }

    private function normalizeTags(?string $tags): ?string
    {
        $normalized = collect(explode(',', (string) $tags))
            ->map(fn ($tag) => trim($tag))
            ->filter()
            ->take(10)
            ->implode(',');

        return $normalized ?: null;
    }

    private function goalProgress(Petition $petition): array
    {
        $goalTotal = (int) ($petition->goal_signatures ?? 100);
        $goalCurrent = (int) ($petition->signature_count ?? 0);
        $pct = $goalTotal > 0 ? min(100, round(($goalCurrent / $goalTotal) * 100)) : 0;

        return [$goalTotal, $goalCurrent, $pct];
    }

    private function storeCoverImage(UploadedFile $file): string
    {
        $size = @getimagesize($file->getPathname());
        if ($size !== false && $size[0] * $size[1] > 40_000_000) {
            throw ValidationException::withMessages([
                'image' => __('create.err_image_pixels'),
            ]);
        }

        $path = $file->store('petitions', 'public');
        $absolute = Storage::disk('public')->path($path);

        try {
            $img = Image::read($absolute)->orient();
            $img->scaleDown(width: 800, height: 800)->save($absolute);
            ImageVariants::ensure($absolute);

            if (! Storage::disk('public')->exists($path)) {
                throw ValidationException::withMessages([
                    'image' => __('create.err_image_save_failed'),
                ]);
            }
        } catch (\Throwable $e) {
            Storage::disk('public')->delete($path);
            ImageVariants::purge($path);

            if ($e instanceof ValidationException) {
                throw $e;
            }

            throw ValidationException::withMessages([
                'image' => __('create.err_image_processing_failed'),
            ]);
        }

        return $path;
    }

    /** Base query for the public petition listing; cache:warm uses it too. */
    public static function listingQuery(string $locale): Builder
    {
        return Petition::query()
            ->select([
                'petitions.id',
                'petitions.signature_count',
                'petitions.goal_signatures',
                'petitions.category_id',
                'petitions.cover_image',
            ])
            ->addSelect(['pt_locale.title as tr_title', 'pt_locale.slug as tr_slug'])
            ->join('petition_translations as pt_locale', function ($join) use ($locale) {
                $join->on('pt_locale.petition_id', '=', 'petitions.id')
                    ->where('pt_locale.locale', '=', $locale);
            })
            ->whereNotNull('pt_locale.title')
            ->visible();
    }

    public static function applySearch(Builder $query, string $search): Builder
    {
        $driver = DB::connection()->getDriverName();
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $terms = PetitionTranslation::booleanTerms($search);
            $query->where(function ($q) use ($terms) {
                if ($terms === '') {
                    $q->whereRaw('1 = 0');
                } else {
                    $q->whereRaw('MATCH(pt_locale.title) AGAINST(? IN BOOLEAN MODE)', [$terms]);
                }
            });
        } else {
            $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $search).'%';
            $query->where(function ($q) use ($like) {
                $q->where('pt_locale.title', 'LIKE', $like);
            });
        }

        return $query;
    }

    public function index(string $locale)
    {
        $locale = normalize_locale($locale);

        $search = trim((string) request('q', ''));
        $pageLimit = request('goal') === 'reached' ? 10000 : 500;
        $page = max(1, min((int) request('page', 1), $pageLimit));

        $categories = Category::cachedActive($locale);

        $goalReached = request('goal') === 'reached';
        $query = self::listingQuery($locale)->when($goalReached, fn ($q) => $q->goalReached());
        $goalCacheSuffix = $goalReached ? ':goal-reached' : '';

        if ($search !== '') {
            self::applySearch($query, $search);
            $query->orderByDesc('petitions.signature_count');
            $petitions = $query->paginate(15, ['*'], 'page', $page)->withQueryString();
        } else {
            $sort = in_array(request('sort'), ['signed', 'newest', 'trending'], true) ? request('sort') : 'newest';

            if ($sort === 'signed') {
                $petitions = $query
                    ->orderByDesc('petitions.signature_count')
                    ->orderByDesc('petitions.id')
                    ->paginate(15, ['*'], 'page', $page)
                    ->withQueryString();
            } elseif ($sort === 'trending') {
                $recent = Signature::query()
                    ->where('confirmed', true)
                    ->where('is_spam', false)
                    ->where('created_at', '>=', now()->subDays(7))
                    ->selectRaw('petition_id, count(*) as recent_count')
                    ->groupBy('petition_id');

                $petitions = $this->cachedListingPage(
                    $query,
                    CacheKeys::petitionsTrending($locale, $page).$goalCacheSuffix,
                    now()->addMinutes(10),
                    $page,
                    fn ($q) => $q->leftJoinSub($recent, 'trend', 'trend.petition_id', '=', 'petitions.id')
                        ->orderByDesc('trend.recent_count')
                        ->orderByDesc('petitions.signature_count')
                        ->orderByDesc('petitions.id')
                );
            } else {
                $petitions = $this->cachedListingPage(
                    $query,
                    CacheKeys::petitionsIndex($locale, $page).$goalCacheSuffix,
                    now()->addSeconds(300),
                    $page,
                    fn ($q) => $q->orderByDesc('petitions.id')
                );
            }
        }

        return view('pages.petitions-list', [
            'pageTitle' => $search ? __('list.search_results', ['q' => $search]) : ($goalReached ? __('home.goal_reached_petitions') : __('list.all_petitions')),
            'heading' => $search ? __('list.search_results', ['q' => $search]) : ($goalReached ? __('home.goal_reached_petitions') : __('list.petitions')),
            'goalReached' => $goalReached,
            'search' => $search,
            'categories' => $categories,
            'sort' => $search ? null : (in_array(request('sort'), ['signed', 'newest', 'trending'], true) ? request('sort') : 'newest'),
            'petitions' => $petitions,
            'petitionTitle' => fn ($row) => $row->tr_title,
            'petitionUrl' => fn ($row) => lroute('petition.show', [
                'slug' => $row->tr_slug,
                'id' => $row->id,
            ]),
        ]);
    }

    public function show(Request $request, string $locale, string $slug, int $id)
    {
        $locale = normalize_locale($locale);
        $defaultLocale = default_locale();

        $petition = Petition::query()
            ->with(['category', 'user', 'translations', 'organization', 'causes'])
            ->where('id', $id)
            ->where(function ($q) {
                $q->where(function ($q2) {
                    $q2->where('status', 'published');
                })
                    ->orWhere(function ($q2) {
                        $q2->where('status', 'draft')->where(function ($draft) {
                            $draft->where('user_id', auth()->id() ?? 0);
                            if (auth()->check()) {
                                $draft->orWhereHas('organization.members', fn ($members) => $members->where('user_id', auth()->id())->whereIn('role', ['owner', 'admin']));
                            }
                        });
                    });
            })
            ->firstOrFail();

        $trOrRedirect = $this->resolveTrOrRedirect('petition.show', $locale, $slug, $petition);
        if ($trOrRedirect instanceof RedirectResponse) {
            return $trOrRedirect;
        }

        if ($petition->status === 'published' && $petition->is_active) {
            $this->recordView($request, $petition);
        }
        $tr = $trOrRedirect;

        $related = cache()->remember(
            "petition:related:{$petition->id}:{$locale}:".CacheVersion::get('list'),
            now()->addMinutes(10),
            function () use ($petition, $locale, $defaultLocale) {
                if (! $petition->category_id) {
                    return collect();
                }

                return Petition::query()
                    ->with('translations')
                    ->select(['petitions.id', 'petitions.signature_count', 'petitions.cover_image'])
                    ->translated($locale, 'pt_locale', 'pt_default', ['title' => 'tr_title', 'slug' => 'tr_slug'], $defaultLocale)
                    ->where('petitions.category_id', $petition->category_id)
                    ->where('petitions.id', '!=', $petition->id)
                    ->where('petitions.status', 'published')
                    ->where('petitions.is_active', 1)
                    ->where(fn ($q) => $q->whereNotNull('pt_locale.title')->orWhereNotNull('pt_default.title'))
                    ->orderByDesc('petitions.signature_count')
                    ->limit(3)
                    ->get();
            }
        );

        if ($petition->status === 'published' && ! $petition->is_active) {
            return response()->view('petition.offline', compact('petition', 'tr', 'locale', 'related'));
        }

        $hasSigned = auth()->check() && $this->hasSigned($petition);

        [$goalTotal, $goalCurrent, $pct] = $this->goalProgress($petition);

        $latest = cache()->remember(
            "petition:latest_sigs:{$petition->id}",
            now()->addMinutes(5),
            fn () => Signature::where('petition_id', $petition->id)
                ->with('user')
                ->where('confirmed', true)
                ->where('is_spam', false)
                ->latest('created_at')
                ->limit(27)
                ->get()
        );

        $directLink = lroute('petition.show', [
            'slug' => $tr->slug,
            'id' => $petition->id,
        ]);

        $pages = app(PageContentService::class);
        $content = collect($pages->getPage('petition_show', $tr->locale));
        $formContent = collect($pages->getPage('petition_sign_form', $tr->locale));

        $defaultComments = DefaultComments::forLocales(
            Language::where('is_active', true)
                ->pluck('code')
                ->push($locale, $defaultLocale)
                ->unique()
                ->values()
                ->all()
        );

        $shoutboxPage = max(1, min((int) request('shoutbox', 1), 500));

        $shoutbox = Signature::with('user')
            ->where('petition_id', $petition->id)
            ->where('is_spam', false)
            ->where('confirmed', true)
            ->whereNotNull('text')
            ->whereRaw("TRIM(text) <> ''")
            ->when(! empty($defaultComments), fn ($q) => $q->whereNotIn('text', $defaultComments))
            ->orderBy('created_at')
            ->paginate(10, ['*'], 'shoutbox', $shoutboxPage)
            ->withQueryString();

        $recentSignatureCount = cache()->remember(
            "petition:recent24h:{$petition->id}",
            now()->addMinutes(10),
            fn () => Signature::where('petition_id', $petition->id)
                ->where('confirmed', true)
                ->where('is_spam', false)
                ->where('created_at', '>=', now()->subDay())
                ->count()
        );

        $canEdit = auth()->check() && app(PetitionPolicy::class)->update(auth()->user(), $petition) && $this->isEditable($petition);

        return view('petition.show', compact(
            'canEdit',
            'petition',
            'tr',
            'locale',
            'latest',
            'goalTotal',
            'goalCurrent',
            'pct',
            'directLink',
            'hasSigned',
            'content',
            'formContent',
            'shoutbox',
            'recentSignatureCount',
            'related'
        ));
    }

    private function recordView(Request $request, Petition $petition): void
    {
        if (ConditionalLogging::isPrefetchOnly($request)) {
            return;
        }

        $sessionKey = "petition_viewed.{$petition->id}";

        if ($request->hasSession() && $request->session()->has($sessionKey)) {
            return;
        }

        $today = now()->toDateString();
        $now = now();

        DB::table('petition_views')->insertOrIgnore([
            'petition_id' => $petition->id,
            'viewed_on' => $today,
            'views' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('petition_views')
            ->where('petition_id', $petition->id)
            ->where('viewed_on', $today)
            ->increment('views', 1, ['updated_at' => $now]);

        DB::table('petitions')->where('id', $petition->id)->increment('views');

        if ($request->hasSession()) {
            $request->session()->put($sessionKey, true);
        }
    }

    public function sign(Request $request, string $locale, string $slug, int $id)
    {
        $petition = Petition::query()
            ->where('id', $id)
            ->visible()
            ->firstOrFail();

        $defaultLocale = default_locale();

        $tr = $petition->translationOrFallback($locale, $defaultLocale);

        abort_if(! $tr, 404);

        if (auth()->check()) {
            if (! auth()->user()->verified) {
                toast(__('sign.verify_first'), 'warning');

                return back()->withInput();
            }

            return PetitionSigning::signAsAuthed($request, $locale, $petition);
        }

        return PetitionSigning::signAsGuest($request, $petition, $locale);
    }

    public function shoutboxStore(Request $request, string $locale, string $slug, int $id): RedirectResponse
    {
        $petition = Petition::query()->where('id', $id)->visible()->firstOrFail();
        $data = $request->validate(['comment' => ['required', 'string', 'max:500']]);
        $u = $request->user();
        $sig = Signature::query()->where('petition_id', $petition->id)->where('user_id', $u->id)->where('confirmed', true)->where('is_spam', false)->first();
        abort_unless($sig && $u->verified, 403);
        if (Spam::isSpam($data['comment'], Spam::reviewOwned())) {
            Spam::log('comment', json_encode(['petition_id' => $petition->id, 'comment' => $data['comment']]));
            toast(__('sign.suspicious'), 'error');

            return back()->withInput();
        }
        $sig->forceFill(['text' => $data['comment']])->save();
        toast(__('sign.comment_posted'), 'success');
        $tr = $petition->translationOrFallback($locale);
        abort_if(! $tr, 404);

        return redirect()->to(route('petition.show', ['locale' => $tr->locale, 'slug' => $tr->slug, 'id' => $petition->id]).'#petition-comments');
    }

    private function activeCategories(string $locale)
    {
        return Category::query()
            ->select(['categories.id'])
            ->translated($locale, 'ct_locale', 'ct_default', ['name' => 'name', 'slug' => 'slug'], default_locale())
            ->where('categories.is_active', true)
            ->orderBy('name')
            ->get();
    }

    public function create(string $locale)
    {
        $categories = $this->activeCategories($locale);

        return view('petition.create', compact('locale', 'categories'));
    }

    public function store(PetitionRequest $request, string $locale)
    {
        $dailyKey = 'petition-create-daily:'.auth()->id();
        abort_if(RateLimiter::hit($dailyKey, 86400) > 10, 429);

        $data = $request->validated();
        $petitionText = implode(' ', [
            $data['title'] ?? '',
            strip_tags($data['description'] ?? ''),
            $data['target'] ?? '',
            $data['tags'] ?? '',
        ]);
        $reviewMatches = ReviewKeywords::matches($petitionText);

        if (Spam::isSpam($petitionText, Spam::reviewOwned())) {
            Spam::log('petition.create', json_encode(['title' => $data['title'] ?? '']));

            return back()->withErrors(['title' => __('create.err_suspicious')])->withInput();
        }

        $newCoverImage = null;
        if ($request->hasFile('image')) {
            $newCoverImage = $this->storeCoverImage($request->file('image'));
        }

        try {
            $response = DB::transaction(function () use ($request, $data, $locale, &$newCoverImage, $reviewMatches) {

                $tags = $this->normalizeTags($data['tags'] ?? null);

                $petition = new Petition;
                $petition->user_id = auth()->id();
                $petition->status = $reviewMatches === [] ? 'published' : 'draft';
                $petition->is_active = 1;
                $petition->goal_signatures = $data['goal_signatures'];
                $petition->category_id = $data['category_id'];
                $petition->target = $data['target'] ?? null;
                $petition->tags = $tags;
                $petition->city = $data['city'] ?? null;
                $petition->country = $data['country'] ?? null;
                $petition->community = $data['community'] ?? null;
                $petition->community_url = $data['community_url'] ?? null;
                $petition->youtube_url = Petition::youtubeId($data['youtube'] ?? null);
                $petition->image_url = $request->hasFile('image') ? null : ($data['image_url'] ?? null);

                if ($request->hasFile('image')) {
                    $petition->cover_image = $newCoverImage;
                }

                if (filled($data['organization_id'] ?? null)) {
                    $org = Organization::verified()->forMember(auth()->user())->whereKey($data['organization_id'])->lockForUpdate()->first();
                    if (! $org) {
                        throw ValidationException::withMessages(['organization_id' => __('organizations.invalid_attribution')]);
                    }
                    $petition->organization_id = $org->id;
                }
                $petition->save();
                $petition->causes()->sync($data['causes'] ?? []);

                $slug = $this->saveTranslationWithSlugRetry(
                    $data['title'],
                    $locale,
                    null,
                    fn (string $slug) => $petition->translations()->create([
                        'locale' => $locale,
                        'title' => $data['title'],
                        'slug' => $slug,
                        'description' => sanitizePetitionHtml($data['description']),
                    ])
                );

                $user = auth()->user();

                $sig = Signature::firstOrCreate(
                    ['petition_id' => $petition->id, 'email' => $user->email],
                    [
                        'user_id' => $user->id,
                        'name' => $user->name ?? 'Anonymous',
                        'locale' => $locale,
                        'text' => 'I support this petition',
                        'confirmed' => true,
                    ]
                );

                if ($sig->wasRecentlyCreated) {
                    $previousCount = PetitionStats::incrementMany([$petition->id => 1])[$petition->id];
                    PetitionStats::notifyMilestoneIfCrossed($petition->id, $previousCount);
                }

                if ($reviewMatches !== []) {
                    Spam::log('petition.review', json_encode([
                        'petition_id' => $petition->id,
                        'title' => $data['title'] ?? '',
                        'keywords' => $reviewMatches,
                    ]));

                    toast(__('petition.review_required'), 'warning');
                } else {
                    toast(__('create.toast_created'), 'success');
                }

                return redirect()->route('petition.thanks', [
                    'locale' => $locale,
                    'slug' => $slug,
                    'id' => $petition->id,
                ])->with('petition_mode', 'created');
            });

            return $response;
        } catch (\Throwable $e) {
            if ($newCoverImage) {
                Storage::disk('public')->delete($newCoverImage);
                ImageVariants::purge($newCoverImage);
            }

            throw $e;
        }
    }

    public function thanks(Request $request, string $locale, string $slug, int $id)
    {
        $requestedLocale = normalize_locale($locale);

        $petition = Petition::query()->with('category')->where('id', $id)->firstOrFail();

        $isOwner = auth()->check() && (int) $petition->user_id === (int) auth()->id();

        abort_unless($petition->status === 'published' ? (bool) $petition->is_active : $isOwner, 404);

        $mode = $request->session()->get('petition_mode', 'signed');
        $verified = $request->session()->get('petition_verified', true);

        $trOrRedirect = $this->resolveTrOrRedirect('petition.thanks', $requestedLocale, $slug, $petition);
        if ($trOrRedirect instanceof RedirectResponse) {
            return $trOrRedirect->with('petition_mode', $mode)->with('petition_verified', $verified);
        }
        $tr = $trOrRedirect;

        $locale = $tr->locale;
        App::setLocale($locale);
        URL::defaults(['locale' => $locale]);
        $request->session()->put('locale', $locale);

        $suggestions = Petition::query()
            ->select([
                'petitions.id',
                'petitions.signature_count',
                'pt_locale.title as tr_title',
                'pt_locale.slug as tr_slug',
            ])
            ->join('petition_translations as pt_locale', function ($join) use ($locale) {
                $join->on('pt_locale.petition_id', '=', 'petitions.id')
                    ->where('pt_locale.locale', '=', $locale);
            })

            ->whereNotNull('pt_locale.title')
            ->where('petitions.status', 'published')
            ->where('petitions.is_active', 1)
            ->where('petitions.id', '!=', $petition->id)
            ->orderByDesc('petitions.signature_count')
            ->limit(5)
            ->get();

        $content = collect(app(PageContentService::class)->getPage('petition_thanks', $tr->locale));

        $count = (int) ($petition->signature_count ?? 0);
        $nextMilestone = collect(Petition::GOALS)->first(fn ($m) => $m > $count) ?? last(Petition::GOALS);
        $shareUrl = lroute('petition.show', ['slug' => $tr->slug, 'id' => $petition->id]);

        $reviewRequired = $petition->status !== 'published';

        return view('petition.thanks', compact(
            'petition',
            'suggestions',
            'locale',
            'mode',
            'verified',
            'tr',
            'content',
            'nextMilestone',
            'shareUrl',
            'reviewRequired'
        ));
    }

    public function signPage(Request $request, string $locale, string $slug, int $id)
    {
        $petition = Petition::query()
            ->with('translations')
            ->whereKey($id)
            ->where('status', 'published')
            ->where('is_active', true)
            ->firstOrFail();

        $trOrRedirect = $this->resolveTrOrRedirect('petition.sign.page', $locale, $slug, $petition);
        if ($trOrRedirect instanceof RedirectResponse) {
            return $trOrRedirect;
        }
        $tr = $trOrRedirect;

        $alreadySigned = auth()->check() && $this->hasSigned($petition);

        if ($alreadySigned) {
            return redirect()->route('petition.show', [
                'locale' => $tr->locale,
                'slug' => $tr->slug,
                'id' => $petition->id,
            ]);
        }

        $content = collect(app(PageContentService::class)->getPage('petition_sign', $locale));

        if ($request->isMethod('get')) {
            return view('petition.sign_page', compact('petition', 'tr', 'locale', 'content'));
        }

        $rules = auth()->check()
            ? PetitionSigning::authedRules()
            : PetitionSigning::guestRules();

        session(["sign.{$petition->id}" => $request->validate($rules)]);

        return view('petition.sign_page', compact('petition', 'tr', 'locale', 'content'));
    }

    public function myPetitions(Request $request, string $locale)
    {
        $locale = normalize_locale($locale);
        $defaultLocale = default_locale();

        $u = auth()->user();
        $tab = $request->query('tab');

        $withTr = function ($q) use ($locale, $defaultLocale) {
            $q->translated($locale, 'pt_locale', 'pt_default', ['title' => 'tr_title', 'slug' => 'tr_slug'], $defaultLocale)
                ->where(function ($q) {
                    $q->whereNotNull('pt_locale.title')
                        ->orWhereNotNull('pt_default.title');
                });
        };

        $signedBase = Petition::query()
            ->select([
                'petitions.*',
                'signatures.created_at as signed_at',
            ])
            ->join('signatures', 'signatures.petition_id', '=', 'petitions.id')
            ->where('petitions.status', 'published')
            ->where(fn ($q) => $q->where('signatures.email', $u->email)->orWhere('signatures.user_id', $u->id))
            ->tap($withTr)
            ->orderByDesc('signatures.created_at');

        $createdBase = Petition::query()
            ->select(['petitions.*'])
            ->where('petitions.user_id', $u->id)
            ->tap($withTr)
            ->latest('petitions.created_at');

        $cachedTotal = fn ($base, string $kind) => cache()->remember(
            "my-petitions:total:{$kind}:{$u->id}:{$locale}:".CacheVersion::get('list'),
            300,
            fn () => (clone $base)->toBase()->getCountForPagination()
        );

        if ($tab === 'signed') {
            $signed = $signedBase->paginate(10, ['*'], 'page', null, $cachedTotal($signedBase, 'signed'))->withQueryString();
            $created = $createdBase->limit(5)->get();
        } elseif ($tab === 'created') {
            $signed = $signedBase->limit(5)->get();
            $created = $createdBase->paginate(10, ['*'], 'page', null, $cachedTotal($createdBase, 'created'))->withQueryString();
        } else {
            $signed = $signedBase->limit(5)->get();
            $created = $createdBase->limit(5)->get();
        }

        return view('petition.my-petitions', compact('locale', 'signed', 'created', 'tab'));
    }

    public function edit(Request $request, string $locale, string $slug, int $id)
    {
        $petition = Petition::query()->with('category')->findOrFail($id);
        $this->requireEditable($petition);

        $trOrRedirect = $this->resolveTrOrRedirect('petition.edit', $locale, $slug, $petition);
        if ($trOrRedirect instanceof RedirectResponse) {
            return $trOrRedirect;
        }
        $tr = $trOrRedirect;

        $categories = $this->activeCategories($locale);

        $mode = 'edit';
        $isHeldForReview = (bool) $petition->held_by_admin;

        return view('petition.create', compact('petition', 'tr', 'locale', 'categories', 'mode', 'isHeldForReview'));
    }

    public function withdraw(string $locale, string $slug, int $id): RedirectResponse
    {
        $petition = DB::transaction(function () use ($id) {
            $petition = Petition::query()->lockForUpdate()->findOrFail($id);
            $this->requireOwner($petition);
            abort_unless($petition->status === 'published', 403);
            $petition->is_active = 0;
            $petition->save();

            return $petition;
        }, 3);

        try {
            EdgeCachePurge::urls(EdgeCachePurge::petitionUrls([$petition->id]));
        } catch (\Throwable $e) {
            Log::warning('Edge cache purge failed after petition save: '.$e->getMessage());
        }

        return redirect()->to(lroute('account.petitions'))->with('success', __('myp.withdrawn'));
    }

    public function update(PetitionRequest $request, string $locale, string $slug, int $id)
    {
        $petition = Petition::query()->findOrFail($id);
        $this->requireEditable($petition);

        $tr = $petition->translationOrFallback($locale, default_locale());
        abort_if(! $tr, 404);

        $data = $request->validated();
        $petitionText = implode(' ', [
            $data['title'],
            strip_tags($data['description']),
            $data['target'] ?? '',
            $data['tags'] ?? '',
        ]);
        $reviewMatches = ReviewKeywords::matches($petitionText);

        if (Spam::isSpam($petitionText, Spam::reviewOwned())) {
            Spam::log('petition.update', json_encode(['petition_id' => $petition->id, 'title' => $data['title']]));

            return back()->withErrors(['title' => __('create.err_suspicious')])->withInput();
        }

        $oldCoverImage = $petition->cover_image;
        $newCoverImage = null;
        if ($request->hasFile('image')) {
            $newCoverImage = $this->storeCoverImage($request->file('image'));
        }

        try {
            $urlsBefore = EdgeCachePurge::petitionUrls([$petition->id]);
        } catch (\Throwable $e) {
            $urlsBefore = [];
            Log::warning('Edge cache URL lookup failed before petition save: '.$e->getMessage());
        }

        try {
            $response = DB::transaction(function () use ($request, $data, $petition, $tr, $reviewMatches, $newCoverImage) {
                $locked = Petition::query()->lockForUpdate()->findOrFail($petition->id);
                $this->requireEditable($locked);
                $petition->setRawAttributes($locked->getAttributes(), true);

                $tags = $this->normalizeTags($data['tags'] ?? null);

                if (array_key_exists('causes', $data)) {
                    $petition->causes()->sync($data['causes']);
                }
                $petition->category_id = $data['category_id'];
                $petition->target = $data['target'] ?? null;
                $petition->tags = $tags;
                $petition->city = $data['city'] ?? null;
                $petition->community = $data['community'] ?? null;
                $petition->country = $data['country'] ?? null;
                $petition->community_url = $data['community_url'] ?? null;

                if (($data['youtube'] ?? null) !== $petition->youtube_url) {
                    $petition->youtube_url = Petition::youtubeId($data['youtube'] ?? null);
                }

                $petition->image_url = $data['image_url'] ?? null;

                if ($newCoverImage) {
                    $petition->cover_image = $newCoverImage;
                    $petition->image_url = null;
                } elseif ($request->boolean('remove_image')) {
                    $petition->cover_image = null;
                } elseif (filled($data['image_url'] ?? null) && $petition->isDirty('image_url')) {
                    $petition->cover_image = null;
                }

                $heldForReview = $reviewMatches !== [] && $petition->status === 'published';
                if ($heldForReview) {
                    $petition->status = 'draft';
                } elseif ($reviewMatches === [] && $petition->status === 'draft' && ! $petition->held_by_admin) {
                    $petition->status = 'published';
                }

                $petition->save();

                $oldTitle = $tr->title;
                $oldDescription = $tr->description;
                $tr->title = $data['title'];
                $tr->description = sanitizePetitionHtml($data['description']);
                if ($data['title'] !== $oldTitle) {
                    $this->saveTranslationWithSlugRetry(
                        $data['title'],
                        $tr->locale,
                        $tr->id,
                        function (string $slug) use ($tr): void {
                            $tr->slug = $slug;
                            $tr->save();
                        }
                    );
                } else {
                    $tr->save();
                }

                PetitionTranslation::where('petition_id', $petition->id)
                    ->where('id', '!=', $tr->id)
                    ->get()
                    ->filter(fn ($copy) => $copy->title === $oldTitle && $copy->description === $oldDescription)
                    ->each(fn ($copy) => $copy->update(['title' => $tr->title, 'description' => $tr->description]));

                if ($heldForReview) {
                    Spam::log('petition.review', json_encode([
                        'petition_id' => $petition->id,
                        'title' => $data['title'],
                        'keywords' => $reviewMatches,
                    ]));
                }

                if ($reviewMatches !== []) {
                    toast(__('petition.review_required'), 'warning');
                } else {
                    toast(__('create.toast_updated'), 'success');
                }

                return redirect()->route('petition.show', [
                    'locale' => $tr->locale,
                    'slug' => $tr->slug,
                    'id' => $petition->id,
                ]);
            });
        } catch (\Throwable $e) {
            if ($newCoverImage) {
                Storage::disk('public')->delete($newCoverImage);
                ImageVariants::purge($newCoverImage);
            }

            throw $e;
        }

        try {
            EdgeCachePurge::urls([...$urlsBefore, ...EdgeCachePurge::petitionUrls([$petition->id])]);
        } catch (\Throwable $e) {
            Log::warning('Edge cache purge failed after petition save: '.$e->getMessage());
        }

        if ($oldCoverImage && $oldCoverImage !== $petition->cover_image && ! Str::startsWith($oldCoverImage, ['http://', 'https://'])) {
            try {
                Storage::disk('public')->delete($oldCoverImage);
                ImageVariants::purge($oldCoverImage);
            } catch (\Throwable $e) {
                Log::warning('Old cover image cleanup failed after petition update: '.$e->getMessage());
            }
        }

        return $response;
    }

    public function downloadTxt(Request $request, string $locale, string $slug, int $id)
    {
        $petition = Petition::query()->findOrFail($id);
        $this->requireOwnerOrAdmin($petition);

        $trOrRedirect = $this->resolveTrOrRedirect('petition.show', $locale, $slug, $petition);
        if ($trOrRedirect instanceof RedirectResponse) {
            return $trOrRedirect;
        }
        $tr = $trOrRedirect;

        $filename = 'petition-'.$petition->id.'.txt';
        $total = (int) ($petition->signature_count ?? 0);

        $header = [];
        $header[] = strtoupper($tr->title ?? 'Petition');
        $header[] = 'URL: '.url("/{$locale}/petition/{$tr->slug}/{$petition->id}");
        $header[] = '';
        $header[] = '--- DESCRIPTION ---';
        $header[] = trim(strip_tags($tr->description ?? ''));
        $header[] = '';
        $header[] = 'Total signatures: '.$total;
        $header[] = '';
        $header[] = '--- SIGNATURES ---';

        return response()->streamDownload(function () use ($petition, $header) {
            $out = fopen('php://output', 'w');

            fwrite($out, implode("\n", $header)."\n");

            $num = 0;
            Signature::query()
                ->where('petition_id', $petition->id)
                ->where('confirmed', true)
                ->where('is_spam', false)
                ->chunkById(1000, function ($rows) use ($out, &$num) {
                    foreach ($rows as $sig) {
                        $num++;
                        $name = $sig->name ?: 'Anonymous';
                        $date = optional($sig->created_at)->format('Y-m-d');
                        $comment = trim($sig->text ?? 'I support this petition');

                        fwrite($out, "{$num}. {$name} ({$date})\n");
                        fwrite($out, "   {$comment}\n");
                    }
                });

            if ($num === 0) {
                fwrite($out, "No signatures yet.\n");
            }

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/plain; charset=UTF-8',
        ]);
    }

    public function downloadPdf(Request $request, string $locale, string $slug, int $id)
    {
        $petition = Petition::query()->findOrFail($id);
        $this->requireOwnerOrAdmin($petition);

        $trOrRedirect = $this->resolveTrOrRedirect('petition.show', $locale, $slug, $petition);
        if ($trOrRedirect instanceof RedirectResponse) {
            return $trOrRedirect;
        }
        $tr = $trOrRedirect;

        // PDF renders in-memory, so cap the embedded list; the full,
        // uncapped export is available as TXT/CSV (streamed).
        $signatures = Signature::query()
            ->where('petition_id', $petition->id)
            ->where('confirmed', true)
            ->where('is_spam', false)
            ->orderBy('created_at')
            ->limit(1000)
            ->get();

        $isCapped = (int) ($petition->signature_count ?? 0) > 1000;

        try {
            return Pdf::loadView('petition.pdf', compact(
                'petition',
                'tr',
                'locale',
                'signatures',
                'isCapped'
            ))
                ->download("petition-{$petition->id}.pdf");
        } catch (\Exception $e) {
            AppLog::error('PDF generation failed', $e->getMessage(), 'petition.pdf');
            toast(__('petition.pdf_failed_fallback'), 'warning');

            return $this->downloadTxt($request, $locale, $slug, $id);
        }
    }

    public function downloadCsv(Request $request, string $locale, string $slug, int $id)
    {
        $petition = Petition::query()->findOrFail($id);
        $this->requireOwnerOrAdmin($petition);

        $trOrRedirect = $this->resolveTrOrRedirect('petition.show', $locale, $slug, $petition);
        if ($trOrRedirect instanceof RedirectResponse) {
            return $trOrRedirect;
        }

        $filename = 'petition-'.$petition->id.'-signatures.csv';

        // Stream ALL signatures as CSV (chunked cursor) so the export is
        // memory-safe on large petitions.
        $showEmail = admin_can('signatures', 'view');
        $header = $showEmail ? ['#', 'name', 'email', 'comment', 'locale', 'confirmed', 'signed_at'] : ['#', 'name', 'comment', 'locale', 'signed_at'];

        return response()->streamDownload(function () use ($petition, $showEmail, $header) {
            $out = fopen('php://output', 'w');

            // UTF-8 BOM so spreadsheet apps read accents correctly.
            fwrite($out, "\xEF\xBB\xBF");

            fputcsv($out, $header);

            $num = 0;
            Signature::query()
                ->where('petition_id', $petition->id)
                ->where('is_spam', false)
                ->when(! $showEmail, fn ($q) => $q->where('confirmed', true))
                ->chunkById(1000, function ($rows) use ($out, &$num, $showEmail) {
                    foreach ($rows as $sig) {
                        $num++;
                        $row = [$num, $sig->name ?: 'Anonymous'];
                        if ($showEmail) {
                            $row[] = $sig->email ?? '';
                        }
                        $row[] = trim($sig->text ?? '');
                        $row[] = $sig->locale ?? '';
                        if ($showEmail) {
                            $row[] = $sig->confirmed ? '1' : '0';
                        }
                        $row[] = optional($sig->created_at)->format('Y-m-d H:i:s');
                        fputcsv($out, array_map('csv_safe', $row));
                    }
                });

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    private function makeUniqueSlug(string $title, string $locale, ?int $ignoreTranslationId = null): string
    {
        return Slug::dedupe(
            $title,
            fn (string $slug) => PetitionTranslation::where('locale', $locale)
                ->where('slug', $slug)
                ->when($ignoreTranslationId, fn ($query) => $query->whereKeyNot($ignoreTranslationId))
                ->exists(),
            'petition',
            255
        );
    }

    private function saveTranslationWithSlugRetry(
        string $title,
        string $locale,
        ?int $ignoreTranslationId,
        \Closure $save
    ): string {
        $slug = $this->makeUniqueSlug($title, $locale, $ignoreTranslationId);
        $tries = 0;

        while (true) {
            try {
                $save($slug);

                return $slug;
            } catch (QueryException $e) {
                if (($e->errorInfo[0] ?? null) !== '23000' || ++$tries >= 5) {
                    throw $e;
                }
                $slug = Slug::dedupe($title.'-'.$tries, fn () => false, 'petition', 255);
            }
        }
    }

    public function signatures(Request $request, string $locale, string $slug, int $id)
    {
        $locale = normalize_locale($locale);

        $petition = Petition::with('category', 'user', 'translations')
            ->where('id', $id)
            ->visible()
            ->firstOrFail();

        $trOrRedirect = $this->resolveTrOrRedirect('petition.signatures', $locale, $slug, $petition);
        if ($trOrRedirect instanceof RedirectResponse) {
            return $trOrRedirect;
        }
        $tr = $trOrRedirect;

        $page = max(1, min((int) request('page', 1), 500));

        $signatures = Signature::with('user')
            ->where('petition_id', $petition->id)
            ->where('confirmed', true)
            ->where('is_spam', false)
            ->orderByDesc('created_at')
            ->paginate(25, ['*'], 'page', $page)
            ->withQueryString();

        [$goalTotal, $goalCurrent, $pct] = $this->goalProgress($petition);

        return view('petition.signatures', compact(
            'petition',
            'tr',
            'locale',
            'signatures',
            'goalTotal',
            'goalCurrent',
            'pct'
        ));
    }
}
