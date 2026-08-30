{{--
    Аватар игрока в турнирных таблицах.

    Ждёт $player (модель пользователя). Фото, если оно есть, иначе инициалы —
    как в приложении: человек с фото в рейтинге и безликий кружок в таблице
    выглядели как разные разделы.
--}}
@php
    $avatarName = trim($player->name ?? '');
    $avatarFirst = $player->first_name ?? $avatarName;
    $avatarLast = $player->last_name ?? '';
    $avatarInitials = mb_strtoupper(
        mb_substr($avatarFirst, 0, 1) . mb_substr($avatarLast, 0, 1)
    );
@endphp

@if(!empty($player->avatar))
    <img class="player-avatar player-avatar-img" src="{{ $player->avatar }}"
         alt="{{ $avatarName }}" loading="lazy">
@else
    <div class="player-avatar">{{ $avatarInitials }}</div>
@endif
