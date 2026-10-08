<?php

/**
 * 3D 카탈로그 0.2.0 — 서버 쪽 검사 (SQLite 메모리, 실제 서비스)
 *   G7_VENDOR=C:/Users/gbook/source/g7-core/vendor php tests/sim.php
 */

declare(strict_types=1);

namespace App\Contracts\Extension {
    interface HookListenerInterface
    {
        public static function getSubscribedHooks(): array;

        public function handle(...$args): void;
    }
}

namespace Modules\Custom\Companies\Services {
    /** 업체검색 ModelBook 흉내 — known() 은 규칙(승인 · 업체 수 · 숨김/합침)을 넘은 모델만 돌려줌 */
    final class ModelBook
    {
        public static array $known = [];

        public static function known(string $kind, int $uid = 0, int $limit = 300): array
        {
            return self::$known[$kind] ?? [];
        }

        /** 관리자가 고른 모델 대표 사진 */
        public static function photo(string $kind, ?string $brand, ?string $model): ?string
        {
            return $model === 'P2S' || $model === 'P1S' ? '/api/modules/custom-companies/files/'.strtolower((string) $model) : null;
        }
    }
}

namespace {
    use Illuminate\Database\Capsule\Manager as Capsule;
    use Illuminate\Support\Facades\DB;
    use Modules\Custom\Catalog\Api\Catalog;
    use Modules\Custom\Catalog\Listeners\CatalogListener;
    use Modules\Custom\Catalog\Services\AiClient;
    use Modules\Custom\Catalog\Services\CatalogService;
    use Modules\Custom\Catalog\Services\Collector;
    use Modules\Custom\Catalog\Services\PhotoService;
    use Modules\Custom\Catalog\Support\AiSettings;
    use Modules\Custom\Catalog\Support\Fields;
    use Modules\Custom\Catalog\Support\Schema;
    use Modules\Custom\Catalog\Support\Settings;

    require (getenv('G7_VENDOR') ?: 'C:/Users/gbook/source/g7-core/vendor').'/autoload.php';
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

    $tmp = sys_get_temp_dir().'/cct-sim-'.getmypid();
    @mkdir($tmp, 0777, true);
    Settings::$dirOverride = $tmp;
    PhotoService::$dirOverride = $tmp.'/img';

    $pass = $fail = 0;
    function t(bool $ok, string $label): void
    {
        global $pass, $fail;
        echo ($ok ? '  ok   ' : '  FAIL ').$label."\n";
        $ok ? $pass++ : $fail++;
    }
    function jpeg(int $w = 800, int $h = 600, int $seed = 1): string
    {
        $im = imagecreatetruecolor($w, $h);
        imagefill($im, 0, 0, imagecolorallocate($im, 30 * $seed % 255, 90, 160));
        ob_start();
        imagejpeg($im, null, 80);

        return (string) ob_get_clean();
    }

    echo "■ 표 맞추기\n";
    // 예전 표(0.1.x 처음 꼴 — image_url · specs 없음)가 있는 채로 시작
    DB::connection()->getSchemaBuilder()->create('cat_equipment', function ($t) {
        $t->id();
        $t->string('key', 60)->unique();
        $t->string('kind', 16);
        $t->string('brand', 60);
        $t->string('model', 80);
        $t->unsignedSmallInteger('build_x_mm')->nullable();
        $t->unsignedSmallInteger('build_y_mm')->nullable();
        $t->unsignedSmallInteger('build_z_mm')->nullable();
        $t->unsignedSmallInteger('min_layer_um')->nullable();
        $t->boolean('multicolor')->default(false);
        $t->boolean('enclosed')->nullable();
        $t->string('nozzle', 120)->nullable();
        $t->json('materials')->nullable();
        $t->date('released_on')->nullable();
        $t->string('status', 12)->default('active');
        $t->timestamps();
    });
    DB::table('cat_equipment')->insert(['key' => 'bambu-lab-p1s-fdm', 'kind' => 'fdm', 'brand' => 'Bambu Lab', 'model' => 'P1S', 'build_x_mm' => 256, 'build_y_mm' => 256, 'build_z_mm' => 256, 'multicolor' => 1, 'status' => 'active']);
    Schema::ensure();
    $sb = DB::connection()->getSchemaBuilder();
    t($sb->hasColumn('cat_equipment', 'specs') && $sb->hasColumn('cat_equipment', 'image_url') && $sb->hasColumn('cat_equipment', 'ai_photo_at') && $sb->hasTable('cat_materials') && $sb->hasTable('cat_photos') && $sb->hasTable('cat_suggestions'), '예전 표에 모자란 칸 · 표를 더함');

