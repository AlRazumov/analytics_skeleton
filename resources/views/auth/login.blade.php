<x-layouts.standalone title="Вход" :guest="true">
    <div class="widget login-card">
        <h1>Вход</h1>

        <form method="POST" action="{{ route('login') }}">
            @csrf

            <label for="email">Email</label>
            <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username">

            <label for="password">Пароль</label>
            <input id="password" type="password" name="password" required autocomplete="current-password">

            <label class="remember">
                <input type="checkbox" name="remember" value="1"> Запомнить меня
            </label>

            @error('email')
                <div class="form-error" role="alert">{{ $message }}</div>
            @enderror

            <button type="submit">Войти</button>
        </form>
    </div>
</x-layouts.standalone>
