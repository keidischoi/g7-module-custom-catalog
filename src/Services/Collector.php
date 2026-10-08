<?php

namespace Modules\Custom\Catalog\Services;

use Illuminate\Support\Facades\DB;
use Modules\Custom\Catalog\Support\Fields;
use Modules\Custom\Catalog\Support\Schema;
use Modules\Custom\Catalog\Support\Settings;

/**
 * 0.2.0 🌙 자동 수집 — 서버가 조용할 때 AI 가 카탈로그를 채움.
 *
 *  회원 등록 가져오기(members): 업체검색에서 회원이 적어 넣은 장비 모델 · 재료 — 업체검색 규칙(관리자 승인, 또는 서로 다른 업체 N곳 이상 · 숨김/합침은 빼고)을 넘은 것만. AI 를 쓰지 않음
 *  새 모델 · 재료 찾기(new): 제조사를 돌아가며 「목록에 없는 제품」을 물음
 *  빈 제원 채우기(fill)   : 제원이 덜 찬 항목의 빈 칸만 물음 (이미 적힌 값은 건드리지 않음)
 *  사진 찾기(photo)       : 사진 없는 항목 — ① 검색(Brave 키가 있으면) ② 제품 공식 페이지의 대표 사진 ③ 위키미디어 공용
 *
 * 결과는 「제안」으로 쌓임. 설정이 review 면 관리자가 확인해서 반영, auto 면 바로 반영(제안함에는 「반영함」으로 남음).
 * 조용할 때 = 설정한 시간대 + 서버 부하가 기준 아래. 한 번에 몇 개 · 하루 몇 개까지인지도 설정.
 * 도는 길: ① 스케줄(catalog:collect — 10분마다) ② 스케줄이 없는 서버는 카탈로그 화면을 누가 열 때 응답 뒤에.
 */
final class Collector
{
    public static ?float $loadOverride = null;

    public static ?int $hourOverride = null;

    /** 테스트용: fn(string $url, array $headers): ?string  (웹 문서 · JSON 글) */
    public static $pager = null;

    public function __construct(private CatalogService $catalog, private PhotoService $photos, private AiClient $ai) {}

    /** 서버 부하 % (모르면 null) */
    public static function loadPercent(): ?int
    {
        $load = self::$loadOverride ?? (function_exists('sys_getloadavg') ? ((@sys_getloadavg())[0] ?? null) : null);
        if ($load === null) {
            return null;
        }
        static $cores = null;
        if ($cores === null) {
            $cores = 1;
            if (is_readable('/proc/cpuinfo')) {
                $cores = max(1, preg_match_all('/^processor\s*:/m', (string) @file_get_contents('/proc/cpuinfo')));
            }
        }

        return (int) round($load / $cores * 100);
    }

    /** 지금 돌려도 되는지 @return array{ok: bool, reason: string} */
    public function quiet(): array
    {
        $s = Settings::all();
        if (! $s['auto']) {
            return ['ok' => false, 'reason' => '자동 수집이 꺼져 있어요.'];
        }
        if (! $s['task_members'] && ! $s['task_new'] && ! $s['task_fill'] && ! $s['task_photo']) {
            return ['ok' => false, 'reason' => '할 일이 모두 꺼져 있어요.'];
        }
        $h = self::$hourOverride ?? (int) date('G');
        $from = $s['auto_from'];
        $to = $s['auto_to'];
        $in = $from === $to || ($from < $to ? ($h >= $from && $h < $to) : ($h >= $from || $h < $to));
        if (! $in) {
            return ['ok' => false, 'reason' => '정해 둔 시간('.$from.'시 ~ '.$to.'시)이 아니에요.'];
        }
        $pct = self::loadPercent();
        if ($pct !== null && $pct >= $s['auto_load']) {
            return ['ok' => false, 'reason' => '서버가 바빠요 (부하 '.$pct.'% · 기준 '.$s['auto_load'].'%).'];
        }
        $st = Settings::state();
        if ($st['day'] === date('Y-m-d') && $st['count'] >= $s['auto_per_day']) {
            return ['ok' => false, 'reason' => '오늘 할 만큼 했어요 ('.$st['count'].'개).'];
        }

        return ['ok' => true, 'reason' => ''];
    }

