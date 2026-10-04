<?php
// community_lib.php - shared helpers for friends, blocks, stats and chat.
// Every permission check lives here so the API and pages use the same rules.

// SQL expression for a name that is safe to show to other users.
// Never exposes an email address (Google users have their email as username).
function publicNameSql(string $alias = 'u'): string {
    return "CASE
        WHEN {$alias}.display_name IS NOT NULL AND {$alias}.display_name != '' THEN {$alias}.display_name
        WHEN {$alias}.username NOT LIKE '%@%' THEN {$alias}.username
        ELSE CONCAT('Nutzer #', {$alias}.id)
    END";
}

// Has $blocker blocked $blocked?
function isBlockedBy(PDO $pdo, int $blocker, int $blocked): bool {
    $stmt = $pdo->prepare("SELECT 1 FROM user_blocks WHERE blocker_id = ? AND blocked_id = ? LIMIT 1");
    $stmt->execute([$blocker, $blocked]);
    return (bool)$stmt->fetchColumn();
}

function isBlockedEitherWay(PDO $pdo, int $a, int $b): bool {
    return isBlockedBy($pdo, $a, $b) || isBlockedBy($pdo, $b, $a);
}

// The friendship row between two users (pending or accepted), or null
function getFriendship(PDO $pdo, int $a, int $b): ?array {
    $stmt = $pdo->prepare("SELECT * FROM friendships WHERE user_low = ? AND user_high = ? LIMIT 1");
    $stmt->execute([min($a, $b), max($a, $b)]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

// THE permission check for stats and chat
function areFriends(PDO $pdo, int $a, int $b): bool {
    if ($a <= 0 || $b <= 0 || $a === $b) {
        return false;
    }
    $f = getFriendship($pdo, $a, $b);
    return $f !== null && $f['status'] === 'accepted' && !isBlockedEitherWay($pdo, $a, $b);
}

// Returns the user's friend code, creating one on first use
function ensureFriendCode(PDO $pdo, int $uid): string {
    $stmt = $pdo->prepare("SELECT friend_code FROM users WHERE id = ?");
    $stmt->execute([$uid]);
    $code = $stmt->fetchColumn();
    if ($code) {
        return $code;
    }

    // No 0/O/1/I to avoid confusion when people type it
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    for ($try = 0; $try < 5; $try++) {
        $code = '';
        for ($i = 0; $i < 8; $i++) {
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        try {
            $pdo->prepare("UPDATE users SET friend_code = ? WHERE id = ? AND friend_code IS NULL")
                ->execute([$code, $uid]);
            $stmt->execute([$uid]);
            return (string)$stmt->fetchColumn();
        } catch (PDOException $e) {
            if ($e->getCode() !== '23000') { // 23000 = duplicate code, try another
                throw $e;
            }
        }
    }
    throw new RuntimeException('Could not generate a friend code.');
}

// Aggregate learning stats that friends are allowed to see (no word lists)
function getUserStats(PDO $pdo, int $uid): array {
    $stmt = $pdo->prepare("SELECT Status, COUNT(*) AS c FROM meine_wortschatz
                           WHERE user_id = ? GROUP BY Status
                           ORDER BY FIELD(Status, 'aktiva', 'wiederholen', 'neu', 'passiv', 'warteschlange')");
    $stmt->execute([$uid]);
    $byStatus = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $byStatus[$row['Status'] ?: 'ohne Status'] = (int)$row['c'];
    }

    $stmt = $pdo->prepare("SELECT
            COUNT(*) AS total,
            SUM(DATE(Modified) = CURDATE()) AS reviewed_today,
            SUM(Created >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)) AS added_7d,
            MAX(Modified) AS last_active
        FROM meine_wortschatz WHERE user_id = ?");
    $stmt->execute([$uid]);
    $agg = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    return [
        'total'          => (int)($agg['total'] ?? 0),
        'reviewed_today' => (int)($agg['reviewed_today'] ?? 0),
        'added_7d'       => (int)($agg['added_7d'] ?? 0),
        'last_active'    => $agg['last_active'] ?? null,
        'by_status'      => $byStatus,
    ];
}
