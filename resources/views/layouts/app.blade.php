<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#183f3e">
    <title>@yield('title', 'Native Blog') · Native Blog</title>
    @include('partials.ui-theme')
    @stack('styles')
</head>
<body class="@auth @unless(request()->routeIs('unlock')) has-nav @endunless @endauth">
    <a href="#main-content" class="skip-link">Skip to content</a>
    <header class="app-bar">
        <div class="app-bar-inner">
            @if (! request()->routeIs('home', 'login', 'register', 'unlock'))
                <a class="icon-button" href="{{ request()->routeIs('posts.create', 'posts.edit', 'camera.waiting') ? route('posts.index') : (request()->routeIs('users.edit') ? route('users.index') : route('home')) }}" aria-label="Back to {{ request()->routeIs('posts.create', 'posts.edit', 'camera.waiting') ? 'posts' : (request()->routeIs('users.edit') ? 'users' : 'home') }}">
                    @include('partials.ui-icon', ['name' => 'back'])
                </a>
            @else
                <span class="brand-mark">@include('partials.ui-icon', ['name' => 'posts'])</span>
            @endif
            <div class="app-bar-heading"><p>NATIVE BLOG</p><h1>@yield('title', 'Your workspace')</h1></div>
            @hasSection('app-bar-action')<div class="app-bar-action">@yield('app-bar-action')</div>@endif
        </div>
    </header>
    <main class="content" id="main-content" tabindex="-1">
        @if (session('status'))<div class="status-banner" role="status">{{ session('status') }}</div>@endif
        @unless($__env->hasSection('hide-intro'))
            <div class="page-intro">
                <span class="feature-symbol">@include('partials.ui-icon', ['name' => trim($__env->yieldContent('page-icon', 'spark'))])</span>
                <div><p class="eyebrow">@yield('eyebrow', 'Your workspace')</p><h2>@yield('title', 'Native Blog')</h2>@hasSection('subtitle')<p>@yield('subtitle')</p>@endif</div>
            </div>
        @endunless
        @yield('content')
    </main>
    @auth
        @unless(request()->routeIs('unlock'))
            @yield('fab')
            <nav class="bottom-nav" aria-label="Main navigation"><div class="bottom-nav-inner">
                @foreach ([['home', 'home', 'Home', 'home'], ['posts.index', 'posts', 'Posts', 'posts.*'], ['push.index', 'bell', 'Push', 'push.*'], ['users.index', 'users', 'Users', 'users.*']] as [$uiRoute, $uiIcon, $uiLabel, $uiPattern])
                    <a href="{{ route($uiRoute) }}" class="{{ request()->routeIs($uiPattern) ? 'active' : '' }}" @if(request()->routeIs($uiPattern)) aria-current="page" @endif>
                        @include('partials.ui-icon', ['name' => $uiIcon])<span>{{ $uiLabel }}</span>
                    </a>
                @endforeach
            </div></nav>
        @endunless
        @if (filled(auth()->user()->push_token))@include('partials.firebase-push-token-sync')@endif
    @endauth
    @stack('scripts')
</body>
</html>
