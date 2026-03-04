<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Удаление аккаунта</title>
    <style>
        body { font-family: Arial, sans-serif; background: #f3f4f6; margin: 0; padding: 40px 20px; }
        .container { max-width: 480px; margin: 0 auto; background: #fff; border-radius: 8px; padding: 40px; }
        h2 { color: #111827; margin-top: 0; }
        .code { font-size: 36px; font-weight: bold; letter-spacing: 8px; color: #1d4ed8; text-align: center; margin: 24px 0; }
        p { color: #6b7280; line-height: 1.6; }
        .warning { color: #b91c1c; font-size: 13px; }
    </style>
</head>
<body>
    <div class="container">
        <h2>Удаление аккаунта</h2>
        <p>Вы запросили удаление аккаунта в приложении <strong>Padel</strong>.</p>
        <p>Ваш код подтверждения:</p>
        <div class="code">{{ $code }}</div>
        <p>Код действителен в течение <strong>10 минут</strong>.</p>
        <p class="warning">⚠ Если вы не запрашивали удаление — проигнорируйте это письмо.</p>
    </div>
</body>
</html>
