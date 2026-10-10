<?php
// community.php - friends, requests, blocked users, stats comparison and chat.
// All data comes from community_api.php; this page only renders it.
ini_set('display_errors', 0);
error_reporting(E_ALL);

session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'secure'   => !empty($_SERVER['HTTPS']),
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

require_once 'theme.php';

$uid = (int)($_SESSION['user_id'] ?? 0);
if (empty($_SESSION['logged_in']) || $uid <= 0) {
    header('Location: login.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php theme_head(); ?>
    <title>Community - vokabiq</title>
    <link rel="icon" type="image/png" sizes="32x32" href="data:image/png;base64,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">
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
            --accent: #90caf9;
            --mine-bg: #173554;
            --danger-bg: #3b1d1d;
            --danger-text: #ffb4ab;
            --success-bg: #15352f;
            --success-text: #a8e6cf;
            --info-bg: #1a2a3a;
            --info-text: #90caf9;
            --neutral-btn: #414141;
            --neutral-btn-hover: #505050;
            --neutral-btn-text: #ffffff;
            --danger-hover: #4a2424;
            --input-border: #555555;
            --hover-border: #4a4a4a;
            --active-bg: #1b2a3a;
            --track: #2e2e2e;
            --row-border: #2c2c2c;
        }

        /* ===== Light theme ===== */
        :root[data-theme="light"] {
            --bg: #f4f6f8;
            --surface: #ffffff;
            --surface-raised: #f8f9fb;
            --text: #1f2933;
            --muted: #5f6b7a;
            --border: #dde2e8;
            --accent: #1565c0;
            --mine-bg: #dbeafe;
            --danger-bg: #fdecea;
            --danger-text: #b3261e;
            --success-bg: #e6f4f1;
            --success-text: #00695c;
            --info-bg: #e3f2fd;
            --info-text: #1565c0;
            --neutral-btn: #e3e7ec;
            --neutral-btn-hover: #d0d6dd;
            --neutral-btn-text: #1f2933;
            --danger-hover: #f9d6d2;
            --input-border: #c5ccd4;
            --hover-border: #b8c1cb;
            --active-bg: #e3f2fd;
            --track: #e3e7ec;
            --row-border: #eef1f4;
        }
        * { box-sizing: border-box; }
        [hidden] { display: none !important; }
        body {
            min-height: 100vh;
            margin: 0;
            padding: 24px 16px;
            background: var(--bg);
            color: var(--text);
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }
        .container { max-width: 1000px; margin: 0 auto; }
        header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            flex-wrap: wrap;
            margin-bottom: 16px;
            padding: 16px 20px;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 8px;
        }
        h1 { margin: 0; color: var(--accent); font-size: 1.35rem; font-weight: 600; }
        h2 { margin: 0 0 6px; font-size: 1.05rem; }
        h3 { margin: 0 0 12px; font-size: 0.98rem; color: var(--muted); font-weight: 600; }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 42px;
            padding: 9px 14px;
            border: 0;
            border-radius: 6px;
            background: var(--primary);
            color: #fff;
            font: inherit;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
            white-space: nowrap;
        }
        .btn:hover { background: var(--primary-hover); }
        .btn:disabled { opacity: 0.6; cursor: default; }
        .btn-secondary { background: var(--neutral-btn); color: var(--neutral-btn-text); }
        .btn-secondary:hover { background: var(--neutral-btn-hover); }
        .header-title { display: flex; align-items: center; gap: 10px; min-width: 0; }
        .back-icon {
            display: inline-grid; place-items: center; flex-shrink: 0;
            width: 40px; height: 40px; border-radius: 50%;
            color: var(--muted); text-decoration: none;
            transition: background-color 0.15s, color 0.15s;
        }
        .back-icon svg { width: 22px; height: 22px; }
        .back-icon:hover { background: var(--neutral-btn); color: var(--accent); }
        .btn-danger { background: var(--danger-bg); color: var(--danger-text); }
        .btn-danger:hover { background: var(--danger-hover); }
        .btn-small { min-height: 34px; padding: 6px 11px; font-size: 0.88rem; }
        :focus-visible { outline: 2px solid var(--accent); outline-offset: 2px; }
        .header-actions { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }

        .message { margin: 0 0 16px; padding: 12px 14px; border-radius: 6px; font-size: 0.92rem; }
        .message.error { background: var(--danger-bg); color: var(--danger-text); }
        .message.success { background: var(--success-bg); color: var(--success-text); }
        .message.info { background: var(--info-bg); color: var(--info-text); }

        .layout {
            display: grid;
            grid-template-columns: 320px 1fr;
            gap: 16px;
            align-items: start;
        }
        .sidebar, .detail-area { display: grid; gap: 16px; min-width: 0; }
        #detail { display: grid; gap: 16px; }
        .panel {
            padding: 18px;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 8px;
        }
        .hint, .muted { margin: 0; color: var(--muted); font-size: 0.88rem; }

        /* People search */
        #peopleSearch { margin: 10px 0 12px; }
        .people-list {
            display: grid;
            gap: 6px;
            max-height: 340px;
            overflow-y: auto;
        }
        .person {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            padding: 9px 12px;
            border-radius: 6px;
            background: var(--surface-raised);
        }
        .person .name { font-weight: 600; overflow-wrap: anywhere; }
        .person .state { color: var(--muted); font-size: 0.82rem; white-space: nowrap; }
        label { display: block; margin-bottom: 7px; font-size: 0.9rem; font-weight: 600; }
        input, textarea {
            width: 100%;
            min-width: 0;
            padding: 10px 12px;
            border: 1px solid var(--input-border);
            border-radius: 6px;
            background: var(--surface-raised);
            color: var(--text);
            font: inherit;
        }

        /* Tabs */
        .tabs { display: flex; gap: 4px; margin: -6px -6px 14px; border-bottom: 1px solid var(--border); }
        .tab {
            flex: 1;
            padding: 10px 6px;
            border: 0;
            border-bottom: 2px solid transparent;
            background: none;
            color: var(--muted);
            font: inherit;
            font-size: 0.9rem;
            font-weight: 600;
            cursor: pointer;
        }
        .tab[aria-selected="true"] { color: var(--text); border-bottom-color: var(--primary); }
        .badge {
            display: inline-block;
            min-width: 20px;
            margin-left: 4px;
            padding: 1px 6px;
            border-radius: 10px;
            background: var(--primary);
            color: #fff;
            font-size: 0.75rem;
            text-align: center;
        }
        .count { margin-left: 3px; color: var(--muted); font-weight: 400; }

        /* Lists */
        .list { display: grid; gap: 6px; }
        .list-heading { margin: 14px 0 6px; color: var(--muted); font-size: 0.85rem; font-weight: 600; }
        .list-heading:first-child { margin-top: 0; }
        .friend-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            width: 100%;
            padding: 11px 12px;
            border: 1px solid transparent;
            border-radius: 6px;
            background: var(--surface-raised);
            color: var(--text);
            font: inherit;
            text-align: left;
            cursor: pointer;
        }
        .friend-item:hover { border-color: var(--hover-border); }
        .friend-item.active { border-color: var(--primary); background: var(--active-bg); }
        .row-item {
            padding: 11px 12px;
            border-radius: 6px;
            background: var(--surface-raised);
        }
        .row-item .name { font-weight: 600; overflow-wrap: anywhere; }
        .row-item .meta { margin-top: 2px; color: var(--muted); font-size: 0.8rem; }
        .row-actions { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 10px; }
        .empty { margin: 4px 0; color: var(--muted); font-size: 0.9rem; line-height: 1.45; }

        /* Detail */
        .detail-head { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
        .detail-head h2 { margin: 0; font-size: 1.2rem; color: var(--accent); overflow-wrap: anywhere; }
        .detail-actions, .detail-nav { display: flex; gap: 6px; }
        #statsBtn[aria-expanded="true"] { background: var(--primary); }

        /* Ranking */
        .ranking { list-style: none; margin: 16px 0 0; padding: 0; display: grid; gap: 8px; }
        .rank-row {
            display: grid;
            grid-template-columns: 44px 1fr auto;
            align-items: center;
            gap: 4px 12px;
            width: 100%;
            padding: 12px 14px;
            border: 1px solid transparent;
            border-radius: 8px;
            background: var(--surface-raised);
            color: var(--text);
            font: inherit;
            text-align: left;
        }
        button.rank-row { cursor: pointer; }
        button.rank-row:hover { border-color: var(--hover-border); }
        .rank-row.me { border-color: var(--primary); background: var(--active-bg); }
        .rank-pos { grid-row: span 2; font-size: 1.4rem; font-weight: 700; color: var(--muted); text-align: center; }
        .rank-row.top .rank-pos { font-size: 1.7rem; }
        .rank-name { font-weight: 600; overflow-wrap: anywhere; }
        .rank-value { text-align: right; font-size: 1.15rem; font-weight: 700; color: var(--accent); font-variant-numeric: tabular-nums; }
        .rank-value small { display: block; color: var(--muted); font-size: 0.72rem; font-weight: 400; }
        .rank-bar { grid-column: 2 / 4; height: 6px; border-radius: 3px; background: var(--track); overflow: hidden; }
        .rank-bar span { display: block; height: 100%; border-radius: 3px; background: var(--primary); }
        .rank-row.me .rank-bar span { background: var(--success-text); }
        .ranking-note { margin-top: 14px; }

        /* Chats list */
        .chat-item {
            display: grid;
            grid-template-columns: 1fr auto;
            gap: 2px 10px;
            width: 100%;
            padding: 10px 12px;
            border: 1px solid transparent;
            border-radius: 6px;
            background: var(--surface-raised);
            color: var(--text);
            font: inherit;
            text-align: left;
            cursor: pointer;
        }
        .chat-item:hover { border-color: var(--hover-border); }
        .chat-item.active { border-color: var(--primary); background: var(--active-bg); }
        .chat-item .name { font-weight: 600; overflow-wrap: anywhere; }
        .chat-item .when { color: var(--muted); font-size: 0.78rem; white-space: nowrap; }
        .chat-item .preview {
            overflow: hidden;
            color: var(--muted);
            font-size: 0.86rem;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .chat-item.unread .preview { color: var(--text); font-weight: 600; }

        /* Stats */
        .stats-table { width: 100%; border-collapse: collapse; font-size: 0.93rem; }
        .stats-table th, .stats-table td { padding: 9px 6px; border-bottom: 1px solid var(--row-border); }
        .stats-table th { color: var(--muted); font-weight: 600; text-align: right; }
        .stats-table th:first-child, .stats-table td:first-child { text-align: left; }
        .stats-table td { text-align: right; font-variant-numeric: tabular-nums; }
        .stats-table td.lead { color: var(--success-text); font-weight: 700; }
        .stats-table tr.group-start td { border-top: 1px solid var(--border); }
        .last-active { margin-top: 12px; }

        /* Chat */
        .chat-log {
            display: flex;
            flex-direction: column;
            gap: 8px;
            height: 360px;
            overflow-y: auto;
            padding: 12px;
            background: var(--bg);
            border: 1px solid var(--border);
            border-radius: 6px;
        }
        .msg {
            max-width: 80%;
            align-self: flex-start;
            padding: 8px 11px;
            border-radius: 10px 10px 10px 3px;
            background: var(--surface-raised);
        }
        .msg.mine { align-self: flex-end; border-radius: 10px 10px 3px 10px; background: var(--mine-bg); }
        .msg-body { white-space: pre-wrap; overflow-wrap: anywhere; line-height: 1.4; }
        .msg-time { display: block; margin-top: 3px; color: var(--muted); font-size: 0.72rem; text-align: right; }
        .chat-empty { margin: auto; color: var(--muted); font-size: 0.9rem; }
        .chat-form { display: flex; gap: 8px; margin-top: 10px; align-items: flex-end; }
        .chat-form textarea { resize: vertical; min-height: 44px; max-height: 160px; }
        .visually-hidden {
            position: absolute; width: 1px; height: 1px; overflow: hidden;
            clip: rect(0 0 0 0); white-space: nowrap;
        }

        @media (max-width: 800px) {
            .layout { grid-template-columns: 1fr; }
            body.friend-open .sidebar { display: none; }
            .detail-area { order: -1; } /* ranking first on phones */
            .detail-head h2 { order: -1; width: 100%; }
            .chat-log { height: 50vh; }
        }
        @media (max-width: 420px) {
            .chat-form { flex-direction: column; align-items: stretch; }
        }
        /* Doodle background with small brains */
        body { background-image: url("data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 width=%22280%22 height=%22280%22 viewBox=%220 0 280 280%22%3E%3Cg fill=%22none%22 stroke=%22rgba(255,255,255,0.11)%22 stroke-width=%222.2%22 stroke-linecap=%22round%22 stroke-linejoin=%22round%22%3E%3Cg transform=%22translate(12 10) rotate(-15 20 20) scale(1.0)%22%3E%3Cpath d=%22M20 6C15 4 10 6 9 10C5 11 4 16 6 19C3 22 4 28 8 29C9 33 14 35 18 33L20 32M20 6C25 4 30 6 31 10C35 11 36 16 34 19C37 22 36 28 32 29C31 33 26 35 22 33L20 32M20 6V32M13 12C16 13 16 17 13 18M27 12C24 13 24 17 27 18M11 24C14 23 16 25 15 28M29 24C26 23 24 25 25 28%22/%3E%3C/g%3E%3Cg transform=%22translate(130 4) rotate(20 20 20) scale(0.8)%22%3E%3Cpath d=%22M20 6C15 4 10 6 9 10C5 11 4 16 6 19C3 22 4 28 8 29C9 33 14 35 18 33L20 32M20 6C25 4 30 6 31 10C35 11 36 16 34 19C37 22 36 28 32 29C31 33 26 35 22 33L20 32M20 6V32M13 12C16 13 16 17 13 18M27 12C24 13 24 17 27 18M11 24C14 23 16 25 15 28M29 24C26 23 24 25 25 28%22/%3E%3C/g%3E%3Cg transform=%22translate(214 60) rotate(-8 20 20) scale(1.05)%22%3E%3Cpath d=%22M20 6C15 4 10 6 9 10C5 11 4 16 6 19C3 22 4 28 8 29C9 33 14 35 18 33L20 32M20 6C25 4 30 6 31 10C35 11 36 16 34 19C37 22 36 28 32 29C31 33 26 35 22 33L20 32M20 6V32M13 12C16 13 16 17 13 18M27 12C24 13 24 17 27 18M11 24C14 23 16 25 15 28M29 24C26 23 24 25 25 28%22/%3E%3C/g%3E%3Cg transform=%22translate(70 84) rotate(30 20 20) scale(0.85)%22%3E%3Cpath d=%22M20 6C15 4 10 6 9 10C5 11 4 16 6 19C3 22 4 28 8 29C9 33 14 35 18 33L20 32M20 6C25 4 30 6 31 10C35 11 36 16 34 19C37 22 36 28 32 29C31 33 26 35 22 33L20 32M20 6V32M13 12C16 13 16 17 13 18M27 12C24 13 24 17 27 18M11 24C14 23 16 25 15 28M29 24C26 23 24 25 25 28%22/%3E%3C/g%3E%3Cg transform=%22translate(168 140) rotate(-25 20 20) scale(0.95)%22%3E%3Cpath d=%22M20 6C15 4 10 6 9 10C5 11 4 16 6 19C3 22 4 28 8 29C9 33 14 35 18 33L20 32M20 6C25 4 30 6 31 10C35 11 36 16 34 19C37 22 36 28 32 29C31 33 26 35 22 33L20 32M20 6V32M13 12C16 13 16 17 13 18M27 12C24 13 24 17 27 18M11 24C14 23 16 25 15 28M29 24C26 23 24 25 25 28%22/%3E%3C/g%3E%3Cg transform=%22translate(6 166) rotate(12 20 20) scale(0.8)%22%3E%3Cpath d=%22M20 6C15 4 10 6 9 10C5 11 4 16 6 19C3 22 4 28 8 29C9 33 14 35 18 33L20 32M20 6C25 4 30 6 31 10C35 11 36 16 34 19C37 22 36 28 32 29C31 33 26 35 22 33L20 32M20 6V32M13 12C16 13 16 17 13 18M27 12C24 13 24 17 27 18M11 24C14 23 16 25 15 28M29 24C26 23 24 25 25 28%22/%3E%3C/g%3E%3Cg transform=%22translate(100 200) rotate(-5 20 20) scale(1.05)%22%3E%3Cpath d=%22M20 6C15 4 10 6 9 10C5 11 4 16 6 19C3 22 4 28 8 29C9 33 14 35 18 33L20 32M20 6C25 4 30 6 31 10C35 11 36 16 34 19C37 22 36 28 32 29C31 33 26 35 22 33L20 32M20 6V32M13 12C16 13 16 17 13 18M27 12C24 13 24 17 27 18M11 24C14 23 16 25 15 28M29 24C26 23 24 25 25 28%22/%3E%3C/g%3E%3Cg transform=%22translate(220 214) rotate(35 20 20) scale(0.75)%22%3E%3Cpath d=%22M20 6C15 4 10 6 9 10C5 11 4 16 6 19C3 22 4 28 8 29C9 33 14 35 18 33L20 32M20 6C25 4 30 6 31 10C35 11 36 16 34 19C37 22 36 28 32 29C31 33 26 35 22 33L20 32M20 6V32M13 12C16 13 16 17 13 18M27 12C24 13 24 17 27 18M11 24C14 23 16 25 15 28M29 24C26 23 24 25 25 28%22/%3E%3C/g%3E%3Cg transform=%22translate(42 246) rotate(-30 20 20) scale(0.7)%22%3E%3Cpath d=%22M20 6C15 4 10 6 9 10C5 11 4 16 6 19C3 22 4 28 8 29C9 33 14 35 18 33L20 32M20 6C25 4 30 6 31 10C35 11 36 16 34 19C37 22 36 28 32 29C31 33 26 35 22 33L20 32M20 6V32M13 12C16 13 16 17 13 18M27 12C24 13 24 17 27 18M11 24C14 23 16 25 15 28M29 24C26 23 24 25 25 28%22/%3E%3C/g%3E%3C/g%3E%3C/svg%3E"); }
        :root[data-theme="light"] body { background-image: url("data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 width=%22280%22 height=%22280%22 viewBox=%220 0 280 280%22%3E%3Cg fill=%22none%22 stroke=%22rgba(30,45,70,0.13)%22 stroke-width=%222.2%22 stroke-linecap=%22round%22 stroke-linejoin=%22round%22%3E%3Cg transform=%22translate(12 10) rotate(-15 20 20) scale(1.0)%22%3E%3Cpath d=%22M20 6C15 4 10 6 9 10C5 11 4 16 6 19C3 22 4 28 8 29C9 33 14 35 18 33L20 32M20 6C25 4 30 6 31 10C35 11 36 16 34 19C37 22 36 28 32 29C31 33 26 35 22 33L20 32M20 6V32M13 12C16 13 16 17 13 18M27 12C24 13 24 17 27 18M11 24C14 23 16 25 15 28M29 24C26 23 24 25 25 28%22/%3E%3C/g%3E%3Cg transform=%22translate(130 4) rotate(20 20 20) scale(0.8)%22%3E%3Cpath d=%22M20 6C15 4 10 6 9 10C5 11 4 16 6 19C3 22 4 28 8 29C9 33 14 35 18 33L20 32M20 6C25 4 30 6 31 10C35 11 36 16 34 19C37 22 36 28 32 29C31 33 26 35 22 33L20 32M20 6V32M13 12C16 13 16 17 13 18M27 12C24 13 24 17 27 18M11 24C14 23 16 25 15 28M29 24C26 23 24 25 25 28%22/%3E%3C/g%3E%3Cg transform=%22translate(214 60) rotate(-8 20 20) scale(1.05)%22%3E%3Cpath d=%22M20 6C15 4 10 6 9 10C5 11 4 16 6 19C3 22 4 28 8 29C9 33 14 35 18 33L20 32M20 6C25 4 30 6 31 10C35 11 36 16 34 19C37 22 36 28 32 29C31 33 26 35 22 33L20 32M20 6V32M13 12C16 13 16 17 13 18M27 12C24 13 24 17 27 18M11 24C14 23 16 25 15 28M29 24C26 23 24 25 25 28%22/%3E%3C/g%3E%3Cg transform=%22translate(70 84) rotate(30 20 20) scale(0.85)%22%3E%3Cpath d=%22M20 6C15 4 10 6 9 10C5 11 4 16 6 19C3 22 4 28 8 29C9 33 14 35 18 33L20 32M20 6C25 4 30 6 31 10C35 11 36 16 34 19C37 22 36 28 32 29C31 33 26 35 22 33L20 32M20 6V32M13 12C16 13 16 17 13 18M27 12C24 13 24 17 27 18M11 24C14 23 16 25 15 28M29 24C26 23 24 25 25 28%22/%3E%3C/g%3E%3Cg transform=%22translate(168 140) rotate(-25 20 20) scale(0.95)%22%3E%3Cpath d=%22M20 6C15 4 10 6 9 10C5 11 4 16 6 19C3 22 4 28 8 29C9 33 14 35 18 33L20 32M20 6C25 4 30 6 31 10C35 11 36 16 34 19C37 22 36 28 32 29C31 33 26 35 22 33L20 32M20 6V32M13 12C16 13 16 17 13 18M27 12C24 13 24 17 27 18M11 24C14 23 16 25 15 28M29 24C26 23 24 25 25 28%22/%3E%3C/g%3E%3Cg transform=%22translate(6 166) rotate(12 20 20) scale(0.8)%22%3E%3Cpath d=%22M20 6C15 4 10 6 9 10C5 11 4 16 6 19C3 22 4 28 8 29C9 33 14 35 18 33L20 32M20 6C25 4 30 6 31 10C35 11 36 16 34 19C37 22 36 28 32 29C31 33 26 35 22 33L20 32M20 6V32M13 12C16 13 16 17 13 18M27 12C24 13 24 17 27 18M11 24C14 23 16 25 15 28M29 24C26 23 24 25 25 28%22/%3E%3C/g%3E%3Cg transform=%22translate(100 200) rotate(-5 20 20) scale(1.05)%22%3E%3Cpath d=%22M20 6C15 4 10 6 9 10C5 11 4 16 6 19C3 22 4 28 8 29C9 33 14 35 18 33L20 32M20 6C25 4 30 6 31 10C35 11 36 16 34 19C37 22 36 28 32 29C31 33 26 35 22 33L20 32M20 6V32M13 12C16 13 16 17 13 18M27 12C24 13 24 17 27 18M11 24C14 23 16 25 15 28M29 24C26 23 24 25 25 28%22/%3E%3C/g%3E%3Cg transform=%22translate(220 214) rotate(35 20 20) scale(0.75)%22%3E%3Cpath d=%22M20 6C15 4 10 6 9 10C5 11 4 16 6 19C3 22 4 28 8 29C9 33 14 35 18 33L20 32M20 6C25 4 30 6 31 10C35 11 36 16 34 19C37 22 36 28 32 29C31 33 26 35 22 33L20 32M20 6V32M13 12C16 13 16 17 13 18M27 12C24 13 24 17 27 18M11 24C14 23 16 25 15 28M29 24C26 23 24 25 25 28%22/%3E%3C/g%3E%3Cg transform=%22translate(42 246) rotate(-30 20 20) scale(0.7)%22%3E%3Cpath d=%22M20 6C15 4 10 6 9 10C5 11 4 16 6 19C3 22 4 28 8 29C9 33 14 35 18 33L20 32M20 6C25 4 30 6 31 10C35 11 36 16 34 19C37 22 36 28 32 29C31 33 26 35 22 33L20 32M20 6V32M13 12C16 13 16 17 13 18M27 12C24 13 24 17 27 18M11 24C14 23 16 25 15 28M29 24C26 23 24 25 25 28%22/%3E%3C/g%3E%3C/g%3E%3C/svg%3E"); }
    </style>
</head>
<body>
<main class="container">
    <header>
        <div class="header-title">
            <a class="back-icon" href="index.php" title="Zurück zum Wortschatz" aria-label="Zurück zum Wortschatz">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 18l-6-6 6-6"/></svg>
            </a>
            <h1>👥 Community</h1>
        </div>
    </header>

    <p id="notice" class="message" role="status" hidden></p>

    <div class="layout">
        <aside class="sidebar">
            <section class="panel" aria-labelledby="peopleHeading">
                <h2 id="peopleHeading">Personen finden</h2>
                <label for="peopleSearch" class="visually-hidden">Nach Namen suchen</label>
                <input id="peopleSearch" type="search" maxlength="50" autocomplete="off" placeholder="Nach Namen suchen">
                <div id="people" class="people-list" aria-live="polite"><p class="empty">Wird geladen…</p></div>
            </section>

            <section class="panel">
                <div class="tabs" role="tablist">
                    <button class="tab" role="tab" data-tab="chats" aria-selected="true">Chats</button>
                    <button class="tab" role="tab" data-tab="friends" aria-selected="false">Freunde</button>
                    <button class="tab" role="tab" data-tab="requests" aria-selected="false">Anfragen</button>
                    <button class="tab" role="tab" data-tab="blocked" aria-selected="false">Blockiert</button>
                </div>
                <div id="list" role="tabpanel"><p class="empty">Wird geladen…</p></div>
            </section>
        </aside>

        <section class="detail-area">
            <section id="detailEmpty" class="panel" aria-labelledby="rankingHeading">
                <h2 id="rankingHeading">🏆 Rangliste</h2>
                <p class="hint">Aktive Wörter (Status „aktiva“) von dir und deinen Freunden.</p>
                <ol id="ranking" class="ranking"></ol>
                <p id="rankingNote" class="muted ranking-note"></p>
            </section>

            <div id="detail" hidden>
                <div class="panel detail-head">
                    <div class="detail-nav">
                        <button id="backBtn" class="btn btn-secondary btn-small" type="button">Schließen</button>
                        <button id="statsBtn" class="btn btn-secondary btn-small" type="button"
                                aria-expanded="false" aria-controls="statsPanel">Statistik anzeigen</button>
                    </div>
                    <h2 id="friendName"></h2>
                    <div class="detail-actions">
                        <button id="removeBtn" class="btn btn-secondary btn-small" type="button">Entfernen</button>
                        <button id="blockBtn" class="btn btn-danger btn-small" type="button">Blockieren</button>
                    </div>
                </div>

                <section id="statsPanel" class="panel" aria-labelledby="statsHeading" hidden>
                    <h3 id="statsHeading">Statistik</h3>
                    <div id="stats"></div>
                </section>

                <section class="panel" aria-labelledby="chatHeading">
                    <h3 id="chatHeading">Chat</h3>
                    <div id="chatLog" class="chat-log" aria-live="polite"></div>
                    <form id="chatForm" class="chat-form">
                        <label for="chatInput" class="visually-hidden">Nachricht</label>
                        <textarea id="chatInput" rows="2" maxlength="1000" placeholder="Nachricht schreiben…"></textarea>
                        <button id="chatSend" class="btn" type="submit">Senden</button>
                    </form>
                </section>
            </div>
        </section>
    </div>
</main>

<script>
const $ = (id) => document.getElementById(id);

const state = {
    csrf: null,
    data: null,
    tab: 'chats',
    friendId: null,
    chatLoaded: false,
    lastId: 0,
    seen: new Set(),
    polling: false,
    pollAgain: false,
    chatTimer: null,
};

const STATUS_ORDER = ['aktiva', 'wiederholen', 'neu', 'passiv', 'warteschlange'];

// ---------- Helpers ----------

// Builds DOM nodes. Strings are always inserted as text, never as HTML,
// so names and chat messages can't inject markup or scripts.
function el(tag, props = {}, ...children) {
    const node = document.createElement(tag);
    for (const [k, v] of Object.entries(props)) {
        if (k === 'class') node.className = v;
        else if (k.startsWith('on')) node.addEventListener(k.slice(2), v);
        else if (v !== null && v !== undefined && v !== false) node.setAttribute(k, v === true ? '' : v);
    }
    for (const c of children.flat()) {
        if (c !== null && c !== undefined && c !== false) node.append(c instanceof Node ? c : String(c));
    }
    return node;
}

async function api(action, body = null, params = '') {
    const opts = body ? {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': state.csrf },
        body: JSON.stringify(body),
    } : {};
    let res;
    try {
        res = await fetch(`community_api.php?action=${action}${params}`, opts);
    } catch {
        return { success: false, error: 'Keine Verbindung zum Server.', _status: 0 };
    }
    if (res.status === 401) {
        location.href = 'login.php';
        return { success: false, _status: 401 };
    }
    try {
        const json = await res.json();
        json._status = res.status;
        return json;
    } catch {
        return { success: false, error: 'Unerwartete Antwort vom Server.', _status: res.status };
    }
}

let noticeTimer = null;
function showNotice(text, type = 'info') {
    const n = $('notice');
    n.textContent = text;
    n.className = 'message ' + type;
    n.hidden = false;
    clearTimeout(noticeTimer);
    noticeTimer = setTimeout(() => { n.hidden = true; }, 5000);
}

function parseDate(s) {
    return s ? new Date(s.replace(' ', 'T')) : null;
}

function fmtDate(s) {
    const d = parseDate(s);
    if (!d || isNaN(d)) return '–';
    return d.toLocaleString('de-CH', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });
}

function fmtTime(s) {
    const d = parseDate(s);
    if (!d || isNaN(d)) return '';
    const time = d.toLocaleTimeString('de-CH', { hour: '2-digit', minute: '2-digit' });
    if (d.toDateString() === new Date().toDateString()) return time;
    return d.toLocaleDateString('de-CH', { day: '2-digit', month: '2-digit' }) + ' ' + time;
}

function currentFriend() {
    return state.data?.friends.find(f => Number(f.id) === state.friendId) || null;
}

function statusLabel(s) {
    return s.charAt(0).toUpperCase() + s.slice(1);
}

// ---------- Overview (code, tabs, lists) ----------

async function loadOverview() {
    const d = await api('overview');
    if (!d.success) {
        showNotice(d.error || 'Die Daten konnten nicht geladen werden.', 'error');
        return;
    }
    state.csrf = d.csrf_token;
    state.data = d;

    if (state.friendId && !currentFriend()) {
        closeFriend('Diese Freundschaft besteht nicht mehr.');
    } else if (state.friendId) {
        $('friendName').textContent = currentFriend().name;
    }
    renderTabs();
    renderList();
    renderRanking();
}

function renderRanking() {
    const ranking = state.data.ranking;
    const medals = { 1: '🥇', 2: '🥈', 3: '🥉' };
    const leader = Math.max(1, ...ranking.map(r => r.active));

    $('ranking').replaceChildren(...ranking.map(r => {
        const inner = [
            el('span', { class: 'rank-pos', 'aria-label': `Platz ${r.rank}` }, medals[r.rank] || r.rank),
            el('span', { class: 'rank-name' }, r.me ? `${r.name} (du)` : r.name),
            el('span', { class: 'rank-value' }, r.active, el('small', {}, `von ${r.total} Wörtern`)),
            el('span', { class: 'rank-bar', 'aria-hidden': 'true' },
                el('span', { style: `width: ${Math.round(r.active / leader * 100)}%` })),
        ];
        const cls = 'rank-row' + (r.me ? ' me' : '') + (r.rank <= 3 ? ' top' : '');
        // Friends are clickable (opens stats + chat), your own row isn't
        const row = r.me
            ? el('div', { class: cls }, inner)
            : el('button', { class: cls, type: 'button', onclick: () => openFriend(r.id) }, inner);
        return el('li', {}, row);
    }));

    const me = ranking.find(r => r.me);
    let note = '';
    if (ranking.length < 2) {
        note = 'Füge Freunde hinzu, um dich mit ihnen zu vergleichen.';
    } else if (me && me.rank === 1) {
        note = 'Du bist auf Platz 1. Weiter so!';
    } else if (me) {
        const ahead = ranking.filter(r => r.active > me.active).pop(); // the next one above you
        const gap = ahead.active - me.active + 1;
        note = `Noch ${gap} aktive ${gap === 1 ? 'Wort' : 'Wörter'} bis Platz ${ahead.rank}.`;
    }
    $('rankingNote').textContent = note;
}

function renderTabs() {
    const d = state.data;
    const unread = d.conversations.reduce((sum, c) => sum + Number(c.unread || 0), 0);
    const labels = {
        chats: ['Chats', unread ? el('span', { class: 'badge', 'aria-label': `${unread} ungelesen` }, unread) : null],
        friends: ['Freunde', el('span', { class: 'count' }, d.friends.length)],
        requests: ['Anfragen', d.incoming.length ? el('span', { class: 'badge', 'aria-label': `${d.incoming.length} neu` }, d.incoming.length) : null],
        blocked: ['Blockiert', d.blocked.length ? el('span', { class: 'count' }, d.blocked.length) : null],
    };
    document.querySelectorAll('.tab').forEach(tab => {
        const key = tab.dataset.tab;
        tab.replaceChildren(...labels[key].filter(Boolean));
        tab.setAttribute('aria-selected', String(key === state.tab));
    });
}

function renderList() {
    const d = state.data;
    if (!d) return;
    const list = $('list');
    let content = [];

    if (state.tab === 'chats') {
        if (!d.conversations.length) {
            content = [el('p', { class: 'empty' }, 'Noch keine Unterhaltungen. Öffne einen Freund und schreib die erste Nachricht.')];
        } else {
            content = [el('div', { class: 'list' }, d.conversations.map(c => el('button', {
                class: 'chat-item' + (c.unread > 0 ? ' unread' : '') + (c.id === state.friendId ? ' active' : ''),
                type: 'button',
                onclick: () => openFriend(c.id),
            },
                el('span', { class: 'name' }, c.name),
                el('span', { class: 'when' }, fmtTime(c.created_at)),
                el('span', { class: 'preview' }, (c.mine ? 'Du: ' : '') + c.preview),
                c.unread > 0 ? el('span', { class: 'badge', 'aria-label': `${c.unread} ungelesen` }, c.unread) : el('span')
            )))];
        }
    }

    if (state.tab === 'friends') {
        if (!d.friends.length) {
            content = [el('p', { class: 'empty' }, 'Noch keine Freunde. Suche oben nach einer Person und sende ihr eine Anfrage.')];
        } else {
            content = [el('div', { class: 'list' }, d.friends.map(f => el('button', {
                class: 'friend-item' + (Number(f.id) === state.friendId ? ' active' : ''),
                type: 'button',
                onclick: () => openFriend(Number(f.id)),
            },
                el('span', { class: 'name' }, f.name),
                Number(f.unread) > 0 ? el('span', { class: 'badge', 'aria-label': `${f.unread} ungelesen` }, f.unread) : null
            )))];
        }
    }

    if (state.tab === 'requests') {
        content.push(el('p', { class: 'list-heading' }, 'Erhalten'));
        content.push(d.incoming.length
            ? el('div', { class: 'list' }, d.incoming.map(r => el('div', { class: 'row-item' },
                el('div', { class: 'name' }, r.name),
                el('div', { class: 'meta' }, 'Gesendet am ' + fmtDate(r.created_at)),
                el('div', { class: 'row-actions' },
                    el('button', { class: 'btn btn-small', type: 'button', onclick: () => act('respond_request', { request_id: r.request_id, accept: true }, 'Anfrage angenommen.') }, 'Annehmen'),
                    el('button', { class: 'btn btn-secondary btn-small', type: 'button', onclick: () => act('respond_request', { request_id: r.request_id, accept: false }, 'Anfrage abgelehnt.') }, 'Ablehnen'),
                    el('button', { class: 'btn btn-danger btn-small', type: 'button', onclick: () => confirmBlock(r.user_id, r.name) }, 'Blockieren')
                )
            )))
            : el('p', { class: 'empty' }, 'Keine offenen Anfragen.'));

        content.push(el('p', { class: 'list-heading' }, 'Gesendet'));
        content.push(d.outgoing.length
            ? el('div', { class: 'list' }, d.outgoing.map(r => el('div', { class: 'row-item' },
                el('div', { class: 'name' }, r.name),
                el('div', { class: 'meta' }, 'Wartet auf Antwort seit ' + fmtDate(r.created_at)),
                el('div', { class: 'row-actions' },
                    el('button', { class: 'btn btn-secondary btn-small', type: 'button', onclick: () => act('remove_friend', { friend_id: r.user_id }, 'Anfrage zurückgezogen.') }, 'Zurückziehen')
                )
            )))
            : el('p', { class: 'empty' }, 'Keine gesendeten Anfragen.'));
    }

    if (state.tab === 'blocked') {
        content = d.blocked.length
            ? [el('div', { class: 'list' }, d.blocked.map(b => el('div', { class: 'row-item' },
                el('div', { class: 'name' }, b.name),
                el('div', { class: 'meta' }, 'Blockiert seit ' + fmtDate(b.created_at)),
                el('div', { class: 'row-actions' },
                    el('button', { class: 'btn btn-secondary btn-small', type: 'button', onclick: () => act('unblock', { user_id: b.user_id }, 'Blockierung aufgehoben.') }, 'Blockierung aufheben')
                )
            )))]
            : [el('p', { class: 'empty' }, 'Du hast niemanden blockiert.')];
    }

    list.replaceChildren(...content);
}

async function act(action, body, successText) {
    const d = await api(action, body);
    if (d.success) {
        showNotice(d.message || successText, 'success');
    } else {
        showNotice(d.error || 'Die Aktion ist fehlgeschlagen.', 'error');
    }
    await loadOverview();
    loadPeople();
    return d.success;
}

// ---------- People search ----------

let peopleRequest = 0;
async function loadPeople() {
    const q = $('peopleSearch').value.trim();
    const requestNo = ++peopleRequest;
    const d = await api('search_users', null, '&q=' + encodeURIComponent(q));
    if (requestNo !== peopleRequest) return; // a newer search already started

    const box = $('people');
    if (!d.success) {
        box.replaceChildren(el('p', { class: 'empty' }, d.error || 'Die Suche ist fehlgeschlagen.'));
        return;
    }
    if (!d.people.length) {
        box.replaceChildren(el('p', { class: 'empty' }, q ? `Niemand gefunden für „${q}“.` : 'Noch keine anderen Personen registriert.'));
        return;
    }

    box.replaceChildren(...d.people.map(p => {
        let action;
        if (p.relation === 'none') {
            action = el('button', { class: 'btn btn-small', type: 'button', onclick: () => act('send_request', { user_id: p.id }, 'Anfrage gesendet.') }, 'Anfrage senden');
        } else if (p.relation === 'incoming') {
            action = el('button', { class: 'btn btn-small', type: 'button', onclick: () => act('respond_request', { request_id: p.request_id, accept: true }, 'Anfrage angenommen.') }, 'Annehmen');
        } else if (p.relation === 'outgoing') {
            action = el('span', { class: 'state' }, 'Anfrage gesendet');
        } else {
            action = el('button', { class: 'btn btn-secondary btn-small', type: 'button', onclick: () => openFriend(p.id) }, 'Öffnen');
        }
        return el('div', { class: 'person' }, el('span', { class: 'name' }, p.name), action);
    }));
}

let searchTimer = null;
$('peopleSearch').addEventListener('input', () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(loadPeople, 300); // wait until typing pauses
});

