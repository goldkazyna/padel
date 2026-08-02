<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Certificate;
use App\Models\CertificateTemplate;
use App\Models\ClubClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Сертификаты клиента в мобильном приложении.
 *
 * Клиент видит ВСЕ свои сертификаты от разных клубов (связка по user_id или
 * телефону, как клубные карты), со статусом активен/использован и дизайном
 * из конструктора клуба (для рендера самого сертификата на детальном экране).
 */
class MobileCertificateController extends Controller
{
    /** GET /api/mobile/certificates */
    public function index(Request $request): JsonResponse
    {
        $userId = $request->user()?->id;
        $last10 = $this->userPhoneLast10($request);

        $clientIds = $this->matchingClientIds($userId, $last10);
        if (empty($clientIds)) {
            return response()->json([
                'active_count' => 0,
                'used_count' => 0,
                'certificates' => [],
            ]);
        }

        $certs = Certificate::whereIn('client_id', $clientIds)
            ->with(['club', 'template'])
            ->orderByDesc('id')
            ->get();

        // Кэш дефолтных шаблонов по клубу (если у сертификата нет своего).
        $defaults = [];

        $activeCount = 0;
        $usedCount = 0;
        $items = [];
        foreach ($certs as $cert) {
            $used = $cert->isUsed();
            $used ? $usedCount++ : $activeCount++;

            $tpl = $cert->template;
            if (!$tpl && $cert->club_id) {
                $tpl = $defaults[$cert->club_id]
                    ??= CertificateTemplate::defaultForClub($cert->club_id);
            }

            $items[] = [
                'id' => $cert->id,
                'number' => $cert->number,
                'type' => $cert->type,
                'recipient_name' => $cert->recipient_name,
                'title' => $cert->title,
                'value_type' => $cert->value_type,
                'value_label' => $cert->valueLabel(),
                'amount' => (int) ($cert->amount ?? 0),
                'hours' => (int) ($cert->hours ?? 0),
                'tournaments' => (int) ($cert->tournaments ?? 0),
                'used' => $used,
                'used_at' => $cert->used_at?->toIso8601String(),
                'created_at' => $cert->created_at?->toIso8601String(),
                'club' => [
                    'id' => $cert->club?->id,
                    'name' => $cert->club?->name,
                    'city' => $cert->club?->city,
                    'logo' => $cert->club?->logo ? url($cert->club->logo) : null,
                ],
                'design' => $this->design($tpl, $cert),
            ];
        }

        return response()->json([
            'active_count' => $activeCount,
            'used_count' => $usedCount,
            'certificates' => $items,
        ]);
    }

    /** Поля дизайна сертификата (шаблон конструктора клуба) + дефолты. */
    private function design(?CertificateTemplate $tpl, Certificate $cert): array
    {
        return [
            'heading' => $tpl->heading ?? 'Сертификат',
            'subtitle_named' => $tpl->subtitle_named ?? 'Настоящий сертификат выдан',
            'subtitle_generic' => $tpl->subtitle_generic ?? 'Сертификат на предъявителя',
            'body_text' => $cert->title
                ?: ($tpl->body_text ?? 'подтверждает право на получение услуг клуба.'),
            'background_color' => $tpl->background_color ?? '#fbfaf6',
            'accent_color' => $tpl->accent_color ?? '#c9a24b',
            'border_color' => $tpl->border_color ?? '#1f6b3b',
            'text_color' => $tpl->text_color ?? '#14532d',
            'logo_url' => $tpl?->logoUrl(),
            'orientation' => $tpl->orientation ?? 'portrait',
        ];
    }

    private function userPhoneLast10(Request $request): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $request->user()?->phone);
        return strlen($digits) < 10 ? null : substr($digits, -10);
    }

    /**
     * id клиентов клубов, принадлежащих пользователю: по user_id ИЛИ телефону
     * (последние 10 цифр). Идентично клубным картам.
     */
    private function matchingClientIds(?int $userId, ?string $last10): array
    {
        $ids = [];
        if ($userId !== null) {
            $ids = ClubClient::where('user_id', $userId)->pluck('id')->all();
        }
        if ($last10 !== null) {
            $tail = substr($last10, -8);
            $byPhone = ClubClient::whereNotNull('phone')
                ->where('phone', 'like', '%' . $tail . '%')
                ->pluck('phone', 'id')
                ->filter(fn($phone) =>
                    substr(preg_replace('/\D+/', '', (string) $phone), -10) === $last10)
                ->keys()
                ->all();
            $ids = array_merge($ids, $byPhone);
        }
        return array_values(array_unique($ids));
    }
}
