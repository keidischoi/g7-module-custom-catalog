<?php

/**
 * 0.3.13 🖥️ 3DS 관리 프로그램 연결 (OpsSource) — SQLite 메모리, 실제 서비스:
 *   G7_VENDOR=C:/Users/gbook/source/g7-core/vendor php tests/ops_source.php
 */

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Support\Facades\DB;
use Modules\Custom\Catalog\Listeners\OpsListener;
use Modules\Custom\Catalog\Services\PhotoService;
use Modules\Custom\Catalog\Support\OpsSource;
use Modules\Custom\Catalog\Support\Schema;
use Modules\Custom\Catalog\Support\Settings;

$vendor = getenv('G7_VENDOR') ?: 'C:/Users/gbook/source/g7-core/vendor';
if (! is_file($vendor.'/autoload.php')) {
    echo "SKIP — G7_VENDOR 가 없어 검사를 건너뜁니다\n";
    exit(0);
}
require $vendor.'/autoload.php';
if (! interface_exists(\App\Contracts\Extension\HookListenerInterface::class)) {
    eval('namespace App\Contracts\Extension; interface HookListenerInterface { public static function getSubscribedHooks(): array; public function handle(...$args): void; }');
}
spl_autoload_register(function (string $c): void {
    $p = 'Modules\\Custom\\Catalog\\';
    if (str_starts_with($c, $p) && is_file($f = dirname(__DIR__).'/src/'.str_replace('\\', '/', substr($c, strlen($p))).'.php')) {
        require $f;
    }
});
$app = new Illuminate\Container\Container();
Illuminate\Container\Container::setInstance($app);
$capsule = new Capsule($app);
$capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
$capsule->setAsGlobal();
$capsule->bootEloquent();
$app->instance('db', $capsule->getDatabaseManager());
$app->bind('db.schema', fn () => $capsule->getConnection()->getSchemaBuilder());
Illuminate\Support\Facades\Facade::setFacadeApplication($app);
$tmp = sys_get_temp_dir().'/cct-ops-'.getmypid();
@mkdir($tmp, 0777, true);
Settings::$dirOverride = $tmp;
PhotoService::$dirOverride = $tmp.'/img';

$fails = 0;
$count = 0;
function check(bool $ok, string $label): void
{
    global $fails, $count;
    $count++;
    if (! $ok) {
        $fails++;
        echo "FAIL: {$label}\n";
    }
}
function throws(callable $f): string
{
    try {
        $f();
    } catch (\Throwable $e) {
        return $e->getMessage() ?: get_class($e);
    }

    return '';
}