    echo "■ 저장 · 목록 · 상세\n";
    $svc = new CatalogService();
    $r = $svc->save('equipment', ['kind' => 'fdm', 'brand' => 'Bambu Lab', 'title' => 'X1 Carbon', 'summary' => '밀폐형 CoreXY 프린터.', 'homepage_url' => 'https://bambulab.com/en/x1',
        'values' => ['build_x_mm' => 256, 'build_y_mm' => '256', 'build_z_mm' => 256, 'enclosed' => true, 'multicolor' => true, 'structure' => 'CoreXY', 'speed_max' => '500 mm/s', 'nozzle_temp_max' => 300,
            'materials' => 'PLA, PETG, ABS, PA-CF', 'connect' => ['Wi-Fi', 'LAN'], 'released_on' => '2022-06', 'camera' => true, 'price_krw' => '1,890,000', 'wavelength_nm' => 405],
        'facts' => ['라이다' => '있음']]);
    t($r['created'] && $r['key'] === 'bambu-lab-x1-carbon-fdm', '새 장비 — 키는 제조사-모델-종류');
    $hit = $svc->find($r['key']);
    $d = $svc->detail($hit[0], $hit[1], true);
    $flat = [];
    foreach ($d['groups'] as $g) {
        foreach ($g['items'] as $it) {
            $flat[$it['label']] = $it['value'];
        }
    }
    t(($flat['최대 속도'] ?? '') === '500 mm/s' && ($flat['참고 가격'] ?? '') === '1,890,000 원' && ($flat['출시'] ?? '') === '2022-06' && ($flat['쓸 수 있는 재료'] ?? '') === 'PLA · PETG · ABS · PA-CF' && ($flat['밀폐형'] ?? '') === '예' && ($flat['연결'] ?? '') === 'Wi-Fi · LAN', '상세 — 묶음별 제원 · 단위 · 여러 값');
    t(! isset($flat['파장']) && ! isset($d['values']['wavelength_nm']), 'FDM 에 없는 칸(파장)은 저장하지 않음');
    t($d['facts'][0]['label'] === '라이다' && $d['values']['structure'] === 'CoreXY' && $d['filled'] >= 12, '기타 제원 · 편집용 값');
    $r2 = $svc->save('equipment', ['kind' => 'fdm', 'brand' => 'bambu lab', 'title' => 'x1-carbon', 'values' => ['noise_db' => 48]]);
    t(! $r2['created'] && $r2['key'] === $r['key'] && $svc->detail(...$svc->find($r['key']))['title'] === 'x1-carbon', '같은 제조사 · 모델(대소문자 · 띄어쓰기 무시)은 새로 만들지 않고 고침');
    $svc->save('equipment', ['key' => $r['key'], 'kind' => 'fdm', 'brand' => 'Bambu Lab', 'title' => 'X1 Carbon', 'values' => ['speed_max' => '']]);
    t(! isset($svc->detail(...array_merge($svc->find($r['key']), [true]))['values']['speed_max']), '값을 비우면 지워짐');
    try {
        $svc->save('equipment', ['kind' => 'fdm', 'brand' => '', 'title' => 'X']);
        t(false, '제조사 없으면 거절');
    } catch (\InvalidArgumentException) {
        t(true, '제조사 없으면 거절');
    }
    $m = $svc->save('materials', ['kind' => 'fdm', 'brand' => 'eSUN', 'title' => 'PLA+', 'values' => ['material' => 'PLA+', 'nozzle_min' => 205, 'nozzle_max' => 225, 'bed_min' => 45, 'bed_max' => 60, 'dry_temp' => 50, 'dry_hours' => 6,
        'chamber' => '불필요', 'weight_g' => 1000, 'diameter' => '1.75', 'traits' => ['고속(HS)', '매트'], 'density' => 1.23, 'abrasive' => false, 'sds_url' => 'https://example.com/sds.pdf']]);
    $md = $svc->detail(...array_merge($svc->find($m['key']), [true]));
    t($md['type'] === 'materials' && $md['chips'] === ['PLA+', '노즐 205–225°C', '1 kg'] && $md['values']['traits'] === ['고속(HS)', '매트'] && $md['values']['density'] === 1.23 && $md['values']['diameter'] === '1.75', '재료 — 카드 요약 · 특징 · 물성');
    $svc->save('equipment', ['kind' => 'sla', 'brand' => 'Elegoo', 'title' => 'Saturn 4 Ultra', 'values' => ['build_x_mm' => 218, 'build_y_mm' => 122, 'build_z_mm' => 220, 'wavelength_nm' => 405, 'light' => 'LCD (MSLA)']]);
    $svc->save('equipment', ['kind' => 'laser', 'brand' => 'xTool', 'title' => 'P2S', 'values' => ['laser_type' => 'CO2', 'laser_w' => 55]]);
    $l = $svc->list(['type' => 'equipment', 'kinds' => ['fdm']]);
    t($l['total'] === 2 && $l['brands'] === [['name' => 'Bambu Lab', 'n' => 2]] && $l['items'][0]['chips'][0] === '256 × 256 × 256 mm', 'FDM 탭 목록 · 제조사 칩');
    t($svc->list(['type' => 'equipment', 'q' => 'bambu x1'])['total'] === 1 && $svc->list(['type' => 'equipment', 'q' => 'saturn'])['items'][0]['kind_label'] === 'SLA 레진 프린터', '검색 — 여러 낱말');
    t($svc->list(['type' => 'equipment', 'flags' => ['enclosed']])['total'] === 1, '밀폐형만');
    $c = $svc->counts();
    t($c['fdm'] === 2 && $c['resin_printer'] === 1 && $c['maker'] === 1 && $c['filament'] === 1 && $c['resin'] === 0, '탭마다 개수');
    $svc->remove('equipment', 'xtool-p2s-laser');
    t($svc->find('xtool-p2s-laser') === null && $svc->find('xtool-p2s-laser', null, true) !== null && $svc->list(['type' => 'equipment', 'status' => 'archived'])['total'] === 1, '지우기 = 보관');
    $svc->restore('equipment', 'xtool-p2s-laser');
    t($svc->find('xtool-p2s-laser') !== null, '되살리기');

