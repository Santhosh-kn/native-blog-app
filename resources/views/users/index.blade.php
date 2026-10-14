@extends('layouts.app')

@section('title', 'Users')
@section('page-icon', 'users')
@section('subtitle', 'View and manage the people in your app.')

@section('content')
    @error('delete')
        <div class="error-banner">{{ $message }}</div>
    @enderror

    @forelse ($users as $user)
        <div class="card user-card" style="margin-bottom: 14px;">
            <span class="avatar" aria-hidden="true">{{ Str::upper(Str::substr($user->name, 0, 1)) }}</span>
            <div class="user-copy">
                <p style="margin: 0; font-weight: 700;">{{ $user->name }}</p>
                <p style="margin: 2px 0 0; font-size: 13px; color: var(--text-muted);">{{ $user->email }}</p>
            </div>
            <div class="actions">
                <a href="{{ route('users.edit', $user->id) }}" class="btn btn-secondary btn-sm">Edit</a>
                <form method="POST" action="{{ route('users.destroy', $user->id) }}" onsubmit="return confirm('Delete this user?');" style="margin: 0;">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="btn btn-sm" style="background: #fdeced; color: var(--danger);">Delete</button>
                </form>
            </div>
        </div>
    @empty
        <div class="empty-state">
            <span class="feature-symbol">@include('partials.ui-icon', ['name' => 'users'])</span>
            <p>No users.</p>
        </div>
    @endforelse
@endsection