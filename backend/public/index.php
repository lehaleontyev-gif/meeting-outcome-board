<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

function ensureSchema(): void {
    $pdo = db();
    $pdo->exec("
      CREATE TABLE IF NOT EXISTS meetings (
        id SERIAL PRIMARY KEY,
        title TEXT NOT NULL,
        goal TEXT NOT NULL DEFAULT '',
        status TEXT NOT NULL DEFAULT 'draft',
        created_at TIMESTAMPTZ NOT NULL DEFAULT now()
      );
    ");
}


// ---- Базовые переменные запроса ----
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

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
    echo json_encode(
        ['status' => 'ok', 'time' => date('c')],
        JSON_UNESCAPED_UNICODE
    );
    exit;
}

// ---- Meetings list ----
ensureSchema();
$pdo = db();

$rows = $pdo->query(
  "SELECT id, title, goal, status FROM meetings ORDER BY id DESC"
)->fetchAll();

$items = [];
foreach ($rows as $r) {
  $items[] = [
    'id' => (int)$r['id'],
    'title' => $r['title'],
    'goal' => $r['goal'],
    'status' => $r['status'],
    'outcomes' => ['decisions' => 0, 'actions' => 0, 'questions' => 0],
    'is_empty' => true,
    'flags' => ['empty'],
  ];
}

echo json_encode(['items' => $items], JSON_UNESCAPED_UNICODE);
exit;

// ---- Create meeting ----
if ($path === '/api/meetings' && $method === 'POST') {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw ?: '[]', true);

    $title = trim((string)($data['title'] ?? ''));
    if ($title === '') {
        http_response_code(400);
        echo json_encode(['error' => 'title is required'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $meeting = [
        'id' => random_int(1000, 9999),
        'title' => $title,
        'goal' => (string)($data['goal'] ?? ''),
        'status' => 'draft',
        'outcomes' => ['decisions' => 0, 'actions' => 0, 'questions' => 0],
        'is_empty' => true
    ];

    http_response_code(201);
    echo json_encode($meeting, JSON_UNESCAPED_UNICODE);
    exit;
}

// ---- 404 ----
http_response_code(404);
echo json_encode(['error' => 'not found', 'path' => $path], JSON_UNESCAPED_UNICODE);

?>
