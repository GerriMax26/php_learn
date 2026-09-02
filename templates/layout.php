<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($title ?? 'Bitrix migrate', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></title>
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body>
    <div class="shell">
        <aside class="sidebar">
            <div class="brand">
                <span class="brand-mark">B24</span>
                <div>
                    <strong>Миграция</strong>
                    <small>облако → коробка</small>
                </div>
            </div>
            <nav>
                <a href="/" class="<?= ($view ?? '') === 'mapping' ? 'is-active' : '' ?>">Пользователи</a>
                <a href="/settings" class="<?= ($view ?? '') === 'settings' ? 'is-active' : '' ?>">Подключение</a>
            </nav>
            <p class="sidebar-note">Шаг 1: сопоставление учёток через <code>user.get</code>.</p>
        </aside>
        <main class="main">
            <?php require $viewFile; ?>
        </main>
    </div>
    <script src="/assets/app.js"></script>
</body>
</html>
