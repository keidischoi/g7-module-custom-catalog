<?php

namespace Modules\Custom\Catalog\Services;

use Illuminate\Support\Facades\DB;
use Modules\Custom\Catalog\Support\Fields;
use Modules\Custom\Catalog\Support\Schema;
use Modules\Custom\Catalog\Support\Settings;

/**
 * 0.3.0 🔩 부품 · 업그레이드 호환표 — 회원이 채우고 관리자는 확인.
 *
 *  - 맞는 프린터(cat_part_fits): 부품 ↔ 카탈로그 프린터. 편집자가 넣거나 확인하면 바로 보이고,
 *    회원이 제안한 연결은 표가 parts_auto_votes 개 쌓이면 보임 (맞음 · 개조 · 안 맞음 어느 쪽이든 — 결과가 그 표로) (그 전엔 「🕓 확인 중」)
 *  - 맞음 투표(cat_part_votes): fit(그대로 맞음) · mod(개조 필요) · no(안 맞음). 내 프린터(custom-my_printer)에
 *    그 프린터가 있는 회원의 표는 ✔ 실사용 — 두 배로 셈. 편집자가 상태를 정하면 그것이 먼저
 *  - 후기(cat_part_reviews): 회원마다 부품 하나에 하나 (고치면 덮어씀) · 별점 · 쓴 프린터 · 같이 쓴 소재 · 사용 기간
 *  - 구매처(cat_part_links): 회원이 남긴 가게 주소 · 가격 · 신고 3번이면 숨김
 *  - 새 부품 제안: 편집자는 바로 저장, 회원은 제안함(cat_suggestions, task new · item_type parts)으로
 */
final class PartsService
{
    public const VOTES_OK = ['fit' => '그대로 맞음', 'mod' => '개조 필요', 'no' => '안 맞음'];

    /** 테스트용: 회원이 내 프린터에 등록한 카탈로그 프린터 키 */
    public static ?\Closure $myPrinters = null;

    public function __construct(private CatalogService $catalog) {}

    /** @return list<string> 이 회원이 「내 프린터」에 등록한 카탈로그 프린터 키 (custom-my_printer mpr_printers) */
    public static function userPrinters(int $uid): array
    {
        if ($uid < 1) {
            return [];
        }
        if (self::$myPrinters) {
            return (self::$myPrinters)($uid);
        }
        try {
            if (! \Illuminate\Support\Facades\Schema::hasTable('mpr_printers')) {
                return [];
            }
            $keys = [];
            $eq = null;
            foreach (DB::table('mpr_printers')->where('user_id', $uid)->get() as $p) {
                $k = (string) ($p->catalog_key ?? '');
                if ($k === '' && ! empty($p->model)) {
                    // 카탈로그를 고르지 않고 직접 적은 프린터 — 제조사 · 모델 이름이 같으면 그 항목으로
                    $eq ??= DB::table(Schema::EQUIPMENT)->where('status', 'active')->get(['key', 'brand', 'model']);
                    foreach ($eq as $e) {
                        if (CatalogService::norm($e->model) === CatalogService::norm((string) $p->model) && (empty($p->brand) || CatalogService::norm($e->brand) === CatalogService::norm((string) $p->brand))) {
                            $k = (string) $e->key;
                            break;
                        }
                    }
                }
                if ($k !== '') {
                    $keys[$k] = true;
                }
            }

            return array_keys($keys);
        } catch (\Throwable) {
            return [];
        }
    }

    private static function part(string $key): object
    {
        $p = DB::table(Schema::PARTS)->where('key', $key)->where('status', 'active')->first();
        if (! $p) {
            throw new \InvalidArgumentException('없는 부품이에요.');
        }

        return $p;
    }

    private static function printer(string $key): object
    {
        $e = DB::table(Schema::EQUIPMENT)->where('key', $key)->where('status', 'active')->first();
        if (! $e) {
            throw new \InvalidArgumentException('카탈로그에 없는 프린터예요.');
        }

        return $e;
    }

    /** 투표로 정한 상태 · 편집자가 정한 것이 먼저 @param array{fit: int, mod: int, no: int} $w */
    public static function decide(?string $set, array $w, bool $approved): string
    {
        if (in_array($set, ['fit', 'mod', 'no'], true)) {
            return $set;
        }
        if (array_sum($w) === 0) {
            return $approved ? 'fit' : 'pending';
        }
        arsort($w);

        return (string) array_key_first($w);
    }