async function confirmBlock(userId, name) {
    if (!confirm(`${name} blockieren?\n\nIhr seid dann keine Freunde mehr, und ${name} kann dir keine Anfragen oder Nachrichten mehr senden.`)) return;
    const ok = await act('block', { user_id: Number(userId) }, `${name} wurde blockiert.`);
    if (ok && Number(userId) === state.friendId) closeFriend();
}

// ---------- Friend detail: stats + chat ----------

function openFriend(id) {
    stopChat();
    state.friendId = id;
    state.lastId = 0;
    state.seen = new Set();
    state.chatLoaded = false;

    document.body.classList.add('friend-open');
    $('detailEmpty').hidden = true;
    $('detail').hidden = false;
    $('friendName').textContent = currentFriend()?.name || '';
    setStatsVisible(false);
    state.statsLoaded = false;
    $('chatLog').replaceChildren();
    renderList();

    pollChat();
    state.chatTimer = setInterval(() => { if (!document.hidden) pollChat(); }, 4000);
    if (window.matchMedia('(max-width: 800px)').matches) window.scrollTo(0, 0);
}

function closeFriend(message) {
    stopChat();
    state.friendId = null;
    document.body.classList.remove('friend-open');
    $('detail').hidden = true;
    $('detailEmpty').hidden = false;
    renderList();
    if (message) showNotice(message, 'info');
}

