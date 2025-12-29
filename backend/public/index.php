<?php

// Красивый ответ для корня сайта
if ($path === '/' && $method === 'GET') {
  header('Content-Type: text/html; charset=utf-8');
  echo '<!doctype html><html><head><meta charset="utf-8"><title>Meeting Outcome Board</title></head><body style="font-family:Arial;max-width:900px;margin:24px;">';
  echo '<h1>Meeting Outcome Board</h1>';
  echo '<p>Backend is running ✅</p>';
  echo '<ul>';
  echo '<li><a href="/api/health">/api/health</a></li>';
  echo '<li><a href="/api/meetings">/api/meetings</a></li>';
  echo '</ul>';
  echo '</body></html>';
  exit;
}


declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

// Простой роутинг
if ($path === '/api/health') {
  echo json_encode(['status' => 'ok', 'time' => date('c')], JSON_UNESCAPED_UNICODE);
  exit;
}

if ($path === '/api/meetings' && $method === 'GET') {
  // Пока мок-данные (позже заменим на Postgres)
  $meetings = [
    [
      'id' => 1,
      'title' => 'Daily sync',
      'goal' => 'Align on next steps',
      'status' => 'finished',
      'outcomes' => ['decisions' => 1, 'actions' => 2, 'questions' => 0],
      'is_empty' => false
    ],
    [
      'id' => 2,
      'title' => 'Discussion with vendor',
      'goal' => 'Decide integration approach',
      'status' => 'finished',
      'outcomes' => ['decisions' => 0, 'actions' => 0, 'questions' => 0],
      'is_empty' => true
    ],
  ];

  echo json_encode(['items' => $meetings], JSON_UNESCAPED_UNICODE);
  exit;
}

if ($path === '/api/meetings' && $method === 'POST') {
  $raw = file_get_contents('php://input');
  $data = json_decode($raw ?: '[]', true);

  $title = trim((string)($data['title'] ?? ''));
  if ($title === '') {
    http_response_code(400);
    echo json_encode(['error' => 'title is required'], JSON_UNESCAPED_UNICODE);
    exit;
  }

  // Пока без БД: просто "создали" и вернули объект
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

http_response_code(404);
echo json_encode(['error' => 'not found', 'path' => $path], JSON_UNESCAPED_UNICODE);
?>
