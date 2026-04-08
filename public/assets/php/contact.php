<?php
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

// Only allow POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
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
    'secret'   => '6LdRnncaAAAAAAehSt5CfJ8DxPDDqusdNFtmudDq',
    'response' => $token,
]));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$recaptcha  = json_decode(curl_exec($ch), true);
curl_close($ch);

if (!$recaptcha['success'] || $recaptcha['action'] !== $action || $recaptcha['score'] < 0.5) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'reCAPTCHA verification failed. Please try again.']);
    exit;
}

// Sanitise inputs
$name    = trim(filter_input(INPUT_POST, 'name',    FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?? '');
$email   = trim(filter_input(INPUT_POST, 'email',   FILTER_SANITIZE_EMAIL)               ?? '');
$phone   = trim(filter_input(INPUT_POST, 'phone',   FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?? '');
$message = trim(filter_input(INPUT_POST, 'message', FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?? '');

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

// Strip newlines to prevent email header injection via Reply-To
$email = str_replace(["\r", "\n"], '', $email);

// Simple spam check
if (preg_match('/\b(http|https|www\.)\b/i', $message)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Message rejected.']);
    exit;
}

// Compose email
$to      = 'support@smtp.centralcomms.nz';
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
    "From: noreply@centralcomms.nz",
    "Reply-To: {$email}",
    "X-Mailer: PHP/" . PHP_VERSION,
]);

if (mail($to, $subject, $body, $headers)) {
    echo json_encode(['success' => true, 'message' => "Thanks {$name}! We'll be in touch shortly."]);
} else {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Sorry, there was an error sending your message. Please call us on 0800 848 0038.']);
}
