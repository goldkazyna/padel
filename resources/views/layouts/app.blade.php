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
            --bg-primary: #0c0e0f;
            --bg-secondary: #131619;
            --bg-sidebar: #0c0e0f;
            --bg-card: #15181a;
            --bg-card-hover: #1c2023;
            --accent: #22c55e;
            --accent-dark: #16a34a;
            --accent-glow: rgba(34, 197, 94, 0.15);
            --text-primary: #ffffff;
            --text-secondary: #9ca3af;
            --text-muted: #6b7280;
            --border: #2d2d2d;
            --border-light: #3d3d3d;
        }

        /* Светлая тема */
        body.light-theme {
            --bg-primary: #f5f5f5;
            --bg-secondary: #ffffff;
            --bg-sidebar: #ffffff;
            --bg-card: #ffffff;
            --bg-card-hover: #f0f0f0;
            --accent: #16a34a;
            --accent-dark: #15803d;
            --accent-glow: rgba(22, 163, 74, 0.15);
            --text-primary: #1a1a1a;
            --text-secondary: #666666;
            --text-muted: #999999;
            --border: #e0e0e0;
            --border-light: #d0d0d0;
        }
        
        * { box-sizing: border-box; }
        
        body {
            background:
                radial-gradient(1200px 900px at 68% -12%, rgba(34,197,94,0.09), rgba(34,197,94,0) 55%),
                var(--bg-primary);
            background-attachment: fixed;
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
            height: 100dvh; /* учитывает панели браузера на мобильных */
            background: var(--bg-sidebar);
            border-right: 1px solid var(--border);
            padding: 24px 0;
            z-index: 1000;
            transition: all 0.3s ease;
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
        .sidebar-brand-img {
            display: block;
            width: 100%;
            max-width: 100px;
            height: auto;
            margin: 0 auto;
            background: #ffffff;
            border-radius: 14px;
            padding: 12px 16px;
        }
        
        .sidebar-nav {
            flex: 1;
            padding: 0 12px;
            overflow-y: auto;
            scrollbar-width: thin;
            scrollbar-color: rgba(255,255,255,0.12) transparent;
        }
        .sidebar-nav::-webkit-scrollbar { width: 6px; }
        .sidebar-nav::-webkit-scrollbar-track { background: transparent; }
        .sidebar-nav::-webkit-scrollbar-thumb {
            background: rgba(255,255,255,0.12);
            border-radius: 3px;
        }
        .sidebar-nav:hover::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.22); }
        .sidebar-nav::-webkit-scrollbar-thumb:hover { background: rgba(255,255,255,0.3); }
        
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

        .unprocessed-badge {
            position: absolute;
            top: 6px;
            right: 10px;
            min-width: 20px;
            height: 20px;
            padding: 0 6px;
            background: #ef4444;
            color: #fff;
            font-size: 11px;
            font-weight: 700;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            line-height: 1;
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
            flex-shrink: 0;
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
            transition: margin-left 0.3s ease;
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

        .badge-warning-custom {
            background: rgba(234, 179, 8, 0.15);
            color: #eab308;
            padding: 6px 12px;
            border-radius: 6px;
            font-weight: 500;
        }

        .badge-primary-custom {
            background: rgba(59, 130, 246, 0.15);
            color: #3b82f6;
            padding: 6px 12px;
            border-radius: 6px;
            font-weight: 500;
        }

        .badge-info-custom {
            background: rgba(6, 182, 212, 0.15);
            color: #06b6d4;
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

        /* ===== THEME TOGGLE ===== */
        .btn-theme-toggle {
            background: var(--bg-secondary);
            border: 1px solid var(--border);
            color: var(--text-secondary);
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.3s;
            margin-bottom: 12px;
        }

        .btn-theme-toggle:hover {
            background: var(--bg-card-hover);
            color: var(--accent);
            border-color: var(--accent);
        }
		/* ===== SETTINGS BUTTONS ===== */
		.settings-buttons {
			display: flex;
			gap: 8px;
			margin-bottom: 12px;
		}

		.font-size-controls {
			display: flex;
			gap: 4px;
		}

		.btn-font-size {
			background: var(--bg-secondary);
			border: 1px solid var(--border);
			color: var(--text-secondary);
			width: 40px;
			height: 40px;
			border-radius: 10px;
			display: flex;
			align-items: center;
			justify-content: center;
			cursor: pointer;
			transition: all 0.3s;
			font-size: 1.2rem;
		}

		.btn-font-size:hover {
			background: var(--bg-card-hover);
			color: var(--accent);
			border-color: var(--accent);
		}
        /* ===== SIDEBAR TOGGLE (Desktop) ===== */
        .sidebar-toggle {
            position: absolute;
            top: 20px;
            right: -12px;
            width: 24px;
            height: 24px;
            background: var(--accent);
            border: none;
            border-radius: 50%;
            color: white;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            transition: all 0.3s;
            z-index: 100;
        }

        .sidebar-toggle:hover {
            background: var(--accent-dark);
            transform: scale(1.1);
        }

        /* ===== SIDEBAR COLLAPSED (Desktop only) ===== */
        body.sidebar-collapsed .sidebar {
            width: 70px;
        }

        body.sidebar-collapsed .main-content {
            margin-left: 70px;
        }

        body.sidebar-collapsed .sidebar span,
        body.sidebar-collapsed .sidebar .user-info,
        body.sidebar-collapsed .sidebar .user-name,
        body.sidebar-collapsed .sidebar .user-role,
        body.sidebar-collapsed .sidebar-brand span,
        body.sidebar-collapsed .nav-section-title {
            display: none !important;
        }

        body.sidebar-collapsed .sidebar-brand {
            padding: 0 8px 28px;
            text-align: center;
        }
        body.sidebar-collapsed .sidebar-brand-img {
            max-width: 50px;
            padding: 6px;
            border-radius: 10px;
        }

        body.sidebar-collapsed .nav-link {
            justify-content: center;
            padding: 14px 10px;
        }

        body.sidebar-collapsed .nav-link i {
            margin-right: 0;
            font-size: 1.3rem;
        }

        body.sidebar-collapsed .user-card {
            justify-content: center;
            padding: 10px;
        }

        body.sidebar-collapsed .btn-logout,
        body.sidebar-collapsed .btn-theme-toggle {
            justify-content: center;
            width: 100%;
        }

        body.sidebar-collapsed .sidebar-toggle i {
            transform: rotate(180deg);
        }

        body.sidebar-collapsed .sidebar-footer {
            padding: 10px;
            margin: 0 5px;
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
        
        /* ===== MOBILE BREAKPOINT ===== */
        @media (max-width: 991px) {
            /* Sidebar скрыт по умолчанию */
            .sidebar {
                transform: translateX(-100%);
                width: 260px !important; /* Всегда полная ширина на мобильных */
                overflow-y: auto; /* весь сайдбар скроллится — чтобы достать «Выйти» */
                -webkit-overflow-scrolling: touch;
            }
            /* На мобильных не отдельный скролл навигации, а скролл всего сайдбара */
            .sidebar-nav {
                flex: 0 0 auto;
                overflow-y: visible;
            }
            .sidebar-footer {
                margin-top: 0; /* не прибиваем к низу — пусть идёт сразу под меню */
            }
            .sidebar.show { 
                transform: translateX(0); 
            }
            
            /* Контент на всю ширину */
            .main-content { 
                margin-left: 0 !important; /* Переопределяем collapsed */
                padding: 80px 20px 100px; 
            }
            
            /* Показываем мобильные элементы */
            .mobile-menu-btn { display: flex; }
            .mobile-nav { display: block; }
            
            /* Скрываем кнопку сворачивания на мобильных */
            .sidebar-toggle { display: none; }
            
            /* Отменяем collapsed стили на мобильных */
            body.sidebar-collapsed .sidebar {
                width: 260px;
            }
            
            body.sidebar-collapsed .sidebar span,
            body.sidebar-collapsed .sidebar .user-info,
            body.sidebar-collapsed .sidebar .user-name,
            body.sidebar-collapsed .sidebar .user-role,
            body.sidebar-collapsed .sidebar-brand span,
            body.sidebar-collapsed .nav-section-title {
                display: block !important;
            }
            
            body.sidebar-collapsed .sidebar-brand {
                padding: 0 24px 28px;
                text-align: left;
            }
            
            body.sidebar-collapsed .nav-link {
                justify-content: flex-start;
                padding: 14px 16px;
            }
            
            body.sidebar-collapsed .user-card {
                justify-content: flex-start;
                padding: 12px;
            }
            
            body.sidebar-collapsed .sidebar-footer {
                padding: 20px 16px;
                margin: 0 12px;
            }
        }
        
        @media (max-width: 576px) {
            .stats-grid { grid-template-columns: 1fr 1fr; gap: 12px; }
            .stat-card { padding: 18px; }
            .stat-value { font-size: 1.5rem; }
            .page-header { flex-direction: column; align-items: flex-start; gap: 16px; }
            .page-header .btn-primary-custom { width: 100%; justify-content: center; }
        }
		/* ===== FIX FOR CHROME iOS ===== */
		html {
			overflow: hidden;
			height: 100%;
		}

		body {
			overflow: auto;
			height: 100%;
			overscroll-behavior: none;
			-webkit-overflow-scrolling: touch;
		}

		.main-content {
			height: 100dvh; /* Добавь эту строку после существующих стилей main-content */
			overflow-y: auto;
			overscroll-behavior: contain;
			-webkit-overflow-scrolling: touch;
		}

		.mobile-nav {
			/* Замени существующий .mobile-nav на этот: */
			display: none;
			position: fixed;
			bottom: 0;
			left: 0;
			right: 0;
			background: var(--bg-sidebar);
			border-top: 1px solid var(--border);
			padding: 10px 0;
			padding-bottom: calc(10px + env(safe-area-inset-bottom, 0px));
			z-index: 1000;
			transform: translateZ(0);
			-webkit-transform: translateZ(0);
		}

		@media (max-width: 991px) {
			.main-content {
				padding-bottom: calc(80px + env(safe-area-inset-bottom, 0px)) !important;
			}
		}
		/* ===== PLAYER NAME & FONT SIZES ===== */
		.player-name {
			font-size: 18px;
		}

		.player-line {
			font-size: 26px;
		}

		.match-court-header {
			font-size: 24px;
		}

		.score-input {
			font-size: 34px;
		}

		.team-score {
			font-size: 40px;
		}

		.team-name {
			font-size: 18px;
		}

		.match-team {
			font-size: 15px !important;
		}

		.score-left, .score-right {
			font-size: 22px !important;
		}

		.standings-table td {
			font-size: 15px;
		}

		.court-name {
			font-size: 14px !important;
		}
    </style>
    @stack('styles')
</head>
<body>
    <!-- Mobile menu button -->
    <button class="mobile-menu-btn" onclick="toggleMobileMenu()">
        <i class="bi bi-list"></i>
    </button>
    
    <!-- Overlay -->
    <div class="overlay" onclick="toggleMobileMenu()"></div>
    
    <!-- Sidebar -->
    <aside class="sidebar">
        <button type="button" class="sidebar-toggle" onclick="toggleSidebarCollapse()" title="Свернуть меню">
            <i class="bi bi-chevron-left" id="sidebar-toggle-icon"></i>
        </button>
        <div class="sidebar-brand">
            <img src="{{ asset('images/padel-logo.png') }}" alt="Padel KZ" class="sidebar-brand-img">
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
				@if(!auth()->user()->isClubModerator())
				<li class="nav-item">
					<a href="{{ route('tournaments.index') }}" class="nav-link {{ request()->routeIs('tournaments.*') ? 'active' : '' }}">
						<i class="bi bi-trophy"></i>
						<span>Турниры</span>
					</a>
				</li>
				<li class="nav-item">
					<a href="{{ route('rating.index') }}" class="nav-link {{ request()->routeIs('rating.*') ? 'active' : '' }}">
						<i class="bi bi-bar-chart"></i>
						<span>Рейтинг</span>
					</a>
				</li>
				@endif

                @if(auth()->user()->isCoach())
                    <li class="nav-item">
                        <a href="{{ route('coach.schedule') }}" class="nav-link {{ request()->routeIs('coach.*') ? 'active' : '' }}">
                            <i class="bi bi-calendar-week"></i>
                            <span>Расписание</span>
                        </a>
                    </li>
                @endif

                @if(auth()->user()->isClubModerator())
					@php($modClub = auth()->user()->moderatorClubs()->first())
					<li class="nav-section-title">Работа клуба</li>
					@if(!$modClub || $modClub->hasFeature('courts'))
					<li class="nav-item">
						<a href="{{ route('club.courts.schedule') }}" class="nav-link {{ request()->routeIs('club.courts.*') ? 'active' : '' }}" style="position:relative;">
							<i class="bi bi-grid-3x3"></i>
							<span>Корты</span>
							<span class="unprocessed-badge" id="unprocessedBadgeMod" style="display:none;"></span>
						</a>
					</li>
					@endif
					@if(!$modClub || $modClub->hasFeature('coaches'))
					<li class="nav-item">
						<a href="{{ route('club.coaches.index') }}" class="nav-link {{ request()->routeIs('club.coaches.*') ? 'active' : '' }}">
							<i class="bi bi-person-badge"></i>
							<span>Тренеры</span>
						</a>
					</li>
					@endif
					@if(!$modClub || $modClub->hasFeature('groups'))
					<li class="nav-item">
						<a href="{{ route('club.groups.index') }}" class="nav-link {{ request()->routeIs('club.groups.*') ? 'active' : '' }}">
							<i class="bi bi-people"></i>
							<span>Группы</span>
						</a>
					</li>
					<li class="nav-item">
						<a href="{{ route('club.groupSessions.index') }}" class="nav-link {{ request()->routeIs('club.groupSessions.*') ? 'active' : '' }}" style="position:relative;">
							<i class="bi bi-journal-check"></i>
							<span>Журнал занятий</span>@if(($__gsp = \App\Models\ClubGroupSession::pendingConductCountForCurrentUser()) > 0)<span class="unprocessed-badge">{{ $__gsp }}</span>@endif
						</a>
					</li>
					@endif
					<li class="nav-section-title">Клиенты и турниры</li>
					@if(!$modClub || $modClub->hasFeature('clients'))
					<li class="nav-item">
						<a href="{{ route('club.clients.index') }}" class="nav-link {{ request()->routeIs('club.clients.*') ? 'active' : '' }}">
							<i class="bi bi-person-lines-fill"></i>
							<span>Клиенты</span>
						</a>
					</li>
					@endif
					<li class="nav-item">
						@php($cardsPendingMod = ($__cc = $modClub) ? app(\App\Services\ClubCardService::class)->pendingCountForClub($__cc) : 0)
						<a href="{{ route('club.cards.index') }}" class="nav-link {{ request()->routeIs('club.cards.*') || request()->routeIs('club.cardTypes.*') ? 'active' : '' }}" style="position:relative;">
							<i class="bi bi-credit-card-2-front"></i>
							<span>Клубные карты</span>
							@if($cardsPendingMod > 0)<span class="unprocessed-badge">{{ $cardsPendingMod }}</span>@endif
						</a>
					</li>
					@if(!$modClub || $modClub->hasFeature('tournaments'))
					<li class="nav-item">
						<a href="{{ route('club.tournaments.index') }}" class="nav-link {{ request()->routeIs('club.tournaments.*') ? 'active' : '' }}">
							<i class="bi bi-trophy"></i>
							<span>Турниры клуба</span>
						</a>
					</li>
					@endif
					<li class="nav-section-title">Управление</li>
					@if($modClub && $modClub->hasFeature('activity_log') && auth()->user()->canViewActivityLog($modClub))
					<li class="nav-item">
						<a href="{{ route('club.activityLog') }}" class="nav-link {{ request()->routeIs('club.activityLog') ? 'active' : '' }}">
							<i class="bi bi-clock-history"></i>
							<span>Журнал</span>
						</a>
					</li>
					@endif
						<li class="nav-item">
							<a href="{{ route('club.help.index') }}" class="nav-link {{ request()->routeIs('club.help.*') ? 'active' : '' }}">
								<i class="bi bi-question-circle"></i>
								<span>Помощь</span>
							</a>
						</li>
				@elseif(auth()->user()->isClubAdmin() || auth()->user()->isSuperAdmin())
					@php($navClub = auth()->user()->isSuperAdmin() ? null : auth()->user()->adminClubs()->first())
					<li class="nav-section-title">Работа клуба</li>
					@if(!$navClub || $navClub->hasFeature('courts'))
					<li class="nav-item">
						<a href="{{ route('club.courts.schedule') }}" class="nav-link {{ request()->routeIs('club.courts.*') ? 'active' : '' }}" style="position:relative;">
							<i class="bi bi-grid-3x3"></i>
							<span>Корты</span>
							<span class="unprocessed-badge" id="unprocessedBadge" style="display:none;"></span>
						</a>
					</li>
					@endif
					@if(!$navClub || $navClub->hasFeature('coaches'))
					<li class="nav-item">
						<a href="{{ route('club.coaches.index') }}" class="nav-link {{ request()->routeIs('club.coaches.*') ? 'active' : '' }}">
							<i class="bi bi-person-badge"></i>
							<span>Тренеры</span>
						</a>
					</li>
					@endif
					@if(!$navClub || $navClub->hasFeature('groups'))
					<li class="nav-item">
						<a href="{{ route('club.groups.index') }}" class="nav-link {{ request()->routeIs('club.groups.*') ? 'active' : '' }}">
							<i class="bi bi-people"></i>
							<span>Группы</span>
						</a>
					</li>
					<li class="nav-item">
						<a href="{{ route('club.groupSessions.index') }}" class="nav-link {{ request()->routeIs('club.groupSessions.*') ? 'active' : '' }}" style="position:relative;">
							<i class="bi bi-journal-check"></i>
							<span>Журнал занятий</span>@if(($__gsp = \App\Models\ClubGroupSession::pendingConductCountForCurrentUser()) > 0)<span class="unprocessed-badge">{{ $__gsp }}</span>@endif
						</a>
					</li>
					@endif
					<li class="nav-section-title">Клиенты и турниры</li>
					@if(!$navClub || $navClub->hasFeature('clients'))
					<li class="nav-item">
						<a href="{{ route('club.clients.index') }}" class="nav-link {{ request()->routeIs('club.clients.*') ? 'active' : '' }}">
							<i class="bi bi-person-lines-fill"></i>
							<span>Клиенты</span>
						</a>
					</li>
					@endif
					@if(!$navClub || $navClub->hasFeature('users'))
					<li class="nav-item">
						<a href="{{ route('club.users.index') }}" class="nav-link {{ request()->routeIs('club.users.*') ? 'active' : '' }}">
							<i class="bi bi-people"></i>
							<span>Пользователи</span>
						</a>
					</li>
					@endif
					<li class="nav-item">
						@php($cardsPendingNav = ($__cc = $navClub ?? (auth()->user()->isSuperAdmin() ? \App\Models\Club::first() : null)) ? app(\App\Services\ClubCardService::class)->pendingCountForClub($__cc) : 0)
						<a href="{{ route('club.cards.index') }}" class="nav-link {{ request()->routeIs('club.cards.*') || request()->routeIs('club.cardTypes.*') ? 'active' : '' }}" style="position:relative;">
							<i class="bi bi-credit-card-2-front"></i>
							<span>Клубные карты</span>
							@if($cardsPendingNav > 0)<span class="unprocessed-badge">{{ $cardsPendingNav }}</span>@endif
						</a>
					</li>
					@if(!$navClub || $navClub->hasFeature('tournaments'))
					<li class="nav-item">
						<a href="{{ route('club.tournaments.index') }}" class="nav-link {{ request()->routeIs('club.tournaments.*') ? 'active' : '' }}">
							<i class="bi bi-trophy"></i>
							<span>Турниры клуба</span>
						</a>
					</li>
					@endif
					<li class="nav-section-title">Управление</li>
					<li class="nav-item">
						<a href="{{ route('club.reports.index') }}" class="nav-link {{ request()->routeIs('club.reports.*') ? 'active' : '' }}">
							<i class="bi bi-bar-chart-line"></i>
							<span>Отчёты</span>
						</a>
					</li>
					<li class="nav-item">
						<a href="{{ route('club.settings') }}" class="nav-link {{ request()->routeIs('club.settings') ? 'active' : '' }}">
							<i class="bi bi-gear"></i>
							<span>Настройки</span>
						</a>
					</li>
					@if(!$navClub || $navClub->hasFeature('moderators'))
					<li class="nav-item">
						<a href="{{ route('club.moderators.index') }}" class="nav-link {{ request()->routeIs('club.moderators.*') ? 'active' : '' }}">
							<i class="bi bi-people"></i>
							<span>Менеджеры / Модераторы</span>
						</a>
					</li>
					@endif
					@if((!$navClub) || ($navClub->hasFeature('activity_log') && auth()->user()->canViewActivityLog($navClub)))
					<li class="nav-item">
						<a href="{{ route('club.activityLog') }}" class="nav-link {{ request()->routeIs('club.activityLog') ? 'active' : '' }}">
							<i class="bi bi-clock-history"></i>
							<span>Журнал</span>
						</a>
					</li>
					@endif
						<li class="nav-item">
							<a href="{{ route('club.help.index') }}" class="nav-link {{ request()->routeIs('club.help.*') ? 'active' : '' }}">
								<i class="bi bi-question-circle"></i>
								<span>Помощь</span>
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
                    <li class="nav-item">
                        <a href="{{ route('admin.banners.index') }}" class="nav-link {{ request()->routeIs('admin.banners.*') ? 'active' : '' }}">
                            <i class="bi bi-megaphone"></i>
                            <span>Рекламный баннер</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="{{ route('admin.tickets.index') }}" class="nav-link {{ request()->routeIs('admin.tickets.*') ? 'active' : '' }}">
                            <i class="bi bi-life-preserver"></i>
                            <span>Тикеты</span>
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
            <div class="settings-buttons">
				<button type="button" class="btn-theme-toggle" onclick="toggleTheme()" title="Переключить тему">
					<i class="bi bi-sun-fill" id="theme-icon-light" style="display: none;"></i>
					<i class="bi bi-moon-fill" id="theme-icon-dark"></i>
				</button>
				<div class="font-size-controls">
					<button type="button" class="btn-font-size" onclick="changeFontSize(-1)" title="Уменьшить шрифт">
						<i class="bi bi-dash"></i>
					</button>
					<button type="button" class="btn-font-size" onclick="changeFontSize(1)" title="Увеличить шрифт">
						<i class="bi bi-plus"></i>
					</button>
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
            <a href="{{ route('tournaments.index') }}" class="mobile-nav-item {{ request()->routeIs('tournaments.*') ? 'active' : '' }}">
                <i class="bi bi-trophy"></i>
                Турниры
            </a>
            <a href="{{ route('rating.index') }}" class="mobile-nav-item {{ request()->routeIs('rating.*') ? 'active' : '' }}">
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
    // ===== MOBILE MENU (показ/скрытие sidebar на мобильных) =====
    function toggleMobileMenu() {
        document.querySelector('.sidebar').classList.toggle('show');
        document.querySelector('.overlay').classList.toggle('show');
    }

    // ===== SIDEBAR COLLAPSE (сворачивание на десктопе) =====
    function toggleSidebarCollapse() {
        const body = document.body;
        const isCollapsed = body.classList.toggle('sidebar-collapsed');
        localStorage.setItem('sidebarCollapsed', isCollapsed ? 'true' : 'false');
    }

    // ===== THEME TOGGLE =====
    function toggleTheme() {
        const body = document.body;
        const isLight = body.classList.toggle('light-theme');
        localStorage.setItem('theme', isLight ? 'light' : 'dark');
        updateThemeIcon(isLight);
    }

    function updateThemeIcon(isLight) {
        document.getElementById('theme-icon-light').style.display = isLight ? 'block' : 'none';
        document.getElementById('theme-icon-dark').style.display = isLight ? 'none' : 'block';
    }
	// ===== FONT SIZE CONTROL =====
	function changeFontSize(delta) {
		const currentSize = parseInt(localStorage.getItem('playerNameFontSize')) || 18;
		const newSize = Math.min(Math.max(currentSize + delta, 12), 32); // Мин 12px, макс 28px
		
		localStorage.setItem('playerNameFontSize', newSize);
		applyFontSize(newSize);
	}

	function applyFontSize(size) {
    // Базовый размер 18px, size - текущий выбранный
    const diff = size - 18;
    
    // .player-name базовый 18px
    document.querySelectorAll('.player-name').forEach(el => {
        el.style.fontSize = size + 'px';
    });
    
    // .player-line базовый 26px
    document.querySelectorAll('.player-line').forEach(el => {
        el.style.fontSize = (26 + diff) + 'px';
    });
    
    // .match-court-header базовый 24px
    document.querySelectorAll('.match-court-header').forEach(el => {
        el.style.fontSize = (24 + diff) + 'px';
    });
    
    // .score-input базовый 34px
    document.querySelectorAll('.score-input').forEach(el => {
        el.style.fontSize = (34 + diff) + 'px';
    });
    
    // .team-score базовый 40px
    document.querySelectorAll('.team-score').forEach(el => {
        el.style.fontSize = (40 + diff) + 'px';
    });
    
    // .team-name базовый 18px
    document.querySelectorAll('.team-name').forEach(el => {
        el.style.fontSize = size + 'px';
    });
    
    // .match-team базовый 15px (с !important)
    document.querySelectorAll('.match-team').forEach(el => {
        el.style.setProperty('font-size', (15 + diff) + 'px', 'important');
    });
    
    // .score-left, .score-right базовый 22px (с !important)
    document.querySelectorAll('.score-left, .score-right').forEach(el => {
        el.style.setProperty('font-size', (22 + diff) + 'px', 'important');
    });
    
    // .standings-table td базовый 15px
    document.querySelectorAll('.standings-table td').forEach(el => {
        el.style.fontSize = (15 + diff) + 'px';
    });
    
    // .court-name базовый 14px (с !important)
    document.querySelectorAll('.court-name').forEach(el => {
        el.style.setProperty('font-size', (14 + diff) + 'px', 'important');
    });
}
    // ===== APPLY SAVED SETTINGS ON LOAD =====
    document.addEventListener('DOMContentLoaded', function() {
		// Theme
		const savedTheme = localStorage.getItem('theme');
		if (savedTheme === 'light') {
			document.body.classList.add('light-theme');
			updateThemeIcon(true);
		}
		
		// Sidebar collapsed (только на десктопе)
		const savedSidebar = localStorage.getItem('sidebarCollapsed');
		if (savedSidebar === 'true' && window.innerWidth > 991) {
			document.body.classList.add('sidebar-collapsed');
		}
		
		// Font size
		const savedFontSize = localStorage.getItem('playerNameFontSize');
		if (savedFontSize) {
			applyFontSize(parseInt(savedFontSize));
		}
	});

	// Polling необработанных бронирований
	@if(auth()->check() && (auth()->user()->isClubAdmin() || auth()->user()->isSuperAdmin() || auth()->user()->isClubModerator()))
	function checkUnprocessed() {
		fetch('{{ route("club.unprocessedCount") }}', { headers: { 'X-Requested-With': 'XMLHttpRequest' }, cache: 'no-store' })
			.then(r => r.ok ? r.json() : null)
			.then(data => {
				if (!data) return;
				['unprocessedBadge', 'unprocessedBadgeMod'].forEach(id => {
					const badge = document.getElementById(id);
					if (badge) {
						if (data.count > 0) {
							badge.textContent = data.count;
							badge.style.display = 'flex';
						} else {
							badge.style.display = 'none';
						}
					}
				});
			})
			.catch(() => {});
	}
	checkUnprocessed();
	setInterval(checkUnprocessed, 30000);
	@endif
    </script>

    @stack('scripts')
    @yield('modals')
</body>
</html>