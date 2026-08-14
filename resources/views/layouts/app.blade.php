<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'Blog')</title>
    <style>
        :root {
            --primary: #04ABA6;
            --primary-dark: #038a86;
            --bg: #f4f5f7;
            --surface: #ffffff;
            --text: #1a1d1f;
            --text-muted: #6b7280;
            --border: #e5e7eb;
            --danger: #e0455f;
            --radius: 14px;
            --shadow: 0 1px 3px rgba(0,0,0,0.06), 0 1px 2px rgba(0,0,0,0.04);
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: var(--bg);
            color: var(--text);
            padding-bottom: 76px;
            -webkit-tap-highlight-color: transparent;
        }
        .app-bar {
            position: sticky;
            top: 0;
            z-index: 10;
            background: var(--surface);
            padding: 16px 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 1px solid var(--border);
        }
        .app-bar h1 {
            font-size: 20px;
            margin: 0;
            font-weight: 700;
        }
        .content {
            padding: 16px;
        }
        .card {
            background: var(--surface);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            padding: 16px;
        }
        input, textarea {
            width: 100%;
            padding: 12px 14px;
            font-size: 16px;
            border: 1px solid var(--border);
            border-radius: 10px;
            box-sizing: border-box;
            font-family: inherit;
            background: var(--surface);
        }
        textarea { min-height: 140px; resize: vertical; }
        label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: var(--text-muted);
            margin-bottom: 6px;
        }
        .field { margin-bottom: 16px; }
        button, .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            padding: 12px 18px;
            font-size: 15px;
            font-weight: 600;
            border: none;
            border-radius: 10px;
            cursor: pointer;
            text-decoration: none;
            transition: transform 0.1s ease, opacity 0.15s ease;
        }
        button:active, .btn:active { transform: scale(0.97); }
        .btn-primary { background: var(--primary); color: white; }
        .btn-secondary { background: var(--border); color: var(--text); }
        .btn-danger { background: var(--danger); color: white; }
        .btn-block { width: 100%; }
        .btn-sm { padding: 8px 12px; font-size: 13px; }
        .error { color: var(--danger); font-size: 13px; margin: 4px 0 0; }
        .status-banner {
            background: #e3f7ee;
            color: #0f7a4b;
            padding: 12px 16px;
            border-radius: 10px;
            font-size: 14px;
            margin-bottom: 16px;
        }
        .error-banner {
            background: #fdeced;
            color: #b3273f;
            padding: 12px 16px;
            border-radius: 10px;
            font-size: 14px;
            margin-bottom: 16px;
        }
        .fab {
            position: fixed;
            bottom: 88px;
            right: 20px;
            width: 56px;
            height: 56px;
            border-radius: 50%;
            background: var(--primary);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 28px;
            box-shadow: 0 4px 12px rgba(4,171,166,0.4);
            text-decoration: none;
            z-index: 20;
        }
        .bottom-nav {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: var(--surface);
            border-top: 1px solid var(--border);
            display: flex;
            padding: 8px 0 max(8px, env(safe-area-inset-bottom));
            z-index: 20;
        }
        .bottom-nav a {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 2px;
            text-decoration: none;
            color: var(--text-muted);
            font-size: 11px;
            padding: 4px 0;
        }
        .bottom-nav a.active { color: var(--primary); }
        .bottom-nav .icon { font-size: 20px; line-height: 1; }
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: var(--text-muted);
        }
        .empty-state .icon { font-size: 40px; margin-bottom: 12px; }
    </style>
</head>
<body>
    <div class="app-bar">
        <h1>@yield('title', 'Blog')</h1>
        @hasSection('app-bar-action')
            @yield('app-bar-action')
        @endif
    </div>

    <div class="content">
        @if (session('status'))
            <div class="status-banner">{{ session('status') }}</div>
        @endif

        @yield('content')
    </div>

    @auth
        @yield('fab')

        <nav class="bottom-nav">
            <a href="{{ route('home') }}" class="{{ request()->routeIs('home') ? 'active' : '' }}">
                <span class="icon">🏠</span> Home
            </a>
            <a href="{{ route('posts.index') }}" class="{{ request()->routeIs('posts.*') ? 'active' : '' }}">
                <span class="icon">📝</span> Posts
            </a>
            <a href="{{ route('push.index') }}" class="{{ request()->routeIs('push.*') ? 'active' : '' }}">
                <span class="icon">🔔</span> Push
            </a>
            <a href="{{ route('users.index') }}" class="{{ request()->routeIs('users.*') ? 'active' : '' }}">
                <span class="icon">👥</span> Users
            </a>
        </nav>
    @endauth
</body>
</html>