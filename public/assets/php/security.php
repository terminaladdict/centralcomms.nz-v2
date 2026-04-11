<?php

function security_is_https(): bool {
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') return true;
    return strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
}

function security_start_session(): void {
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => security_is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function security_client_ip(): string {
    return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}

function security_rate_key(string $scope, string $id): string {
    return hash('sha256', $scope . '|' . $id);
}

function security_rate_limit(string $scope, string $id, int $limit, int $windowSeconds): bool {
    $now = time();
    $key = security_rate_key($scope, $id);
    $dir = sys_get_temp_dir() . '/centralcomms-rate-limits';
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    $file = $dir . '/' . $key . '.json';

    $handle = fopen($file, 'c+');
    if (!$handle) return true;

    flock($handle, LOCK_EX);
    $raw = stream_get_contents($handle);
    $bucket = json_decode($raw ?: '', true);
    if (!is_array($bucket)) $bucket = ['start' => $now, 'count' => 0];

    if (($now - ($bucket['start'] ?? 0)) >= $windowSeconds) {
        $bucket = ['start' => $now, 'count' => 0];
    }

    $bucket['count']++;
    ftruncate($handle, 0);
    rewind($handle);
    fwrite($handle, json_encode($bucket));
    fflush($handle);
    flock($handle, LOCK_UN);
    fclose($handle);

    return $bucket['count'] <= $limit;
}

function security_csrf_token(string $scope): string {
    $_SESSION['csrf_tokens'] ??= [];
    if (empty($_SESSION['csrf_tokens'][$scope])) {
        $_SESSION['csrf_tokens'][$scope] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_tokens'][$scope];
}

function security_validate_csrf(string $scope): bool {
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    $expected = $_SESSION['csrf_tokens'][$scope] ?? '';
    return $expected !== '' && is_string($token) && hash_equals($expected, $token);
}

function security_origin_allowed(): bool {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') return true;

    $host = $_SERVER['HTTP_HOST'] ?? '';
    $expectedHost = strtolower(preg_replace('/:\d+$/', '', $host));

    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin !== '') {
        $originHost = strtolower(parse_url($origin, PHP_URL_HOST) ?? '');
        return $originHost !== '' && hash_equals($expectedHost, $originHost);
    }

    $referer = $_SERVER['HTTP_REFERER'] ?? '';
    if ($referer !== '') {
        $refererHost = strtolower(parse_url($referer, PHP_URL_HOST) ?? '');
        return $refererHost !== '' && hash_equals($expectedHost, $refererHost);
    }

    return false;
}

function security_trim_string(mixed $value, int $maxLength): string {
    $value = trim((string)($value ?? ''));
    if (mb_strlen($value, 'UTF-8') > $maxLength) {
        $value = mb_substr($value, 0, $maxLength, 'UTF-8');
    }
    return $value;
}

function security_require_string_length(string $value, string $label, int $minLength, int $maxLength): ?string {
    $length = mb_strlen($value, 'UTF-8');
    if ($length < $minLength) return "{$label} is required";
    if ($length > $maxLength) return "{$label} is too long";
    return null;
}
