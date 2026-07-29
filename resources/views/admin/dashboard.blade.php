<!DOCTYPE html>
<html>
<body>
    <h1>Admin Dashboard</h1>
    <ul>
        <li>Total users: {{ $totalUsers }}</li>
        <li>Premium users: {{ $premiumUsers }}</li>
        <li>New users today: {{ $newUsersToday }}</li>
    </ul>
    <form method="POST" action="{{ route('admin.logout') }}">
        @csrf
        <button type="submit">Logout</button>
    </form>
</body>
</html>
