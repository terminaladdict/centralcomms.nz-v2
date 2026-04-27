<?php
ob_start();
ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/security.php';
security_start_session();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('X-Content-Type-Options: nosniff');

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

function enforce_post_security(string $action): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') return;
    if (!security_origin_allowed()) {
        respond(403, ['error' => 'Cross-origin request blocked']);
    }
    if (!in_array($action, ['login'], true) && !security_validate_csrf('updates')) {
        respond(403, ['error' => 'Invalid CSRF token']);
    }
}

function lock_path(string $file): string {
    return $file . '.lock';
}

function read_data_unlocked(string $file): array {
    if (!file_exists($file)) return ['updates' => []];
    $data = json_decode(file_get_contents($file), true);
    return is_array($data) ? $data : ['updates' => []];
}

function read_data(string $file): array {
    $lock = fopen(lock_path($file), 'c');
    if (!$lock) return read_data_unlocked($file);
    flock($lock, LOCK_SH);
    try {
        return read_data_unlocked($file);
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function write_data_unlocked(string $file, array $data): bool {
    $tmp = $file . '.tmp.' . getmypid();
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (file_put_contents($tmp, $json, LOCK_EX) === false) return false;
    return rename($tmp, $file);
}

function update_data(string $file, callable $mutator): array {
    $lock = fopen(lock_path($file), 'c');
    if (!$lock) return ['code' => 500, 'body' => ['error' => 'Failed to acquire data lock']];

    flock($lock, LOCK_EX);
    try {
        $data = read_data_unlocked($file);
        $result = $mutator($data);
        if (($result['write'] ?? false) && !write_data_unlocked($file, $data)) {
            return ['code' => 500, 'body' => ['error' => 'Failed to save']];
        }
        return $result;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
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
    $title = trim($body['title'] ?? '');
    $date = trim($body['date'] ?? '');
    $author = trim($body['author'] ?? '');
    $excerpt = trim($body['excerpt'] ?? '');
    $content = $body['content_html'] ?? '';

    if ($title === '') $errors[] = 'Title is required';
    if (mb_strlen($title, 'UTF-8') > 160) $errors[] = 'Title is too long';
    if ($date === '') $errors[] = 'Date is required';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $errors[] = 'Date must be YYYY-MM-DD';
    if (mb_strlen($author, 'UTF-8') > 120) $errors[] = 'Author is too long';
    if (mb_strlen($excerpt, 'UTF-8') > 600) $errors[] = 'Excerpt is too long';
    if (mb_strlen((string)$content, 'UTF-8') > 50000) $errors[] = 'Content is too long';
    return $errors;
}

function author_or_fallback(array $body, string $fallback): string {
    $author = security_trim_string($body['author'] ?? '', 120);
    return $author !== '' ? $author : $fallback;
}

function sanitize_image_filename(mixed $filename): ?string {
    if ($filename === null || $filename === '') return null;
    $filename = basename((string)$filename);
    if (!preg_match('/^[A-Za-z0-9_-]+\.(jpe?g|png|gif|webp)$/i', $filename)) return null;
    return $filename;
}

function update_categories_from_request(array $body): array {
    return array_values(array_slice(array_filter(array_map(
        fn($category) => security_trim_string($category, 40),
        (array)($body['categories'] ?? [])
    )), 0, 8));
}

function is_safe_url(string $url, bool $allowRelative = true): bool {
    if ($url === '') return false;
    if ($allowRelative && str_starts_with($url, '/')) return !str_starts_with($url, '//');
    $parts = parse_url($url);
    $scheme = strtolower($parts['scheme'] ?? '');
    return in_array($scheme, ['http', 'https', 'mailto', 'tel'], true);
}

function is_safe_youtube_url(string $url): bool {
    $parts = parse_url($url);
    if (($parts['scheme'] ?? '') !== 'https') return false;
    $host = strtolower($parts['host'] ?? '');
    return in_array($host, ['www.youtube.com', 'youtube.com', 'www.youtube-nocookie.com', 'youtube-nocookie.com'], true)
        && str_starts_with($parts['path'] ?? '', '/embed/');
}

function sanitize_update_html(string $html): string {
    if (trim($html) === '') return '';

    $allowedTags = [
        'p', 'br', 'strong', 'b', 'em', 'i', 's', 'ul', 'ol', 'li',
        'blockquote', 'h2', 'h3', 'hr', 'a', 'img', 'figure', 'figcaption',
        'div', 'iframe',
    ];
    $allowedAttrs = [
        'a' => ['href', 'title', 'target', 'rel'],
        'img' => ['src', 'alt', 'title', 'width', 'height'],
        'iframe' => ['src', 'width', 'height', 'allow', 'allowfullscreen', 'title'],
        'div' => ['data-youtube-video'],
    ];

    $doc = new DOMDocument('1.0', 'UTF-8');
    libxml_use_internal_errors(true);
    $doc->loadHTML(
        '<!DOCTYPE html><html><body><div id="content-root">' . $html . '</div></body></html>',
        LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
    );
    libxml_clear_errors();

    $sanitizeNode = function (DOMNode $node) use (&$sanitizeNode, $allowedTags, $allowedAttrs): void {
        if ($node instanceof DOMElement) {
            $tag = strtolower($node->tagName);
            if (in_array($tag, ['script', 'style', 'template', 'object', 'embed'], true)) {
                $node->parentNode?->removeChild($node);
                return;
            }
            if (!in_array($tag, $allowedTags, true)) {
                $parent = $node->parentNode;
                if (!$parent) return;
                while ($node->firstChild) {
                    $parent->insertBefore($node->firstChild, $node);
                }
                $parent->removeChild($node);
                return;
            }

            $allowed = $allowedAttrs[$tag] ?? [];
            for ($i = $node->attributes->length - 1; $i >= 0; $i--) {
                $attr = $node->attributes->item($i);
                $name = strtolower($attr->name);
                $value = trim($attr->value);
                if (!in_array($name, $allowed, true) || str_starts_with($name, 'on') || str_starts_with(strtolower($value), 'javascript:')) {
                    $node->removeAttribute($attr->name);
                }
            }

            if ($tag === 'a') {
                $href = $node->getAttribute('href');
                if (!is_safe_url($href)) $node->removeAttribute('href');
                if ($node->getAttribute('target') === '_blank') $node->setAttribute('rel', 'noopener noreferrer');
            }
            if ($tag === 'img') {
                $src = $node->getAttribute('src');
                if (!is_safe_url($src)) $node->parentNode?->removeChild($node);
            }
            if ($tag === 'iframe') {
                $src = $node->getAttribute('src');
                if (!is_safe_youtube_url($src)) {
                    $node->parentNode?->removeChild($node);
                } else {
                    $node->setAttribute('allowfullscreen', '');
                }
            }
        } elseif ($node instanceof DOMComment) {
            $node->parentNode?->removeChild($node);
            return;
        }

        foreach (iterator_to_array($node->childNodes) as $child) {
            $sanitizeNode($child);
        }
    };

    $root = $doc->getElementById('content-root');
    if (!$root) return '';
    $sanitizeNode($root);

    $clean = '';
    foreach ($root->childNodes as $child) {
        $clean .= $doc->saveHTML($child);
    }
    return $clean;
}

// ── Router ───────────────────────────────────────────────────────────────────

$action = $_GET['action'] ?? 'list';
$method = $_SERVER['REQUEST_METHOD'];
enforce_post_security($action);

$body = [];
if ($method === 'POST' && strpos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false) {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
}

// ── Actions ──────────────────────────────────────────────────────────────────

switch ($action) {

    case 'me':
        if (!empty($_SESSION['updates_staff_user'])) {
            respond(200, ['logged_in' => true, 'user' => $_SESSION['updates_staff_user'], 'csrf' => security_csrf_token('updates')]);
        }
        respond(200, ['logged_in' => false, 'user' => null]);

    case 'login':
        if ($method !== 'POST') respond(405, ['error' => 'Method not allowed']);
        if (!security_rate_limit('updates-login-ip', security_client_ip(), 12, 15 * 60)) {
            respond(429, ['error' => 'Too many login attempts. Please try again later.']);
        }
        $username = security_trim_string($body['username'] ?? '', 80);
        $password = $body['password'] ?? '';
        if (!security_rate_limit('updates-login-user', mb_strtolower($username, 'UTF-8'), 8, 15 * 60)) {
            respond(429, ['error' => 'Too many login attempts. Please try again later.']);
        }
        if ($username === '' || $password === '') respond(400, ['error' => 'Username and password required']);
        $hash = $UPDATES_STAFF_USERS[$username] ?? '$2y$10$invalidhashthatalwaysfailsXXXXXXXXXXXXXX';
        if (!password_verify($password, $hash) || !isset($UPDATES_STAFF_USERS[$username])) {
            error_log('Updates login failed for user "' . $username . '" from ' . security_client_ip());
            respond(401, ['error' => 'Invalid credentials']);
        }
        session_regenerate_id(true);
        $_SESSION['updates_staff_user'] = $username;
        respond(200, ['logged_in' => true, 'user' => $username, 'csrf' => security_csrf_token('updates')]);

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

        $result = update_data($dataFile, function (&$data) use ($body) {
            $existingSlugs = array_column($data['updates'], 'slug');
            $baseSlug = slugify(security_trim_string($body['title'], 160));
            $slug = unique_slug($baseSlug, $existingSlugs);

            $update = [
                'slug'         => $slug,
                'title'        => security_trim_string($body['title'], 160),
                'date'         => security_trim_string($body['date'], 10),
                'author'       => author_or_fallback($body, current_user()),
                'image'        => sanitize_image_filename($body['image'] ?? null),
                'categories'   => update_categories_from_request($body),
                'excerpt'      => security_trim_string($body['excerpt'] ?? '', 600),
                'content_html' => sanitize_update_html($body['content_html'] ?? ''),
            ];

            array_unshift($data['updates'], $update);
            usort($data['updates'], fn($a, $b) => strcmp($b['date'], $a['date']));

            return ['code' => 201, 'body' => ['update' => $update], 'write' => true];
        });
        respond($result['code'], $result['body']);

    case 'update':
        require_auth();
        if ($method !== 'POST') respond(405, ['error' => 'Method not allowed']);
        $slug = $_GET['slug'] ?? '';
        if ($slug === '') respond(400, ['error' => 'Slug required']);
        $errors = validate_update_fields($body);
        if ($errors) respond(400, ['errors' => $errors]);

        $result = update_data($dataFile, function (&$data) use ($body, $slug) {
            $found = false;
            foreach ($data['updates'] as &$u) {
                if ($u['slug'] === $slug) {
                    $u['title']        = security_trim_string($body['title'], 160);
                    $u['date']         = security_trim_string($body['date'], 10);
                    $u['author']       = author_or_fallback($body, $u['author']);
                    $u['image']        = array_key_exists('image', $body) ? sanitize_image_filename($body['image']) : $u['image'];
                    $u['categories']   = update_categories_from_request($body);
                    $u['excerpt']      = security_trim_string($body['excerpt'] ?? '', 600);
                    $u['content_html'] = sanitize_update_html($body['content_html'] ?? $u['content_html']);
                    $updated = $u;
                    $found = true;
                    break;
                }
            }
            unset($u);
            if (!$found) return ['code' => 404, 'body' => ['error' => 'Update not found'], 'write' => false];
            usort($data['updates'], fn($a, $b) => strcmp($b['date'], $a['date']));
            return ['code' => 200, 'body' => ['update' => $updated], 'write' => true];
        });
        respond($result['code'], $result['body']);

    case 'delete':
        require_auth();
        if ($method !== 'POST') respond(405, ['error' => 'Method not allowed']);
        $slug = $_GET['slug'] ?? '';
        if ($slug === '') respond(400, ['error' => 'Slug required']);
        $result = update_data($dataFile, function (&$data) use ($slug) {
            $before = count($data['updates']);
            $data['updates'] = array_values(array_filter($data['updates'], fn($u) => $u['slug'] !== $slug));
            if (count($data['updates']) === $before) {
                return ['code' => 404, 'body' => ['error' => 'Update not found'], 'write' => false];
            }
            return ['code' => 200, 'body' => ['success' => true], 'write' => true];
        });
        respond($result['code'], $result['body']);

    case 'upload_image':
        require_auth();
        if ($method !== 'POST') respond(405, ['error' => 'Method not allowed']);
        if (!isset($_FILES['file'])) respond(400, ['error' => 'No file uploaded']);

        $file = $_FILES['file'];
        if ($file['error'] !== UPLOAD_ERR_OK) respond(400, ['error' => 'Upload error: ' . $file['error']]);
        if (!security_rate_limit('updates-upload-user', current_user(), 30, 60 * 60)) {
            respond(429, ['error' => 'Too many uploads. Please try again later.']);
        }
        if ($file['size'] > 5 * 1024 * 1024) respond(400, ['error' => 'File too large (max 5 MB)']);

        $mime = mime_content_type($file['tmp_name']);
        $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        if (!in_array($mime, $allowed, true)) respond(400, ['error' => 'Invalid file type. Allowed: jpg, png, gif, webp']);
        $imageInfo = getimagesize($file['tmp_name']);
        if ($imageInfo === false) respond(400, ['error' => 'Invalid image']);
        if (($imageInfo[0] ?? 0) > 6000 || ($imageInfo[1] ?? 0) > 6000) {
            respond(400, ['error' => 'Image dimensions are too large']);
        }

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
        if (!preg_match('/^[A-Za-z0-9_-]+\.(jpe?g|png|gif|webp)$/i', $filename)) respond(400, ['error' => 'Invalid filename']);

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
