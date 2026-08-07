<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Biometric Test</title>
    <style>
        body { font-family: -apple-system, sans-serif; padding: 24px; background: #f5f5f5; text-align: center; }
        button { padding: 14px 24px; font-size: 16px; background: #04ABA6; color: white; border: none; border-radius: 8px; margin-top: 24px; }
        p { font-size: 16px; margin-top: 16px; }
    </style>
</head>
<body>
    <h1>Biometric Test</h1>
    <p id="status">Ready</p>
    <button id="test-btn">Test Biometric</button>

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
</body>
</html>