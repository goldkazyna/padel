<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class MobileAppController extends Controller
{
    /**
     * Проверка версии приложения
     * GET /api/mobile/app/version
     */
    public function version(Request $request)
    {
        $platform = $request->query('platform', 'android');

        return response()->json([
            'success' => true,
            'min_version' => config('mobile_app.min_version'),
            'latest_version' => config('mobile_app.latest_version'),
            'force_update' => (bool) config('mobile_app.force_update'),
            'store_url' => $platform === 'ios'
                ? config('mobile_app.store_url_ios')
                : config('mobile_app.store_url_android'),
        ]);
    }
}
