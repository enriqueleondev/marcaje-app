<?php

namespace App\Http\Controllers\Kiosk;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\View\View;

/**
 * La página del kiosco es pública: la tableta no inicia sesión, se autentica
 * con su token en cada llamada a la API.
 */
class KioskPageController extends Controller
{
    public function show(): View
    {
        return view('kiosk.show');
    }

    public function manifest(): JsonResponse
    {
        return response()->json([
            'name' => 'Marcaje · Kiosco',
            'short_name' => 'Kiosco',
            'start_url' => '/kiosco',
            'scope' => '/kiosco',
            'display' => 'fullscreen',
            'orientation' => 'landscape',
            'background_color' => '#08070C',
            'theme_color' => '#1A0B33',
            'icons' => [
                ['src' => '/kiosco-icon-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any maskable'],
                ['src' => '/kiosco-icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any maskable'],
            ],
        ], 200, ['Content-Type' => 'application/manifest+json']);
    }
}
