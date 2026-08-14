@extends('layouts.app')

@section('title', 'Register')

@section('content')
    <div style="max-width: 400px; margin: 40px auto 0;">
        <div style="text-align: center; margin-bottom: 32px;">
            <p style="font-size: 40px; margin: 0 0 8px;">✨</p>
            <p style="font-size: 22px; font-weight: 700; margin: 0;">Create account</p>
        </div>

        <form method="POST" action="{{ route('register.store') }}">
            @csrf
            <div class="field">
                <label for="name">Name</label>
                <input type="text" id="name" name="name" value="{{ old('name') }}">
                @error('name') <p class="error">{{ $message }}</p> @enderror
            </div>
            <div class="field">
                <label for="email">Email</label>
                <input type="email" id="email" name="email" value="{{ old('email') }}">
                @error('email') <p class="error">{{ $message }}</p> @enderror
            </div>
            <div class="field">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" autocomplete="new-password" autocapitalize="off" autocorrect="off">
                @error('password') <p class="error">{{ $message }}</p> @enderror
            </div>
            <div class="field">
                <label for="password_confirmation">Confirm Password</label>
                <input type="password" id="password_confirmation" name="password_confirmation" autocomplete="new-password" autocapitalize="off" autocorrect="off">
            </div>
            <button type="submit" class="btn btn-primary btn-block">Register</button>
        </form>

        <p style="text-align: center; margin-top: 20px; font-size: 14px; color: var(--text-muted);">
            Already have an account? <a href="{{ route('login') }}" style="color: var(--primary); font-weight: 600; text-decoration: none;">Login</a>
        </p>
    </div>
@endsection