function stopChat() {
    clearInterval(state.chatTimer);
    state.chatTimer = null;
}

function setStatsVisible(visible) {
    $('statsPanel').hidden = !visible;
    $('statsBtn').setAttribute('aria-expanded', String(visible));
    $('statsBtn').textContent = visible ? 'Statistik ausblenden' : 'Statistik anzeigen';
}

async function loadStats() {
    state.statsLoaded = true;
    $('stats').replaceChildren(el('p', { class: 'muted' }, 'Statistik wird geladen…'));
    const fid = state.friendId;
    const d = await api('friend_stats', null, `&friend_id=${fid}`);
    if (fid !== state.friendId) return; // user switched friend meanwhile

    const box = $('stats');
    if (!d.success) {
        box.replaceChildren(el('p', { class: 'muted' }, d.error || 'Die Statistik ist nicht verfügbar.'));
        return;
    }

    const name = currentFriend()?.name || 'Freund';
    const rows = [
        ['Wörter gesamt', d.me.total, d.friend.total],
        ['Heute wiederholt', d.me.reviewed_today, d.friend.reviewed_today],
        ['Neu in den letzten 7 Tagen', d.me.added_7d, d.friend.added_7d],
    ];

    const statuses = [...STATUS_ORDER];
    for (const s of [...Object.keys(d.me.by_status), ...Object.keys(d.friend.by_status)]) {
        if (!statuses.includes(s)) statuses.push(s);
    }
    const statusRows = statuses
        .map(s => [statusLabel(s), d.me.by_status[s] || 0, d.friend.by_status[s] || 0])
        .filter(([, a, b]) => a > 0 || b > 0);

    const makeRow = ([label, mine, theirs], groupStart = false) => el('tr', { class: groupStart ? 'group-start' : null },
        el('td', {}, label),
        el('td', { class: mine > theirs ? 'lead' : null }, mine),
        el('td', { class: theirs > mine ? 'lead' : null }, theirs)
    );

    box.replaceChildren(
        el('table', { class: 'stats-table' },
            el('thead', {}, el('tr', {}, el('th', { scope: 'col' }, ''), el('th', { scope: 'col' }, 'Du'), el('th', { scope: 'col' }, name))),
            el('tbody', {},
                rows.map(r => makeRow(r)),
                statusRows.map((r, i) => makeRow(r, i === 0))
            )
        ),
        el('p', { class: 'muted last-active' },
            d.friend.last_active ? `${name} hat zuletzt am ${fmtDate(d.friend.last_active)} gelernt.` : `${name} hat noch keine Wörter gelernt.`)
    );
}

