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
                <a href="/settings" class="<?= ($view ?? '') === 'settings' ? 'is-active' : '' ?>">
                    🔌 Подключение
                </a>
                <a href="/" class="<?= ($view ?? '') === 'mapping' ? 'is-active' : '' ?>">
                    👤 Маппинг пользователей
                </a>
                <a href="/companies" class="<?= ($view ?? '') === 'companies' ? 'is-active' : '' ?>">
                    🏢 Миграция компаний
                </a>
                <a href="/contacts" class="<?= ($view ?? '') === 'contacts' ? 'is-active' : '' ?>">
                    📇 Миграция контактов
                </a>
                <a href="/leads" class="<?= ($view ?? '') === 'leads' ? 'is-active' : '' ?>">
                    💼 Миграция лидов
                </a>
                <!-- Для будущих пунктов -->
                <!--
                <a href="/deals" class="<?= ($view ?? '') === 'deals' ? 'is-active' : '' ?>">
                    📊 Миграция сделок
                </a>
                -->
            </nav>
            <p class="sidebar-note">
                <strong>Порядок миграции:</strong><br>
                1. 🔌 Настройте подключение<br>
                2. 👤 Сопоставьте пользователей<br>
                3. 🏢 Перенесите компании<br>
                4. 📇 Перенесите контакты<br>
                5. 💼 Перенесите лиды
            </p>
        </aside>
        <main class="main">
            <?php require $viewFile; ?>
        </main>
    </div>
    <script src="/assets/app.js"></script>
</body>
</html>