@extends('layouts.app')
@section('title', 'Biometric Test')
@section('page-icon', 'shield')
@section('subtitle', 'Check biometric authentication on your device.')
@section('content')
    <section class="card center-card">
        <span class="feature-symbol">@include('partials.ui-icon', ['name' => 'shield'])</span>
        <h2>Biometric authentication</h2>
        <p id="status" role="status" aria-live="polite">Ready</p>
        <button id="test-btn" type="button" class="btn btn-primary btn-block">Test Biometric</button>
    </section>
<script type="module">
        import { Biometric, On, Events } from '#nativephp';

        On(Events.Biometric.Completed, (payload) => {
            const status = document.getElementById('status');
            if (payload.success) {
                status.textContent = '✓ Biometric SUCCESS';
                status.style.color = 'green';
            } else {
                status.textContent = '✗ Biometric FAILED';
                status.style.color = 'red';
            }
        });

        document.getElementById('test-btn').addEventListener('click', async () => {
            document.getElementById('status').textContent = 'Waiting...';
            document.getElementById('status').style.color = 'blue';
            await Biometric.prompt();
        });
    </script>
@endsection
