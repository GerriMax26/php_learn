<?php

declare(strict_types=1);

$pdo = new PDO('sqlite:' . dirname(__DIR__) . '/data/app.sqlite');
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
echo "mappings:\n";
foreach ($pdo->query('SELECT * FROM user_mappings ORDER BY cloud_user_id') as $row) {
    echo json_encode($row, JSON_UNESCAPED_UNICODE) . PHP_EOL;
}
