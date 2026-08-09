<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\Training;
use App\Models\TrainingParticipant;
use App\Models\User;
use RuntimeException;

/**
 * Логика тренировок: запись игроков и управление занятием со стороны тренера.
 *
 * Все проверки живут здесь, а не в контроллерах: тренировкой управляют из
 * двух мест (кабинет тренера и экран игрока), и правила должны быть одни.
 */
class TrainingService
{
    /**
     * Записать игрока.
     *
     * Повторный вызов ничего не делает: двойной тап по кнопке в приложении
     * не должен превращаться во вторую запись.
     *
     * @throws RuntimeException если записаться нельзя.
     */
    public function join(Training $training, User $player): void
    {
        $already = $training->participants()->where('user_id', $player->id)->exists();
        if ($already) {
            return;
        }

        if (!$training->isPlanned()) {
            throw new RuntimeException(
                $training->status === 'cancelled'
                    ? 'Тренировка отменена'
                    : 'Тренировка уже завершена'
            );
        }

        if (!$training->starts_at->isFuture()) {
            throw new RuntimeException('Тренировка уже началась');
        }

        if (!$training->hasFreeSlots()) {
            throw new RuntimeException('Свободных мест больше нет');
        }

        TrainingParticipant::create([
            'training_id' => $training->id,
            'user_id' => $player->id,
        ]);

        $this->notifyCoach(
            $training,
            'Запись на тренировку',
            $player->name . ' записался: ' . $this->trainingLabel($training),
            'training_joined'
        );
    }

    /** Отписаться от тренировки. */
    public function leave(Training $training, User $player): void
    {
        $removed = $training->participants()->where('user_id', $player->id)->delete();
        if (!$removed) {
            return;
        }

        // Тренеру важно знать: место освободилось, можно позвать другого.
        $this->notifyCoach(
            $training,
            'Отписался от тренировки',
            $player->name . ' отписался: ' . $this->trainingLabel($training),
            'training_left'
        );
    }

    /**
     * Уведомить тренера о движении в записи.
     * Себе самому уведомление не шлём: тренер может записаться на своё занятие.
     */
    private function notifyCoach(Training $training, string $title, string $body, string $type): void
    {
        $training->loadMissing('coach');
        $coach = $training->coach;
        if (!$coach) {
            return;
        }

        $this->notify($coach, $title, $body, $type, $training);
    }

    /**
     * Убрать участника решением тренера.
     *
     * От `leave` отличается только тем, кто инициатор: игрок получает
     * уведомление, что его сняли с занятия.
     */
    public function removeParticipant(Training $training, User $player): void
    {
        $removed = $training->participants()->where('user_id', $player->id)->delete();
        if (!$removed) {
            return;
        }

        $this->notify(
            $player,
            'Вас сняли с тренировки',
            $this->trainingLabel($training) . ' — тренер убрал вас из списка.',
            'training_removed',
            $training
        );
    }

    /**
     * Завершить тренировку.
     *
     * @throws RuntimeException если занятие ещё не закончилось.
     */
    public function complete(Training $training): void
    {
        if (!$training->isPlanned()) {
            throw new RuntimeException('Тренировка уже завершена или отменена');
        }

        if (!$training->isPast()) {
            throw new RuntimeException('Тренировка ещё не закончилась');
        }

        $training->update(['status' => 'completed']);
    }

    /**
     * Отменить тренировку — доступно в любой момент, пока она запланирована.
     * Каждому записавшемуся уходит уведомление, иначе люди приедут зря.
     *
     * @throws RuntimeException если тренировка уже завершена или отменена.
     */
    public function cancel(Training $training): void
    {
        if (!$training->isPlanned()) {
            throw new RuntimeException('Тренировка уже завершена или отменена');
        }

        $training->update(['status' => 'cancelled']);

        foreach ($training->players()->get() as $player) {
            $this->notify(
                $player,
                'Тренировка отменена',
                $this->trainingLabel($training) . ' — тренер отменил занятие.',
                'training_cancelled',
                $training
            );
        }
    }

    /** «10.08 в 19:00, Клуб» — общая подпись для уведомлений. */
    private function trainingLabel(Training $training): string
    {
        $training->loadMissing('club');
        $club = $training->club->name ?? '';
        $when = $training->starts_at->format('d.m') . ' в ' . $training->starts_at->format('H:i');

        return $club !== '' ? "Тренировка {$when}, {$club}" : "Тренировка {$when}";
    }

    /** Запись в колокольчик + пуш. Пуш не критичен: упал — молчим. */
    private function notify(User $user, string $title, string $body, string $type, Training $training): void
    {
        Notification::create([
            'user_id' => $user->id,
            'title' => $title,
            'body' => $body,
            'type' => $type,
            'category' => 'training',
            'data' => ['training_id' => $training->id],
        ]);

        try {
            app(FCMNotificationService::class)->sendToUser($user, $title, $body, [
                'type' => $type,
                'training_id' => (string) $training->id,
            ]);
        } catch (\Throwable $e) {
            // пуш не критичен
        }
    }
}
