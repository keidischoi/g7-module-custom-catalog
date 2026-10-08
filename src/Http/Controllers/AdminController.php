<?php

namespace Modules\Custom\Catalog\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Custom\Catalog\Services\AiClient;
use Modules\Custom\Catalog\Services\CatalogService;
use Modules\Custom\Catalog\Services\Collector;
use Modules\Custom\Catalog\Services\PhotoService;
use Modules\Custom\Catalog\Support\Access;
use Modules\Custom\Catalog\Support\AiConflict;
use Modules\Custom\Catalog\Support\AiSettings;
use Modules\Custom\Catalog\Support\Settings;

/**
 * 0.2.0 편집 쪽 (관리자 · 카탈로그 편집 역할)
 *
 *  POST admin/items                 저장 (새로 · 고치기)
 *  POST admin/items/{key}/delete    보관 (hard=1 이면 보관한 것을 아주 지움) · restore 되살리기
 *  POST admin/items/{key}/photos    사진 올리기 (photos[]) 또는 주소로 (url)
 *  POST admin/photos/{id}/delete · main
 *  GET/POST admin/settings          설정 · 자동 수집
 *  GET/POST admin/ai · ai/test · ai/models · ai/live · ai/import-jobs   AI 서버 연결 (구인구직과 같은 꼴)
 *  GET admin/collect · POST admin/collect/run     자동 수집 상태 · 지금 한 번
 *  GET admin/suggestions · POST admin/suggestions/{id}/apply · reject
 */
class AdminController extends Controller
{
    public function __construct(private CatalogService $catalog, private PhotoService $photos, private Collector $collector, private AiClient $ai) {}

    private function guard(Request $r): ?JsonResponse
    {
        return Access::canEdit($r) ? null : response()->json(['success' => false, 'message' => '카탈로그를 고칠 권한이 없어요.'], 403);
    }

    private static function ok(mixed $data = null, string $message = ''): JsonResponse
    {
        return response()->json(['success' => true, 'message' => $message, 'data' => $data]);
    }

    private static function fail(string $message, int $code = 422, mixed $data = null): JsonResponse
    {
        return response()->json(['success' => false, 'message' => $message, 'data' => $data], $code);
    }

    private static function type(mixed $v): string
    {
        return $v === 'materials' || $v === 'material' ? 'materials' : 'equipment';
    }

    public function save(Request $r): JsonResponse
    {
        if ($g = $this->guard($r)) {
            return $g;
        }
        try {
            $res = $this->catalog->save(self::type($r->input('type')), (array) $r->all());
        } catch (\InvalidArgumentException $e) {
            return self::fail($e->getMessage());
        }

        return self::ok($res, $res['created'] ? '새 항목을 넣었어요.' : '저장했어요.');
    }

    public function remove(Request $r, string $key): JsonResponse
    {
        if ($g = $this->guard($r)) {
            return $g;
        }
        $hit = $this->catalog->find($key, null, true);
        if (! $hit) {
            return self::fail('없는 항목이에요.', 404);
        }
        if ($r->boolean('hard')) {
            if ($hit[1]->status !== 'archived') {
                return self::fail('보관한 항목만 아주 지울 수 있어요.');
            }
            $this->photos->purge($hit[0], $key);
            $this->catalog->remove($hit[0], $key, true);

            return self::ok(null, '아주 지웠어요.');
        }
        $this->catalog->remove($hit[0], $key);

        return self::ok(null, '보관함으로 옮겼어요 — 「보관한 것」에서 되살릴 수 있어요.');
    }

    public function restore(Request $r, string $key): JsonResponse
    {
        if ($g = $this->guard($r)) {
            return $g;
        }
        $hit = $this->catalog->find($key, null, true);
        if (! $hit) {
            return self::fail('없는 항목이에요.', 404);
        }
        $this->catalog->restore($hit[0], $key);

        return self::ok(null, '되살렸어요.');
    }

    public function photos(Request $r, string $key): JsonResponse
    {
        if ($g = $this->guard($r)) {
            return $g;
        }
        $hit = $this->catalog->find($key, null, true);
        if (! $hit) {
            return self::fail('없는 항목이에요.', 404);
        }
        $added = 0;
        $errors = [];
        try {
            $url = trim((string) $r->input('url', ''));
            if ($url !== '') {
                $this->photos->addFromUrl($hit[0], $key, $url, mb_substr((string) $r->input('credit', ''), 0, 200));
                $added++;
            }
            $files = $r->file('photos', []);
            foreach (array_filter(is_array($files) ? $files : [$files]) as $f) {
                if (! $f->isValid() || $f->getSize() > 12_000_000) {
                    $errors[] = '너무 큰 사진은 건너뛰었어요 (12MB 까지).';

                    continue;
                }
                try {
                    $this->photos->add($hit[0], $key, (string) file_get_contents($f->getRealPath()));
                    $added++;
                } catch (\InvalidArgumentException $e) {
                    $errors[] = $e->getMessage();
                }
            }
        } catch (\InvalidArgumentException $e) {
            $errors[] = $e->getMessage();
        }
        if ($added === 0) {
            return self::fail($errors[0] ?? '사진을 골라 주세요.');
        }

        return self::ok(['photos' => $this->photos->of($hit[0], $key)], '사진 '.$added.'장을 넣었어요.'.($errors ? ' ('.$errors[0].')' : ''));
    }

