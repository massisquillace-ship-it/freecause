<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class UserProfileController extends Controller
{
    public function show(string $slug, string $id)
    {
        $id = (int) request()->route('id');
        $locale = app()->getLocale();
        $defaultLocale = default_locale();

        $data = cache()->remember("user:profile:data:{$id}:{$locale}", now()->addSeconds(120), function () use ($id, $locale, $defaultLocale) {
            $model = User::findOrFail($id);
            // cache only what the view renders, never the full User row
            $user = (object) ['display_name' => $model->display_name, 'created_at' => $model->created_at];

            $petitionCount = DB::table('petitions')
                ->where('user_id', $id)
                ->where('status', 'published')
                ->count();

            $signatureCount = DB::table('signatures as s')
                ->join('petitions as p', 'p.id', '=', 's.petition_id')
                ->where('s.user_id', $id)
                ->where('s.confirmed', true)
                ->where('s.is_spam', false)
                ->where('p.status', 'published')
                ->count();

            $recentPetitions = DB::table('petitions as p')
                ->select(['p.id', 'p.signature_count', 'p.created_at'])
                ->withTranslation([
                    'base' => 'p',
                    'base_key' => 'id',
                    'translation_table' => 'petition_translations',
                    'translation_key' => 'petition_id',
                    'locale_alias' => 'pt_locale',
                    'default_alias' => 'pt_default',
                    'locale' => $locale,
                    'default_locale' => $defaultLocale,
                    'columns' => ['title' => 'title', 'slug' => 'slug'],
                ])
                ->where('p.user_id', $id)
                ->where('p.status', 'published')
                ->whereRaw('(pt_locale.title IS NOT NULL OR pt_default.title IS NOT NULL)')
                ->orderByDesc('p.id')
                ->limit(5)
                ->get();

            $recentSignatures = DB::table('signatures as s')
                ->join('petitions as p', 'p.id', '=', 's.petition_id')
                ->select(['s.id', 'p.id as petition_id', 's.created_at'])
                ->withTranslation([
                    'base' => 'p',
                    'base_key' => 'id',
                    'translation_table' => 'petition_translations',
                    'translation_key' => 'petition_id',
                    'locale_alias' => 'pt_locale',
                    'default_alias' => 'pt_default',
                    'locale' => $locale,
                    'default_locale' => $defaultLocale,
                    'columns' => ['title' => 'title', 'slug' => 'slug'],
                ])
                ->where('s.user_id', $id)
                ->where('s.confirmed', true)
                ->where('s.is_spam', false)
                ->where('p.status', 'published')
                ->whereRaw('(pt_locale.title IS NOT NULL OR pt_default.title IS NOT NULL)')
                ->orderByDesc('s.id')
                ->limit(5)
                ->get();

            return compact(
                'user',
                'petitionCount',
                'signatureCount',
                'recentPetitions',
                'recentSignatures'
            );
        });

        return view('pages.user-profile', $data);
    }
}
