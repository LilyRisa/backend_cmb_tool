<!DOCTYPE html>
<html>
<body>
    <p>Xin chào {{ $user->name }},</p>
    <p>Vui lòng nhấn vào liên kết dưới đây để xác minh email của bạn (hết hạn sau 24 giờ):</p>
    <p><a href="{{ $verificationUrl }}">{{ $verificationUrl }}</a></p>
</body>
</html>
