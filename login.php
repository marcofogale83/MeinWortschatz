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
    <title>vokabiq - Login</title>
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Nunito:wght@800&text=vokabiq&display=swap">
    <link rel="icon" type="image/png" sizes="32x32" href="data:image/png;base64,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">
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
        h1.wordmark { font-size: 2rem; }
        .wordmark { font-family: "Nunito", "Arial Rounded MT Bold", system-ui, sans-serif; font-weight: 800; letter-spacing: -0.02em; color: #d3d7de; }
        .wordmark span { color: #ef2b6f; }
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
        /* Doodle background with small brains */
        body { background-image: url("data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 width=%22280%22 height=%22280%22 viewBox=%220 0 280 280%22%3E%3Cg fill=%22none%22 stroke=%22rgba(255,255,255,0.11)%22 stroke-width=%222.2%22 stroke-linecap=%22round%22 stroke-linejoin=%22round%22%3E%3Cg transform=%22translate(12 10) rotate(-15 20 20) scale(1.0)%22%3E%3Cpath d=%22M20 6C15 4 10 6 9 10C5 11 4 16 6 19C3 22 4 28 8 29C9 33 14 35 18 33L20 32M20 6C25 4 30 6 31 10C35 11 36 16 34 19C37 22 36 28 32 29C31 33 26 35 22 33L20 32M20 6V32M13 12C16 13 16 17 13 18M27 12C24 13 24 17 27 18M11 24C14 23 16 25 15 28M29 24C26 23 24 25 25 28%22/%3E%3C/g%3E%3Cg transform=%22translate(130 4) rotate(20 20 20) scale(0.8)%22%3E%3Cpath d=%22M20 6C15 4 10 6 9 10C5 11 4 16 6 19C3 22 4 28 8 29C9 33 14 35 18 33L20 32M20 6C25 4 30 6 31 10C35 11 36 16 34 19C37 22 36 28 32 29C31 33 26 35 22 33L20 32M20 6V32M13 12C16 13 16 17 13 18M27 12C24 13 24 17 27 18M11 24C14 23 16 25 15 28M29 24C26 23 24 25 25 28%22/%3E%3C/g%3E%3Cg transform=%22translate(214 60) rotate(-8 20 20) scale(1.05)%22%3E%3Cpath d=%22M20 6C15 4 10 6 9 10C5 11 4 16 6 19C3 22 4 28 8 29C9 33 14 35 18 33L20 32M20 6C25 4 30 6 31 10C35 11 36 16 34 19C37 22 36 28 32 29C31 33 26 35 22 33L20 32M20 6V32M13 12C16 13 16 17 13 18M27 12C24 13 24 17 27 18M11 24C14 23 16 25 15 28M29 24C26 23 24 25 25 28%22/%3E%3C/g%3E%3Cg transform=%22translate(70 84) rotate(30 20 20) scale(0.85)%22%3E%3Cpath d=%22M20 6C15 4 10 6 9 10C5 11 4 16 6 19C3 22 4 28 8 29C9 33 14 35 18 33L20 32M20 6C25 4 30 6 31 10C35 11 36 16 34 19C37 22 36 28 32 29C31 33 26 35 22 33L20 32M20 6V32M13 12C16 13 16 17 13 18M27 12C24 13 24 17 27 18M11 24C14 23 16 25 15 28M29 24C26 23 24 25 25 28%22/%3E%3C/g%3E%3Cg transform=%22translate(168 140) rotate(-25 20 20) scale(0.95)%22%3E%3Cpath d=%22M20 6C15 4 10 6 9 10C5 11 4 16 6 19C3 22 4 28 8 29C9 33 14 35 18 33L20 32M20 6C25 4 30 6 31 10C35 11 36 16 34 19C37 22 36 28 32 29C31 33 26 35 22 33L20 32M20 6V32M13 12C16 13 16 17 13 18M27 12C24 13 24 17 27 18M11 24C14 23 16 25 15 28M29 24C26 23 24 25 25 28%22/%3E%3C/g%3E%3Cg transform=%22translate(6 166) rotate(12 20 20) scale(0.8)%22%3E%3Cpath d=%22M20 6C15 4 10 6 9 10C5 11 4 16 6 19C3 22 4 28 8 29C9 33 14 35 18 33L20 32M20 6C25 4 30 6 31 10C35 11 36 16 34 19C37 22 36 28 32 29C31 33 26 35 22 33L20 32M20 6V32M13 12C16 13 16 17 13 18M27 12C24 13 24 17 27 18M11 24C14 23 16 25 15 28M29 24C26 23 24 25 25 28%22/%3E%3C/g%3E%3Cg transform=%22translate(100 200) rotate(-5 20 20) scale(1.05)%22%3E%3Cpath d=%22M20 6C15 4 10 6 9 10C5 11 4 16 6 19C3 22 4 28 8 29C9 33 14 35 18 33L20 32M20 6C25 4 30 6 31 10C35 11 36 16 34 19C37 22 36 28 32 29C31 33 26 35 22 33L20 32M20 6V32M13 12C16 13 16 17 13 18M27 12C24 13 24 17 27 18M11 24C14 23 16 25 15 28M29 24C26 23 24 25 25 28%22/%3E%3C/g%3E%3Cg transform=%22translate(220 214) rotate(35 20 20) scale(0.75)%22%3E%3Cpath d=%22M20 6C15 4 10 6 9 10C5 11 4 16 6 19C3 22 4 28 8 29C9 33 14 35 18 33L20 32M20 6C25 4 30 6 31 10C35 11 36 16 34 19C37 22 36 28 32 29C31 33 26 35 22 33L20 32M20 6V32M13 12C16 13 16 17 13 18M27 12C24 13 24 17 27 18M11 24C14 23 16 25 15 28M29 24C26 23 24 25 25 28%22/%3E%3C/g%3E%3Cg transform=%22translate(42 246) rotate(-30 20 20) scale(0.7)%22%3E%3Cpath d=%22M20 6C15 4 10 6 9 10C5 11 4 16 6 19C3 22 4 28 8 29C9 33 14 35 18 33L20 32M20 6C25 4 30 6 31 10C35 11 36 16 34 19C37 22 36 28 32 29C31 33 26 35 22 33L20 32M20 6V32M13 12C16 13 16 17 13 18M27 12C24 13 24 17 27 18M11 24C14 23 16 25 15 28M29 24C26 23 24 25 25 28%22/%3E%3C/g%3E%3C/g%3E%3C/svg%3E"); }
    </style>
</head>
<body>

<div class="login-card">
    <img src="data:image/jpeg;base64,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" alt="vokabiq" style="display:block;width:96px;height:96px;margin:0 auto 12px;border-radius:22%;">
    <h1 class="wordmark">vokab<span>iq</span></h1>
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