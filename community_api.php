<?php
// community_api.php - JSON backend for the community feature.
// GET  ?action=overview | friend_stats&friend_id= | messages&friend_id=&after_id=
// POST ?action=send_request | respond_request | remove_friend | block | unblock | send_message
//      (JSON body + header "X-CSRF-Token")
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

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function respond(array $data, int $status = 200): void {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function fail(string $msg, int $status = 400): void {
    respond(['success' => false, 'error' => $msg], $status);
}

$me = (int)($_SESSION['user_id'] ?? 0);
if (empty($_SESSION['logged_in']) || $me <= 0) {
    fail('Nicht angemeldet.', 401);
}

require_once 'db.php';
require_once 'community_lib.php';
$pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$action = $_GET['action'] ?? '';
$postActions = ['send_request', 'respond_request', 'remove_friend', 'block', 'unblock', 'send_message'];
$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
$input = [];

if (in_array($action, $postActions, true)) {
    if (!$isPost) {
        fail('Methode nicht erlaubt.', 405);
    }
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!is_string($token) || !hash_equals($_SESSION['csrf_token'], $token)) {
        fail('Sitzung abgelaufen. Bitte Seite neu laden.', 403);
    }
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        $input = [];
    }
}

$name = publicNameSql('u');

try {
    switch ($action) {

        // ---------- Everything the community page needs on load ----------
        case 'overview':
            $code = ensureFriendCode($pdo, $me);

            $stmt = $pdo->prepare("SELECT u.id, $name AS name, f.accepted_at,
                    (SELECT COUNT(*) FROM messages m
                      WHERE m.sender_id = u.id AND m.receiver_id = ? AND m.read_at IS NULL) AS unread
                FROM friendships f
                JOIN users u ON u.id = IF(f.requester_id = ?, f.addressee_id, f.requester_id)
                WHERE f.status = 'accepted' AND (f.requester_id = ? OR f.addressee_id = ?)
                ORDER BY name ASC");
            $stmt->execute([$me, $me, $me, $me]);
            $friends = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $stmt = $pdo->prepare("SELECT f.id AS request_id, u.id AS user_id, $name AS name, f.created_at
                FROM friendships f JOIN users u ON u.id = f.requester_id
                WHERE f.addressee_id = ? AND f.status = 'pending' ORDER BY f.created_at DESC");
            $stmt->execute([$me]);
            $incoming = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $stmt = $pdo->prepare("SELECT f.id AS request_id, u.id AS user_id, $name AS name, f.created_at
                FROM friendships f JOIN users u ON u.id = f.addressee_id
                WHERE f.requester_id = ? AND f.status = 'pending' ORDER BY f.created_at DESC");
            $stmt->execute([$me]);
            $outgoing = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $stmt = $pdo->prepare("SELECT u.id AS user_id, $name AS name, b.created_at
                FROM user_blocks b JOIN users u ON u.id = b.blocked_id
                WHERE b.blocker_id = ? ORDER BY b.created_at DESC");
            $stmt->execute([$me]);
            $blocked = $stmt->fetchAll(PDO::FETCH_ASSOC);

            respond([
                'success'     => true,
                'csrf_token'  => $_SESSION['csrf_token'],
                'friend_code' => $code,
                'friends'     => $friends,
                'incoming'    => $incoming,
                'outgoing'    => $outgoing,
                'blocked'     => $blocked,
            ]);

        // ---------- Stats: friend's and mine, for comparison ----------
        case 'friend_stats':
            $fid = (int)($_GET['friend_id'] ?? 0);
            if (!areFriends($pdo, $me, $fid)) {
                fail('Keine Berechtigung.', 403);
            }
            respond([
                'success' => true,
                'friend'  => getUserStats($pdo, $fid),
                'me'      => getUserStats($pdo, $me),
            ]);

        // ---------- Chat: load history / poll for new messages ----------
        case 'messages':
            $fid = (int)($_GET['friend_id'] ?? 0);
            $after = max(0, (int)($_GET['after_id'] ?? 0));
            if (!areFriends($pdo, $me, $fid)) {
                fail('Keine Berechtigung.', 403);
            }

            $base = "SELECT id, sender_id, body, created_at FROM messages
                     WHERE ((sender_id = ? AND receiver_id = ?) OR (sender_id = ? AND receiver_id = ?))";
            $params = [$me, $fid, $fid, $me];

            if ($after === 0) {
                // First load: the last 50 messages, oldest first
                $stmt = $pdo->prepare("$base ORDER BY id DESC LIMIT 50");
                $stmt->execute($params);
                $rows = array_reverse($stmt->fetchAll(PDO::FETCH_ASSOC));
            } else {
                // Polling: only what's new since the last message the browser has
                $stmt = $pdo->prepare("$base AND id > ? ORDER BY id ASC LIMIT 100");
                $stmt->execute(array_merge($params, [$after]));
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }

            $pdo->prepare("UPDATE messages SET read_at = NOW()
                           WHERE sender_id = ? AND receiver_id = ? AND read_at IS NULL")
                ->execute([$fid, $me]);

            $messages = array_map(fn($r) => [
                'id'         => (int)$r['id'],
                'mine'       => (int)$r['sender_id'] === $me,
                'body'       => $r['body'],
                'created_at' => $r['created_at'],
            ], $rows);

            respond(['success' => true, 'messages' => $messages]);

        // ---------- Send a friend request by code ----------
        case 'send_request':
            $code = strtoupper(trim((string)($input['friend_code'] ?? '')));
            if (!preg_match('/^[A-Z0-9]{8}$/', $code)) {
                fail('Ungültiger Code.');
            }

            $stmt = $pdo->prepare("SELECT id FROM users WHERE friend_code = ? LIMIT 1");
            $stmt->execute([$code]);
            $target = (int)$stmt->fetchColumn();

            if ($target <= 0 || $target === $me) {
                fail('Code nicht gefunden.', 404);
            }
            if (isBlockedBy($pdo, $me, $target)) {
                fail('Du hast diese Person blockiert. Hebe die Blockierung zuerst auf.');
            }
            if (isBlockedBy($pdo, $target, $me)) {
                fail('Code nicht gefunden.', 404); // don't reveal the block
            }

            $f = getFriendship($pdo, $me, $target);
            if ($f) {
                if ($f['status'] === 'accepted') {
                    fail('Ihr seid bereits befreundet.');
                }
                if ((int)$f['requester_id'] === $me) {
                    fail('Anfrage wurde bereits gesendet.');
                }
                // They already asked me -> accept instead of creating a second request
                $pdo->prepare("UPDATE friendships SET status = 'accepted', accepted_at = NOW() WHERE id = ?")
                    ->execute([$f['id']]);
                respond(['success' => true, 'message' => 'Ihr seid jetzt befreundet.']);
            }

            $stmt = $pdo->prepare("SELECT COUNT(*) FROM friendships WHERE requester_id = ? AND status = 'pending'");
            $stmt->execute([$me]);
            if ((int)$stmt->fetchColumn() >= 20) {
                fail('Zu viele offene Anfragen.', 429);
            }

            try {
                $pdo->prepare("INSERT INTO friendships (requester_id, addressee_id) VALUES (?, ?)")
                    ->execute([$me, $target]);
            } catch (PDOException $e) {
                if ($e->getCode() === '23000') {
                    fail('Anfrage existiert bereits.');
                }
                throw $e;
            }
            respond(['success' => true, 'message' => 'Anfrage gesendet.']);

        // ---------- Accept or decline an incoming request ----------
        case 'respond_request':
            $rid = (int)($input['request_id'] ?? 0);
            if (!empty($input['accept'])) {
                $stmt = $pdo->prepare("UPDATE friendships SET status = 'accepted', accepted_at = NOW()
                                       WHERE id = ? AND addressee_id = ? AND status = 'pending'");
            } else {
                $stmt = $pdo->prepare("DELETE FROM friendships
                                       WHERE id = ? AND addressee_id = ? AND status = 'pending'");
            }
            $stmt->execute([$rid, $me]);
            if ($stmt->rowCount() === 0) {
                fail('Anfrage nicht gefunden.', 404);
            }
            respond(['success' => true]);

        // ---------- Remove friend (also cancels an outgoing request) ----------
        case 'remove_friend':
            $fid = (int)($input['friend_id'] ?? 0);
            $pdo->prepare("DELETE FROM friendships WHERE user_low = ? AND user_high = ?")
                ->execute([min($me, $fid), max($me, $fid)]);
            respond(['success' => true]);

        // ---------- Block: remove friendship + prevent contact ----------
        case 'block':
            $bid = (int)($input['user_id'] ?? 0);
            if ($bid <= 0 || $bid === $me) {
                fail('Ungültiger Nutzer.');
            }
            $stmt = $pdo->prepare("SELECT 1 FROM users WHERE id = ?");
            $stmt->execute([$bid]);
            if (!$stmt->fetchColumn()) {
                fail('Ungültiger Nutzer.', 404);
            }

            $pdo->beginTransaction();
            $pdo->prepare("INSERT IGNORE INTO user_blocks (blocker_id, blocked_id) VALUES (?, ?)")
                ->execute([$me, $bid]);
            $pdo->prepare("DELETE FROM friendships WHERE user_low = ? AND user_high = ?")
                ->execute([min($me, $bid), max($me, $bid)]);
            $pdo->commit();
            respond(['success' => true]);

        case 'unblock':
            $bid = (int)($input['user_id'] ?? 0);
            $pdo->prepare("DELETE FROM user_blocks WHERE blocker_id = ? AND blocked_id = ?")
                ->execute([$me, $bid]);
            respond(['success' => true]);

        // ---------- Send a chat message ----------
        case 'send_message':
            $fid = (int)($input['friend_id'] ?? 0);
            $body = trim((string)($input['body'] ?? ''));

            if ($body === '') {
                fail('Nachricht ist leer.');
            }
            if (mb_strlen($body) > 1000) {
                fail('Nachricht ist zu lang (max. 1000 Zeichen).');
            }
            if (!areFriends($pdo, $me, $fid)) {
                fail('Keine Berechtigung.', 403);
            }

            // Simple flood protection: max 5 messages per 10 seconds
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM messages
                                   WHERE sender_id = ? AND created_at > NOW() - INTERVAL 10 SECOND");
            $stmt->execute([$me]);
            if ((int)$stmt->fetchColumn() >= 5) {
                fail('Bitte etwas langsamer.', 429);
            }

            $pdo->prepare("INSERT INTO messages (sender_id, receiver_id, body) VALUES (?, ?, ?)")
                ->execute([$me, $fid, $body]);
            $id = (int)$pdo->lastInsertId();

            $stmt = $pdo->prepare("SELECT created_at FROM messages WHERE id = ?");
            $stmt->execute([$id]);

            respond(['success' => true, 'message' => [
                'id'         => $id,
                'mine'       => true,
                'body'       => $body,
                'created_at' => $stmt->fetchColumn(),
            ]]);

        default:
            fail('Unbekannte Aktion.');
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('community_api: ' . $e->getMessage());
    fail('Ein Fehler ist aufgetreten.', 500);
}