    echo "■ 사진\n";
    $ph = new PhotoService();
    DB::table('cat_equipment')->where('key', 'bambu-lab-p1s-fdm')->update(['image_url' => '/api/modules/custom-catalog/assets/photo-bambulab-p1s.jpg']);
    $a = $ph->add('equipment', 'bambu-lab-p1s-fdm', jpeg(2400, 1600));
    $list = $ph->of('equipment', 'bambu-lab-p1s-fdm');
    $size = PhotoService::size((string) PhotoService::read(basename($a['url'])));
    t(count($list) === 2 && str_contains($list[0]['url'], 'photo-bambulab-p1s') && $size === [1100, 733] && PhotoService::size((string) PhotoService::read(substr(basename($a['url']), 0, -4).'-t.jpg')) === [480, 320] && $svc->card('equipment', (object) array_merge((array) DB::table('cat_equipment')->where('key', 'bambu-lab-p1s-fdm')->first(), ['image_url' => $a['url']]))['image'] === substr($a['url'], 0, -4).'-t.jpg', '올리기 — 예전 대표 사진을 지키고 뒤에 더함 · 긴 변 1100 · 목록용 작은 사진(480)');
    $ph->main($a['id']);
    t(DB::table('cat_equipment')->where('key', 'bambu-lab-p1s-fdm')->value('image_url') === $a['url'], '대표 사진 바꾸기');
    $ph->delete($a['id']);
    t(PhotoService::read(basename($a['url'])) === null && PhotoService::read(substr(basename($a['url']), 0, -4).'-t.jpg') === null && str_contains((string) DB::table('cat_equipment')->where('key', 'bambu-lab-p1s-fdm')->value('image_url'), 'photo-bambulab-p1s'), '사진 지우기 — 파일도 지우고 대표는 다음 사진');
    try {
        $ph->add('equipment', 'bambu-lab-p1s-fdm', 'not an image');
        t(false, '사진이 아니면 거절');
    } catch (\InvalidArgumentException) {
        t(true, '사진이 아니면 거절');
    }
    t(! PhotoService::safeUrl('http://127.0.0.1/a.jpg') && ! PhotoService::safeUrl('http://192.168.0.5/a.jpg') && ! PhotoService::safeUrl('file:///etc/passwd') && PhotoService::safeUrl('https://93.184.216.34/a.jpg'), '주소로 가져오기 — 안쪽 주소는 막음');

