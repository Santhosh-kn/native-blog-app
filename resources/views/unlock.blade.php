<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Unlock</title>
    <style>
        body { font-family: -apple-system, sans-serif; padding: 24px; background: #f5f5f5; text-align: center; }
        button { padding: 14px 24px; font-size: 16px; background: #04ABA6; color: white; border: none; border-radius: 8px; margin-top: 24px; }
        p.error { color: #d33; font-size: 14px; }
    </style>
</head>
<body>
    <h1>Welcome back</h1>
    <p>Unlock with biometrics to continue</p>

    <button id="unlock-btn">Unlock</button>
    <p id="error-msg" class="error"></p>

    <form id="confirm-form" method="POST" action="{{ route('unlock.confirm') }}" style="display: none;">
        @csrf
    </form>

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
            await Biometric.prompt();
        });
    </script>
</body>
</html>