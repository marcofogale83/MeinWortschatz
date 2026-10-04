<?php
ini_set('display_errors', 0);
error_reporting(E_ALL);

session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => !empty($_SERVER['HTTPS']),
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || empty($_SESSION['username'])) {
    header('Location: login.php');
    exit;
}

require_once 'db.php';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$username = (string)$_SESSION['username'];
$error = '';
$success = '';

try {
    $stmt = $pdo->prepare('SELECT username, password FROM users WHERE username = ? LIMIT 1');
    $stmt->execute([$username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        session_unset();
        session_destroy();
        header('Location: login.php');
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $csrfToken = $_POST['csrf_token'] ?? '';
        $currentPassword = $_POST['current_password'] ?? '';
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        if (!is_string($csrfToken) || !hash_equals($_SESSION['csrf_token'], $csrfToken)) {
            $error = 'Die Anfrage ist abgelaufen. Bitte laden Sie die Seite neu und versuchen Sie es erneut.';
        } elseif (!is_string($currentPassword) || !password_verify($currentPassword, $user['password'])) {
            $error = 'Das aktuelle Passwort ist nicht korrekt.';
        } elseif (!is_string($newPassword) || strlen($newPassword) < 12) {
            $error = 'Das neue Passwort muss mindestens 12 Zeichen lang sein.';
        } elseif (strlen($newPassword) > 72) {
            $error = 'Das neue Passwort darf höchstens 72 Bytes lang sein.';
        } elseif ($newPassword !== $confirmPassword) {
            $error = 'Die neuen Passwörter stimmen nicht überein.';
        } elseif (password_verify($newPassword, $user['password'])) {
            $error = 'Das neue Passwort muss sich vom aktuellen Passwort unterscheiden.';
        } else {
            $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
            $update = $pdo->prepare('UPDATE users SET password = ? WHERE username = ?');
            $update->execute([$newHash, $username]);
            session_regenerate_id(true);
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            $success = 'Ihr Passwort wurde geändert.';
        }
    }
} catch (PDOException $e) {
    error_log($e->getMessage());
    $error = 'Das Profil konnte momentan nicht geladen werden. Bitte versuchen Sie es später erneut.';
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profil - Mein Wortschatz</title>
    <style>
        :root {
            --bg: #121212;
            --surface: #1e1e1e;
            --surface-raised: #252525;
            --text: #e0e0e0;
            --muted: #a0a0a0;
            --border: #383838;
            --primary: #1e88e5;
            --primary-hover: #1565c0;
            --danger-bg: #3b1d1d;
            --danger-text: #ffb4ab;
            --success-bg: #15352f;
            --success-text: #a8e6cf;
        }
        * { box-sizing: border-box; }
        body {
            min-height: 100vh;
            margin: 0;
            padding: 24px 16px;
            background: var(--bg);
            color: var(--text);
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }
        .container { max-width: 760px; margin: 0 auto; }
        header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            flex-wrap: wrap;
            margin-bottom: 24px;
            padding: 16px 20px;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 8px;
        }
        h1 { margin: 0; color: #90caf9; font-size: 1.35rem; font-weight: 600; }
        .back-link, .submit-button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 42px;
            padding: 9px 14px;
            border: 0;
            border-radius: 6px;
            color: #fff;
            font: inherit;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
        }
        .back-link { background: #414141; }
        .back-link:hover { background: #505050; }
        .profile-section {
            padding: 22px;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 8px;
        }
        h2 { margin: 0 0 6px; font-size: 1.1rem; }
        .section-description { margin: 0 0 22px; color: var(--muted); font-size: 0.92rem; }
        .account-name {
            display: flex;
            justify-content: space-between;
            gap: 16px;
            padding: 14px 0 20px;
            border-bottom: 1px solid var(--border);
            margin-bottom: 20px;
        }
        .account-name span:first-child { color: var(--muted); }
        .account-name strong { overflow-wrap: anywhere; }
        label { display: block; margin-bottom: 7px; font-size: 0.9rem; font-weight: 600; }
        input {
            width: 100%;
            min-height: 44px;
            margin-bottom: 16px;
            padding: 10px 12px;
            border: 1px solid #555;
            border-radius: 6px;
            background: var(--surface-raised);
            color: var(--text);
            font: inherit;
        }
        input:focus { outline: 2px solid var(--primary); outline-offset: 1px; }
        .password-note { margin: -6px 0 18px; color: var(--muted); font-size: 0.82rem; }
        .submit-button { background: var(--primary); }
        .submit-button:hover { background: var(--primary-hover); }
        .message { margin: 0 0 18px; padding: 12px 14px; border-radius: 6px; font-size: 0.92rem; }
        .error { background: var(--danger-bg); color: var(--danger-text); }
        .success { background: var(--success-bg); color: var(--success-text); }
        @media (max-width: 520px) {
            header { align-items: flex-start; }
            .back-link { width: 100%; }
            .profile-section { padding: 18px 16px; }
            .submit-button { width: 100%; }
        }
    </style>
</head>
<body>
<main class="container">
    <header>
        <h1>👤 Mein Profil</h1>
        <a class="back-link" href="index.php">Zurück zum Wortschatz</a>
    </header>

    <section class="profile-section" aria-labelledby="profile-heading">
        <h2 id="profile-heading">Kontodaten</h2>
        <p class="section-description">Verwalten Sie Ihr Konto und ändern Sie Ihr Passwort.</p>

        <?php if ($error !== ''): ?>
            <p class="message error" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
        <?php endif; ?>
        <?php if ($success !== ''): ?>
            <p class="message success" role="status"><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></p>
        <?php endif; ?>

        <div class="account-name">
            <span>Benutzername</span>
            <strong><?= htmlspecialchars($username, ENT_QUOTES, 'UTF-8') ?></strong>
        </div>

        <h2>Passwort ändern</h2>
        <form method="post" action="profile.php" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">

            <label for="current_password">Aktuelles Passwort</label>
            <input type="password" id="current_password" name="current_password" required autocomplete="current-password">

            <label for="new_password">Neues Passwort</label>
            <input type="password" id="new_password" name="new_password" required minlength="12" maxlength="72" autocomplete="new-password">
            <p class="password-note">Mindestens 12 Zeichen, höchstens 72 Bytes.</p>

            <label for="confirm_password">Neues Passwort bestätigen</label>
            <input type="password" id="confirm_password" name="confirm_password" required minlength="12" maxlength="72" autocomplete="new-password">

            <button class="submit-button" type="submit">Passwort speichern</button>
        </form>
    </section>
</main>
</body>
</html>