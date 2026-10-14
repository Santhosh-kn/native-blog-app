@extends('layouts.app')
@section('title', 'Home')
@section('hide-intro', 'yes')
@section('app-bar-action')
    <form method="POST" action="{{ route('logout') }}">
        @csrf
        <button type="submit" class="icon-button" aria-label="Logout" title="Logout">@include('partials.ui-icon', ['name' => 'logout'])</button>
    </form>
@endsection
@section('content')
    @php
        // Direct view rendering keeps the same validated local defaults.
        $remoteConfig ??= [
            'defaults' => App\Support\FirebaseRemoteConfigDemo::DEFAULTS,
            'values' => array_map(static fn (array $entry): array => [...$entry, 'source' => 'local',
                'nativeSource' => null, 'fallbackReason' => null], App\Support\FirebaseRemoteConfigDemo::DEFAULTS),
            'info' => ['success' => false],
        ];
    @endphp
    <section class="home-hero">
        <p class="eyebrow">Welcome back</p>
        <h2>{{ auth()->user()->name }}</h2>
        <p id="rc-welcome">{{ $remoteConfig['values']['welcome_message']['value'] }}</p>
        <div class="actions">
            <a href="{{ route('posts.index') }}" class="btn">@include('partials.ui-icon', ['name' => 'posts']) My Posts</a>
            <a href="{{ route('push.index') }}" class="btn hero-secondary">@include('partials.ui-icon', ['name' => 'bell']) Notifications</a>
        </div>
    </section>
    <section aria-labelledby="files-heading">
        <div class="section-heading"><div><h2 id="files-heading">Media &amp; files</h2><p>Create, process and move your content.</p></div></div>
        <div class="feature-grid">
            <a id="rc-media-link" href="{{ route('native-media-optimizer.index') }}" class="feature-card" style="{{ $remoteConfig['values']['show_media_optimizer']['value'] ? '' : 'display: none;' }}"><span class="feature-symbol">@include('partials.ui-icon', ['name' => 'image'])</span><strong>Media Optimizer</strong><span>Images, video &amp; thumbnails</span></a>
            <a href="{{ route('native-document-picker.index') }}" class="feature-card"><span class="feature-symbol blue">@include('partials.ui-icon', ['name' => 'file'])</span><strong>Document Picker</strong><span>Select a document securely</span></a>
            <a href="{{ route('native-printing.index') }}" class="feature-card"><span class="feature-symbol amber">@include('partials.ui-icon', ['name' => 'printer'])</span><strong>PDF Printing</strong><span>Preview and print documents</span></a>
            <a href="{{ route('native-background-transfer.index') }}" class="feature-card"><span class="feature-symbol violet">@include('partials.ui-icon', ['name' => 'transfer'])</span><strong>Background Transfers</strong><span>Manage uploads &amp; downloads</span></a>
        </div>
    </section>
    <section aria-labelledby="device-heading">
        <div class="section-heading"><div><h2 id="device-heading">On your device</h2><p>Explore the phone's native capabilities.</p></div></div>
        <div class="feature-grid">
            <a href="{{ route('native-contacts.index') }}" class="feature-card"><span class="feature-symbol violet">@include('partials.ui-icon', ['name' => 'contacts'])</span><strong>Contacts</strong><span>Pick and open a contact</span></a>
            <a href="{{ route('native-calendar.index') }}" class="feature-card"><span class="feature-symbol blue">@include('partials.ui-icon', ['name' => 'calendar'])</span><strong>Calendar</strong><span>Events and date viewing</span></a>
            <a href="{{ route('microphone.index') }}" class="feature-card"><span class="feature-symbol amber">@include('partials.ui-icon', ['name' => 'mic'])</span><strong>Microphone</strong><span>Record and manage audio</span></a>
        </div>
    </section>
    <section aria-labelledby="settings-heading">
        <div class="section-heading"><div><h2 id="settings-heading">Security &amp; settings</h2><p>Check capabilities and app configuration.</p></div></div>
        <div class="feature-grid">
            <a href="{{ route('native-passkeys.index') }}" class="feature-card"><span class="feature-symbol">@include('partials.ui-icon', ['name' => 'shield'])</span><strong>Passkeys</strong><span>Readiness and diagnostics</span></a>
            <a href="{{ route('firebase-remote-config.index') }}" class="feature-card"><span class="feature-symbol violet">@include('partials.ui-icon', ['name' => 'settings'])</span><strong>Remote Config</strong><span>Review and apply app settings</span></a>
        </div>
    </section>
    <section aria-labelledby="recent-heading">
        <div class="section-heading"><div><h2 id="recent-heading">Recent posts</h2><p>Your latest writing, in one place.</p></div><a href="{{ route('posts.index') }}" class="text-link">View all @include('partials.ui-icon', ['name' => 'arrow'])</a></div>
        @forelse ($posts->take(3) as $post)
            <div class="card recent-post"><span class="feature-symbol">@include('partials.ui-icon', ['name' => 'posts'])</span><div class="post-copy"><h3>{{ $post->title }}</h3><p>{{ Str::limit($post->body, 80) }}</p></div></div>
        @empty
            <div class="empty-state"><span class="feature-symbol">@include('partials.ui-icon', ['name' => 'edit'])</span><h3>No posts yet</h3><p>Start with a thought, a story or a photo.</p><a href="{{ route('posts.create') }}" class="btn btn-primary">@include('partials.ui-icon', ['name' => 'plus']) Write your first post</a></div>
        @endforelse
    </section>
    <p id="rc-home-status" class="home-sync" role="status" aria-live="polite">Cached settings and local defaults are usable.</p>
    @include('partials.firebase-remote-config', ['remoteConfigMode' => 'home'])
@endsection