    public function due(): bool
    {
        return time() - Settings::state()['last'] >= Settings::get('auto_every') * 60;
    }

    /**
     * 한 번 돌기 — 조용하고 차례가 됐을 때만 ($force 면 조건 무시: 관리자 「지금 돌리기」)
     *
     * @return array{ran: list<string>, skipped: string}
     */
    public function tick(bool $force = false, ?string $only = null): array
    {
        Schema::ensure();
        $s = Settings::all();
        if (! $force) {
            $q = $this->quiet();
            if (! $q['ok']) {
                return ['ran' => [], 'skipped' => $q['reason']];
            }
            if (! $this->due()) {
                return ['ran' => [], 'skipped' => '아직 쉬는 시간이에요.'];
            }
        }
        $lock = @fopen(Settings::file('collect.lock'), 'c');
        if (! $lock || ! flock($lock, LOCK_EX | LOCK_NB)) {
            return ['ran' => [], 'skipped' => '이미 돌고 있어요.'];
        }
        try {
            $st = Settings::state();
            if ($st['day'] !== date('Y-m-d')) {
                $st['day'] = date('Y-m-d');
                $st['count'] = 0;
            }
            $st['last'] = time();
            Settings::saveState($st);
            $tasks = array_values(array_filter(['members', 'new', 'fill', 'photo'], static fn ($t) => $only ? $t === $only : $s['task_'.$t]));
            $ran = [];
            $n = $only ? 1 : $s['auto_per_run'];
            for ($i = 0; $i < $n && $tasks; $i++) {
                $st = Settings::state();
                $task = $tasks[$st['turn'] % count($tasks)];
                $st['turn']++;
                $st['count']++;
                Settings::saveState($st);
                try {
                    $line = match ($task) {
                        'members' => $this->taskMembers(),
                        'new' => $this->taskNew(),
                        'fill' => $this->taskFill(),
                        default => $this->taskPhoto(),
                    };
                } catch (\Throwable $e) {
                    $line = '⚠ '.['members' => '회원 등록 가져오기', 'new' => '새 항목 찾기', 'fill' => '제원 채우기', 'photo' => '사진 찾기'][$task].': '.mb_substr($e->getMessage(), 0, 200);
                }
                Settings::log($line);
                $ran[] = $line;
            }

            return ['ran' => $ran, 'skipped' => ''];
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /* ───────── 회원이 등록한 것 (업체검색) ───────── */

    /**
     * 업체검색에서 회원이 적어 넣은 장비 모델 · 재료를 카탈로그로 — 업체검색이 정한 규칙 그대로:
     *  장비: ModelBook::known() 이 「다른 사람에게도 보여 주는」 모델 = 관리자가 승인했거나 서로 다른 업체 N곳(model_min_companies) 이상이 쓴 것. 숨김 · 합침은 빠짐.
     *  재료: 재고(cmp_spools)에 서로 다른 업체 N곳 이상이 같은 제조사 · 재료로 넣은 것. 가장 많이 쓴 표기로.
     * AI 를 쓰지 않음. 이미 카탈로그에 있거나(보관한 것 포함) 한 번 버린 제안은 다시 올리지 않음.
     */
    public function taskMembers(int $limit = 20): string
    {
        $MB = '\Modules\Custom\Companies\Services\ModelBook';
        $ST = '\Modules\Custom\Companies\Support\Settings';
        $spools = false;
        try {
            $spools = \Illuminate\Support\Facades\Schema::hasTable('cmp_spools');
        } catch (\Throwable) {
        }
        if (! class_exists($MB) && ! $spools) {
            return '회원 등록 가져오기: 업체검색 모듈이 없어요.';
        }
        $min = 2;
        try {
            $min = class_exists($ST) ? max(1, (int) $ST::get('model_min_companies')) : 2;
        } catch (\Throwable) {
        }
        $seen = ['equipment' => [], 'materials' => []];
        foreach (['equipment' => 'model', 'materials' => 'name'] as $type => $tcol) {
            foreach (DB::table(Schema::table($type))->get(['brand', $tcol, 'kind']) as $r) {
                $seen[$type][$r->kind.'|'.CatalogService::norm($r->brand).'|'.CatalogService::norm($r->{$tcol})] = true;
            }
            foreach (DB::table(Schema::SUGGEST)->where('task', 'new')->where('item_type', $type)->whereIn('status', ['pending', 'rejected'])->pluck('payload') as $p) {
                $p = Schema::json($p);
                $seen[$type][($p['kind'] ?? '').'|'.CatalogService::norm($p['brand'] ?? '').'|'.CatalogService::norm($p['title'] ?? '')] = true;
            }
        }
        $added = [];
        if (class_exists($MB)) {
            foreach (array_keys(Fields::EQUIPMENT_KINDS) as $kind) {
                foreach ($MB::known($kind, 0, 300) as $m) {
                    $brand = trim((string) ($m['b'] ?? ''));
                    $model = trim((string) ($m['m'] ?? ''));
                    $k = $kind.'|'.CatalogService::norm($brand).'|'.CatalogService::norm($model);
                    if ($brand === '' || mb_strlen($model) < 2 || isset($seen['equipment'][$k]) || count($added) >= $limit) {
                        continue;
                    }
                    $seen['equipment'][$k] = true;
                    $vals = [];
                    if (is_array($m['s'] ?? null) && count($m['s']) >= 2) {
                        $vals = ['build_x_mm' => (int) $m['s'][0], 'build_y_mm' => (int) $m['s'][1], 'build_z_mm' => (int) ($m['s'][2] ?? 0)];
                    }
                    if (! empty($m['mc'])) {
                        $vals['multicolor'] = true;
                    }
                    $this->suggest('new', 'equipment', null, $brand.' '.$model, ['brand' => $brand, 'title' => $model, 'kind' => $kind, 'values' => array_filter($vals)],
                        '회원 등록 · 업체 '.(int) ($m['n'] ?? 1).'곳');
                    $added[] = $brand.' '.$model;
                }
            }
        }
        if ($spools) {
            $g = [];
            foreach (DB::table('cmp_spools')->whereNull('deleted_at')->whereNotNull('brand')->where('brand', '!=', '')->where('material', '!=', '')->get() as $r) {
                if (! Fields::validKind('materials', (string) $r->kind)) {
                    continue;
                }
                $k = $r->kind.'|'.CatalogService::norm($r->brand).'|'.CatalogService::norm($r->material);
                $x = &$g[$k];
                $x ??= ['kind' => (string) $r->kind, 'spell' => [], 'companies' => [], 'vals' => []];
                $sp = trim((string) $r->brand).'|'.trim((string) $r->material);
                $x['spell'][$sp] = ($x['spell'][$sp] ?? 0) + 1;
                $x['companies'][(int) $r->company_id] = true;
                foreach (['diameter', 'nozzle_min', 'nozzle_max', 'bed_min', 'bed_max', 'dry_temp', 'dry_hours', 'weight_g'] as $c) {
                    if (! isset($x['vals'][$c]) && isset($r->{$c}) && (float) $r->{$c} > 0) {
                        $x['vals'][$c] = $r->{$c};
                    }
                }
                unset($x);
            }
            foreach ($g as $k => $x) {
                if (count($x['companies']) < $min || isset($seen['materials'][$k]) || count($added) >= $limit) {
                    continue;
                }
                arsort($x['spell']);
                [$brand, $mat] = explode('|', (string) array_key_first($x['spell']), 2);
                $seen['materials'][$k] = true;
                $this->suggest('new', 'materials', null, $brand.' '.$mat, ['brand' => $brand, 'title' => $mat, 'kind' => $x['kind'], 'values' => ['material' => $mat] + $x['vals']],
                    '회원 등록 · 업체 '.count($x['companies']).'곳');
                $added[] = $brand.' '.$mat;
            }
        }

        return '회원 등록 가져오기: '.($added ? count($added).'개 ('.implode(', ', array_slice($added, 0, 4)).(count($added) > 4 ? ' …' : '').')'
            : '새로 넘어온 것이 없어요 (관리자가 승인했거나 업체 '.$min.'곳 이상이 쓴 것만 가져와요)');
    }

    /* ───────── 새 모델 · 재료 ───────── */

    public function taskNew(): string
    {
        // 제조사 × 탭 을 돌아가며
        $pairs = [];
        foreach (Fields::TABS as $t) {
            foreach (DB::table(Schema::table($t[3]))->where('status', 'active')->whereIn('kind', $t[4])->select('brand', DB::raw('count(*) as n'))->groupBy('brand')->orderByDesc('n')->limit(40)->pluck('n', 'brand') as $brand => $n) {
                if (trim((string) $brand) !== '' && ! in_array($brand, ['종류', '기타'], true)) {
                    $pairs[] = [$t, (string) $brand];
                }
            }
        }
        if (! $pairs) {
            return '새 항목 찾기: 물어볼 제조사가 아직 없어요 (항목을 몇 개 먼저 넣어 주세요).';
        }
        $st = Settings::state();
        [$tab, $brand] = $pairs[$st['brand'] % count($pairs)];
        $st['brand']++;
        Settings::saveState($st);
        $type = $tab[3];
        $tcol = CatalogService::titleCol($type);
        $have = DB::table(Schema::table($type))->where('brand', $brand)->whereIn('kind', $tab[4])->pluck($tcol)->all();
        $kinds = implode(', ', array_map(static fn ($k) => $k.'('.Fields::kindLabel($type, $k).')', $tab[4]));
        $keys = [];
        foreach (Fields::forKind($type, $tab[4][0]) as $d) {
            $keys[] = $d['key'].': '.$d['label'].($d['unit'] ? ' ('.$d['unit'].')' : '').($d['options'] && $d['type'] === 'sel' ? ' ['.implode('|', $d['options']).']' : '').($d['type'] === 'bool' ? ' [true|false]' : '');
        }
        $what = $type === 'materials' ? '재료 제품(필라멘트 · 레진 등)' : '장비';
        $j = $this->ai->json("제조사 「{$brand}」의 {$tab[1]} {$what} 중에서, 아래 「이미 있는 목록」에 없는 실제 제품을 최대 6개 알려 줘.\n"
            ."- 확실히 아는 실제 제품만. 없거나 잘 모르면 items 를 빈 배열로.\n- 같은 제품을 다른 표기로 다시 적지 않는다.\n"
            ."- kind 는 다음 중 하나: {$kinds}\n- values 는 아래 칸 중 확실한 것만 (모르면 넣지 않음).\n"
            .'- summary 는 한국어 한두 문장.'."\n\n[이미 있는 목록]\n".($have ? implode(', ', array_slice($have, 0, 150)) : '(없음)')
            ."\n\n[칸]\n".implode("\n", $keys)
            ."\n\n[답 모양]\n".'{"items":[{"'.($type === 'materials' ? 'name' : 'model').'":"","kind":"'.$tab[4][0].'","homepage_url":"","summary":"","values":{}}]}');
        $items = is_array($j['items'] ?? null) ? $j['items'] : (array_is_list($j) ? $j : []);
        $known = [];
        foreach ($have as $h) {
            $known[CatalogService::norm($h)] = true;
        }
        foreach (DB::table(Schema::SUGGEST)->where('task', 'new')->where('item_type', $type)->pluck('title') as $t) {
            $known[CatalogService::norm(preg_replace('/^'.preg_quote($brand, '/').'\s+/iu', '', (string) $t))] = true;
        }
        $added = [];
        foreach (array_slice($items, 0, 8) as $it) {
            if (! is_array($it)) {
                continue;
            }
            $title = mb_substr(trim(strip_tags((string) ($it['model'] ?? $it['name'] ?? ''))), 0, 80);
            $title = trim(preg_replace('/^'.preg_quote($brand, '/').'\s+/iu', '', $title) ?? $title);
            $kind = in_array($it['kind'] ?? '', $tab[4], true) ? (string) $it['kind'] : $tab[4][0];
            if (mb_strlen($title) < 2 || isset($known[CatalogService::norm($title)])) {
                continue;
            }
            $known[CatalogService::norm($title)] = true;
            $vals = [];
            foreach (Fields::forKind($type, $kind) as $d) {
                $v = Fields::clean($d, is_array($it['values'] ?? null) ? ($it['values'][$d['key']] ?? null) : null);
                if ($v !== null) {
                    $vals[$d['key']] = $v;
                }
            }
            $payload = ['brand' => $brand, 'title' => $title, 'kind' => $kind, 'values' => $vals, 'summary' => mb_substr(trim(strip_tags((string) ($it['summary'] ?? ''))), 0, 600),
                'homepage_url' => (string) ($it['homepage_url'] ?? '')];
            $this->suggest('new', $type, null, $brand.' '.$title, $payload);
            $added[] = $title;
        }

        return '새 항목 찾기 · '.$brand.' '.$tab[1].': '.($added ? count($added).'개 제안 ('.implode(', ', array_slice($added, 0, 4)).(count($added) > 4 ? ' …' : '').')' : '새로 나온 것이 없어요').' — '.$this->ai->last;
    }

    /* ───────── 빈 제원 ───────── */

    public function taskFill(): string
    {
        foreach (['equipment', 'materials'] as $type) {
            $table = Schema::table($type);
            $rows = DB::table($table)->where('status', 'active')->whereNotIn('brand', ['종류', '기타'])
                ->where(static fn ($w) => $w->whereNull('ai_fill_at')->orWhere('ai_fill_at', '<', now()->subDays(60)))
                ->orderByRaw('case when ai_fill_at is null then 0 else 1 end')->orderBy('ai_fill_at')->orderByDesc('id')->limit(8)->get();
            foreach ($rows as $r) {
                DB::table($table)->where('id', $r->id)->update(['ai_fill_at' => now()]);
                $have = CatalogService::values($type, $r);
                $empty = array_values(array_filter(Fields::forKind($type, (string) $r->kind), static fn ($d) => ! isset($have[$d['key']]) && $d['type'] !== 'url'));
                if (count($empty) < 4 || DB::table(Schema::SUGGEST)->where('task', 'fill')->where('item_key', $r->key)->where('status', 'pending')->exists()) {
                    continue;
                }
                $title = $r->brand.' '.$r->{CatalogService::titleCol($type)};
                $keys = array_map(static fn ($d) => $d['key'].': '.$d['label'].($d['unit'] ? ' ('.$d['unit'].', 숫자만)' : '').($d['options'] && $d['type'] === 'sel' ? ' ['.implode('|', $d['options']).']' : '')
                    .($d['type'] === 'bool' ? ' [true|false]' : '').($d['type'] === 'tags' ? ' [배열]' : ''), $empty);
                $j = $this->ai->json("제품 「{$title}」 (".Fields::kindLabel($type, (string) $r->kind).")의 공개 제원을 아래 칸에 채워 줘.\n"
                    ."- 제조사가 공개한 값 중 확실히 아는 것만. 모르는 칸은 null (추측 금지).\n- 숫자 칸은 단위 없이 숫자만.\n"
                    .(trim((string) ($r->summary ?? '')) === '' ? "- summary: 이 제품을 소개하는 한국어 두세 문장 (사실만).\n" : '')
                    .(trim((string) ($r->homepage_url ?? '')) === '' ? "- homepage_url: 이 제품의 제조사 공식 페이지 주소 (확실할 때만).\n" : '')
                    .(trim((string) ($r->issues ?? '')) === '' ? "- issues: 이 제품을 쓰는 사람들 사이에 널리 알려진 문제 · 고질병 · 주의할 점 (한국어 짧은 문장 2~5개의 배열 · 확실한 것만 · 모르면 빈 배열).\n" : '')
                    ."\n[칸]\n".implode("\n", $keys)."\n\n[답 모양]\n".'{"values":{"칸 키":값},"summary":"","homepage_url":"","issues":[]}');
                $in = is_array($j['values'] ?? null) ? $j['values'] : $j;
                $vals = [];
                foreach ($empty as $d) {
                    $v = Fields::clean($d, $in[$d['key']] ?? null);
                    if ($v !== null && ! ($d['type'] === 'sel' && $d['options'] && ! in_array((string) $v, $d['options'], true))) {
                        $vals[$d['key']] = $v;
                    }
                }
                $payload = ['values' => $vals];
                if (trim((string) ($r->summary ?? '')) === '' && is_string($j['summary'] ?? null) && mb_strlen(trim($j['summary'])) >= 10) {
                    $payload['summary'] = mb_substr(trim(strip_tags($j['summary'])), 0, 600);
                }
                if (trim((string) ($r->homepage_url ?? '')) === '' && is_string($j['homepage_url'] ?? null) && preg_match('#^https?://#i', trim($j['homepage_url']))) {
                    $payload['homepage_url'] = trim($j['homepage_url']);
                }
                if (trim((string) ($r->issues ?? '')) === '' && is_array($j['issues'] ?? null)) {
                    $iss = array_slice(array_values(array_filter(array_map(static fn ($x) => is_string($x) ? mb_substr(trim(strip_tags($x)), 0, 200) : '', $j['issues']), static fn ($x) => mb_strlen($x) >= 6)), 0, 6);
                    if ($iss) {
                        $payload['issues'] = implode("\n", $iss);
                    }
                }
                if (! $vals && count($payload) === 1) {
                    return '제원 채우기 · '.$title.': AI 가 아는 값이 없어요 — '.$this->ai->last;
                }
                $this->suggest('fill', $type, (string) $r->key, $title, $payload);

                return '제원 채우기 · '.$title.': '.count($vals).'칸 제안 — '.$this->ai->last;
            }
        }

        return '제원 채우기: 채울 항목이 없어요.';
    }

    /* ───────── 사진 ───────── */

    public function taskPhoto(): string
    {
        foreach (['equipment', 'materials'] as $type) {
            $table = Schema::table($type);
            $r = DB::table($table)->where('status', 'active')->whereNotIn('brand', ['종류', '기타'])
                ->where(static fn ($w) => $w->whereNull('image_url')->orWhere('image_url', ''))
                ->where(static fn ($w) => $w->whereNull('ai_photo_at')->orWhere('ai_photo_at', '<', now()->subDays(30)))
                ->orderByRaw('case when ai_photo_at is null then 0 else 1 end')->orderBy('ai_photo_at')->orderByDesc('id')->first();
            if (! $r) {
                continue;
            }
            DB::table($table)->where('id', $r->id)->update(['ai_photo_at' => now()]);
            $model = (string) $r->{CatalogService::titleCol($type)};
            $title = $r->brand.' '.$model;
            if (DB::table(Schema::SUGGEST)->where('task', 'photo')->where('item_key', $r->key)->where('status', 'pending')->exists()) {
                return '사진 찾기 · '.$title.': 확인을 기다리는 제안이 이미 있어요.';
            }
            foreach ($this->photoCandidates((string) $r->brand, $model, (string) ($r->homepage_url ?? ''), $type) as $c) {
                $got = PhotoService::fetch($c['image_url']);
                $size = $got ? PhotoService::size($got['body']) : null;
                if (! $size || min($size) < 300) {
                    continue;
                }
                $this->suggest('photo', $type, (string) $r->key, $title, $c);

                return '사진 찾기 · '.$title.': '.$c['from'].'에서 한 장 찾음';
            }

            return '사진 찾기 · '.$title.': 맞는 사진을 찾지 못했어요.';
        }

        return '사진 찾기: 사진 없는 항목이 없어요.';
    }

    /** 이름이 맞는 글인지 — 모델 이름의 낱말(2자 이상)이 모두 들어 있어야 */
    public static function matches(string $text, string $model): bool
    {
        $t = CatalogService::norm($text);
        $words = array_values(array_filter(preg_split('/[\s\-_\/·.]+/u', mb_strtolower($model)) ?: [], static fn ($w) => mb_strlen($w) >= 2 || preg_match('/\d/', $w)));
        if (! $words) {
            return false;
        }
        foreach ($words as $w) {
            if (! str_contains($t, CatalogService::norm($w))) {
                return false;
            }
        }

        return true;
    }

    /** @return list<array{image_url: string, page_url: string, credit: string, from: string}> */
    public function photoCandidates(string $brand, string $model, string $homepage, string $type): array
    {
        $out = [];
        $q = trim($brand.' '.$model.($type === 'materials' ? ' filament' : ''));
        $s = Settings::all();
        if ($s['search'] === 'brave' && $s['brave_key'] !== '') {
            $j = json_decode((string) self::page('https://api.search.brave.com/res/v1/images/search?count=8&safesearch=strict&q='.rawurlencode($q),
                ['X-Subscription-Token' => $s['brave_key'], 'Accept' => 'application/json']), true);
            foreach (is_array($j['results'] ?? null) ? $j['results'] : [] as $it) {
                $img = (string) ($it['properties']['url'] ?? '');
                if ($img !== '' && self::matches((string) ($it['title'] ?? ''), $model)) {
                    $out[] = ['image_url' => $img, 'page_url' => (string) ($it['url'] ?? ''), 'credit' => (string) ($it['source'] ?? parse_url((string) ($it['url'] ?? ''), PHP_URL_HOST) ?? ''), 'from' => '검색'];
                }
                if (count($out) >= 3) {
                    break;
                }
            }
        }
        // 제품 공식 페이지의 대표 사진 (og:image) — 첫 화면 주소(경로 없음)는 제품 사진이 아니라 건너뜀
        $path = trim((string) parse_url($homepage, PHP_URL_PATH), '/');
        if ($homepage !== '' && $path !== '' && PhotoService::safeUrl($homepage)) {
            $html = (string) self::page($homepage, ['Accept' => 'text/html']);
            $pageTitle = preg_match('#<title[^>]*>(.*?)</title>#si', $html, $m) ? html_entity_decode(strip_tags($m[1])) : '';
            if ($html !== '' && self::matches($pageTitle.' '.$homepage, $model)
                && (preg_match('#<meta[^>]+property=["\']og:image(?::secure_url)?["\'][^>]+content=["\']([^"\']+)#i', $html, $m)
                    || preg_match('#<meta[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']og:image#i', $html, $m))) {
                $img = html_entity_decode($m[1]);
                if (str_starts_with($img, '//')) {
                    $img = 'https:'.$img;
                } elseif (str_starts_with($img, '/')) {
                    $img = parse_url($homepage, PHP_URL_SCHEME).'://'.parse_url($homepage, PHP_URL_HOST).$img;
                }
                $out[] = ['image_url' => $img, 'page_url' => $homepage, 'credit' => (string) parse_url($homepage, PHP_URL_HOST), 'from' => '제조사 페이지'];
            }
        }
        // 위키미디어 공용 (자유 이용 사진 — 저작자 · 라이선스를 같이 적어 둠)
        $j = json_decode((string) self::page('https://commons.wikimedia.org/w/api.php?action=query&format=json&generator=search&gsrnamespace=6&gsrlimit=6&prop=imageinfo&iiprop=url|extmetadata|mime&iiurlwidth=1280&gsrsearch='.rawurlencode($brand.' '.$model), []), true);
        foreach (is_array($j['query']['pages'] ?? null) ? $j['query']['pages'] : [] as $pg) {
            $ii = $pg['imageinfo'][0] ?? null;
            if (! is_array($ii) || ! preg_match('#^image/(jpeg|png|webp)$#', (string) ($ii['mime'] ?? '')) || ! self::matches((string) ($pg['title'] ?? ''), $model)
                || ! self::matches((string) ($pg['title'] ?? ''), (string) (preg_split('/[\s(]+/u', trim($brand))[0] ?? ''))) {
                continue;
            }
            $meta = $ii['extmetadata'] ?? [];
            $credit = trim('Wikimedia Commons · '.trim(strip_tags((string) ($meta['Artist']['value'] ?? ''))).' · '.trim((string) ($meta['LicenseShortName']['value'] ?? '')), ' ·');
            $out[] = ['image_url' => (string) ($ii['thumburl'] ?? $ii['url'] ?? ''), 'page_url' => (string) ($ii['descriptionurl'] ?? ''), 'credit' => mb_substr($credit, 0, 200), 'from' => '위키미디어 공용'];
        }

        return array_values(array_filter($out, static fn ($c) => $c['image_url'] !== '' && PhotoService::safeUrl($c['image_url'])));
    }

    /** 웹 문서 받기 (글) */
    public static function page(string $url, array $headers = []): ?string
    {
        if (is_callable(self::$pager)) {
            return (self::$pager)($url, $headers);
        }
        if (! PhotoService::safeUrl($url)) {
            return null;
        }
        try {
            $res = \Illuminate\Support\Facades\Http::withHeaders($headers + ['User-Agent' => 'Mozilla/5.0 (compatible; G7Catalog/0.2)'])->connectTimeout(6)->timeout(15)->get($url);

            return $res->successful() ? mb_substr((string) $res->body(), 0, 600000) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /* ───────── 제안함 ───────── */

    /** @param array<string, mixed> $payload */
    public function suggest(string $task, string $type, ?string $key, string $title, array $payload, string $source = ''): int
    {
        $id = (int) DB::table(Schema::SUGGEST)->insertGetId(['task' => $task, 'item_type' => $type, 'item_key' => $key, 'title' => mb_substr($title, 0, 160),
            'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE), 'source' => mb_substr($source !== '' ? $source : ($task === 'photo' ? (string) ($payload['from'] ?? '') : $this->ai->last), 0, 300),
            'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
        if (Settings::get('apply') === 'auto') {
            try {
                $this->apply($id);
            } catch (\Throwable $e) {
                Settings::log('⚠ 바로 반영 실패 · '.$title.': '.mb_substr($e->getMessage(), 0, 160));
            }
        }

        return $id;
    }

    /** 제안 반영 @return array{key: string} */
    public function apply(int $id, ?array $edited = null): array
    {
        $sg = DB::table(Schema::SUGGEST)->where('id', $id)->first();
        if (! $sg || $sg->status !== 'pending') {
            throw new \InvalidArgumentException('이미 처리한 제안이에요.');
        }
        $p = is_array($edited) ? $edited : Schema::json($sg->payload);
        $type = (string) $sg->item_type;
        if ($sg->task === 'new') {
            $key = $this->catalog->save($type, $p, true, str_starts_with((string) ($sg->source ?? ''), '회원') ? 'members' : 'ai')['key'];
        } elseif ($sg->task === 'fill') {
            $key = $this->catalog->save($type, ['key' => (string) $sg->item_key] + $p, true)['key'];
        } else {
            $key = (string) $sg->item_key;
            $this->photos->addFromUrl($type, $key, (string) ($p['image_url'] ?? ''), (string) ($p['credit'] ?? ''), (string) ($p['page_url'] ?? ''));
        }
        DB::table(Schema::SUGGEST)->where('id', $id)->update(['status' => 'applied', 'item_key' => $key, 'updated_at' => now()]);

        return ['key' => $key];
    }

    public function reject(int $id): void
    {
        DB::table(Schema::SUGGEST)->where('id', $id)->where('status', 'pending')->update(['status' => 'rejected', 'updated_at' => now()]);
    }

    /** @return array{items: list<array<string, mixed>>, pending: int} */
    public function suggestions(string $status = 'pending', int $limit = 60): array
    {
        Schema::ensure();
        $rows = DB::table(Schema::SUGGEST)->where('status', in_array($status, ['applied', 'rejected'], true) ? $status : 'pending')->orderByDesc('id')->limit($limit)->get();
        $items = [];
        foreach ($rows as $r) {
            $p = Schema::json($r->payload);
            $type = (string) $r->item_type;
            $lines = [];
            if ($r->task !== 'photo') {
                $kind = (string) ($p['kind'] ?? '');
                if ($kind === '' && $r->item_key) {
                    $kind = (string) (DB::table(Schema::table($type))->where('key', $r->item_key)->value('kind') ?? '');
                }
                foreach (Fields::forKind($type, $kind) as $d) {
                    if (isset($p['values'][$d['key']])) {
                        $lines[] = ['label' => $d['label'], 'value' => Fields::show($d, $p['values'][$d['key']])];
                    }
                }
                if (! empty($p['homepage_url'])) {
                    $lines[] = ['label' => '공식 페이지', 'value' => (string) $p['homepage_url']];
                }
                foreach (preg_split('/\R/u', (string) ($p['issues'] ?? '')) ?: [] as $is) {
                    if (trim($is) !== '') {
                        $lines[] = ['label' => '알려진 문제', 'value' => trim($is)];
                    }
                }
            }
            $items[] = ['id' => (int) $r->id, 'task' => (string) $r->task, 'type' => $type, 'key' => (string) ($r->item_key ?? ''), 'title' => (string) $r->title,
                'kind_label' => isset($p['kind']) ? Fields::kindLabel($type, (string) $p['kind']) : '', 'summary' => (string) ($p['summary'] ?? ''), 'lines' => $lines,
                'image_url' => (string) ($p['image_url'] ?? ''), 'page_url' => (string) ($p['page_url'] ?? ''), 'credit' => (string) ($p['credit'] ?? ''),
                'source' => (string) ($r->source ?? ''), 'status' => (string) $r->status, 'at' => substr((string) $r->created_at, 0, 16)];
        }

        return ['items' => $items, 'pending' => (int) DB::table(Schema::SUGGEST)->where('status', 'pending')->count()];
    }
}
