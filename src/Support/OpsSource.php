<?php

namespace Modules\Custom\Catalog\Support;

use Illuminate\Support\Facades\DB;
use Modules\Custom\Catalog\Services\CatalogService;
use Modules\Custom\Catalog\Services\Collector;
use Modules\Custom\Catalog\Services\PartsService;
use Modules\Custom\Catalog\Services\PhotoService;

/**
 * 0.3.13 🖥️ 3DS 관리 프로그램(custom-ops 0.1.11+ · 프로그램 0.11.0+) 연결 (필터 custom-ops.sources)
 *
 *  할 일 — 관리 화면과 같은 처리:
 *   💡 제안함 (AI 자동 수집 · 회원 제안, Collector): ✔ 반영 · 버리기 — 「✏️ 고쳐서 반영」은 사이트에서
 *   🔩 부품 맞음 확인 (PartsService::editFit): ✅ 맞음 · 🔧 손보면 맞음 · ❌ 안 맞음 · 🗑 지우기
 *  📋 항목 목록 — 프린터 · 재료 · 부품 (탭마다 거르기): 📦 보관 · ↩ 되살리기 · 💥 아주 지우기 (보관한 것만 · 사진까지)
 */
final class OpsSource
{
    public const ADMIN = '/admin/catalog';

    public function label(): string
    {
        return '🧊 카탈로그';
    }

    public function tabs(): array
    {
        return [
            ['key' => 'suggest', 'label' => '💡 제안함'],
            ['key' => 'fits', 'label' => '🔩 부품 맞음 확인'],
            ['key' => 'items', 'label' => '📋 항목 목록', 'kind' => 'list'],
        ];
    }

    /** @return list<array<string, mixed>> */
    public function items(): array
    {
        $out = [];
        $sg = app(Collector::class)->suggestions('pending', 300);
        foreach ($sg['items'] as $s) {
            $lines = array_map(static fn ($l) => $l['label'].' '.$l['value'], array_slice($s['lines'], 0, 6));
            $out[] = [
                'id' => 'suggest:'.(int) $s['id'],
                'kind' => '💡 '.(['new' => '새 항목', 'fill' => '제원 채우기', 'photo' => '사진', 'sds' => '안전 자료'][$s['task']] ?? $s['task']).($s['kind_label'] !== '' ? ' · '.$s['kind_label'] : ''),
                'title' => (string) $s['title'],
                'detail' => trim(($s['summary'] !== '' ? mb_substr($s['summary'], 0, 150).' · ' : '').implode(' · ', $lines).($s['source'] !== '' ? ' — '.$s['source'] : ''), ' ·'),
                'url' => $s['task'] === 'photo' ? (string) $s['image_url'] : (string) ($s['page_url'] ?: ($s['sources'][0]['url'] ?? '')),
                'admin_url' => self::ADMIN,
                'at' => (string) $s['at'],
                'level' => 'normal',
                'actions' => ['apply' => '✔ 반영', 'reject' => '버리기'],
                'group' => 'suggest',
            ];
        }
        foreach (app(PartsService::class)->pending()['fits'] as $f) {
            $v = $f['votes'];
            $out[] = [
                'id' => 'fit:'.(int) $f['id'],
                'kind' => '🔩 부품 맞음',
                'title' => $f['part']['title'].' → '.$f['printer']['title'],
                'detail' => '회원 표: 맞음 '.$v['fit'].' · 손보면 '.$v['mod'].' · 안 맞음 '.$v['no'],
                'url' => '/catalog/'.$f['part']['key'],
                'admin_url' => self::ADMIN,
                'at' => (string) $f['at'],
                'level' => 'normal',
                'actions' => ['fit' => '✅ 맞음', 'mod' => '🔧 손보면 맞음', 'no' => '❌ 안 맞음', 'delete' => ['label' => '🗑 연결 지우기', 'danger' => true, 'reason' => '이 부품 · 프린터 연결과 회원 표를 지워요.']],
                'group' => 'fits',
            ];
        }

        return $out;
    }

