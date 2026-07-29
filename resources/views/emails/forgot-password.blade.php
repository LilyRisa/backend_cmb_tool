<!DOCTYPE html>
<html>
<body>
    <p>Xin chào {{ $user->name }},</p>
    <p>Nhấn vào liên kết dưới đây để đặt lại mật khẩu (hết hạn sau 60 phút):</p>
    <p><a href="{{ $resetUrl }}">{{ $resetUrl }}</a></p>
</body>
</html>
