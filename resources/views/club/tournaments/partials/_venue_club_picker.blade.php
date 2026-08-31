{{--
    Выбор клуба-площадки: поиск по списку с подсказкой.

    Ждёт $venueClubs (коллекция клубов) и $venueClubSelected (клуб или null).

    Один блок на создание и правку турнира: раньше разметка, стили и скрипт
    были скопированы в оба файла и успели разойтись.

    Ищем по имени, городу И их транслитерации: клубы записаны латиницей
    («Pulse», «Davai Padel»), а вводят их кириллицей — и список молча
    оказывался пустым.
--}}
@php
    $venueClubSearchIndex = function ($club) {
        $parts = [$club->name, $club->city];

        foreach ([$club->name, $club->city] as $value) {
            if ($value) {
                $parts = array_merge($parts, \App\Support\NameSearch::variants($value));
            }
        }

        return mb_strtolower(implode(' ', array_filter($parts)));
    };
@endphp

<div class="venue-club-wrapper">
    <input type="text" id="venueClubSearch" class="form-control" autocomplete="off"
           placeholder="Начните вводить название клуба..."
           value="{{ $venueClubSelected->name ?? '' }}">
    <button type="button" id="venueClubClearBtn" class="venue-club-clear-btn"
            style="{{ $venueClubSelected ? '' : 'display:none;' }}" title="Очистить">&times;</button>

    <div id="venueClubResults" class="venue-club-results">
        @forelse($venueClubs as $vc)
            <div class="venue-club-item" data-id="{{ $vc->id }}" data-name="{{ $vc->name }}"
                 data-search="{{ $venueClubSearchIndex($vc) }}">
                <span class="venue-club-item-name">{{ $vc->name }}</span>
                <span class="venue-club-item-city">{{ $vc->city }}</span>
            </div>
        @empty
            <div class="venue-club-empty">Клубов пока нет</div>
        @endforelse

        {{-- Показывается, когда фильтр не нашёл совпадений: пустой выпадающий
             список выглядит как сломанный. --}}
        <div class="venue-club-empty" id="venueClubNothing" style="display: none;">Клубы не найдены</div>
    </div>
</div>

<input type="hidden" name="venue_club_id" id="venueClubId" value="{{ $venueClubSelected->id ?? '' }}">
<small class="text-secondary">Необязательно. Где физически играют — увидят записавшиеся.</small>

<style>
.venue-club-wrapper { position: relative; }

.venue-club-clear-btn {
    position: absolute;
    right: 10px;
    top: 50%;
    transform: translateY(-50%);
    width: 22px;
    height: 22px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border: none;
    border-radius: 50%;
    background: rgba(255, 255, 255, .08);
    color: var(--text-secondary);
    font-size: 16px;
    line-height: 1;
    cursor: pointer;
}

.venue-club-clear-btn:hover { background: rgba(255, 255, 255, .16); color: var(--text-primary); }

.venue-club-results {
    display: none;
    position: absolute;
    top: 100%;
    left: 0;
    right: 0;
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: 8px;
    max-height: 250px;
    overflow-y: auto;
    z-index: 1000;
    margin-top: 4px;
}

.venue-club-results.show { display: block; }

.venue-club-item {
    padding: 10px 12px;
    cursor: pointer;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
}

.venue-club-item:hover { background: var(--bg-card-hover); }
.venue-club-item-city { color: var(--text-muted); font-size: .85rem; }
.venue-club-empty { padding: 10px 12px; color: var(--text-muted); font-size: .9rem; }
</style>

<script>
(function () {
    var search = document.getElementById('venueClubSearch');
    var hidden = document.getElementById('venueClubId');
    var results = document.getElementById('venueClubResults');
    var clearBtn = document.getElementById('venueClubClearBtn');
    var nothing = document.getElementById('venueClubNothing');
    if (!search || !hidden || !results) return;

    var items = Array.prototype.slice.call(results.querySelectorAll('.venue-club-item'));

    function showResults() { results.classList.add('show'); }
    function hideResults() { results.classList.remove('show'); }

    function filterItems() {
        var q = search.value.trim().toLowerCase();
        var visible = 0;

        items.forEach(function (item) {
            var match = !q || item.dataset.search.indexOf(q) !== -1;
            item.style.display = match ? '' : 'none';
            if (match) visible++;
        });

        // Пустой список без объяснения читается как «поиск сломался».
        if (nothing) nothing.style.display = visible ? 'none' : 'block';
    }

    function toggleClearBtn() {
        if (clearBtn) clearBtn.style.display = hidden.value ? 'inline-flex' : 'none';
    }

    search.addEventListener('focus', function () {
        filterItems();
        showResults();
    });

    search.addEventListener('input', function () {
        hidden.value = '';
        toggleClearBtn();
        filterItems();
        showResults();
    });

    items.forEach(function (item) {
        item.addEventListener('mousedown', function (e) {
            e.preventDefault();
            hidden.value = item.getAttribute('data-id');
            search.value = item.getAttribute('data-name');
            toggleClearBtn();
            hideResults();
        });
    });

    document.addEventListener('click', function (e) {
        if (e.target !== search && !results.contains(e.target)) {
            hideResults();
        }
    });

    if (clearBtn) {
        clearBtn.addEventListener('click', function () {
            hidden.value = '';
            search.value = '';
            toggleClearBtn();
            filterItems();
            search.focus();
        });
    }

    toggleClearBtn();
})();
</script>
