<?php

namespace Modules\Custom\Catalog\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Modules\Custom\Catalog\Support\AiSettings;
use Modules\Custom\Catalog\Support\CatalogSettings;

class AdminController extends Controller
{
    private function allowed(Request $request): bool
    {
        $user = $request->user();
        if (! $user) {
            return false;
        }
        foreach (['hasRole', 'hasPermission', 'can'] as $m) {
            if (! method_exists($user, $m)) {
                continue;
            }
            if ($m === 'hasRole' && ($user->hasRole('admin') || $user->hasRole('custom-catalog.editor'))) {
                return true;
            }
            if ($m !== 'hasRole' && $user->{$m}('custom-catalog.specs.update')) {
                return true;
            }
        }

        return false;
    }

    private function deny(): JsonResponse
    {
        return response()->json(['success' => false, 'message' => '제원 입력 권한이 없습니다.'], 403);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(["success" => true, "data" => ["can_edit" => $this->allowed($request)]]);
    }

    public function equipment(Request $request): JsonResponse
    {
        if (! $this->allowed($request)) return $this->deny();
        return response()->json(['success' => true, 'data' => $this->rows('cat_equipment', $request)]);
    }

    public function materials(Request $request): JsonResponse
    {
        if (! $this->allowed($request)) return $this->deny();
        return response()->json(['success' => true, 'data' => $this->rows('cat_materials', $request)]);
    }

    public function saveEquipment(Request $request): JsonResponse
    {
        if (! $this->allowed($request)) return $this->deny();
        return $this->save('cat_equipment', $request, ['kind', 'brand', 'model', 'build_x_mm', 'build_y_mm', 'build_z_mm', 'min_layer_um', 'multicolor', 'enclosed', 'nozzle', 'homepage_url', 'note', 'wiki_url', 'summary']);
    }

    public function saveMaterial(Request $request): JsonResponse
    {
        if (! $this->allowed($request)) return $this->deny();
        return $this->save('cat_materials', $request, ['kind', 'brand', 'name', 'material', 'color', 'color_hex', 'diameter', 'nozzle_min', 'nozzle_max', 'bed_min', 'bed_max', 'dry_temp', 'dry_hours', 'chamber', 'weight_g', 'traits', 'sds_url', 'storage_note', 'caution', 'wiki_url', 'summary']);
    }

    public function remove(Request $request, string $table, string $key): JsonResponse
    {
        if (! $this->allowed($request)) return $this->deny();
        $table = $table === 'materials' ? 'cat_materials' : 'cat_equipment';
        if (Schema::hasTable($table)) {
            DB::table($table)->where('key', $key)->update(['status' => 'archived', 'updated_at' => now()]);
        }

        return response()->json(['success' => true]);
    }

    private function rows(string $table, Request $request): array
    {
        if (! Schema::hasTable($table)) {
            return ['items' => []];
        }
        $q = trim((string) $request->query('q', ''));
        $query = DB::table($table)->where('status', 'active');
        if ($q !== '') {
            $like = '%'.addcslashes($q, '%_\\').'%';
            $query->where(function ($w) use ($like) {
                $w->orWhere('brand', 'like', $like)->orWhere('key', 'like', $like);
            });
        }

        return ['items' => $query->orderByDesc('id')->limit(100)->get()];
    }

