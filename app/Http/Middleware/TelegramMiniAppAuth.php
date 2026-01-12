<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TelegramMiniAppAuth
{
    public function handle(Request $request, Closure $next): Response
    {
    // ВРЕМЕННО: пропускаем всех для теста
    $initData = $request->header('X-Telegram-Init-Data');
    
    if ($initData) {
        // Парсим без проверки подписи (временно!)
        parse_str($initData, $data);
        if (isset($data['user'])) {
            $data['user'] = json_decode($data['user'], true);
        }
        $request->merge(['telegram_user' => $data]);
        return $next($request);
    }

    // DEV MODE
    if (config('app.debug') && $request->header('X-Dev-Mode') === 'true') {
        $devUserId = $request->header('X-Dev-User-Id', '123456789');
        $request->merge(['telegram_user' => [
            'user' => [
                'id' => $devUserId,
                'first_name' => 'Test',
                'last_name' => 'User',
            ]
        ]]);
        return $next($request);
    }

    return response()->json(['error' => 'Missing Telegram init data'], 401);

        $initData = $request->header('X-Telegram-Init-Data');
        
        if (!$initData) {
            return response()->json(['error' => 'Missing Telegram init data'], 401);
        }

        $validatedData = $this->validateInitData($initData);
        
        if (!$validatedData) {
            return response()->json(['error' => 'Invalid Telegram init data'], 401);
        }

        $request->merge(['telegram_user' => $validatedData]);

        return $next($request);
    }

    protected function validateInitData(string $initData): ?array
    {
        $botToken = config('services.telegram.bot_token');
        
        if (empty($botToken)) {
            return null;
        }

        parse_str($initData, $data);
        
        if (empty($data['hash'])) {
            return null;
        }

        $hash = $data['hash'];
        unset($data['hash']);

        ksort($data);
        $dataCheckString = collect($data)
            ->map(fn($value, $key) => "$key=$value")
            ->implode("\n");

        $secretKey = hash_hmac('sha256', $botToken, 'WebAppData', true);
        $calculatedHash = bin2hex(hash_hmac('sha256', $dataCheckString, $secretKey, true));

        if (!hash_equals($calculatedHash, $hash)) {
            return null;
        }

        if (isset($data['auth_date'])) {
            $authDate = (int) $data['auth_date'];
            if (time() - $authDate > 86400) {
                return null;
            }
        }

        if (isset($data['user'])) {
            $data['user'] = json_decode($data['user'], true);
        }

        return $data;
    }
}