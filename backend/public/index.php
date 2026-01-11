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

    $rows = $pdo->query("
      SELECT
        m.id, m.title, m.goal, m.status,
        (SELECT COUNT(*) FROM decisions d WHERE d.meeting_id = m.id) AS decisions_count,
        (SELECT COUNT(*) FROM tasks t WHERE t.meeting_id = m.id) AS tasks_count
      FROM meetings m
      ORDER BY m.id DESC
    ")->fetchAll(PDO::FETCH_ASSOC);

    $items = [];
    foreach ($rows as $r) {
        $items[] = [
            'id' => (int)$r['id'],
            'title' => $r['title'],
            'goal' => $r['goal'],
            'status' => $r['status'],
            'outcomes' => [
                'decisions' => (int)$r['decisions_count'],
                'actions' => (int)$r['tasks_count'],
                'questions' => 0
            ],
            'is_empty' => ((int)$r['decisions_count'] + (int)$r['tasks_count']) === 0,
            'flags' => []
        ];
    }

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['items' => $items], JSON_UNESCAPED_UNICODE);
    exit;
}
if (preg_match('#^/api/meetings/(\d+)$#', $path, $m) && $method === 'GET') {
    ensureSchema();
    $pdo = db();
    $id = (int)$m[1];

    $meeting = $pdo->prepare("SELECT * FROM meetings WHERE id = :id");
    $meeting->execute([':id' => $id]);
    $meeting = $meeting->fetch(PDO::FETCH_ASSOC);

    if (!$meeting) {
        http_response_code(404);
        echo json_encode(['error' => 'not found']);
        exit;
    }

    $decisions = $pdo->prepare("SELECT * FROM decisions WHERE meeting_id = :id ORDER BY id DESC");
    $decisions->execute([':id' => $id]);

    $tasks = $pdo->prepare("SELECT * FROM tasks WHERE meeting_id = :id ORDER BY id DESC");
    $tasks->execute([':id' => $id]);

    echo json_encode([
        'meeting' => $meeting,
        'decisions' => $decisions->fetchAll(PDO::FETCH_ASSOC),
        'tasks' => $tasks->fetchAll(PDO::FETCH_ASSOC),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
if (preg_match('#^/api/meetings/(\d+)/decisions$#', $path, $m) && $method === 'POST') {
    ensureSchema();
    $pdo = db();
    $id = (int)$m[1];

    $data = json_decode(file_get_contents('php://input'), true);
    $title = trim($data['title'] ?? '');

    if ($title === '') {
        http_response_code(400);
        echo json_encode(['error' => 'title required']);
        exit;
    }

    $stmt = $pdo->prepare("
      INSERT INTO decisions (meeting_id, title, owner, status)
      VALUES (:id, :title, :owner, :status)
    ");
    $stmt->execute([
        ':id' => $id,
        ':title' => $title,
        ':owner' => $data['owner'] ?? '',
        ':status' => $data['status'] ?? 'active',
    ]);

    http_response_code(201);
    echo json_encode(['ok' => true]);
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
if (preg_match('#^/api/meetings/(\d+)/tasks$#', $path, $m) && $method === 'POST') {
    ensureSchema();
    $pdo = db();
    $id = (int)$m[1];

    $data = json_decode(file_get_contents('php://input'), true);
    $title = trim($data['title'] ?? '');

    if ($title === '') {
        http_response_code(400);
        echo json_encode(['error' => 'title required']);
        exit;
    }

    $stmt = $pdo->prepare("
      INSERT INTO tasks (meeting_id, title, assignee, due_date, status)
      VALUES (:id, :title, :assignee, :due_date, :status)
    ");
    $stmt->execute([
        ':id' => $id,
        ':title' => $title,
        ':assignee' => $data['assignee'] ?? '',
        ':due_date' => $data['due_date'] ?? null,
        ':status' => $data['status'] ?? 'open',
    ]);

    http_response_code(201);
    echo json_encode(['ok' => true]);
    exit;
}


// ---- Meeting by id ----
if (preg_match('#^/api/meetings/(\d+)$#', $path, $m) && $method === 'GET') {
    ensureSchema();
    $pdo = db();

    $id = (int)$m[1];

    $stmt = $pdo->prepare("SELECT id, title, goal, status, created_at FROM meetings WHERE id = :id");
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        http_response_code(404);
        echo json_encode(['error' => 'meeting not found', 'id' => $id], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode([
        'id' => (int)$row['id'],
        'title' => (string)$row['title'],
        'goal' => (string)$row['goal'],
        'status' => (string)$row['status'],
        'created_at' => (string)$row['created_at'],
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

// ---- 404 ----
http_response_code(404);
echo json_encode(['error' => 'not found', 'path' => $path], JSON_UNESCAPED_UNICODE);
