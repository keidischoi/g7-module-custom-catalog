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
 *  안전 자료 찾기(sds)    : MSDS 가 빈 재료 — ① 제품 공식 페이지의 SDS 링크 ② 검색(Brave 키가 있으면) ③ AI. 어느 것이든 주소를 열어 SDS 가 맞는지 확인한 것만
 *
 * 결과는 「제안」으로 쌓임. 설정이 review 면 관리자가 확인해서 반영, auto 면 바로 반영(제안함에는 「반영함」으로 남음).
 * 조용할 때 = 설정한 시간대 + 서버 부하가 기준 아래. 한 번에 몇 개 · 하루 몇 개까지인지도 설정.
 * 도는 길: ① 스케줄(catalog:collect — 10분마다) ② 스케줄이 없는 서버는 카탈로그 화면을 누가 열 때 응답 뒤에.
 */
final class Collector
{
    public static ?float $loadOverride = null;

    public static ?int $hourOverride = null;

    /** 할 일 (차례대로 돌아감) */
    public const TASKS = ['members', 'new', 'fill', 'photo', 'sds'];

    /** 안전 자료(SDS · MSDS)를 알아보는 말 — 주소 · 링크 글 · 문서 제목에 */
    public const SDS_RE = '/(?<![a-z])m?sds(?![a-z])|safety[\s_\-]*data[\s_\-]*sheet|sicherheitsdatenblatt|fiche[\s_\-]*de[\s_\-]*donn|물질\s*안전|안전\s*보건\s*자료/i';

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
        if (! $s['task_members'] && ! $s['task_new'] && ! $s['task_fill'] && ! $s['task_photo'] && ! $s['task_sds']) {
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
            $tasks = array_values(array_filter(self::TASKS, static fn ($t) => $only ? $t === $only : $s['task_'.$t]));
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
                        'sds' => $this->taskSds(),
                        default => $this->taskPhoto(),
                    };
                } catch (\Throwable $e) {
                    $line = '⚠ '.['members' => '회원 등록 가져오기', 'new' => '새 항목 찾기', 'fill' => '제원 채우기', 'photo' => '사진 찾기', 'sds' => '안전 자료 찾기'][$task].': '.mb_substr($e->getMessage(), 0, 200);
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
            ."- sources 에는 이 제품 · 값을 확인할 수 있는 실제 페이지 주소(제조사 공식 페이지 우선)를 최대 3개. 모르면 빈 배열 — 주소를 지어내지 않는다.\n"
            .'- summary 는 한국어 한두 문장.'."\n\n[이미 있는 목록]\n".($have ? implode(', ', array_slice($have, 0, 150)) : '(없음)')
            ."\n\n[칸]\n".implode("\n", $keys)
            ."\n\n[답 모양]\n".'{"items":[{"'.($type === 'materials' ? 'name' : 'model').'":"","kind":"'.$tab[4][0].'","homepage_url":"","summary":"","values":{},"sources":[]}]}');
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
            $payload['sources'] = $this->sources($it['sources'] ?? [], $payload['homepage_url']);   // 0.2.11 출처 (열어 본 결과와 함께)
            if (! Settings::get('new_values')) {
                // 0.2.11 AI 가 기억으로 적은 제원 · 소개 · 주소는 틀린 것이 많아 이름 · 종류만 (관리자가 「고쳐서 반영」 · 「AI 로 정리해 넣기」로 채움) — 출처는 남김
                $payload = ['brand' => $brand, 'title' => $title, 'kind' => $kind, 'values' => [], 'sources' => $payload['sources']];
            }
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
                    ."- sources: 이 값들을 확인할 수 있는 실제 페이지 주소(제조사 공식 제원 페이지 우선) 최대 3개. 모르면 빈 배열 — 주소를 지어내지 않는다.\n"
                    ."\n[칸]\n".implode("\n", $keys)."\n\n[답 모양]\n".'{"values":{"칸 키":값},"summary":"","homepage_url":"","issues":[],"sources":[]}');
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
                $payload['sources'] = $this->sources($j['sources'] ?? [], (string) ($payload['homepage_url'] ?? ''));   // 0.2.11 출처
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

    /* ───────── 안전 자료 (MSDS) ───────── */

    public function taskSds(): string
    {
        $table = Schema::table('materials');
        $r = DB::table($table)->where('status', 'active')->whereNotIn('brand', ['종류', '기타'])
            ->where(static fn ($w) => $w->whereNull('sds_url')->orWhere('sds_url', ''))
            ->where(static fn ($w) => $w->whereNull('ai_sds_at')->orWhere('ai_sds_at', '<', now()->subDays(30)))
            ->orderByRaw('case when ai_sds_at is null then 0 else 1 end')->orderBy('ai_sds_at')->orderByDesc('id')->first();
        if (! $r) {
            return '안전 자료 찾기: MSDS 가 빈 재료가 없어요.';
        }
        DB::table($table)->where('id', $r->id)->update(['ai_sds_at' => now()]);
        $name = (string) $r->{CatalogService::titleCol('materials')};
        $title = $r->brand.' '.$name;
        if (DB::table(Schema::SUGGEST)->where('task', 'sds')->where('item_key', $r->key)->where('status', 'pending')->exists()) {
            return '안전 자료 찾기 · '.$title.': 확인을 기다리는 제안이 이미 있어요.';
        }
        $found = null;
        foreach ($this->sdsCandidates((string) $r->brand, $name, (string) ($r->homepage_url ?? '')) as $c) {
            if (self::isSds($c['url'], $c['hint'])) {
                $found = $c;
                break;
            }
        }
        // 못 찾았으면 AI 에게 — 알려 준 주소도 열어서 확인한 것만
        $ai = '';
        if (! $found) {
            try {
                $j = $this->ai->json("제품 「{$title}」 (".Fields::kindLabel('materials', (string) $r->kind).")의 제조사 공식 안전보건자료(SDS · MSDS) 주소를 알려 줘.\n"
                    ."- 실제로 있는 주소만. 확실하지 않으면 빈 문자열 (추측 · 지어내기 금지).\n- 이 제품만의 SDS 가 없으면 제조사의 SDS 모음 페이지도 괜찮아.\n\n[답 모양]\n".'{"sds_url":""}');
                $u = trim((string) (is_string($j['sds_url'] ?? null) ? $j['sds_url'] : ''));
                $ai = ' — '.$this->ai->last;
                if (preg_match('#^https?://#i', $u) && self::isSds($u)) {
                    $found = ['url' => $u, 'from' => 'AI'];
                }
            } catch (\Throwable $e) {
                $ai = ' — AI: '.mb_substr($e->getMessage(), 0, 80);
            }
        }
        if (! $found) {
            return '안전 자료 찾기 · '.$title.': 찾지 못했어요'.$ai;
        }
        $this->suggest('sds', 'materials', (string) $r->key, $title, ['values' => ['sds_url' => $found['url']], 'page_url' => $found['url']], '안전 자료 · '.$found['from']);

        return '안전 자료 찾기 · '.$title.': '.$found['from'].'에서 찾음';
    }

    /**
     * SDS 일 듯한 주소 — ① 제품 공식 페이지에 걸린 SDS 링크 ② 검색 (Brave 키가 있으면)
     *
     * @return list<array{url: string, from: string, hint: bool}>  hint = 링크 글 · 검색 제목에 SDS 라고 적혀 있었음
     */
    public function sdsCandidates(string $brand, string $name, string $homepage): array
    {
        $out = [];
        $add = static function (string $url, string $from) use (&$out): void {
            if (preg_match('#^https?://#i', $url) && ! in_array($url, array_column($out, 'url'), true) && count($out) < 6) {
                $out[] = ['url' => $url, 'from' => $from, 'hint' => true];
            }
        };
        if ($homepage !== '' && trim((string) parse_url($homepage, PHP_URL_PATH), '/') !== '') {
            $html = (string) self::page($homepage, ['Accept' => 'text/html']);
            preg_match_all('#<a\b[^>]*\bhref=["\']([^"\'\#]+)["\'][^>]*>(.*?)</a>#si', $html, $m, PREG_SET_ORDER);
            foreach ($m as $a) {
                $href = html_entity_decode(trim($a[1]));
                if (preg_match(self::SDS_RE, trim(html_entity_decode(strip_tags($a[2])))) || preg_match(self::SDS_RE, rawurldecode($href))) {
                    $add(self::absUrl($href, $homepage), '제품 페이지');
                }
            }
        }
        $s = Settings::all();
        if ($s['search'] === 'brave' && $s['brave_key'] !== '') {
            $j = json_decode((string) self::page('https://api.search.brave.com/res/v1/web/search?count=10&safesearch=strict&q='.rawurlencode($brand.' '.$name.' safety data sheet SDS'),
                ['X-Subscription-Token' => $s['brave_key'], 'Accept' => 'application/json']), true);
            foreach (is_array($j['web']['results'] ?? null) ? $j['web']['results'] : [] as $it) {
                $u = (string) ($it['url'] ?? '');
                $t = (string) ($it['title'] ?? '').' '.rawurldecode($u);
                if ($u !== '' && preg_match(self::SDS_RE, $t) && self::matches($t, (string) (preg_split('/[\s(]+/u', trim($brand))[0] ?? ''))) {
                    $add($u, '검색');
                }
            }
        }

        return $out;
    }

    /**
     * 열어 봐서 안전 자료가 맞는지 — 첫 화면 주소(경로 없음)는 아님.
     * PDF 면 링크 글 · 주소 · 내용 중 하나에 SDS 라는 말, 웹 문서면 제목(title · h1)에.
     */
    public static function isSds(string $url, bool $hint = false): bool
    {
        if (trim((string) parse_url($url, PHP_URL_PATH), '/') === '') {
            return false;
        }
        $body = (string) self::page($url, ['Accept' => 'application/pdf,text/html;q=0.9,*/*;q=0.5']);
        if ($body === '') {
            return false;
        }
        if (str_starts_with(ltrim($body), '%PDF')) {
            return $hint || preg_match(self::SDS_RE, rawurldecode($url)) === 1 || preg_match('/Safety\s*Data\s*Sheet|\(\s*M?SDS\s*\)/i', $body) === 1;
        }
        $head = (preg_match('#<title[^>]*>(.*?)</title>#si', $body, $m) ? $m[1] : '').' '.(preg_match('#<h1[^>]*>(.*?)</h1>#si', $body, $m) ? $m[1] : '');

        return preg_match(self::SDS_RE, html_entity_decode(strip_tags($head))) === 1;
    }

    private static function absUrl(string $href, string $base): string
    {
        if (preg_match('#^https?://#i', $href)) {
            return $href;
        }
        $scheme = (string) (parse_url($base, PHP_URL_SCHEME) ?: 'https');
        $host = (string) parse_url($base, PHP_URL_HOST);
        if (str_starts_with($href, '//')) {
            return $scheme.':'.$href;
        }
        if (str_starts_with($href, '/')) {
            return $scheme.'://'.$host.$href;
        }
        if (preg_match('#^[a-z][a-z0-9+.\-]*:#i', $href)) {
            return '';   // mailto: · javascript: …
        }

        return $scheme.'://'.$host.preg_replace('#/[^/]*$#', '/', (string) (parse_url($base, PHP_URL_PATH) ?: '/')).$href;
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

    /**
     * 0.2.11 AI 가 댄 출처 — http(s) 주소만 3개까지, 하나씩 열어 봄 (ok: 열림 · 지어낸 주소는 대개 안 열림)
     *
     * @return list<array{url: string, ok: bool}>
     */
    public function sources(mixed $list, string $extra = ''): array
    {
        $urls = [];
        foreach (array_merge(is_array($list) ? array_values($list) : [], $extra !== '' ? [$extra] : []) as $u) {
            $u = is_string($u) ? trim($u) : (is_array($u) && is_string($u['url'] ?? null) ? trim($u['url']) : '');
            if (preg_match('#^https?://[^\s<>"]+$#i', $u) && ! in_array($u, $urls, true) && count($urls) < 3) {
                $urls[] = mb_substr($u, 0, 500);
            }
        }

        return array_map(fn ($u) => ['url' => $u, 'ok' => self::page($u) !== null], $urls);
    }

    /* ───────── 0.2.11 AI 로 정리해 넣기 ───────── */

    /**
     * 붙여 넣은 글(또는 주소 — 열어서 글만)에서 편집 창 칸을 채울 값을 뽑음. 글에 없는 값은 넣지 않음.
     *
     * @return array{kind: string, brand: string, title: string, summary: string, homepage_url: string, issues: string, values: array<string, mixed>, facts: array<string, string>, from: string}
     *
     * @throws \InvalidArgumentException|\RuntimeException
     */
    public function extract(string $type, string $kind, string $text, string $brand = '', string $title = ''): array
    {
        $type = $type === 'materials' ? 'materials' : 'equipment';
        $text = trim($text);
        $from = '';
        if (preg_match('#^https?://\S+$#i', $text)) {
            $from = $text;
            $html = self::page($text);
            if ($html === null) {
                throw new \InvalidArgumentException('주소를 열지 못했어요 — 페이지의 글을 복사해 붙여 넣어 주세요.');
            }
            $text = self::plain($html);
        }
        // 0.2.17 빈 줄 · 겹친 띄어쓰기 줄이고 6000 자까지 (긴 글은 작은 모델을 몇 분씩 붙잡음)
        $text = mb_substr(trim(preg_replace("/[ \t]+/u", ' ', preg_replace("/\n\s*\n+/u", "\n", $text) ?? $text) ?? $text), 0, 6000);
        if (mb_strlen($text) < 8) {
            throw new \InvalidArgumentException('정리할 글이 없어요 — 제품 페이지의 제원 · 소개 글을 붙여 넣거나 끌어 놓아 주세요.');
        }
        $kinds = array_keys($type === 'materials' ? Fields::MATERIAL_KINDS : Fields::EQUIPMENT_KINDS);
        if (! in_array($kind, $kinds, true)) {
            $kind = $kinds[0] ?? 'fdm';
        }
        $kindList = implode(', ', array_map(static fn ($k) => $k.'('.Fields::kindLabel($type, $k).')', $kinds));
        $keys = [];
        foreach (Fields::forKind($type, $kind) as $d) {
            $keys[] = $d['key'].': '.$d['label'].($d['unit'] ? ' ('.$d['unit'].', 숫자만)' : '').($d['options'] && $d['type'] === 'sel' ? ' ['.implode('|', $d['options']).']' : '')
                .($d['type'] === 'bool' ? ' [true|false]' : '').($d['type'] === 'tags' ? ' [배열]' : '');
        }
        $this->ai->opt = ['temperature' => 0, 'max_tokens' => 1500];   // 0.2.17 정리하기: 지어내지 않게 · 답은 짧게
        try {
            $j = $this->ai->json("아래 [글]은 관리자가 붙여 넣은 제품 자료다. 이 글에 **적혀 있는 것만** 골라 칸에 맞게 정리해 줘.\n"
            ."- 글에 없는 값은 넣지 않는다 (기억 · 추측 금지). 단위는 칸 단위로 바꿔 숫자만 (예: 0.25 m/s → 250, 1 kg → 1000).\n"
            ."- 선택 칸은 보기 중 하나로만. 칸에 없는 제원은 facts 에 「이름: 값」으로 (한국어 이름).\n"
            ."- summary 는 글 내용으로 한국어 두세 문장. issues 는 글에 적힌 주의 · 알려진 문제만 (배열).\n"
            ."- kind 는 다음 중 하나 (글로 알 수 없으면 \"{$kind}\"): {$kindList}\n"
            .($brand !== '' || $title !== '' ? "- 지금 편집 중인 제품: {$brand} {$title}\n" : '')
            ."\n[칸]\n".implode("\n", $keys)
            ."\n\n[답 모양]\n".'{"kind":"","brand":"","title":"","summary":"","homepage_url":"","issues":[],"values":{"칸 키":값},"facts":{"이름":"값"}}'
            ."\n\n[글]\n".$text);
        } finally {
            $this->ai->opt = [];
        }
        $k2 = in_array($j['kind'] ?? '', $kinds, true) ? (string) $j['kind'] : $kind;
        $vals = [];
        $in = is_array($j['values'] ?? null) ? $j['values'] : [];
        foreach (Fields::forKind($type, $k2) as $d) {
            $v = Fields::clean($d, $in[$d['key']] ?? null);
            if ($v !== null && ! ($d['type'] === 'sel' && $d['options'] && ! in_array((string) $v, $d['options'], true))) {
                $vals[$d['key']] = $v;
            }
        }
        $facts = [];
        foreach (is_array($j['facts'] ?? null) ? $j['facts'] : [] as $n => $v) {
            if (is_string($n) && is_scalar($v) && trim((string) $v) !== '' && count($facts) < 30) {
                $facts[mb_substr(trim(strip_tags($n)), 0, 40)] = mb_substr(trim(strip_tags((string) $v)), 0, 200);
            }
        }
        $s = static fn ($v, int $n) => is_string($v) ? mb_substr(trim(strip_tags($v)), 0, $n) : '';
        $url = $s($j['homepage_url'] ?? '', 500);
        $iss = is_array($j['issues'] ?? null) ? array_values(array_filter(array_map(static fn ($x) => is_string($x) ? mb_substr(trim(strip_tags($x)), 0, 200) : '', $j['issues']))) : [];

        return ['kind' => $k2, 'brand' => $s($j['brand'] ?? '', 80), 'title' => $s($j['title'] ?? '', 80), 'summary' => $s($j['summary'] ?? '', 600),
            'homepage_url' => preg_match('#^https?://#i', $url) ? $url : ($from !== '' ? $from : ''), 'issues' => implode("\n", array_slice($iss, 0, 8)),
            'values' => $vals, 'facts' => $facts, 'from' => $this->ai->last, 'source_url' => $from];
    }

    /*
     * AI 로 정리해 넣기 결과를 일 번호 파일에도 남김 — 앞단이 504 로 끊어도 화면이 GET 으로 받아 감.
     * 상태는 설정 폴더의 extract-{id}.json: run(stage) · done(data) · fail(message).
     */
    public static function jobStart(array $args = [], string $id = ''): string
    {
        foreach (glob(Settings::file('extract-*.json')) ?: [] as $f) {
            if (@filemtime($f) < time() - 3600) {
                @unlink($f);
            }
        }
        if (! preg_match('/^[a-f0-9]{16}$/', $id) || is_file(Settings::file('extract-'.$id.'.json'))) {
            $id = bin2hex(random_bytes(8));
        }
        self::jobPut($id, ['status' => 'run', 'at' => time(), 'args' => $args]);

        return $id;
    }

    /** 0.2.14 파일 그대로 (args 포함) @return array<string, mixed>|null */
    public static function jobRaw(string $id): ?array
    {
        if (! preg_match('/^[a-f0-9]{16}$/', $id)) {
            return null;
        }
        $f = Settings::file('extract-'.$id.'.json');
        $j = is_file($f) ? json_decode((string) file_get_contents($f), true) : null;

        return is_array($j) ? $j : null;
    }



    /** @param array<string, mixed> $res */
    private static function jobPut(string $id, array $res): void
    {
        @file_put_contents(Settings::file('extract-'.$id.'.json'), json_encode($res, JSON_UNESCAPED_UNICODE), LOCK_EX);
    }

    /** 화면에 줄 상태 (args 빼고) @return array<string, mixed>|null */
    public static function job(string $id): ?array
    {
        $j = self::jobRaw($id);
        if ($j === null) {
            return null;
        }
        unset($j['args']);
        if (($j['status'] ?? '') === 'run' && (int) ($j['at'] ?? 0) < time() - 420) {
            return ['status' => 'fail', 'message' => 'AI 가 7분 안에 답하지 못했어요 (마지막: '.($j['stage'] ?? '?').') — 글을 줄이거나 🤖 AI 연결에서 더 빠른 모델을 위로 올려 주세요.'];
        }

        return $j;
    }

    /** @param array{type?: string, kind?: string, text?: string, brand?: string, title?: string} $a */
    public function jobRun(string $id, array $a = []): void
    {
        $raw = self::jobRaw($id);
        if ($raw === null || ($raw['status'] ?? '') !== 'run' || ! empty($raw['started'])) {
            return;   // 이미 누가 맡음 · 끝남
        }
        $a = $a ?: (is_array($raw['args'] ?? null) ? $raw['args'] : []);
        @set_time_limit(450);
        @ignore_user_abort(true);
        $t0 = time();
        $stage = function (string $s) use ($id, $raw, $t0) {
            self::jobPut($id, ['status' => 'run', 'at' => (int) ($raw['at'] ?? $t0), 'started' => $t0, 'stage' => $s]);
        };
        $stage(preg_match('#^https?://\S+$#i', trim((string) ($a['text'] ?? ''))) ? '🌐 페이지 여는 중' : '📝 글 정리 중');
        // PHP 가 시간 제한 등으로 멈추면 까닭을 남김
        register_shutdown_function(static function () use ($id) {
            $j = self::jobRaw($id);
            if ($j !== null && ($j['status'] ?? '') === 'run') {
                $e = error_get_last();
                self::jobPut($id, ['status' => 'fail', 'message' => '서버가 일을 멈췄어요 (마지막: '.($j['stage'] ?? '?').')'.($e ? ' — '.mb_substr((string) $e['message'], 0, 200) : '')]);
            }
        });
        $this->ai->onTry = function (string $sv, string $model, int $timeout, array $errors) use ($stage) {
            $stage('🤖 '.$sv.' · '.$model.' 에게 묻는 중 (최대 '.$timeout.'초)'.($errors ? ' — 앞 서버 실패: '.mb_substr((string) end($errors), 0, 120) : ''));
        };
        try {
            $res = $this->extract((string) ($a['type'] ?? ''), (string) ($a['kind'] ?? ''), (string) ($a['text'] ?? ''), (string) ($a['brand'] ?? ''), (string) ($a['title'] ?? ''));
            self::jobPut($id, ['status' => 'done', 'data' => $res, 'message' => count($res['values']) + count($res['facts']).'칸을 채웠어요 — 맞는지 보고 저장해 주세요.']);
        } catch (\InvalidArgumentException $e) {
            self::jobPut($id, ['status' => 'fail', 'message' => $e->getMessage()]);
        } catch (\Throwable $e) {
            self::jobPut($id, ['status' => 'fail', 'message' => 'AI 가 정리하지 못했어요 — '.mb_substr($e->getMessage(), 0, 300)]);
        } finally {
            $this->ai->onTry = null;
        }
    }

    /** HTML → 읽을 글 (스크립트 · 꾸밈 빼고, 표는 칸 사이 「 : 」) */
    public static function plain(string $html): string
    {
        $html = preg_replace('#<(script|style|noscript|svg|nav|footer|header)\b[^>]*>.*?</\1>#is', ' ', $html) ?? $html;
        $html = preg_replace('#</(td|th)>\s*<(td|th)\b[^>]*>#i', ' : ', $html) ?? $html;
        $html = preg_replace('#<(br|/p|/div|/li|/tr|/h[1-6])\b[^>]*>#i', "\n", $html) ?? $html;
        $t = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $t = preg_replace('/[ \t\x{00A0}]+/u', ' ', $t) ?? $t;

        return trim(preg_replace('/\s*\n\s*(\n\s*)*/u', "\n", $t) ?? $t);
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

    /** 제안 반영 — $edited: 0.2.11 관리자가 편집 창에서 고친 값 (그대로 저장 · 빈 칸만이 아니라 전부) @return array{key: string} */
    public function apply(int $id, ?array $edited = null): array
    {
        $sg = DB::table(Schema::SUGGEST)->where('id', $id)->first();
        if (! $sg || $sg->status !== 'pending') {
            throw new \InvalidArgumentException('이미 처리한 제안이에요.');
        }
        $p = is_array($edited) ? $edited : Schema::json($sg->payload);
        $type = (string) $sg->item_type;
        if (is_array($edited) && $sg->task !== 'photo') {
            unset($edited['type']);
            $key = $sg->task === 'new' ? $this->catalog->save($type, ['key' => null] + $edited, false, str_starts_with((string) ($sg->source ?? ''), '회원') ? 'members' : 'ai')['key']
                : $this->catalog->save($type, ['key' => (string) $sg->item_key] + $edited)['key'];
        } elseif ($sg->task === 'new') {
            $key = $this->catalog->save($type, $p, true, str_starts_with((string) ($sg->source ?? ''), '회원') ? 'members' : 'ai')['key'];
        } elseif ($sg->task === 'fill' || $sg->task === 'sds') {
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
                'source' => (string) ($r->source ?? ''), 'status' => (string) $r->status, 'at' => substr((string) $r->created_at, 0, 16),
                // 0.2.11 출처 — AI 가 댄 주소(열어 본 결과) · 안전 자료 · 사진은 그 페이지
                'sources' => array_values(array_filter(is_array($p['sources'] ?? null) ? $p['sources'] : (! empty($p['page_url']) ? [['url' => (string) $p['page_url'], 'ok' => true]] : []), static fn ($x) => is_array($x) && is_string($x['url'] ?? null))),
                // 0.2.11 「✏️ 고쳐서 반영」 편집 창에 채울 값
                'draft' => $r->task === 'photo' ? null : array_intersect_key($p, array_flip(['brand', 'title', 'kind', 'values', 'summary', 'homepage_url', 'issues', 'msds_url']))];
        }

        return ['items' => $items, 'pending' => (int) DB::table(Schema::SUGGEST)->where('status', 'pending')->count()];
    }
}
