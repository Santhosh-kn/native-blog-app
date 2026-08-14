@extends('layouts.app')

@section('title', 'Push Notifications')

@section('content')
    <div class="card" style="margin-bottom: 16px; text-align: center;">
        <p style="margin: 0 0 4px; font-size: 13px; color: var(--text-muted);">Permission status</p>
        <p style="margin: 0; font-weight: 700; font-size: 16px; color: {{ $permission === 'granted' ? 'var(--primary)' : 'var(--text)' }};">{{ $permission ?? 'unknown' }}</p>
    </div>

    <form method="POST" action="{{ route('push.enroll') }}" style="margin-bottom: 10px;">
        @csrf
        <button type="submit" class="btn btn-primary btn-block">Enable Push Notifications</button>
    </form>

    @error('push')
        <div class="error-banner">{{ $message }}</div>
    @enderror

    <form method="POST" action="{{ route('push.send-test') }}">
        @csrf
        <button type="submit" class="btn btn-secondary btn-block">Send Test Notification</button>
    </form>

    <div class="card" style="margin-top: 16px;">
        <p style="margin: 0 0 4px; font-size: 13px; color: var(--text-muted);">Device token</p>
        <p style="margin: 0; font-size: 12px; word-break: break-all; color: var(--text-muted);">{{ $token ?: 'Not yet registered' }}</p>
    </div>
@endsection