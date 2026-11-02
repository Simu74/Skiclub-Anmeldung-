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
    if ($version < 3) {
        // Rollen, Kategorien, Vorlagen, Freitext, Anhänge, Leiter-Verfügbarkeit, Rückmeldungen
        $pdo->exec(<<<SQL
        BEGIN;
        ALTER TABLE leaders ADD COLUMN role TEXT NOT NULL DEFAULT 'leiter';
        UPDATE leaders SET role = CASE WHEN is_admin = 1 THEN 'admin' ELSE 'hauptleiter' END;

        CREATE TABLE event_categories (
            id INTEGER PRIMARY KEY,
            name TEXT NOT NULL,
            kind TEXT NOT NULL CHECK (kind IN ('off','on','race')),
            sort INTEGER NOT NULL DEFAULT 0
        );
        INSERT INTO event_categories (id, name, kind, sort) VALUES (1, 'Off Snow', 'off', 1), (2, 'On Snow', 'on', 2), (3, 'Rennen', 'race', 3);

        ALTER TABLE events ADD COLUMN category_id INTEGER REFERENCES event_categories(id) ON DELETE SET NULL;
        ALTER TABLE events ADD COLUMN details TEXT NOT NULL DEFAULT '';
        UPDATE events SET category_id = CASE type WHEN 'off' THEN 1 WHEN 'on' THEN 2 ELSE 3 END;

        CREATE TABLE event_templates (
            id INTEGER PRIMARY KEY,
            name TEXT NOT NULL,
            category_id INTEGER REFERENCES event_categories(id) ON DELETE SET NULL,
            title TEXT NOT NULL,
            weekday INTEGER NOT NULL DEFAULT 6,
            time_text TEXT NOT NULL DEFAULT '',
            place TEXT NOT NULL DEFAULT '',
            material TEXT NOT NULL DEFAULT '',
            details TEXT NOT NULL DEFAULT '',
            min_skill INTEGER NOT NULL DEFAULT 1,
            deadline_days INTEGER NOT NULL DEFAULT 2,
            sort INTEGER NOT NULL DEFAULT 0
        );
        INSERT INTO event_templates (name, category_id, title, weekday, time_text, place, material, min_skill, sort) VALUES
            ('Off Snow Samstag', 1, 'Off Snow Training', 6, '14:00–16:00', 'Turnhalle', 'Turnschuhe, Trinkflasche. Bei schönem Wetter draussen: Bike + Helm', 1, 1),
            ('On Snow Samstag', 2, 'On Snow Training', 6, '09:30–12:30', 'Tschentenalp, Trainingshang', 'Skis, Helm, Rückenpanzer, Skipass, Lunch', 3, 2),
            ('On Snow Mittwoch', 2, 'On Snow Mittwoch', 3, '13:30–16:30', 'Tschentenalp, Trainingshang', 'Skis, Helm, Rückenpanzer, Skipass', 1, 3),
            ('Rennen', 3, 'Rennen', 6, 'Start 10:00', '', 'Rennanzug, Rückenpanzer, Skipass', 1, 4);

        CREATE TABLE event_files (
            id INTEGER PRIMARY KEY,
            event_id INTEGER NOT NULL REFERENCES events(id) ON DELETE CASCADE,
            orig_name TEXT NOT NULL,
            stored_name TEXT NOT NULL UNIQUE,
            mime TEXT NOT NULL,
            size INTEGER NOT NULL,
            created_at TEXT NOT NULL DEFAULT (datetime('now'))
        );

        CREATE TABLE leader_availability (
            event_id INTEGER NOT NULL REFERENCES events(id) ON DELETE CASCADE,
            leader_id INTEGER NOT NULL REFERENCES leaders(id) ON DELETE CASCADE,
            status TEXT NOT NULL CHECK (status IN ('yes','no')),
            note TEXT NOT NULL DEFAULT '',
            PRIMARY KEY (event_id, leader_id)
        );

        CREATE TABLE kid_feedback (
            id INTEGER PRIMARY KEY,
            kid_id INTEGER NOT NULL REFERENCES kids(id) ON DELETE CASCADE,
            event_id INTEGER REFERENCES events(id) ON DELETE SET NULL,
            leader_id INTEGER REFERENCES leaders(id) ON DELETE SET NULL,
            kind TEXT NOT NULL CHECK (kind IN ('skill','injury','note')),
            skill INTEGER,
            text TEXT NOT NULL DEFAULT '',
            done INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT (datetime('now'))
        );

        ALTER TABLE drivers ADD COLUMN note TEXT NOT NULL DEFAULT '';
        PRAGMA user_version = 3;
        COMMIT;
        SQL);
    }
}
