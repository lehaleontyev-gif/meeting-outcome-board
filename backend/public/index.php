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

/**
 * Безопасное чтение JSON тела запроса.
 */
function readJsonBody(): array {
    $raw = file_get_contents('php://input') ?: '';
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

/**
 * Быстрая выдача JSON-ошибки
 */
function jsonError(int $code, string $msg, array $extra = []): void {
    http_response_code($code);
    echo json_encode(array_merge(['error' => $msg], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

// ------------------------------------------------------------
// ВАЖНО: сначала самые конкретные POST-роуты, потом GET.
// ------------------------------------------------------------

// ---- Add decision ----
if (preg_match('#^/api/meetings/(\d+)/decisions$#', $path, $m) && $method === 'POST') {
    ensureSchema();
    $pdo = db();
    $meetingId = (int)$m[1];

    $data = readJsonBody();

    $title = trim((string)($data['title'] ?? ''));
    if ($title === '') jsonError(400, 'title required');

    $owner = (string)($data['owner'] ?? '');
    $status = (string)($data['status'] ?? 'active');

    // защита от кривых статусов (ENUM)
    if (!in_array($status, ['active', 'revoked', 'superseded'], true)) {
        $status = 'active';
    }

    $stmt = $pdo->prepare("
      INSERT INTO decisions (meeting_id, title, owner, status)
      VALUES (:meeting_id, :title, :owner, :status)
      RETURNING id
    ");
    $stmt->execute([
        ':meeting_id' => $meetingId,
        ':title' => $title,
        ':owner' => $owner,
        ':status' => $status,
    ]);

    $id = (int)$stmt->fetchColumn();

    http_response_code(201);
    echo json_encode(['ok' => true, 'id' => $id], JSON_UNESCAPED_UNICODE);
    exit;
}

// ---- Add task ----
if (preg_match('#^/api/meetings/(\d+)/tasks$#', $path, $m) && $method === 'POST') {
    ensureSchema();
    $pdo = db();
    $meetingId = (int)$m[1];

    $data = readJsonBody();

    $title = trim((string)($data['title'] ?? ''));
    if ($title === '') jsonError(400, 'title required');

    $assignee = (string)($data['assignee'] ?? '');
    $due_date = $data['due_date'] ?? null;
    $status = (string)($data['status'] ?? 'open');

    if (!in_array($status, ['open', 'in_progress', 'done', 'canceled'], true)) {
        $status = 'open';
    }

    $stmt = $pdo->prepare("
      INSERT INTO tasks (meeting_id, title, assignee, due_date, status)
      VALUES (:meeting_id, :title, :assignee, :due_date, :status)
      RETURNING id
    ");
    $stmt->execute([
        ':meeting_id' => $meetingId,
        ':title' => $title,
        ':assignee' => $assignee,
        ':due_date' => $due_date,
        ':status' => $status,
    ]);

    $id = (int)$stmt->fetchColumn();

    http_response_code(201);
    echo json_encode(['ok' => true, 'id' => $id], JSON_UNESCAPED_UNICODE);
    exit;
}

// ---- Create meeting ----
if ($path === '/api/meetings' && $method === 'POST') {
    ensureSchema();
    $pdo = db();

    $data = readJsonBody();

    $title = trim((string)($data['title'] ?? ''));
    if ($title === '') jsonError(400, 'title is required');

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
        $dec = (int)$r['decisions_count'];
        $tsk = (int)$r['tasks_count'];
        $items[] = [
            'id' => (int)$r['id'],
            'title' => (string)$r['title'],
            'goal' => (string)$r['goal'],
            'status' => (string)$r['status'],
            'outcomes' => [
                'decisions' => $dec,
                'actions' => $tsk,
                'questions' => 0
            ],
            'is_empty' => ($dec + $tsk) === 0,
            'flags' => []
        ];
    }

    echo json_encode(['items' => $items], JSON_UNESCAPED_UNICODE);
    exit;
}

// ---- Meeting by id (полная карточка + решения + задачи) ----
if (preg_match('#^/api/meetings/(\d+)$#', $path, $m) && $method === 'GET') {
    ensureSchema();
    $pdo = db();
    $id = (int)$m[1];

    $stmt = $pdo->prepare("SELECT id, title, goal, status, created_at FROM meetings WHERE id = :id");
    $stmt->execute([':id' => $id]);
    $meeting = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$meeting) {
        jsonError(404, 'meeting not found', ['id' => $id]);
    }

    $decStmt = $pdo->prepare("SELECT id, title, owner, status, created_at FROM decisions WHERE meeting_id = :id ORDER BY id DESC");
    $decStmt->execute([':id' => $id]);

    $taskStmt = $pdo->prepare("SELECT id, title, assignee, due_date, status, created_at FROM tasks WHERE meeting_id = :id ORDER BY id DESC");
    $taskStmt->execute([':id' => $id]);

    echo json_encode([
        'meeting' => $meeting,
        'decisions' => $decStmt->fetchAll(PDO::FETCH_ASSOC),
        'tasks' => $taskStmt->fetchAll(PDO::FETCH_ASSOC),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ---- 404 ----
http_response_code(404);
echo json_encode(['error' => 'not found', 'path' => $path], JSON_UNESCAPED_UNICODE);
