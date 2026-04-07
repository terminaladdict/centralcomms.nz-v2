<?php
ob_start();
ini_set('display_errors', '0');
error_reporting(E_ALL);

session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

$configFile = __DIR__ . '/updates-auth-config.php';
if (!file_exists($configFile)) {
    respond(500, ['error' => 'Auth configuration not found. Please create updates-auth-config.php from the .example file.']);
}
require $configFile;
// Provides: $UPDATES_STAFF_USERS = ['username' => 'bcrypt_hash', ...]

$dataFile  = __DIR__ . '/../data/updates.json';
$imagesDir = __DIR__ . '/../../assets/images/posts/';

// ── Helpers ──────────────────────────────────────────────────────────────────

function respond(int $code, array $data): void {
    ob_clean();
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

function require_auth(): void {
    if (empty($_SESSION['updates_staff_user'])) {
        respond(401, ['error' => 'Authentication required']);
    }
}

function current_user(): string {
    return $_SESSION['updates_staff_user'] ?? '';
}

function read_data(string $file): array {
    if (!file_exists($file)) return ['updates' => []];
    $data = json_decode(file_get_contents($file), true);
    return is_array($data) ? $data : ['updates' => []];
}

function write_data(string $file, array $data): bool {
    $tmp = $file . '.tmp.' . getmypid();
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (file_put_contents($tmp, $json, LOCK_EX) === false) return false;
    return rename($tmp, $file);
}

function slugify(string $title): string {
    $slug = mb_strtolower($title, 'UTF-8');
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
    return trim($slug, '-');
}

function unique_slug(string $base, array $existing, ?string $exclude = null): string {
    if (!in_array($base, $existing) || $base === $exclude) return $base;
    $i = 2;
    while (in_array("{$base}-{$i}", $existing) && "{$base}-{$i}" !== $exclude) $i++;
    return "{$base}-{$i}";
}

function validate_update_fields(array $body): array {
    $errors = [];
    if (empty(trim($body['title'] ?? '')))   $errors[] = 'Title is required';
    if (empty(trim($body['date'] ?? '')))    $errors[] = 'Date is required';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($body['date'] ?? ''))) $errors[] = 'Date must be YYYY-MM-DD';
    return $errors;
}

// ── Router ───────────────────────────────────────────────────────────────────

$action = $_GET['action'] ?? 'list';
$method = $_SERVER['REQUEST_METHOD'];

$body = [];
if ($method === 'POST' && strpos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false) {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
}

// ── Actions ──────────────────────────────────────────────────────────────────

