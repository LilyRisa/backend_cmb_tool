<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#0a0a0f">
    <title>CMB Core - User Portal</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <meta name="description" content="CMB Core User Portal - TTS, Credit Management, Voice Generation">

    @viteReactRefresh
    @vite(['resources/scss/app.scss', 'resources/js/react/tool-main.jsx'])
</head>

<body>

    <script>
        window.__TURNSTILE_SITE_KEY = "{{ config('services.cloudflare_turnstile.site_key', '') }}";
    </script>
    <div id="root"></div>

    <script src="https://cdn.jsdelivr.net/npm/popper.js@1.16.1/dist/umd/popper.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.min.js"></script>
</body>

</html>
