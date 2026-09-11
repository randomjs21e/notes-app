<?php
declare(strict_types=1);

session_name('notes_studio_session');
session_start();

$databasePath = getenv('NOTES_DB_PATH') ?: __DIR__ . DIRECTORY_SEPARATOR . 'notes.sqlite';
$database = new SQLite3($databasePath);
$database->enableExceptions(true);
$database->exec('PRAGMA foreign_keys = ON');
$database->exec(
    <<<'SQL'
    CREATE TABLE IF NOT EXISTS users (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      name TEXT NOT NULL UNIQUE,
      email TEXT NOT NULL UNIQUE,
      password_hash TEXT NOT NULL,
      created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    );
    CREATE TABLE IF NOT EXISTS notes (
      id TEXT PRIMARY KEY,
      user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
      title TEXT NOT NULL DEFAULT '',
      body TEXT NOT NULL DEFAULT '',
      preview TEXT NOT NULL DEFAULT '',
      updated_at TEXT NOT NULL,
      pinned INTEGER NOT NULL DEFAULT 0,
      folder TEXT NOT NULL DEFAULT 'Ideas',
      language TEXT NOT NULL DEFAULT 'Markdown'
    );
    CREATE TABLE IF NOT EXISTS tasks (
      id TEXT PRIMARY KEY,
      user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
      title TEXT NOT NULL,
      due_date TEXT NOT NULL,
      completed INTEGER NOT NULL DEFAULT 0
    );
    CREATE TABLE IF NOT EXISTS calendar_events (
      id TEXT PRIMARY KEY,
      user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
      title TEXT NOT NULL,
      event_date TEXT NOT NULL,
      event_time TEXT NOT NULL,
      color TEXT NOT NULL DEFAULT 'yellow'
    );
    CREATE TABLE IF NOT EXISTS routines (
      id TEXT PRIMARY KEY,
      user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
      day_of_week INTEGER NOT NULL,
      title TEXT NOT NULL,
      start_time TEXT NOT NULL,
      end_time TEXT NOT NULL,
      color TEXT NOT NULL DEFAULT 'yellow'
    );
    SQL
);

function jsonResponse(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function input(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        return $_POST;
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : $_POST;
}

function userId(): ?int
{
    return isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
}

function idFor(string $prefix): string
{
    return $prefix . '-' . bin2hex(random_bytes(7));
}

function workspaceState(SQLite3 $database, int $userId): array
{
    $notes = [];
    $result = $database->query('SELECT id, title, body, preview, updated_at AS updatedAt, pinned, folder, language FROM notes WHERE user_id = ' . $userId . ' ORDER BY pinned DESC, updated_at DESC');
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $row['pinned'] = (bool) $row['pinned'];
        $notes[] = $row;
    }

    $tasks = [];
    $result = $database->query('SELECT id, title, due_date AS dueDate, completed FROM tasks WHERE user_id = ' . $userId . ' ORDER BY completed ASC, due_date ASC');
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $row['completed'] = (bool) $row['completed'];
        $tasks[] = $row;
    }

    $events = [];
    $result = $database->query('SELECT id, title, event_date AS date, event_time AS time, color FROM calendar_events WHERE user_id = ' . $userId . ' ORDER BY event_date ASC, event_time ASC');
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $events[] = $row;
    }

    $routines = [];
    $result = $database->query('SELECT id, day_of_week AS dayOfWeek, title, start_time AS startTime, end_time AS endTime, color FROM routines WHERE user_id = ' . $userId . ' ORDER BY day_of_week ASC, start_time ASC');
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $row['dayOfWeek'] = (int) $row['dayOfWeek'];
        $routines[] = $row;
    }

    return ['notes' => $notes, 'tasks' => $tasks, 'events' => $events, 'routines' => $routines];
}

function saveWorkspace(SQLite3 $database, int $userId, array $state): void
{
    $notes = is_array($state['notes'] ?? null) ? $state['notes'] : [];
    $tasks = is_array($state['tasks'] ?? null) ? $state['tasks'] : [];
    $events = is_array($state['events'] ?? null) ? $state['events'] : [];
    $routines = is_array($state['routines'] ?? null) ? $state['routines'] : [];

    $database->exec('BEGIN IMMEDIATE TRANSACTION');
    try {
        foreach (['notes', 'tasks', 'calendar_events', 'routines'] as $table) {
            $statement = $database->prepare("DELETE FROM {$table} WHERE user_id = :user_id");
            $statement->bindValue(':user_id', $userId, SQLITE3_INTEGER);
            $statement->execute();
        }
        foreach ($notes as $note) {
            $statement = $database->prepare('INSERT INTO notes (id, user_id, title, body, preview, updated_at, pinned, folder, language) VALUES (:id, :user_id, :title, :body, :preview, :updated_at, :pinned, :folder, :language)');
            $statement->bindValue(':id', (string) ($note['id'] ?? idFor('n')), SQLITE3_TEXT);
            $statement->bindValue(':user_id', $userId, SQLITE3_INTEGER);
            $statement->bindValue(':title', (string) ($note['title'] ?? ''), SQLITE3_TEXT);
            $statement->bindValue(':body', (string) ($note['body'] ?? ''), SQLITE3_TEXT);
            $statement->bindValue(':preview', (string) ($note['preview'] ?? ''), SQLITE3_TEXT);
            $statement->bindValue(':updated_at', (string) ($note['updatedAt'] ?? gmdate('c')), SQLITE3_TEXT);
            $statement->bindValue(':pinned', !empty($note['pinned']) ? 1 : 0, SQLITE3_INTEGER);
            $statement->bindValue(':folder', (string) ($note['folder'] ?? 'Ideas'), SQLITE3_TEXT);
            $statement->bindValue(':language', (string) ($note['language'] ?? 'Markdown'), SQLITE3_TEXT);
            $statement->execute();
        }
        foreach ($tasks as $task) {
            $statement = $database->prepare('INSERT INTO tasks (id, user_id, title, due_date, completed) VALUES (:id, :user_id, :title, :due_date, :completed)');
            $statement->bindValue(':id', (string) ($task['id'] ?? idFor('t')), SQLITE3_TEXT);
            $statement->bindValue(':user_id', $userId, SQLITE3_INTEGER);
            $statement->bindValue(':title', (string) ($task['title'] ?? ''), SQLITE3_TEXT);
            $statement->bindValue(':due_date', (string) ($task['dueDate'] ?? date('Y-m-d')), SQLITE3_TEXT);
            $statement->bindValue(':completed', !empty($task['completed']) ? 1 : 0, SQLITE3_INTEGER);
            $statement->execute();
        }
        foreach ($events as $event) {
            $statement = $database->prepare('INSERT INTO calendar_events (id, user_id, title, event_date, event_time, color) VALUES (:id, :user_id, :title, :event_date, :event_time, :color)');
            $statement->bindValue(':id', (string) ($event['id'] ?? idFor('e')), SQLITE3_TEXT);
            $statement->bindValue(':user_id', $userId, SQLITE3_INTEGER);
            $statement->bindValue(':title', (string) ($event['title'] ?? ''), SQLITE3_TEXT);
            $statement->bindValue(':event_date', (string) ($event['date'] ?? date('Y-m-d')), SQLITE3_TEXT);
            $statement->bindValue(':event_time', (string) ($event['time'] ?? '09:00'), SQLITE3_TEXT);
            $statement->bindValue(':color', (string) ($event['color'] ?? 'yellow'), SQLITE3_TEXT);
            $statement->execute();
        }
        foreach ($routines as $routine) {
            $statement = $database->prepare('INSERT INTO routines (id, user_id, day_of_week, title, start_time, end_time, color) VALUES (:id, :user_id, :day_of_week, :title, :start_time, :end_time, :color)');
            $statement->bindValue(':id', (string) ($routine['id'] ?? idFor('r')), SQLITE3_TEXT);
            $statement->bindValue(':user_id', $userId, SQLITE3_INTEGER);
            $statement->bindValue(':day_of_week', (int) ($routine['dayOfWeek'] ?? 0), SQLITE3_INTEGER);
            $statement->bindValue(':title', (string) ($routine['title'] ?? ''), SQLITE3_TEXT);
            $statement->bindValue(':start_time', (string) ($routine['startTime'] ?? '09:00'), SQLITE3_TEXT);
            $statement->bindValue(':end_time', (string) ($routine['endTime'] ?? '10:00'), SQLITE3_TEXT);
            $statement->bindValue(':color', (string) ($routine['color'] ?? 'yellow'), SQLITE3_TEXT);
            $statement->execute();
        }
        $database->exec('COMMIT');
    } catch (Throwable $error) {
        $database->exec('ROLLBACK');
        throw $error;
    }
}

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($action === 'register' && $method === 'POST') {
    $payload = input();
    $name = trim((string) ($payload['name'] ?? ''));
    $email = trim(strtolower((string) ($payload['email'] ?? '')));
    $password = (string) ($payload['password'] ?? '');

    if ($name === '' || mb_strlen($name) > 80) {
        jsonResponse(['error' => 'A valid name is required.'], 422);
    }
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        jsonResponse(['error' => 'A valid email is required.'], 422);
    }
    if (mb_strlen($password) < 8) {
        jsonResponse(['error' => 'Password must be at least 8 characters.'], 422);
    }

    $statement = $database->prepare('SELECT id FROM users WHERE email = :email');
    $statement->bindValue(':email', $email, SQLITE3_TEXT);
    if ($statement->execute()->fetchArray(SQLITE3_ASSOC)) {
        jsonResponse(['error' => 'An account with this email already exists.'], 409);
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);
    try {
        $statement = $database->prepare('INSERT INTO users (name, email, password_hash) VALUES (:name, :email, :hash)');
        $statement->bindValue(':name', $name, SQLITE3_TEXT);
        $statement->bindValue(':email', $email, SQLITE3_TEXT);
        $statement->bindValue(':hash', $hash, SQLITE3_TEXT);
        $statement->execute();
    } catch (Throwable $error) {
        jsonResponse(['error' => 'That name is already taken.'], 409);
    }

    $user = ['id' => $database->lastInsertRowID(), 'name' => $name];
    $_SESSION['user_id'] = (int) $user['id'];
    jsonResponse(['user' => $user, 'state' => workspaceState($database, (int) $user['id'])]);
}