switch ($action) {

    case 'me':
        if (!empty($_SESSION['updates_staff_user'])) {
            respond(200, ['logged_in' => true, 'user' => $_SESSION['updates_staff_user']]);
        }
        respond(200, ['logged_in' => false, 'user' => null]);

    case 'login':
        if ($method !== 'POST') respond(405, ['error' => 'Method not allowed']);
        $username = trim($body['username'] ?? '');
        $password = $body['password'] ?? '';
        if ($username === '' || $password === '') respond(400, ['error' => 'Username and password required']);
        $hash = $UPDATES_STAFF_USERS[$username] ?? '$2y$10$invalidhashthatalwaysfailsXXXXXXXXXXXXXX';
        if (!password_verify($password, $hash) || !isset($UPDATES_STAFF_USERS[$username])) {
            respond(401, ['error' => 'Invalid credentials']);
        }
        session_regenerate_id(true);
        $_SESSION['updates_staff_user'] = $username;
        respond(200, ['logged_in' => true, 'user' => $username]);

    case 'logout':
        if ($method !== 'POST') respond(405, ['error' => 'Method not allowed']);
        unset($_SESSION['updates_staff_user']);
        respond(200, ['logged_in' => false]);

    case 'list':
        $data = read_data($dataFile);
        respond(200, ['updates' => $data['updates'] ?? []]);

    case 'get':
        $slug = $_GET['slug'] ?? '';
        if ($slug === '') respond(400, ['error' => 'Slug required']);
        $data = read_data($dataFile);
        foreach ($data['updates'] as $u) {
            if ($u['slug'] === $slug) respond(200, ['update' => $u]);
        }
        respond(404, ['error' => 'Update not found']);

    case 'create':
        require_auth();
        if ($method !== 'POST') respond(405, ['error' => 'Method not allowed']);
        $errors = validate_update_fields($body);
        if ($errors) respond(400, ['errors' => $errors]);

        $data = read_data($dataFile);
        $existingSlugs = array_column($data['updates'], 'slug');
        $baseSlug = slugify(trim($body['title']));
        $slug = unique_slug($baseSlug, $existingSlugs);

        $update = [
            'slug'         => $slug,
            'title'        => trim($body['title']),
            'date'         => trim($body['date']),
            'author'       => trim($body['author'] ?? current_user()),
            'image'        => $body['image'] ?? null,
            'categories'   => array_values(array_filter(array_map('trim', (array)($body['categories'] ?? [])))),
            'excerpt'      => trim($body['excerpt'] ?? ''),
            'content_html' => $body['content_html'] ?? '',
        ];

        array_unshift($data['updates'], $update);
        // Keep sorted by date desc
        usort($data['updates'], fn($a, $b) => strcmp($b['date'], $a['date']));

        if (!write_data($dataFile, $data)) respond(500, ['error' => 'Failed to save']);
        respond(201, ['update' => $update]);

    case 'update':
        require_auth();
        if ($method !== 'POST') respond(405, ['error' => 'Method not allowed']);
        $slug = $_GET['slug'] ?? '';
        if ($slug === '') respond(400, ['error' => 'Slug required']);
        $errors = validate_update_fields($body);
        if ($errors) respond(400, ['errors' => $errors]);

        $data = read_data($dataFile);
        $found = false;
        foreach ($data['updates'] as &$u) {
            if ($u['slug'] === $slug) {
                $u['title']        = trim($body['title']);
                $u['date']         = trim($body['date']);
                $u['author']       = trim($body['author'] ?? $u['author']);
                $u['image']        = array_key_exists('image', $body) ? $body['image'] : $u['image'];
                $u['categories']   = array_values(array_filter(array_map('trim', (array)($body['categories'] ?? []))));
                $u['excerpt']      = trim($body['excerpt'] ?? '');
                $u['content_html'] = $body['content_html'] ?? $u['content_html'];
                $updated = $u;
                $found = true;
                break;
            }
        }
        unset($u);
        if (!$found) respond(404, ['error' => 'Update not found']);
        usort($data['updates'], fn($a, $b) => strcmp($b['date'], $a['date']));
        if (!write_data($dataFile, $data)) respond(500, ['error' => 'Failed to save']);
        respond(200, ['update' => $updated]);

    case 'delete':
        require_auth();
        if ($method !== 'POST') respond(405, ['error' => 'Method not allowed']);
        $slug = $_GET['slug'] ?? '';
        if ($slug === '') respond(400, ['error' => 'Slug required']);
        $data = read_data($dataFile);
        $before = count($data['updates']);
        $data['updates'] = array_values(array_filter($data['updates'], fn($u) => $u['slug'] !== $slug));
        if (count($data['updates']) === $before) respond(404, ['error' => 'Update not found']);
        if (!write_data($dataFile, $data)) respond(500, ['error' => 'Failed to save']);
        respond(200, ['success' => true]);

    case 'upload_image':
        require_auth();
        if ($method !== 'POST') respond(405, ['error' => 'Method not allowed']);
        if (!isset($_FILES['file'])) respond(400, ['error' => 'No file uploaded']);

        $file = $_FILES['file'];
        if ($file['error'] !== UPLOAD_ERR_OK) respond(400, ['error' => 'Upload error: ' . $file['error']]);
        if ($file['size'] > 10 * 1024 * 1024) respond(400, ['error' => 'File too large (max 10 MB)']);

        $mime = mime_content_type($file['tmp_name']);
        $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        if (!in_array($mime, $allowed)) respond(400, ['error' => 'Invalid file type. Allowed: jpg, png, gif, webp']);

        $ext = match($mime) {
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/gif'  => 'gif',
            'image/webp' => 'webp',
        };

        // Keep original filename if it's safe, otherwise generate one
        $original = pathinfo($file['name'], PATHINFO_FILENAME);
        $original = preg_replace('/[^a-zA-Z0-9_\-]/', '-', $original);
        $original = trim($original, '-') ?: 'image';

        // Append a random suffix to avoid collisions without a TOCTOU race
        $filename = $original . '-' . bin2hex(random_bytes(3)) . '.' . $ext;
        $dest = $imagesDir . $filename;

        if (!move_uploaded_file($file['tmp_name'], $dest)) respond(500, ['error' => 'Failed to save file']);

        respond(201, [
            'filename' => $filename,
            'url'      => '/assets/images/posts/' . $filename,
        ]);

    case 'delete_image':
        require_auth();
        if ($method !== 'POST') respond(405, ['error' => 'Method not allowed']);
        $filename = basename($body['filename'] ?? '');
        if ($filename === '') respond(400, ['error' => 'Filename required']);

        $path = realpath($imagesDir . $filename);
        $base = realpath($imagesDir);
        if ($path === false || $base === false || strpos($path, $base . DIRECTORY_SEPARATOR) !== 0) {
            respond(400, ['error' => 'Invalid filename']);
        }
        if (!file_exists($path)) respond(404, ['error' => 'File not found']);
        if (!unlink($path)) respond(500, ['error' => 'Failed to delete file']);
        respond(200, ['success' => true]);

    default:
        respond(404, ['error' => 'Unknown action']);
}