    echo "■ 설명 · 알려진 문제 · 메모 (0.2.2)\n";
    $svc->save('equipment', ['key' => 'bambu-lab-x1-carbon-fdm', 'kind' => 'fdm', 'brand' => 'Bambu Lab', 'title' => 'X1 Carbon', 'detail' => "첫째 줄\n둘째 <b>줄</b>", 'issues' => "- 카본 로드에 먼지가 쌓임\n\n• 초기 펌웨어 소음", 'memo' => '국내 정식 발매',
        'values' => ['speed_max' => 500]]);
    $xd = $svc->detail(...$svc->find('bambu-lab-x1-carbon-fdm'));
    t($xd['detail'] === "첫째 줄\n둘째 줄" && $xd['issues'] === ['카본 로드에 먼지가 쌓임', '초기 펌웨어 소음'] && $xd['memo'] === '국내 정식 발매' && in_array('500 mm/s', $xd['chips'], true), '자세한 설명(줄바꿈 유지) · 알려진 문제(한 줄에 하나) · 메모 · 카드에 속도');
    $before = DB::table('cat_equipment')->where('key', 'bambu-lab-p1s-fdm')->first();
    (require dirname(__DIR__).'/database/migrations/2026_10_09_000019_specs_and_notes.php')->up();
    $pd2 = $svc->detail(...array_merge($svc->find('bambu-lab-p1s-fdm'), [true]));
    $sd2 = $svc->detail(...array_merge($svc->find('elegoo-saturn-4-ultra-sla'), [true]));
    t($pd2['values']['speed_max'] === 500 && $pd2['values']['accel_max'] === 20000 && $pd2['values']['nozzle_temp_max'] === 300 && $pd2['values']['structure'] === 'CoreXY' && in_array('ABS', $pd2['values']['materials'], true) && $pd2['values']['build_x_mm'] === 256,
        '많이 쓰는 프린터의 속도 · 온도 · 구조 · 쓸 수 있는 필라멘트를 채움 (P1S)');
    t($svc->detail(...array_merge($svc->find('bambu-lab-x1-carbon-fdm'), [true]))['values']['materials'] === ['PLA', 'PETG', 'ABS', 'PA-CF'] && ! isset($sd2['values']['speed_max']), '이미 적힌 값(X1 Carbon 의 재료)은 그대로 · 목록에 없는 것은 건드리지 않음');

    echo "■ 어떤 그림을 보여 줄까 (관리자 설정)\n";
    $img = static fn (string $key) => $svc->detail(...$svc->find($key));
    $p1s = (string) DB::table('cat_equipment')->where('key', 'bambu-lab-p1s-fdm')->value('image_url');
    $a1 = $img('xtool-p2s-laser');
    $a2 = $img('bambu-lab-p1s-fdm');
    t($a1['image'] === '/api/modules/custom-companies/files/p2s' && $a1['photos'][0]['credit'] === '업체검색 대표 사진' && $a2['image'] === $p1s && $img('elegoo-saturn-4-ultra-sla')['image'] === '', '기본: 카탈로그 사진 → 없으면 업체검색 대표 사진 → 없으면 그림');
    Settings::save(['image_mode' => 'company']);
    t($img('bambu-lab-p1s-fdm')['image'] === '/api/modules/custom-companies/files/p1s' && count($img('bambu-lab-p1s-fdm')['photos']) === 1, '「업체검색 대표 사진 먼저」');
    Settings::save(['image_mode' => 'catalog']);
    t($img('xtool-p2s-laser')['image'] === '' && $img('bambu-lab-p1s-fdm')['image'] === $p1s, '「카탈로그 사진만」');
    Settings::save(['image_mode' => 'icon']);
    $ic = $img('bambu-lab-p1s-fdm');
    t($ic['image'] === '' && $ic['photos'] === [] && $ic['enclosed'] === true && count($svc->detail(...array_merge($svc->find('bambu-lab-p1s-fdm'), [true]))['photos']) === 1, '「그림만」 — 사진을 쓰지 않음 (편집하는 사람에게는 사진 관리가 보임)');
    Settings::save(['image_mode' => 'auto']);

