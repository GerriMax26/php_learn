<?php


declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

// Загружаем переменные окружения
$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->load();

// Создаём папку для логов в проекте
$logDir = dirname(__DIR__) . '/logs';
if (!is_dir($logDir)) {
    mkdir($logDir, 0777, true);
}

// Перенаправляем логи в файл внутри проекта
ini_set('error_log', $logDir . '/php_errors.log');
ini_set('log_errors', 1);
ini_set('display_errors', 1);
error_reporting(E_ALL);

session_start();

require dirname(__DIR__) . '/src/Database.php';
require dirname(__DIR__) . '/src/BitrixClient.php';
require dirname(__DIR__) . '/src/DemoUsers.php';
require dirname(__DIR__) . '/src/UserService.php';
require dirname(__DIR__) . '/src/CompanyService.php';
require dirname(__DIR__) . '/src/ContactService.php';
require dirname(__DIR__) . '/src/LeadService.php';

$db = new Database(dirname(__DIR__) . '/data/app.sqlite');
$users = new UserService($db);
$companyService = new CompanyService($db, $users);
$contactService = new ContactService($db, $users, $companyService);
$leadService = new LeadService($db, $users, $companyService, $contactService);

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
    // ===== МАРШРУТЫ НАСТРОЕК =====
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

    // ===== МАРШРУТЫ ПОЛЬЗОВАТЕЛЕЙ =====
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

    // ===== МАРШРУТЫ КОМПАНИЙ =====
    if ($path === '/sync-companies' && $method === 'POST') {
        require_csrf();
        $counts = $companyService->syncCompanies();
        json_response(['ok' => true, 'counts' => $counts]);
    }

    if ($path === '/migrate-companies' && $method === 'POST') {
        require_csrf();
        $result = $companyService->migrateCompanies();
        json_response(['ok' => true, 'result' => $result]);
    }

    if ($path === '/companies') {
        render('companies', [
            'title' => 'Перенос компаний',
            'csrf' => $_SESSION['csrf'],
            'cloudCompanies' => $companyService->listCompanies('cloud'),
            'boxCompanies' => $companyService->listCompanies('box'),
            'mappings' => $companyService->getAllMappings(),
            'userMappings' => $users->mappingsByCloudId(),
        ]);
        exit;
    }

    // ===== МАРШРУТЫ КОНТАКТОВ =====
    if ($path === '/sync-contacts' && $method === 'POST') {
        require_csrf();
        $counts = $contactService->syncContacts();
        json_response(['ok' => true, 'counts' => $counts]);
    }

    if ($path === '/migrate-contacts' && $method === 'POST') {
        require_csrf();
        $result = $contactService->migrateContacts();
        json_response(['ok' => true, 'result' => $result]);
    }

    if ($path === '/contacts') {
        render('contacts', [
            'title' => 'Миграция контактов',
            'csrf' => $_SESSION['csrf'],
            'cloudContacts' => $contactService->listContacts('cloud'),
            'boxContacts' => $contactService->listContacts('box'),
            'mappings' => $contactService->getAllMappings(),
            'userMappings' => $users->mappingsByCloudId(),
            'companyMappings' => $companyService->getAllMappings(),
        ]);
        exit;
    }

    // ===== МАРШРУТЫ СТАДИЙ =====
    if ($path === '/sync-stages' && $method === 'POST') {
        require_csrf();
        $counts = $leadService->syncStages();
        json_response(['ok' => true, 'counts' => $counts]);
    }

    // ===== МАРШРУТЫ ЛИДОВ =====
    if ($path === '/sync-leads' && $method === 'POST') {
        require_csrf();
        $counts = $leadService->syncLeads();
        json_response(['ok' => true, 'counts' => $counts]);
    }

    if ($path === '/migrate-leads' && $method === 'POST') {
        require_csrf();
        $result = $leadService->migrateLeads();
        json_response(['ok' => true, 'result' => $result]);
    }

    if ($path === '/leads') {
        render('leads', [
            'title' => 'Миграция лидов',
            'csrf' => $_SESSION['csrf'],
            'cloudLeads' => $leadService->listLeads('cloud'),
            'boxLeads' => $leadService->listLeads('box'),
            'mappings' => $leadService->getAllMappings(),
            'userMappings' => $users->mappingsByCloudId(),
            'companyMappings' => $companyService->getAllMappings(),
            'contactMappings' => $contactService->getAllMappings(),
            'stageMappings' => $leadService->getStageMappings(),
            'cloudStages' => $leadService->listStages('cloud'),
            'boxStages' => $leadService->listStages('box'),
        ]);
        exit;
    }

    // ===== ГЛАВНАЯ СТРАНИЦА =====
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