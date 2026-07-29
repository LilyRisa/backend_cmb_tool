<!DOCTYPE html>
<html>
<body>
    @if($expired ?? false)
        <h1>Liên kết đặt lại mật khẩu đã hết hạn hoặc không hợp lệ.</h1>
    @else
        <form method="POST" action="/password/reset">
            @csrf
            <input type="hidden" name="token" value="{{ $token }}">
            <input type="hidden" name="email" value="{{ $email }}">
            <input type="password" name="password" placeholder="Mật khẩu mới" required>
            <input type="password" name="password_confirmation" placeholder="Xác nhận mật khẩu" required>
            <button type="submit">Đặt lại mật khẩu</button>
        </form>
    @endif
</body>
</html>