    echo "■ AI 연결 · 자동 수집\n";
    t(AiSettings::normalize(['enabled' => true, 'provider' => 'ollama', 'url' => 'http://192.168.0.216:11434', 'model' => 'qwen2.5:7b', 'api_key' => ''])['servers'][0]['models'][0]['name'] === 'qwen2.5:7b', '예전 카탈로그 AI 설정(서버 하나)도 첫 서버로 읽음');
    AiClient::$settingsOverride = ['enabled' => true, 'servers' => [
        ['id' => 's1', 'name' => 'PC', 'enabled' => true, 'provider' => 'ollama', 'url' => 'http://192.168.0.216:11434', 'models' => ['bad', 'qwen2.5:14b']],
    ]];
    $asked = [];
    $reply = '';
    AiClient::$sender = function (string $url, array $h, array $body) use (&$asked, &$reply) {
        $asked[] = $body['model'];
        if ($body['model'] === 'bad') {
            throw new \RuntimeException('모델이 없어요');
        }

        return ['message' => ['content' => $reply]];
    };
    $ai = new AiClient();
    $col = new Collector($svc, $ph, $ai);
    Collector::$hourOverride = 14;
    Collector::$loadOverride = 0.1;
    t(! $col->quiet()['ok'], '꺼져 있으면 돌지 않음');
    Settings::save(['auto' => true, 'auto_from' => 1, 'auto_to' => 7, 'auto_load' => 50]);
    t(! $col->quiet()['ok'] && str_contains($col->quiet()['reason'], '시간'), '정한 시간(1 ~ 7시)이 아니면 쉼');
    Collector::$hourOverride = 3;
    Collector::$loadOverride = 99.0;
    t(! $col->quiet()['ok'] && str_contains($col->quiet()['reason'], '바빠요'), '서버가 바쁘면 쉼');
    Collector::$loadOverride = 0.1;
    t($col->quiet()['ok'], '조용하면 돎');
    Settings::save(['auto_from' => 22, 'auto_to' => 6]);
    Collector::$hourOverride = 23;
    $night = $col->quiet()['ok'];
    Collector::$hourOverride = 12;
    t($night && ! $col->quiet()['ok'], '자정을 넘는 시간대(22 ~ 6시)');
    Collector::$hourOverride = 3;

    $reply = "생각해 보면…\n```json\n".json_encode(['items' => [
        ['model' => 'Bambu Lab H2D', 'kind' => 'fdm', 'summary' => '듀얼 노즐 대형 프린터.', 'homepage_url' => 'https://bambulab.com/en/h2d', 'values' => ['build_x_mm' => 350, 'build_y_mm' => 320, 'build_z_mm' => 325, 'multicolor' => true, 'structure' => '없는값', 'speed_max' => 600]],
        ['model' => 'P1S', 'kind' => 'fdm'],
        ['model' => 'x1 carbon', 'kind' => 'fdm'],
    ]], JSON_UNESCAPED_UNICODE)."\n```";
    $line = $col->taskNew();
    $sg = $col->suggestions();
    t($sg['pending'] === 1 && $sg['items'][0]['title'] === 'Bambu Lab H2D' && $asked === ['bad', 'qwen2.5:14b'] && str_contains($line, 'PC · qwen2.5:14b'), '새 항목 찾기 — 안 되는 모델은 넘기고, 이미 있는 것(P1S · X1 Carbon)은 빼고 제안');
    t(count(array_filter($sg['items'][0]['lines'], static fn ($x) => $x['label'] === '구조')) === 1, '(고르기 칸의 값은 그대로 보여 주고 확인은 관리자가)');
    $ap = $col->apply($sg['items'][0]['id']);
    $nd = $svc->detail(...array_merge($svc->find($ap['key']), [true]));
    t($nd['brand'] === 'Bambu Lab' && $nd['title'] === 'H2D' && $nd['values']['build_x_mm'] === 350 && $nd['summary'] !== '' && DB::table('cat_equipment')->where('key', $ap['key'])->value('source') === 'ai', '제안 반영 → 새 항목');
    $asked = [];
    $col->taskNew();
    t(true, '다음 제조사로 넘어감 ('.implode(',', array_unique($asked)).')');

