@extends('layouts.app')

@section('title', 'Edit User')
@section('page-icon', 'users')
@section('subtitle', 'Update account information.')

@section('content')
    <div class="card form-panel">
    <form method="POST" action="{{ route('users.update', $user->id) }}">
        @csrf
        @method('PUT')
        <div class="field">
            <label for="name">Name</label>
            <input type="text" id="name" name="name" value="{{ old('name', $user->name) }}">
            @error('name') <p class="error">{{ $message }}</p> @enderror
        </div>
        <div class="field">
            <label for="email">Email</label>
            <input type="email" id="email" name="email" value="{{ old('email', $user->email) }}">
            @error('email') <p class="error">{{ $message }}</p> @enderror
        </div>
        <button type="submit" class="btn btn-primary btn-block">Save Changes</button>
    </form>
    </div>
@endsection
