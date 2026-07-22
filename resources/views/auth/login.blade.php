<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Вход · {{ config('app.name', 'Padel KZ') }}</title>
    <style>
        :root{
            --bg:#0c0e0f; --card:#15181a; --line:rgba(255,255,255,.08); --line2:rgba(255,255,255,.16);
            --ink:#eef1f2; --mut:#8b9298; --mut2:#6b7278; --field:#0f1113;
            --accent:#22c55e; --accent-ink:#06210f; --danger:#ef6a63;
        }
        *{box-sizing:border-box}
        body{margin:0;min-height:100vh;background:var(--bg);color:var(--ink);
            font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;
            display:flex;flex-direction:column;align-items:center;justify-content:center;padding:32px 18px;
            -webkit-font-smoothing:antialiased;position:relative;overflow-x:hidden}
        body:before{content:"";position:fixed;top:-30%;left:50%;transform:translateX(-50%);
            width:900px;height:900px;border-radius:50%;pointer-events:none;
            background:radial-gradient(circle, rgba(34,197,94,.14), rgba(34,197,94,0) 62%);filter:blur(20px)}

        .wrap{position:relative;width:100%;max-width:410px}
        .brand{display:flex;flex-direction:column;align-items:center;margin-bottom:24px}
        .brand-tile{width:92px;height:92px;border-radius:22px;background:#fff;display:flex;align-items:center;justify-content:center;
            padding:16px;box-shadow:0 18px 48px -14px rgba(34,197,94,.45)}
        .brand-tile img{width:100%;height:100%;object-fit:contain;display:block}
        .brand-sub{margin-top:16px;font-size:13px;color:var(--mut);letter-spacing:.3px}

        .card{background:var(--card);border:1px solid var(--line);border-radius:20px;padding:26px 24px 24px;
            box-shadow:0 30px 70px -30px rgba(0,0,0,.8)}
        .card-title{font-size:19px;font-weight:800;letter-spacing:-.2px;margin:0 0 20px;text-align:center}

        .tabs{display:flex;background:var(--field);border:1px solid var(--line);border-radius:12px;padding:4px;margin-bottom:20px}
        .tab{flex:1;border:none;background:none;padding:9px 0;border-radius:9px;font-size:13.5px;font-weight:700;
            color:var(--mut);cursor:pointer;font-family:inherit;transition:all .15s}
        .tab.active{background:rgba(34,197,94,.14);color:#34d17f}

        .fg{margin-bottom:16px}
        .lbl{display:block;font-size:11.5px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--mut);margin-bottom:8px}
        .inp{width:100%;background:var(--field);border:1px solid var(--line);border-radius:11px;padding:13px 14px;
            font-size:15px;color:var(--ink);font-family:inherit;transition:border-color .15s,box-shadow .15s}
        .inp::placeholder{color:var(--mut2)}
        .inp:focus{outline:none;border-color:var(--accent);box-shadow:0 0 0 3px rgba(34,197,94,.16)}
        .err{color:var(--danger);font-size:12.5px;margin-top:7px}

        .row{display:flex;align-items:center;justify-content:space-between;margin:4px 0 20px}
        .chk{display:inline-flex;align-items:center;gap:8px;font-size:13px;color:var(--mut);cursor:pointer;user-select:none}
        .chk input{width:16px;height:16px;accent-color:var(--accent);cursor:pointer}
        .link{font-size:13px;color:var(--mut);text-decoration:none}
        .link:hover{color:var(--ink)}

        .btn{width:100%;border:none;border-radius:12px;padding:14px;background:var(--accent);color:var(--accent-ink);
            font-size:15px;font-weight:800;cursor:pointer;font-family:inherit;transition:background .15s}
        .btn:hover{background:#1eb257}

        .divider{display:flex;align-items:center;gap:12px;margin:22px 0 16px;color:var(--mut2);font-size:12.5px}
        .divider:before,.divider:after{content:"";flex:1;height:1px;background:var(--line)}
        .tg{display:flex;justify-content:center;min-height:44px}

        .flash{background:rgba(34,197,94,.1);border:1px solid rgba(34,197,94,.3);color:#7fe0a0;
            border-radius:11px;padding:11px 14px;font-size:13.5px;margin-bottom:18px}

        .foot{display:flex;flex-wrap:wrap;gap:16px;justify-content:center;margin-top:22px}
        .foot a{font-size:12px;color:var(--mut2);text-decoration:none}
        .foot a:hover{color:var(--mut)}
    </style>
</head>
<body>
    <div class="wrap">
        <div class="brand">
            <div class="brand-tile"><img src="{{ asset('images/padel-logo.png') }}" alt="Padel KZ"></div>
            <div class="brand-sub">Панель клуба</div>
        </div>

        <div class="card">
            <h1 class="card-title">Вход в аккаунт</h1>

            @if (session('status'))
                <div class="flash">{{ session('status') }}</div>
            @endif

            <div class="tabs">
                <button type="button" id="tab-phone" class="tab active" onclick="switchTab('phone')">По телефону</button>
                <button type="button" id="tab-email" class="tab" onclick="switchTab('email')">По email</button>
            </div>

            <form method="POST" action="{{ route('login') }}">
                @csrf
                <input type="hidden" name="login_type" id="login_type" value="phone">

                <div class="fg" id="field-phone">
                    <label class="lbl" for="phone">Телефон</label>
                    <input class="inp" id="phone" type="tel" name="phone" value="{{ old('phone') }}" autocomplete="tel" placeholder="+7 (___) ___-__-__">
                    @error('phone')<div class="err">{{ $message }}</div>@enderror
                </div>

                <div class="fg" id="field-email" style="display:none">
                    <label class="lbl" for="email">Email</label>
                    <input class="inp" id="email" type="email" name="email" value="{{ old('email') }}" autocomplete="email" placeholder="example@mail.com">
                    @error('email')<div class="err">{{ $message }}</div>@enderror
                </div>

                <div class="fg">
                    <label class="lbl" for="password">Пароль</label>
                    <input class="inp" id="password" type="password" name="password" required autocomplete="current-password" placeholder="••••••••">
                    @error('password')<div class="err">{{ $message }}</div>@enderror
                </div>

                <div class="row">
                    <label class="chk" for="remember_me"><input id="remember_me" type="checkbox" name="remember"> Запомнить меня</label>
                    @if (Route::has('password.request'))
                        <a class="link" href="{{ route('password.request') }}">Забыли пароль?</a>
                    @endif
                </div>

                <button type="submit" class="btn">Войти</button>
            </form>
        </div>

        <div class="foot">
            <a href="/terms">Пользовательское соглашение</a>
            <a href="/privacy-policy.html">Политика конфиденциальности</a>
            <a href="/consent">Обработка данных</a>
        </div>
    </div>

    <script>
    function switchTab(type) {
        const tabPhone = document.getElementById('tab-phone');
        const tabEmail = document.getElementById('tab-email');
        const fieldPhone = document.getElementById('field-phone');
        const fieldEmail = document.getElementById('field-email');
        const loginType = document.getElementById('login_type');
        const phoneInput = document.getElementById('phone');
        const emailInput = document.getElementById('email');

        if (type === 'phone') {
            tabPhone.classList.add('active'); tabEmail.classList.remove('active');
            fieldPhone.style.display = 'block'; fieldEmail.style.display = 'none';
            loginType.value = 'phone'; phoneInput.required = true; emailInput.required = false;
        } else {
            tabEmail.classList.add('active'); tabPhone.classList.remove('active');
            fieldPhone.style.display = 'none'; fieldEmail.style.display = 'block';
            loginType.value = 'email'; phoneInput.required = false; emailInput.required = true;
        }
    }

    // Маска телефона
    document.getElementById('phone').addEventListener('input', function(e) {
        let value = e.target.value.replace(/\D/g, '');
        if (value.startsWith('8')) value = '7' + value.substring(1);
        let f = '';
        if (value.length > 0) f = '+' + value[0];
        if (value.length > 1) f += ' (' + value.substring(1, 4);
        if (value.length > 4) f += ') ' + value.substring(4, 7);
        if (value.length > 7) f += '-' + value.substring(7, 9);
        if (value.length > 9) f += '-' + value.substring(9, 11);
        e.target.value = f;
    });
    </script>
</body>
</html>