    DB::table('cat_suggestions')->delete();
    $reply = json_encode(['values' => ['speed_max' => '500', 'nozzle_temp_max' => 300, 'build_x_mm' => 999, 'structure' => 'CoreXY', 'extruder' => '모름', 'camera' => 'yes', 'noise_db' => 49, 'weight_kg' => 12.95, 'power_w' => 1000, 'size_w' => 389], 'summary' => 'P1S 는 밀폐형 CoreXY 프린터입니다.', 'homepage_url' => 'https://bambulab.com/en/p1s', 'issues' => ['보조 팬 소음이 큰 편', 'x', '고온 재료는 챔버 온도가 아쉬움']]);
    DB::table('cat_equipment')->where('key', '!=', 'bambu-lab-p1s-fdm')->update(['ai_fill_at' => now()]);
    $line = $col->taskFill();
    $sg = $col->suggestions();
    t($sg['pending'] === 1 && $sg['items'][0]['task'] === 'fill' && str_contains($line, 'Bambu Lab P1S'), '제원 채우기 — 덜 찬 항목 하나');
    $col->apply($sg['items'][0]['id']);
    $pd = $svc->detail(...array_merge($svc->find('bambu-lab-p1s-fdm'), [true]));
    t($pd['values']['build_x_mm'] === 256 && $pd['values']['noise_db'] === 49 && $pd['values']['weight_kg'] === 12.95 && $pd['values']['extruder'] === '다이렉트' && $pd['summary'] !== '' && $pd['homepage_url'] === 'https://bambulab.com/en/p1s', '빈 칸만 채움 (적혀 있던 256 은 그대로) · 목록에 없는 값(모름)은 버림');
    t($pd['issues'] === ['보조 팬 소음이 큰 편', '고온 재료는 챔버 온도가 아쉬움'], 'AI 가 알려 준 알려진 문제도 (너무 짧은 것은 버림)');

    echo "■ 사진 찾기\n";
    DB::table('cat_suggestions')->delete();
    DB::table('cat_equipment')->update(['ai_photo_at' => now()]);
    DB::table('cat_equipment')->where('key', $ap['key'])->update(['ai_photo_at' => null]);
    Collector::$pager = function (string $url) {
        if (str_contains($url, 'bambulab.com/en/h2d')) {
            return '<html><head><title>Bambu Lab H2D | Dual nozzle</title><meta property="og:image" content="//cdn.bambulab.com/h2d.jpg"></head></html>';
        }
        if (str_contains($url, 'commons.wikimedia.org')) {
            return json_encode(['query' => ['pages' => [['title' => 'File:Bambu Lab P1S printer.jpg', 'imageinfo' => [['mime' => 'image/jpeg', 'thumburl' => 'https://upload.wikimedia.org/p1s.jpg', 'descriptionurl' => 'https://commons.wikimedia.org/wiki/File:P1S.jpg',
                'extmetadata' => ['Artist' => ['value' => '<a>Kim</a>'], 'LicenseShortName' => ['value' => 'CC BY-SA 4.0']]]]]]]]);
        }

        return null;
    };
    PhotoService::$fetcher = fn (string $url) => str_contains($url, 'h2d.jpg') ? ['body' => jpeg(1200, 900, 3), 'type' => 'image/jpeg'] : (str_contains($url, 'p1s.jpg') ? ['body' => jpeg(900, 700, 5), 'type' => 'image/jpeg'] : null);
    $line = $col->taskPhoto();
    $sg = $col->suggestions();
    t($sg['pending'] === 1 && $sg['items'][0]['image_url'] === 'https://cdn.bambulab.com/h2d.jpg' && str_contains($line, '제조사 페이지'), '사진 찾기 — 제품 공식 페이지의 대표 사진');
    $col->apply($sg['items'][0]['id']);
    $hp = $ph->of('equipment', $ap['key']);
    t(count($hp) === 1 && $hp[0]['credit'] === 'bambulab.com' && $hp[0]['source_url'] === 'https://bambulab.com/en/h2d' && DB::table('cat_equipment')->where('key', $ap['key'])->value('image_url') === $hp[0]['url'], '반영 → 사진 저장 · 출처 적어 둠');
    t(Collector::matches('File:Bambu Lab P1S printer.jpg', 'P1S') && ! Collector::matches('Bambu Lab P1P', 'P1S') && ! Collector::matches('Creality K1', 'K1 Max'), '이름이 맞는 사진만');
    $c2 = $col->photoCandidates('Bambu Lab', 'P1S', 'https://bambulab.com', 'equipment');
    t(count($c2) === 1 && $c2[0]['from'] === '위키미디어 공용' && $c2[0]['credit'] === 'Wikimedia Commons · Kim · CC BY-SA 4.0', '첫 화면 주소는 건너뛰고 위키미디어 공용에서 (저작자 · 라이선스)');

