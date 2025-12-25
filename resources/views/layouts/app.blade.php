<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'Padel Center')</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        .sidebar { min-height: 100vh; background: #212529; }
        .sidebar a { color: #adb5bd; text-decoration: none; }
        .sidebar a:hover, .sidebar a.active { color: #fff; background: #495057; }
        .level-badge { font-size: 0.85rem; }
    </style>
</head>
<body>
    <div class="d-flex">
        <!-- Sidebar -->
        <nav class="sidebar p-3" style="width: 250px;">
            <h4 class="text-white mb-4">🎾 Padel</h4>
            
            <ul class="nav flex-column">
                <li class="nav-item mb-2">
                    <a href="{{ route('dashboard') }}" class="nav-link rounded {{ request()->routeIs('dashboard') ? 'active' : '' }}">
                        <i class="bi bi-house me-2"></i> Главная
                    </a>
                </li>
                <li class="nav-item mb-2">
                    <a href="{{ route('profile.show') }}" class="nav-link rounded {{ request()->routeIs('profile.*') ? 'active' : '' }}">
                        <i class="bi bi-person me-2"></i> Профиль
                    </a>
                </li>

                @if(auth()->user()->isClubAdmin() || auth()->user()->isSuperAdmin())
                    <li class="nav-item mt-3 mb-2">
                        <span class="text-muted small">АДМИН КЛУБА</span>
                    </li>
                    <li class="nav-item mb-2">
                        <a href="{{ route('club.dashboard') }}" class="nav-link rounded {{ request()->routeIs('club.*') ? 'active' : '' }}">
                            <i class="bi bi-building me-2"></i> Мой клуб
                        </a>
                    </li>
                @endif

                @if(auth()->user()->isSuperAdmin())
                    <li class="nav-item mt-3 mb-2">
                        <span class="text-muted small">СУПЕР-АДМИН</span>
                    </li>
                    <li class="nav-item mb-2">
                        <a href="{{ route('admin.dashboard') }}" class="nav-link rounded {{ request()->routeIs('admin.dashboard') ? 'active' : '' }}">
                            <i class="bi bi-speedometer2 me-2"></i> Панель
                        </a>
                    </li>
                    <li class="nav-item mb-2">
                        <a href="{{ route('admin.clubs.index') }}" class="nav-link rounded {{ request()->routeIs('admin.clubs.*') ? 'active' : '' }}">
                            <i class="bi bi-buildings me-2"></i> Клубы
                        </a>
                    </li>
                @endif
            </ul>

            <hr class="text-secondary mt-4">
            
            <div class="text-white small mb-2">
                {{ auth()->user()->full_name }}
            </div>
            
            <form action="{{ route('logout') }}" method="POST">
                @csrf
                <button type="submit" class="btn btn-outline-secondary btn-sm w-100">
                    <i class="bi bi-box-arrow-right me-1"></i> Выйти
                </button>
            </form>
        </nav>

        <!-- Main content -->
        <main class="flex-grow-1 p-4 bg-light">
            @if(session('success'))
                <div class="alert alert-success alert-dismissible fade show">
                    {{ session('success') }}
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            @endif

            @yield('content')
        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>