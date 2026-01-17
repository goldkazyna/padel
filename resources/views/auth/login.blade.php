<x-guest-layout>
    <x-auth-session-status class="mb-4" :status="session('status')" />

    <!-- Переключатель -->
    <div class="flex mb-6 border-b border-gray-200">
        <button type="button" id="tab-phone" onclick="switchTab('phone')" class="flex-1 py-2 text-center border-b-2 border-indigo-500 text-indigo-600 font-medium">
            По телефону
        </button>
        <button type="button" id="tab-email" onclick="switchTab('email')" class="flex-1 py-2 text-center border-b-2 border-transparent text-gray-500 hover:text-gray-700">
            По email
        </button>
    </div>

    <form method="POST" action="{{ route('login') }}">
        @csrf
        
        <input type="hidden" name="login_type" id="login_type" value="phone">

        <!-- Телефон -->
        <div id="field-phone">
            <x-input-label for="phone" :value="__('Телефон')" />
            <x-text-input id="phone" class="block mt-1 w-full" type="tel" name="phone" :value="old('phone')" autocomplete="tel" placeholder="+7 (___) ___-__-__" />
            <x-input-error :messages="$errors->get('phone')" class="mt-2" />
        </div>

        <!-- Email -->
        <div id="field-email" style="display: none;">
            <x-input-label for="email" :value="__('Email')" />
            <x-text-input id="email" class="block mt-1 w-full" type="email" name="email" :value="old('email')" autocomplete="email" placeholder="example@mail.com" />
            <x-input-error :messages="$errors->get('email')" class="mt-2" />
        </div>

        <!-- Пароль -->
        <div class="mt-4">
            <x-input-label for="password" :value="__('Пароль')" />
            <x-text-input id="password" class="block mt-1 w-full" type="password" name="password" required autocomplete="current-password" />
            <x-input-error :messages="$errors->get('password')" class="mt-2" />
        </div>

        <!-- Запомнить меня -->
        <div class="block mt-4">
            <label for="remember_me" class="inline-flex items-center">
                <input id="remember_me" type="checkbox" class="rounded border-gray-300 text-indigo-600 shadow-sm focus:ring-indigo-500" name="remember">
                <span class="ms-2 text-sm text-gray-600">Запомнить меня</span>
            </label>
        </div>

        <div class="flex items-center justify-end mt-4">
            @if (Route::has('password.request'))
                <a class="underline text-sm text-gray-600 hover:text-gray-900 rounded-md" href="{{ route('password.request') }}">
                    Забыли пароль?
                </a>
            @endif

            <x-primary-button class="ms-3">
                Войти
            </x-primary-button>
        </div>
        
        <div class="mt-4 text-center">
            <a class="underline text-sm text-gray-600 hover:text-gray-900" href="{{ route('register') }}">
                Нет аккаунта? Зарегистрироваться
            </a>
        </div>
    </form>

    {{-- Telegram Login --}}
    <div class="mt-6 text-center">
        <p class="text-gray-500 mb-3">или</p>
        <script async src="https://telegram.org/js/telegram-widget.js?22"
                data-telegram-login="add_app_bot"
                data-size="large"
                data-radius="8"
                data-onauth="onTelegramAuth(user)"
                data-request-access="write">
        </script>
    </div>

    <script>
    function onTelegramAuth(user) {
        const params = new URLSearchParams(user).toString();
        window.location.href = "{{ route('auth.telegram.callback') }}?" + params;
    }
    
    function switchTab(type) {
        const tabPhone = document.getElementById('tab-phone');
        const tabEmail = document.getElementById('tab-email');
        const fieldPhone = document.getElementById('field-phone');
        const fieldEmail = document.getElementById('field-email');
        const loginType = document.getElementById('login_type');
        const phoneInput = document.getElementById('phone');
        const emailInput = document.getElementById('email');
        
        if (type === 'phone') {
            tabPhone.classList.add('border-indigo-500', 'text-indigo-600', 'font-medium');
            tabPhone.classList.remove('border-transparent', 'text-gray-500');
            tabEmail.classList.remove('border-indigo-500', 'text-indigo-600', 'font-medium');
            tabEmail.classList.add('border-transparent', 'text-gray-500');
            fieldPhone.style.display = 'block';
            fieldEmail.style.display = 'none';
            loginType.value = 'phone';
            phoneInput.required = true;
            emailInput.required = false;
        } else {
            tabEmail.classList.add('border-indigo-500', 'text-indigo-600', 'font-medium');
            tabEmail.classList.remove('border-transparent', 'text-gray-500');
            tabPhone.classList.remove('border-indigo-500', 'text-indigo-600', 'font-medium');
            tabPhone.classList.add('border-transparent', 'text-gray-500');
            fieldPhone.style.display = 'none';
            fieldEmail.style.display = 'block';
            loginType.value = 'email';
            phoneInput.required = false;
            emailInput.required = true;
        }
    }
    
    // Маска телефона
    document.getElementById('phone').addEventListener('input', function(e) {
        let value = e.target.value.replace(/\D/g, '');
        
        if (value.startsWith('8')) {
            value = '7' + value.substring(1);
        }
        
        let formatted = '';
        if (value.length > 0) {
            formatted = '+' + value[0];
        }
        if (value.length > 1) {
            formatted += ' (' + value.substring(1, 4);
        }
        if (value.length > 4) {
            formatted += ') ' + value.substring(4, 7);
        }
        if (value.length > 7) {
            formatted += '-' + value.substring(7, 9);
        }
        if (value.length > 9) {
            formatted += '-' + value.substring(9, 11);
        }
        
        e.target.value = formatted;
    });
    </script>
</x-guest-layout>