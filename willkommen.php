<?php
// willkommen.php - first visit: the new user chooses plan and translation languages.
// index.php sends users here while users.translation_langs is still empty.
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

$uid = (int)($_SESSION['user_id'] ?? 0);
if (empty($_SESSION['logged_in']) || $uid <= 0) {
    header('Location: login');
    exit;
}

require_once 'db.php';
require_once 'theme.php';
require_once 'legal.php';
require_once 'user_settings.php';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function e(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

$error = '';
$plan = 'free';
$langs = getTranslationLanguages($pdo, $uid);

// Already set up (or opened again later): nothing to do here
if ($langs !== null && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ./');
    exit;
}
$langs = $langs ?? ['en'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    $plan = (string)($_POST['plan'] ?? '');
    $langs = cleanLanguageList($_POST['langs'] ?? []);

    if (!is_string($csrfToken) || !hash_equals($_SESSION['csrf_token'], $csrfToken)) {
        $error = 'Die Anfrage ist abgelaufen. Bitte laden Sie die Seite neu und versuchen Sie es erneut.';
    } elseif (!isset(PLAN_OPTIONS[$plan])) {
        $error = 'Bitte wählen Sie ein Abo aus.';
        $plan = 'free';
    } elseif (!$langs) {
        $error = 'Bitte wählen Sie mindestens eine Sprache.';
    } else {
        try {
            // Later the payment step for paid plans goes here, before saving
            $pdo->prepare('UPDATE users SET subscription = ? WHERE id = ?')->execute([$plan, $uid]);
            saveTranslationLanguages($pdo, $uid, $langs);
            header('Location: ./');
            exit;
        } catch (PDOException $e) {
            error_log($e->getMessage());
            $error = 'Die Einstellungen konnten nicht gespeichert werden. Bitte versuchen Sie es später erneut.';
        }
    }
}

$name = (string)(($_SESSION['display_name'] ?? '') ?: ($_SESSION['username'] ?? ''));
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php theme_head(); ?>
    <title>Willkommen - vokabiq</title>
    <style>
        :root {
            --bg: #121212; --surface: #1e1e1e; --text: #e0e0e0; --muted: #a0a0a0; --border: #383838;
            --primary: #3f7fd6; --primary-hover: #3169b8; --accent: #90caf9; --row-border: #2c2c2c;
            --danger-bg: #3b1d1d; --danger-text: #ffb4ab;
        }
        :root[data-theme="light"] {
            --bg: #d5d9de; --surface: #e4e7eb; --text: #1f2933; --muted: #5f6b7a; --border: #c8ced5;
            --accent: #1565c0; --row-border: #c8ced5; --danger-bg: #fdecea; --danger-text: #b3261e;
        }
        * { box-sizing: border-box; }
        body { min-height: 100vh; margin: 0; padding: 24px 16px; background: var(--bg); color: var(--text); font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; }
        .container { max-width: 640px; margin: 0 auto; }
        header { display: flex; justify-content: space-between; align-items: center; gap: 12px; margin-bottom: 20px; padding: 16px 20px; background: var(--surface); border: 1px solid var(--border); border-radius: 8px; }
        h1 { margin: 0; color: var(--accent); font-size: 1.35rem; font-weight: 600; }
        .theme-switch { display: inline-flex; align-items: center; gap: 8px; }
        section { padding: 22px; background: var(--surface); border: 1px solid var(--border); border-radius: 8px; }
        .intro { margin: 0 0 22px; color: var(--muted); }
        h2 { margin: 0 0 6px; font-size: 1.1rem; }
        h2 + p { margin: 0 0 14px; color: var(--muted); font-size: 0.92rem; }
        .step { margin-bottom: 26px; }
        .submit-button { display: inline-flex; align-items: center; justify-content: center; width: 100%; min-height: 46px; padding: 10px 14px; border: 0; border-radius: 6px; background: var(--primary); color: #fff; font: inherit; font-weight: 600; cursor: pointer; }
        .submit-button:hover { background: var(--primary-hover); }
        .message { margin: 0 0 18px; padding: 12px 14px; border-radius: 6px; font-size: 0.92rem; }
        .error { background: var(--danger-bg); color: var(--danger-text); }
        .plan-form, .lang-grid { margin-bottom: 0; }
    </style>
    <?php settingsStyles(); ?>
</head>
<body>
<div class="container">
    <header>
        <h1>Willkommen bei vokabiq</h1>
        <span class="theme-switch"><?php theme_toggle(); ?></span>
    </header>

    <section>
        <p class="intro">
            <?= $name !== '' ? 'Hallo ' . e($name) . '! ' : '' ?>Zwei kurze Einstellungen, dann kann es losgehen.
            Sie können beides später jederzeit in Ihrem Profil ändern.
        </p>

        <?php if ($error !== ''): ?>
            <p class="message error" role="alert"><?= e($error) ?></p>
        <?php endif; ?>

        <form method="post" action="willkommen">
            <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">

            <div class="step">
                <h2>1. Ihr Abo</h2>
                <p>Wählen Sie, welche Funktionen Sie nutzen möchten.</p>
                <div class="plan-form"><?php renderPlanOptions($plan, false); ?></div>
            </div>

            <div class="step">
                <h2>2. Übersetzungssprachen</h2>
                <p>Wählen Sie bis zu <?= MAX_TRANSLATION_LANGUAGES ?> Sprachen, in die neue Wörter übersetzt werden.</p>
                <div class="lang-grid"><?php renderLanguageCheckboxes($langs); ?></div>
            </div>

            <button class="submit-button" type="submit">Los geht's</button>
        </form>
    </section>
    <?php legal_footer(); ?>
</div>
</body>
</html>