    /**
     * 부품 하나의 맞는 프린터 — 편집자 확인 · 투표 · 내 표
     *
     * @return array{items: list<array<string, mixed>>, pending: list<array<string, mixed>>}
     */
    public function fitsOf(string $partKey, int $uid = 0, bool $edit = false): array
    {
        Schema::ensure();
        $fits = DB::table(Schema::FITS)->where('part_key', $partKey)->get();
        if ($fits->isEmpty()) {
            return ['items' => [], 'pending' => []];
        }
        $votes = DB::table(Schema::VOTES)->where('part_key', $partKey)->get()->groupBy('printer_key');
        $eq = DB::table(Schema::EQUIPMENT)->whereIn('key', $fits->pluck('printer_key')->all())->where('status', 'active')->get()->keyBy('key');
        $auto = max(1, (int) Settings::get('parts_auto_votes'));
        $items = [];
        $pending = [];
        foreach ($fits as $f) {
            $e = $eq[$f->printer_key] ?? null;
            if (! $e) {
                continue;
            }
            $w = ['fit' => 0, 'mod' => 0, 'no' => 0];
            $n = ['fit' => 0, 'mod' => 0, 'no' => 0];
            $real = 0;
            $mine = null;
            foreach ($votes[$f->printer_key] ?? [] as $v) {
                if (! isset($w[$v->vote])) {
                    continue;
                }
                $w[$v->vote] += $v->real_use ? 2 : 1;
                $n[$v->vote]++;
                $real += $v->real_use ? 1 : 0;
                if ($uid > 0 && (int) $v->user_id === $uid) {
                    $mine = (string) $v->vote;
                }
            }
            $status = self::decide($f->status, $w, (bool) $f->approved);
            $row = ['id' => (int) $f->id, 'printer' => $this->catalog->card('equipment', $e), 'status' => $status, 'status_label' => self::VOTES_OK[$status] ?? '확인 중',
                'set_by_editor' => in_array($f->status, ['fit', 'mod', 'no'], true), 'approved' => (bool) $f->approved, 'votes' => $n, 'real_use' => $real, 'mine' => $mine, 'note' => (string) ($f->note ?? '')];
            if ($f->approved || array_sum($n) >= $auto || in_array($f->status, ['fit', 'mod', 'no'], true)) {
                $items[] = $row;
            } else {
                $pending[] = $row + ['need' => max(0, $auto - array_sum($n))];
            }
        }
        $ord = ['fit' => 0, 'mod' => 1, 'pending' => 2, 'no' => 3];
        usort($items, static fn ($a, $b) => [$ord[$a['status']] ?? 9, $a['printer']['brand'], $a['printer']['title']] <=> [$ord[$b['status']] ?? 9, $b['printer']['brand'], $b['printer']['title']]);

        return ['items' => $items, 'pending' => $pending];
    }

    /**
     * 프린터 하나에 맞는 부품 (프린터 상세 · 「내 프린터용」) — 종류별, 「안 맞음」은 빼고
     *
     * @return list<array{kind: string, label: string, icon: string, items: list<array<string, mixed>>}>
     */
    public function forPrinter(string $printerKey, int $limit = 300): array
    {
        Schema::ensure();
        $auto = max(1, (int) Settings::get('parts_auto_votes'));
        // 0.3.3 같은 프린터의 다른 항목(「A1 Combo」 · 숨김/중복 항목)에 이어진 부품도 — 이 항목 것이 먼저
        $keys = self::sameKeys($printerKey);
        $fits = DB::table(Schema::FITS)->whereIn('printer_key', $keys)->get()
            ->sortBy(static fn ($f) => $f->printer_key === $printerKey ? 0 : 1)->unique('part_key')->keyBy('part_key');
        if ($fits->isEmpty()) {
            return [];
        }
        $votes = DB::table(Schema::VOTES)->whereIn('printer_key', $keys)->get()->groupBy('part_key');
        $avg = self::avgScores(array_keys($fits->all()));
        $by = [];
        foreach (DB::table(Schema::PARTS)->whereIn('key', array_keys($fits->all()))->where('status', 'active')->limit($limit)->get() as $p) {
            $f = $fits[$p->key];
            $w = ['fit' => 0, 'mod' => 0, 'no' => 0];
            $nfit = 0;
            foreach ($votes[$p->key] ?? [] as $v) {
                if (isset($w[$v->vote])) {
                    $w[$v->vote] += $v->real_use ? 2 : 1;
                    $nfit++;
                }
            }
            $st = self::decide($f->status, $w, (bool) $f->approved);
            if ($st === 'no' || (! $f->approved && ! $f->status && $nfit < $auto)) {
                continue;
            }
            $by[$p->kind][] = $this->catalog->card('parts', $p) + ['fit' => $st, 'fit_label' => self::VOTES_OK[$st] ?? '', 'score' => $avg[$p->key] ?? null];
        }
        $out = [];
        foreach (Fields::PART_KINDS as $k => [$label, $icon]) {
            if (! empty($by[$k])) {
                $out[] = ['kind' => $k, 'label' => $label, 'icon' => $icon, 'items' => $by[$k]];
            }
        }

        return $out;
    }

