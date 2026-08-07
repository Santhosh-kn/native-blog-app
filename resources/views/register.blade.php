<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register</title>
    <style>
        body {
            font-family: -apple-system, sans-serif;
            padding: 24px;
            background: #f5f5f5;
        }
        form {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        label {
            font-size: 14px;
            font-weight: 600;
        }
        input {
            padding: 12px;
            font-size: 16px;
            border: 1px solid #ccc;
            border-radius: 8px;
            width: 100%;
            box-sizing: border-box;
        }
        button {
            padding: 12px;
            font-size: 16px;
            background: #04ABA6;
            color: white;
            border: none;
            border-radius: 8px;
            margin-top: 8px;
        }
        .error {
            color: #d33;
            font-size: 13px;
            margin: 0;
        }
    </style>
</head>
<body>
    <h1>Register</h1>

    <form method="POST" action="{{ route('register.store') }}">
        @csrf

        <label for="name">Name</label>
        <input type="text" id="name" name="name" value="{{ old('name') }}">
        @error('name') <p class="error">{{ $message }}</p> @enderror

        <label for="email">Email</label>
        <input type="email" id="email" name="email" value="{{ old('email') }}">
        @error('email') <p class="error">{{ $message }}</p> @enderror

        <label for="password">Password</label>
        <input type="password" id="password" name="password">
        @error('password') <p class="error">{{ $message }}</p> @enderror

        <label for="password_confirmation">Confirm Password</label>
        <input type="password" id="password_confirmation" name="password_confirmation">

        <button type="submit">Register</button>
    </form>

    <p>Already have an account? <a href="{{ route('login') }}">Login</a></p>
</body>
</html>