async function pollChat() {
    if (!state.friendId) return;
    if (state.polling) { state.pollAgain = true; return; }
    state.polling = true;

    const fid = state.friendId;
    const d = await api('messages', null, `&friend_id=${fid}&after_id=${state.lastId}`);
    state.polling = false;

    if (fid === state.friendId) {
        if (!d.success) {
            if (d._status === 403) {
                closeFriend('Diese Freundschaft besteht nicht mehr.');
                loadOverview();
            }
        } else {
            const added = appendMessages(d.messages);
            if (added && state.chatLoaded) {
                // New message sent or received: refresh the chats list preview and order
                loadOverview();
            } else {
                // Server marked them as read: clear the badges locally
                const f = currentFriend();
                const c = state.data?.conversations.find(x => x.id === fid);
                if ((f && Number(f.unread) > 0) || (c && c.unread > 0)) {
                    if (f) f.unread = 0;
                    if (c) c.unread = 0;
                    renderTabs();
                    renderList();
                }
            }
            state.chatLoaded = true;
        }
    }

    if (state.pollAgain) {
        state.pollAgain = false;
        pollChat();
    }
}

function appendMessages(messages) {
    const log = $('chatLog');
    const nearBottom = log.scrollHeight - log.scrollTop - log.clientHeight < 80;
    let added = 0;

    for (const m of messages) {
        if (state.seen.has(m.id)) continue;
        state.seen.add(m.id);
        state.lastId = Math.max(state.lastId, m.id);
        log.append(el('div', { class: 'msg' + (m.mine ? ' mine' : '') },
            el('div', { class: 'msg-body' }, m.body),
            el('time', { class: 'msg-time', datetime: m.created_at }, fmtTime(m.created_at))
        ));
        added++;
    }

    if (added) {
        log.querySelector('.chat-empty')?.remove();
        if (nearBottom || added === state.seen.size) log.scrollTop = log.scrollHeight;
    } else if (!state.seen.size && !log.querySelector('.chat-empty')) {
        log.append(el('p', { class: 'chat-empty' }, 'Noch keine Nachrichten. Schreib die erste!'));
    }
    return added;
}