    /** 0.3.3 같은 프린터로 보는 항목들 — 제조사가 같고 모델 이름이 같음 (「Combo」 · 「콤보」 · 「with AMS」 는 빼고 비교) @return list<string> */
    public static function sameKeys(string $printerKey): array
    {
        $me = DB::table(Schema::EQUIPMENT)->where('key', $printerKey)->first(['brand', 'model']);
        if (! $me) {
            return [$printerKey];
        }
        $nb = CatalogService::norm($me->brand);
        $nm = self::baseModel((string) $me->model);
        $out = [$printerKey];
        if ($nb === '' || $nm === '') {
            return $out;
        }
        foreach (DB::table(Schema::EQUIPMENT)->where('key', '!=', $printerKey)->whereIn('kind', ['fdm', 'sla', 'dlp'])->get(['key', 'brand', 'model']) as $e) {
            $eb = CatalogService::norm($e->brand);
            if (($eb === $nb || str_starts_with($eb, $nb) || str_starts_with($nb, $eb)) && self::baseModel((string) $e->model) === $nm) {
                $out[] = (string) $e->key;
            }
        }

        return $out;
    }

    public static function baseModel(string $model): string
    {
        return (string) preg_replace('/(combo|콤보|withams(lite)?|ams(lite)?combo)$/u', '', CatalogService::norm($model));
    }

    /** @param list<string> $keys @return array<string, array{avg: float, n: int}> */
    public static function avgScores(array $keys): array
    {
        if (! $keys) {
            return [];
        }
        $out = [];
        foreach (DB::table(Schema::REVIEWS)->whereIn('part_key', $keys)->where('status', 'visible')->select('part_key', DB::raw('avg(score) as a'), DB::raw('count(*) as n'))->groupBy('part_key')->get() as $r) {
            $out[(string) $r->part_key] = ['avg' => round((float) $r->a, 1), 'n' => (int) $r->n];
        }

        return $out;
    }

    /** 맞는 프린터에 표 — 연결이 없으면 만듦(회원 = 제안 · 편집자 = 확인) @return array<string, mixed> */
    public function vote(int $uid, string $partKey, string $printerKey, string $vote, bool $edit = false): array
    {
        if ($uid < 1) {
            throw new \InvalidArgumentException('로그인이 필요해요.');
        }
        if (! isset(self::VOTES_OK[$vote]) && $vote !== '') {
            throw new \InvalidArgumentException('맞음 · 개조 필요 · 안 맞음 중에 골라 주세요.');
        }
        Schema::ensure();
        self::part($partKey);
        self::printer($printerKey);
        $fit = DB::table(Schema::FITS)->where('part_key', $partKey)->where('printer_key', $printerKey)->first();
        if (! $fit) {
            if ($vote === '') {
                return $this->fitsOf($partKey, $uid, $edit);
            }
            DB::table(Schema::FITS)->insert(['part_key' => $partKey, 'printer_key' => $printerKey, 'approved' => $edit, 'created_by' => $uid, 'created_at' => now(), 'updated_at' => now()]);
        }
        if ($vote === '') {
            DB::table(Schema::VOTES)->where(['part_key' => $partKey, 'printer_key' => $printerKey, 'user_id' => $uid])->delete();   // 표 거두기
        } else {
            DB::table(Schema::VOTES)->updateOrInsert(['part_key' => $partKey, 'printer_key' => $printerKey, 'user_id' => $uid],
                ['vote' => $vote, 'real_use' => in_array($printerKey, self::userPrinters($uid), true), 'updated_at' => now(), 'created_at' => now()]);
        }

        return $this->fitsOf($partKey, $uid, $edit);
    }

