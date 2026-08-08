{{-- Выбор инвентаря в модалке редактирования брони. --}}
@if(($inventoryItems ?? collect())->count())
{{-- Маркер: форма реально показывала пикер инвентаря. Статический, не
     зависит от JS — присутствует при любой отправке формы, даже если
     список выбранного пуст (админ убрал всё). Бэкенд по нему отличает
     «инвентарь намеренно не тронут» от «поле пустое, потому что выбор
     сбросили» — без маркера сервер не трогает уже записанные строки. --}}
<input type="hidden" name="inventory_touched" value="1">
<div class="modal-section-title js-edit-hide-for-group">Инвентарь</div>
<div class="inv-pick js-edit-hide-for-group" id="editInventoryPick">
    @foreach($inventoryItems as $inv)
        <button type="button" class="inv-pick-btn" data-item-id="{{ $inv->id }}"
                onclick="addInventory('edit', {{ $inv->id }}, @js($inv->name), {{ (int) $inv->price }})">
            <span class="inv-pick-name">{{ $inv->name }}</span>
            <span class="inv-pick-price">{{ number_format((int) $inv->price, 0, ',', ' ') }} ₸</span>
        </button>
    @endforeach
</div>
<div class="inv-chosen js-edit-hide-for-group" id="editInventoryChosen"></div>
@endif
