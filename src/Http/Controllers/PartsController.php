<?php

namespace Modules\Custom\Catalog\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Custom\Catalog\Services\PartsService;
use Modules\Custom\Catalog\Support\Access;

/**
 * 0.3.0 🔩 부품 · 업그레이드 — 회원: 맞음 투표 · 맞는 프린터 제안 · 후기 · 구매처 · 새 부품 제안 / 편집자: 확인 · 숨기기
 */
class PartsController extends Controller
{
    public function __construct(private PartsService $parts) {}

    private static function ok(mixed $data, string $message = ''): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $data, 'message' => $message]);
    }

    private static function run(callable $fn, string $message = ''): JsonResponse
    {
        try {
            return self::ok($fn(), $message);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    private static function uid(Request $r): int
    {
        return CatalogController::uid($r);
    }

    public function printers(Request $r): JsonResponse
    {
        return self::ok($this->parts->printerPick(mb_substr((string) $r->query('q', ''), 0, 60)));
    }

    public function vote(Request $r, string $key): JsonResponse
    {
        return self::run(fn () => $this->parts->vote(self::uid($r), $key, (string) $r->input('printer', ''), (string) $r->input('vote', ''), Access::canEdit($r)), '고마워요 — 표를 남겼어요.');
    }

    public function review(Request $r, string $key): JsonResponse
    {
        return self::run(fn () => $this->parts->review(self::uid($r), $key, (array) $r->all()), '후기를 남겼어요.');
    }

    public function reviewDelete(Request $r, int $id): JsonResponse
    {
        return self::run(function () use ($r, $id) {
            $this->parts->removeReview(self::uid($r), $id, Access::canEdit($r));

            return ['ok' => true];
        }, '후기를 지웠어요.');
    }

    public function link(Request $r, string $key): JsonResponse
    {
        return self::run(fn () => $this->parts->addLink(self::uid($r), $key, (array) $r->all()), '구매처를 남겼어요.');
    }

    public function linkAction(Request $r, int $id, string $action): JsonResponse
    {
        return self::run(function () use ($r, $id, $action) {
            $this->parts->linkAction(self::uid($r), $id, $action, Access::canEdit($r));

            return ['ok' => true];
        }, $action === 'report' ? '신고했어요 — 여러 번 신고되면 숨겨져요.' : '바꿨어요.');
    }

    public function propose(Request $r): JsonResponse
    {
        $edit = Access::canEdit($r);

        return self::run(fn () => $this->parts->propose(self::uid($r), (array) $r->all(), $edit), $edit ? '새 부품을 넣었어요.' : '제안을 보냈어요 — 관리자가 확인하면 카탈로그에 올라가요.');
    }

    /* ── 편집자 ── */

    private static function guard(Request $r): ?JsonResponse
    {
        return Access::canEdit($r) ? null : response()->json(['success' => false, 'message' => '카탈로그를 고칠 권한이 없어요.'], 403);
    }

    public function pending(Request $r): JsonResponse
    {
        return self::guard($r) ?? self::ok($this->parts->pending());
    }

    public function fit(Request $r, int $id): JsonResponse
    {
        return self::guard($r) ?? self::run(fn () => $this->parts->editFit($id, (string) $r->input('action', ''), $r->input('status'), $r->has('note') ? (string) $r->input('note') : null), '바꿨어요.');
    }

    public function reviewHide(Request $r, int $id): JsonResponse
    {
        if ($g = self::guard($r)) {
            return $g;
        }
        $this->parts->hideReview($id, (bool) $r->input('hide', true));

        return self::ok(['ok' => true], '바꿨어요.');
    }
}