    /** 편집자: 연결 확인 · 상태 정하기 · 지우기 @return array<string, mixed> */
    public function editFit(int $id, string $action, ?string $status = null, ?string $note = null): array
    {
        Schema::ensure();
        $f = DB::table(Schema::FITS)->where('id', $id)->first();
        if (! $f) {
            throw new \InvalidArgumentException('없는 연결이에요.');
        }
        if ($action === 'delete') {
            DB::table(Schema::FITS)->where('id', $id)->delete();
            DB::table(Schema::VOTES)->where(['part_key' => $f->part_key, 'printer_key' => $f->printer_key])->delete();

            return ['ok' => true];
        }
        $row = ['updated_at' => now()];
        if ($action === 'approve') {
            $row['approved'] = true;
        }
        if ($action === 'status') {
            $row['status'] = in_array($status, ['fit', 'mod', 'no'], true) ? $status : null;
            $row['approved'] = true;
        }
        if ($note !== null) {
            $row['note'] = mb_substr(trim(strip_tags($note)), 0, 300) ?: null;
        }
        DB::table(Schema::FITS)->where('id', $id)->update($row);

        return ['ok' => true];
    }

    /** 편집자: 확인 기다리는 연결 · 신고된 구매처 @return array<string, mixed> */
    public function pending(): array
    {
        Schema::ensure();
        $fits = DB::table(Schema::FITS)->where('approved', false)->whereNull('status')->orderByDesc('id')->limit(100)->get();
        $parts = DB::table(Schema::PARTS)->whereIn('key', $fits->pluck('part_key')->all())->get()->keyBy('key');
        $eq = DB::table(Schema::EQUIPMENT)->whereIn('key', $fits->pluck('printer_key')->all())->get()->keyBy('key');
        $votes = DB::table(Schema::VOTES)->whereIn('part_key', $fits->pluck('part_key')->all())->get();
        $items = [];
        foreach ($fits as $f) {
            if (! isset($parts[$f->part_key], $eq[$f->printer_key])) {
                continue;
            }
            $n = ['fit' => 0, 'mod' => 0, 'no' => 0];
            foreach ($votes as $v) {
                if ($v->part_key === $f->part_key && $v->printer_key === $f->printer_key && isset($n[$v->vote])) {
                    $n[$v->vote]++;
                }
            }
            $p = $parts[$f->part_key];
            $e = $eq[$f->printer_key];
            $items[] = ['id' => (int) $f->id, 'part' => ['key' => (string) $p->key, 'title' => trim($p->brand.' '.$p->name)], 'printer' => ['key' => (string) $e->key, 'title' => trim($e->brand.' '.$e->model)],
                'votes' => $n, 'at' => substr((string) $f->created_at, 0, 10)];
        }
        $links = DB::table(Schema::LINKS)->where('reports', '>', 0)->orderByDesc('reports')->limit(50)->get()
            ->map(static fn ($l) => ['id' => (int) $l->id, 'part_key' => (string) $l->part_key, 'url' => (string) $l->url, 'label' => (string) ($l->label ?? ''), 'reports' => (int) $l->reports, 'status' => (string) $l->status])->all();

        return ['fits' => $items, 'links' => $links];
    }

    /* ── 후기 ── */

