<?php

namespace Modules\Custom\Catalog\Support;

use Illuminate\Support\Facades\DB;
use Modules\Custom\Catalog\Services\CatalogService;
use Modules\Custom\Catalog\Services\PartsService;
use Modules\Custom\Catalog\Services\PhotoService;

/**
 * 0.3.4 프린터 기본 제원 · 대표 사진 채우기 (마이그레이션에서) — resources/assets/printers-seed.json · seed-specs-0.2.2.json
 *  - 카탈로그에 이미 있는 프린터만 (새로 만들지 않음). 같은 프린터의 다른 항목(Combo · 중복)도 함께
 *  - 비어 있는 칸만 채움. 다색 출력만 「아니오」 → 「예」로 고침 (기본값이 「아니오」라 비어 있는 것과 같아서)
 *  - 사진: 사진이 하나도 없을 때만 — 받아 두고, 시간이 넘으면 공식 스토어 사진 주소를 그대로
 */
final class PrinterSeed
{
    /** @return array{filled: int, photos: int} */
    public static function load(string $file, int $seconds = 120): array
    {
        Schema::ensure();
        $j = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        if (! is_array($j['items'] ?? null)) {
            return ['filled' => 0, 'photos' => 0];
        }
        $svc = new CatalogService();
        $ph = new PhotoService();
        $eq = DB::table(Schema::EQUIPMENT)->whereIn('kind', ['fdm', 'sla', 'dlp'])->orderByRaw("case when status = 'active' then 0 else 1 end")->get(['key', 'brand', 'model']);
        $budget = microtime(true) + $seconds;
        $filled = 0;
        $photos = 0;
        foreach ($j['items'] as $it) {
            $pk = PartsSeed::printer($eq, (string) ($it['brand'] ?? ''), (string) ($it['model'] ?? ''));
            if ($pk === null) {
                continue;
            }
            $vals = is_array($it['values'] ?? null) ? $it['values'] : [];
            foreach (PartsService::sameKeys($pk) as $key) {
                $row = DB::table(Schema::EQUIPMENT)->where('key', $key)->first();
                if (! $row) {
                    continue;
                }
                if ($vals) {
                    try {
                        $svc->save('equipment', ['key' => $key, 'values' => $vals], true);
                        $filled++;
                    } catch (\Throwable) {
                    }
                    if (($vals['multicolor'] ?? false) === true && ! $row->multicolor) {
                        DB::table(Schema::EQUIPMENT)->where('key', $key)->update(['multicolor' => true, 'updated_at' => now()]);
                    }
                }
                if (! empty($it['image']) && trim((string) $row->image_url) === '' && ! DB::table(Schema::PHOTOS)->where('item_type', 'equipment')->where('item_key', $key)->exists()) {
                    self::photo($ph, $key, $it, microtime(true) < $budget);
                    $photos++;
                }
            }
        }

        return ['filled' => $filled, 'photos' => $photos];
    }

    /** @param array<string, mixed> $it */
    private static function photo(PhotoService $ph, string $key, array $it, bool $fetch): void
    {
        $img = (string) $it['image'];
        if ($fetch) {
            try {
                $ph->addFromUrl('equipment', $key, $img, (string) ($it['credit'] ?? ''), (string) ($it['url'] ?? ''));

                return;
            } catch (\Throwable) {
            }
        }
        DB::table(Schema::PHOTOS)->insert(['item_type' => 'equipment', 'item_key' => $key, 'url' => mb_substr($img, 0, 300), 'sort' => 0,
            'credit' => mb_substr((string) ($it['credit'] ?? ''), 0, 200) ?: null, 'source_url' => mb_substr((string) ($it['url'] ?? ''), 0, 500) ?: null, 'created_at' => now(), 'updated_at' => now()]);
        DB::table(Schema::EQUIPMENT)->where('key', $key)->update(['image_url' => mb_substr($img, 0, 300)]);
    }
}