    public function act(string $id, string $action, int $adminId, string $note = ''): string
    {
        try {
            if (preg_match('/^suggest:(\d+)$/', $id, $m)) {
                $col = app(Collector::class);
                if ($action === 'apply') {
                    $res = $col->apply((int) $m[1]);

                    return '반영했어요 ('.$res['key'].').';
                }
                if ($action === 'reject') {
                    if (! DB::table(Schema::SUGGEST)->where('id', (int) $m[1])->where('status', 'pending')->exists()) {
                        throw new \DomainException('이미 처리한 제안이에요.', 409);
                    }
                    $col->reject((int) $m[1]);

                    return '버렸어요.';
                }
            } elseif (preg_match('/^fit:(\d+)$/', $id, $m)) {
                $svc = app(PartsService::class);
                if (in_array($action, ['fit', 'mod', 'no'], true)) {
                    $svc->editFit((int) $m[1], 'status', $action, trim($note) !== '' ? $note : null);

                    return ['fit' => '맞음', 'mod' => '손보면 맞음', 'no' => '안 맞음'][$action].'으로 정했어요.';
                }
                if ($action === 'delete') {
                    $svc->editFit((int) $m[1], 'delete');

                    return '연결을 지웠어요.';
                }
            } else {
                throw new \DomainException('없는 항목이에요.', 404);
            }
        } catch (\InvalidArgumentException $e) {
            throw new \DomainException($e->getMessage(), 422);
        }
        throw new \InvalidArgumentException('알 수 없는 처리예요.');
    }

    /** @param array{q?: string, filter?: string, page?: int} $q */
    public function list(string $key, array $q): array
    {
        self::key($key);
        Schema::ensure();
        $f = (string) ($q['filter'] ?? '');
        $archived = str_starts_with($f, 'archived');
        $tab = $archived ? substr($f, 9) : $f;
        $tabs = [];
        foreach (Fields::TABS as $t) {
            $tabs[$t[0]] = $t;
        }
        $tab = isset($tabs[$tab]) ? $tab : 'fdm';
        [, , , $type, $kinds] = $tabs[$tab];
        $b = DB::table(Schema::table($type))->whereIn('kind', $kinds)->where('status', $archived ? 'archived' : 'active');
        $name = $type === 'equipment' ? 'model' : 'name';
        $t = trim((string) ($q['q'] ?? ''));
        if ($t !== '') {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $t).'%';
            $b->where(static fn ($w) => $w->where('brand', 'like', $like)->orWhere($name, 'like', $like)->orWhere('key', 'like', $like));
        }
        $per = 30;
        $page = max(1, (int) ($q['page'] ?? 1));
        $total = (clone $b)->count();
        $rows = $b->orderBy('brand')->orderBy($name)->offset(($page - 1) * $per)->limit($per)->get()->all();
        $filters = [];
        foreach ($tabs as $k => $x) {
            $filters[$k] = $x[2].' '.$x[1];
        }
        foreach ($tabs as $k => $x) {
            $filters['archived:'.$k] = '📦 보관 — '.$x[1];
        }