    /** @return array{items: list<array<string, mixed>>, avg: float|null, n: int, mine: array<string, mixed>|null} */
    public function reviews(string $partKey, int $uid = 0, bool $edit = false): array
    {
        Schema::ensure();
        $q = DB::table(Schema::REVIEWS)->where('part_key', $partKey);
        if (! $edit) {
            $q->where(fn ($w) => $w->where('status', 'visible')->orWhere('user_id', $uid));
        }
        $rows = $q->orderByDesc('real_use')->orderByDesc('updated_at')->limit(100)->get();
        $names = self::names($rows->pluck('user_id')->all());
        $eq = DB::table(Schema::EQUIPMENT)->whereIn('key', $rows->pluck('printer_key')->filter()->all())->get()->keyBy('key');
        $items = [];
        $mine = null;
        $sum = 0;
        $n = 0;
        foreach ($rows as $r) {
            $e = $r->printer_key ? ($eq[$r->printer_key] ?? null) : null;
            $it = ['id' => (int) $r->id, 'score' => (int) $r->score, 'body' => (string) ($r->body ?? ''), 'materials' => (string) ($r->materials ?? ''), 'months' => $r->months !== null ? (int) $r->months : null,
                'real_use' => (bool) $r->real_use, 'printer' => $e ? trim($e->brand.' '.$e->model) : '', 'printer_key' => (string) ($r->printer_key ?? ''), 'user' => $names[(int) $r->user_id] ?? '회원',
                'mine' => $uid > 0 && (int) $r->user_id === $uid, 'hidden' => $r->status !== 'visible', 'at' => substr((string) $r->updated_at, 0, 10)];
            if ($it['mine']) {
                $mine = $it;
            }
            if ($r->status === 'visible') {
                $sum += (int) $r->score;
                $n++;
            }
            $items[] = $it;
        }

        return ['items' => $items, 'avg' => $n ? round($sum / $n, 1) : null, 'n' => $n, 'mine' => $mine];
    }

    /** @param array<string, mixed> $in @return array<string, mixed> */
    public function review(int $uid, string $partKey, array $in): array
    {
        if ($uid < 1) {
            throw new \InvalidArgumentException('로그인이 필요해요.');
        }
        Schema::ensure();
        self::part($partKey);
        $score = (int) ($in['score'] ?? 0);
        if ($score < 1 || $score > 5) {
            throw new \InvalidArgumentException('별점을 골라 주세요 (1~5).');
        }
        $body = mb_substr(trim(strip_tags((string) ($in['body'] ?? ''))), 0, 2000);
        $pk = trim((string) ($in['printer'] ?? ''));
        if ($pk !== '' && ! DB::table(Schema::EQUIPMENT)->where('key', $pk)->exists()) {
            $pk = '';
        }
        $mine = self::userPrinters($uid);
        $fitKeys = DB::table(Schema::FITS)->where('part_key', $partKey)->pluck('printer_key')->all();
        $real = $pk !== '' ? in_array($pk, $mine, true) : (bool) array_intersect($mine, $fitKeys);
        $months = is_numeric($in['months'] ?? null) ? max(0, min(240, (int) $in['months'])) : null;
        DB::table(Schema::REVIEWS)->updateOrInsert(['part_key' => $partKey, 'user_id' => $uid], [
            'score' => $score, 'body' => $body !== '' ? $body : null, 'printer_key' => $pk !== '' ? $pk : null, 'months' => $months,
            'materials' => mb_substr(trim(strip_tags((string) ($in['materials'] ?? ''))), 0, 120) ?: null, 'real_use' => $real, 'status' => 'visible', 'updated_at' => now(), 'created_at' => now(),
        ]);

        return $this->reviews($partKey, $uid);
    }

    public function removeReview(int $uid, int $id, bool $edit = false): void
    {
        $r = DB::table(Schema::REVIEWS)->where('id', $id)->first();
        if (! $r || (! $edit && (int) $r->user_id !== $uid)) {
            throw new \InvalidArgumentException('지울 수 없는 후기예요.');
        }
        DB::table(Schema::REVIEWS)->where('id', $id)->delete();
    }

    /** 편집자: 후기 숨기기 · 보이기 */
    public function hideReview(int $id, bool $hide): void
    {
        DB::table(Schema::REVIEWS)->where('id', $id)->update(['status' => $hide ? 'hidden' : 'visible', 'updated_at' => now()]);
    }

    /* ── 구매처 ── */

    /** @return list<array<string, mixed>> */
    public function links(string $partKey, int $uid = 0, bool $edit = false): array
    {
        Schema::ensure();
        $q = DB::table(Schema::LINKS)->where('part_key', $partKey);
        if (! $edit) {
            $q->where('status', 'visible');
        }

        return $q->orderByRaw('price_krw is null')->orderBy('price_krw')->orderByDesc('id')->limit(30)->get()
            ->map(static fn ($l) => ['id' => (int) $l->id, 'url' => (string) $l->url, 'label' => (string) ($l->label ?? ''), 'price' => $l->price_krw !== null ? (int) $l->price_krw : null,
                'mine' => $uid > 0 && (int) $l->user_id === $uid, 'reports' => $edit ? (int) $l->reports : null, 'hidden' => $l->status !== 'visible', 'at' => substr((string) $l->updated_at, 0, 10)])->all();
    }