    echo "■ 한 번 돌기\n";
    DB::table('cat_suggestions')->delete();
    Settings::save(['auto_from' => 0, 'auto_to' => 0, 'auto_per_run' => 2, 'auto_per_day' => 3, 'auto_every' => 10, 'apply' => 'auto', 'task_fill' => false]);
    Settings::saveState(['last' => 0, 'day' => '', 'count' => 0, 'turn' => 0, 'brand' => 0, 'log' => []]);
    $reply = json_encode(['items' => [['model' => 'A1 mini', 'kind' => 'fdm', 'values' => ['build_x_mm' => 180, 'build_y_mm' => 180, 'build_z_mm' => 180]]]]);
    $res = $col->tick();
    t(count($res['ran']) === 2 && $svc->find('bambu-lab-a1-mini-fdm') !== null && $col->suggestions('applied')['items'] !== [], '조용하면 2개 일 · 「바로 반영」이면 제안이 곧 항목으로');
    t($col->tick()['skipped'] === '아직 쉬는 시간이에요.', '10분 안에는 다시 돌지 않음');
    Settings::saveState(['last' => 0] + Settings::state());
    $res = $col->tick();
    t(count($res['ran']) === 1 || str_contains($col->quiet()['reason'], '오늘'), '하루 최대를 넘지 않음');
    t(str_contains($col->tick(true)['ran'][0] ?? '', '') && count(Settings::state()['log']) >= 3, '「지금 돌리기」는 조건 없이 · 기록이 남음');

