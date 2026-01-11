<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

// ---- Базовые переменные запроса ----
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);


function clampInt(int $v, int $min, int $max): int {
  return max($min, min($max, $v));
}

/**
 * Возвращает ['score'=>int 0..100, 'flags'=>string[]]
 */
function calcMeetingQualityFromCounts(int $decisionsCount, int $tasksCount, int $tasksWithDue, int $decisionsWithOwner, string $meetingStatus = 'draft'): array {
  $flags = [];

  if (($decisionsCount + $tasksCount) === 0) $flags[] = 'empty_meeting';
  if ($tasksCount > 8) $flags[] = 'too_many_tasks';

  if ($tasksCount > 0) {
    $ratio = $tasksWithDue / max(1, $tasksCount);
    if ($ratio < 0.5) $flags[] = 'tasks_without_due_dates';
  }

  if ($decisionsCount > 0) {
    $ratio = $decisionsWithOwner / max(1, $decisionsCount);
    if ($ratio < 0.5) $flags[] = 'decisions_without_owner';
  }

  $score = 0;

  // Outcome (0..60)
  if ($decisionsCount > 0) $score += 15;
  if ($tasksCount > 0) $score += 10;
  if ($tasksCount > 0 && ($tasksWithDue / max(1,$tasksCount)) >= 0.5) $score += 10;
  if ($decisionsCount > 0 && ($decisionsWithOwner / max(1,$decisionsCount)) >= 0.5) $score += 10;
  if (($decisionsCount + $tasksCount) > 0) $score += 15;

  // Hygiene penalties
  if ($tasksCount > 8) $score -= 15;
  if ($tasksCount > 0 && ($tasksWithDue / max(1,$tasksCount)) < 0.5) $score -= 10;
  if ($decisionsCount > 0 && ($decisionsWithOwner / max(1,$decisionsCount)) < 0.5) $score -= 10;
  if (($decisionsCount + $tasksCount) > 5 && $meetingStatus === 'draft') $score -= 5;

  $score = clampInt($score, 0, 100);

  return ['score' => $score, 'flags' => $flags];
}

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
        (SELECT COUNT(*) FROM tasks t WHERE t.meeting_id = m.id) AS tasks_count,
        (SELECT COUNT(*) FROM tasks t WHERE t.meeting_id = m.id AND t.due_date IS NOT NULL) AS tasks_with_due,
        (SELECT COUNT(*) FROM decisions d
          WHERE d.meeting_id = m.id AND COALESCE(NULLIF(d.owner,''), '') <> ''
        ) AS decisions_with_owner
      FROM meetings m
      ORDER BY m.id DESC
    ")->fetchAll(PDO::FETCH_ASSOC);

    $items = [];
    foreach ($rows as $r) {
        $dc  = (int)$r['decisions_count'];
        $tc  = (int)$r['tasks_count'];
        $twd = (int)$r['tasks_with_due'];
        $dwo = (int)$r['decisions_with_owner'];

        $quality = calcMeetingQualityFromCounts($dc, $tc, $twd, $dwo, (string)$r['status']);

        $items[] = [
            'id' => (int)$r['id'],
            'title' => (string)$r['title'],
            'goal' => (string)$r['goal'],
            'status' => (string)$r['status'],
            'outcomes' => [
                'decisions' => $dc,
                'actions' => $tc,
                'questions' => 0
            ],
            'is_empty' => ($dc + $tc) === 0,
            'flags' => [],
            'quality' => $quality,
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
$decisionsStmt = $pdo->prepare("SELECT * FROM decisions WHERE meeting_id = :id ORDER BY id DESC");
$decisionsStmt->execute([':id' => $id]);
$decisions = $decisionsStmt->fetchAll(PDO::FETCH_ASSOC);

$tasksStmt = $pdo->prepare("SELECT * FROM tasks WHERE meeting_id = :id ORDER BY id DESC");
$tasksStmt->execute([':id' => $id]);
$tasks = $tasksStmt->fetchAll(PDO::FETCH_ASSOC);

  $decisionsCount = count($decisions);
$tasksCount = count($tasks);

$tasksWithDue = 0;
foreach ($tasks as $t) {
  if (!empty($t['due_date'])) $tasksWithDue++;
}

$decisionsWithOwner = 0;
foreach ($decisions as $d) {
  if (!empty(trim((string)($d['owner'] ?? '')))) $decisionsWithOwner++;
}

$quality = calcMeetingQualityFromCounts(
  $decisionsCount,
  $tasksCount,
  $tasksWithDue,
  $decisionsWithOwner,
  (string)($meeting['status'] ?? 'draft')
);

    echo json_encode([
  'meeting' => $meeting,
  'decisions' => $decisions,
  'tasks' => $tasks,
  'quality' => $quality,
], JSON_UNESCAPED_UNICODE);
    exit;
}

// ---- 404 ----
http_response_code(404);
echo json_encode(['error' => 'not found', 'path' => $path], JSON_UNESCAPED_UNICODE);
