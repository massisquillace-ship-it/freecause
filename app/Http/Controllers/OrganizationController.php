<?php

namespace App\Http\Controllers;

use App\Http\Requests\OrganizationProfileRequest;
use App\Models\Cause;
use App\Models\Organization;
use App\Models\User;
use App\Services\OrganizationImages;
use App\Services\OrganizationLifecycle;
use App\Support\Countries;
use App\Support\ImageVariants;
use App\Support\Locale;
use Database\Seeders\OrganizationCauseSeeder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OrganizationController extends Controller
{
    public function __construct(private OrganizationLifecycle $lifecycle, private OrganizationImages $images) {}

    public function landing()
    {
        $owned = auth('web')->check() ? Organization::where('owner_user_id', auth('web')->id())->orderByDesc('id')->get() : collect();
        $view = view('organizations.landing', ['owned' => $owned, 'applicationsOpen' => $this->applicationsOpen()]);

        return auth('web')->check() ? $this->privateResponse($view) : $view;
    }

    public function terms()
    {
        return view('organizations.terms', ['terms' => config('organizations.terms.'.app()->getLocale(), config('organizations.terms.en', ''))]);
    }

    public function apply()
    {
        abort_unless($this->applicationsOpen(), 403, __('organizations.applications_closed'));

        return $this->form(new Organization(['country' => auth('web')->user()->country, 'cover_focal_point' => 'center']));
    }

    public function store(OrganizationProfileRequest $request, string $locale)
    {
        abort_unless($this->applicationsOpen(), 403, __('organizations.applications_closed'));
        $data = $request->safe()->except(['causes', 'logo', 'cover', 'remove_logo', 'remove_cover', 'representation_confirmed', 'terms_accepted']);
        $data['slug'] = ($data['slug'] ?? null) ?: $this->uniqueSlug($data['name']);
        $paths = $this->uploads($request);
        try {
            $org = DB::transaction(function () use ($request, $data, $paths) {
                $owner = User::whereKey(auth('web')->id())->lockForUpdate()->firstOrFail();
                $org = Organization::create(array_merge($this->clean($data), $paths, [
                    'owner_user_id' => $owner->id, 'status' => 'pending',
                    'representation_confirmed_at' => now(), 'terms_accepted_at' => now(),
                    'terms_version' => config('organizations.terms_version'),
                ]));
                $org->causes()->sync($request->validated('causes'));
                $this->lifecycle->record($org, $owner->id, 'applied');

                return $org;
            }, 3);
        } catch (\Throwable $e) {
            $this->deleteImages(array_values($paths));
            throw $e;
        }

        return redirect()->to(lroute('organizations.manage', ['slug' => $org->slug]));
    }

    public function show(string $locale, string $slug)
    {
        $org = Organization::public()->with('causes')->where('slug', $slug)->firstOrFail();

        return view('organizations.show', ['org' => $org, 'profileUrl' => canonical_url($locale.'/org/'.$org->slug)]);
    }

    public function manage(string $locale, string $slug)
    {
        $org = $this->owned($slug);

        return $this->privateResponse(view('organizations.manage', compact('org')));
    }

    public function edit(string $locale, string $slug)
    {
        $org = $this->owned($slug);
        abort_if($org->status === 'suspended', 403);

        return $this->form($org);
    }

    public function update(OrganizationProfileRequest $request, string $locale, string $slug)
    {
        $existing = $this->owned($slug);
        $data = $request->safe()->except(['causes', 'logo', 'cover', 'remove_logo', 'remove_cover']);
        // Changing a saved crop requires its source again; originals are never retained.
        if ($existing->cover_path && ! $request->hasFile('cover') && ! $request->boolean('remove_cover') && $data['cover_focal_point'] !== $existing->cover_focal_point) {
            throw ValidationException::withMessages(['cover' => __('organizations.focal_upload_required')]);
        }
        if (empty($data['slug'])) {
            unset($data['slug']);
        }
        $paths = $this->uploads($request);
        $oldPaths = [];
        try {
            $org = DB::transaction(function () use ($request, $existing, $data, $paths, &$oldPaths) {
                $org = Organization::whereKey($existing->id)->lockForUpdate()->firstOrFail();
                abort_unless((int) $org->owner_user_id === (int) auth('web')->id() && $org->status !== 'suspended', 403);
                if ($org->verified_at) {
                    foreach (Organization::IDENTITY_FIELDS as $field) {
                        if (array_key_exists($field, $data) && $data[$field] !== $org->$field) {
                            throw ValidationException::withMessages([$field => __('organizations.identity_locked')]);
                        }
                        unset($data[$field]);
                    }
                }
                if (array_key_exists('donation_url', $data) && $data['donation_url'] !== $org->donation_url) {
                    $org->donation_url_approved_at = null;
                    $org->donation_url_approved_by = null;
                }
                foreach (['logo', 'cover'] as $kind) {
                    if (isset($paths[$kind.'_path']) || $request->boolean('remove_'.$kind)) {
                        if ($org->{$kind.'_path'}) {
                            $oldPaths[] = $org->{$kind.'_path'};
                        }
                        $org->{$kind.'_path'} = $paths[$kind.'_path'] ?? null;
                    }
                }
                $org->fill($this->clean($data));
                $org->save();
                $org->causes()->sync($request->validated('causes'));
                $this->lifecycle->record($org, auth('web')->id(), 'profile_updated');

                return $org;
            }, 3);
        } catch (\Throwable $e) {
            $this->deleteImages(array_values($paths));
            throw $e;
        }
        $this->deleteImages($oldPaths);

        return redirect()->to(lroute('organizations.manage', ['slug' => $org->slug]))->with('success', __('organizations.saved'));
    }

    public function resubmit(string $locale, string $slug)
    {
        DB::transaction(function () use ($slug) {
            $org = Organization::where('slug', $slug)->lockForUpdate()->firstOrFail();
            abort_unless((int) $org->owner_user_id === (int) auth('web')->id(), 403);
            abort_unless($org->status === 'rejected', 409);
            $org->status = 'pending';
            $org->rejection_reason = null;
            $org->save();
            $this->lifecycle->record($org, auth('web')->id(), 'resubmitted');
        }, 3);

        return back()->with('success', __('organizations.saved'));
    }

    private function owned(string $slug): Organization
    {
        $org = Organization::with('causes')->where('slug', $slug)->firstOrFail();
        abort_unless((int) $org->owner_user_id === (int) auth('web')->id(), 403);

        return $org;
    }

    private function form(Organization $org)
    {
        return $this->privateResponse(view('organizations.form', [
            'org' => $org, 'countries' => Countries::all(), 'languages' => Locale::options(),
            'causes' => Cause::whereIn('slug', array_keys(OrganizationCauseSeeder::CAUSES))->orderBy('sort_order')->get(),
        ]));
    }

    private function privateResponse($view)
    {
        return response($view)->header('Cache-Control', 'private, no-store')->header('X-Robots-Tag', 'noindex, nofollow');
    }

    private function applicationsOpen(): bool
    {
        return (bool) config('organizations.applications_open') && filled(config('organizations.terms_version')) && filled(config('organizations.terms.en'));
    }

    private function uniqueSlug(string $name): string
    {
        $base = substr(Str::slug($name) ?: 'organization', 0, 65);
        $slug = $base;
        while (in_array($slug, Organization::RESERVED_SLUGS, true) || Organization::where('slug', $slug)->exists()) {
            $slug = $base.'-'.Str::lower(Str::random(8));
        }

        return $slug;
    }

    private function clean(array $data): array
    {
        foreach (['social_links', 'tagline', 'description'] as $key) {
            if (isset($data[$key])) {
                $data[$key] = array_filter($data[$key], fn ($v) => is_string($v) && trim($v) !== '');
            }
        }

        return $data;
    }

    private function uploads(Request $request): array
    {
        $paths = [];
        try {
            foreach (['logo', 'cover'] as $kind) {
                if ($request->hasFile($kind)) {
                    $paths = array_merge($paths, $this->images->store($request->file($kind), $kind, $request->input('cover_focal_point', 'center')));
                }
            }
        } catch (\Throwable $e) {
            $this->deleteImages(array_values($paths));
            throw $e;
        }

        return $paths;
    }

    private function deleteImages(array $paths): void
    {
        foreach ($paths as $path) {
            ImageVariants::purge($path);
        }
        Storage::disk('public')->delete($paths);
    }
}
