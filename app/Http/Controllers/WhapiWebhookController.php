<?php

namespace App\Http\Controllers;

use App\Services\WhapiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Приём вебхуков Whapi.Cloud.
 *
 * Whapi не умеет слать свои заголовки, поэтому секрет живёт прямо в адресе
 * вебхука — он известен только нам и настройкам канала. Отвечаем всегда
 * 200: на ошибку Whapi шлёт пакет повторно, а разбирать чужой формат в
 * бесконечном цикле ретраев смысла нет — что не поняли, то уже в логе.
 */
class WhapiWebhookController extends Controller
{
    public function __invoke(Request $request, string $secret, WhapiService $whapi): JsonResponse
    {
        $expected = (string) config('services.whapi.webhook_secret');

        if ($expected === '' || !hash_equals($expected, $secret)) {
            Log::warning('Whapi: вебхук с неверным секретом', ['ip' => $request->ip()]);

            return response()->json(['ok' => false], 404);
        }

        $payload = $request->all();

        try {
            $saved = app(WhapiService::class)->storeWebhook($payload);
        } catch (\Throwable $e) {
            Log::error('Whapi: не смогли разобрать вебхук', [
                'error' => $e->getMessage(),
                'payload' => $payload,
            ]);

            return response()->json(['ok' => true, 'saved' => 0]);
        }

        return response()->json(['ok' => true, 'saved' => $saved]);
    }
}
