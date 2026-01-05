<div class="mb-4 p-3" style="background: rgba(255,255,255,0.05); border-radius: 8px;">
    <h6 class="text-white mb-3"><i class="bi bi-plus-circle me-2"></i>Добавить пару</h6>
    <form action="{{ route('club.tournaments.addTeam', $tournament) }}" method="POST">
        @csrf
        <div class="row">
            <div class="col-md-4 mb-3">
                <label class="form-label">Игрок 1 *</label>
                <input type="text" class="form-control" id="searchPlayer1" placeholder="Поиск..." autocomplete="off">
                <input type="hidden" name="player1_id" id="player1_id">
                <div id="player1Results" class="search-results"></div>
                <div id="player1Selected" class="selected-player mt-2" style="display: none;"></div>
            </div>
            <div class="col-md-4 mb-3">
                <label class="form-label">Игрок 2 *</label>
                <input type="text" class="form-control" id="searchPlayer2" placeholder="Поиск..." autocomplete="off">
                <input type="hidden" name="player2_id" id="player2_id">
                <div id="player2Results" class="search-results"></div>
                <div id="player2Selected" class="selected-player mt-2" style="display: none;"></div>
            </div>
            <div class="col-md-4 mb-3">
                <label class="form-label">Название команды</label>
                <input type="text" name="name" class="form-control" placeholder="Опционально">
            </div>
        </div>
        <div class="d-flex gap-2">
            <button type="submit" class="btn-primary-custom">
                <i class="bi bi-plus-lg me-1"></i> Добавить пару
            </button>
    </form>
    <form action="{{ route('club.tournaments.addTestTeams', $tournament) }}" method="POST" class="d-inline">
        @csrf
        <button type="submit" class="btn-outline-custom">
            <i class="bi bi-lightning me-1"></i> +Тестовые пары
        </button>
    </form>
        </div>
</div>
