@extends('layouts.app')

@section('title', 'Register')
@section('page-icon', 'users')
@section('subtitle', 'Create your account and start exploring.')
@section('hide-intro', 'yes')

@section('content')
    <div class="auth-panel card">
        <div class="auth-heading">
            <span class="feature-symbol">@include('partials.ui-icon', ['name' => 'users'])</span>
            <h2>Create account</h2>
        </div>

        <form method="POST" action="{{ route('register.store') }}">
            @csrf
            <div class="field">
                <label for="name">Name</label>
                <input autocomplete="name" type="text" id="name" name="name" value="{{ old('name') }}">
                @error('name') <p class="error">{{ $message }}</p> @enderror
            </div>
            <div class="field">
                <label for="email">Email</label>
                <input autocomplete="email" type="email" id="email" name="email" value="{{ old('email') }}">
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

        <p class="auth-footer">
            Already have an account? <a href="{{ route('login') }}" style="color: var(--primary); font-weight: 600; text-decoration: none;">Login</a>
        </p>
    </div>
@endsection