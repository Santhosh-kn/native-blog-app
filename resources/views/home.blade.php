<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Home</title>
    <style>
        body { font-family: -apple-system, sans-serif; padding: 24px; background: #f5f5f5; }
        button, .btn { padding: 8px 14px; font-size: 14px; border: none; border-radius: 6px; cursor: pointer; text-decoration: none; display: inline-block; }
        .btn-logout { background: #d33; color: white; padding: 12px 20px; font-size: 16px; border-radius: 8px; }
        .btn-edit { background: #04ABA6; color: white; }
        .btn-delete { background: #999; color: white; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; background: white; border-radius: 8px; overflow: hidden; }
        th, td { text-align: left; padding: 10px; font-size: 14px; border-bottom: 1px solid #eee; }
        th { background: #eee; }
        .actions { display: flex; gap: 6px; }
        .pagination { margin-top: 16px; display: flex; justify-content: space-between; align-items: center; font-size: 14px; }
        .status { background: #d4edda; color: #155724; padding: 10px; border-radius: 6px; margin-top: 16px; }
        .error { background: #f8d7da; color: #721c24; padding: 10px; border-radius: 6px; margin-top: 16px; }
    </style>
</head>
<body>
    <h1>Welcome, {{ session('api_user')['name'] }}</h1>

    <p><a href="{{ route('posts.index') }}">Manage Posts</a></p>
    
    <form method="POST" action="{{ route('logout') }}">
        @csrf
        <button type="submit" class="btn-logout">Logout</button>
    </form>

    @error('delete')
        <p class="error">{{ $message }}</p>
    @enderror

    <h2>Registered users</h2>
    <table>
        <tr>
            <th>ID</th>
            <th>Name</th>
            <th>Email</th>
            <th>Actions</th>
        </tr>
        @foreach ($users as $user)
            <tr>
                <td>{{ $user['id'] }}</td>
                <td>{{ $user['name'] }}</td>
                <td>{{ $user['email'] }}</td>
                <td class="actions">
                    <a href="{{ route('users.edit', $user['id']) }}" class="btn btn-edit">Edit</a>
                    <form method="POST" action="{{ route('users.destroy', $user['id']) }}" onsubmit="return confirm('Delete this user?');">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="btn btn-delete">Delete</button>
                    </form>
                </td>
            </tr>
        @endforeach
    </table>

    <div class="pagination">
        <span>@if ($meta['current_page'] > 1)<a href="{{ route('home', ['page' => $meta['current_page'] - 1]) }}">&laquo; Prev</a>@endif</span>
        <span>Page {{ $meta['current_page'] }} of {{ $meta['last_page'] }}</span>
        <span>@if ($meta['current_page'] < $meta['last_page'])<a href="{{ route('home', ['page' => $meta['current_page'] + 1]) }}">Next &raquo;</a>@endif</span>
    </div>
</body>
</html>