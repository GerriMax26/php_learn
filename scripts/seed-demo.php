<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/src/Database.php';
require $root . '/src/BitrixClient.php';
require $root . '/src/DemoUsers.php';
require $root . '/src/UserService.php';

$db = new Database($root . '/data/app.sqlite');
$service = new UserService($db);
$service->saveSettings('', '', true);
$counts = $service->syncUsers();
echo json_encode($counts, JSON_UNESCAPED_UNICODE) . PHP_EOL;
