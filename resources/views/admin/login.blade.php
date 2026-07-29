<!DOCTYPE html>
<html>
<body>
    <h1>Admin Login</h1>
    @if(session('error')) <p style="color:red">{{ session('error') }}</p> @endif
    <form method="POST" action="{{ route('admin.login.submit') }}">
        @csrf
        <input type="email" name="email" placeholder="Email" required>
        <input type="password" name="password" placeholder="Password" required>
        <label><input type="checkbox" name="remember"> Remember me</label>
        <button type="submit">Login</button>
    </form>
</body>
</html>
