<?php

declare(strict_types=1);

session_start();

require dirname(__DIR__) . '/src/Database.php';
require dirname(__DIR__) . '/src/BitrixClient.php';
require dirname(__DIR__) . '/src/DemoUsers.php';
require dirname(__DIR__) . '/src/UserService.php';

$db = new Database(dirname(__DIR__) . '/data/app.sqlite');
$users = new UserService($db);

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

function json_response(mixed $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    exit;
}

function require_csrf(): void
{
    $token = $_POST['csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!hash_equals($_SESSION['csrf'] ?? '', (string) $token)) {
        json_response(['ok' => false, 'error' => 'Неверный CSRF-токен'], 403);
    }
}

function render(string $view, array $vars): void
{
    extract($vars, EXTR_SKIP);
    $viewFile = dirname(__DIR__) . '/templates/' . $view . '.php';
    require dirname(__DIR__) . '/templates/layout.php';
}

try {
    if ($path === '/settings' && $method === 'POST') {
        require_csrf();
        $users->saveSettings(
            (string) ($_POST['cloud_webhook'] ?? ''),
            (string) ($_POST['box_webhook'] ?? ''),
            isset($_POST['demo_mode'])
        );
        header('Location: /');
        exit;
    }

    if ($path === '/sync' && $method === 'POST') {
        require_csrf();
        $counts = $users->syncUsers();
        json_response(['ok' => true, 'counts' => $counts]);
    }

    if ($path === '/mappings' && $method === 'POST') {
        require_csrf();
        $raw = file_get_contents('php://input') ?: '';
        $payload = json_decode($raw, true);
        if (!is_array($payload) || !isset($payload['pairs']) || !is_array($payload['pairs'])) {
            json_response(['ok' => false, 'error' => 'Некорректные данные'], 400);
        }

        $pairs = [];
        foreach ($payload['pairs'] as $pair) {
            if (!is_array($pair)) {
                continue;
            }
            $pairs[] = [
                'cloud_user_id' => (int) ($pair['cloud_user_id'] ?? 0),
                'box_user_id' => isset($pair['box_user_id']) && $pair['box_user_id'] !== '' && $pair['box_user_id'] !== null
                    ? (int) $pair['box_user_id']
                    : null,
            ];
        }

        $users->saveMappings($pairs);
        json_response(['ok' => true, 'saved' => count(array_filter($pairs, static fn ($p) => $p['box_user_id']))]);
    }

    if ($path === '/settings') {
        render('settings', [
            'title' => 'Подключение порталов',
            'csrf' => $_SESSION['csrf'],
            'cloudWebhook' => $users->webhook('cloud'),
            'boxWebhook' => $users->webhook('box'),
            'demoMode' => $users->demoMode(),
            'flash' => null,
        ]);
        exit;
    }

    if ($path !== '/') {
        http_response_code(404);
        echo 'Not found';
        exit;
    }

    if (!$users->isConfigured()) {
        header('Location: /settings');
        exit;
    }

    render('mapping', [
        'title' => 'Сопоставление пользователей',
        'csrf' => $_SESSION['csrf'],
        'demoMode' => $users->demoMode(),
        'cloudUsers' => $users->listUsers('cloud'),
        'boxUsers' => $users->listUsers('box'),
        'mappings' => $users->mappingsByCloudId(),
    ]);
} catch (Throwable $e) {
    if (str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') || $method === 'POST' && $path !== '/settings') {
        json_response(['ok' => false, 'error' => $e->getMessage()], 500);
    }

    http_response_code(500);
    echo htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
