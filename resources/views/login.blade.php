@extends('performance-tracking::layout')

@section('title', 'Sign in · Request timings')

@push('head')
    <style>
        .login { min-height: calc(100vh - 88px); display: grid; place-items: center; }
        .login .card { width: 100%; max-width: 360px; padding: 24px; display: grid; gap: 18px; }
        .login h1 { font-size: 20px; margin: 0; font-weight: 600; }
        .login p { margin: 4px 0 0; color: var(--ink-2); }
        .login form { display: grid; gap: 14px; }
        .login label { display: grid; gap: 6px; font-size: 13px; font-weight: 500; color: var(--ink-2); }
        .login input {
            font: inherit; color: var(--ink); background: var(--page);
            border: 1px solid var(--border); border-radius: 8px; padding: 9px 11px; min-width: 0;
        }
        .login input[aria-invalid="true"] { border-color: var(--status-critical); }
        .login button { padding: 9px 12px; font-weight: 600; background: var(--accent); color: #fff; border-color: transparent; }
        .login button:hover { filter: brightness(1.08); }
        .login .error { color: var(--status-critical); font-size: 13px; margin: 0; }
        .login .status { color: var(--status-good); font-size: 13px; margin: 0; }
        .login .bar { margin-bottom: 2px; }
    </style>
@endpush

@section('content')
    <div class="login">
        <div class="card">
            <div class="bar" aria-hidden="true">
                @include('performance-tracking::partials.bar', ['segments' => [
                    ['kind' => 'calc', 'label' => 'Calculating', 'ms' => 12],
                    ['kind' => 'network', 'label' => 'Network out', 'ms' => 30],
                    ['kind' => 'delay', 'label' => 'Delay', 'ms' => 8],
                    ['kind' => 'service', 'label' => 'Service', 'ms' => 45],
                    ['kind' => 'network', 'label' => 'Network return', 'ms' => 10],
                ]])
            </div>
            <div>
                <h1>Request timings</h1>
                <p>Sign in to view the dashboard.</p>
            </div>

            <form method="post" action="{{ route('performance-tracking.login.attempt') }}">
                @csrf
                @if (session('status'))
                    <p class="status" role="status">{{ session('status') }}</p>
                @endif
                @error('username')
                    <p class="error" role="alert" id="login-error">{{ $message }}</p>
                @enderror
                @error('password')
                    <p class="error" role="alert">{{ $message }}</p>
                @enderror

                <label for="username">Username
                    <input id="username" name="username" type="text" value="{{ old('username') }}" autocomplete="username" required autofocus
                           @error('username') aria-invalid="true" aria-describedby="login-error" @enderror>
                </label>
                <label for="password">Password
                    <input id="password" name="password" type="password" autocomplete="current-password" required>
                </label>
                <button type="submit">Sign in</button>
            </form>
        </div>
    </div>
@endsection