        return ['columns' => ['키', '제조사', '이름', '종류', '상태', '고친 때'], 'total' => $total, 'per' => $per, 'filters' => $filters,
            'note' => '거르기 = 탭 (처음은 FDM 프린터) · 제원 · 사진 고치기는 사이트 관리 화면에서',
            'rows' => array_map(static fn ($r) => ['id' => 'item:'.$type.':'.(string) $r->key, 'level' => $r->status === 'archived' ? 'low' : 'normal',
                'cells' => [(string) $r->key, (string) $r->brand, (string) $r->{$name}, Fields::kindLabel($type, (string) $r->kind), $r->status === 'archived' ? '📦 보관' : '보임', substr((string) ($r->updated_at ?? ''), 0, 16)]], $rows)];
    }

    public function detail(string $key, string $id): array
    {
        self::key($key);
        [$type, $r] = self::item($id);
        $name = trim($r->brand.' '.($type === 'equipment' ? $r->model : $r->name));
        $gone = $r->status === 'archived';
        $photos = (int) DB::table(Schema::PHOTOS)->where('item_type', ['materials' => 'material', 'parts' => 'part'][$type] ?? 'equipment')->where('item_key', $r->key)->count();
        $rows = [['키', (string) $r->key], ['종류', ['equipment' => '장비', 'materials' => '재료', 'parts' => '부품'][$type].' · '.Fields::kindLabel($type, (string) $r->kind)], ['상태', $gone ? '📦 보관' : '보임'], ['사진', $photos.'장']];
        foreach (['homepage_url' => '공식 페이지', 'material' => '재료', 'color' => '색', 'nozzle' => '노즐', 'note' => '메모', 'summary' => '소개', 'source' => '출처'] as $c => $l) {
            if (trim((string) ($r->{$c} ?? '')) !== '') {
                $rows[] = [$l, mb_substr(trim((string) $r->{$c}), 0, 300)];
            }
        }
        $rows[] = ['고친 때', substr((string) ($r->updated_at ?? ''), 0, 16)];

        return ['title' => $name, 'subtitle' => (string) $r->key.' · '.($gone ? '📦 보관' : '보임'), 'url' => $gone ? '' : '/catalog/'.$r->key, 'admin_url' => self::ADMIN,
            'note' => '제원 · 사진 고치기는 사이트 관리 화면에서',
            'sections' => [['label' => '항목', 'rows' => $rows]],
            'actions' => $gone ? ['restore' => '↩ 되살리기', 'purge' => ['label' => '💥 아주 지우기', 'danger' => true, 'reason' => '항목과 사진 파일까지 지워요. 되돌릴 수 없어요.']]
                : ['archive' => ['label' => '📦 보관 (카탈로그에서 빼기)', 'reason' => '「📦 보관」 거르기에서 되살릴 수 있어요.']]];
    }

    public function listAct(string $key, string $id, string $action, int $adminId, string $note = ''): string
    {
        self::key($key);
        [$type, $r] = self::item($id);
        $svc = app(CatalogService::class);
        switch ($action) {
            case 'archive':
                if ($r->status === 'archived') {
                    throw new \DomainException('이미 보관한 항목이에요.', 409);
                }
                $svc->remove($type, (string) $r->key);

                return '보관함으로 옮겼어요.';
            case 'restore':
                if ($r->status !== 'archived') {
                    throw new \DomainException('보관한 항목이 아니에요.', 409);
                }
                $svc->restore($type, (string) $r->key);

                return '되살렸어요.';
            case 'purge':
                if ($r->status !== 'archived') {
                    throw new \DomainException('보관한 항목만 아주 지울 수 있어요.', 422);
                }
                app(PhotoService::class)->purge($type, (string) $r->key);   // 관리 화면과 같은 순서 (사진 → 항목)
                $svc->remove($type, (string) $r->key, true);

                return '아주 지웠어요.';
        }
        throw new \InvalidArgumentException('알 수 없는 처리예요.');
    }

    private static function key(string $key): void
    {
        if ($key !== 'items') {
            throw new \DomainException('없는 목록이에요.', 404);
        }
    }

    /** @return array{0: string, 1: object} */
    private static function item(string $id): array
    {
        if (! preg_match('/^item:(equipment|materials|parts):([a-z0-9][a-z0-9_-]{1,79})$/', $id, $m)) {
            throw new \DomainException('없는 항목이에요.', 404);
        }
        Schema::ensure();
        $r = DB::table(Schema::table($m[1]))->where('key', $m[2])->first();

        return $r ? [$m[1], $r] : throw new \DomainException('없는 항목이에요.', 404);
    }
}
