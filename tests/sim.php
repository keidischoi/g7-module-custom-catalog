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
        ['model' => 'Bambu Lab H2D', 'kind' => 'fdm', 'summary' => '듀얼 노즐 대형 프린터.', 'homepage_url' => 'https://bambulab.com/en/h2d', 'values' => ['build_x_mm' => 350, 'build_y_mm' => 320, 'build_z_mm' => 325, 'multicolor' => true, 'structure' => '없는값', 'speed_max' => 600],
            'sources' => ['https://bambulab.com/en/h2d', 'https://made.up/h2d-specs', 'ftp://x']],
        ['model' => 'P1S', 'kind' => 'fdm'],
        ['model' => 'x1 carbon', 'kind' => 'fdm'],
    ]], JSON_UNESCAPED_UNICODE)."\n```";
    // 0.2.11 기본은 이름 · 종류만 (AI 기억 제원은 틀린 것이 많음) · 출처는 열어 보고 남김
    Collector::$pager = fn (string $u) => str_contains($u, 'bambulab.com/en/h2d') ? '<html>H2D</html>' : null;
    $st0 = Settings::state();
    $col->taskNew();
    $s0 = $col->suggestions()['items'][0] ?? [];
    t(($s0['title'] ?? '') === 'Bambu Lab H2D' && $s0['lines'] === [] && $s0['summary'] === '' && $s0['draft']['values'] === [] && count($s0['sources']) === 2 && $s0['sources'][0]['ok'] && ! $s0['sources'][1]['ok'],
        '새 항목 제안: 기본은 이름 · 종류만 · 출처(✅ 열림 · ⚠️ 안 열림)는 남김');
    DB::table('cat_suggestions')->delete();
    Settings::saveState($st0);
    Settings::save(['new_values' => true]);
    $asked = [];
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

    echo "■ 안전 자료 (MSDS) 찾기 (0.2.8)\n";
    DB::table('cat_suggestions')->delete();
    $mk = $svc->save('materials', ['kind' => 'fdm', 'brand' => 'Polymaker', 'title' => 'PolyLite PETG', 'homepage_url' => 'https://polymaker.com/product/polylite-petg/', 'values' => ['material' => 'PETG']])['key'];
    $mk2 = $svc->save('materials', ['kind' => 'resin', 'brand' => 'Elegoo', 'title' => 'Standard Resin 2.0', 'values' => ['material' => 'RESIN']])['key'];
    DB::table('cat_materials')->whereNotIn('key', [$mk, $mk2])->update(['ai_sds_at' => now()]);
    DB::table('cat_materials')->where('key', $mk2)->update(['ai_sds_at' => now()->subDays(40)]);
    Collector::$pager = function (string $url) {
        return match (true) {
            str_contains($url, 'polymaker.com/product/') => '<html><body><a href="/downloads/brochure.pdf">Brochure</a> <a href="mailto:sds@polymaker.com">SDS by mail</a> <a href="/wp-content/uploads/PolyLite-PETG.pdf"> Safety Data Sheet </a></body></html>',
            str_contains($url, 'PolyLite-PETG.pdf') => '%PDF-1.7 ...',
            str_contains($url, 'elegoo.com/pages/sds') => '<html><head><title>Safety Data Sheets | ELEGOO</title></head></html>',
            str_contains($url, 'elegoo.com/pages/resin') => '<html><head><title>ELEGOO Resin</title></head><body><a href="/sds">SDS</a></body></html>',
            str_contains($url, 'nosds.pdf') => '%PDF-1.4 brochure',
            default => null,
        };
    };
    $asked = [];
    $line = $col->taskSds();
    $sg = $col->suggestions();
    t($sg['pending'] === 1 && $sg['items'][0]['task'] === 'sds' && $sg['items'][0]['page_url'] === 'https://polymaker.com/wp-content/uploads/PolyLite-PETG.pdf' && str_contains($sg['items'][0]['source'], '제품 페이지') && $asked === [] && str_contains($line, 'PolyLite PETG'),
        '안전 자료 찾기 — 제품 공식 페이지의 「Safety Data Sheet」 링크 (메일 주소는 건너뜀 · AI 는 묻지 않음)');
    t(in_array('안전 자료 (MSDS)', array_column($sg['items'][0]['lines'], 'label'), true), '제안함에 MSDS 주소가 보임');
    $col->apply($sg['items'][0]['id']);
    t(DB::table('cat_materials')->where('key', $mk)->value('sds_url') === 'https://polymaker.com/wp-content/uploads/PolyLite-PETG.pdf', '반영 → MSDS 칸');
    $reply = json_encode(['sds_url' => 'https://www.elegoo.com/pages/resin']);
    $line = $col->taskSds();
    t($col->suggestions()['pending'] === 0 && str_contains($line, '찾지 못했어요') && $asked !== [], 'AI 가 준 주소라도 열어서 SDS 문서가 아니면(제품 페이지) 올리지 않음');
    DB::table('cat_materials')->where('key', $mk2)->update(['ai_sds_at' => null]);
    $reply = json_encode(['sds_url' => 'https://www.elegoo.com/pages/sds']);
    $line = $col->taskSds();
    $sg = $col->suggestions();
    t($sg['pending'] === 1 && $sg['items'][0]['page_url'] === 'https://www.elegoo.com/pages/sds' && str_contains($sg['items'][0]['source'], 'AI') && str_contains($line, 'Elegoo Standard Resin 2.0'), '공식 페이지가 없으면 AI — 열어 보니 SDS 모음 페이지라 올림');
    t(! Collector::isSds('https://bambulab.com') && ! Collector::isSds('https://x.com/nosds.pdf') && Collector::isSds('https://x.com/nosds.pdf', true) && str_contains($col->taskSds(), '없어요'),
        '첫 화면 주소는 SDS 아님 · SDS 라는 말이 없는 PDF 는 링크 글이 SDS 일 때만 · 다 찬 뒤엔 쉼');
    DB::table('cat_materials')->where('key', $mk2)->update(['sds_url' => 'https://www.elegoo.com']);
    (require dirname(__DIR__).'/database/migrations/2026_10_09_000020_clear_homepage_sds.php')->up();
    t(DB::table('cat_materials')->where('key', $mk2)->value('sds_url') === null && DB::table('cat_materials')->where('key', $mk)->value('sds_url') !== null, '업데이트 — 홈페이지 주소만 있던 MSDS 칸은 비움 (진짜 SDS 주소는 그대로)');
    DB::table('cat_suggestions')->delete();

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

    echo "■ 제조사 로고 (0.2.3)\n";
    $LG = \Modules\Custom\Catalog\Support\Logos::class;
    t($LG::map() === [] && $LG::of('Bambu Lab') === '' && $LG::home('Bambu Lab') === 'https://bambulab.com' && $LG::home('Prusa') !== '' && $LG::home('Two Trees') === '', '처음엔 로고 없음(머리글자 배지) · 제조사 홈페이지는 앎');
    Collector::$pager = fn (string $url) => str_contains($url, 'bambulab.com') ? '<html><head><link rel="icon" href="/favicon.ico"><link rel="icon" type="image/png" sizes="32x32" href="/i32.png"><link rel="apple-touch-icon" sizes="180x180" href="//cdn.bambulab.com/touch.png"></head></html>' : '<html></html>';
    $asked = [];
    PhotoService::$fetcher = function (string $url) use (&$asked) {
        $asked[] = $url;

        return str_contains($url, 'touch.png') ? ['body' => jpeg(180, 180, 9), 'type' => 'image/png'] : null;
    };
    $bu = $LG::fetch('Bambu Lab');
    t($asked[0] === 'https://cdn.bambulab.com/touch.png' && $LG::of('bambu lab') === $bu && PhotoService::size((string) PhotoService::read(basename($bu))) === [180, 180], '홈페이지에서 가져오기 — 가장 큰 아이콘(apple-touch-icon)부터');
    try {
        $LG::fetch('Creality');
        t(false, '아이콘이 없으면 알려 줌');
    } catch (\InvalidArgumentException $e) {
        t(str_contains($e->getMessage(), '직접 올려'), '아이콘이 없으면 알려 줌');
    }
    $u = $LG::set('Kingroon', jpeg(900, 300, 7));
    $ls = PhotoService::size((string) PhotoService::read(basename($u)));
    t(str_contains($u, '/images/logo-') && $ls === [320, 107] && $LG::map()['kingroon'] === $u && array_values(array_filter($LG::adminList(), static fn ($x) => $x['brand'] === 'Kingroon'))[0]['logo'] === $u, '로고 올리기 — 320px 로 줄여 저장');
    Settings::save(['per_page' => 30]);
    t($LG::of('Kingroon') === $u, '다른 설정을 저장해도 로고는 그대로');
    $LG::clear('Kingroon');
    t($LG::of('Kingroon') === '' && $LG::of('Bambu Lab') === $bu, '로고 지우기');
    $svc->save('equipment', ['kind' => 'fdm', 'brand' => 'QIDI', 'title' => 'Q2']);
    $svc->save('equipment', ['kind' => 'fdm', 'brand' => 'QIDI Tech', 'title' => 'Plus4']);
    $q1 = $LG::set('QIDI Tech', jpeg(300, 300, 2));
    $mp = $LG::map();
    t($LG::of('QIDI') === $q1 && $mp['qidi'] === $q1 && $mp['qiditech'] === $q1, '0.2.5 로고 하나를 바꾸면 같은 회사(조금 다르게 적은 이름 포함)의 모든 항목에');
    $q2 = $LG::set('QIDI', jpeg(300, 300, 4));
    t($q2 !== $q1 && $LG::of('QIDI Tech') === $q2 && count(array_filter(array_keys((array) Settings::get('logos')), static fn ($k) => str_starts_with((string) $k, 'qidi'))) === 1, '다시 바꾸면 예전 것은 치우고 하나만');

    echo "■ 다른 모듈과 잇기\n";
    $book = Catalog::equipmentBook();
    $x1 = array_values(array_filter($book['fdm'], static fn ($x) => $x['m'] === 'X1 Carbon'))[0] ?? [];
    t(isset($book['fdm'], $book['sla'], $book['laser']) && $x1['b'] === 'Bambu Lab' && $x1['s'] === [256, 256, 256] && $x1['mc'] === 1 && ! isset($x1['image']), '업체검색용 장비 목록 — equipment-catalog.json 과 같은 꼴 (사진 없음)');
    $mb = Catalog::materialBook();
    $es = array_values(array_filter($mb['fdm'], static fn ($x) => $x['b'] === 'eSUN'))[0];
    t($es['mat'] === 'PLA+' && $es['nz'] === [205, 225] && $es['dry'] === [50, 6], '재료 목록');
    Catalog::forget();
    $fe = Catalog::findEquipment('fdm', 'bambu lab', 'p1s');
    $fm = Catalog::findMaterial('fdm', 'ESUN', 'pla+');
    t($fe['url'] === '/catalog/bambu-lab-p1s-fdm' && in_array('500 mm/s', $fe['chips'], true) && $fm['title'] === 'PLA+' && $fm['chips'][0] === 'PLA+' && Catalog::findMaterial('fdm', 'eSUN', 'PEEK') === null && Catalog::findEquipment('fdm', '', 'P1S') === null,
        '0.2.4 다른 모듈이 항목 하나를 찾아 감 (요약 · 그림 · 주소) — 대소문자 · 띄어쓰기 무시');
    $sr = CatalogListener::siteSearch('bambu', 1, 3, 'relevance');
    t($sr['total'] >= 4 && count($sr['items']) === 3 && $sr['has_more_pages'] && str_contains($sr['items'][0]['title_highlighted'], '<mark>Bambu</mark>') && str_starts_with($sr['items'][0]['url'], '/catalog/'), '통합 검색 — 카탈로그 탭');
    $sr2 = CatalogListener::siteSearch('pla', 1, 10, 'relevance');
    t($sr2['total'] === 1 && $sr2['items'][0]['badge'] === '필라멘트', '통합 검색 — 재료도');
    t(CatalogService::kindWords('equipment', '레진 프린터')[0] === ['sla', 'dlp'] && CatalogService::kindWords('equipment', 'Elegoo 레진프린터') === [['sla', 'dlp'], ['Elegoo']]
        && CatalogService::kindWords('materials', 'eSUN 필라멘트') === [['fdm'], ['eSUN']] && CatalogService::kindWords('materials', '레진 프린터') === [['resin'], ['프린터']]
        && CatalogService::kindWords('equipment', 'Bambu P1S') === [null, ['Bambu', 'P1S']] && CatalogService::kindWords('equipment', 'FDM 프린터')[0] === ['fdm'], '검색어에서 종류 이름을 골라냄 (띄어쓰기 상관없이)');
    $st = CatalogListener::searchType('materials', '필라멘트', 1, 50);
    $fdmN = DB::table('cat_materials')->where('status', 'active')->where('kind', 'fdm')->count();
    t($st['total'] === $fdmN && $fdmN > 0 && $st['items'][0]['category'] === 'filament' && str_starts_with($st['items'][0]['url'], '/catalog/') && $st['items'][0]['title'] !== '', '통합 검색 「필라멘트 · 레진」 — 「필라멘트」 로 필라멘트 전부 (제목 · 주소)');
    $rp = CatalogListener::searchType('equipment', '레진 프린터', 1, 50);
    t($rp['total'] === DB::table('cat_equipment')->where('status', 'active')->whereIn('kind', ['sla', 'dlp'])->count() && CatalogListener::searchType('materials', '레진 프린터', 1, 5)['total'] === 0, '「레진 프린터」 는 장비에서만 (레진 재료로 잘못 나오지 않음)');
    $wp = CatalogListener::searchType('equipment', 'P1S', 1, 5);
    t($wp['total'] >= 1 && $wp['items'][0]['has_thumbnail'] === ($wp['items'][0]['thumbnail'] !== '') && array_key_exists('image_url', $wp['items'][0]) && CatalogListener::searchType('equipment', 'P1S', 9, 5)['items'] === [], '사진 주소 · 넘는 쪽은 빈 목록');
    $home = (new CatalogListener())->homeSections([]);
    t($home[0]['key'] === 'catalog' && count($home[0]['items']) >= 4 && $home[0]['items'][0]['image'] !== '', '홈 칸 — 사진 있는 것부터');
    t(count(Fields::forKind('equipment', 'fdm')) >= 40 && count(Fields::forKind('materials', 'fdm')) >= 30 && count(Fields::meta()['tabs']) === 8, '칸 정의 (FDM 장비 '.count(Fields::forKind('equipment', 'fdm')).'칸 · 필라멘트 '.count(Fields::forKind('materials', 'fdm')).'칸)');

    echo "■ 고쳐서 반영 · AI 로 정리해 넣기 · 출처 (0.2.11)\n";
    DB::table('cat_suggestions')->delete();
    $sid = $col->suggest('new', 'equipment', null, 'Bambu Lab Z9', ['brand' => 'Bambu Lab', 'title' => 'Z9', 'kind' => 'fdm', 'values' => ['build_x_mm' => 999]]);
    $k9 = $col->apply($sid, ['type' => 'equipment', 'brand' => 'Bambu Lab', 'title' => 'Z9 Pro', 'kind' => 'fdm', 'summary' => '고친 소개', 'values' => ['build_x_mm' => 300]])['key'];
    $d9 = $svc->detail(...array_merge($svc->find($k9), [true]));
    t($d9['title'] === 'Z9 Pro' && $d9['values']['build_x_mm'] === 300 && $d9['summary'] === '고친 소개' && DB::table('cat_suggestions')->find($sid)->status === 'applied', '고쳐서 반영 — 고친 값으로 새 항목');
    $sid2 = $col->suggest('fill', 'equipment', $k9, 'Bambu Lab Z9 Pro', ['values' => ['build_y_mm' => 1]]);
    $col->apply($sid2, ['brand' => 'Bambu Lab', 'title' => 'Z9 Pro', 'kind' => 'fdm', 'summary' => '고친 소개', 'values' => ['build_x_mm' => 310, 'build_y_mm' => 290]]);
    $d9 = $svc->detail(...array_merge($svc->find($k9), [true]));
    t($d9['values']['build_x_mm'] === 310 && $d9['values']['build_y_mm'] === 290, '제원 제안도 고쳐서 반영 — 적혀 있던 값도 고칠 수 있음');
    $sent = '';
    AiClient::$sender = function (string $url, array $h, array $body) use (&$reply, &$sent) {
        $sent = json_encode($body, JSON_UNESCAPED_UNICODE);

        return ['message' => ['content' => $reply]];
    };
    $reply = json_encode(['kind' => 'fdm', 'brand' => 'Bambu Lab', 'title' => 'Z9 Pro', 'summary' => '글로 쓴 소개.', 'values' => ['build_x_mm' => '256', 'structure' => '없는값'], 'facts' => ['Wi-Fi' => '2.4GHz'], 'issues' => ['팬 소음']], JSON_UNESCAPED_UNICODE);
    $x = $col->extract('equipment', 'fdm', "Build volume : 256 x 256 x 256 mm\nWi-Fi : 2.4GHz");
    t($x['values'] === ['build_x_mm' => 256] && $x['facts'] === ['Wi-Fi' => '2.4GHz'] && $x['issues'] === '팬 소음' && $x['source_url'] === '' && str_contains($sent, 'Build volume') && str_contains($sent, '적혀 있는 것만'),
        'AI 로 정리해 넣기 — 붙여 넣은 글에서 칸 값만 (보기에 없는 값은 버림 · 나머지는 기타 제원)');
    Collector::$pager = fn (string $u) => str_contains($u, 'spec.test') ? '<html><script>var x=1</script><table><tr><td>Build volume</td><td>256 mm</td></tr></table></html>' : null;
    $x2 = $col->extract('equipment', 'fdm', 'https://spec.test/z9');
    t($x2['source_url'] === 'https://spec.test/z9' && $x2['homepage_url'] === 'https://spec.test/z9' && str_contains($sent, 'Build volume : 256 mm') && ! str_contains($sent, 'var x'), '주소만 넣으면 그 페이지 글로 (표는 「 : 」 · 스크립트 빼고) · 출처 주소');
    $e1 = $e2 = '';
    try { $col->extract('equipment', 'fdm', 'https://nope.test/z'); } catch (\InvalidArgumentException $e) { $e1 = $e->getMessage(); }
    try { $col->extract('equipment', 'fdm', '짧음'); } catch (\InvalidArgumentException $e) { $e2 = $e->getMessage(); }
    t(str_contains($e1, '열지 못했어요') && str_contains($e2, '없어요'), '못 여는 주소 · 너무 짧은 글은 알려 줌');
    $so = $col->sources(['https://spec.test/a', 'javascript:x', 'https://spec.test/a', ['url' => 'https://nope.test/b']]);
    t($so === [['url' => 'https://spec.test/a', 'ok' => true], ['url' => 'https://nope.test/b', 'ok' => false]], '출처 정리 — 주소만 · 겹침 빼고 · 열어 봄');
    t(Settings::normalize([])['new_values'] === false && Settings::normalize([])['ai_paste'] === true, '설정 기본 — 새 항목은 이름만 · AI 정리 켜짐');
    echo "■ HeyGears UltraCraft Reflex 제원 · 칸 정리 (0.2.12)\n";
    $hg = $svc->save('equipment', ['kind' => 'dlp', 'brand' => 'HeyGears', 'title' => 'UltraCraft Reflex', 'values' => ['build_x_mm' => 999, 'light' => 'DLP']])['key'];
    (require dirname(__DIR__).'/database/migrations/2026_10_09_000021_heygears_reflex_specs.php')->up();
    $hd = $svc->detail(...array_merge($svc->find($hg), [true]));
    $hv = $hd['values'];
    t($hv['build_x_mm'] === 192 && $hv['build_y_mm'] === 121 && $hv['build_z_mm'] === 220 && $hv['min_layer_um'] === 20 && $hv['xy_um'] === 33 && $hv['light'] === 'LCD (MSLA)' && $hv['lcd_res'] === '6K Mono (5760×3600)'
        && $hv['vat_heat'] === true && $hv['auto_feed'] === true && $hv['camera'] === true && $hv['ai_detect'] === true && $hv['air_filter'] === true && $hv['open_source'] === false
        && $hv['connect'] === ['USB', 'Wi-Fi', 'LAN'] && $hv['size_h'] === 572 && $hv['weight_kg'] === 25 && $hv['power_w'] === 350 && $hv['sale'] === '판매 중' && $hv['origin'] === '중국' && str_starts_with((string) $hv['released_on'], '2023-06')
        && str_contains($hv['slicer'], 'Blueprint Studio') && str_contains($hv['print_speed_h'], '27 mm/h') && str_contains($hv['voltage'], '100–240'), 'Reflex 제원 23칸 — 예전 값(999 · DLP) 위에 덮어씀');
    $cd = array_values(array_filter(Fields::forKind('equipment', 'dlp'), static fn ($d) => $d['key'] === 'connect'))[0];
    t(Fields::clean($cd, 'USB · Wi-Fi · Ethernet') === ['USB', 'Wi-Fi', 'LAN'] && Fields::clean($cd, ['이더넷', 'WiFi', 'TF 카드', 'lan']) === ['LAN', 'Wi-Fi', 'SD'], '연결: Ethernet · 이더넷 → LAN, WiFi → Wi-Fi (겹치면 하나)');
    echo "■ AI 로 정리해 넣기 — 뒤에서 돌기 (0.2.13)\n";
    $jid = Collector::jobStart();
    t(Collector::job($jid)['status'] === 'run' && Collector::job('zz') === null, '일 번호 → 처음엔 「도는 중」');
    $col->jobRun($jid, ['type' => 'equipment', 'kind' => 'fdm', 'text' => "Build volume : 256 x 256 x 256 mm\nWi-Fi : 2.4GHz"]);
    $jd = Collector::job($jid);
    t($jd['status'] === 'done' && $jd['data']['values'] === ['build_x_mm' => 256] && str_contains($jd['message'], '칸을 채웠어요'), '끝나면 결과 (화면이 2초마다 받아 감)');
    $jid2 = Collector::jobStart();
    $col->jobRun($jid2, ['type' => 'equipment', 'kind' => 'fdm', 'text' => '짧음']);
    t(Collector::job($jid2)['status'] === 'fail' && str_contains(Collector::job($jid2)['message'], '없어요'), '안 되면 까닭');
    array_map('unlink', glob($tmp.'/img/*') ?: []);
    array_map('unlink', glob($tmp.'/*.*') ?: []);
    echo "\n통과 {$pass} · 실패 {$fail}\n";
    exit($fail ? 1 : 0);
}
