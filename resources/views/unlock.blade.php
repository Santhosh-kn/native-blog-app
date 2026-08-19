@extends('layouts.app')

@section('title', 'Unlock')

@section('content')
    <div style="max-width: 360px; margin: 60px auto 0; text-align: center;">
        <p style="font-size: 48px; margin: 0 0 16px;">🔒</p>
        <p style="font-size: 20px; font-weight: 700; margin: 0 0 4px;">Welcome back</p>
        <p style="font-size: 14px; color: var(--text-muted); margin: 0 0 32px;">Unlock with biometrics to continue</p>

        <button id="unlock-btn" class="btn btn-primary btn-block">Unlock</button>
        <p id="status-msg" style="font-size: 13px; color: var(--text-muted); margin-top: 12px;"></p>
        <p id="error-msg" class="error" style="text-align: center; margin-top: 12px;"></p>

        <form id="confirm-form" method="POST" action="{{ route('unlock.confirm') }}" style="display: none;">
            @csrf
        </form>
    </div>

    <script>
        let polling = false;

        async function pollBiometricStatus() {
            if (!polling) return;

            try {
                const response = await fetch('{{ route("unlock.biometric-status") }}');
                const data = await response.json();

                if (data.result) {
                    polling = false;
                    if (data.result.success) {
                        document.getElementById('status-msg').textContent = 'Verified!';
                        document.getElementById('confirm-form').submit();
                    } else {
                        document.getElementById('status-msg').textContent = '';
                        document.getElementById('error-msg').textContent = 'Authentication failed. Try again.';
                    }
                    return;
                }
            } catch (e) {}

            setTimeout(pollBiometricStatus, 800);
        }

        document.getElementById('unlock-btn').addEventListener('click', async () => {
            document.getElementById('error-msg').textContent = '';
            document.getElementById('status-msg').textContent = 'Waiting for fingerprint...';

            const csrfToken = document.querySelector('input[name="_token"]').value;

            await fetch('{{ route("unlock.trigger-biometric") }}', {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrfToken },
            });

            polling = true;
            pollBiometricStatus();
        });
    </script>
@endsection