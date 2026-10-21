<?php

namespace Modules\Custom\Catalog\Support;

use Illuminate\Support\Facades\DB;
use Modules\Custom\Catalog\Services\CatalogService;
use Modules\Custom\Catalog\Services\PhotoService;

/**
 * 0.3.7 🌈 다색 장치 맞추기 (resources/assets/parts-seed-4.json) — 주변 장비의 「다색 장치」에 모두 있게
 *  - 이미 다색 장치로 있으면 그대로 · 같은 이름이 부품(어느 종류든)으로 있으면 옮김 (표 · 후기 · 구매처 · 사진 따라감) · 없으면 새로
 *  - 관련 프린터: 카탈로그에 있는 프린터만 이음 (빠진 연결만 더함)
 */
final class MulticolorSeed
{
    /** @return array{created: int, moved: int, fits: int, failed: list<string>} */
    public static function sync(string $file, int $seconds = 60): array
    {
        Schema::ensure();
        $j = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        $out = ['created' => 0, 'moved' => 0, 'fits' => 0, 'failed' => []];
        if (! is_array($j['items'] ?? null)) {
            return $out;
        }
        $svc = new CatalogService();
        $ph = new PhotoService();
        $eq = DB::table(Schema::EQUIPMENT)->whereIn('kind', ['fdm', 'sla', 'dlp'])->orderByRaw("case when status = 'active' then 0 else 1 end")->get(['key', 'brand', 'model']);
        $budget = microtime(true) + $seconds;
        foreach ($j['items'] as $it) {
            if (($it['unit'] ?? true) === false) {
                continue;   // 0.3.8 부속품은 부품 (accessories() 가 되돌림)
            }
            $nk = CatalogService::norm($it['brand']).'|'.CatalogService::norm($it['name']);
            $same = static fn ($r, string $col) => CatalogService::norm($r->brand).'|'.CatalogService::norm($r->{$col}) === $nk;
            $key = null;
            foreach (DB::table(Schema::EQUIPMENT)->where('kind', 'multicolor')->get(['key', 'brand', 'model', 'status']) as $e) {
                if ($same($e, 'model')) {
                    $key = (string) $e->key;
                    if ($e->status !== 'active') {
                        DB::table(Schema::EQUIPMENT)->where('key', $key)->update(['status' => 'active', 'updated_at' => now()]);
                    }
                    break;
                }
            }
            try {
                if ($key === null) {
                    $p = DB::table(Schema::PARTS)->get()->first(static fn ($r) => $same($r, 'name'));
                    $key = $svc->save('equipment', ['kind' => 'multicolor', 'brand' => $it['brand'], 'title' => $it['name'], 'summary' => (string) ($it['summary'] ?? ''),
                        'homepage_url' => (string) ($it['url'] ?? ''), 'values' => $it['values'] ?? [], 'facts' => $it['facts'] ?? []])['key'];
                    if ($p) {
                        self::move($p, $key);
                        $out['moved']++;
                    } else {
                        $out['created']++;
                    }
                    DB::table(Schema::EQUIPMENT)->where('key', $key)->update(['source' => 'seed']);
                }
                if (! empty($it['image']) && ! DB::table(Schema::PHOTOS)->where('item_type', 'equipment')->where('item_key', $key)->exists()) {
                    self::photo($ph, $key, $it, microtime(true) < $budget);
                }
            } catch (\Throwable $e) {
                $out['failed'][] = $it['name'].': '.mb_substr($e->getMessage(), 0, 120);

                continue;
            }
            foreach ($it['fits'] ?? [] as $f) {
                $pk = PartsSeed::printer($eq, (string) $f[0], (string) $f[1]);
                if ($pk === null) {
                    continue;
                }
                $st = in_array($f[2] ?? 'fit', ['fit', 'mod', 'no'], true) ? ($f[2] ?? 'fit') : 'fit';
                $out['fits'] += DB::table(Schema::FITS)->insertOrIgnore(['part_key' => $key, 'printer_key' => $pk, 'status' => $st === 'fit' ? null : $st, 'approved' => true,
                    'note' => isset($f[3]) ? mb_substr((string) $f[3], 0, 300) : null, 'created_at' => now(), 'updated_at' => now()]);
            }
        }

        return $out;
    }

