<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Восстановление пароля · {{ config('app.name', 'Padel KZ') }}</title>
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
        .card-title{font-size:19px;font-weight:800;letter-spacing:-.2px;margin:0 0 6px;text-align:center}
        .card-lead{font-size:13.5px;color:var(--mut);text-align:center;margin:0 0 20px;line-height:1.45}

        .fg{margin-bottom:16px}
        .lbl{display:block;font-size:11.5px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--mut);margin-bottom:8px}
        .inp{width:100%;background:var(--field);border:1px solid var(--line);border-radius:11px;padding:13px 14px;
            font-size:15px;color:var(--ink);font-family:inherit;transition:border-color .15s,box-shadow .15s}
        .inp::placeholder{color:var(--mut2)}
        .inp:focus{outline:none;border-color:var(--accent);box-shadow:0 0 0 3px rgba(34,197,94,.16)}
        .inp.code{text-align:center;letter-spacing:8px;font-size:20px;font-weight:700}

        .btn{width:100%;border:none;border-radius:12px;padding:14px;background:var(--accent);color:var(--accent-ink);
            font-size:15px;font-weight:800;cursor:pointer;font-family:inherit;transition:background .15s}
        .btn:hover{background:#1eb257}
        .btn:disabled{opacity:.6;cursor:default}

        .msg{border-radius:11px;padding:11px 14px;font-size:13.5px;margin-bottom:16px;display:none}
        .msg.err{background:rgba(239,106,99,.1);border:1px solid rgba(239,106,99,.32);color:#f0938d;display:block}
        .msg.ok{background:rgba(34,197,94,.1);border:1px solid rgba(34,197,94,.3);color:#7fe0a0;display:block}

        .alt{margin-top:14px;text-align:center;font-size:13px}
        .alt a,.linkbtn{color:var(--mut);text-decoration:none;background:none;border:none;font-size:13px;cursor:pointer;font-family:inherit}
        .alt a:hover,.linkbtn:hover{color:var(--ink)}
        .hidden{display:none}
    </style>
</head>
<body>
    <div class="wrap">
        <div class="brand">
            <div class="brand-tile"><img src="{{ asset('images/padel-logo.png') }}" alt="Padel KZ"></div>
            <div class="brand-sub">Панель клуба</div>
        </div>

        <div class="card">
            <div id="msg" class="msg"></div>

            {{-- Шаг 1: телефон --}}
            <div id="step-phone">
                <h1 class="card-title">Восстановление пароля</h1>
                <p class="card-lead">Введите номер телефона — вышлем код в SMS.</p>
                <form id="formPhone">
                    <div class="fg">
                        <label class="lbl" for="phone">Телефон</label>
                        <input class="inp" id="phone" type="tel" name="phone" autocomplete="tel" placeholder="+7 (___) ___-__-__" required>
                    </div>
                    <button type="submit" class="btn" id="btnSend">Отправить код</button>
                </form>
                <div class="alt"><a href="{{ route('login') }}">← Вернуться ко входу</a></div>
            </div>

            {{-- Шаг 2: код из SMS --}}
            <div id="step-code" class="hidden">
                <h1 class="card-title">Введите код</h1>
                <p class="card-lead" id="sentTo">Мы отправили код в SMS.</p>
                <form id="formCode">
                    <div class="fg">
                        <label class="lbl" for="code">Код из SMS</label>
                        <input class="inp code" id="code" type="text" inputmode="numeric" maxlength="4" placeholder="0000" required>
                    </div>
                    <button type="submit" class="btn" id="btnVerify">Подтвердить</button>
                </form>
                <div class="alt"><button type="button" class="linkbtn" id="backToPhone">← Изменить номер</button></div>
            </div>

            {{-- Шаг 3: новый пароль --}}
            <div id="step-password" class="hidden">
                <h1 class="card-title">Новый пароль</h1>
                <p class="card-lead">Придумайте новый пароль для входа.</p>
                <form id="formReset">
                    <div class="fg">
                        <label class="lbl" for="password">Новый пароль</label>
                        <input class="inp" id="password" type="password" name="password" autocomplete="new-password" placeholder="Минимум 6 символов" required>
                    </div>
                    <div class="fg">
                        <label class="lbl" for="password_confirmation">Повторите пароль</label>
                        <input class="inp" id="password_confirmation" type="password" name="password_confirmation" autocomplete="new-password" placeholder="Ещё раз" required>
                    </div>
                    <button type="submit" class="btn" id="btnReset">Сменить пароль и войти</button>
                </form>
                <div class="alt"><button type="button" class="linkbtn" id="backToCode">← Назад к коду</button></div>
            </div>
        </div>
    </div>

    <script>
    const csrf = document.querySelector('meta[name=csrf-token]').content;
    const msg = document.getElementById('msg');
    let currentPhone = '';
    let currentCode = '';

    function showMsg(text, ok){ msg.textContent = text; msg.className = 'msg ' + (ok ? 'ok' : 'err'); }
    function clearMsg(){ msg.className = 'msg'; msg.textContent = ''; }
    function goStep(id){
        ['step-phone','step-code','step-password'].forEach(s => document.getElementById(s).classList.add('hidden'));
        document.getElementById(id).classList.remove('hidden');
    }

    async function post(url, data){
        const r = await fetch(url, {
            method:'POST',
            headers:{ 'X-CSRF-TOKEN':csrf, 'Accept':'application/json', 'Content-Type':'application/json' },
            body: JSON.stringify(data)
        });
        let j = {};
        try { j = await r.json(); } catch(e) {}
        return { ok:r.ok, data:j };
    }

    // Шаг 1 — отправить код
    document.getElementById('formPhone').addEventListener('submit', async function(e){
        e.preventDefault();
        clearMsg();
        const btn = document.getElementById('btnSend');
        btn.disabled = true; btn.textContent = 'Отправляем…';
        const res = await post('{{ route('password.phone.send') }}', { phone: document.getElementById('phone').value });
        btn.disabled = false; btn.textContent = 'Отправить код';
        if (res.ok && res.data.success) {
            currentPhone = document.getElementById('phone').value;
            goStep('step-code');
            document.getElementById('sentTo').textContent = res.data.message + '.';
            showMsg(res.data.message, true);
            document.getElementById('code').focus();
        } else {
            showMsg(res.data.message || 'Не удалось отправить код');
        }
    });

    // Шаг 2 — проверить код
    document.getElementById('formCode').addEventListener('submit', async function(e){
        e.preventDefault();
        clearMsg();
        const code = document.getElementById('code').value.trim();
        const btn = document.getElementById('btnVerify');
        btn.disabled = true; btn.textContent = 'Проверяем…';
        const res = await post('{{ route('password.phone.verify') }}', { phone: currentPhone, code });
        btn.disabled = false; btn.textContent = 'Подтвердить';
        if (res.ok && res.data.success) {
            currentCode = code;
            goStep('step-password');
            document.getElementById('password').focus();
        } else {
            showMsg(res.data.message || 'Неверный код');
        }
    });

    // Шаг 3 — сменить пароль
    document.getElementById('formReset').addEventListener('submit', async function(e){
        e.preventDefault();
        clearMsg();
        const p = document.getElementById('password').value;
        const p2 = document.getElementById('password_confirmation').value;
        if (p.length < 6) { showMsg('Пароль должен быть не короче 6 символов'); return; }
        if (p !== p2) { showMsg('Пароли не совпадают'); return; }
        const btn = document.getElementById('btnReset');
        btn.disabled = true; btn.textContent = 'Сохраняем…';
        const res = await post('{{ route('password.phone.reset') }}', {
            phone: currentPhone,
            code: currentCode,
            password: p,
            password_confirmation: p2
        });
        if (res.ok && res.data.success) {
            btn.textContent = 'Готово, входим…';
            window.location.href = res.data.redirect || '/';
        } else {
            btn.disabled = false; btn.textContent = 'Сменить пароль и войти';
            showMsg(res.data.message || 'Не удалось сменить пароль');
        }
    });

    document.getElementById('backToPhone').addEventListener('click', function(){ clearMsg(); goStep('step-phone'); });
    document.getElementById('backToCode').addEventListener('click', function(){ clearMsg(); goStep('step-code'); });

    // Маска телефона
    document.getElementById('phone').addEventListener('input', function(e){
        let v = e.target.value.replace(/\D/g, '');
        if (v.startsWith('8')) v = '7' + v.substring(1);
        let f = '';
        if (v.length > 0) f = '+' + v[0];
        if (v.length > 1) f += ' (' + v.substring(1, 4);
        if (v.length > 4) f += ') ' + v.substring(4, 7);
        if (v.length > 7) f += '-' + v.substring(7, 9);
        if (v.length > 9) f += '-' + v.substring(9, 11);
        e.target.value = f;
    });
    </script>
</body>
</html>
