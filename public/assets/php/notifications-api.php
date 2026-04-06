<?php
// Capture any stray output (PHP notices/warnings) so they can't corrupt JSON
ob_start();
// Suppress display of PHP errors into output
ini_set('display_errors', '0');
error_reporting(E_ALL);

session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

$configFile = __DIR__ . '/notifications-auth-config.php';
if (!file_exists($configFile)) {
    respond(500, ['error' => 'Auth configuration not found. Please create notifications-auth-config.php from the .example file.']);
}
require $configFile;
// Provides: $STAFF_USERS = ['username' => 'bcrypt_hash', ...]

$dataFile = __DIR__ . '/../data/notifications.json';

// ── Helpers ──────────────────────────────────────────────────────────────────

function respond(int $code, array $data): void {
    ob_clean(); // discard any stray output before sending JSON
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function generate_uuid(): string {
    $d = random_bytes(16);
    $d[6] = chr(ord($d[6]) & 0x0f | 0x40);
    $d[8] = chr(ord($d[8]) & 0x3f | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
}

function now_nz(): string {
    return (new DateTime('now', new DateTimeZone('Pacific/Auckland')))->format(DateTime::ATOM);
}

function read_data(string $file): array {
    if (!file_exists($file)) {
        return ['notifications' => []];
    }
    $data = json_decode(file_get_contents($file), true);
    return is_array($data) ? $data : ['notifications' => []];
}

function write_data(string $file, array $data): bool {
    $tmp = $file . '.tmp.' . getmypid();
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (file_put_contents($tmp, $json, LOCK_EX) === false) {
        return false;
    }
    return rename($tmp, $file);
}

function require_auth(): void {
    if (empty($_SESSION['staff_user'])) {
        respond(401, ['error' => 'Authentication required']);
    }
}

function current_user(): string {
    return $_SESSION['staff_user'] ?? '';
}

function valid_status(string $s): bool {
    return in_array($s, ['info', 'warning', 'outage', 'resolved'], true);
}

// ── Router ───────────────────────────────────────────────────────────────────

$action = $_GET['action'] ?? 'list';
$method = $_SERVER['REQUEST_METHOD'];

$body = [];
if ($method === 'POST') {
    $raw = file_get_contents('php://input');
    $body = json_decode($raw, true) ?? [];
}

// ── Actions ──────────────────────────────────────────────────────────────────

switch ($action) {

    // Public: list all notifications (newest first)
    case 'list':
        $data = read_data($dataFile);
        $notifications = $data['notifications'] ?? [];
        usort($notifications, fn($a, $b) => strcmp($b['created_at'], $a['created_at']));
        respond(200, ['notifications' => $notifications]);

    // Check session state
    case 'me':
        if (!empty($_SESSION['staff_user'])) {
            respond(200, ['logged_in' => true, 'user' => $_SESSION['staff_user']]);
        }
        respond(200, ['logged_in' => false, 'user' => null]);

    // Staff login
    case 'login':
        if ($method !== 'POST') respond(405, ['error' => 'Method not allowed']);

        $username = trim($body['username'] ?? '');
        $password = $body['password'] ?? '';

        if ($username === '' || $password === '') {
            respond(400, ['error' => 'Username and password required']);
        }

        // Constant-time check to prevent user enumeration
        $hash = $STAFF_USERS[$username] ?? '$2y$10$invalidhashthatalwaysfailsXXXXXXXXXXXXXX';
        if (!password_verify($password, $hash) || !isset($STAFF_USERS[$username])) {
            respond(401, ['error' => 'Invalid credentials']);
        }

        session_regenerate_id(true);
        $_SESSION['staff_user'] = $username;
        respond(200, ['logged_in' => true, 'user' => $username]);

    // Staff logout
    case 'logout':
        if ($method !== 'POST') respond(405, ['error' => 'Method not allowed']);
        $_SESSION = [];
        session_destroy();
        respond(200, ['logged_in' => false]);

    // Create notification
    case 'create':
        require_auth();
        if ($method !== 'POST') respond(405, ['error' => 'Method not allowed']);

        $title  = trim($body['title'] ?? '');
        $text   = trim($body['body'] ?? '');
        $status = $body['status'] ?? 'info';

        if ($title === '' || $text === '') respond(400, ['error' => 'Title and body are required']);
        if (!valid_status($status)) respond(400, ['error' => 'Invalid status']);

        $now = now_nz();
        $notification = [
            'id'         => generate_uuid(),
            'title'      => $title,
            'body'       => $text,
            'status'     => $status,
            'author'     => current_user(),
            'created_at' => $now,
            'updated_at' => $now,
            'comments'   => [],
        ];

        $data = read_data($dataFile);
        array_unshift($data['notifications'], $notification);
        if (!write_data($dataFile, $data)) respond(500, ['error' => 'Failed to save notification']);

        respond(201, ['notification' => $notification]);

    // Update notification
    case 'update':
        require_auth();
        if ($method !== 'POST') respond(405, ['error' => 'Method not allowed']);

        $id     = $_GET['id'] ?? '';
        $title  = trim($body['title'] ?? '');
        $text   = trim($body['body'] ?? '');
        $status = $body['status'] ?? '';

        if ($id === '') respond(400, ['error' => 'ID required']);
        if ($title === '' || $text === '') respond(400, ['error' => 'Title and body required']);
        if (!valid_status($status)) respond(400, ['error' => 'Invalid status']);

        $data = read_data($dataFile);
        $found = false;
        foreach ($data['notifications'] as &$n) {
            if ($n['id'] === $id) {
                $n['title']      = $title;
                $n['body']       = $text;
                $n['status']     = $status;
                $n['updated_at'] = now_nz();
                $updated = $n;
                $found = true;
                break;
            }
        }
        unset($n);

        if (!$found) respond(404, ['error' => 'Notification not found']);
        if (!write_data($dataFile, $data)) respond(500, ['error' => 'Failed to save']);

        respond(200, ['notification' => $updated]);

    // Delete notification
    case 'delete':
        require_auth();
        if ($method !== 'POST') respond(405, ['error' => 'Method not allowed']);

        $id = $_GET['id'] ?? '';
        if ($id === '') respond(400, ['error' => 'ID required']);

        $data = read_data($dataFile);
        $before = count($data['notifications']);
        $data['notifications'] = array_values(
            array_filter($data['notifications'], fn($n) => $n['id'] !== $id)
        );

        if (count($data['notifications']) === $before) respond(404, ['error' => 'Notification not found']);
        if (!write_data($dataFile, $data)) respond(500, ['error' => 'Failed to save']);

        respond(200, ['success' => true]);

    // Add comment
    case 'comment_add':
        require_auth();
        if ($method !== 'POST') respond(405, ['error' => 'Method not allowed']);

        $id   = $_GET['id'] ?? '';
        $text = trim($body['body'] ?? '');

        if ($id === '') respond(400, ['error' => 'Notification ID required']);
        if ($text === '') respond(400, ['error' => 'Comment body required']);

        $now = now_nz();
        $comment = [
            'id'         => generate_uuid(),
            'author'     => current_user(),
            'body'       => $text,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        $data = read_data($dataFile);
        $found = false;
        foreach ($data['notifications'] as &$n) {
            if ($n['id'] === $id) {
                $n['comments'][] = $comment;
                $found = true;
                break;
            }
        }
        unset($n);

        if (!$found) respond(404, ['error' => 'Notification not found']);
        if (!write_data($dataFile, $data)) respond(500, ['error' => 'Failed to save']);

        respond(201, ['comment' => $comment]);

    // Update comment
    case 'comment_update':
        require_auth();
        if ($method !== 'POST') respond(405, ['error' => 'Method not allowed']);

        $id        = $_GET['id'] ?? '';
        $commentId = $_GET['comment_id'] ?? '';
        $text      = trim($body['body'] ?? '');

        if ($id === '' || $commentId === '') respond(400, ['error' => 'Notification and comment ID required']);
        if ($text === '') respond(400, ['error' => 'Comment body required']);

        $data = read_data($dataFile);
        $found = false;
        foreach ($data['notifications'] as &$n) {
            if ($n['id'] === $id) {
                foreach ($n['comments'] as &$c) {
                    if ($c['id'] === $commentId) {
                        $c['body']       = $text;
                        $c['updated_at'] = now_nz();
                        $updated = $c;
                        $found = true;
                        break 2;
                    }
                }
                unset($c);
            }
        }
        unset($n);

        if (!$found) respond(404, ['error' => 'Comment not found']);
        if (!write_data($dataFile, $data)) respond(500, ['error' => 'Failed to save']);

        respond(200, ['comment' => $updated]);

    // Delete comment
    case 'comment_delete':
        require_auth();
        if ($method !== 'POST') respond(405, ['error' => 'Method not allowed']);

        $id        = $_GET['id'] ?? '';
        $commentId = $_GET['comment_id'] ?? '';

        if ($id === '' || $commentId === '') respond(400, ['error' => 'Notification and comment ID required']);

        $data = read_data($dataFile);
        $found = false;
        foreach ($data['notifications'] as &$n) {
            if ($n['id'] === $id) {
                $before = count($n['comments']);
                $n['comments'] = array_values(
                    array_filter($n['comments'], fn($c) => $c['id'] !== $commentId)
                );
                if (count($n['comments']) < $before) $found = true;
                break;
            }
        }
        unset($n);

        if (!$found) respond(404, ['error' => 'Comment not found']);
        if (!write_data($dataFile, $data)) respond(500, ['error' => 'Failed to save']);

        respond(200, ['success' => true]);

    default:
        respond(404, ['error' => 'Unknown action']);
}
