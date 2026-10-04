<?php
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'secure'   => !empty($_SERVER['HTTPS']),
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

require_once 'db.php'; // gives $pdo and $config
$g = $config['google'];

function fail(string $logMsg): void {
    error_log('Google login: ' . $logMsg);
    header('Location: login.php?error=google');
    exit;
}

// 1. Check state (CSRF protection)
$expected = $_SESSION['oauth_state'] ?? '';
unset($_SESSION['oauth_state']);
if ($expected === '' || !hash_equals($expected, $_GET['state'] ?? '')) {
    fail('invalid state');
}
if (empty($_GET['code'])) {
    fail('no code (user cancelled?)');
}

// 2. Exchange the code for tokens (server-to-server)
$ch = curl_init('https://oauth2.googleapis.com/token');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_TIMEOUT        => 15,
    CURLOPT_POSTFIELDS     => http_build_query([
        'code'          => $_GET['code'],
        'client_id'     => $g['client_id'],
        'client_secret' => $g['client_secret'],
        'redirect_uri'  => $g['redirect_uri'],
        'grant_type'    => 'authorization_code',
    ]),
]);
$response = curl_exec($ch);
curl_close($ch);

$token = json_decode((string)$response, true);
if (empty($token['id_token'])) {
    fail('token exchange failed: ' . $response);
}

// 3. Read the ID token. It came straight from Google over HTTPS,
//    so decoding the payload is sufficient; we still check the claims.
$parts = explode('.', $token['id_token']);
$claims = json_decode(base64_decode(strtr($parts[1] ?? '', '-_', '+/')), true);

if (!is_array($claims)
    || ($claims['aud'] ?? '') !== $g['client_id']
    || !in_array($claims['iss'] ?? '', ['https://accounts.google.com', 'accounts.google.com'], true)
    || ($claims['exp'] ?? 0) < time()
    || empty($claims['email_verified'])
) {
    fail('invalid id_token claims');
}

$sub   = $claims['sub'];
$email = strtolower($claims['email']);
$name  = $claims['name'] ?? $email;

// 4. Optional allowlist (empty array = anyone may sign up)
$allowed = array_map('strtolower', $g['allowed_emails'] ?? []);
if ($allowed && !in_array($email, $allowed, true)) {
    fail("email not allowed: $email");
}

// 5. Find or create the user
try {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE google_sub = ?");
    $stmt->execute([$sub]);
    $user = $stmt->fetch();

    if (!$user) {
        // Existing account with same email? Link it.
        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user) {
            $pdo->prepare("UPDATE users SET google_sub = ? WHERE id = ?")
                ->execute([$sub, $user['id']]);
        } else {
            // New profile
            $pdo->prepare("INSERT INTO users (username, email, google_sub, display_name, password)
                           VALUES (?, ?, ?, ?, NULL)")
                ->execute([$email, $email, $sub, $name]);
            $user = [
                'id'       => $pdo->lastInsertId(),
                'username' => $email,
            ];
        }
    }
} catch (PDOException $e) {
    fail('db error: ' . $e->getMessage());
}

// 6. Log in (same session keys as login.php)
session_regenerate_id(true);
$_SESSION['logged_in']    = true;
$_SESSION['user_id']      = $user['id'];
$_SESSION['username']     = $user['username'];
$_SESSION['display_name'] = $name;

header('Location: index.php');
exit;
