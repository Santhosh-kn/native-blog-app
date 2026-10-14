@php
    $uiPaths = [
        'home' => 'M3 10l9-7 9 7v10a1 1 0 0 1-1 1h-5v-7H9v7H4a1 1 0 0 1-1-1z',
        'posts' => 'M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z M14 2v6h6 M8 13h8 M8 17h5',
        'bell' => 'M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9 M10 21h4',
        'users' => 'M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2 M17 4a4 4 0 0 1 0 8 M22 21v-2a4 4 0 0 0-3-3.9 M13 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0',
        'arrow' => 'M5 12h14 M13 6l6 6-6 6',
        'back' => 'M19 12H5 M11 6l-6 6 6 6',
        'plus' => 'M12 5v14 M5 12h14',
        'logout' => 'M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4 M9 12h12 M17 8l4 4-4 4',
        'file' => 'M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z M14 2v6h6 M12 12v6 M9 15l3 3 3-3',
        'image' => 'M4 3h16a1 1 0 0 1 1 1v16a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1 M3 16l6-6 5 5 3-3 4 4 M17 7h.01',
        'printer' => 'M6 9V3h12v6 M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2 M6 14h12v7H6z M18 12h.01',
        'transfer' => 'M8 3v14 M3 12l5 5 5-5 M16 21V7 M11 12l5-5 5 5',
        'calendar' => 'M4 5h16a1 1 0 0 1 1 1v14a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1 M16 3v4 M8 3v4 M3 11h18 M8 15h2 M14 15h2 M8 18h2',
        'contacts' => 'M5 3h14a1 1 0 0 1 1 1v16a1 1 0 0 1-1 1H5V3 M2 7h4 M2 12h4 M2 17h4 M16 9a3 3 0 1 1-6 0 3 3 0 0 1 6 0 M8 18v-1a4 4 0 0 1 8 0v1',
        'mic' => 'M9 5a3 3 0 0 1 6 0v7a3 3 0 0 1-6 0V5 M5 10v2a7 7 0 0 0 14 0v-2 M12 19v3 M8 22h8',
        'shield' => 'M12 2l9 4v6c0 5-9 10-9 10S3 17 3 12V6z M8 12l3 3 5-6',
        'settings' => 'M4 7h16 M4 17h16 M9 4v6 M15 14v6',
        'lock' => 'M5 10h14a1 1 0 0 1 1 1v9a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1v-9a1 1 0 0 1 1-1 M8 10V6a4 4 0 0 1 8 0v4 M12 15v2',
        'camera' => 'M8 5l2-3h4l2 3h4a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2z M16 13a4 4 0 1 1-8 0 4 4 0 0 1 8 0',
        'spark' => 'M12 3l2.5 6.5L21 12l-6.5 2.5L12 21l-2.5-6.5L3 12l6.5-2.5z',
        'edit' => 'M12 20H4v-8 M16 3l5 5-11 11H5v-5z M14 5l5 5',
    ];
@endphp
<svg class="ui-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="{{ $uiPaths[$name ?? 'spark'] ?? $uiPaths['spark'] }}" /></svg>
