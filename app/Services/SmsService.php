<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Отправка SMS через HTTP-шлюз KazInfoTeh.
 *
 * Документация: http://docs.kazinfoteh.kz/protocols/http/outbox/
 * Запрос: GET/POST http://kazinfoteh.org:9507/api
 *   ?action=sendmessage&username=...&password=...
 *   &recipient=77001234567&messagetype=SMS:TEXT
 *   &originator=<sender>&messagedata=<text>
 * Успех: XML <acceptreport><statuscode>0</statuscode>...</acceptreport>
 */
class SmsService
{
    /**
     * Отправить SMS на номер.
     *
     * @param string $phone Номер в любом формате (нормализуется до 77XXXXXXXXX)
     * @param string $message Текст сообщения
     * @return bool Принято ли шлюзом к доставке
     */
    public function send(string $phone, string $message): bool
    {
        $config = config('services.kazinfoteh');

        if (empty($config['username']) || empty($config['password'])) {
            Log::warning('KazInfoTeh SMS not configured (no username/password)');
            return false;
        }

        $recipient = $this->normalizePhone($phone);

        $url = sprintf('%s://%s:%s/api', $config['scheme'], $config['host'], $config['port']);

        try {
            $response = Http::timeout(15)->asForm()->get($url, [
                'action' => 'sendmessage',
                'username' => $config['username'],
                'password' => $config['password'],
                'recipient' => $recipient,
                'messagetype' => 'SMS:TEXT',
                'originator' => $config['originator'],
                'messagedata' => $message,
            ]);

            if (!$response->successful()) {
                Log::error('KazInfoTeh SMS HTTP error', [
                    'recipient' => $recipient,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                return false;
            }

            // Ответ — XML вида <acceptreport><statuscode>0</statuscode>...
            $statusCode = $this->parseStatusCode($response->body());

            if ($statusCode !== '0') {
                Log::error('KazInfoTeh SMS rejected', [
                    'recipient' => $recipient,
                    'status_code' => $statusCode,
                    'body' => $response->body(),
                ]);
                return false;
            }

            Log::info('KazInfoTeh SMS accepted', ['recipient' => $recipient]);
            return true;
        } catch (\Throwable $e) {
            Log::error('KazInfoTeh SMS exception', [
                'recipient' => $recipient,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Достать statuscode из XML-ответа шлюза.
     */
    private function parseStatusCode(string $body): ?string
    {
        if (preg_match('/<statuscode>\s*(.*?)\s*<\/statuscode>/i', $body, $m)) {
            return trim($m[1]);
        }
        return null;
    }

    /**
     * Нормализация номера к формату 77XXXXXXXXX (только цифры, KZ).
     */
    private function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/[^0-9]/', '', $phone);

        // 8XXXXXXXXXX -> 7XXXXXXXXXX
        if (strlen($digits) === 11 && $digits[0] === '8') {
            $digits = '7' . substr($digits, 1);
        }
        // XXXXXXXXXX (10 цифр без кода страны) -> 7XXXXXXXXXX
        if (strlen($digits) === 10) {
            $digits = '7' . $digits;
        }

        return $digits;
    }
}
