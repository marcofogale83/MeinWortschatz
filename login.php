<?php
// Same cookie settings as index.php and google_callback.php, BEFORE session_start
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'secure'   => !empty($_SERVER['HTTPS']),
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

// Already logged in? Go straight to the app
if (!empty($_SESSION['logged_in']) && !empty($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

require_once 'db.php';

$error = '';

// Error coming back from google_callback.php
if (($_GET['error'] ?? '') === 'google') {
    $error = 'Google-Anmeldung fehlgeschlagen. Bitte erneut versuchen.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username !== '' && $password !== '') {
        try {
            $stmt = $pdo->prepare("SELECT id, username, display_name, password FROM users WHERE username = ?");
            $stmt->execute([$username]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            // Google-only accounts have no password (NULL) and fail here too
            if ($user && !empty($user['password']) && password_verify($password, $user['password'])) {
                session_regenerate_id(true);
                $_SESSION['logged_in']    = true;
                $_SESSION['user_id']      = (int)$user['id'];
                $_SESSION['username']     = $user['username'];
                $_SESSION['display_name'] = $user['display_name'] ?: $user['username'];
                header('Location: index.php');
                exit;
            }

            // One generic message: don't reveal whether the username exists
            $error = 'Benutzername oder Passwort ist falsch.';
        } catch (PDOException $e) {
            error_log('Login DB error: ' . $e->getMessage());
            $error = 'Ein Fehler ist aufgetreten. Bitte später erneut versuchen.';
        }
    } else {
        $error = 'Bitte alle Felder ausfüllen.';
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MeineWortschatz - Login</title>
    <style>
        /* Login page is always dark */
        :root {
            color-scheme: dark;
            --primary: #1e88e5;
            --primary-dark: #1565c0;
            --bg-color: #121212;
            --card-bg: #1e1e1e;
            --input-bg: #252525;
            --text-main: #e0e0e0;
            --text-muted: #a0a0a0;
            --border-color: #383838;
            --input-border: #555555;
            --danger-bg: #3b1d1d;
            --danger-border: #5c2b2b;
            --danger-text: #ffb4ab;
        }
        * { box-sizing: border-box; }
        body {
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background-color: var(--bg-color);
            color: var(--text-main);
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            margin: 0;
            padding: 16px;
        }
        .login-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 30px;
            width: 100%;
            max-width: 400px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.5);
        }
        h1 { font-size: 1.4rem; margin-top: 0; text-align: center; color: #90caf9; }
        label { display: block; font-weight: 600; font-size: 0.85rem; margin-bottom: 0.3rem; }
        input {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid var(--input-border);
            border-radius: 6px;
            margin-bottom: 1rem;
            font-size: 1rem;
            background: var(--input-bg);
            color: var(--text-main);
        }
        input:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(30, 136, 229, 0.25); }
        .btn {
            display: block;
            background-color: var(--primary);
            color: white;
            border: none;
            padding: 10px;
            border-radius: 6px;
            font-weight: 600;
            width: 100%;
            cursor: pointer;
            font-size: 1rem;
            text-align: center;
            text-decoration: none;
        }
        .btn:hover { background-color: var(--primary-dark); }
        .btn-google {
            background: var(--input-bg);
            color: var(--text-main);
            border: 1px solid var(--input-border);
        }
        .btn-google:hover { background: #303030; }
        .divider { display: flex; align-items: center; gap: 10px; color: var(--text-muted); font-size: 0.8rem; margin: 16px 0 8px; }
        .divider::before, .divider::after { content: ""; flex: 1; border-top: 1px solid var(--input-border); }
        .error { background: var(--danger-bg); color: var(--danger-text); padding: 10px; border-radius: 6px; margin-bottom: 1rem; font-size: 0.85rem; text-align: center; border: 1px solid var(--danger-border); }
    </style>
</head>
<body>

<div class="login-card">
    <h1>🔒 MeineWortschatz Login</h1>
    <?php if ($error !== ''): ?>
        <div class="error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <a href="google_login.php" class="btn btn-google">Mit Google anmelden</a>

    <div class="divider">oder</div>

    <form action="login.php" method="POST">
        <label for="username">Benutzername</label>
        <input type="text" id="username" name="username" required autocomplete="username"
               value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">

        <label for="password">Passwort</label>
        <input type="password" id="password" name="password" required autocomplete="current-password">

        <button type="submit" class="btn">Anmelden</button>
    </form>

</div>

</body>
</html>