if ($action === 'login' && $method === 'POST') {
    $payload = input();
    $email = trim(strtolower((string) ($payload['email'] ?? '')));
    $password = (string) ($payload['password'] ?? '');

    if ($email === '' || $password === '') {
        jsonResponse(['error' => 'Email and password are required.'], 422);
    }

    $statement = $database->prepare('SELECT id, name, password_hash FROM users WHERE email = :email');
    $statement->bindValue(':email', $email, SQLITE3_TEXT);
    $user = $statement->execute()->fetchArray(SQLITE3_ASSOC);

    if (!$user || !password_verify($password, (string) $user['password_hash'])) {
        jsonResponse(['error' => 'Incorrect email or password.'], 401);
    }

    $_SESSION['user_id'] = (int) $user['id'];
    $publicUser = ['id' => $user['id'], 'name' => $user['name']];
    jsonResponse(['user' => $publicUser, 'state' => workspaceState($database, (int) $user['id'])]);
}

if ($action === 'logout' && $method === 'POST') {
    $_SESSION = [];
    session_destroy();
    jsonResponse(['ok' => true]);
}

if ($action === 'state') {
    $currentUserId = userId();
    if ($currentUserId === null) {
        jsonResponse(['error' => 'Authentication required.'], 401);
    }
    if ($method === 'POST') {
        saveWorkspace($database, $currentUserId, input());
    }
    jsonResponse(workspaceState($database, $currentUserId));
}

