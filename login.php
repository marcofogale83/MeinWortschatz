<?php
session_start();
require_once 'db.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (!empty($username) && !empty($password)) {
        try {
            $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
            $stmt->execute([$username]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user) {
                $error = "Debug: Username '$username' not found in database.";
            } elseif (!password_verify($password, $user['password'])) {
                $error = "Debug: Password verification failed for '$username'. Hash in DB might be incorrect.";
            } else {
                session_regenerate_id(true);
                $_SESSION['logged_in'] = true;
                $_SESSION['username'] = $user['username'];
                header('Location: index.php');
                exit;
            }
        } catch (PDOException $e) {
            $error = 'Database error: ' . $e->getMessage();
        }
    } else {
        $error = 'Please fill in all fields.';
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
        :root {
            --primary: #2563eb;
            --primary-dark: #1d4ed8;
            --bg-color: #f1f5f9;
            --card-bg: #ffffff;
            --text-main: #1e293b;
            --border-color: #cbd5e1;
            --danger: #ef4444;
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
            box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1);
        }
        h1 { font-size: 1.4rem; margin-top: 0; text-align: center; }
        label { display: block; font-weight: 600; font-size: 0.85rem; margin-bottom: 0.3rem; }
        input {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            margin-bottom: 1rem;
            font-size: 1rem;
        }
        input:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.15); }
        .btn {
            background-color: var(--primary);
            color: white;
            border: none;
            padding: 10px;
            border-radius: 6px;
            font-weight: 600;
            width: `100%`;
            cursor: pointer;
            font-size: 1rem;
        }
        .btn:hover { background-color: var(--primary-dark); }
        .error { background: #fee2e2; color: var(--danger); padding: 10px; border-radius: 6px; margin-bottom: 1rem; font-size: 0.85rem; text-align: center; border: 1px solid #fca5a5; }
    </style>
</head>
<body>

<div class="login-card">
    <h1>🔒 MeineWortschatz Login</h1>
    <?php if (!empty($error)): ?>
        <div class="error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <form action="login.php" method="POST">
        <label for="username">Username</label>
        <input type="text" id="username" name="username" required autocomplete="username" autofocus>

        <label for="password">Password</label>
        <input type="password" id="password" name="password" required autocomplete="current-password">

        <button type="submit" class="btn">Login</button>
    </form>
    <a href="google_login.php" class="btn" style="display:block;text-align:center;text-decoration:none;margin-top:10px;background:#fff;color:#1e293b;border:1px solid var(--border-color);">
        Mit Google anmelden
    </a>
</div>

</body>
</html>