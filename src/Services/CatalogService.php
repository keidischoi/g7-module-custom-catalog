<?php

namespace Modules\Custom\Catalog\Services;

use Illuminate\Support\Facades\DB;
use Modules\Custom\Catalog\Support\Fields;
use Modules\Custom\Catalog\Support\Schema;

/**
 * 0.2.0 카탈로그 — 목록 · 상세 · 저장 · 지우기.
 * 장비(cat_equipment) · 재료(cat_materials) 를 같은 꼴로 다룸. type = equipment | materials.
 */
final class CatalogService
{
    public static function norm(?string $s): string
    {
        return (string) preg_replace('/[\s\-_.·\/]+/u', '', mb_strtolower(trim((string) $s)));
    }

    public static function titleCol(string $type): string
    {
        return $type === 'materials' ? 'name' : 'model';
    }

    /**
     * 목록 — 탭(종류 묶음) · 제조사 · 검색 · 정렬 · 쪽
     *
     * @param  array<string, mixed>  $p  {type, kinds[], brand, q, sort, page, per, flags[], status}
     * @return array{items: list<array<string, mixed>>, total: int, page: int, pages: int, brands: list<array{name: string, n: int}>}
     */
    public function list(array $p): array
    {
        Schema::ensure();
        $type = ($p['type'] ?? '') === 'materials' ? 'materials' : 'equipment';
        $table = Schema::table($type);
        $title = self::titleCol($type);
        $status = in_array($p['status'] ?? '', ['archived', 'all'], true) ? $p['status'] : 'active';
        $base = DB::table($table);
        if ($status !== 'all') {
            $base->where('status', $status);
        }
        $kinds = array_values(array_filter((array) ($p['kinds'] ?? []), static fn ($k) => Fields::validKind($type, (string) $k)));
        if ($kinds) {
            $base->whereIn('kind', $kinds);
        }
        $q = trim((string) ($p['q'] ?? ''));
        if ($q !== '') {
            // 「필라멘트」 · 「레진 프린터」 · 「Elegoo 레진」 처럼 종류 이름이 들어 있으면 그 종류로 거르고, 남은 낱말로 찾음
            [$byWord, $words] = self::kindWords($type, $q);
            if ($byWord !== null) {
                $base->whereIn('kind', $byWord ?: ['-']);
            }
            $cols = $type === 'materials' ? ['brand', 'name', 'material', 'key'] : ['brand', 'model', 'key'];
            foreach (array_slice($words, 0, 5) as $word) {
                $like = '%'.addcslashes($word, '%_\\').'%';
                $base->where(function ($w) use ($cols, $like) {
                    foreach ($cols as $c) {
                        $w->orWhere($c, 'like', $like);
                    }
                });
            }
        }
        // 제조사 칩은 제조사를 고르기 전 숫자로
        $brands = (clone $base)->select('brand', DB::raw('count(*) as n'))->groupBy('brand')->orderByDesc('n')->orderBy('brand')->limit(80)->get()
            ->map(static fn ($r) => ['name' => (string) $r->brand, 'n' => (int) $r->n])->all();
        $brand = trim((string) ($p['brand'] ?? ''));
        if ($brand !== '') {
            $base->where('brand', $brand);
        }
        foreach ((array) ($p['flags'] ?? []) as $f) {
            if ($type === 'equipment' && in_array($f, ['multicolor', 'enclosed'], true)) {
                $base->where($f, 1);
            } elseif ($f === 'photo') {
                $base->whereNotNull('image_url')->where('image_url', '!=', '');
            } elseif ($f === 'nophoto') {
                $base->where(static fn ($w) => $w->whereNull('image_url')->orWhere('image_url', ''));
            }
        }
        $total = (clone $base)->count();
        $per = max(1, min(200, (int) ($p['per'] ?? 24)));
        $pages = max(1, (int) ceil($total / $per));
        $page = max(1, min($pages, (int) ($p['page'] ?? 1)));
        match ($p['sort'] ?? '') {
            'new' => $base->orderByDesc('id'),
            'size' => $type === 'equipment' ? $base->orderByRaw('(coalesce(build_x_mm,0) * coalesce(build_y_mm,0) * coalesce(build_z_mm,0)) desc')->orderBy('brand') : $base->orderBy('brand')->orderBy($title),
            'updated' => $base->orderByDesc('updated_at'),
            'name' => $base->orderBy('brand')->orderBy($title),
            default => $base->orderByRaw("case when image_url is null or image_url = '' then 1 else 0 end")->orderBy('brand')->orderBy($title),   // 사진 있는 것부터
        };
        $rows = $base->offset(($page - 1) * $per)->limit($per)->get();

        return ['items' => $rows->map(fn ($r) => $this->card($type, $r))->all(), 'total' => $total, 'page' => $page, 'pages' => $pages, 'brands' => $brands];
    }

