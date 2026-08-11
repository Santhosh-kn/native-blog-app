<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Users</title>
    <style>
        body { font-family: -apple-system, sans-serif; padding: 24px; background: #f5f5f5; }
        button, .btn { padding: 8px 14px; font-size: 14px; border: none; border-radius: 6px; cursor: pointer; text-decoration: none; display: inline-block; }
        .user-card { background: white; border-radius: 8px; padding: 16px; margin-top: 12px; }
        .user-card h3 { margin: 0 0 6px; }
        .user-card p { margin: 0 0 10px; font-size: 14px; color: #555; }
        .actions { display: flex; gap: 6px; }
        .btn-edit { background: #04ABA6; color: white; }
        .btn-delete { background: #999; color: white; }
        .back { display: inline-block; margin-top: 16px; }
    </style>
</head>
<body>
    <h1>Users</h1>

    @forelse ($users as $user)
        <div class="user-card">
            <h3>{{ $user->name }}</h3>
            <p>{{ $user->email }}</p>
            <div class="actions">
                <a href="{{ route('users.edit', $user->id) }}" class="btn btn-edit">Edit</a>
                <form method="POST" action="{{ route('users.destroy', $user->id) }}" onsubmit="return confirm('Delete this user?');" style="display: inline;">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="btn btn-delete">Delete</button>
                </form>
            </div>
        </div>
    @empty
        <p>No users.</p>
    @endforelse

    <a href="/" class="back">&larr; Back to home</a>
</body>
</html>