@extends('layouts.app')

@section('title', 'Unlock')

@section('content')
    <div style="max-width: 360px; margin: 60px auto 0; text-align: center;">
        <p style="font-size: 48px; margin: 0 0 16px;">🔒</p>
        <p style="font-size: 20px; font-weight: 700; margin: 0 0 4px;">Welcome back</p>
        <p style="font-size: 14px; color: var(--text-muted); margin: 0 0 32px;">Unlock with biometrics to continue</p>

        <button id="unlock-btn" class="btn btn-primary btn-block">Unlock</button>
        <p id="error-msg" class="error" style="text-align: center; margin-top: 12px;"></p>

        <form id="confirm-form" method="POST" action="{{ route('unlock.confirm') }}" style="display: none;">
            @csrf
        </form>
    </div>

    <script type="module">
        import { Biometric, On, Events } from '#nativephp';

        On(Events.Biometric.Completed, (payload) => {
            if (payload.success) {
                document.getElementById('confirm-form').submit();
            } else {
                document.getElementById('error-msg').textContent = 'Authentication failed. Try again.';
            }
        });

        document.getElementById('unlock-btn').addEventListener('click', async () => {
            document.getElementById('error-msg').textContent = '';
            try {
                await Biometric.prompt();
            } catch (e) {
                document.getElementById('error-msg').textContent = 'Biometrics not available.';
            }
        });
    </script>
@endsection