    /** 검색에서 종류로 읽는 다른 이름 (띄어쓰기 · 「·」 없이 소문자) */
    private const KIND_ALIASES = [
        'equipment' => ['프린터' => ['fdm', 'sla', 'dlp', 'sls', 'mjf', 'metal'], '3d프린터' => ['fdm', 'sla', 'dlp', 'sls', 'mjf', 'metal'], 'fdm' => ['fdm'],
            'fdm프린터' => ['fdm'], '레진프린터' => ['sla', 'dlp'], '광경화프린터' => ['sla', 'dlp'], 'sla' => ['sla'], 'dlp' => ['dlp'], 'lcd' => ['dlp'], 'msla' => ['dlp'],
            'sls' => ['sls'], 'mjf' => ['mjf'], 'cnc' => ['cnc'], '레이저' => ['laser'], '스캐너' => ['scanner'], '건조기' => ['dryer'], '세척경화기' => ['wash_cure']],
        'materials' => ['필라멘트' => ['fdm'], '레진' => ['resin'], '수지' => ['resin'], '분말' => ['powder'], '파우더' => ['powder'], '금속분말' => ['powder']],
    ];

    /**
     * 검색어에서 종류 이름을 골라냄 — 낱말 1~4개를 이어 붙인 것이 탭 이름 · 종류 이름 · 다른 이름과 같으면 종류로.
     *
     * @return array{0: list<string>|null, 1: list<string>}  [종류들(없으면 null), 남은 낱말]
     */
    public static function kindWords(string $type, string $q): array
    {
        $n = static fn (string $s): string => mb_strtolower(preg_replace('/[\s·・\-_]+/u', '', $s) ?? $s);
        $map = [];
        foreach (Fields::TABS as $t) {
            if ($t[3] === $type) {
                $map[$n($t[1])] = $t[4];
            }
        }
        foreach ($type === 'materials' ? Fields::MATERIAL_KINDS : Fields::EQUIPMENT_KINDS as $k => $v) {
            $map[$n($v[0])] = array_values(array_unique(array_merge($map[$n($v[0])] ?? [], [$k])));
        }
        $map += self::KIND_ALIASES[$type];
        $words = array_values(array_filter(preg_split('/\s+/u', trim($q)) ?: [], static fn ($w) => $w !== '' && $w !== '·'));
        $kinds = null;
        $rest = [];
        for ($i = 0; $i < count($words);) {
            $hit = 0;
            for ($len = min(4, count($words) - $i); $len >= 1; $len--) {
                $key = $n(implode('', array_slice($words, $i, $len)));
                if (isset($map[$key])) {
                    $kinds = $kinds === null ? $map[$key] : array_values(array_intersect($kinds, $map[$key]));
                    $hit = $len;
                    break;
                }
            }
            if ($hit) {
                $i += $hit;
            } else {
                $rest[] = $words[$i++];
            }
        }

        return [$kinds, $rest];
    }

    /** 탭마다 몇 개 @return array<string, int> */
    public function counts(): array
    {
        Schema::ensure();
        $out = [];
        $by = [];
        foreach (['equipment', 'materials'] as $type) {
            $by[$type] = DB::table(Schema::table($type))->where('status', 'active')->select('kind', DB::raw('count(*) as n'))->groupBy('kind')->pluck('n', 'kind')->all();
        }
        foreach (Fields::TABS as $t) {
            $out[$t[0]] = array_sum(array_map(static fn ($k) => (int) ($by[$t[3]][$k] ?? 0), $t[4]));
        }

        return $out;
    }

