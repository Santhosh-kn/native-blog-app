@extends('layouts.app')

@section('title', 'Microphone')
@section('page-icon', 'mic')
@section('subtitle', 'Record audio using your device microphone.')

@section('content')
    <div class="actions" style="margin-bottom: 16px;">
        <form method="POST" action="{{ route('microphone.start') }}" style="flex: 1; margin: 0;">
            @csrf
            <button type="submit" class="btn btn-primary btn-block">Start</button>
        </form>
        <form method="POST" action="{{ route('microphone.stop') }}" style="flex: 1; margin: 0;">
            @csrf
            <button type="submit" class="btn btn-block" style="background: #fdeced; color: var(--danger);">Stop</button>
        </form>
    </div>

    <div class="card center-card">
        <span class="feature-symbol">@include('partials.ui-icon', ['name' => 'mic'])</span>
        <p id="status" role="status" aria-live="polite" style="margin: 0 0 8px; font-weight: 700;">Status: checking...</p>
        <p id="recording-path" style="margin: 0; font-size: 11px; color: var(--text-muted); word-break: break-all;"></p>
    </div>

    <script>
        const checkStatus = async () => {
            try {
                const response = await fetch('{{ route("microphone.status") }}');
                const data = await response.json();
                document.getElementById('status').textContent = 'Status: ' + data.status;
                if (data.recording) {
                    document.getElementById('recording-path').textContent = 'Last recording: ' + data.recording;
                }
            } catch (e) {}
            setTimeout(checkStatus, 1500);
        };
        checkStatus();
    </script>
@endsection