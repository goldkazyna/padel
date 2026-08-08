{{-- Выбор инвентаря в модалке создания брони. Подключается в дневном
     и недельном расписании. Скрывается для групповых и турнирных броней. --}}
@if(($inventoryItems ?? collect())->count())
<div class="modal-section-title js-hide-for-group">Инвентарь</div>
<div class="inv-pick js-hide-for-group" id="bookInventoryPick">
    @foreach($inventoryItems as $inv)
        <button type="button" class="inv-pick-btn" data-item-id="{{ $inv->id }}"
                onclick="addInventory('book', {{ $inv->id }}, @js($inv->name), {{ (int) $inv->price }})">
            <span class="inv-pick-name">{{ $inv->name }}</span>
            <span class="inv-pick-price">{{ number_format((int) $inv->price, 0, ',', ' ') }} ₸</span>
        </button>
    @endforeach
</div>
<div class="inv-chosen js-hide-for-group" id="bookInventoryChosen"></div>
{{-- Повтор брони создаёт отдельную бронь на каждую дату, и инвентарь
     записывается в каждую — предупреждаем заранее, чтобы «еженедельно на
     месяц» не обернулось неожиданными четырьмя списаниями. --}}
<div class="inv-hint js-hide-for-group">При повторе брони выбранные позиции выдаются на каждую дату.</div>
@endif