async function sendMessage(e) {
    e.preventDefault();
    const input = $('chatInput');
    const body = input.value.trim();
    if (!body || !state.friendId) return;

    const btn = $('chatSend');
    btn.disabled = true;
    const d = await api('send_message', { friend_id: state.friendId, body });
    btn.disabled = false;

    if (d.success) {
        input.value = '';
        input.focus();
        // Fetch via polling so messages always appear in the correct order
        pollChat();
        $('chatLog').scrollTop = $('chatLog').scrollHeight;
    } else {
        showNotice(d.error || 'Die Nachricht konnte nicht gesendet werden.', 'error');
    }
}

// ---------- Events ----------

document.querySelectorAll('.tab').forEach(tab => tab.addEventListener('click', () => {
    state.tab = tab.dataset.tab;
    renderTabs();
    renderList();
}));

$('removeBtn').addEventListener('click', async () => {
    const f = currentFriend();
    if (!f || !confirm(`${f.name} aus deinen Freunden entfernen?\n\nIhr seht dann eure Statistiken nicht mehr und könnt nicht mehr chatten.`)) return;
    if (await act('remove_friend', { friend_id: Number(f.id) }, `${f.name} wurde entfernt.`)) closeFriend();
});

$('blockBtn').addEventListener('click', () => {
    const f = currentFriend();
    if (f) confirmBlock(f.id, f.name);
});

$('backBtn').addEventListener('click', () => closeFriend());

$('statsBtn').addEventListener('click', () => {
    const show = $('statsPanel').hidden;
    setStatsVisible(show);
    if (show && !state.statsLoaded) loadStats(); // load only the first time it's opened
});

$('chatForm').addEventListener('submit', sendMessage);

// Enter sends, Shift+Enter makes a new line
$('chatInput').addEventListener('keydown', (e) => {
    if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) {
        e.preventDefault();
        $('chatForm').requestSubmit();
    }
});

// Refresh requests and unread counts every 30 s, and when the tab becomes visible again
setInterval(() => { if (!document.hidden) loadOverview(); }, 30000);
document.addEventListener('visibilitychange', () => {
    if (!document.hidden) {
        loadOverview();
        pollChat();
    }
});

loadOverview();
loadPeople();
</script>
</body>
</html>