    /** 0.3.8 부속품(unit: false — 허브 · CFS Nano Kit · IFS 부품)이 다색 장치로 가 있으면 부품(다색 장치 · 스풀)으로 되돌림 @return int 되돌린 수 */
    public static function accessories(string $file): int
    {
        Schema::ensure();
        $j = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        $svc = new CatalogService();
        $n = 0;
        foreach ($j['items'] ?? [] as $it) {
            if (($it['unit'] ?? true) !== false) {
                continue;
            }
            $nk = CatalogService::norm($it['brand']).'|'.CatalogService::norm($it['name']);
            foreach (DB::table(Schema::EQUIPMENT)->where('kind', 'multicolor')->get() as $e) {
                if (CatalogService::norm($e->brand).'|'.CatalogService::norm($e->model) !== $nk) {
                    continue;
                }
                try {
                    $key = $svc->save('parts', ['kind' => 'multi', 'brand' => $e->brand, 'title' => $e->model, 'summary' => (string) ($e->summary ?? ''), 'homepage_url' => (string) ($e->homepage_url ?? ''),
                        'values' => ($it['values'] ?? []) + CatalogService::values('equipment', $e), 'facts' => Schema::json($e->facts ?? null)])['key'];
                } catch (\Throwable) {
                    continue;
                }
                DB::transaction(static function () use ($e, $key) {
                    foreach ([Schema::FITS, Schema::VOTES, Schema::REVIEWS, Schema::LINKS] as $t) {
                        foreach (DB::table($t)->where('part_key', $e->key)->get() as $r) {
                            try {
                                DB::table($t)->where('id', $r->id)->update(['part_key' => $key]);
                            } catch (\Throwable) {
                                DB::table($t)->where('id', $r->id)->delete();
                            }
                        }
                    }
                    DB::table(Schema::PHOTOS)->where('item_type', 'equipment')->where('item_key', $e->key)->update(['item_type' => 'part', 'item_key' => $key]);
                    DB::table(Schema::PARTS)->where('key', $key)->update(['image_url' => $e->image_url, 'source' => $e->source ?? 'seed']);
                    DB::table(Schema::EQUIPMENT)->where('id', $e->id)->delete();
                });
                $n++;
            }
        }

        return $n;
    }

    /** 부품 → 다색 장치: 연결 · 표 · 후기 · 구매처 · 사진을 옮기고 부품은 지움 */
    public static function move(object $p, string $key): void
    {
        DB::transaction(static function () use ($p, $key) {
            foreach ([Schema::FITS, Schema::VOTES, Schema::REVIEWS, Schema::LINKS] as $t) {
                foreach (DB::table($t)->where('part_key', $p->key)->get() as $r) {
                    try {
                        DB::table($t)->where('id', $r->id)->update(['part_key' => $key]);
                    } catch (\Throwable) {
                        DB::table($t)->where('id', $r->id)->delete();   // 같은 연결이 이미 있으면 (고유 키) 예전 것은 지움
                    }
                }
            }
            DB::table(Schema::PHOTOS)->where('item_type', 'part')->where('item_key', $p->key)->update(['item_type' => 'equipment', 'item_key' => $key]);
            if (trim((string) ($p->image_url ?? '')) !== '') {
                DB::table(Schema::EQUIPMENT)->where('key', $key)->update(['image_url' => $p->image_url]);
            }
            DB::table(Schema::PARTS)->where('id', $p->id)->delete();
        });
    }

    /** @param array<string, mixed> $it */
    private static function photo(PhotoService $ph, string $key, array $it, bool $fetch): void
    {
        if ($fetch) {
            try {
                $ph->addFromUrl('equipment', $key, (string) $it['image'], (string) ($it['credit'] ?? ''), (string) ($it['url'] ?? ''));

                return;
            } catch (\Throwable) {
            }
        }
        DB::table(Schema::PHOTOS)->insert(['item_type' => 'equipment', 'item_key' => $key, 'url' => mb_substr((string) $it['image'], 0, 300), 'sort' => 0,
            'credit' => mb_substr((string) ($it['credit'] ?? ''), 0, 200) ?: null, 'source_url' => mb_substr((string) ($it['url'] ?? ''), 0, 500) ?: null, 'created_at' => now(), 'updated_at' => now()]);
        DB::table(Schema::EQUIPMENT)->where('key', $key)->update(['image_url' => mb_substr((string) $it['image'], 0, 300)]);
    }
}
