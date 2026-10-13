@extends('layouts.app')

@section('title', 'Home')

@section('content')
    @php
        // Direct view rendering (including existing tests) uses the same safe local defaults.
        $remoteConfig ??= [
            'defaults' => App\Support\FirebaseRemoteConfigDemo::DEFAULTS,
            'values' => array_map(static fn (array $entry): array => [...$entry, 'source' => 'local',
                'nativeSource' => null, 'fallbackReason' => null], App\Support\FirebaseRemoteConfigDemo::DEFAULTS),
            'info' => ['success' => false],
        ];
    @endphp
    <div class="card" style="margin-bottom: 16px;">
        <p style="margin: 0 0 4px; font-size: 14px; color: var(--text-muted);">Welcome back</p>
        <p id="rc-welcome" style="margin: 6px 0; overflow-wrap: anywhere;">{{ $remoteConfig['values']['welcome_message']['value'] }}</p>
        <p style="margin: 0; font-size: 18px; font-weight: 700;">{{ auth()->user()->name }}</p>
    </div>

    <div style="display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 20px;">
        <a href="{{ route('posts.index') }}" class="btn btn-secondary btn-sm">📝 My Posts</a>
        <a href="{{ route('push.index') }}" class="btn btn-secondary btn-sm">🔔 Notifications</a>
        <a href="{{ route('native-printing.index') }}" class="btn btn-secondary btn-sm">PDF Printing</a>
        <a href="{{ route('native-document-picker.index') }}" class="btn btn-secondary btn-sm">Document Picker</a>
        <a href="{{ route('native-passkeys.index') }}" class="btn btn-secondary btn-sm">Passkeys</a>
        <a href="{{ route('native-contacts.index') }}" class="btn btn-secondary btn-sm">Contacts</a>
        <a href="{{ route('native-calendar.index') }}" class="btn btn-secondary btn-sm">Calendar</a>
        <a id="rc-media-link" href="{{ route('native-media-optimizer.index') }}" class="btn btn-secondary btn-sm" style="{{ $remoteConfig['values']['show_media_optimizer']['value'] ? '' : 'display: none;' }}">Media Optimizer</a>
        <a href="{{ route('firebase-remote-config.index') }}" class="btn btn-secondary btn-sm">Remote Config</a>
        <a href="{{ route('native-background-transfer.index') }}" class="btn btn-secondary btn-sm">Background Transfers</a>
        <form method="POST" action="{{ route('logout') }}" style="margin: 0;">
            @csrf
            <button type="submit" class="btn btn-secondary btn-sm">🚪 Logout</button>
        </form>
    </div>

    <p id="rc-home-status" style="font-size: 12px; color: var(--text-muted);" role="status" aria-live="polite">Cached settings and local defaults are usable.</p>
    @include('partials.firebase-remote-config', ['remoteConfigMode' => 'home'])

    <p style="font-size: 13px; font-weight: 600; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.03em; margin-bottom: 8px;">Recent posts</p>

    @forelse ($posts->take(3) as $post)
        <div class="card" style="margin-bottom: 10px;">
            <p style="margin: 0 0 4px; font-weight: 700;">{{ $post->title }}</p>
            <p style="margin: 0; font-size: 13px; color: var(--text-muted);">{{ Str::limit($post->body, 80) }}</p>
        </div>
    @empty
        <div class="empty-state">
            <div class="icon">📭</div>
            <p>No posts yet</p>
        </div>
    @endforelse
@endsection