    private function save(string $table, Request $request, array $fields): JsonResponse
    {
        if (! Schema::hasTable($table)) {
            return response()->json(['success' => false, 'message' => '표가 없습니다.'], 404);
        }
        $in = $request->all();
        $brand = trim((string) ($in['brand'] ?? ''));
        $title = trim((string) ($in['model'] ?? $in['name'] ?? ''));
        if ($brand === '' || $title === '') {
            return response()->json(['success' => false, 'message' => '제조사와 이름을 입력하세요.'], 422);
        }
        $key = Str::slug((string) ($in['key'] ?? ($brand.'-'.$title)), '-');
        $key = substr($key !== '' ? $key : 'item-'.time(), 0, $table === 'cat_equipment' ? 60 : 80);
        $row = ['key' => $key, 'brand' => mb_substr($brand, 0, 60), 'status' => 'active', 'updated_at' => now()];
        if ($table === 'cat_equipment') {
            $row['model'] = mb_substr($title, 0, 80);
        } else {
            $row['name'] = mb_substr($title, 0, 80);
            $row['material'] = mb_substr(trim((string) ($in['material'] ?? '')), 0, 40);
            $row['material_norm'] = mb_strtolower($row['material']);
        }
        foreach ($fields as $f) {
            if (! array_key_exists($f, $in) || in_array($f, ['brand', 'model', 'name'], true)) {
                continue;
            }
            $row[$f] = $in[$f] === '' ? null : $in[$f];
        }
        $exists = DB::table($table)->where('key', $key)->exists();
        if ($exists) {
            DB::table($table)->where('key', $key)->update($row);
        } else {
            $row['created_at'] = now();
            DB::table($table)->insert($row);
        }

        return response()->json(['success' => true, 'data' => ['key' => $key]]);
    }
}


    public function ai(Request $request): JsonResponse
    {
        if (! $this->allowed($request)) return $this->deny();
        return response()->json(["success" => true, "data" => AiSettings::public()]);
    }

    public function saveAi(Request $request): JsonResponse
    {
        if (! $this->allowed($request)) return $this->deny();
        AiSettings::save($request->all());
        return response()->json(["success" => true, "data" => AiSettings::public()]);
    }

    public function suggest(Request $request): JsonResponse
    {
        if (! $this->allowed($request)) return $this->deny();
        $s = AiSettings::all();
        if (! $s["enabled"]) {
            return response()->json(["success" => false, "message" => "AI가 꺼져 있습니다."], 422);
        }
        $prompt = "3D 프린터나 필라멘트 공개 제원 후보를 JSON 배열로만 답하세요. 배합은 쓰지 마세요. 각 항목은 brand, model, kind, note. 기존 카드를 덮지 않는 제안만.";
        try {
            $text = $this->ask($s, $prompt);
        } catch (\Throwable $e) {
            return response()->json(["success" => false, "message" => "AI 연결에 실패했습니다."], 502);
        }
        return response()->json(["success" => true, "data" => ["text" => mb_substr($text, 0, 4000)]]);
    }

    private function ask(array $s, string $prompt): string
    {
        $provider = $s["provider"];
        $url = rtrim((string) $s["url"], "/");
        $model = (string) $s["model"];
        if ($provider === "ollama") {
            $res = Http::timeout(60)->post($url."/api/chat", ["model" => $model, "stream" => false, "messages" => [["role" => "user", "content" => $prompt]]]);
            return (string) ($res->json("message.content") ?? "");
        }
        $base = $provider === "claude" ? $url."/messages" : $url."/chat/completions";
        $headers = ["Authorization" => "Bearer ".$s["api_key"]];
        $body = $provider === "claude"
            ? ["model" => $model, "max_tokens" => 800, "messages" => [["role" => "user", "content" => $prompt]]]
            : ["model" => $model, "messages" => [["role" => "user", "content" => $prompt]]];
        $res = Http::timeout(60)->withHeaders($headers)->post($base, $body);
        return (string) ($res->json("choices.0.message.content") ?? $res->json("content.0.text") ?? "");
    }


    public function kinds(): JsonResponse
    {
        return response()->json(['success' => true, 'data' => CatalogSettings::all()]);
    }

    public function saveKinds(Request $request): JsonResponse
    {
        if (! $this->allowed($request)) {
            return $this->deny();
        }

        return response()->json(['success' => true, 'data' => CatalogSettings::save($request->all())]);
    }

    public function uploadPhotos(Request $request): JsonResponse
    {
        if (! $this->allowed($request)) {
            return $this->deny();
        }
        $type = $request->input('type') === 'material' ? 'material' : 'equipment';
        $key = substr(preg_replace('/[^a-z0-9_-]/', '', strtolower((string) $request->input('key'))), 0, 80);
        $table = $type === 'material' ? 'cat_materials' : 'cat_equipment';
        if ($key === '' || ! Schema::hasTable($table) || ! DB::table($table)->where('key', $key)->exists()) {
            return response()->json(['success' => false, 'message' => '항목이 없습니다.'], 404);
        }
        $files = $request->file('photos', []);
        if (! is_array($files)) {
            $files = [$files];
        }
        $files = array_values(array_filter($files));
        if ($files === []) {
            return response()->json(['success' => false, 'message' => '사진을 선택하세요.'], 422);
        }
        if (! Schema::hasTable('cat_photos')) {
            return response()->json(['success' => false, 'message' => '사진 표가 없습니다. migrate를 실행하세요.'], 422);
        }
        $have = (int) DB::table('cat_photos')->where('item_type', $type)->where('item_key', $key)->count();
        $sort = (int) DB::table('cat_photos')->where('item_type', $type)->where('item_key', $key)->max('sort');
        $saved = [];
        foreach (array_slice($files, 0, max(0, 12 - $have)) as $file) {
            if (! $file->isValid() || $file->getSize() > 4_000_000) {
                continue;
            }
            $raw = (string) file_get_contents($file->getRealPath());
            $jpeg = $this->fitJpeg($raw);
            if ($jpeg === null) {
                continue;
            }
            $name = $key.'-'.substr(md5($jpeg.microtime(true)), 0, 10).'.jpg';
            $dest = 'modules/custom-catalog/images/'.$name;
            \Illuminate\Support\Facades\Storage::disk('local')->put($dest, $jpeg);
            $url = '/api/modules/custom-catalog/images/'.$name;
            $sort++;
            $id = DB::table('cat_photos')->insertGetId([
                'item_type' => $type, 'item_key' => $key, 'url' => $url, 'sort' => $sort,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $saved[] = ['id' => $id, 'url' => $url];
        }
        if ($saved !== [] && Schema::hasColumn($table, 'image_url')) {
            $first = DB::table('cat_photos')->where('item_type', $type)->where('item_key', $key)->orderBy('sort')->value('url');
            DB::table($table)->where('key', $key)->update(['image_url' => $first, 'updated_at' => now()]);
        }

        return response()->json(['success' => true, 'data' => ['photos' => $saved]]);
    }

    public function deletePhoto(Request $request, int $id): JsonResponse
    {
        if (! $this->allowed($request)) {
            return $this->deny();
        }
        if (! Schema::hasTable('cat_photos')) {
            return response()->json(['success' => false], 404);
        }
        $photo = DB::table('cat_photos')->where('id', $id)->first();
        if (! $photo) {
            return response()->json(['success' => false, 'message' => '없습니다.'], 404);
        }
        DB::table('cat_photos')->where('id', $id)->delete();
        $table = $photo->item_type === 'material' ? 'cat_materials' : 'cat_equipment';
        $next = DB::table('cat_photos')->where('item_type', $photo->item_type)->where('item_key', $photo->item_key)->orderBy('sort')->value('url');
        if (Schema::hasColumn($table, 'image_url')) {
            DB::table($table)->where('key', $photo->item_key)->update(['image_url' => $next, 'updated_at' => now()]);
        }

        return response()->json(['success' => true]);
    }

    private function fitJpeg(string $raw): ?string
    {
        if (! function_exists('imagecreatefromstring')) {
            return strlen($raw) <= 80000 && str_starts_with($raw, "\xFF\xD8") ? $raw : null;
        }
        $im = @imagecreatefromstring($raw);
        if (! $im) {
            return null;
        }
        $w = imagesx($im);
        $h = imagesy($im);
        $scale = 720 / max($w, $h, 1);
        if ($scale < 1) {
            $nw = max(1, (int) ($w * $scale));
            $nh = max(1, (int) ($h * $scale));
            $next = imagecreatetruecolor($nw, $nh);
            imagecopyresampled($next, $im, 0, 0, 0, 0, $nw, $nh, $w, $h);
            imagedestroy($im);
            $im = $next;
        }
        ob_start();
        imagejpeg($im, null, 70);
        imagedestroy($im);

        return (string) ob_get_clean();
    }
}
