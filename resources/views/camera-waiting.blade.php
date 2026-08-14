@extends('layouts.app')

@section('title', 'Capturing')

@section('content')
    <div style="text-align: center; margin-top: 80px;">
        <div style="width: 48px; height: 48px; border: 4px solid var(--border); border-top-color: var(--primary); border-radius: 50%; margin: 0 auto 20px; animation: spin 0.8s linear infinite;"></div>
        <p style="color: var(--text-muted); font-size: 15px;">Waiting for photo...</p>
    </div>

    <style>
        @keyframes spin { to { transform: rotate(360deg); } }
    </style>

    <script>
        const check = async () => {
            try {
                const response = await fetch('{{ route("camera.status") }}');
                const data = await response.json();
                if (data.ready) {
                    window.location.href = '{{ route("posts.create") }}';
                } else {
                    setTimeout(check, 1000);
                }
            } catch (e) {
                setTimeout(check, 1000);
            }
        };
        check();
    </script>
@endsection