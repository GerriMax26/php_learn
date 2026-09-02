<?php
/** @var string $csrf */
/** @var string $cloudWebhook */
/** @var string $boxWebhook */
/** @var bool $demoMode */
$view = 'settings';
?>
<section class="page">
    <header class="page-head">
        <h1>Подключение порталов</h1>
        <p>Входящие вебхуки REST API облака и коробки. Метод <code>user.get</code> вызывается с пагинацией и <code>ADMIN_MODE</code>.</p>
    </header>

    <form class="card form" method="post" action="/settings">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">

        <label>
            Вебхук облака
            <input type="url" name="cloud_webhook" placeholder="https://company.bitrix24.ru/rest/1/xxxxxxxxxxxxxxxx/"
                   value="<?= htmlspecialchars($cloudWebhook, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
        </label>

        <label>
            Вебхук коробки
            <input type="url" name="box_webhook" placeholder="https://portal.company.local/rest/1/xxxxxxxxxxxxxxxx/"
                   value="<?= htmlspecialchars($boxWebhook, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
        </label>

        <label class="check">
            <input type="checkbox" name="demo_mode" value="1" <?= $demoMode ? 'checked' : '' ?>>
            Демо-режим (тестовые пользователи, без запросов к Bitrix)
        </label>

        <p class="hint">
            Вебхук создаётся в Битрикс24: Приложения → Разработчикам → Другое → Входящий вебхук.
            Нужны права на пользователей. URL хранится локально в SQLite.
        </p>

        <button type="submit" class="btn primary">Сохранить</button>
    </form>
</section>
