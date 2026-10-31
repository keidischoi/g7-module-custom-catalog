<?php

namespace Modules\Custom\Catalog\Support;

use Illuminate\Support\Facades\DB;
use Modules\Custom\Catalog\Services\CatalogService;

/**
 * 0.3.24 레진 공식 제원 — resources/assets/resin-specs.json (제조사 페이지 원문과 대조한 값).
 *  빈 칸만 채움 · 이미 있는 값은 그대로 두고 다르면 conflicts 에 적음 · 범위 값은 ranges 에 원문, 칸에는 가운데 값.
 *  노출은 레진 + 프린터 + 층 높이 조합이라 print_profiles 로 — exposure_s 한 칸에 넣지 않음.
 *  0.3.25 카탈로그에 없는 제품은 create 로 새로 만듦 · grade slicer = 제조사 슬라이서 내장 값 (주소 없음)
 */
final class ResinSpecs
{
    /** 범위가 프린터마다 달라 한 값으로 줄이면 안 되는 칸 */
    public const NO_MID = ['exposure_s', 'bottom_exposure_s'];

    /** @return array{checked_at: string, grade: string, items: list<array<string, mixed>>} */
    public static function data(): array
    {
        return json_decode((string) file_get_contents(dirname(__DIR__, 2).'/resources/assets/resin-specs.json'), true);
    }

    /** 한 제품에 넣을 칸 값 @return array<string, mixed> */
    public static function values(array $item): array
    {
        $out = $item['values'] ?? [];
        foreach ($item['ranges'] ?? [] as $k => [$a, $b]) {
            if (! in_array($k, self::NO_MID, true) && ! isset($out[$k])) {
                $out[$k] = round(($a + $b) / 2, 3);
            }
        }
        foreach (['wash', 'cure_min'] as $k) {
            if (isset($item[$k])) {
                $out[$k] = $item[$k];
            }
        }

        return $out;
    }

    /**
     * 기존 specs 에 합침 (DB 없이 시험할 수 있게 따로)
     *
     * @return array<string, mixed>
     */
    public static function merge(array $specs, array $item, string $at, string $grade): array
    {
        $conflicts = [];
        foreach (self::values($item) as $k => $v) {
            if (! isset($specs[$k]) || $specs[$k] === '') {
                $specs[$k] = $v;
            } elseif ((string) $specs[$k] !== (string) $v) {
                $conflicts[$k] = $v;
            }
        }
        if (! empty($item['ranges'])) {
            $specs['ranges'] = $item['ranges'];
        }
        if (! empty($item['profiles'])) {
            $specs['print_profiles'] = $item['profiles'];
        }
        $specs['resin_src'] = array_filter(['grade' => $item['grade'] ?? $grade, 'url' => $item['url'] ?? null, 'at' => $at, 'note' => $item['note'] ?? null, 'conflicts' => $conflicts ?: null]);

        return $specs;
    }

    /** @return int 고친 제품 수 */
    public static function apply(): int
    {
        $data = self::data();
        $n = 0;
        foreach ($data['items'] as $item) {
            $keys = $item['keys'];
            if (! empty($item['create'])) {
                $keys[] = self::ensure($item['create']);
            }
            foreach (DB::table(Schema::MATERIALS)->whereIn('key', $keys)->where('kind', 'resin')->get() as $r) {
                $up = ['specs' => json_encode(self::merge(Schema::json($r->specs ?? null), $item, $data['checked_at'], $data['grade']), JSON_UNESCAPED_UNICODE)];
                if (trim((string) ($r->homepage_url ?? '')) === '' && ! empty($item['url'])) {
                    $up['homepage_url'] = $item['url'];
                }
                DB::table(Schema::MATERIALS)->where('id', $r->id)->update($up);
                $n++;
            }
        }

        return $n;
    }

    /** 없으면 새로 만듦 — 같은 제조사 · 이름이 있으면 그것 (재료 칸은 비었을 때만) */
    private static function ensure(array $c): string
    {
        foreach (DB::table(Schema::MATERIALS)->where('kind', 'resin')->where('brand', $c['brand'])->get() as $x) {
            if (CatalogService::norm($x->name) === CatalogService::norm($c['title'])) {
                return (string) $x->key;
            }
        }
        $key = (new CatalogService())->save('materials', ['kind' => 'resin', 'brand' => $c['brand'], 'title' => $c['title'], 'values' => ['material' => $c['material']]], false, 'official')['key'];

        return (string) $key;
    }

    /** 상세 화면 · 검사기로 보낼 모양 @return array<string, mixed>|null */
    public static function view(array $specs): ?array
    {
        if (empty($specs['resin_src'])) {
            return null;
        }

        return ['src' => $specs['resin_src'], 'ranges' => $specs['ranges'] ?? [], 'profiles' => $specs['print_profiles'] ?? []];
    }
}
