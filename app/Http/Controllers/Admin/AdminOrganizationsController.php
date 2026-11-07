<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Services\OrganizationLifecycle;
use App\Support\Countries;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AdminOrganizationsController extends Controller
{
    public function index(Request $request)
    {
        $data = $request->validate(['q' => ['nullable', 'string', 'max:120'], 'status' => ['nullable', Rule::in(Organization::STATES)]]);
        $query = Organization::with('owner')->orderByDesc('id');
        if (filled($data['status'] ?? null)) {
            $query->where('status', $data['status']);
        }
        if (filled($data['q'] ?? null)) {
            $query->where('name', 'like', '%'.addcslashes($data['q'], '%_\\').'%');
        }

        return view('admin.organizations.index', ['organizations' => $query->paginate(30)->withQueryString()]);
    }

    public function show(int $organization)
    {
        return view('admin.organizations.show', ['org' => Organization::with(['owner', 'causes', 'events' => fn ($q) => $q->orderByDesc('id')->limit(100)])->findOrFail($organization)]);
    }

    public function action(Request $request, int $organization, string $action, OrganizationLifecycle $lifecycle)
    {
        $org = Organization::findOrFail($organization);
        $actor = auth('admin')->user();
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:3000'], 'confirm' => ['accepted']]);
        if (in_array($action, ['verify', 'reject', 'suspend', 'restore'], true)) {
            $lifecycle->review($org, $actor, $action, $data['reason'] ?? null);
        } elseif ($action === 'reassign') {
            $request->validate(['owner_user_id' => ['required', 'integer', 'exists:users,id']]);
            $lifecycle->reassign($org, $actor, (int) $request->input('owner_user_id'));
        } else {
            DB::transaction(function () use ($request, $org, $actor, $action, $lifecycle) {
                $org = Organization::whereKey($org->id)->lockForUpdate()->firstOrFail();
                if ($action === 'founding') {
                    $org->founding_partner = ! $org->founding_partner;
                } elseif ($action === 'donation') {
                    abort_unless($org->status === 'verified' && $org->owner_user_id && filled($org->donation_url), 409);
                    // Bind approval to the exact URL the administrator reviewed.
                    $request->validate(['reviewed_donation_url' => ['required', 'string', Rule::in([$org->donation_url])]]);
                    $org->donation_url_approved_at = now();
                    $org->donation_url_approved_by = $actor->id;
                } elseif ($action === 'identity') {
                    $identity = $request->validate([
                        'name' => ['required', 'string', 'max:120'], 'legal_name' => ['nullable', 'string', 'max:200'],
                        'type' => ['required', Rule::in(Organization::TYPES)], 'country' => ['required', Rule::in(Countries::codes())],
                    ]);
                    $org->fill($identity);
                } else {
                    abort(404);
                }
                $org->save();
                $lifecycle->record($org, $actor->id, $action, $action === 'founding' ? ['enabled' => $org->founding_partner] : []);
            }, 3);
        }

        return redirect()->route('admin.organizations.show', ['organization' => $org->id])->with('success', __('organizations.saved'));
    }
}