    /** @param array<string, mixed> $in @return list<array<string, mixed>> */
    public function addLink(int $uid, string $partKey, array $in): array
    {
        if ($uid < 1) {
            throw new \InvalidArgumentException('로그인이 필요해요.');
        }
        Schema::ensure();
        self::part($partKey);
        $u = trim((string) ($in['url'] ?? ''));
        if (! preg_match('#^https?://[^\s<>"]{4,}$#i', $u)) {
            throw new \InvalidArgumentException('https:// 로 시작하는 가게 주소를 넣어 주세요.');
        }
        if (DB::table(Schema::LINKS)->where('part_key', $partKey)->where('user_id', $uid)->where('created_at', '>', now()->subDay())->count() >= 5) {
            throw new \InvalidArgumentException('하루에 부품 하나에 5개까지 남길 수 있어요.');
        }
        $price = is_numeric(str_replace([',', ' ', '원'], '', (string) ($in['price'] ?? ''))) ? max(0, min(100000000, (int) str_replace([',', ' ', '원'], '', (string) $in['price']))) : null;
        $host = (string) (parse_url($u, PHP_URL_HOST) ?: '');
        DB::table(Schema::LINKS)->insert(['part_key' => $partKey, 'user_id' => $uid, 'url' => mb_substr($u, 0, 500), 'label' => mb_substr(trim(strip_tags((string) ($in['label'] ?? ''))), 0, 60) ?: mb_substr(preg_replace('/^www\./', '', $host) ?? $host, 0, 60),
            'price_krw' => $price ?: null, 'status' => 'visible', 'created_at' => now(), 'updated_at' => now()]);

        return $this->links($partKey, $uid);
    }

    /** 신고 — 3번이면 숨김 · 편집자는 바로 숨기기 · 보이기 · 지우기 */
    public function linkAction(int $uid, int $id, string $action, bool $edit = false): void
    {
        $l = DB::table(Schema::LINKS)->where('id', $id)->first();
        if (! $l) {
            throw new \InvalidArgumentException('없는 구매처예요.');
        }
        if ($action === 'report') {
            $n = (int) $l->reports + 1;
            DB::table(Schema::LINKS)->where('id', $id)->update(['reports' => $n, 'status' => $n >= 3 ? 'hidden' : $l->status, 'updated_at' => now()]);

            return;
        }
        if ($action === 'delete' && ($edit || (int) $l->user_id === $uid)) {
            DB::table(Schema::LINKS)->where('id', $id)->delete();

            return;
        }
        if (! $edit) {
            throw new \InvalidArgumentException('바꿀 수 없어요.');
        }
        DB::table(Schema::LINKS)->where('id', $id)->update(['status' => $action === 'hide' ? 'hidden' : 'visible', 'reports' => $action === 'show' ? 0 : $l->reports, 'updated_at' => now()]);
    }

    /* ── 새 부품 ── */