    public function photoDelete(Request $r, int $id): JsonResponse
    {
        if ($g = $this->guard($r)) {
            return $g;
        }
        $this->photos->delete($id);

        return self::ok(null, '사진을 지웠어요.');
    }

    public function photoMain(Request $r, int $id): JsonResponse
    {
        if ($g = $this->guard($r)) {
            return $g;
        }
        $this->photos->main($id);

        return self::ok(null, '대표 사진으로 정했어요.');
    }

    /* ───────── 제조사 로고 ───────── */

    public function logos(Request $r): JsonResponse
    {
        return $this->guard($r) ?? self::ok(['items' => \Modules\Custom\Catalog\Support\Logos::adminList()]);
    }

    public function logoSave(Request $r): JsonResponse
    {
        if ($g = $this->guard($r)) {
            return $g;
        }
        $brand = trim((string) $r->input('brand', ''));
        $f = $r->file('logo');
        if ($brand === '' || ! $f || ! $f->isValid() || $f->getSize() > 6_000_000) {
            return self::fail('제조사와 로고 그림(6MB 까지)을 골라 주세요.');
        }
        try {
            \Modules\Custom\Catalog\Support\Logos::set($brand, (string) file_get_contents($f->getRealPath()));
        } catch (\InvalidArgumentException $e) {
            return self::fail($e->getMessage());
        }

        return self::ok(['items' => \Modules\Custom\Catalog\Support\Logos::adminList()], '「'.$brand.'」 로고를 바꿨어요 — 이 제조사의 모든 항목에 적용돼요.');
    }

    /** 제조사 홈페이지의 아이콘을 받아 로고로 */
    public function logoFetch(Request $r): JsonResponse
    {
        if ($g = $this->guard($r)) {
            return $g;
        }
        try {
            \Modules\Custom\Catalog\Support\Logos::fetch(trim((string) $r->input('brand', '')));
        } catch (\InvalidArgumentException $e) {
            return self::fail($e->getMessage());
        }

        return self::ok(['items' => \Modules\Custom\Catalog\Support\Logos::adminList()], '홈페이지에서 로고를 가져왔어요.');
    }

    public function logoDelete(Request $r): JsonResponse
    {
        if ($g = $this->guard($r)) {
            return $g;
        }
        \Modules\Custom\Catalog\Support\Logos::clear((string) $r->input('brand', ''));

        return self::ok(['items' => \Modules\Custom\Catalog\Support\Logos::adminList()], '올린 로고를 지웠어요.');
    }

    /* ───────── 설정 · 자동 수집 ───────── */

    private function collectState(): array
    {
        $st = Settings::state();
        $q = $this->collector->quiet();

        return ['quiet' => $q, 'load' => Collector::loadPercent(), 'last' => $st['last'] ? date('Y-m-d H:i', $st['last']) : '', 'today' => $st['day'] === date('Y-m-d') ? $st['count'] : 0,
            'log' => array_slice($st['log'], 0, 30), 'ai' => $this->ai->available(), 'pending' => $this->collector->suggestions('pending', 1)['pending']];
    }

    public function settings(Request $r): JsonResponse
    {
        return $this->guard($r) ?? self::ok(['settings' => Settings::forAdmin(), 'collect' => $this->collectState()]);
    }

    public function saveSettings(Request $r): JsonResponse
    {
        if ($g = $this->guard($r)) {
            return $g;
        }
        $in = (array) $r->input('settings', []);
        unset($in['logos']);   // 로고는 로고 올리기로만
        Settings::save($in);

        return self::ok(['settings' => Settings::forAdmin(), 'collect' => $this->collectState()], '저장했어요.');
    }

    public function collectRun(Request $r): JsonResponse
    {
        if ($g = $this->guard($r)) {
            return $g;
        }
        @set_time_limit(300);
        $task = in_array($r->input('task'), ['members', 'new', 'fill', 'photo'], true) ? (string) $r->input('task') : null;
        $res = $this->collector->tick(true, $task);

        return self::ok(['ran' => $res['ran'], 'collect' => $this->collectState()], $res['ran'] ? implode("\n", $res['ran']) : $res['skipped']);
    }

