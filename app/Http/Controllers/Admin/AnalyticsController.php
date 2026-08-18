<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\PlatformAnalytics;

/**
 * Цифры платформы за прошлое — для партнёров.
 */
class AnalyticsController extends Controller
{
    public function index(PlatformAnalytics $analytics)
    {
        return view('admin.analytics.index', [
            'totals' => $analytics->totals(),
            'monthly' => $analytics->monthly(),
            'retention' => $analytics->retention(),
            'byClub' => $analytics->byClub(),
        ]);
    }
}