Schema::ensure();
$now = date('Y-m-d H:i:s');
DB::table(Schema::EQUIPMENT)->insert([
    ['key' => 'bambu-lab-a1-fdm', 'kind' => 'fdm', 'brand' => 'Bambu Lab', 'model' => 'A1', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now],
    ['key' => 'old-printer-fdm', 'kind' => 'fdm', 'brand' => 'Old', 'model' => 'Printer', 'status' => 'archived', 'created_at' => $now, 'updated_at' => $now],
    ['key' => 'elegoo-mars-sla', 'kind' => 'sla', 'brand' => 'Elegoo', 'model' => 'Mars', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now],
]);
DB::table(Schema::PARTS)->insert(['key' => 'hardened-nozzle', 'kind' => 'nozzle', 'brand' => 'Bambu Lab', 'name' => '경화 노즐', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
DB::table(Schema::FITS)->insert(['part_key' => 'hardened-nozzle', 'printer_key' => 'bambu-lab-a1-fdm', 'approved' => false, 'created_at' => $now, 'updated_at' => $now]);
DB::table(Schema::SUGGEST)->insert([
    ['task' => 'new', 'item_type' => 'equipment', 'item_key' => null, 'title' => 'Bambu Lab H2D', 'payload' => json_encode(['brand' => 'Bambu Lab', 'title' => 'H2D', 'kind' => 'fdm', 'values' => [], 'summary' => '큰 프린터']), 'source' => 'PC · qwen', 'status' => 'pending', 'created_at' => $now, 'updated_at' => $now],
    ['task' => 'new', 'item_type' => 'equipment', 'item_key' => null, 'title' => 'Fake X', 'payload' => json_encode(['brand' => 'Fake', 'title' => 'X', 'kind' => 'fdm', 'values' => []]), 'source' => '회원 #10', 'status' => 'pending', 'created_at' => $now, 'updated_at' => $now],
]);

$reg = (new OpsListener)->register([]);
check(isset($reg['catalog']) && $reg['catalog'] instanceof OpsSource, '필터에 catalog 등록');
$s = new OpsSource;
check(array_column($s->tabs(), 'key') === ['suggest', 'fits', 'items'], '탭 셋');
$items = $s->items();
$sg = array_values(array_filter($items, static fn ($i) => $i['group'] === 'suggest'));
$ft = array_values(array_filter($items, static fn ($i) => $i['group'] === 'fits'));
check(count($sg) === 2 && str_contains($sg[1]['detail'], '큰 프린터') && isset($sg[0]['actions']['apply'], $sg[0]['actions']['reject']), '제안 둘 · 반영 · 버리기');
check(count($ft) === 1 && $ft[0]['title'] === 'Bambu Lab 경화 노즐 → Bambu Lab A1' && ! empty($ft[0]['actions']['delete']['danger']), '부품 맞음 확인 하나');

$h2d = $sg[1]['id'];
$msg = $s->act($h2d, 'apply', 1);
check(str_contains($msg, '반영') && DB::table(Schema::EQUIPMENT)->where('brand', 'Bambu Lab')->where('model', 'H2D')->exists(), '제안 반영 → 새 항목 ('.$msg.')');
check(throws(static fn () => $s->act($h2d, 'apply', 1)) !== '', '이미 반영한 제안');
$s->act($sg[0]['id'], 'reject', 1);
check(DB::table(Schema::SUGGEST)->where('status', 'pending')->count() === 0, '버리기');
check(throws(static fn () => $s->act($sg[0]['id'], 'reject', 1)) !== '', '이미 버린 제안');

$s->act($ft[0]['id'], 'mod', 1, '어댑터 필요');
$fit = DB::table(Schema::FITS)->first();
check($fit->status === 'mod' && (bool) $fit->approved && $fit->note === '어댑터 필요', '손보면 맞음 + 메모');
check(count(array_filter($s->items(), static fn ($i) => $i['group'] === 'fits')) === 0, '정하면 할 일에서 빠짐');
check(throws(static fn () => $s->act('fit:99', 'fit', 1)) !== '' && throws(static fn () => $s->act('nope:1', 'fit', 1)) !== '', '없는 연결 · 항목');

$l = $s->list('items', []);
check($l['total'] === 2 && array_column(array_column($l['rows'], 'cells'), 2) === ['A1', 'H2D'], '처음 = FDM 프린터 (보관 · 레진 빠짐)');
check($s->list('items', ['filter' => 'resin_printer'])['total'] === 1 && $s->list('items', ['filter' => 'archived:fdm'])['total'] === 1, '레진 프린터 · 보관 거르기');
check($s->list('items', ['filter' => 'parts', 'q' => '노즐'])['total'] === 1, '부품 찾기');
$d = $s->detail('items', 'item:equipment:bambu-lab-a1-fdm');
check($d['url'] === '/catalog/bambu-lab-a1-fdm' && isset($d['actions']['archive']), '상세 · 보관 단추');
$s->listAct('items', 'item:equipment:bambu-lab-a1-fdm', 'archive', 1);
check(DB::table(Schema::EQUIPMENT)->where('key', 'bambu-lab-a1-fdm')->value('status') === 'archived', '보관');
check(throws(static fn () => $s->listAct('items', 'item:equipment:elegoo-mars-sla', 'purge', 1)) !== '', '보관 안 한 것은 아주 지우기 거절');
$s->listAct('items', 'item:equipment:bambu-lab-a1-fdm', 'restore', 1);
check(DB::table(Schema::EQUIPMENT)->where('key', 'bambu-lab-a1-fdm')->value('status') === 'active', '되살리기');
$d = $s->detail('items', 'item:equipment:old-printer-fdm');
check(isset($d['actions']['restore']) && ! empty($d['actions']['purge']['danger']), '보관한 것: 되살리기 · 아주 지우기');
$s->listAct('items', 'item:equipment:old-printer-fdm', 'purge', 1);
check(DB::table(Schema::EQUIPMENT)->where('key', 'old-printer-fdm')->doesntExist(), '아주 지우기');
check(throws(static fn () => $s->detail('items', 'item:equipment:zz')) !== '' && throws(static fn () => $s->list('nope', [])) !== '', '없는 항목 · 목록');

echo $fails === 0 ? "OK {$count}/{$count}\n" : "FAIL {$fails}/{$count}\n";
exit($fails === 0 ? 0 : 1);
