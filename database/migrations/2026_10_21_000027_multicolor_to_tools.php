<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Modules\Custom\Catalog\Services\CatalogService;
use Modules\Custom\Catalog\Support\Schema;

/**
 * 0.3.6 🌈 다색 장치(AMS · CFS · ACE · QIDI BOX · CANVAS · IFS · MMU3 · INDX)를 부품 → 「주변 장비」의 다색 장치로 옮김.
 * 관련 프린터 · 표 · 후기 · 구매처 · 사진은 그대로 따라감 (같은 연결 표를 씀). 부속품(ACE 케이블 · 버퍼 같은 것)은 부품에 그대로.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::ensure();
        $file = dirname(__DIR__, 2).'/resources/assets/parts-seed-4.json';
        $j = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        $names = [];
        foreach ($j['items'] ?? [] as $it) {
            if (($it['unit'] ?? true) === false) {
                continue;   // 0.3.8 부속품(허브 · 키트 · 부품)은 부품에 남음
            }
            $names[CatalogService::norm($it['brand']).'|'.CatalogService::norm($it['name'])] = true;
        }
        $svc = new CatalogService();
        foreach (DB::table(Schema::PARTS)->where('kind', 'multi')->get() as $p) {
            if (! isset($names[CatalogService::norm($p->brand).'|'.CatalogService::norm($p->name)])) {
                continue;
            }
            try {
                $key = $svc->save('equipment', ['kind' => 'multicolor', 'brand' => $p->brand, 'title' => $p->name, 'summary' => (string) ($p->summary ?? ''),
                    'homepage_url' => (string) ($p->homepage_url ?? ''), 'values' => CatalogService::values('parts', $p), 'facts' => Schema::json($p->facts ?? null)])['key'];
            } catch (\Throwable) {
                continue;
            }
            DB::transaction(static function () use ($p, $key) {
                foreach ([Schema::FITS, Schema::VOTES, Schema::REVIEWS, Schema::LINKS] as $t) {
                    DB::table($t)->where('part_key', $p->key)->update(['part_key' => $key]);
                }
                DB::table(Schema::PHOTOS)->where('item_type', 'part')->where('item_key', $p->key)->update(['item_type' => 'equipment', 'item_key' => $key]);
                DB::table(Schema::EQUIPMENT)->where('key', $key)->update(['image_url' => $p->image_url, 'source' => $p->source ?? 'seed']);
                DB::table(Schema::PARTS)->where('id', $p->id)->delete();
            });
        }
    }

    public function down(): void {}
};
