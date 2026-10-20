<?php
declare(strict_types=1);
function database(): PDO {
    $dir = getenv('DATA_DIR') ?: dirname(__DIR__) . '/data';
    if (!is_dir($dir)) mkdir($dir, 0700, true);
    $pdo = new PDO('sqlite:' . $dir . '/campus.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $pdo->exec('PRAGMA foreign_keys=ON; PRAGMA busy_timeout=5000');
    $pdo->exec('CREATE TABLE IF NOT EXISTS users (id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT NOT NULL UNIQUE, password_hash TEXT NOT NULL)');
    $pdo->exec("CREATE TABLE IF NOT EXISTS lost_found_items (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL REFERENCES users(id), reference_no TEXT NOT NULL UNIQUE, item_name TEXT NOT NULL, category TEXT NOT NULL, item_type TEXT NOT NULL CHECK(item_type IN ('Lost','Found')), description TEXT, location TEXT NOT NULL, date_reported TEXT NOT NULL, status TEXT NOT NULL DEFAULT 'Open' CHECK(status IN ('Open','Matched','Claimed')), reporter_name TEXT NOT NULL)");
    $pdo->exec('CREATE TABLE IF NOT EXISTS activity_log (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL REFERENCES users(id), action TEXT NOT NULL, reference_no TEXT NOT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)');
    return $pdo;
}
