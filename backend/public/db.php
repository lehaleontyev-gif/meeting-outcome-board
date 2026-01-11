<?php
declare(strict_types=1);

function db(): PDO {
    $url = getenv('DATABASE_URL');
    if (!$url) {
        throw new RuntimeException('DATABASE_URL is not set');
    }

    $parts = parse_url($url);
    $dsn = sprintf(
        'pgsql:host=%s;port=%s;dbname=%s',
        $parts['host'],
        $parts['port'] ?? 5432,
        ltrim($parts['path'], '/')
    );

    return new PDO(
        $dsn,
        $parts['user'],
        $parts['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
}

function ensureSchema(): void {
    $pdo = db();

    // meetings
    $pdo->exec("
      CREATE TABLE IF NOT EXISTS meetings (
        id SERIAL PRIMARY KEY,
        title TEXT NOT NULL,
        goal TEXT NOT NULL DEFAULT '',
        status TEXT NOT NULL DEFAULT 'draft',
        created_at TIMESTAMPTZ NOT NULL DEFAULT now()
      );
    ");

    // enums
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

    // decisions
    $pdo->exec("
      CREATE TABLE IF NOT EXISTS decisions (
        id SERIAL PRIMARY KEY,
        meeting_id INT NOT NULL REFERENCES meetings(id) ON DELETE CASCADE,
        title TEXT NOT NULL,
        owner TEXT NOT NULL DEFAULT '',
        status decision_status NOT NULL DEFAULT 'active',
        created_at TIMESTAMPTZ NOT NULL DEFAULT now()
      );
    ");

    // tasks
    $pdo->exec("
      CREATE TABLE IF NOT EXISTS tasks (
        id SERIAL PRIMARY KEY,
        meeting_id INT NOT NULL REFERENCES meetings(id) ON DELETE CASCADE,
        title TEXT NOT NULL,
        assignee TEXT NOT NULL DEFAULT '',
        due_date DATE NULL,
        status task_status NOT NULL DEFAULT 'open',
        created_at TIMESTAMPTZ NOT NULL DEFAULT now()
      );
    ");
}