    /** 카드 한 장 (목록) @return array<string, mixed> */
    public function card(string $type, object $r): array
    {
        $title = (string) ($r->{self::titleCol($type)} ?? '');
        $chips = [];
        if ($type === 'equipment') {
            if ($r->build_x_mm && $r->build_y_mm) {
                $chips[] = $r->build_x_mm.' × '.$r->build_y_mm.($r->build_z_mm ? ' × '.$r->build_z_mm : '').' mm';
            }
            $sp = Schema::json($r->specs ?? null);
            if (! empty($sp['speed_max'])) {
                $chips[] = $sp['speed_max'].' mm/s';
            }
            if (! empty($r->multicolor)) {
                $chips[] = '다색';
            }
            if (! empty($r->enclosed)) {
                $chips[] = '밀폐';
            }
        } else {
            if (trim((string) $r->material) !== '') {
                $chips[] = (string) $r->material;
            }
            if ($r->nozzle_min && $r->nozzle_max) {
                $chips[] = '노즐 '.$r->nozzle_min.'–'.$r->nozzle_max.'°C';
            }
            if ($r->weight_g) {
                $chips[] = $r->weight_g >= 1000 && $r->weight_g % 1000 === 0 ? ($r->weight_g / 1000).' kg' : $r->weight_g.' g';
            }
        }

        return ['key' => (string) $r->key, 'type' => $type, 'kind' => (string) $r->kind, 'kind_label' => Fields::kindLabel($type, (string) $r->kind),
            'brand' => (string) $r->brand, 'title' => $title, 'image' => PhotoService::thumb(self::imageOf($type, $r)[0]), 'chips' => $chips,
            'enclosed' => $type === 'equipment' ? ! empty($r->enclosed) : false, 'material' => $type === 'materials' ? (string) $r->material : '',
            'color_hex' => $type === 'materials' ? (string) ($r->color_hex ?? '') : '', 'status' => (string) $r->status];
    }

