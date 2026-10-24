<?php
// SQLite-Datenbank. Die Datei liegt im Ordner data/, der per .htaccess gesperrt ist.

declare(strict_types=1);

function db(): PDO
{
    static $pdo = null;
    global $config;
    if ($pdo) {
        return $pdo;
    }
    $dir = $config['data_dir'];
    if (!is_dir($dir)) {
        mkdir($dir, 0750, true);
    }
    $pdo = new PDO('sqlite:' . $dir . '/skiklub.sqlite', null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA journal_mode = WAL');
    migrate($pdo);
    return $pdo;
}

function migrate(PDO $pdo): void
{
    $version = (int)$pdo->query('PRAGMA user_version')->fetchColumn();
    if ($version < 1) {
        $pdo->exec(<<<SQL
        CREATE TABLE leaders (
            id INTEGER PRIMARY KEY,
            name TEXT NOT NULL,
            phone TEXT NOT NULL DEFAULT '',
            username TEXT NOT NULL UNIQUE,
            pass_hash TEXT NOT NULL,
            is_admin INTEGER NOT NULL DEFAULT 0
        );
        CREATE TABLE families (
            id INTEGER PRIMARY KEY,
            name TEXT NOT NULL,
            phone TEXT NOT NULL DEFAULT '',
            token TEXT NOT NULL UNIQUE,
            created_at TEXT NOT NULL DEFAULT (datetime('now'))
        );
        CREATE TABLE kids (
            id INTEGER PRIMARY KEY,
            family_id INTEGER NOT NULL REFERENCES families(id) ON DELETE CASCADE,
            first_name TEXT NOT NULL,
            birth_year INTEGER NOT NULL,
            skill INTEGER NOT NULL DEFAULT 1,
            emergency_phone TEXT NOT NULL DEFAULT '',
            health TEXT NOT NULL DEFAULT '',
            active INTEGER NOT NULL DEFAULT 1
        );
        CREATE TABLE events (
            id INTEGER PRIMARY KEY,
            type TEXT NOT NULL CHECK (type IN ('off','on','race')),
            title TEXT NOT NULL,
            date TEXT NOT NULL,
            time_text TEXT NOT NULL DEFAULT '',
            place TEXT NOT NULL DEFAULT '',
            material TEXT NOT NULL DEFAULT '',
            deadline TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT 'draft' CHECK (status IN ('draft','open','cancelled')),
            info TEXT NOT NULL DEFAULT '',
            min_skill INTEGER NOT NULL DEFAULT 1,
            group_count INTEGER NOT NULL DEFAULT 0
        );
        CREATE TABLE registrations (
            event_id INTEGER NOT NULL REFERENCES events(id) ON DELETE CASCADE,
            kid_id INTEGER NOT NULL REFERENCES kids(id) ON DELETE CASCADE,
            status TEXT NOT NULL CHECK (status IN ('in','out')),
            reason TEXT NOT NULL DEFAULT '',
            note TEXT NOT NULL DEFAULT '',
            updated_at TEXT NOT NULL DEFAULT (datetime('now')),
            PRIMARY KEY (event_id, kid_id)
        );
        CREATE TABLE drivers (
            event_id INTEGER NOT NULL REFERENCES events(id) ON DELETE CASCADE,
            family_id INTEGER NOT NULL REFERENCES families(id) ON DELETE CASCADE,
            seats INTEGER NOT NULL,
            PRIMARY KEY (event_id, family_id)
        );
        CREATE TABLE assignments (
            event_id INTEGER NOT NULL REFERENCES events(id) ON DELETE CASCADE,
            kid_id INTEGER NOT NULL REFERENCES kids(id) ON DELETE CASCADE,
            group_no INTEGER NOT NULL,
            PRIMARY KEY (event_id, kid_id)
        );
        CREATE TABLE group_leaders (
            event_id INTEGER NOT NULL REFERENCES events(id) ON DELETE CASCADE,
            group_no INTEGER NOT NULL,
            leader_id INTEGER REFERENCES leaders(id) ON DELETE SET NULL,
            PRIMARY KEY (event_id, group_no)
        );
        PRAGMA user_version = 1;
        SQL);
    }
    if ($version < 2) {
        // Präsenzkontrolle: attended NULL = nicht erfasst, 1 = da, 0 = nicht da
        $pdo->exec(<<<SQL
        ALTER TABLE registrations ADD COLUMN attended INTEGER;
        ALTER TABLE events ADD COLUMN attendance_taken INTEGER NOT NULL DEFAULT 0;
        PRAGMA user_version = 2;
        SQL);
    }
}
