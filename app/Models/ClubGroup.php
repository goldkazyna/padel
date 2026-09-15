<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClubGroup extends Model
{
    /** Ходят по пакету занятий. */
    public const TYPE_SUBSCRIPTION = 'subscription';

    /** Пришли разово попробовать. */
    public const TYPE_TRIAL = 'trial';

    /** Цена указана за занятие целиком, длительность не важна. */
    public const PRICE_UNIT_SESSION = 'session';

    /** Цена указана за час: двухчасовое занятие стоит вдвое дороже. */
    public const PRICE_UNIT_HOUR = 'hour';

    protected $fillable = [
        'club_id', 'name', 'type', 'coach_id', 'price_per_session', 'price_unit',
        'coach_price_per_client', 'capacity', 'status', 'note',
    ];

    protected $casts = [
        'price_per_session' => 'decimal:2',
        'coach_price_per_client' => 'decimal:2',
        'capacity' => 'integer',
    ];

    /** Новая группа без явного выбора — абонементная, как работало до появления поля. */
    protected $attributes = [
        'type' => self::TYPE_SUBSCRIPTION,
        'price_unit' => self::PRICE_UNIT_SESSION,
    ];

    public static function types(): array
    {
        return [
            self::TYPE_SUBSCRIPTION => 'Абонемент',
            self::TYPE_TRIAL => 'Пробная',
        ];
    }

    /** Цена считается по часам, а не за занятие целиком. */
    public function chargesByHour(): bool
    {
        return $this->price_unit === self::PRICE_UNIT_HOUR;
    }

    /**
     * Сколько занятие такой длительности стоит одному участнику.
     *
     * Час — единица цены, а не пакета: из абонемента всё равно списывается
     * одно занятие, сколько бы оно ни длилось.
     */
    public function priceForHours(float $hours): float
    {
        $price = (float) $this->price_per_session;

        return $this->chargesByHour() ? $price * max($hours, 0) : $price;
    }

    /**
     * Сколько тренер получает за одного пришедшего на занятие такой длительности.
     *
     * Единица та же, что у цены для клиента: если клуб берёт с людей по часам,
     * то и тренеру за двухчасовое занятие платится вдвое — иначе он ведёт вдвое
     * дольше за те же деньги. null — ставка за клиента не задана, платим по
     * часовой групповой ставке тренера.
     */
    public function coachPriceForHours(float $hours): ?float
    {
        if ($this->coach_price_per_client === null) {
            return null;
        }

        $price = (float) $this->coach_price_per_client;

        return $this->chargesByHour() ? $price * max($hours, 0) : $price;
    }

    /** «за час» / «за занятие» — подпись рядом с ценой. */
    public function priceUnitLabel(): string
    {
        return $this->chargesByHour() ? 'за час' : 'за занятие';
    }

    public function isTrial(): bool
    {
        return $this->type === self::TYPE_TRIAL;
    }

    public function getTypeNameAttribute(): string
    {
        return self::types()[$this->type] ?? self::types()[self::TYPE_SUBSCRIPTION];
    }

    public function club() { return $this->belongsTo(Club::class); }
    public function coach() { return $this->belongsTo(User::class, 'coach_id'); }
    public function members() { return $this->hasMany(ClubGroupMember::class, 'group_id'); }
    public function sessions() { return $this->hasMany(ClubGroupSession::class, 'group_id'); }
}
