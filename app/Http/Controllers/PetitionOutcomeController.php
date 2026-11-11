<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Models\Petition;
use App\Policies\PetitionPolicy;
use App\Support\EdgeCachePurge;
use App\Support\ReviewKeywords;
use App\Support\Spam;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PetitionOutcomeController extends Controller
{
    private function authorized(int $id): Petition
    {
        $petition = Petition::findOrFail($id);
        $admin = request()->routeIs('admin.*');
        abort_unless($admin ? admin_can('petitions', 'edit') : (auth('web')->check() && app(PetitionPolicy::class)->setOutcome(auth('web')->user(), $petition)), 403);

        return $petition;
    }

    public function edit(string $locale, string $slug, int $id)
    {
        return $this->form($this->authorized($id));
    }

    public function adminEdit(int $petition)
    {
        return $this->form($this->authorized($petition));
    }

    private function form(Petition $petition)
    {
        return response()->view('petition.outcome-form', compact('petition'))
            ->header('Cache-Control', 'private, no-store')->header('X-Robots-Tag', 'noindex, nofollow');
    }

    public function update(Request $request, string $locale, string $slug, int $id)
    {
        return $this->save($request, $id);
    }

    public function adminUpdate(Request $request, int $petition)
    {
        return $this->save($request, $petition);
    }

    private function save(Request $request, int $id)
    {
        $this->authorized($id);
        $data = $request->validate([
            'outcome' => ['required', Rule::in(Petition::OUTCOMES)],
            'outcome_at' => ['nullable', 'required_unless:outcome,open', 'date_format:Y-m-d', 'before_or_equal:today'],
            'outcome_summary' => ['nullable', 'required_unless:outcome,open', 'string', 'max:500', function ($attribute, $value, $fail) {
                if ($value !== strip_tags($value)) {
                    $fail(__('organizations.outcome_plain_text'));
                }
            }],
            'confirm_reopen' => ['nullable', 'boolean'],
        ]);
        $summary = (string) ($data['outcome_summary'] ?? '');
        if ($summary !== '' && ! $request->routeIs('admin.*')) {
            if (Spam::isSpam($summary, Spam::reviewOwned())) {
                Spam::log('petition.outcome', json_encode(['petition_id' => $id, 'summary' => $summary]));
                throw ValidationException::withMessages(['outcome_summary' => __('create.err_suspicious')]);
            }
            if (ReviewKeywords::matches($summary) !== []) {
                throw ValidationException::withMessages(['outcome_summary' => __('create.err_suspicious')]);
            }
        }
        DB::transaction(function () use ($id, $data, $request) {
            $petition = Petition::whereKey($id)->lockForUpdate()->firstOrFail();
            if ($petition->organization_id) {
                $petition->setRelation('organization', Organization::whereKey($petition->organization_id)->lockForUpdate()->first());
            }
            abort_unless($request->routeIs('admin.*') ? admin_can('petitions', 'edit') : (auth('web')->check() && app(PetitionPolicy::class)->setOutcome(auth('web')->user(), $petition)), 403);
            if ($data['outcome'] === 'open' && $petition->outcome !== 'open' && ! $request->boolean('confirm_reopen')) {
                throw ValidationException::withMessages(['confirm_reopen' => __('organizations.outcome_reopen_confirm')]);
            }
            $fields = ['outcome', 'outcome_at', 'outcome_summary'];
            $before = $petition->only($fields);
            $petition->outcome = $data['outcome'];
            $petition->outcome_at = $data['outcome'] === 'open' ? null : $data['outcome_at'];
            $petition->outcome_summary = $data['outcome'] === 'open' ? null : $data['outcome_summary'];
            if (! $petition->isDirty($fields)) {
                return;
            }
            $petition->outcome_updated_at = now();
            $petition->outcome_updated_by = $request->routeIs('admin.*') ? auth('admin')->id() : auth('web')->id();
            $petition->save();
            DB::table('petition_outcome_events')->insert([
                'petition_id' => $petition->id, 'actor_user_id' => $petition->outcome_updated_by,
                'before' => json_encode($before), 'after' => json_encode($petition->only($fields)), 'created_at' => now(),
            ]);
            DB::afterCommit(fn () => EdgeCachePurge::urls(EdgeCachePurge::petitionUrls([$petition->id])));
        }, 3);

        return back()->with('success', __('organizations.saved'));
    }
}
