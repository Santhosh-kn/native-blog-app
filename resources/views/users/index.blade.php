@extends('layouts.app')

@section('title', 'Users')

@section('content')
    @error('delete')
        <div class="error-banner">{{ $message }}</div>
    @enderror

    @forelse ($users as $user)
        <div class="card" style="margin-bottom: 10px; display: flex; align-items: center; justify-content: space-between; gap: 12px;">
            <div>
                <p style="margin: 0; font-weight: 700;">{{ $user->name }}</p>
                <p style="margin: 2px 0 0; font-size: 13px; color: var(--text-muted);">{{ $user->email }}</p>
            </div>
            <div style="display: flex; gap: 6px; flex-shrink: 0;">
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
            <div class="icon">👥</div>
            <p>No users.</p>
        </div>
    @endforelse
@endsection