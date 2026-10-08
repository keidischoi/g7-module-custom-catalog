<?php

namespace Modules\Custom\Catalog\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Schema;

class CatalogController extends Controller
{
    public function equipment(Request $request): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->search('cat_equipment', $request, ['brand', 'model', 'kind'])]);
    }

    public function materials(Request $request): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->search('cat_materials', $request, ['brand', 'name', 'material', 'material_norm'])]);
    }

    public function equipmentOne(string $key): JsonResponse
    {
        return $this->one('cat_equipment', $key);
    }

    public function materialOne(string $key): JsonResponse
    {
        return $this->one('cat_materials', $key);
    }

    public function photo(Request $request): JsonResponse
    {
        $kind = trim((string) $request->query('kind', ''));
        $brand = mb_strtolower((string) preg_replace('/[\s\-_.·\/]+/u', '', trim((string) $request->query('brand', ''))));
        $model = mb_strtolower((string) preg_replace('/[\s\-_.·\/]+/u', '', trim((string) $request->query('model', ''))));
        $url = null;
        if (Schema::hasTable('cmp_settings') && $brand !== '' && $model !== '') {
            $raw = DB::table('cmp_settings')->where('key', 'model_rules')->value('value');
            $rules = is_string($raw) ? json_decode($raw, true) : [];
            $hit = is_array($rules) ? ($rules[$kind.'|'.$brand.'|'.$model] ?? null) : null;
            $hash = is_array($hit) ? (string) ($hit['img'] ?? '') : '';
            if ($hash !== '' && ! in_array($hit['s'] ?? '', ['hidden', 'merged'], true)) {
                $url = '/api/modules/custom-companies/files/'.$hash;
            }
        }

        return response()->json(['success' => true, 'data' => ['url' => $url]]);
    }

    private function one(string $table, string $key): JsonResponse
    {
        if (! Schema::hasTable($table)) {
            return response()->json(['success' => false, 'message' => '표가 없습니다.'], 404);
        }
        $row = DB::table($table)->where('key', $key)->where('status', 'active')->first();
        if (! $row) {
            return response()->json(['success' => false, 'message' => '없습니다.'], 404);
        }
        $type = $table === 'cat_materials' ? 'material' : 'equipment';
        $photos = [];
        if (Schema::hasTable('cat_photos')) {
            $photos = DB::table('cat_photos')->where('item_type', $type)->where('item_key', $key)->orderBy('sort')->orderBy('id')->get(['id', 'url', 'sort']);
        }
        if ($photos === [] && ! empty($row->image_url)) {
            $photos = [(object) ['id' => 0, 'url' => $row->image_url, 'sort' => 0]];
        }
        $row->photos = $photos;
        if (empty($row->image_url) && count($photos)) {
            $row->image_url = $photos[0]->url;
        }
        if (isset($row->facts) && is_string($row->facts)) {
            $row->facts = json_decode($row->facts, true);
        }

        return response()->json(['success' => true, 'data' => $row]);
    }

    /** @param  list<string>  $cols */
    private function search(string $table, Request $request, array $cols): array
    {
        if (! Schema::hasTable($table)) {
            return ['items' => [], 'ready' => false];
        }
        $q = trim((string) $request->query('q', ''));
        $kind = trim((string) $request->query('kind', ''));
        $query = DB::table($table)->where('status', 'active');
        if ($kind !== '') {
            $query->whereIn('kind', array_values(array_filter(explode(',', $kind))));
        }
        if ($q !== '') {
            $like = '%'.addcslashes($q, '%_\\').'%';
            $query->where(function ($w) use ($cols, $like) {
                foreach ($cols as $c) {
                    $w->orWhere($c, 'like', $like);
                }
            });
        }
        $fields = $table === 'cat_materials'
            ? ['key', 'kind', 'brand', 'name', 'material', 'image_url']
            : ['key', 'kind', 'brand', 'model', 'image_url'];

        try {
            return ['ready' => true, 'items' => $query->orderBy('brand')->limit(120)->get($fields)];
        } catch (\Throwable) {
            return ['items' => [], 'ready' => false];
        }
    }

    public function image(string $file)
    {
        $name = basename($file);
        $path = 'modules/custom-catalog/images/'.$name;
        if (! Storage::disk('local')->exists($path)) {
            abort(404);
        }

        return Storage::disk('local')->response($path);
    }
}
