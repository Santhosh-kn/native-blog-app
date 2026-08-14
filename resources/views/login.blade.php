@extends('layouts.app')

@section('title', 'Login')

@section('content')
    <div style="max-width: 400px; margin: 40px auto 0;">
        <div style="text-align: center; margin-bottom: 32px;">
            <p style="font-size: 40px; margin: 0 0 8px;">📖</p>
            <p style="font-size: 22px; font-weight: 700; margin: 0;">Welcome back</p>
            <p style="font-size: 14px; color: var(--text-muted); margin: 4px 0 0;">Log in to continue</p>
        </div>

        <form method="POST" action="{{ route('login.store') }}">
            @csrf
            <div class="field">
                <label for="email">Email</label>
                <input type="email" id="email" name="email" value="{{ old('email') }}" autocomplete="email">
                @error('email') <p class="error">{{ $message }}</p> @enderror
            </div>
            <div class="field">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" autocomplete="current-password">
            </div>
            <button type="submit" class="btn btn-primary btn-block">Login</button>
        </form>

        <p style="text-align: center; margin-top: 20px; font-size: 14px; color: var(--text-muted);">
            Don't have an account? <a href="{{ route('register') }}" style="color: var(--primary); font-weight: 600; text-decoration: none;">Register</a>
        </p>
    </div>
@endsection