$currentUserId = userId();
$currentUser = null;
$initialState = ['notes' => [], 'tasks' => [], 'events' => [], 'routines' => []];
if ($currentUserId !== null) {
    $statement = $database->prepare('SELECT id, name FROM users WHERE id = :id');
    $statement->bindValue(':id', $currentUserId, SQLITE3_INTEGER);
    $currentUser = $statement->execute()->fetchArray(SQLITE3_ASSOC) ?: null;
    if ($currentUser) {
        $initialState = workspaceState($database, $currentUserId);
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Morrow — Notes</title>
  <meta name="description" content="A private notes, tasks, and calendar workspace.">
  <style>
    :root {
      --bg: #f2f1ed;
      --paper: #fffef7;
      --paper-2: #fbfaf4;
      --ink: #1f1f1d;
      --muted: #7e7d78;
      --line: #deddd5;
      --soft: #ecebe5;
      --yellow: #f5bf16;
      --yellow-deep: #c48b00;
      --dark: #373429;
      --dark-2: #464237;
      --white: #fffef7;
      --red: #c94b3e;
      --shadow: 0 14px 42px rgba(49, 46, 38, .08);
      --radius: 18px;
      --serif: Georgia, 'Times New Roman', serif;
      --sans: -apple-system, BlinkMacSystemFont, 'SF Pro Display', 'Segoe UI', sans-serif;
      --mono: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
      --phone-body: #f2f1ed;
      --phone-body-2: #dedcd3;
      --phone-accent: var(--yellow);
      --phone-accent-2: var(--yellow-deep);
    }
    * { box-sizing: border-box; }
    html, body { min-height: 100%; margin: 0; }
    body { background: var(--bg); color: var(--ink); font-family: var(--sans); }
    button, input, textarea, select { font: inherit; }
    button { cursor: pointer; }
    .grain::after { content: ''; position: fixed; inset: 0; pointer-events: none; opacity: .035; z-index: 20; background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='120' height='120' viewBox='0 0 120 120'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='.8' numOctaves='3'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)' opacity='.3'/%3E%3C/svg%3E"); }
    .hidden { display: none !important; }

    /* ---------- Login ---------- */
    .login-shell { min-height: 100vh; display: grid; place-items: center; padding: 26px; background: #4a4535; }
    .login-card { width: min(1060px, 100%); min-height: 640px; overflow: hidden; display: grid; grid-template-columns: 1.05fr .95fr; background: var(--paper); border-radius: 30px; box-shadow: 0 30px 80px rgba(0,0,0,.24); }
    .login-hero { position: relative; display: flex; flex-direction: column; justify-content: space-between; padding: 52px; color: var(--white); background: var(--dark); overflow: hidden; }
    .login-hero::before { content: ''; position: absolute; width: 420px; height: 420px; top: -170px; right: -120px; border-radius: 50%; background: rgba(245,191,22,.16); filter: blur(2px); }
    .brand { position: relative; display: flex; align-items: center; gap: 12px; font-weight: 700; letter-spacing: -.02em; }
    .brand-mark { display: grid; place-items: center; width: 42px; height: 42px; border-radius: 13px; color: #2b291e; background: var(--yellow); font-size: 22px; }
    .eyebrow { color: var(--yellow); font-family: var(--mono); font-size: 10px; letter-spacing: .22em; text-transform: uppercase; }
    .login-hero h1 { position: relative; max-width: 480px; margin: 40px 0 20px; font-family: var(--serif); font-size: clamp(44px, 5.4vw, 74px); line-height: .94; letter-spacing: -.065em; }
    .login-hero h1 span { color: var(--yellow); }
    .login-hero p.lede { position: relative; max-width: 360px; color: rgba(255,254,247,.66); font-size: 14px; line-height: 1.8; }
    .login-foot { position: relative; display: flex; align-items: center; gap: 12px; color: rgba(255,254,247,.5); font-family: var(--mono); font-size: 10px; }
    .login-foot i { display: block; width: 40px; height: 1px; background: var(--yellow); }

    /* Stylised phone that mirrors the OS color scheme, "aurora" style */
    .phone-wrap { position: relative; display: flex; justify-content: center; margin: 18px 0 6px; }
    .phone { position: relative; width: 128px; height: 262px; padding: 7px; border-radius: 34px; background: linear-gradient(155deg, var(--phone-body), var(--phone-body-2)); box-shadow: 0 18px 40px rgba(0,0,0,.35), inset 0 0 0 1px rgba(255,255,255,.08); transition: background .4s ease; }
    .phone-screen { position: relative; width: 100%; height: 100%; overflow: hidden; border-radius: 27px; background: linear-gradient(200deg, var(--phone-accent) 0%, var(--phone-accent-2) 55%, var(--phone-body-2) 100%); }
    .phone-screen::before { content: ''; position: absolute; inset: 0; background: radial-gradient(circle at 30% 20%, rgba(255,255,255,.35), transparent 55%); }
    .phone-notch { position: absolute; top: 6px; left: 50%; transform: translateX(-50%); width: 40%; height: 16px; border-radius: 10px; background: var(--phone-body-2); }
    .phone-caption { margin-top: 10px; text-align: center; color: rgba(255,254,247,.4); font-family: var(--mono); font-size: 9px; letter-spacing: .14em; text-transform: uppercase; }

    .login-form-panel { display: flex; flex-direction: column; justify-content: center; padding: 56px 62px; background: var(--paper); }
    .login-form-panel h2 { margin: 12px 0 8px; font-family: var(--serif); font-size: 36px; letter-spacing: -.05em; }
    .login-form-panel p.sub { margin: 0; color: var(--muted); font-size: 13px; line-height: 1.6; }
    .auth-tabs { display: flex; gap: 4px; margin-top: 24px; padding: 4px; border-radius: 12px; background: var(--soft); }
    .auth-tabs button { flex: 1; padding: 10px; border: 0; border-radius: 9px; color: var(--muted); background: transparent; font-size: 12px; font-weight: 700; }
    .auth-tabs button.active { color: var(--ink); background: var(--paper); box-shadow: var(--shadow); }
    .form-row { margin-top: 20px; }
    .form-row:first-of-type { margin-top: 28px; }
    label { display: block; margin-bottom: 9px; color: var(--muted); font-size: 10px; font-weight: 700; letter-spacing: .16em; text-transform: uppercase; }
    input, textarea, select { border: 1px solid var(--line); border-radius: 12px; outline: 0; background: var(--paper); color: var(--ink); transition: .2s ease; }
    input:focus, textarea:focus, select:focus { border-color: var(--yellow-deep); box-shadow: 0 0 0 4px rgba(245,191,22,.16); }
    .input { width: 100%; height: 52px; padding: 0 16px; }
    .primary-btn { display: inline-flex; align-items: center; justify-content: space-between; gap: 20px; width: 100%; min-height: 54px; padding: 0 18px; border: 0; border-radius: 13px; color: #27251d; background: var(--yellow); font-weight: 700; transition: .2s ease; margin-top: 22px; }
    .primary-btn:hover { background: #ffd34d; transform: translateY(-1px); }
    .primary-btn:disabled { opacity: .6; cursor: default; transform: none; }
    .auth-error { display: none; margin-top: 14px; padding: 11px 13px; border-radius: 10px; color: #8a2f22; background: #fbe4df; font-size: 12px; }
    .auth-error.show { display: block; }
    .small-btn { border: 0; border-radius: 10px; background: transparent; color: inherit; }

    /* ---------- Workspace shell ---------- */
    .workspace { min-height: 100vh; display: flex; }
    .sidebar { width: 238px; flex: 0 0 238px; display: flex; flex-direction: column; color: var(--white); background: var(--dark); }
    .sidebar-top { height: 80px; display: flex; align-items: center; justify-content: space-between; padding: 0 20px; }
    .sidebar .brand-mark { width: 36px; height: 36px; font-size: 18px; }
    .sidebar .brand { font-size: 14px; }
    .side-label { margin: 12px 14px 10px; color: rgba(255,254,247,.4); font-family: var(--mono); font-size: 9px; letter-spacing: .2em; text-transform: uppercase; }
    .nav { padding: 0 10px; }
    .nav button { width: 100%; display: flex; align-items: center; gap: 12px; padding: 12px 13px; border: 0; border-radius: 12px; color: rgba(255,254,247,.68); background: transparent; text-align: left; transition: .2s ease; }
    .nav button:hover, .nav button.active { color: var(--white); background: var(--dark-2); }
    .nav button.active { color: var(--yellow); font-weight: 700; }
    .nav-count { margin-left: auto; min-width: 22px; padding: 3px 6px; border-radius: 7px; color: var(--dark); background: var(--yellow); font-family: var(--mono); font-size: 10px; text-align: center; }
    .phone-mini { position: relative; width: 30px; height: 30px; flex: 0 0 30px; }
    .phone-mini-body { position: absolute; inset: 0; border-radius: 9px; background: linear-gradient(155deg, var(--phone-body), var(--phone-body-2)); padding: 2.5px; }
    .phone-mini-screen { width: 100%; height: 100%; border-radius: 6.5px; background: linear-gradient(200deg, var(--phone-accent), var(--phone-accent-2)); }
    .profile { display: flex; align-items: center; gap: 10px; margin-top: auto; padding: 16px; border-top: 1px solid rgba(255,254,247,.12); }
    .avatar { display: grid; place-items: center; width: 36px; height: 36px; flex: 0 0 36px; border-radius: 50%; color: #2b291e; background: var(--yellow); font-size: 11px; font-weight: 800; }
    .profile-text { min-width: 0; flex: 1; }
    .profile-text strong { display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-size: 13px; }
    .profile-text small { color: rgba(255,254,247,.45); font-family: var(--mono); font-size: 9px; }
    .logout { color: rgba(255,254,247,.58); padding: 8px; }
    .main { min-width: 0; flex: 1; display: flex; flex-direction: column; }
    .topbar { height: 80px; display: flex; align-items: center; justify-content: space-between; padding: 0 28px; border-bottom: 1px solid var(--line); background: rgba(255,254,247,.7); }
    .crumb { display: flex; align-items: center; gap: 10px; color: var(--muted); font-size: 13px; }
    .crumb strong { color: var(--ink); }
    .date-stamp { color: var(--muted); font-family: var(--mono); font-size: 10px; text-transform: uppercase; letter-spacing: .1em; }
    .panel { min-height: 0; flex: 1; display: flex; }

    /* ---------- Notes ---------- */
    .notes-list { width: 310px; flex: 0 0 310px; overflow: auto; padding: 24px 12px; border-right: 1px solid var(--line); background: rgba(242,241,237,.68); }
    .list-heading { display: flex; align-items: end; justify-content: space-between; padding: 0 8px 14px; }
    .list-heading h2, .page-head h1 { margin: 5px 0 0; font-family: var(--serif); font-size: 30px; letter-spacing: -.05em; }
    .new-note { display: grid; place-items: center; width: 36px; height: 36px; border: 0; border-radius: 12px; color: #27251d; background: var(--yellow); font-size: 21px; }
    .search { position: relative; margin: 0 4px 12px; }
    .search input { width: 100%; height: 40px; padding: 0 12px 0 36px; }
    .search span { position: absolute; top: 11px; left: 13px; color: var(--muted); font-size: 14px; }
    .filters { display: flex; gap: 6px; overflow-x: auto; padding: 0 4px 13px; scrollbar-width: none; }
    .filters button { flex: 0 0 auto; padding: 7px 11px; border: 0; border-radius: 99px; color: var(--muted); background: var(--soft); font-size: 11px; }
    .filters button.active { color: #2a281e; background: var(--yellow); }
    .note-card { width: 100%; margin-bottom: 5px; padding: 14px; border: 0; border-radius: 15px; color: var(--ink); background: transparent; text-align: left; transition: .2s ease; }
    .note-card:hover, .note-card.active { background: var(--paper); box-shadow: var(--shadow); }
    .note-title { display: flex; align-items: center; gap: 8px; font-weight: 700; font-size: 13px; }
    .note-title span:first-child { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .pin-dot { width: 6px; height: 6px; flex: 0 0 6px; border-radius: 50%; background: transparent; }
    .pinned .pin-dot { background: var(--yellow); }
    .note-preview { display: -webkit-box; overflow: hidden; margin: 7px 0 10px 14px; color: var(--muted); font-size: 12px; line-height: 1.5; -webkit-box-orient: vertical; -webkit-line-clamp: 2; }
    .note-meta { display: flex; gap: 8px; margin-left: 14px; color: var(--muted); font-family: var(--mono); font-size: 9px; }
    .editor { min-width: 0; flex: 1; display: flex; flex-direction: column; background: var(--paper); }
    .editor-bar { min-height: 64px; display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 0 26px; border-bottom: 1px solid var(--line); }
    .editor-path { color: var(--muted); font-family: var(--mono); font-size: 10px; letter-spacing: .12em; text-transform: uppercase; }
    .editor-actions { display: flex; align-items: center; gap: 5px; }
    .icon-btn { display: grid; place-items: center; width: 34px; height: 34px; border: 0; border-radius: 9px; color: var(--muted); background: transparent; }
    .icon-btn:hover { color: var(--ink); background: var(--soft); }
    .icon-btn.active { color: var(--yellow-deep); }
    .editor-scroll { overflow: auto; flex: 1; }
    .editor-inner { width: min(820px, 100%); margin: 0 auto; padding: 56px 48px 90px; }
    .title-input { width: 100%; height: auto; margin-bottom: 14px; padding: 0; border: 0; border-radius: 0; background: transparent; font-family: var(--serif); font-size: 50px; font-weight: 700; letter-spacing: -.065em; }
    .title-input:focus { box-shadow: none; }
    .note-info { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; margin-bottom: 24px; color: var(--muted); font-size: 11px; }
    .pill { padding: 6px 9px; border-radius: 99px; color: #4e421f; background: #fff0b6; font-family: var(--mono); font-size: 9px; }
    .language { width: auto; height: 26px; padding: 0 8px; border: 0; border-radius: 99px; color: var(--muted); background: var(--soft); font-family: var(--mono); font-size: 9px; }

    /* Improved toolbar */
    .toolbar { position: sticky; top: 0; z-index: 2; display: flex; flex-wrap: wrap; align-items: center; gap: 3px; margin-bottom: 18px; padding: 7px; border: 1px solid var(--line); border-radius: 14px; background: rgba(255,254,247,.94); box-shadow: 0 8px 20px rgba(49,46,38,.06); backdrop-filter: blur(12px); }
    .toolbar button { display: grid; place-items: center; min-width: 33px; height: 33px; padding: 0 9px; border: 0; border-radius: 9px; color: var(--muted); background: transparent; font-size: 12.5px; font-weight: 700; transition: .15s ease; }
    .toolbar button:hover { color: var(--ink); background: var(--soft); transform: translateY(-1px); }
    .toolbar button:active { transform: translateY(0); background: var(--yellow); color: #2a2718; }
    .toolbar button.wide { min-width: 40px; }
    .toolbar-sep { width: 1px; height: 20px; margin: 0 4px; background: var(--line); flex: 0 0 1px; }
    .body-editor { width: 100%; min-height: 390px; resize: vertical; padding: 0; border: 0; border-radius: 0; background: transparent; font-family: var(--mono); font-size: 14px; line-height: 1.9; }
    .body-editor:focus { box-shadow: none; }
    .editor-foot { display: flex; align-items: center; gap: 7px; padding-top: 18px; border-top: 1px solid var(--line); color: var(--muted); font-size: 11px; }
    .editor-foot span:last-child { margin-left: auto; font-family: var(--mono); font-size: 9px; }

    /* ---------- Generic pages / tasks ---------- */
    .page { overflow: auto; flex: 1; padding: 48px clamp(20px, 6vw, 90px); }
    .page-inner { width: min(900px, 100%); margin: 0 auto; }
    .page-head { display: flex; align-items: end; justify-content: space-between; gap: 18px; }
    .page-head p { margin: 9px 0 0; color: var(--muted); font-size: 13px; }
    .add-button { display: inline-flex; align-items: center; gap: 8px; min-height: 42px; padding: 0 14px; border: 0; border-radius: 12px; color: #27251d; background: var(--yellow); font-size: 13px; font-weight: 700; }
    .add-form { display: grid; grid-template-columns: 1fr 150px auto; gap: 8px; margin-top: 28px; padding: 12px; border: 1px solid var(--line); border-radius: 15px; background: var(--paper-2); }
    .add-form input, .add-form select { height: 42px; padding: 0 11px; }
    .save-button { border: 0; border-radius: 10px; padding: 0 15px; color: var(--white); background: var(--dark); font-size: 12px; font-weight: 700; }
    .task-group { margin-top: 34px; }
    .group-label { margin: 0 0 10px; color: var(--muted); font-family: var(--mono); font-size: 9px; letter-spacing: .18em; text-transform: uppercase; }
    .task-row { display: flex; align-items: center; gap: 12px; min-height: 58px; margin-bottom: 8px; padding: 0 15px; border: 1px solid var(--line); border-radius: 15px; background: var(--paper); }
    .task-row.done { opacity: .5; }
    .check { display: grid; place-items: center; width: 21px; height: 21px; flex: 0 0 21px; border: 2px solid var(--line); border-radius: 50%; background: transparent; }
    .check.checked { border-color: var(--yellow); background: var(--yellow); }
    .task-row.done .task-name { text-decoration: line-through; }
    .task-name { flex: 1; font-size: 13px; }
    .task-date { color: var(--muted); font-family: var(--mono); font-size: 9px; }
    .delete { border: 0; color: var(--muted); background: transparent; font-size: 16px; }

    /* ---------- Calendar ---------- */
    .countdown-strip { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 22px; }
    .countdown-chip { flex: 1 1 150px; padding: 14px 16px; border: 1px solid var(--line); border-radius: 15px; background: linear-gradient(160deg, var(--paper), var(--paper-2)); }
    .countdown-chip .cd-label { display: -webkit-box; overflow: hidden; color: var(--muted); font-size: 11px; -webkit-box-orient: vertical; -webkit-line-clamp: 1; }
    .countdown-chip .cd-value { margin-top: 6px; font-family: var(--serif); font-size: 26px; letter-spacing: -.03em; }
    .countdown-chip .cd-value small { color: var(--muted); font-family: var(--sans); font-size: 12px; font-weight: 400; }
    .countdown-chip.now { border-color: var(--yellow-deep); background: linear-gradient(160deg, #fff6d8, var(--paper)); }
    .calendar-grid-layout { display: grid; grid-template-columns: 1.25fr .75fr; gap: 18px; margin-top: 24px; }
    .calendar-card, .events-card { padding: 22px; border: 1px solid var(--line); border-radius: 18px; background: var(--paper); }
    .calendar-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 18px; }
    .calendar-head h2 { margin: 0; font-family: var(--serif); font-size: 21px; }
    .month-actions { display: flex; gap: 3px; }
    .month-actions button { width: 30px; height: 30px; border: 0; border-radius: 9px; color: var(--muted); background: transparent; font-size: 20px; }
    .month-actions button:hover { background: var(--soft); }
    .week, .days { display: grid; grid-template-columns: repeat(7, 1fr); gap: 4px; text-align: center; }
    .week { margin-bottom: 6px; color: var(--muted); font-family: var(--mono); font-size: 9px; }
    .day { position: relative; display: grid; place-items: center; aspect-ratio: 1; border: 0; border-radius: 11px; color: var(--ink); background: transparent; font-size: 12px; }
    .day:hover { background: var(--soft); }
    .day.selected { color: #2c2819; background: var(--yellow); font-weight: 800; }
    .day.today:not(.selected) { color: var(--yellow-deep); font-weight: 800; }
    .day.has-event::after { content: ''; position: absolute; bottom: 5px; width: 4px; height: 4px; border-radius: 50%; background: var(--yellow-deep); }
    .events-card h2 { display: flex; align-items: center; gap: 8px; margin: 0 0 14px; font-size: 15px; }
    .events-tabs { display: flex; gap: 4px; margin-bottom: 16px; padding: 3px; border-radius: 10px; background: var(--soft); }
    .events-tabs button { flex: 1; padding: 8px; border: 0; border-radius: 8px; color: var(--muted); background: transparent; font-size: 11px; font-weight: 700; }
    .events-tabs button.active { color: var(--ink); background: var(--paper); box-shadow: var(--shadow); }
    .event-search { position: relative; margin-bottom: 14px; }
    .event-search input { width: 100%; height: 38px; padding: 0 12px 0 34px; }
    .event-search span { position: absolute; top: 10px; left: 12px; color: var(--muted); font-size: 13px; }
    .event-row { display: flex; align-items: start; gap: 10px; margin-bottom: 14px; }
    .event-dot { width: 8px; height: 8px; flex: 0 0 8px; margin-top: 5px; border-radius: 50%; background: var(--yellow); }
    .event-dot.orange { background: #ed8b31; }
    .event-copy { flex: 1; min-width: 0; }
    .event-copy strong { display: block; font-size: 13px; }
    .event-copy small { color: var(--muted); font-family: var(--mono); font-size: 9px; }
    .event-copy small.event-date-tag { display: inline-block; margin-top: 2px; padding: 2px 6px; border-radius: 99px; background: var(--soft); }
    .empty { padding: 45px 18px; border: 1px dashed var(--line); border-radius: 16px; color: var(--muted); text-align: center; font-size: 13px; }
    .empty.small { padding: 26px 14px; }
    .empty strong { display: block; margin-bottom: 5px; color: var(--ink); font-family: var(--serif); font-size: 20px; }

    /* ---------- Routine ---------- */
    .routine-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 10px; margin-top: 26px; }
    .routine-day { min-height: 200px; padding: 12px; border: 1px solid var(--line); border-radius: 15px; background: var(--paper); }
    .routine-day-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px; }
    .routine-day-head strong { font-family: var(--serif); font-size: 15px; }
    .routine-day-head button { display: grid; place-items: center; width: 22px; height: 22px; border: 0; border-radius: 7px; color: var(--muted); background: var(--soft); font-size: 14px; }
    .routine-block { position: relative; margin-bottom: 7px; padding: 8px 9px; border-radius: 10px; background: linear-gradient(160deg, #fff3c4, #ffe388); }
    .routine-block.orange { background: linear-gradient(160deg, #ffe0bd, #ffc088); }
    .routine-block strong { display: block; overflow: hidden; color: #3a3218; font-size: 11.5px; text-overflow: ellipsis; white-space: nowrap; }
    .routine-block span { color: #5a4f27; font-family: var(--mono); font-size: 9px; }
    .routine-block .delete { position: absolute; top: 3px; right: 4px; width: 16px; height: 16px; color: #5a4f27; font-size: 12px; line-height: 1; }
    .routine-empty { padding: 14px 6px; color: var(--muted); font-size: 10px; text-align: center; }
    .routine-form { display: grid; grid-template-columns: 1fr 1fr 1fr 100px 100px auto; gap: 8px; margin-top: 24px; padding: 12px; border: 1px solid var(--line); border-radius: 15px; background: var(--paper-2); }
    .routine-form select, .routine-form input { height: 42px; padding: 0 10px; }

    .mobile-nav { display: none; }
    @media (max-width: 850px) {
      .login-card { grid-template-columns: 1fr; }
      .login-hero { min-height: 380px; padding: 32px; }
      .login-hero h1 { margin-top: 20px; font-size: 50px; }
      .phone { width: 96px; height: 196px; }
      .login-form-panel { padding: 38px 32px 48px; }
      .sidebar { width: 190px; flex-basis: 190px; }
      .notes-list { width: 270px; flex-basis: 270px; }
      .editor-inner { padding: 42px 28px 72px; }
      .calendar-grid-layout { grid-template-columns: 1fr; }
      .routine-grid { grid-template-columns: repeat(4, 1fr); }
      .routine-form { grid-template-columns: 1fr 1fr; }
    }
    @media (max-width: 650px) {
      .login-shell { padding: 0; }
      .login-card { min-height: 100vh; border-radius: 0; }
      .login-hero { min-height: 300px; }
      .phone-wrap { display: none; }
      .sidebar, .topbar { display: none; }
      .workspace, .main { min-height: 100vh; }
      .mobile-nav { display: flex; align-items: center; justify-content: space-between; gap: 8px; padding: 11px 13px; border-bottom: 1px solid var(--line); background: var(--paper); }
      .mobile-nav .brand-mark { width: 31px; height: 31px; border-radius: 9px; font-size: 15px; }
      .mobile-tabs { display: flex; gap: 2px; padding: 3px; border-radius: 10px; background: var(--soft); overflow-x: auto; }
      .mobile-tabs button { padding: 7px 8px; border: 0; border-radius: 7px; color: var(--muted); background: transparent; font-size: 10px; white-space: nowrap; }
      .mobile-tabs button.active { color: var(--ink); background: var(--paper); font-weight: 700; }
      .panel { display: block; }
      .notes-list { width: 100%; height: 42vh; border-right: 0; border-bottom: 1px solid var(--line); }
      .editor { min-height: 58vh; }
      .editor-inner { padding: 32px 20px 58px; }
      .title-input { font-size: 38px; }
      .page { padding: 32px 17px 55px; }
      .page-head { align-items: start; flex-direction: column; }
      .add-form { grid-template-columns: 1fr; }
      .add-form .save-button { min-height: 42px; }
      .routine-grid { grid-template-columns: 1fr 1fr; }
      .routine-form { grid-template-columns: 1fr; }
    }
  </style>
</head>
<body class="grain">
<?php if (!$currentUser): ?>
  <main class="login-shell">
    <section class="login-card">
      <div class="login-hero">
        <div>
          <div class="brand"><span class="brand-mark">▧</span><span>morrow</span></div>
          <p class="eyebrow" style="margin-top:40px">A place to put things</p>
          <h1>Keep the thought <span>close.</span></h1>
          <p class="lede">Notes for ordinary ideas, half-written things, and code worth finding again.</p>
          <div class="phone-wrap">
            <div>
              <div class="phone" id="phone-widget">
                <div class="phone-screen"><div class="phone-notch"></div></div>
              </div>
              <p class="phone-caption">matches your device</p>
            </div>
          </div>
        </div>
        <div class="login-foot"><i></i> private by default · stored in SQLite</div>
      </div>
      <div class="login-form-panel">
        <p class="eyebrow">Welcome</p>
        <h2 id="auth-title">Sign in to your desk.</h2>
        <p class="sub" id="auth-sub">Your notes stay in your private workspace.</p>
        <div class="auth-tabs">
          <button class="active" data-tab="login" type="button">Sign in</button>
          <button data-tab="register" type="button">Create account</button>
        </div>
        <form id="login-form">
          <div class="form-row">
            <label for="login-email">Email</label>
            <input class="input" id="login-email" type="email" placeholder="you@example.com" required autocomplete="username">
          </div>
          <div class="form-row">
            <label for="login-password">Password</label>
            <input class="input" id="login-password" type="password" placeholder="••••••••" required autocomplete="current-password">
          </div>
          <button class="primary-btn" type="submit" id="login-submit"><span>Enter my workspace</span><span>→</span></button>
        </form>
        <form id="register-form" class="hidden">
          <div class="form-row">
            <label for="register-name">Your name</label>
            <input class="input" id="register-name" placeholder="What should we call you?" required>
          </div>
          <div class="form-row">
            <label for="register-email">Email</label>
            <input class="input" id="register-email" type="email" placeholder="you@example.com" required autocomplete="username">
          </div>
          <div class="form-row">
            <label for="register-password">Password</label>
            <input class="input" id="register-password" type="password" placeholder="At least 8 characters" required autocomplete="new-password" minlength="8">
          </div>
          <button class="primary-btn" type="submit" id="register-submit"><span>Create my workspace</span><span>→</span></button>
        </form>
        <div class="auth-error" id="auth-error"></div>
      </div>
    </section>
  </main>
<?php else: ?>
  <div class="workspace">
    <aside class="sidebar">
      <div class="sidebar-top">
        <div class="brand"><span class="brand-mark">▧</span><span>morrow</span></div>
        <div class="phone-mini"><div class="phone-mini-body"><div class="phone-mini-screen"></div></div></div>
      </div>
      <div class="side-label">Workspace</div>
      <nav class="nav" id="side-nav">
        <button class="active" data-module="notes"><span>▤</span><span>Notes</span></button>
        <button data-module="tasks"><span>✓</span><span>Tasks</span><span class="nav-count" id="task-count">0</span></button>
        <button data-module="calendar"><span>□</span><span>Calendar</span></button>
        <button data-module="routine"><span>◫</span><span>Routine</span></button>
      </nav>
      <div class="profile">
        <span class="avatar" id="avatar"></span>
        <div class="profile-text"><strong><?= htmlspecialchars((string) $currentUser['name'], ENT_QUOTES, 'UTF-8') ?></strong><small>SQLite workspace</small></div>
        <button class="small-btn logout" id="logout" title="Sign out">↗</button>
      </div>
    </aside>
    <main class="main">
      <div class="mobile-nav">
        <div class="brand"><span class="brand-mark">▧</span></div>
        <div class="mobile-tabs">
          <button class="active" data-module="notes">Notes</button>
          <button data-module="tasks">Tasks</button>
          <button data-module="calendar">Calendar</button>
          <button data-module="routine">Routine</button>
        </div>
        <button class="small-btn logout" id="mobile-logout">↗</button>
      </div>
      <header class="topbar"><div class="crumb"><span>⌂</span><span>/</span><strong id="module-label">Notes</strong></div><span class="date-stamp"><?= date('D, M j') ?></span></header>
      <section class="panel" id="app"></section>
    </main>
  </div>
<?php endif; ?>
<script>
const initialState = <?= json_encode($initialState, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
const currentUser = <?= json_encode($currentUser, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
const languages = ['HTML', 'Markdown', 'Python', 'JavaScript', 'PHP', 'C++'];
const weekdayNames = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
const weekdayShort = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
let state = initialState;
let moduleName = 'notes';
let activeNoteId = state.notes?.[0]?.id ?? null;
let noteSearch = '';
let noteFolder = 'All notes';
let selectedDate = new Date().toISOString().slice(0, 10);
let calendarMonth = new Date(new Date().getFullYear(), new Date().getMonth(), 1);
let eventsView = 'day';
let eventSearch = '';
let saveTimer;
let countdownTimer;

const $ = (selector, parent = document) => parent.querySelector(selector);
const $$ = (selector, parent = document) => [...parent.querySelectorAll(selector)];
const escapeHtml = (value = '') => String(value).replace(/[&<>"']/g, character => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[character]));
const uid = prefix => `${prefix}-${Date.now()}-${Math.random().toString(36).slice(2, 6)}`;
const today = new Date().toISOString().slice(0, 10);
const formatDate = value => new Date(`${value}T12:00:00`).toLocaleDateString(undefined, {month:'short', day:'numeric'});
const relative = value => {
  const minutes = Math.max(1, Math.floor((Date.now() - new Date(value).getTime()) / 60000));
  if (minutes < 60) return `${minutes}m ago`;
  if (minutes < 1440) return `${Math.floor(minutes / 60)}h ago`;
  return new Date(value).toLocaleDateString(undefined, {month:'short', day:'numeric'});
};
const save = () => {
  clearTimeout(saveTimer);
  saveTimer = setTimeout(() => fetch('?action=state', {
    method: 'POST',
    headers: {'Content-Type': 'application/json'},
    body: JSON.stringify(state)
  }).catch(() => {}), 220);
};
const setState = next => { state = next; save(); render(); };
const initials = name => name.split(' ').map(part => part[0]).join('').slice(0, 2).toUpperCase();

/* ---------- System-color aware "iPhone" widget ----------
   Reads prefers-color-scheme. Light system => white body with a
   yellow/orange screen ("aurora"); dark system => dark body with an
   orange/yellow screen glow, mirroring the iPhone "Breathe" wallpaper tones. */
function applyPhoneTheme() {
  const dark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
  const root = document.documentElement.style;
  if (dark) {
    root.setProperty('--phone-body', '#151512');
    root.setProperty('--phone-body-2', '#2b2a24');
    root.setProperty('--phone-accent', '#ed8b31');
    root.setProperty('--phone-accent-2', '#f5bf16');
  } else {
    root.setProperty('--phone-body', '#fffef7');
    root.setProperty('--phone-body-2', '#e7e5da');
    root.setProperty('--phone-accent', '#f5bf16');
    root.setProperty('--phone-accent-2', '#ed8b31');
  }
}
applyPhoneTheme();
window.matchMedia?.('(prefers-color-scheme: dark)').addEventListener?.('change', applyPhoneTheme);

/* ---------- Auth (login screen) ---------- */
function setAuthError(message) {
  const box = $('#auth-error');
  if (!box) return;
  if (!message) { box.classList.remove('show'); box.textContent = ''; return; }
  box.textContent = message; box.classList.add('show');
}
$$('.auth-tabs [data-tab]').forEach(button => button.onclick = () => {
  $$('.auth-tabs [data-tab]').forEach(item => item.classList.toggle('active', item === button));
  const isLogin = button.dataset.tab === 'login';
  $('#login-form').classList.toggle('hidden', !isLogin);
  $('#register-form').classList.toggle('hidden', isLogin);
  $('#auth-title').textContent = isLogin ? 'Sign in to your desk.' : 'Set up your desk.';
  $('#auth-sub').textContent = isLogin ? 'Your notes stay in your private workspace.' : 'A few details and your private workspace is ready.';
  setAuthError('');
});
$('#login-form')?.addEventListener('submit', async event => {
  event.preventDefault();
  setAuthError('');
  const button = $('#login-submit'); button.disabled = true;
  try {
    const response = await fetch('?action=login', {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({
      email: $('#login-email').value.trim(),
      password: $('#login-password').value
    })});
    const data = await response.json();
    if (!response.ok) { setAuthError(data.error || 'Could not sign in.'); return; }
    location.reload();
  } catch { setAuthError('Something went wrong. Try again.'); }
  finally { button.disabled = false; }
});
$('#register-form')?.addEventListener('submit', async event => {
  event.preventDefault();
  setAuthError('');
  const button = $('#register-submit'); button.disabled = true;
  try {
    const response = await fetch('?action=register', {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({
      name: $('#register-name').value.trim(),
      email: $('#register-email').value.trim(),
      password: $('#register-password').value
    })});
    const data = await response.json();
    if (!response.ok) { setAuthError(data.error || 'Could not create your workspace.'); return; }
    location.reload();
  } catch { setAuthError('Something went wrong. Try again.'); }
  finally { button.disabled = false; }
});

function setModule(next) {
  moduleName = next;
  $$('#side-nav [data-module], .mobile-tabs [data-module]').forEach(button => button.classList.toggle('active', button.dataset.module === next));
  $('#module-label').textContent = next[0].toUpperCase() + next.slice(1);
  render();
}

/* ---------- Notes ---------- */
function renderNotes() {
  const folders = ['All notes', ...new Set(state.notes.map(note => note.folder))];
  const notes = state.notes.filter(note => (noteFolder === 'All notes' || note.folder === noteFolder) && `${note.title} ${note.body}`.toLowerCase().includes(noteSearch.toLowerCase())).sort((a,b) => Number(b.pinned)-Number(a.pinned) || new Date(b.updatedAt)-new Date(a.updatedAt));
  const active = state.notes.find(note => note.id === activeNoteId);
  const list = notes.length ? notes.map(note => `<button class="note-card ${activeNoteId === note.id ? 'active' : ''} ${note.pinned ? 'pinned' : ''}" data-note="${note.id}">
    <div class="note-title"><span class="pin-dot"></span><span>${escapeHtml(note.title || 'Untitled note')}</span>${note.pinned ? '<span style="margin-left:auto;color:var(--yellow-deep)">●</span>' : ''}</div>
    <div class="note-preview">${escapeHtml(note.preview || 'Empty note')}</div>
    <div class="note-meta"><span>${relative(note.updatedAt)}</span><span>·</span><span>${escapeHtml(note.folder)}</span></div>
  </button>`).join('') : `<div class="empty" style="margin:10px 4px"><strong>${state.notes.length ? 'Nothing here yet.' : 'No notes yet.'}</strong>${state.notes.length ? 'Try another folder or start a new note.' : 'Start your first note with the + button.'}</div>`;
  const toolbarButtons = [
    ['B','**','**','Bold'], ['I','_','_','Italic'], ['U','<u>','</u>','Underline'], ['S','~~','~~','Strikethrough'],
    'sep',
    ['H1','# ','','Heading 1'], ['H2','## ','','Heading 2'],
    'sep',
    ['•','- ','','Bullet list'], ['1.','1. ','','Numbered list'], ['☐','- [ ] ','','Checklist'],
    'sep',
    ['❝','> ','','Quote'], ['{}','`','`','Inline code'], ['▤','```\n','\n```','Code block'], ['↗','[','](url)','Link'],
  ];
  const editor = active ? `<article class="editor">
    <div class="editor-bar"><span class="editor-path">${escapeHtml(active.folder)} / ${escapeHtml(active.language)}</span><div class="editor-actions">
      <span style="font-family:var(--mono);font-size:9px;color:var(--muted);margin-right:8px">saved to SQLite</span>
      <button class="icon-btn ${active.pinned ? 'active' : ''}" data-action="pin" title="Pin note">●</button>
      <button class="icon-btn" data-action="delete" title="Delete note">×</button>
    </div></div>
    <div class="editor-scroll"><div class="editor-inner">
      <input class="title-input" data-field="title" value="${escapeHtml(active.title)}" placeholder="Untitled note">
      <div class="note-info"><span class="pill">${escapeHtml(active.folder)}</span><select class="language" data-field="language">${languages.map(language => `<option ${active.language === language ? 'selected' : ''}>${language}</option>`).join('')}</select><span>Edited ${relative(active.updatedAt)}</span></div>
      <div class="toolbar">${toolbarButtons.map(item => item === 'sep' ? '<span class="toolbar-sep"></span>' : `<button class="${item[0].length > 1 ? 'wide' : ''}" data-format="${item[1]}|${item[2]}" title="${item[3]}">${item[0]}</button>`).join('')}</div>
      <textarea class="body-editor" data-field="body" placeholder="Start with the thing on your mind…">${escapeHtml(active.body)}</textarea>
      <div class="editor-foot"><span>▱</span><span>Stored in ${escapeHtml(active.folder)}</span><span>Never executed · always yours</span></div>
    </div></div>
  </article>` : `<article class="editor"><div class="empty" style="margin:auto"><strong>A blank page, waiting.</strong>Choose a note or create a new one.</div></article>`;
  $('#app').innerHTML = `<div class="notes-list">
    <div class="list-heading"><div><div class="eyebrow" style="color:var(--yellow-deep)">Your desk</div><h2>Notes</h2></div><button class="new-note" data-action="new-note">+</button></div>
    <div class="search"><span>⌕</span><input id="note-search" type="search" placeholder="Search notes" value="${escapeHtml(noteSearch)}"></div>
    <div class="filters">${folders.map(folder => `<button class="${folder === noteFolder ? 'active' : ''}" data-folder="${escapeHtml(folder)}">${escapeHtml(folder)}</button>`).join('')}</div>
    <div>${list}</div>
  </div>${editor}`;
  bindNotes();
}

function bindNotes() {
  $$('#app [data-note]').forEach(button => button.onclick = () => { activeNoteId = button.dataset.note; render(); });
  $$('#app [data-folder]').forEach(button => button.onclick = () => { noteFolder = button.dataset.folder; render(); });
  $('#note-search').oninput = event => { noteSearch = event.target.value; renderNotes(); };
  $('[data-action="new-note"]').onclick = () => {
    const note = {id:uid('n'), title:'', body:'', preview:'', updatedAt:new Date().toISOString(), pinned:false, folder:'Ideas', language:'Markdown'};
    state.notes.unshift(note); activeNoteId = note.id; save(); render();
  };
  const active = () => state.notes.find(note => note.id === activeNoteId);
  $$('[data-field]').forEach(field => field.oninput = event => {
    const note = active(); if (!note) return;
    note[field.dataset.field] = event.target.value;
    if (field.dataset.field === 'body') note.preview = event.target.value.split('\n').find(Boolean)?.slice(0, 100) || '';
    note.updatedAt = new Date().toISOString(); save();
  });
  $$('[data-field="language"]').forEach(field => field.onchange = event => { const note = active(); if (note) { note.language = event.target.value; note.updatedAt = new Date().toISOString(); save(); renderNotes(); }});
  $('[data-action="pin"]')?.addEventListener('click', () => { const note = active(); if (note) { note.pinned = !note.pinned; note.updatedAt = new Date().toISOString(); setState(state); }});
  $('[data-action="delete"]')?.addEventListener('click', () => { if (confirm('Delete this note?')) { state.notes = state.notes.filter(note => note.id !== activeNoteId); activeNoteId = state.notes[0]?.id ?? null; setState(state); }});
  $$('.toolbar button').forEach(button => button.onclick = () => {
    const textarea = $('.body-editor'); const [before, after] = button.dataset.format.split('|'); const start = textarea.selectionStart, end = textarea.selectionEnd; const selected = textarea.value.slice(start, end) || 'text';
    textarea.setRangeText(`${before}${selected}${after}`, start, end, 'select'); textarea.dispatchEvent(new Event('input', {bubbles:true})); textarea.focus();
  });
}

/* ---------- Tasks ---------- */
function renderTasks() {
  const open = state.tasks.filter(task => !task.completed), done = state.tasks.filter(task => task.completed);
  const taskRows = tasks => tasks.map(task => `<div class="task-row ${task.completed ? 'done' : ''}">
    <button class="check ${task.completed ? 'checked' : ''}" data-task-toggle="${task.id}">${task.completed ? '✓' : ''}</button><span class="task-name">${escapeHtml(task.title)}</span><span class="task-date">${task.dueDate === today ? 'today' : formatDate(task.dueDate)}</span><button class="delete" data-task-delete="${task.id}">×</button>
  </div>`).join('');
  $('#app').innerHTML = `<section class="page"><div class="page-inner"><div class="page-head"><div><div class="eyebrow" style="color:var(--yellow-deep)">Small steps</div><h1>Tasks</h1><p>${open.length} open · ${done.length} finished</p></div><button class="add-button" id="show-task-form">+ Add task</button></div>
    <form class="add-form hidden" id="task-form"><input id="task-title" placeholder="What needs doing?" required><input id="task-date" type="date" value="${today}"><button class="save-button">Save</button></form>
    <div class="task-group">${open.length ? taskRows(open) : '<div class="empty"><strong>A clear little horizon.</strong>Everything is done for now.</div>'}</div>
    ${done.length ? `<div class="task-group"><p class="group-label">Finished</p>${taskRows(done)}</div>` : ''}</div></section>`;
  $('#show-task-form').onclick = () => { $('#task-form').classList.toggle('hidden'); $('#task-title').focus(); };
  $('#task-form').onsubmit = event => { event.preventDefault(); state.tasks.unshift({id:uid('t'), title:$('#task-title').value.trim(), dueDate:$('#task-date').value, completed:false}); setState(state); };
  $$('[data-task-toggle]').forEach(button => button.onclick = () => { const task = state.tasks.find(item => item.id === button.dataset.taskToggle); if (task) task.completed = !task.completed; setState(state); });
  $$('[data-task-delete]').forEach(button => button.onclick = () => { state.tasks = state.tasks.filter(item => item.id !== button.dataset.taskDelete); setState(state); });
}

/* ---------- Calendar ---------- */
function nextOccurrence(event) {
  const dt = new Date(`${event.date}T${event.time || '00:00'}:00`);
  return dt;
}
function formatCountdown(ms) {
  if (ms <= 0) return 'now';
  const totalMinutes = Math.floor(ms / 60000);
  const days = Math.floor(totalMinutes / 1440);
  const hours = Math.floor((totalMinutes % 1440) / 60);
  const minutes = totalMinutes % 60;
  if (days > 0) return `${days}d ${hours}h`;
  if (hours > 0) return `${hours}h ${minutes}m`;
  return `${minutes}m`;
}
function renderCountdowns() {
  const strip = $('#countdown-strip');
  if (!strip) return;
  const now = Date.now();
  const upcoming = state.events
    .map(event => ({...event, at: nextOccurrence(event).getTime()}))
    .filter(event => event.at >= now - 60000)
    .sort((a,b) => a.at - b.at)
    .slice(0, 4);
  strip.innerHTML = upcoming.length ? upcoming.map(event => {
    const diff = event.at - now;
    return `<div class="countdown-chip ${diff <= 3600000 ? 'now' : ''}"><div class="cd-label">${escapeHtml(event.title)}</div><div class="cd-value">${formatCountdown(diff)} <small>${diff <= 0 ? 'happening now' : 'to go'}</small></div></div>`;
  }).join('') : '<div class="empty small" style="flex:1"><strong>Nothing on the horizon.</strong>Add an event to see a countdown here.</div>';
}
function renderCalendar() {
  const year = calendarMonth.getFullYear(), month = calendarMonth.getMonth(), firstDay = (new Date(year, month, 1).getDay() + 6) % 7, days = new Date(year, month + 1, 0).getDate();
  let calendar = Array(firstDay).fill('<span></span>');
  for (let day = 1; day <= days; day++) {
    const date = `${year}-${String(month + 1).padStart(2,'0')}-${String(day).padStart(2,'0')}`, hasEvent = state.events.some(event => event.date === date);
    calendar.push(`<button class="day ${date === selectedDate ? 'selected' : ''} ${date === today ? 'today' : ''} ${hasEvent ? 'has-event' : ''}" data-date="${date}">${day}</button>`);
  }
  const dayEvents = state.events.filter(event => event.date === selectedDate).sort((a,b) => a.time.localeCompare(b.time));
  const allEvents = state.events
    .filter(event => event.title.toLowerCase().includes(eventSearch.toLowerCase()))
    .sort((a,b) => `${a.date}${a.time}`.localeCompare(`${b.date}${b.time}`));
  const eventRow = (event, showDate) => `<div class="event-row"><span class="event-dot ${event.color === 'orange' ? 'orange' : ''}"></span><div class="event-copy"><strong>${escapeHtml(event.title)}</strong><small>${escapeHtml(event.time)}</small>${showDate ? `<br><small class="event-date-tag">${formatDate(event.date)}</small>` : ''}</div><button class="delete" data-event-delete="${event.id}">×</button></div>`;
  const eventsPane = eventsView === 'day'
    ? (dayEvents.length ? dayEvents.map(event => eventRow(event, false)).join('') : '<div class="empty small"><strong>No plans here.</strong>A little open space can be useful.</div>')
    : `<div class="event-search"><span>⌕</span><input id="event-search" type="search" placeholder="Search all events" value="${escapeHtml(eventSearch)}"></div>` +
      (allEvents.length ? allEvents.map(event => eventRow(event, true)).join('') : '<div class="empty small"><strong>No matches.</strong>Try a different search.</div>');
  $('#app').innerHTML = `<section class="page"><div class="page-inner"><div class="page-head"><div><div class="eyebrow" style="color:var(--yellow-deep)">Make some room</div><h1>Calendar</h1><p>${new Date(`${selectedDate}T12:00:00`).toLocaleDateString(undefined,{weekday:'long',month:'long',day:'numeric'})}</p></div><button class="add-button" id="show-event-form">+ Add event</button></div>
    <div class="countdown-strip" id="countdown-strip"></div>
    <div class="calendar-grid-layout"><div class="calendar-card"><div class="calendar-head"><h2>${calendarMonth.toLocaleDateString(undefined,{month:'long',year:'numeric'})}</h2><div class="month-actions"><button id="prev-month">‹</button><button id="next-month">›</button></div></div><div class="week">${['M','T','W','T','F','S','S'].map((day,index)=>`<span key="${index}">${day}</span>`).join('')}</div><div class="days">${calendar.join('')}</div></div>
    <div class="events-card">
      <h2>◷ Events</h2>
      <div class="events-tabs"><button class="${eventsView === 'day' ? 'active' : ''}" data-events-view="day">Selected day</button><button class="${eventsView === 'all' ? 'active' : ''}" data-events-view="all">All events</button></div>
      <div id="events-pane">${eventsPane}</div>
    </div></div>
    <form class="add-form hidden" id="event-form"><input id="event-title" placeholder="Event title" required><input id="event-time" type="time" value="09:00"><select id="event-color"><option value="yellow">Yellow</option><option value="orange">Orange</option></select><button class="save-button">Save</button></form></div></section>`;
  renderCountdowns();
  clearInterval(countdownTimer);
  countdownTimer = setInterval(renderCountdowns, 60000);
  $$('.day').forEach(button => button.onclick = () => { selectedDate = button.dataset.date; renderCalendar(); });
  $('#prev-month').onclick = () => { calendarMonth = new Date(year, month - 1, 1); renderCalendar(); };
  $('#next-month').onclick = () => { calendarMonth = new Date(year, month + 1, 1); renderCalendar(); };
  $('#show-event-form').onclick = () => { $('#event-form').classList.toggle('hidden'); $('#event-title').focus(); };
  $('#event-form').onsubmit = event => { event.preventDefault(); state.events.push({id:uid('e'), title:$('#event-title').value.trim(), date:selectedDate, time:$('#event-time').value, color:$('#event-color').value}); setState(state); };
  $$('[data-events-view]').forEach(button => button.onclick = () => { eventsView = button.dataset.eventsView; renderCalendar(); });
  $('#event-search')?.addEventListener('input', event => { eventSearch = event.target.value; $('#events-pane').innerHTML = state.events.filter(item => item.title.toLowerCase().includes(eventSearch.toLowerCase())).sort((a,b) => `${a.date}${a.time}`.localeCompare(`${b.date}${b.time}`)).map(item => eventRow(item, true)).join('') || '<div class="empty small"><strong>No matches.</strong>Try a different search.</div>'; $$('[data-event-delete]').forEach(bindEventDelete); });
  $$('[data-event-delete]').forEach(bindEventDelete);
  function bindEventDelete(button) { button.onclick = () => { state.events = state.events.filter(item => item.id !== button.dataset.eventDelete); setState(state); }; }
}

/* ---------- Routine (weekly plan) ---------- */
function renderRoutine() {
  const byDay = weekdayNames.map((_, index) => state.routines.filter(item => item.dayOfWeek === index).sort((a,b) => a.startTime.localeCompare(b.startTime)));
  const columns = weekdayNames.map((name, index) => `<div class="routine-day">
    <div class="routine-day-head"><strong>${weekdayShort[index]}</strong><button data-add-day="${index}" title="Add block">+</button></div>
    ${byDay[index].length ? byDay[index].map(block => `<div class="routine-block ${block.color === 'orange' ? 'orange' : ''}"><button class="delete" data-routine-delete="${block.id}">×</button><strong>${escapeHtml(block.title)}</strong><span>${escapeHtml(block.startTime)}–${escapeHtml(block.endTime)}</span></div>`).join('') : '<div class="routine-empty">Open</div>'}
  </div>`).join('');
  $('#app').innerHTML = `<section class="page"><div class="page-inner">
    <div class="page-head"><div><div class="eyebrow" style="color:var(--yellow-deep)">Your week, on repeat</div><h1>Routine</h1><p>Plan how a normal week goes, once — it stays the same until you change it.</p></div><button class="add-button" id="show-routine-form">+ Add block</button></div>
    <div class="routine-grid">${columns}</div>
    <form class="add-form routine-form hidden" id="routine-form">
      <select id="routine-day">${weekdayNames.map((name, index) => `<option value="${index}" ${index === (new Date().getDay() + 6) % 7 ? 'selected' : ''}>${name}</option>`).join('')}</select>
      <input id="routine-title" placeholder="What are you doing?" required>
      <select id="routine-color"><option value="yellow">Yellow</option><option value="orange">Orange</option></select>
      <input id="routine-start" type="time" value="09:00">
      <input id="routine-end" type="time" value="10:00">
      <button class="save-button">Save</button>
    </form>
  </div></section>`;
  $('#show-routine-form').onclick = () => { $('#routine-form').classList.toggle('hidden'); $('#routine-title').focus(); };
  $$('[data-add-day]').forEach(button => button.onclick = () => { $('#routine-form').classList.remove('hidden'); $('#routine-day').value = button.dataset.addDay; $('#routine-title').focus(); });
  $('#routine-form').onsubmit = event => {
    event.preventDefault();
    const start = $('#routine-start').value, end = $('#routine-end').value;
    if (end <= start) { alert('End time should be after the start time.'); return; }
    state.routines.push({id:uid('r'), dayOfWeek:Number($('#routine-day').value), title:$('#routine-title').value.trim(), startTime:start, endTime:end, color:$('#routine-color').value});
    $('#routine-title').value = '';
    setState(state);
  };
  $$('[data-routine-delete]').forEach(button => button.onclick = () => { state.routines = state.routines.filter(item => item.id !== button.dataset.routineDelete); setState(state); });
}

function render() {
  if (moduleName === 'notes') renderNotes();
  if (moduleName === 'tasks') renderTasks();
  if (moduleName === 'calendar') renderCalendar();
  if (moduleName === 'routine') renderRoutine();
  $('#task-count').textContent = state.tasks.filter(task => !task.completed).length;
}

$$('[data-module]').forEach(button => button.onclick = () => setModule(button.dataset.module));
$('#logout')?.addEventListener('click', () => fetch('?action=logout', {method:'POST'}).finally(() => location.reload()));
$('#mobile-logout')?.addEventListener('click', () => fetch('?action=logout', {method:'POST'}).finally(() => location.reload()));

if (currentUser) {
  $('#avatar').textContent = initials(currentUser.name);
  render();
}
</script>
</body>
</html>