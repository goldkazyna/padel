<x-guest-layout>
    <div class="mb-4">
        <h2 class="text-lg font-semibold text-gray-800">Удаление аккаунта</h2>
        <p class="mt-1 text-sm text-gray-600">
            Введите email, привязанный к аккаунту. Мы отправим код подтверждения.
        </p>
    </div>

    @if (session('code_sent'))
        {{-- Шаг 2: ввод кода --}}
        <form method="POST" action="{{ route('delete-account.destroy') }}">
            @csrf
            @method('DELETE')

            <input type="hidden" name="email" value="{{ session('email') }}">

            <p class="text-sm text-green-700 bg-green-50 border border-green-200 rounded px-3 py-2 mb-4">
                Код отправлен на <strong>{{ session('email') }}</strong>. Проверьте почту.
            </p>

            <div>
                <x-input-label for="code" value="Код подтверждения" />
                <x-text-input
                    id="code"
                    class="block mt-1 w-full text-center tracking-widest text-xl"
                    type="text"
                    name="code"
                    inputmode="numeric"
                    maxlength="6"
                    placeholder="000000"
                    autofocus
                    required
                />
                <x-input-error :messages="$errors->get('code')" class="mt-2" />
            </div>

            <div class="mt-4 flex items-center justify-between">
                <a href="{{ route('delete-account') }}"
                   class="text-sm text-gray-500 hover:text-gray-700 underline">
                    ← Изменить email
                </a>
                <button type="submit"
                    class="inline-flex items-center px-4 py-2 bg-red-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2 transition ease-in-out duration-150">
                    Удалить аккаунт
                </button>
            </div>
        </form>
    @else
        {{-- Шаг 1: ввод email --}}
        <form method="POST" action="{{ route('delete-account.send-code') }}">
            @csrf

            <div>
                <x-input-label for="email" value="Email" />
                <x-text-input
                    id="email"
                    class="block mt-1 w-full"
                    type="email"
                    name="email"
                    :value="old('email')"
                    autofocus
                    required
                />
                <x-input-error :messages="$errors->get('email')" class="mt-2" />
            </div>

            <div class="mt-4 flex items-center justify-end">
                <button type="submit"
                    class="inline-flex items-center px-4 py-2 bg-red-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2 transition ease-in-out duration-150">
                    Получить код
                </button>
            </div>
        </form>
    @endif
</x-guest-layout>
