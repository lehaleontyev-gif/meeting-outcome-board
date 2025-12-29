<?php
declare(strict_types=1);

function ensureSchema(): void {
    $pdo = db();

    // ENUMs
    $pdo->exec("
    DO $$
    BEGIN
      IF NOT EXISTS (SELECT 1 FROM pg_type WHERE typname = 'task_status') THEN
        CREATE TYPE task_status AS ENUM ('open','in_progress','done','canceled');
      END IF;

      IF NOT EXISTS (SELECT 1 FROM pg_type WHERE typname = 'decision_status') THEN
        CREATE TYPE decision_status AS ENUM ('active','revoked','superseded');
      END IF;
    END $$;
    ");

    // users
    $pdo->exec("
      CREATE TABLE IF NOT EXISTS users (
        id SERIAL PRIMARY KEY,
        first_name TEXT NOT NULL,
        last_name TEXT NOT NULL,
        email TEXT NOT NULL UNIQUE,
        password_hash TEXT NULL,
        organization TEXT NULL,
        created_at TIMESTAMPTZ NOT NULL DEFAULT now()
      );
    ");

    // meetings
    $pdo->exec("
      CREATE TABLE IF NOT EXISTS meetings (
        id SERIAL PRIMARY KEY,
        title TEXT NOT NULL,
        goal TEXT NOT NULL DEFAULT '',
        created_at TIMESTAMPTZ NOT NULL DEFAULT now()
      );
    ");

    // decisions
    $pdo->exec("
      CREATE TABLE IF NOT EXISTS decisions (
        id SERIAL PRIMARY KEY,
        meeting_id INT NOT NULL REFERENCES meetings(id) ON DELETE CASCADE,
        title TEXT NOT NULL,
        rationale TEXT NULL,
        owner_user_id INT NULL REFERENCES users(id),
        status decision_status NOT NULL DEFAULT 'active',
        decided_at TIMESTAMPTZ NOT NULL DEFAULT now()
      );
    ");

    // tasks
    $pdo->exec("
      CREATE TABLE IF NOT EXISTS tasks (
        id SERIAL PRIMARY KEY,
        meeting_id INT NOT NULL REFERENCES meetings(id) ON DELETE CASCADE,
        decision_id INT NULL REFERENCES decisions(id),
        title TEXT NOT NULL,
        assignee_user_id INT NULL REFERENCES users(id),
        due_at TIMESTAMPTZ NULL,
        status task_status NOT NULL DEFAULT 'open',
        created_at TIMESTAMPTZ NOT NULL DEFAULT now()
      );
    ");
}


function db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;

    $url = getenv('DATABASE_URL');
    if (!$url) {
        throw new RuntimeException('DATABASE_URL is not set');
    }

    $parts = parse_url($url);
    if (!$parts) {
        throw new RuntimeException('Invalid DATABASE_URL');
    }

    $host = $parts['host'] ?? 'localhost';
    $port = $parts['port'] ?? 5432;
    $user = $parts['user'] ?? '';
    $pass = $parts['pass'] ?? '';
    $db   = ltrim($parts['path'] ?? '', '/');

    $dsn = "pgsql:host={$host};port={$port};dbname={$db};sslmode=require";

    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    return $pdo;
}
?>
