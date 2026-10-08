<?php

namespace Modules\Custom\Catalog\Http\Controllers;

use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\Response;

class AssetController extends Controller
{
    public function show(string $file): Response
    {
        $name = basename($file);
        $path = dirname(__DIR__, 3).'/resources/assets/'.$name;
        if (! is_file($path)) {
            abort(404);
        }
        $type = str_ends_with($name, '.js') ? 'application/javascript; charset=UTF-8' : 'text/plain; charset=UTF-8';

        return response((string) file_get_contents($path), 200, ['Content-Type' => $type, 'Cache-Control' => 'no-cache']);
    }
}