    /**
     * 새 부품 — 편집자는 바로 저장(+ 맞는 프린터), 회원은 제안함으로
     *
     * @param  array<string, mixed>  $in  {kind, brand, title, values{}, homepage_url, note, summary, printers[]}
     * @return array{key: ?string, suggested: bool}
     */
    public function propose(int $uid, array $in, bool $edit = false): array
    {
        if ($uid < 1) {
            throw new \InvalidArgumentException('로그인이 필요해요.');
        }
        if (! $edit && ! Settings::get('parts_member')) {
            throw new \InvalidArgumentException('지금은 회원 부품 제안을 받지 않아요.');
        }
        Schema::ensure();
        $printers = array_values(array_unique(array_filter(array_map('strval', is_array($in['printers'] ?? null) ? $in['printers'] : []), static fn ($k) => preg_match('/^[a-z0-9][a-z0-9_-]{1,59}$/', $k) === 1)));
        $printers = DB::table(Schema::EQUIPMENT)->whereIn('key', array_slice($printers, 0, 30))->pluck('key')->all();
        if ($edit) {
            $r = $this->catalog->save('parts', $in);
            foreach ($printers as $pk) {
                DB::table(Schema::FITS)->insertOrIgnore(['part_key' => $r['key'], 'printer_key' => $pk, 'approved' => true, 'created_by' => $uid, 'created_at' => now(), 'updated_at' => now()]);
            }

            return ['key' => $r['key'], 'suggested' => false];
        }
        $brand = mb_substr(trim(strip_tags((string) ($in['brand'] ?? ''))), 0, 60);
        $title = mb_substr(trim(strip_tags((string) ($in['title'] ?? ''))), 0, 80);
        if ($brand === '' || $title === '' || ! Fields::validKind('parts', (string) ($in['kind'] ?? ''))) {
            throw new \InvalidArgumentException('종류 · 제조사 · 부품 이름을 적어 주세요.');
        }
        if (DB::table(Schema::SUGGEST)->where('source', '회원 #'.$uid)->where('status', 'pending')->count() >= 20) {
            throw new \InvalidArgumentException('확인을 기다리는 제안이 많아요 — 관리자가 확인한 뒤 더 남겨 주세요.');
        }
        $vals = [];
        foreach (Fields::forKind('parts', (string) $in['kind']) as $d) {
            $v = Fields::clean($d, is_array($in['values'] ?? null) ? ($in['values'][$d['key']] ?? null) : null);
            if ($v !== null) {
                $vals[$d['key']] = $v;
            }
        }
        $payload = ['kind' => (string) $in['kind'], 'brand' => $brand, 'title' => $title, 'values' => $vals, 'printers' => $printers,
            'homepage_url' => preg_match('#^https?://[^\s<>"]+$#i', trim((string) ($in['homepage_url'] ?? ''))) ? trim((string) $in['homepage_url']) : '',
            'summary' => mb_substr(trim(strip_tags((string) ($in['summary'] ?? ''))), 0, 600)];
        DB::table(Schema::SUGGEST)->insert(['task' => 'new', 'item_type' => 'parts', 'item_key' => null, 'title' => mb_substr('🔩 '.$brand.' '.$title, 0, 160),
            'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE), 'source' => '회원 #'.$uid, 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);

        return ['key' => null, 'suggested' => true];
    }

    /** 제안함에서 반영한 뒤 — 회원이 고른 맞는 프린터를 확인된 연결로 @param list<string> $printers */
    public static function fitsFromSuggestion(string $partKey, array $printers, ?int $by = null): void
    {
        foreach (DB::table(Schema::EQUIPMENT)->whereIn('key', array_slice(array_map('strval', $printers), 0, 30))->pluck('key') as $pk) {
            DB::table(Schema::FITS)->insertOrIgnore(['part_key' => $partKey, 'printer_key' => (string) $pk, 'approved' => true, 'created_by' => $by, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    /** 프린터 고르기 칸 (이름으로 찾기) @return list<array{key: string, title: string}> */
    public function printerPick(string $q, int $limit = 20): array
    {
        Schema::ensure();
        $b = DB::table(Schema::EQUIPMENT)->where('status', 'active')->whereIn('kind', ['fdm', 'sla', 'dlp', 'sls', 'mjf', 'metal', 'laser', 'cnc']);
        foreach (array_slice(preg_split('/\s+/u', trim($q)) ?: [], 0, 4) as $w) {
            if ($w !== '') {
                $like = '%'.addcslashes($w, '%_\\').'%';
                $b->where(fn ($x) => $x->where('brand', 'like', $like)->orWhere('model', 'like', $like));
            }
        }

        return $b->orderBy('brand')->orderBy('model')->limit($limit)->get(['key', 'brand', 'model'])->map(static fn ($e) => ['key' => (string) $e->key, 'title' => trim($e->brand.' '.$e->model)])->all();
    }

    /** @param list<int> $ids @return array<int, string> */
    private static function names(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (! $ids) {
            return [];
        }
        try {
            $out = [];
            foreach (DB::table('users')->whereIn('id', $ids)->get() as $u) {
                $out[(int) $u->id] = (string) (($u->nickname ?? '') ?: ($u->name ?? '회원'));
            }

            return $out;
        } catch (\Throwable) {
            return [];
        }
    }
}
