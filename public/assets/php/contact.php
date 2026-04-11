<?php
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/security.php';

$configFile = __DIR__ . '/contact-config.php';
if (!file_exists($configFile)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Contact form configuration is missing.']);
    exit;
}
require $configFile;

$CONTACT_RECAPTCHA_SECRET = trim($CONTACT_RECAPTCHA_SECRET ?? '');
$CONTACT_RECIPIENT = trim($CONTACT_RECIPIENT ?? '');
$CONTACT_FROM = trim($CONTACT_FROM ?? '');
if ($CONTACT_RECAPTCHA_SECRET === '' || !filter_var($CONTACT_RECIPIENT, FILTER_VALIDATE_EMAIL) || !filter_var($CONTACT_FROM, FILTER_VALIDATE_EMAIL)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Contact form configuration is invalid.']);
    exit;
}

// Only allow POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

session_name('contact_rate');
security_start_session();
if (!security_rate_limit('contact-ip', security_client_ip(), 6, 60 * 60)) {
    http_response_code(429);
    echo json_encode(['success' => false, 'message' => 'Too many messages. Please try again later or call us on 0800 848 0038.']);
    exit;
}

// reCAPTCHA v3 verification
$token  = $_POST['token']  ?? '';
$action = $_POST['action'] ?? '';

if (empty($token)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'reCAPTCHA token missing.']);
    exit;
}

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, 'https://www.google.com/recaptcha/api/siteverify');
curl_setopt($ch, CURLOPT_POST, 1);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
    'secret'   => $CONTACT_RECAPTCHA_SECRET,
    'response' => $token,
]));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$recaptchaResponse = curl_exec($ch);
curl_close($ch);

$recaptcha = json_decode($recaptchaResponse ?: '', true);
if (!is_array($recaptcha) || !($recaptcha['success'] ?? false) || ($recaptcha['action'] ?? '') !== $action || ($recaptcha['score'] ?? 0) < 0.5) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'reCAPTCHA verification failed. Please try again.']);
    exit;
}

// Sanitise inputs
$name    = security_trim_string(filter_input(INPUT_POST, 'name',    FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?? '', 120);
$email   = security_trim_string(filter_input(INPUT_POST, 'email',   FILTER_SANITIZE_EMAIL)               ?? '', 254);
$phone   = security_trim_string(filter_input(INPUT_POST, 'phone',   FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?? '', 80);
$message = security_trim_string(filter_input(INPUT_POST, 'message', FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?? '', 5000);

// Basic validation
if (empty($name) || empty($email) || empty($message)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Please fill in all required fields.']);
    exit;
}

if (mb_strlen($name) < 2) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Please enter your name (at least 2 characters).']);
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Please enter a valid email address.']);
    exit;
}

// Strip newlines to prevent email header injection.
$name = str_replace(["\r", "\n"], ' ', $name);
$phone = str_replace(["\r", "\n"], ' ', $phone);
$email = str_replace(["\r", "\n"], '', $email);

// Simple spam check
if (preg_match('/\b(http|https|www\.)\b/i', $message)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Message rejected.']);
    exit;
}

// Compose email
$to      = $CONTACT_RECIPIENT;
$subject = "Website Enquiry from {$name}";
$body    = implode("\n", [
    "Name:    {$name}",
    "Email:   {$email}",
    "Phone:   " . ($phone ?: 'Not provided'),
    "",
    "Message:",
    $message,
    "",
    "---",
    "Sent from centralcomms.nz contact form",
]);

$headers = implode("\r\n", [
    "From: {$CONTACT_FROM}",
    "Reply-To: {$email}",
    "X-Mailer: PHP/" . PHP_VERSION,
]);

if (mail($to, $subject, $body, $headers)) {
    echo json_encode(['success' => true, 'message' => "Thanks {$name}! We'll be in touch shortly."]);
} else {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Sorry, there was an error sending your message. Please call us on 0800 848 0038.']);
}
