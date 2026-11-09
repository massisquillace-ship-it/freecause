<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Petition;
use App\Support\CacheVersion;
use App\Support\EdgeCachePurge;
use App\Support\ImageVariants;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class AdminPetitionDeleteController extends Controller
{
    public function __invoke(Request $request)
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['required', 'integer', 'distinct', 'exists:petitions,id'],
            'confirmed' => ['required', 'accepted'],
        ]);

        [$deleted, $shortLinks, $urls, $imagePaths] = DB::transaction(function () use ($data) {
            $rows = DB::table('petitions')->whereIn('id', $data['ids'])
                ->orderBy('id')->lockForUpdate()->get(['id', 'cover_image', 'image_url']);
            $ids = $rows->pluck('id')->all();
            $shortLinks = DB::table('legacy_shortlinks')->whereIn('petition_id', $ids)->pluck('short_link')->all();
            $urls = EdgeCachePurge::petitionUrls($ids);
            $imagePaths = [];
            foreach ($rows as $row) {
                foreach ([$row->cover_image, $row->image_url && ! preg_match('#^https?://#', $row->image_url) ? 'petitions/'.$row->image_url : null] as $path) {
                    if ($path && str_starts_with($path, 'petitions/') && ! str_contains($path, '..')) {
                        $imagePaths[] = $path;
                    }
                }
            }

            // Legacy short links, outcome events, and reports have no foreign key cascade.
            DB::table('legacy_shortlinks')->whereIn('petition_id', $ids)->delete();
            DB::table('petition_outcome_events')->whereIn('petition_id', $ids)->delete();
            DB::table('reports')
                ->where('reportable_type', Petition::class)
                ->whereIn('reportable_id', $ids)
                ->delete();
            $deleted = DB::table('petitions')->whereIn('id', $ids)->delete();

            return [$deleted, $shortLinks, $urls, array_values(array_unique($imagePaths))];
        });

        try {
            $keep = array_flip(DB::table('petitions')
                ->where(fn ($q) => $q->whereIn('cover_image', $imagePaths)
                    ->orWhereIn('image_url', array_map(fn ($path) => substr($path, strlen('petitions/')), $imagePaths)))
                ->get(['cover_image', 'image_url'])
                ->flatMap(fn ($row) => [$row->cover_image, $row->image_url && ! preg_match('#^https?://#', $row->image_url) ? 'petitions/'.$row->image_url : null])
                ->filter()
                ->flatMap(fn ($path) => [$path, ...array_values(ImageVariants::relativeVariantPaths($path))])
                ->all());
        } catch (\Throwable $e) {
            Log::warning('Petition image reference check failed: '.$e->getMessage());
            $imagePaths = [];
        }

        $base = rtrim((string) config('edge-cache.base_url'), '/');
        foreach ($imagePaths as $path) {
            if (isset($keep[$path])) {
                continue;
            }
            try {
                Storage::disk('public')->delete($path);
                ImageVariants::purge($path);
                foreach ([$path, ...array_values(ImageVariants::relativeVariantPaths($path))] as $file) {
                    $urls[] = $base.'/storage/'.$file;
                }
            } catch (\Throwable $e) {
                Log::warning('Petition image deletion failed: '.$e->getMessage(), ['path' => $path]);
            }
        }

        try {
            foreach ($shortLinks as $shortLink) {
                Cache::forget('legacy_shortlink:'.strtoupper($shortLink));
            }
            CacheVersion::bumpAll();
            EdgeCachePurge::urls($urls);
        } catch (\Throwable $e) {
            Log::warning('Cache invalidation failed after petition deletion: '.$e->getMessage());
        }

        Log::info('Admin permanently deleted petitions.', [
            'admin_id' => $request->user('admin')?->id,
            'petition_ids' => $data['ids'],
            'deleted' => $deleted,
        ]);

        return response()->json(['ok' => true, 'deleted' => $deleted, 'restorable' => false, 'msg' => $deleted.' petitions permanently deleted.']);
    }
}
