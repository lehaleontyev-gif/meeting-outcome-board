<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

// ---- Базовые переменные запроса ----
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

// нормализация: /api/meetings/ -> /api/meetings
$path = rtrim($path, '/');
if ($path === '') $path = '/';



// ---- Debug ----
if ($path === '/api/debug/req') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'method' => $method,
        'path' => $path,
        'content_type' => $_SERVER['CONTENT_TYPE'] ?? null,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ---- Корень сайта (HTML) ----
if (($path === '/' || $path === '/index.html') && $method === 'GET') {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Meeting Outcome Board</title>
</head>
<body style="font-family:Arial;max-width:900px;margin:24px;">
  <h1>Meeting Outcome Board</h1>
  <p>Backend is running ✅</p>
  <ul>
    <li><a href="/api/health">Здоровье</a></li>
    <li><a href="/api/meetings">Встречи</a></li>
    <li><a href="/app.html">Открыть приложение</a></li>
  </ul>
</body>
</html>';
    exit;
}

// ---- Дальше всё JSON API ----
header('Content-Type: application/json; charset=utf-8');

// ---- Health ----
if ($path === '/api/health' && $method === 'GET') {
    echo json_encode(['status' => 'ok', 'time' => date('c')], JSON_UNESCAPED_UNICODE);
    exit;
}

// ---- Meetings list ----
if ($path === '/api/meetings' && $method === 'GET') {
    ensureSchema();
    $pdo = db();

    $rows = $pdo->query(
        "SELECT id, title, goal, status FROM meetings ORDER BY id DESC"
    )->fetchAll();

    $items = [];
    foreach ($rows as $r) {
        $items[] = [
            'id' => (int)$r['id'],
            'title' => (string)$r['title'],
            'goal' => (string)$r['goal'],
            'status' => (string)$r['status'],
            'outcomes' => ['decisions' => 0, 'actions' => 0, 'questions' => 0],
            'is_empty' => true,
            'flags' => ['empty'],
        ];
    }

    echo json_encode(['items' => $items], JSON_UNESCAPED_UNICODE);
    exit;
}

// ---- Create meeting ----
if ($path === '/api/meetings' && $method === 'POST') {
    ensureSchema();
    $pdo = db();

    $raw = file_get_contents('php://input');
    $data = json_decode($raw ?: '[]', true);

    $title = trim((string)($data['title'] ?? ''));
    if ($title === '') {
        http_response_code(400);
        echo json_encode(['error' => 'title is required'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $goal = (string)($data['goal'] ?? '');

    $stmt = $pdo->prepare("
      INSERT INTO meetings (title, goal, status)
      VALUES (:title, :goal, 'draft')
      RETURNING id
    ");
    $stmt->execute([':title' => $title, ':goal' => $goal]);

    $id = (int)$stmt->fetchColumn();

    http_response_code(201);
    echo json_encode([
        'id' => $id,
        'title' => $title,
        'goal' => $goal,
        'status' => 'draft',
        'outcomes' => ['decisions' => 0, 'actions' => 0, 'questions' => 0],
        'is_empty' => true,
        'flags' => ['empty'],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ---- 404 ----
http_response_code(404);
echo json_encode(['error' => 'not found', 'path' => $path], JSON_UNESCAPED_UNICODE);
