<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login</title>
    <style>
        body { font-family: -apple-system, sans-serif; padding: 24px; background: #f5f5f5; }
        form { display: flex; flex-direction: column; gap: 12px; }
        label { font-size: 14px; font-weight: 600; }
        input { padding: 12px; font-size: 16px; border: 1px solid #ccc; border-radius: 8px; width: 100%; box-sizing: border-box; }
        button { padding: 12px; font-size: 16px; background: #04ABA6; color: white; border: none; border-radius: 8px; margin-top: 8px; }
        .google-btn { background: #4285F4; margin-top: 16px; }
        .divider { text-align: center; margin: 16px 0; font-size: 12px; color: #999; }
        .error { color: #d33; font-size: 13px; margin: 0; }
        a { color: #04ABA6; text-decoration: none; }
    </style>
</head>
<body>
    <h1>Login</h1>

    <form method="POST" action="{{ route('login.store') }}">
        @csrf

        <label for="email">Email</label>
        <input type="email" id="email" name="email" value="{{ old('email') }}">
        @error('email') <p class="error">{{ $message }}</p> @enderror

        <label for="password">Password</label>
        <input type="password" id="password" name="password">

        <button type="submit">Login</button>
    </form>

    <div class="divider">OR</div>

    <button type="button" class="google-btn" id="google-signin-btn">Sign in with Google</button>

    <p style="text-align: center; margin-top: 16px; font-size: 14px;">
        Don't have an account? <a href="{{ route('register') }}">Register</a>
    </p>

    <script type="module">
        import { GoogleAuth } from '#nativephp';

        document.getElementById('google-signin-btn').addEventListener('click', async () => {
            try {
                const result = await GoogleAuth.signIn();
                
                if (result.idToken) {
                    const response = await fetch('{{ route("google.callback") }}', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('input[name="_token"]').value,
                        },
                        body: JSON.stringify({
                            id_token: result.idToken,
                        }),
                    });

                    const data = await response.json();

                    if (data.success) {
                        window.location.href = data.redirect;
                    } else {
                        alert('Authentication failed: ' + data.message);
                    }
                }
            } catch (error) {
                alert('Google Sign-In error: ' + error.message);
            }
        });
    </script>
</body>
</html>