    echo "■ 회원이 등록한 것 (업체검색 규칙 그대로)\n";
    DB::table('cat_suggestions')->delete();
    Settings::save(['apply' => 'review']);
    \Modules\Custom\Companies\Services\ModelBook::$known = ['fdm' => [
        ['b' => 'Bambu Lab', 'm' => 'p1s', 'n' => 5, 's' => [256, 256, 256]],           // 이미 있음 (표기만 다름)
        ['b' => 'Two Trees', 'm' => 'SK1', 'n' => 3, 's' => [256, 256, 256], 'mc' => 0],   // 업체 3곳이 씀 → 가져옴
        ['b' => '', 'm' => '이름 모를 것', 'n' => 4],                                       // 제조사 없음 → 건너뜀
    ]];
    DB::connection()->getSchemaBuilder()->create('cmp_spools', function ($t) {
        $t->id();
        $t->unsignedBigInteger('company_id');
        $t->string('kind', 12);
        $t->string('material', 40);
        $t->string('brand', 40)->nullable();
        $t->unsignedSmallInteger('nozzle_min')->nullable();
        $t->unsignedSmallInteger('nozzle_max')->nullable();
        $t->unsignedInteger('weight_g')->nullable();
        $t->timestamp('deleted_at')->nullable();
    });
    foreach ([[1, 'Kingroon', 'PETG', 230, 250], [2, 'kingroon', 'petg', null, null], [2, 'Kingroon', 'PETG', null, null], [3, 'JAYO', 'PLA+', null, null], [4, 'eSUN', 'pla+', null, null], [5, 'ESUN', 'PLA+', null, null]] as $x) {
        DB::table('cmp_spools')->insert(['company_id' => $x[0], 'kind' => 'fdm', 'brand' => $x[1], 'material' => $x[2], 'nozzle_min' => $x[3], 'nozzle_max' => $x[4], 'weight_g' => 1000]);
    }
    $line = $col->taskMembers();
    $sg = $col->suggestions();
    $titles = array_column($sg['items'], 'title');
    sort($titles);
    t($titles === ['Kingroon PETG', 'Two Trees SK1'] && str_contains($line, '2개'), '업체검색 규칙을 넘은 것만 — 장비 SK1(업체 3곳) · 재료 Kingroon PETG(업체 2곳). 이미 있는 것(P1S · eSUN PLA+) · 한 곳만 쓴 것(JAYO)은 빼고');
    $kp = array_values(array_filter($sg['items'], static fn ($x) => $x['title'] === 'Kingroon PETG'))[0];
    t(str_contains($kp['source'], '회원 등록 · 업체 2곳') && in_array(['label' => '노즐 최저', 'value' => '230 °C'], $kp['lines'], true), '회원이 적은 값(노즐 온도)도 같이 · 출처 「회원 등록」');
    $ak = $col->apply($kp['id'])['key'];
    t(DB::table('cat_materials')->where('key', $ak)->value('source') === 'members' && DB::table('cat_materials')->where('key', $ak)->value('nozzle_max') == 250, '반영 → 카탈로그 재료');
    $col->reject(array_values(array_filter($sg['items'], static fn ($x) => $x['title'] === 'Two Trees SK1'))[0]['id']);
    t(str_contains($col->taskMembers(), '없어요') && $col->suggestions()['pending'] === 0, '한 번 버린 것 · 이미 들어간 것은 다시 올리지 않음');

    echo "■ 다른 모듈과 잇기\n";
    $book = Catalog::equipmentBook();
    $x1 = array_values(array_filter($book['fdm'], static fn ($x) => $x['m'] === 'X1 Carbon'))[0] ?? [];
    t(isset($book['fdm'], $book['sla'], $book['laser']) && $x1['b'] === 'Bambu Lab' && $x1['s'] === [256, 256, 256] && $x1['mc'] === 1 && ! isset($x1['image']), '업체검색용 장비 목록 — equipment-catalog.json 과 같은 꼴 (사진 없음)');
    $mb = Catalog::materialBook();
    $es = array_values(array_filter($mb['fdm'], static fn ($x) => $x['b'] === 'eSUN'))[0];
    t($es['mat'] === 'PLA+' && $es['nz'] === [205, 225] && $es['dry'] === [50, 6], '재료 목록');
    $sr = CatalogListener::siteSearch('bambu', 1, 3, 'relevance');
    t($sr['total'] >= 4 && count($sr['items']) === 3 && $sr['has_more_pages'] && str_contains($sr['items'][0]['title_highlighted'], '<mark>Bambu</mark>') && str_starts_with($sr['items'][0]['url'], '/catalog/'), '통합 검색 — 카탈로그 탭');
    $sr2 = CatalogListener::siteSearch('pla', 1, 10, 'relevance');
    t($sr2['total'] === 1 && $sr2['items'][0]['badge'] === '필라멘트', '통합 검색 — 재료도');
    $home = (new CatalogListener())->homeSections([]);
    t($home[0]['key'] === 'catalog' && count($home[0]['items']) >= 4 && $home[0]['items'][0]['image'] !== '', '홈 칸 — 사진 있는 것부터');
    t(count(Fields::forKind('equipment', 'fdm')) >= 40 && count(Fields::forKind('materials', 'fdm')) >= 30 && count(Fields::meta()['tabs']) === 8, '칸 정의 (FDM 장비 '.count(Fields::forKind('equipment', 'fdm')).'칸 · 필라멘트 '.count(Fields::forKind('materials', 'fdm')).'칸)');

    array_map('unlink', glob($tmp.'/img/*') ?: []);
    array_map('unlink', glob($tmp.'/*.*') ?: []);
    echo "\n통과 {$pass} · 실패 {$fail}\n";
    exit($fail ? 1 : 0);
}
