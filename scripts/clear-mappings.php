<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/src/Database.php';

$db = new Database($root . '/data/app.sqlite');
$db->pdo()->exec('DELETE FROM user_mappings');

echo "✅ Все сопоставления удалены.\n";