<?php

namespace Modules\Custom\Catalog\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\Response;

class AssetController extends Controller
{
    /**
     * 0.2.24 (성능) 캐시 — 예전엔 모든 파일이 no-cache · ETag 없음이라 페이지마다 PHP 로 통째로 다시 받았음
     * (catalog-nav.js 는 모든 페이지에서 0.5~1초).
     * - 주소의 ?v= 가 지금 파일 버전(self::version)이면 1년 캐시 (파일이 바뀌면 주소가 바뀜)
     * - 사진 · 로고(jpg · png · webp)는 하루 캐시
     * - 그 밖은 no-cache + ETag (바뀌지 않았으면 304 · 본문 없음)
     */
    public function show(Request $request, string $file): Response
    {
        $name = basename($file);
        $path = self::path($name);
        if (! is_file($path)) {
            abort(404);
        }
        $type = match (true) {
            str_ends_with($name, '.js') => 'application/javascript; charset=UTF-8',
            str_ends_with($name, '.css') => 'text/css; charset=UTF-8',
            str_ends_with($name, '.json') => 'application/json; charset=UTF-8',
            str_ends_with($name, '.jpg'), str_ends_with($name, '.jpeg') => 'image/jpeg',
            str_ends_with($name, '.png') => 'image/png',
            str_ends_with($name, '.webp') => 'image/webp',
            default => 'text/plain; charset=UTF-8',
        };
        $image = str_starts_with($type, 'image/');
        $cache = match (true) {
            (string) $request->query('v', '') === self::version($name) => 'public, max-age=31536000, immutable',
            $image => 'public, max-age=86400',
            default => 'no-cache',
        };
        $etag = '"'.md5($name.'|'.filemtime($path).'|'.filesize($path)).'"';
        $headers = ['Content-Type' => $type, 'Cache-Control' => $cache, 'ETag' => $etag];
        $match = (string) $request->headers->get('If-None-Match', '');
        if ($match !== '' && in_array($etag, array_map('trim', explode(',', $match)), true)) {
            return response('', 304, $headers);
        }

        return response((string) file_get_contents($path), 200, $headers);
    }

    public static function path(string $name): string
    {
        return dirname(__DIR__, 3).'/resources/assets/'.basename($name);
    }

    /** 모듈 버전 + 파일 시각 — 파일이 바뀌면 값이 바뀜 (주소의 ?v= 로 씀) */
    public static function version(string $name): string
    {
        $path = self::path($name);

        return \Modules\Custom\Catalog\Api\Catalog::VERSION.'-'.(is_file($path) ? filemtime($path) : 0);
    }
}
