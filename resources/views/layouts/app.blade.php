<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Padel Center')</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root {
            --bg-primary: #0f0f0f;
            --bg-secondary: #141414;
            --bg-sidebar: #0a0a0a;
            --bg-card: #1a1a1a;
            --bg-card-hover: #222222;
            --accent: #22c55e;
            --accent-dark: #16a34a;
            --accent-glow: rgba(34, 197, 94, 0.15);
            --text-primary: #ffffff;
            --text-secondary: #9ca3af;
            --text-muted: #6b7280;
            --border: #2d2d2d;
            --border-light: #3d3d3d;
        }
        
        * { box-sizing: border-box; }
        
        body {
            background: var(--bg-primary);
            color: var(--text-primary);
            font-family: 'Segoe UI', system-ui, sans-serif;
            min-height: 100vh;
            margin: 0;
        }
        
        /* ===== SIDEBAR ===== */
        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            width: 260px;
            height: 100vh;
            background: var(--bg-sidebar);
            border-right: 1px solid var(--border);
            padding: 24px 0;
            z-index: 1000;
            transition: transform 0.3s ease;
            display: flex;
            flex-direction: column;
        }
        
        .sidebar-brand {
            padding: 0 24px 28px;
            border-bottom: 1px solid var(--border);
            margin-bottom: 24px;
        }
        
        .sidebar-brand h4 {
            color: var(--text-primary);
            margin: 0;
            font-weight: 700;
            font-size: 1.4rem;
        }
        
        .sidebar-brand span { color: var(--accent); }
        
        .sidebar-nav {
            flex: 1;
            padding: 0 12px;
            overflow-y: auto;
        }
        
        .nav-item { margin-bottom: 4px; }
        
        .nav-link {
            color: var(--text-secondary);
            padding: 14px 16px;
            display: flex;
            align-items: center;
            gap: 14px;
            text-decoration: none;
            border-radius: 12px;
            transition: all 0.2s;
            font-weight: 500;
        }
        
        .nav-link:hover {
            color: var(--text-primary);
            background: var(--bg-card);
        }
        
        .nav-link.active {
            color: var(--accent);
            background: var(--accent-glow);
        }
        
        .nav-link i {
            font-size: 1.2rem;
            width: 24px;
            text-align: center;
        }
        
        .nav-section-title {
            padding: 20px 16px 10px;
            font-size: 0.75rem;
            text-transform: uppercase;
            color: var(--text-muted);
            letter-spacing: 0.5px;
        }
        
        .sidebar-footer {
            padding: 20px 16px;
            margin: 0 12px;
            border-top: 1px solid var(--border);
            margin-top: auto;
        }
        
        .user-card {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px;
            background: var(--bg-card);
            border-radius: 12px;
            margin-bottom: 12px;
        }
        
        .user-avatar {
            width: 42px;
            height: 42px;
            background: linear-gradient(135deg, var(--accent) 0%, var(--accent-dark) 100%);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 1rem;
        }
        
        .user-info { flex: 1; min-width: 0; }
        
        .user-name {
            font-weight: 600;
            font-size: 0.95rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .user-role {
            font-size: 0.8rem;
            color: var(--text-muted);
        }
        
        .btn-logout {
            width: 100%;
            padding: 12px;
            background: transparent;
            border: 1px solid var(--border);
            color: var(--text-secondary);
            border-radius: 10px;
            font-weight: 500;
            transition: all 0.2s;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }
        
        .btn-logout:hover {
            background: rgba(239, 68, 68, 0.1);
            border-color: #ef4444;
            color: #ef4444;
        }
        
        /* ===== MAIN CONTENT ===== */
        .main-content {
            margin-left: 260px;
            padding: 30px;
            min-height: 100vh;
        }
        
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
        }
        
        .page-header h2 {
            margin: 0 0 6px 0;
            font-size: 1.6rem;
            font-weight: 700;
        }
        
        .page-header p {
            margin: 0;
            color: var(--text-secondary);
        }
        
        /* ===== BUTTONS ===== */
        .btn-primary-custom {
            background: var(--accent);
            color: #000;
            border: none;
            padding: 12px 24px;
            border-radius: 12px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s;
            text-decoration: none;
        }
        
        .btn-primary-custom:hover {
            background: var(--accent-dark);
            color: #000;
            transform: translateY(-2px);
            box-shadow: 0 10px 30px var(--accent-glow);
        }
        
        .btn-outline-custom {
            background: transparent;
            color: var(--text-secondary);
            border: 1px solid var(--border);
            padding: 10px 20px;
            border-radius: 10px;
            font-weight: 500;
            transition: all 0.2s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        
        .btn-outline-custom:hover {
            background: var(--bg-card-hover);
            color: var(--text-primary);
            border-color: var(--border-light);
        }
        
        .btn-danger-custom {
            background: rgba(239, 68, 68, 0.15);
            color: #ef4444;
            border: 1px solid rgba(239, 68, 68, 0.3);
            padding: 10px 20px;
            border-radius: 10px;
            font-weight: 500;
            transition: all 0.2s;
        }
        
        .btn-danger-custom:hover {
            background: rgba(239, 68, 68, 0.25);
            color: #ef4444;
        }
        
        /* ===== CARDS ===== */
        .card-dark {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 16px;
            overflow: hidden;
        }
        
        .card-dark .card-header {
            background: transparent;
            border-bottom: 1px solid var(--border);
            padding: 20px 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .card-dark .card-header h5 {
            margin: 0;
            font-size: 1rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .card-dark .card-header h5 i { color: var(--accent); }
        
        .card-dark .card-body { padding: 24px; }
        
        /* ===== STAT CARDS ===== */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 24px;
            transition: all 0.3s;
        }
        
        .stat-card:hover {
            border-color: var(--accent);
            box-shadow: 0 0 40px var(--accent-glow);
            transform: translateY(-4px);
        }
        
        .stat-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 16px;
        }
        
        .stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.3rem;
        }
        
        .stat-icon.green { background: rgba(34, 197, 94, 0.15); color: #22c55e; }
        .stat-icon.blue { background: rgba(59, 130, 246, 0.15); color: #3b82f6; }
        .stat-icon.purple { background: rgba(168, 85, 247, 0.15); color: #a855f7; }
        .stat-icon.orange { background: rgba(249, 115, 22, 0.15); color: #f97316; }
        .stat-icon.red { background: rgba(239, 68, 68, 0.15); color: #ef4444; }
        
        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 4px;
        }
        
        .stat-label {
            color: var(--text-secondary);
            font-size: 0.9rem;
        }
        
        /* ===== FORMS ===== */
        .form-label {
            color: var(--text-secondary);
            font-weight: 500;
            margin-bottom: 8px;
        }
        
        .form-control, .form-select {
            background: var(--bg-secondary);
            border: 1px solid var(--border);
            color: var(--text-primary);
            border-radius: 10px;
            padding: 12px 16px;
        }
        
        .form-control:focus, .form-select:focus {
            background: var(--bg-secondary);
            border-color: var(--accent);
            color: var(--text-primary);
            box-shadow: 0 0 0 3px var(--accent-glow);
        }
        
        .form-control::placeholder { color: var(--text-muted); }
        
        /* ===== TABLES ===== */
        .table-dark-custom {
            color: var(--text-primary);
        }
        
        .table-dark-custom th {
            background: var(--bg-secondary);
            color: var(--text-secondary);
            font-weight: 600;
            padding: 16px;
            border-bottom: 1px solid var(--border);
        }
        
        .table-dark-custom td {
            padding: 16px;
            border-bottom: 1px solid var(--border);
            vertical-align: middle;
        }
        
        .table-dark-custom tbody tr:hover {
            background: var(--bg-card-hover);
        }
        
        /* ===== ALERTS ===== */
        .alert-success-custom {
            background: rgba(34, 197, 94, 0.15);
            border: 1px solid rgba(34, 197, 94, 0.3);
            color: #22c55e;
            border-radius: 12px;
            padding: 16px 20px;
        }
        
        .alert-danger-custom {
            background: rgba(239, 68, 68, 0.15);
            border: 1px solid rgba(239, 68, 68, 0.3);
            color: #ef4444;
            border-radius: 12px;
            padding: 16px 20px;
        }
        
        /* ===== BADGES ===== */
        .badge-success-custom {
            background: rgba(34, 197, 94, 0.15);
            color: #22c55e;
            padding: 6px 12px;
            border-radius: 6px;
            font-weight: 500;
        }
        
        .badge-danger-custom {
            background: rgba(239, 68, 68, 0.15);
            color: #ef4444;
            padding: 6px 12px;
            border-radius: 6px;
            font-weight: 500;
        }
        
        .badge-secondary-custom {
            background: var(--bg-secondary);
            color: var(--text-secondary);
            padding: 6px 12px;
            border-radius: 6px;
            font-weight: 500;
        }
        
        /* ===== PROFILE CARD ===== */
        .profile-card-gradient {
            background: linear-gradient(135deg, var(--accent) 0%, var(--accent-dark) 100%);
            border-radius: 16px;
            padding: 28px;
            color: #fff;
            border: none;
        }
        
        .profile-avatar-large {
            width: 72px;
            height: 72px;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 18px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.8rem;
            font-weight: 700;
            margin-bottom: 16px;
        }
        
        /* ===== MOBILE ===== */
        .mobile-menu-btn {
            display: none;
            position: fixed;
            top: 20px;
            left: 20px;
            z-index: 1001;
            background: var(--accent);
            border: none;
            color: #000;
            width: 48px;
            height: 48px;
            border-radius: 14px;
            font-size: 1.4rem;
            cursor: pointer;
            align-items: center;
            justify-content: center;
            box-shadow: 0 4px 20px var(--accent-glow);
        }
        
        .overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.7);
            z-index: 999;
        }
        
        .overlay.show { display: block; }
        
        .mobile-nav {
            display: none;
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: var(--bg-sidebar);
            border-top: 1px solid var(--border);
            padding: 10px 0;
            z-index: 1000;
        }
        
        .mobile-nav-items {
            display: flex;
            justify-content: space-around;
        }
        
        .mobile-nav-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 8px 16px;
            color: var(--text-muted);
            text-decoration: none;
            font-size: 0.7rem;
        }
        
        .mobile-nav-item.active { color: var(--accent); }
        .mobile-nav-item i { font-size: 1.4rem; margin-bottom: 4px; }
        
        @media (max-width: 1200px) {
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
        }
        
        @media (max-width: 991px) {
            .sidebar { transform: translateX(-100%); }
            .sidebar.show { transform: translateX(0); }
            .main-content { margin-left: 0; padding: 80px 20px 100px; }
            .mobile-menu-btn { display: flex; }
            .mobile-nav { display: block; }
        }
        
        @media (max-width: 576px) {
            .stats-grid { grid-template-columns: 1fr 1fr; gap: 12px; }
            .stat-card { padding: 18px; }
            .stat-value { font-size: 1.5rem; }
            .page-header { flex-direction: column; align-items: flex-start; gap: 16px; }
            .page-header .btn-primary-custom { width: 100%; justify-content: center; }
        }
    </style>
</head>
<body>
    <!-- Mobile menu button -->
    <button class="mobile-menu-btn" onclick="toggleSidebar()">
        <i class="bi bi-list"></i>
    </button>
    
    <!-- Overlay -->
    <div class="overlay" onclick="toggleSidebar()"></div>
    
    <!-- Sidebar -->
    <aside class="sidebar">
        <div class="sidebar-brand">
            <h4>🎾 <span>Padel</span>Center</h4>
        </div>
        
        <nav class="sidebar-nav">
            <ul class="nav flex-column">
                <li class="nav-item">
                    <a href="{{ route('dashboard') }}" class="nav-link {{ request()->routeIs('dashboard') ? 'active' : '' }}">
                        <i class="bi bi-house"></i>
                        <span>Главная</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="{{ route('profile.show') }}" class="nav-link {{ request()->routeIs('profile.*') ? 'active' : '' }}">
                        <i class="bi bi-person"></i>
                        <span>Профиль</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="#" class="nav-link">
                        <i class="bi bi-trophy"></i>
                        <span>Турниры</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="#" class="nav-link">
                        <i class="bi bi-bar-chart"></i>
                        <span>Рейтинг</span>
                    </a>
                </li>
                
                @if(auth()->user()->isClubAdmin() || auth()->user()->isSuperAdmin())
                    <li class="nav-section-title">Админ клуба</li>
                    <li class="nav-item">
                        <a href="{{ route('club.dashboard') }}" class="nav-link {{ request()->routeIs('club.*') ? 'active' : '' }}">
                            <i class="bi bi-building"></i>
                            <span>Мой клуб</span>
                        </a>
                    </li>
                @endif
                
                @if(auth()->user()->isSuperAdmin())
                    <li class="nav-section-title">Супер-админ</li>
                    <li class="nav-item">
                        <a href="{{ route('admin.dashboard') }}" class="nav-link {{ request()->routeIs('admin.dashboard') ? 'active' : '' }}">
                            <i class="bi bi-speedometer2"></i>
                            <span>Панель</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="{{ route('admin.clubs.index') }}" class="nav-link {{ request()->routeIs('admin.clubs.*') ? 'active' : '' }}">
                            <i class="bi bi-buildings"></i>
                            <span>Клубы</span>
                        </a>
                    </li>
                @endif
            </ul>
        </nav>
        
        <div class="sidebar-footer">
            <div class="user-card">
                <div class="user-avatar">{{ strtoupper(substr(auth()->user()->first_name, 0, 1) . substr(auth()->user()->last_name, 0, 1)) }}</div>
                <div class="user-info">
                    <div class="user-name">{{ auth()->user()->full_name }}</div>
                    <div class="user-role">
                        @if(auth()->user()->isSuperAdmin()) Супер-админ
                        @elseif(auth()->user()->isClubAdmin()) Админ клуба
                        @else Игрок @endif
                    </div>
                </div>
            </div>
            <form action="{{ route('logout') }}" method="POST">
                @csrf
                <button type="submit" class="btn-logout">
                    <i class="bi bi-box-arrow-left"></i>
                    <span>Выйти</span>
                </button>
            </form>
        </div>
    </aside>
    
    <!-- Main content -->
    <main class="main-content">
        @if(session('success'))
            <div class="alert-success-custom mb-4 d-flex justify-content-between align-items-center">
                {{ session('success') }}
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="alert"></button>
            </div>
        @endif
        
        @if(session('error'))
            <div class="alert-danger-custom mb-4 d-flex justify-content-between align-items-center">
                {{ session('error') }}
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="alert"></button>
            </div>
        @endif
        
        @yield('content')
    </main>
    
    <!-- Mobile bottom nav -->
    <nav class="mobile-nav">
        <div class="mobile-nav-items">
            <a href="{{ route('dashboard') }}" class="mobile-nav-item {{ request()->routeIs('dashboard') ? 'active' : '' }}">
                <i class="bi bi-house"></i>
                Главная
            </a>
            <a href="#" class="mobile-nav-item">
                <i class="bi bi-trophy"></i>
                Турниры
            </a>
            <a href="#" class="mobile-nav-item">
                <i class="bi bi-bar-chart"></i>
                Рейтинг
            </a>
            <a href="{{ route('profile.show') }}" class="mobile-nav-item {{ request()->routeIs('profile.*') ? 'active' : '' }}">
                <i class="bi bi-person"></i>
                Профиль
            </a>
        </div>
    </nav>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function toggleSidebar() {
            document.querySelector('.sidebar').classList.toggle('show');
            document.querySelector('.overlay').classList.toggle('show');
        }
    </script>
</body>
</html>