    /** 업체검색(custom-companies)의 모델 대표 사진 — 관리자가 고른 것만 (ModelBook::photo) */
    public static function companyPhoto(string $kind, string $brand, string $model): string
    {
        $MB = '\Modules\Custom\Companies\Services\ModelBook';
        try {
            return class_exists($MB) && method_exists($MB, 'photo') ? (string) ($MB::photo($kind, $brand, $model) ?? '') : '';
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * 0.2.1 보여 줄 사진 — 관리자 설정(image_mode)대로:
     *   auto    카탈로그 사진 → 업체검색 대표 사진 → 그림
     *   company 업체검색 대표 사진 → 카탈로그 사진 → 그림
     *   catalog 카탈로그 사진만 (없으면 그림)
     *   icon    그림만 (사진을 쓰지 않음)
     * 「그림」은 화면이 그림 (업체검색과 같은 입체 그림) — 여기서는 빈 글.
     *
     * @return array{0: string, 1: string} [주소, 어디 것(catalog|company|'')]
     */
    public static function imageOf(string $type, object $r): array
    {
        $mode = (string) \Modules\Custom\Catalog\Support\Settings::get('image_mode');
        if ($mode === 'icon') {
            return ['', ''];
        }
        $own = trim((string) ($r->image_url ?? ''));
        $co = $mode !== 'catalog' && $type === 'equipment' && ($mode === 'company' || $own === '') ? self::companyPhoto((string) $r->kind, (string) $r->brand, (string) ($r->model ?? '')) : '';
        if ($mode === 'company' && $co !== '') {
            return [$co, 'company'];
        }

        return $own !== '' ? [$own, 'catalog'] : ($co !== '' ? [$co, 'company'] : ['', '']);
    }

    /** 어느 표에 있는지 찾아서 @return array{0: string, 1: object}|null */
    public function find(string $key, ?string $type = null, bool $any = false): ?array
    {
        Schema::ensure();
        foreach ($type ? [$type] : ['equipment', 'materials'] as $t) {
            $q = DB::table(Schema::table($t))->where('key', $key);
            if (! $any) {
                $q->where('status', 'active');
            }
            if ($r = $q->first()) {
                return [$t, $r];
            }
        }

        return null;
    }

    /** 칸 값 (표의 칸 + specs) @return array<string, mixed> */
    public static function values(string $type, object $r): array
    {
        $specs = Schema::json($r->specs ?? null);
        $out = [];
        foreach (Fields::forKind($type, (string) $r->kind) as $d) {
            $k = $d['key'];
            $v = $d['col'] ? ($r->{$k} ?? null) : ($specs[$k] ?? null);
            if ($d['col']) {
                if ($k === 'materials') {
                    $v = Schema::json($v) ?: null;
                } elseif ($k === 'traits') {
                    $v = trim((string) $v) !== '' ? array_values(array_filter(array_map('trim', preg_split('/[,·]+/u', (string) $v) ?: []))) : null;
                } elseif ($k === 'released_on') {
                    $v = $v ? substr((string) $v, 0, 7) : null;
                } elseif ($k === 'chamber') {
                    $v = ['required' => '필요', 'recommended' => '권장', 'none' => '불필요'][$v] ?? $v;
                } elseif ($d['type'] === 'bool') {
                    $v = $v === null ? null : (bool) $v;
                } elseif ($d['type'] === 'num' && $v !== null) {
                    $v = (float) $v == (int) $v ? (int) $v : (float) $v;
                } elseif ($k === 'diameter' && $v !== null) {
                    $v = rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.');
                }
            }
            if ($v !== null && $v !== '' && $v !== []) {
                $out[$k] = $v;
            }
        }

        return $out;
    }

    /** 채워진 칸 수 · 전체 칸 수 @return array{0: int, 1: int} */
    public static function filled(string $type, object $r): array
    {
        return [count(self::values($type, $r)), count(Fields::forKind($type, (string) $r->kind))];
    }

    /** 상세 @return array<string, mixed> */
    public function detail(string $type, object $r, bool $edit = false): array
    {
        $vals = self::values($type, $r);
        $groups = [];
        foreach (Fields::forKind($type, (string) $r->kind) as $d) {
            if (! isset($vals[$d['key']])) {
                continue;
            }
            $shown = Fields::show($d, $vals[$d['key']]);
            if ($shown === '' || ($d['type'] === 'bool' && $vals[$d['key']] === false && ! $d['col'])) {
                continue;
            }
            $groups[$d['group']][] = ['key' => $d['key'], 'label' => $d['label'], 'value' => $shown, 'url' => $d['type'] === 'url'];
        }
        $facts = [];
        foreach (Schema::json($r->facts ?? null) as $k => $v) {
            if (is_scalar($v) && trim((string) $v) !== '') {
                $facts[] = ['label' => (string) $k, 'value' => (string) $v];
            }
        }
        $photos = DB::table(Schema::PHOTOS)->where('item_type', $type === 'materials' ? 'material' : 'equipment')->where('item_key', $r->key)->orderBy('sort')->orderBy('id')
            ->get()->map(static fn ($p) => ['id' => (int) $p->id, 'url' => (string) $p->url, 'thumb' => PhotoService::thumb((string) $p->url), 'credit' => (string) ($p->credit ?? ''), 'source_url' => (string) ($p->source_url ?? '')])->all();
        if ($photos === [] && trim((string) ($r->image_url ?? '')) !== '') {
            $photos = [['id' => 0, 'url' => (string) $r->image_url, 'thumb' => (string) $r->image_url, 'credit' => '', 'source_url' => '']];
        }
        // 0.2.1 보여 줄 사진은 관리자 설정대로 — 편집하는 사람에게는 카탈로그 사진을 모두 보여 줌 (올리고 지우려면)
        [$shown, $from] = self::imageOf($type, $r);
        $mode = (string) \Modules\Custom\Catalog\Support\Settings::get('image_mode');
        if ($from === 'company') {
            array_unshift($photos, ['id' => 0, 'url' => $shown, 'thumb' => $shown, 'credit' => '업체검색 대표 사진', 'source_url' => '']);
            if (! $edit) {
                $photos = array_slice($photos, 0, $mode === 'company' ? 1 : 12);
            }
        } elseif ($shown === '' && ! $edit) {
            $photos = [];
        }
        [$n, $all] = self::filled($type, $r);
        $out = $this->card($type, $r) + [
            'summary' => (string) ($r->summary ?? ''), 'note' => (string) ($r->note ?? ''),
            // 0.2.2 자세한 설명 · 알려진 문제(한 줄에 하나) · 메모
            'detail' => (string) ($r->detail ?? ''), 'memo' => (string) ($r->memo ?? ''),
            'issues' => array_values(array_filter(array_map(static fn ($l) => trim(ltrim(trim($l), '-•·*')), preg_split('/\R/u', (string) ($r->issues ?? '')) ?: []), static fn ($l) => $l !== '')),
            'homepage_url' => (string) ($r->homepage_url ?? ''), 'wiki_url' => (string) ($r->wiki_url ?? ''),
            'groups' => array_map(static fn ($g, $items) => ['group' => $g, 'items' => $items], array_keys($groups), $groups),
            'facts' => $facts, 'photos' => $photos, 'filled' => $n, 'fields' => $all,
            'updated_at' => substr((string) $r->updated_at, 0, 10),
            'related' => $this->related($type, $r),
        ];
        if ($edit) {
            $out['values'] = $vals;
            $out['facts_raw'] = Schema::json($r->facts ?? null);
        }

        return $out;
    }

    /** 같은 제조사의 다른 것 @return list<array<string, mixed>> */
    private function related(string $type, object $r): array
    {
        return DB::table(Schema::table($type))->where('status', 'active')->where('brand', $r->brand)->where('key', '!=', $r->key)
            ->orderByRaw('case when kind = ? then 0 else 1 end', [$r->kind])->orderByDesc('id')->limit(6)->get()->map(fn ($x) => $this->card($type, $x))->all();
    }

    /**
     * 저장 (새로 · 고치기)
     *
     * @param  array<string, mixed>  $in  {key?, kind, brand, title, values{}, facts{}, summary, note, homepage_url, wiki_url}
     * @param  bool  $onlyEmpty  AI 채우기 — 빈 칸만
     * @return array{key: string, created: bool}
     */
    public function save(string $type, array $in, bool $onlyEmpty = false, string $source = ''): array
    {
        Schema::ensure();
        $table = Schema::table($type);
        $tcol = self::titleCol($type);
        $key = trim((string) ($in['key'] ?? ''));
        $old = $key !== '' ? DB::table($table)->where('key', $key)->first() : null;
        $brand = mb_substr(trim(strip_tags((string) ($in['brand'] ?? ($old->brand ?? '')))), 0, 60);
        $title = mb_substr(trim(strip_tags((string) ($in['title'] ?? ($old->{$tcol} ?? '')))), 0, 80);
        $kind = (string) ($in['kind'] ?? ($old->kind ?? ''));
        if ($brand === '' || $title === '') {
            throw new \InvalidArgumentException('제조사와 '.($type === 'materials' ? '제품 이름' : '모델 이름').'을 적어 주세요.');
        }
        if (! Fields::validKind($type, $kind)) {
            throw new \InvalidArgumentException('종류를 골라 주세요.');
        }
        if (! $old) {
            // 같은 제조사 · 같은 이름 · 같은 종류가 이미 있으면 그것을 고침 (대소문자 · 띄어쓰기 무시)
            foreach (DB::table($table)->where('kind', $kind)->where('brand', 'like', mb_substr($brand, 0, 3).'%')->get() as $x) {
                if (self::norm($x->brand) === self::norm($brand) && self::norm($x->{$tcol}) === self::norm($title)) {
                    if ($onlyEmpty && $source !== '') {
                        throw new \InvalidArgumentException('이미 있는 항목이에요: '.$x->brand.' '.$x->{$tcol});
                    }
                    $old = $x;
                    break;
                }
            }
        }
        $row = ['brand' => $brand, $tcol => $title, 'kind' => $kind, 'updated_at' => now()];
        $specs = Schema::json($old->specs ?? null);
        $vals = is_array($in['values'] ?? null) ? $in['values'] : [];
        $cur = $old ? self::values($type, (object) array_merge((array) $old, ['kind' => $kind])) : [];
        foreach (Fields::forKind($type, $kind) as $d) {
            $k = $d['key'];
            if (! array_key_exists($k, $vals) || ($onlyEmpty && isset($cur[$k]))) {
                continue;
            }
            $v = Fields::clean($d, $vals[$k]);
            if (! $d['col']) {
                if ($v === null) {
                    unset($specs[$k]);
                } else {
                    $specs[$k] = $v;
                }

                continue;
            }
            $row[$k] = match (true) {
                $k === 'materials' => $v === null ? null : json_encode($v, JSON_UNESCAPED_UNICODE),
                $k === 'traits' => $v === null ? null : mb_substr(implode(', ', $v), 0, 200),
                $k === 'released_on' => preg_match('/^(\d{4})(?:[-.\/](\d{1,2}))?/', (string) $v, $m) ? sprintf('%04d-%02d-01', $m[1], max(1, min(12, (int) ($m[2] ?? 1)))) : null,
                $k === 'chamber' => $v === null ? null : mb_substr((string) $v, 0, 12),
                $k === 'multicolor' => (bool) $v,
                $k === 'color' => $v === null ? null : mb_substr((string) $v, 0, 30),
                $k === 'color_hex' => $v === null ? null : mb_substr((string) $v, 0, 120),
                $k === 'material' => mb_substr((string) ($v ?? ''), 0, 40),
                $k === 'nozzle' => $v === null ? null : mb_substr((string) $v, 0, 120),
                $d['type'] === 'num' => $v === null ? null : ($k === 'diameter' ? (float) $v : max(0, min($k === 'weight_g' ? 4000000 : ($k === 'dry_hours' ? 255 : 65535), (int) round((float) $v)))),
                default => $v,
            };
        }
        if ($type === 'materials') {
            $row['material'] = (string) ($row['material'] ?? ($old->material ?? ''));
            $row['material_norm'] = mb_substr(mb_strtolower($row['material']), 0, 40);
        }
        $row['specs'] = $specs ? json_encode($specs, JSON_UNESCAPED_UNICODE) : null;
        foreach (['summary' => 4000, 'note' => 500] as $k => $len) {
            if (array_key_exists($k, $in) && ! ($onlyEmpty && trim((string) ($old->{$k} ?? '')) !== '')) {
                $t = mb_substr(trim(strip_tags((string) $in[$k])), 0, $len);
                $row[$k] = $t === '' ? null : $t;
            }
        }
        foreach (['detail' => 10000, 'issues' => 4000, 'memo' => 4000] as $k => $len) {
            if (array_key_exists($k, $in) && ! ($onlyEmpty && trim((string) ($old->{$k} ?? '')) !== '')) {
                $v = is_array($in[$k]) ? implode("\n", array_filter(array_map(static fn ($x) => is_scalar($x) ? trim((string) $x) : '', $in[$k]))) : (string) $in[$k];
                $t = mb_substr(trim(str_replace("\r", '', strip_tags($v))), 0, $len);
                $row[$k] = $t === '' ? null : $t;
            }
        }
        foreach (['homepage_url' => 500, 'wiki_url' => 300] as $k => $len) {
            if (array_key_exists($k, $in) && ! ($onlyEmpty && trim((string) ($old->{$k} ?? '')) !== '')) {
                $u = trim((string) $in[$k]);
                $row[$k] = preg_match('#^https?://[^\s<>"]+$#i', $u) ? mb_substr($u, 0, $len) : null;
            }
        }
        if (array_key_exists('facts', $in) && is_array($in['facts']) && ! $onlyEmpty) {
            $facts = [];
            foreach ($in['facts'] as $k => $v) {
                $k = mb_substr(trim(strip_tags((string) $k)), 0, 40);
                $v = mb_substr(trim(strip_tags(is_scalar($v) ? (string) $v : '')), 0, 200);
                if ($k !== '' && $v !== '' && count($facts) < 40) {
                    $facts[$k] = $v;
                }
            }
            $row['facts'] = $facts ? json_encode($facts, JSON_UNESCAPED_UNICODE) : null;
        }
        if ($old) {
            if ($onlyEmpty) {
                unset($row['brand'], $row[$tcol], $row['kind']);
            } else {
                $row['status'] = 'active';
            }
            DB::table($table)->where('id', $old->id)->update($row);

            return ['key' => (string) $old->key, 'created' => false];
        }
        $key = $this->newKey($table, $brand, $title, $kind, $type === 'materials' ? 80 : 60);
        DB::table($table)->insert($row + ['key' => $key, 'status' => 'active', 'source' => $source !== '' ? mb_substr($source, 0, 20) : null, 'created_at' => now()]);

        return ['key' => $key, 'created' => true];
    }

    private function newKey(string $table, string $brand, string $title, string $kind, int $max): string
    {
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower((string) (function_exists('iconv') ? @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $brand.' '.$title) : $brand.' '.$title))), '-');
        if (strlen($slug) < 2) {
            $slug = 'item-'.substr(md5($brand.'|'.$title), 0, 8);
        }
        $base = substr($slug.'-'.$kind, 0, $max - 4);
        $key = $base;
        for ($i = 2; DB::table($table)->where('key', $key)->exists(); $i++) {
            $key = $base.'-'.$i;
        }

        return $key;
    }

    /** 지우기 = 보관 (되살릴 수 있음) · $hard 면 아주 지움 */
    public function remove(string $type, string $key, bool $hard = false): void
    {
        Schema::ensure();
        $table = Schema::table($type);
        if ($hard) {
            DB::table($table)->where('key', $key)->where('status', 'archived')->delete();

            return;
        }
        DB::table($table)->where('key', $key)->update(['status' => 'archived', 'updated_at' => now()]);
    }

    public function restore(string $type, string $key): void
    {
        Schema::ensure();
        DB::table(Schema::table($type))->where('key', $key)->update(['status' => 'active', 'updated_at' => now()]);
    }
}
