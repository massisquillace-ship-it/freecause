<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    public function __invoke(Request $request, string $locale)
    {
        $data = $request->validate(['q' => ['nullable', 'string', 'max:120'], 'tab' => ['nullable', 'in:organizations,petitions']]);
        $search = trim($data['q'] ?? '');
        $organizations = collect();
        $petitions = collect();
        if ($search !== '') {
            $literal = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search).'%';
            $organizations = Organization::public()->whereRaw("LOWER(name) LIKE ? ESCAPE '!'", [mb_strtolower($literal)])
                ->orderBy('name')->orderBy('id')->limit(20)->get();
            $petitions = PetitionController::applySearch(PetitionController::listingQuery($locale), $search)
                ->addSelect(['petitions.user_id', 'petitions.organization_id'])->with(['user:id,name,first_name,last_name,nickname,identify_mode', 'organization'])
                ->orderByDesc('petitions.signature_count')->orderByDesc('petitions.id')->limit(20)->get();
        }

        return view('pages.search', ['search' => $search, 'organizations' => $organizations, 'petitions' => $petitions, 'tab' => $data['tab'] ?? 'organizations']);
    }
}
