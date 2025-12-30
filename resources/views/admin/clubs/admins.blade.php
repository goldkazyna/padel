@extends('layouts.app')

@section('title', 'Админы клуба')

@section('content')
<div class="page-header">
    <div>
        <h2>Админы клуба</h2>
        <p>{{ $club->name }}</p>
    </div>
    <a href="{{ route('admin.clubs.index') }}" class="btn-outline-custom">
        <i class="bi bi-arrow-left"></i>
        <span>Назад к клубам</span>
    </a>
</div>

<div class="row g-4">
    <!-- Current admins -->
    <div class="col-lg-6">
        <div class="card-dark h-100">
            <div class="card-header">
                <h5><i class="bi bi-people"></i> Текущие админы</h5>
            </div>
            <div class="card-body">
                @if($club->admins->count() > 0)
                    @foreach($club->admins as $admin)
                        <div class="d-flex align-items-center justify-content-between p-3 rounded-3 mb-3" 
                             style="background: var(--bg-secondary);">
                            <div class="d-flex align-items-center gap-3">
                                <div class="user-avatar">
                                    {{ strtoupper(substr($admin->first_name, 0, 1) . substr($admin->last_name, 0, 1)) }}
                                </div>
                                <div>
                                    <div class="fw-medium">{{ $admin->full_name }}</div>
                                    <small class="text-secondary">{{ $admin->email }}</small>
                                </div>
                            </div>
                            <form action="{{ route('admin.clubs.admins.remove', [$club, $admin]) }}" method="POST"
                                  onsubmit="return confirm('Удалить админа?')">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn-danger-custom btn-sm">
                                    <i class="bi bi-x-lg"></i>
                                </button>
                            </form>
                        </div>
                    @endforeach
                @else
                    <div class="text-center text-secondary py-5">
                        <i class="bi bi-people fs-1 d-block mb-3 opacity-50"></i>
                        Нет назначенных админов
                    </div>
                @endif
            </div>
        </div>
    </div>

    <!-- Add admin -->
    <div class="col-lg-6">
        <div class="card-dark h-100">
            <div class="card-header">
                <h5><i class="bi bi-person-plus"></i> Добавить админа</h5>
            </div>
            <div class="card-body">
				<div class="mb-3">
					<label class="form-label">Email игрока</label>
					<div class="input-group">
						<input type="email" id="searchEmail" class="form-control" placeholder="player@example.com">
						<button type="button" class="btn-primary-custom" onclick="searchPlayer()">
							<i class="bi bi-search"></i> Найти
						</button>
					</div>
				</div>
				
				<div id="searchResult"></div>
			</div>

			<script>
			function searchPlayer() {
				const email = document.getElementById('searchEmail').value;
				const resultDiv = document.getElementById('searchResult');
				
				if (!email) {
					resultDiv.innerHTML = '<div class="alert-danger-custom">Введите email</div>';
					return;
				}
				
				fetch(`{{ route('admin.players.search') }}?email=${encodeURIComponent(email)}`)
					.then(response => response.json())
					.then(data => {
						if (data.found) {
							resultDiv.innerHTML = `
								<div class="d-flex align-items-center justify-content-between p-3 rounded-3" style="background: var(--bg-secondary);">
									<div class="d-flex align-items-center gap-3">
										<div class="user-avatar">${data.player.name.split(' ').map(n => n[0]).join('').toUpperCase()}</div>
										<div>
											<div class="fw-medium">${data.player.name}</div>
											<small class="text-secondary">${data.player.email}</small>
										</div>
									</div>
									<form action="{{ route('admin.clubs.admins.add', $club) }}" method="POST">
										@csrf
										<input type="hidden" name="user_id" value="${data.player.id}">
										<button type="submit" class="btn-primary-custom btn-sm">
											<i class="bi bi-plus"></i> Назначить
										</button>
									</form>
								</div>
							`;
						} else {
							resultDiv.innerHTML = '<div class="alert-danger-custom">Игрок не найден или уже является админом</div>';
						}
					})
					.catch(error => {
						resultDiv.innerHTML = '<div class="alert-danger-custom">Ошибка поиска</div>';
					});
			}

			document.getElementById('searchEmail').addEventListener('keypress', function(e) {
				if (e.key === 'Enter') {
					e.preventDefault();
					searchPlayer();
				}
			});
			</script>
        </div>
    </div>
</div>
@endsection