    public function suggestions(Request $r): JsonResponse
    {
        return $this->guard($r) ?? self::ok($this->collector->suggestions((string) $r->query('status', 'pending')));
    }

    public function suggestionApply(Request $r, int $id): JsonResponse
    {
        if ($g = $this->guard($r)) {
            return $g;
        }
        try {
            $res = $this->collector->apply($id);
        } catch (\InvalidArgumentException $e) {
            return self::fail($e->getMessage());
        }

        return self::ok($res, '반영했어요.');
    }

    public function suggestionReject(Request $r, int $id): JsonResponse
    {
        if ($g = $this->guard($r)) {
            return $g;
        }
        $this->collector->reject($id);

        return self::ok(null, '버렸어요.');
    }

    /* ───────── AI 서버 연결 (구인구직 AiController 와 같음) ───────── */

    private static function aiPayload(array $s): array
    {
        $s = AiSettings::normalize($s);

        return ['ai' => AiSettings::forAdmin($s), 'status' => AiSettings::available($s), 'jobs_available' => AiSettings::adAvailable()];
    }

    public function ai(Request $r): JsonResponse
    {
        return $this->guard($r) ?? self::ok(self::aiPayload(AiSettings::load()));
    }

    public function aiSave(Request $r): JsonResponse
    {
        if ($g = $this->guard($r)) {
            return $g;
        }
        try {
            $s = AiSettings::save((array) $r->input('ai', []));
            $a = AiSettings::available($s);

            return self::ok(self::aiPayload($s), $a['enabled'] ? 'AI 연결을 저장했어요.' : '저장했어요 — '.$a['reason']);
        } catch (AiConflict $e) {
            return self::fail($e->getMessage(), 409, self::aiPayload($e->current));
        }
    }

    public function aiImportJobs(Request $r): JsonResponse
    {
        if ($g = $this->guard($r)) {
            return $g;
        }
        try {
            $s = AiSettings::importFromAd();
        } catch (\RuntimeException $e) {
            return self::fail($e->getMessage());
        }

        return self::ok(self::aiPayload($s), '구인구직 AI 연결 설정을 가져왔어요.');
    }

    public function aiLive(Request $r): JsonResponse
    {
        if ($g = $this->guard($r)) {
            return $g;
        }
        $in = (array) $r->input('ai', []);
        unset($in['rev']);
        @set_time_limit(60);
        $s = AiSettings::merge($in, AiSettings::load());
        $order = AiSettings::order($s);
        if (! $order) {
            return self::ok(['ok' => false, 'message' => '❌ 물어볼 서버 · 모델이 없어요 (켜짐 · API 키 · 모델 확인).', 'tries' => []]);
        }
        $t0 = microtime(true);
        $tries = [];
        foreach ($order as $o) {
            $left = (int) floor(25 - (microtime(true) - $t0));
            if ($left < 3) {
                break;
            }
            $res = $this->ai->test($o['server'], $o['model'], $left);
            $tries[] = ['server' => $o['server']['name'], 'model' => $o['model'], 'ok' => $res['ok'], 'message' => $res['message'], 'sec' => $res['sec']];
            if ($res['ok']) {
                return self::ok(['ok' => true, 'message' => '✅ 「'.$o['server']['name'].' · '.$o['model'].'」 가 '.$res['sec'].'초 만에 답해요.', 'tries' => $tries]);
            }
        }

        return self::ok(['ok' => false, 'message' => '❌ 모든 서버 · 모델이 답하지 못했어요.', 'tries' => $tries]);
    }

    public function aiTest(Request $r): JsonResponse
    {
        if ($g = $this->guard($r)) {
            return $g;
        }
        $sv = is_array($r->input('server')) ? AiSettings::formServer((array) $r->input('server'), AiSettings::load()) : null;
        if (! $sv) {
            return self::fail('시험할 서버가 없어요.');
        }
        @set_time_limit(60);

        return self::ok($this->ai->test($sv, (string) $r->input('model', ''), AiSettings::timeoutFor($sv, AiSettings::load())));
    }

    public function aiModels(Request $r): JsonResponse
    {
        if ($g = $this->guard($r)) {
            return $g;
        }
        $sv = is_array($r->input('server')) ? AiSettings::formServer((array) $r->input('server'), AiSettings::load()) : null;
        if (! $sv) {
            return self::fail('서버가 없어요.');
        }

        return self::ok($this->ai->models($sv));
    }
}
