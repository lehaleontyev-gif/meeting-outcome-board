<?php
declare(strict_types=1);

function db(): PDO {
    $url = getenv('DATABASE_URL');
    if (!$url) {
        throw new RuntimeException('DATABASE_URL is not set');
    }

    $parts = parse_url($url);
    if (!is_array($parts) || empty($parts['host']) || empty($parts['path']) || empty($parts['user'])) {
        throw new RuntimeException('DATABASE_URL is invalid');
    }

    $dsn = sprintf(
        'pgsql:host=%s;port=%s;dbname=%s',
        $parts['host'],
        $parts['port'] ?? 5432,
        ltrim($parts['path'], '/')
    );

    return new PDO(
        $dsn,
        $parts['user'],
        $parts['pass'] ?? '',
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
}

function ensureSchema(): void {
    $pdo = db();

    // 1) Base tables (create if not exists)
    $pdo->exec("
      CREATE TABLE IF NOT EXISTS meetings (
        id SERIAL PRIMARY KEY,
        title TEXT NOT NULL,
        goal TEXT NOT NULL DEFAULT '',
        status TEXT NOT NULL DEFAULT 'draft',
        created_at TIMESTAMPTZ NOT NULL DEFAULT now()
      );
    ");

    // 2) Enums (create if not exists)
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

    // 3) Create tables with correct schema (for fresh DB)
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

    // ------------------------------------------------------------
    // 4) Migrations for existing tables (add missing columns)
    // ------------------------------------------------------------

    // meetings columns
    $pdo->exec("
    DO $$
    BEGIN
      IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='meetings' AND column_name='goal') THEN
        ALTER TABLE meetings ADD COLUMN goal TEXT NOT NULL DEFAULT '';
      END IF;

      IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='meetings' AND column_name='status') THEN
        ALTER TABLE meetings ADD COLUMN status TEXT NOT NULL DEFAULT 'draft';
      END IF;

      IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='meetings' AND column_name='created_at') THEN
        ALTER TABLE meetings ADD COLUMN created_at TIMESTAMPTZ NOT NULL DEFAULT now();
      END IF;
    END $$;
    ");

    // decisions columns
    $pdo->exec("
    DO $$
    BEGIN
      IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='decisions' AND column_name='meeting_id') THEN
        ALTER TABLE decisions ADD COLUMN meeting_id INT;
      END IF;

      IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='decisions' AND column_name='title') THEN
        ALTER TABLE decisions ADD COLUMN title TEXT NOT NULL DEFAULT '';
      END IF;

      IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='decisions' AND column_name='owner') THEN
        ALTER TABLE decisions ADD COLUMN owner TEXT NOT NULL DEFAULT '';
      END IF;

      IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='decisions' AND column_name='created_at') THEN
        ALTER TABLE decisions ADD COLUMN created_at TIMESTAMPTZ NOT NULL DEFAULT now();
      END IF;
    END $$;
    ");

    // tasks columns
    $pdo->exec("
    DO $$
    BEGIN
      IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='tasks' AND column_name='meeting_id') THEN
        ALTER TABLE tasks ADD COLUMN meeting_id INT;
      END IF;

      IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='tasks' AND column_name='title') THEN
        ALTER TABLE tasks ADD COLUMN title TEXT NOT NULL DEFAULT '';
      END IF;

      IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='tasks' AND column_name='assignee') THEN
        ALTER TABLE tasks ADD COLUMN assignee TEXT NOT NULL DEFAULT '';
      END IF;

      IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='tasks' AND column_name='due_date') THEN
        ALTER TABLE tasks ADD COLUMN due_date DATE NULL;
      END IF;

      IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='tasks' AND column_name='created_at') THEN
        ALTER TABLE tasks ADD COLUMN created_at TIMESTAMPTZ NOT NULL DEFAULT now();
      END IF;
    END $$;
    ");

    // ------------------------------------------------------------
    // 5) Make sure foreign keys exist (best-effort)
    // (If meeting_id was added later, it might be NULL. We won't force NOT NULL here.)
    // ------------------------------------------------------------
    $pdo->exec("
    DO $$
    BEGIN
      IF NOT EXISTS (
        SELECT 1 FROM pg_constraint WHERE conname = 'decisions_meeting_id_fkey'
      ) THEN
        BEGIN
          ALTER TABLE decisions
            ADD CONSTRAINT decisions_meeting_id_fkey
            FOREIGN KEY (meeting_id) REFERENCES meetings(id) ON DELETE CASCADE;
        EXCEPTION WHEN duplicate_object THEN
          -- ignore
        END;
      END IF;

      IF NOT EXISTS (
        SELECT 1 FROM pg_constraint WHERE conname = 'tasks_meeting_id_fkey'
      ) THEN
        BEGIN
          ALTER TABLE tasks
            ADD CONSTRAINT tasks_meeting_id_fkey
            FOREIGN KEY (meeting_id) REFERENCES meetings(id) ON DELETE CASCADE;
        EXCEPTION WHEN duplicate_object THEN
          -- ignore
        END;
      END IF;
    END $$;
    ");

    // ------------------------------------------------------------
    // 6) Ensure status columns exist with compatible types
    // If old schema had TEXT status, we keep it, but if status is missing, we add it.
    // We don't force type conversion here to avoid hard failures.
    // ------------------------------------------------------------
    $pdo->exec("
    DO $$
    BEGIN
      IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='decisions' AND column_name='status') THEN
        ALTER TABLE decisions ADD COLUMN status decision_status NOT NULL DEFAULT 'active';
      END IF;

      IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='tasks' AND column_name='status') THEN
        ALTER TABLE tasks ADD COLUMN status task_status NOT NULL DEFAULT 'open';
      END IF;
    END $$;
    ");

    // ------------------------------------------------------------
    // 7) Indexes
    // ------------------------------------------------------------
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_decisions_meeting_id ON decisions(meeting_id);");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_tasks_meeting_id ON tasks(meeting_id);");
}
