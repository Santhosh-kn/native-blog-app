<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit User</title>
    <style>
        body { font-family: -apple-system, sans-serif; padding: 24px; background: #f5f5f5; }
        form { display: flex; flex-direction: column; gap: 12px; }
        label { font-size: 14px; font-weight: 600; }
        input { padding: 12px; font-size: 16px; border: 1px solid #ccc; border-radius: 8px; width: 100%; box-sizing: border-box; }
        button { padding: 12px; font-size: 16px; background: #04ABA6; color: white; border: none; border-radius: 8px; margin-top: 8px; }
        .error { color: #d33; font-size: 13px; margin: 0; }
        .back { display: inline-block; margin-top: 16px; }
    </style>
</head>
<body>
    <h1>Edit User</h1>

    <form method="POST" action="{{ route('users.update', $user['id']) }}">
        @csrf
        @method('PUT')

        <label for="name">Name</label>
        <input type="text" id="name" name="name" value="{{ old('name', $user['name']) }}">
        @error('name') <p class="error">{{ $message }}</p> @enderror

        <label for="email">Email</label>
        <input type="email" id="email" name="email" value="{{ old('email', $user['email']) }}">
        @error('email') <p class="error">{{ $message }}</p> @enderror

        <button type="submit">Save Changes</button>
    </form>

    <a href="{{ route('home') }}" class="back">&larr; Back to list</a>
</body>
</html>