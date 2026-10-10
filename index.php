<?php
// --- ERROR REPORTING & ANTI-CACHING ---
ini_set('display_errors', 0);
error_reporting(E_ALL);

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');
header('Expires: 0');

if (function_exists('opcache_reset')) {
    @opcache_reset();
}

// --- SECURE SESSION COOKIE (use the same call in login.php BEFORE its session_start) ---
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'secure'   => !empty($_SERVER['HTTPS']),
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

// db.php loads config.php and creates $pdo and $config
require_once 'db.php';
require_once 'theme.php';

// --- CURRENT USER: every vocabulary query is scoped to this ID ---
$uid = (int)($_SESSION['user_id'] ?? 0);
$isLoggedIn = !empty($_SESSION['logged_in']) && $uid > 0;

// --- CONFIGURATION: API keys come from config.php (never hardcode them here) ---
define('GEMINI_API_KEY', $config['gemini_api_key'] ?? '');
define('RAPIDAPI_KEY', $config['rapidapi_key'] ?? '');

if (isset($pdo) && method_exists($pdo, 'setAttribute')) {
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
}

// --- HELPERS ---
// Prepared query shortcut (used for all user-scoped SELECTs)
function userQuery(PDO $pdo, string $sql, array $params = []): PDOStatement {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}

const WORD_COLS = "sharepoint_list, Thema, Wortarten, Wort, Artikel, Plural, Score, Status, Created, Modified, NachsteUbungDatum, VerbFlag, Konjugation, grundverb, praefix, praeposition_kollokation, Übersetzung, synonym, Beispiel";

// --- SCORE → STATUS (single source of truth) ---
// The status is never set by hand: it is always derived from the score.
//   Score < -1  → warteschlange
//   Score = -1  → passiv
//   Score =  0  → neu
//   Score 1..9  → wiederholen
//   Score >= 10 → aktiva
function statusFromScore(int $score): string {
    if ($score < -1) return 'warteschlange';
    if ($score === -1) return 'passiv';
    if ($score === 0) return 'neu';
    if ($score < 10) return 'wiederholen';
    return 'aktiva';
}

// Same rule in SQL, used to bring existing rows in line with their score
const STATUS_FROM_SCORE_SQL = "CASE WHEN COALESCE(Score, 0) < -1 THEN 'warteschlange' WHEN COALESCE(Score, 0) = -1 THEN 'passiv' WHEN COALESCE(Score, 0) = 0 THEN 'neu' WHEN COALESCE(Score, 0) < 10 THEN 'wiederholen' ELSE 'aktiva' END";

function syncStatusesFromScore(PDO $pdo, $uid): void {
    $stmt = $pdo->prepare("UPDATE meine_wortschatz SET Status = " . STATUS_FROM_SCORE_SQL . " WHERE user_id = ? AND (Status IS NULL OR Status <> " . STATUS_FROM_SCORE_SQL . ")");
    $stmt->execute([$uid]);
}

// Apply game points to a score.
// Gains start from at least 0, so a queued/passive word that is answered correctly enters the learning track.
// Losses never push a learning word below 0 and never change a queued/passive word.
function applyScorePoints(int $current, int $points): int {
    if ($points >= 0) {
        return max(0, $current) + $points;
    }
    return $current > 0 ? max(0, $current + $points) : $current;
}

// Kenntnisse rating → new score (same rule for the main page, the games and the word form)
function scoreAfterRating(int $current, string $result): int {
    switch ($result) {
        case 'direkt_aktiv':
            return 10;
        case 'sehr_gut':
            return applyScorePoints($current, 3);
        case 'yes':
            return applyScorePoints($current, 1);
        case 'wiederholen':
            // Already in "wiederholen": lose a point but stay in it; otherwise restart at score 1
            return statusFromScore($current) === 'wiederholen' ? max(1, $current - 1) : 1;
        case 'passiv':
            return -1;
        case 'warteschlange':
            return min($current, -2);
        default:
            return $current;
    }
}

// Days until the next review, based on the score
function nextReviewDays(int $score): int {
    return statusFromScore($score) === 'aktiva' ? $score * 10 : max(1, $score * 3);
}

// Store a new score for a word; status and next review date follow automatically. Returns the new status.
function saveWordScore(PDO $pdo, $uid, string $wort, int $newScore): string {
    $newStatus = statusFromScore($newScore);
    $update = $pdo->prepare("UPDATE meine_wortschatz SET Score = ?, Status = ?, Modified = NOW(), NachsteUbungDatum = DATE_ADD(NOW(), INTERVAL ? DAY) WHERE Wort = ? AND user_id = ?");
    $update->execute([$newScore, $newStatus, nextReviewDays($newScore), $wort, $uid]);
    return $newStatus;
}

function jsonInput(): array {
    $data = json_decode(file_get_contents('php://input'), true);
    return is_array($data) ? $data : [];
}

function likeEscape(string $s): string {
    return addcslashes($s, '%_\\');
}

function normalizeVerbFlag(array &$w): void {
    $w['VerbFlag'] = (isset($w['VerbFlag']) && (int)$w['VerbFlag'] === 1) ? 1 : 0;
}

// Safe JSON for inline <script> blocks
function jsonForScript($value): string {
    return json_encode($value, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
}

// Safe JS string argument for inline onclick="" attributes
// Kenntnisse rating buttons: [result key, css class, icon, label]
const RATE_OPTIONS = [
    ['sehr_gut',      'r-sehr-gut',      '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3.5l2.6 5.3 5.9.9-4.3 4.1 1 5.8L12 16.9l-5.2 2.7 1-5.8-4.3-4.1 5.9-.9z"/></svg>', 'sehr gut'],
    ['yes',           'r-ja',            '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>', 'ja'],
    ['wiederholen',   'r-wiederholen',   '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 12a8 8 0 1 1-2.3-5.7"/><path d="M20 4v5h-5"/></svg>', "wieder\u{00AD}holen"],
    ['passiv',        'r-passiv',        '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M19.5 14.5A8 8 0 1 1 9.5 4.5a6.5 6.5 0 0 0 10 10z"/></svg>', 'passiv'],
    ['warteschlange', 'r-warteschlange', '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/></svg>', "warte\u{00AD}schlange"],
];

function jsArg($value): string {
    return htmlspecialchars(
        json_encode((string)$value, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE),
        ENT_QUOTES,
        'UTF-8'
    );
}

// One single DeepSeek call for all AI features
function callDeepSeek(string $prompt): array {
    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL => "https://deepseek-v31.p.rapidapi.com/",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => "",
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => "POST",
        CURLOPT_POSTFIELDS => json_encode([
            'model' => 'DeepSeek-V3.2',
            'messages' => [
                ['role' => 'user', 'content' => $prompt]
            ]
        ]),
        CURLOPT_HTTPHEADER => [
            "Content-Type: application/json",
            "x-rapidapi-host: deepseek-v31.p.rapidapi.com",
            "x-rapidapi-key: " . RAPIDAPI_KEY
        ],
    ]);
    $response = curl_exec($curl);
    $err = curl_error($curl);
    curl_close($curl);

    if ($err) {
        return ['ok' => false, 'error' => "cURL Fehler #: " . $err, 'content' => ''];
    }

    $data = json_decode((string)$response, true);
    $content = '';
    if (isset($data['choices'][0]['message']['content'])) {
        $content = $data['choices'][0]['message']['content'];
    } elseif (isset($data['choices'][0]['text'])) {
        $content = $data['choices'][0]['text'];
    } elseif (isset($data['content'])) {
        $content = $data['content'];
    } else {
        $content = (string)$response;
    }

    return ['ok' => true, 'error' => '', 'content' => trim($content)];
}

// Extract a JSON object from an AI answer (with or without ``` fences)
function parseAiJson(string $content): ?array {
    $cleaned = trim($content);
    if (preg_match('/```json\s*(.*?)\s*```/s', $cleaned, $m)) {
        $cleaned = $m[1];
    } elseif (preg_match('/```\s*(.*?)\s*```/s', $cleaned, $m)) {
        $cleaned = $m[1];
    }
    $parsed = json_decode($cleaned, true);
    return is_array($parsed) ? $parsed : null;
}

// Build the "status / lists / themen / categories" filter used by several games
function buildGameFilter(array $input, bool $requireThema = false): array {
    $selectedStatuses = $input['statuses'] ?? [];
    $selectedLists = $input['sharepoint_lists'] ?? [];
    $selectedThemen = $input['themen'] ?? [];
    $categories = $input['categories'] ?? [];

    $where = "";
    $params = [];

    if ($requireThema && !empty($selectedThemen)) {
        $where .= " AND Thema IS NOT NULL AND Thema != ''";
    }
    foreach ([['Status', $selectedStatuses], ['sharepoint_list', $selectedLists], ['Thema', $selectedThemen], ['Wortarten', $categories]] as [$col, $values]) {
        if (!empty($values) && is_array($values)) {
            $where .= " AND $col IN (" . implode(',', array_fill(0, count($values), '?')) . ")";
            foreach ($values as $v) $params[] = (string)$v;
        }
    }
    return [$where, $params];
}

// Pick a random word from the top of the review queue that matches the game settings.
// $extraWhere restricts the pool further (also applied to the fallback when no word matches the filters).
function pickGameWord(PDO $pdo, int $uid, array $input, string $extraWhere = ''): array {
    $sortOrder = ($input['sort_order'] ?? 'ASC') === 'DESC' ? 'DESC' : 'ASC';
    [$filterWhere, $params] = buildGameFilter($input, true);
    array_unshift($params, $uid);
    $where = "WHERE user_id = ?" . $extraWhere . $filterWhere;

    $hasFilters = !empty($input['statuses']) || !empty($input['sharepoint_lists']) || !empty($input['themen']) || !empty($input['categories']);

    if ($hasFilters) {
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM meine_wortschatz " . $where);
        $countStmt->execute($params);
        $totalMatches = (int)$countStmt->fetchColumn();

        $dynamicLimit = max(1, (int)floor($totalMatches / 2));
    } else {
        $dynamicLimit = 1000;
    }

    $stmt = $pdo->prepare("SELECT * FROM (SELECT " . WORD_COLS . " FROM meine_wortschatz " . $where . " ORDER BY NachsteUbungDatum $sortOrder, Created $sortOrder LIMIT $dynamicLimit) AS subset ORDER BY RAND() LIMIT 1");
    $stmt->execute($params);
    $word = $stmt->fetch(PDO::FETCH_ASSOC);
    $notice = '';

    if (!$word) {
        $fallback = userQuery($pdo, "SELECT * FROM (SELECT " . WORD_COLS . " FROM meine_wortschatz WHERE user_id = ?" . $extraWhere . " ORDER BY NachsteUbungDatum ASC, Created ASC LIMIT 1000) AS subset ORDER BY RAND() LIMIT 1", [$uid]);
        $word = $fallback->fetch(PDO::FETCH_ASSOC);
        $notice = "Keine Wörter gefunden, die den genauen Filtern entsprechen. Stattdessen wird ein zufälliges Wort aus der Warteschlange angezeigt.";
    }

    if ($word) {
        normalizeVerbFlag($word);
    }

    return [$word ?: null, $notice];
}

// --- API / AJAX BACKEND CONTROLLER ---
if (isset($_GET['api'])) {
    $action = $_GET['api'] ?? '';

    if ($action === 'export_csv') {
        if (!$isLoggedIn) {
            http_response_code(401);
            exit;
        }

        if (ob_get_level()) {
            ob_end_clean();
        }

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=meine_wortschatz_export_' . date('Y-m-d') . '.csv');

        $output = fopen('php://output', 'w');
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

        // Only the useful vocabulary columns. Never SELECT * here: image_data
        // holds large base64 images and would blow the PHP memory limit.
        $exportCols = [
            'Wort', 'Artikel', 'Plural', 'Übersetzung', 'Wortarten', 'Thema',
            'Beispiel', 'synonym', 'VerbFlag', 'Konjugation', 'grundverb', 'praefix',
            'praeposition_kollokation', 'sharepoint_list', 'Status', 'Score',
            'NachsteUbungDatum', 'Created', 'Modified',
        ];
        $colSql = implode(', ', array_map(fn($c) => "`$c`", $exportCols));

        fputcsv($output, $exportCols, ';', '"', '\\');

        $stmt = userQuery($pdo, "SELECT $colSql FROM meine_wortschatz WHERE user_id = ? ORDER BY Wort ASC", [$uid]);

        // Stream row by row instead of fetchAll()
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $row['VerbFlag'] = (int)($row['VerbFlag'] ?? 0) === 1 ? 1 : 0;
            $line = [];
            foreach ($exportCols as $c) {
                $v = $row[$c] ?? '';
                // Prevent CSV/Excel formula injection
                if (is_string($v) && $v !== '' && in_array($v[0], ['=', '+', '-', '@'], true)) {
                    $v = "'" . $v;
                }
                $line[] = $v;
            }
            fputcsv($output, $line, ';', '"', '\\');
        }

        fclose($output);
        exit;
    }

    if ($action === 'view_image') {
        // SECURITY FIX: images are only visible to logged-in users
        if (!$isLoggedIn) {
            http_response_code(401);
            exit;
        }

        $word = trim($_GET['word'] ?? '');
        if (empty($word)) {
            http_response_code(400);
            exit('Wort fehlt.');
        }

        $stmt = $pdo->prepare("SELECT image_data, mime_type FROM meine_wortschatz WHERE Wort = ? AND user_id = ? LIMIT 1");
        $stmt->execute([$word, $uid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row && !empty($row['image_data'])) {
            // Only allow real image types
            $allowedMimes = ['image/png', 'image/jpeg', 'image/webp', 'image/gif'];
            $mime = (!empty($row['mime_type']) && in_array($row['mime_type'], $allowedMimes, true)) ? $row['mime_type'] : 'image/png';

            if (ob_get_level()) {
                ob_end_clean();
            }

            header('Content-Type: ' . $mime);
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

            $imageData = $row['image_data'];
            if (strpos($imageData, 'data:image') === 0) {
                $base64String = substr($imageData, strpos($imageData, ',') + 1);
                echo base64_decode($base64String);
            } else {
                echo $imageData;
            }
            exit;
        } else {
            if (ob_get_level()) {
                ob_end_clean();
            }
            http_response_code(204);
            exit;
        }
    }

    header('Content-Type: application/json');
    header('X-Content-Type-Options: nosniff');

    if (!$isLoggedIn) {
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }

    session_write_close();

    if ($action === 'generate_ai_image') {
        $input = jsonInput();
        $word = trim($input['word'] ?? '');
        $translation = trim($input['translation'] ?? '');
        $thema = trim($input['thema'] ?? '');

        if (empty($word)) {
            echo json_encode(['success' => false, 'error' => 'Wort fehlt.']);
            exit;
        }

        if (empty(GEMINI_API_KEY)) {
            echo json_encode(['success' => false, 'error' => 'GEMINI_API_KEY wurde nicht gefunden.']);
            exit;
        }

        $prompt = "Create a clear, detailed, and illustrative visual scene explaining the German vocabulary word '$word' (Translation: '$translation', Theme: '$thema'). High quality, educational, professional photography or clear illustration style.";

        $payload = [
            'contents' => [
                [
                    'parts' => [
                        ['text' => $prompt]
                    ]
                ]
            ],
            'generationConfig' => [
                'responseModalities' => ['IMAGE']
            ]
        ];

        $apiUrl = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash-image:generateContent";

        $ch = curl_init($apiUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 90);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'x-goog-api-key: ' . GEMINI_API_KEY
        ]);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        $response = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);

        if ($err) {
            echo json_encode(['success' => false, 'error' => 'cURL Fehler: ' . $err]);
            exit;
        }

        $responseData = json_decode((string)$response, true);
        $imageBase64 = null;
        $mimeType = 'image/png';

        if (isset($responseData['candidates'][0]['content']['parts'])) {
            foreach ($responseData['candidates'][0]['content']['parts'] as $part) {
                if (isset($part['inlineData'])) {
                    $imageBase64 = $part['inlineData']['data'];
                    $mimeType = $part['inlineData']['mimeType'] ?? 'image/png';
                    break;
                }
            }
        }

        if (empty($imageBase64)) {
            $errorMsg = $responseData['error']['message'] ?? 'Konnte kein Bild von der Google API erhalten.';
            echo json_encode(['success' => false, 'error' => $errorMsg]);
            exit;
        }

        $dataUri = 'data:' . $mimeType . ';base64,' . $imageBase64;

        $update = $pdo->prepare("UPDATE meine_wortschatz SET image_data = ?, mime_type = ? WHERE Wort = ? AND user_id = ?");
        $update->execute([$dataUri, $mimeType, $word, $uid]);

        echo json_encode(['success' => true, 'image_data' => $dataUri]);
        exit;
    }

    if ($action === 'get_data') {
        $filterList = $_GET['sharepoint_list'] ?? '';
        $filterThema = $_GET['thema'] ?? '';
        $filterWortart = $_GET['wortart'] ?? '';
        $filterScore = isset($_GET['score']) && $_GET['score'] !== '' ? $_GET['score'] : '';
        $filterStatus = $_GET['status'] ?? '';
        $filterLetter = $_GET['letter'] ?? '';
        $filterSearch = $_GET['search'] ?? '';
        $filterTransSearch = trim($_GET['trans_search'] ?? '');
        $filterVerbFlag = $_GET['verb_flag'] ?? '';
        $filterGrundverb = $_GET['grundverb'] ?? '';
        $filterPraefix = $_GET['praefix'] ?? '';
        $sort = $_GET['sort'] ?? 'Wort';
        $order = $_GET['order'] ?? 'ASC';
        $offset = max(0, isset($_GET['offset']) ? (int)$_GET['offset'] : 0);
        $limit = 50;

        $allowed_sorts = ['sharepoint_list', 'Thema', 'Wortarten', 'Wort', 'Artikel', 'Plural', 'Score', 'Status', 'Created', 'Modified', 'NachsteUbungDatum', 'VerbFlag', 'Konjugation', 'grundverb', 'praefix'];
        if (!in_array($sort, $allowed_sorts, true)) $sort = 'Wort';
        $order = ($order === 'DESC') ? 'DESC' : 'ASC';

        $query = "SELECT " . WORD_COLS . " FROM meine_wortschatz WHERE user_id = ?";
        $params = [$uid];

        if (!empty($filterLetter)) {
            $query .= " AND Wort LIKE ?";
            $params[] = likeEscape($filterLetter) . "%";
        }
        if (!empty($filterSearch)) {
            $query .= " AND Wort LIKE ?";
            $params[] = likeEscape($filterSearch) . "%";
        }
        if (!empty($filterTransSearch)) {
            $query .= " AND Übersetzung LIKE ?";
            $params[] = "%" . likeEscape($filterTransSearch) . "%";
        }
        if (!empty($filterList)) {
            $query .= " AND sharepoint_list = ?";
            $params[] = $filterList;
        }
        if (!empty($filterThema)) {
            $query .= " AND Thema = ?";
            $params[] = $filterThema;
        }
        if (!empty($filterWortart)) {
            $query .= " AND Wortarten = ?";
            $params[] = $filterWortart;
        }
        if ($filterScore !== '') {
            $query .= " AND Score = ?";
            $params[] = (int)$filterScore;
        }
        if (!empty($filterStatus)) {
            $query .= " AND Status = ?";
            $params[] = $filterStatus;
        }
        if ($filterVerbFlag === '1') {
            $query .= " AND VerbFlag = 1";
        } elseif ($filterVerbFlag === '0') {
            $query .= " AND (VerbFlag = 0 OR VerbFlag IS NULL)";
        }
        if (!empty($filterGrundverb)) {
            $query .= " AND grundverb = ?";
            $params[] = $filterGrundverb;
        }
        if (!empty($filterPraefix)) {
            $query .= " AND praefix = ?";
            $params[] = $filterPraefix;
        }

        $query .= " ORDER BY $sort $order LIMIT $limit OFFSET $offset";

        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $words = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($words as &$w) {
            normalizeVerbFlag($w);
        }
        unset($w);

        echo json_encode(['words' => $words]);
        exit;
    }

    if ($action === 'get_stats') {
        $filterList = $_GET['sharepoint_list'] ?? '';
        $filterThema = $_GET['thema'] ?? '';
        $filterWortart = $_GET['wortart'] ?? '';
        $filterScore = isset($_GET['score']) && $_GET['score'] !== '' ? $_GET['score'] : '';

        $query = "SELECT Status, COUNT(*) as count FROM meine_wortschatz WHERE user_id = ?";
        $params = [$uid];

        if (!empty($filterList)) {
            $query .= " AND sharepoint_list = ?";
            $params[] = $filterList;
        }
        if (!empty($filterThema)) {
            $query .= " AND Thema = ?";
            $params[] = $filterThema;
        }
        if (!empty($filterWortart)) {
            $query .= " AND Wortarten = ?";
            $params[] = $filterWortart;
        }
        if ($filterScore !== '') {
            $query .= " AND Score = ?";
            $params[] = (int)$filterScore;
        }

        $query .= " GROUP BY Status ORDER BY FIELD(Status, 'aktiva', 'wiederholen', 'neu', 'passiv', 'warteschlange')";

        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $stats = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['stats' => $stats]);
        exit;
    }

    if ($action === 'get_metadata') {
        try {
            $lists = userQuery($pdo, "SELECT DISTINCT sharepoint_list FROM meine_wortschatz WHERE user_id = ? AND sharepoint_list IS NOT NULL AND sharepoint_list != '' ORDER BY sharepoint_list ASC", [$uid])->fetchAll(PDO::FETCH_COLUMN) ?: [];
            $themen = userQuery($pdo, "SELECT DISTINCT Thema FROM meine_wortschatz WHERE user_id = ? AND Thema IS NOT NULL AND Thema != '' ORDER BY Thema ASC", [$uid])->fetchAll(PDO::FETCH_COLUMN) ?: [];
            $wortarten = userQuery($pdo, "SELECT DISTINCT Wortarten FROM meine_wortschatz WHERE user_id = ? AND Wortarten IS NOT NULL AND Wortarten != '' ORDER BY Wortarten ASC", [$uid])->fetchAll(PDO::FETCH_COLUMN) ?: [];
            $scores = userQuery($pdo, "SELECT DISTINCT Score FROM meine_wortschatz WHERE user_id = ? AND Score IS NOT NULL ORDER BY Score ASC", [$uid])->fetchAll(PDO::FETCH_COLUMN) ?: [];
            $grundverben = userQuery($pdo, "SELECT DISTINCT grundverb FROM meine_wortschatz WHERE user_id = ? AND grundverb IS NOT NULL AND grundverb != '' ORDER BY grundverb ASC", [$uid])->fetchAll(PDO::FETCH_COLUMN) ?: [];
            $praefixe = userQuery($pdo, "SELECT DISTINCT praefix FROM meine_wortschatz WHERE user_id = ? AND praefix IS NOT NULL AND praefix != '' ORDER BY praefix ASC", [$uid])->fetchAll(PDO::FETCH_COLUMN) ?: [];

            $statusesStmt = userQuery($pdo, "SELECT DISTINCT Status FROM meine_wortschatz WHERE user_id = ? AND Status IS NOT NULL AND Status != '' ORDER BY FIELD(Status, 'aktiva', 'wiederholen', 'neu', 'passiv', 'warteschlange')", [$uid]);
            $statuses = $statusesStmt->fetchAll(PDO::FETCH_COLUMN) ?: [];

            $mappingStmt = userQuery($pdo, "SELECT DISTINCT Thema, Wortarten FROM meine_wortschatz WHERE user_id = ? AND Thema IS NOT NULL AND Thema != '' AND Wortarten IS NOT NULL AND Wortarten != '' ORDER BY Thema ASC, Wortarten ASC", [$uid]);
            $pairs = $mappingStmt->fetchAll(PDO::FETCH_ASSOC);
            $themaWortartenMap = [];
            foreach ($pairs as $p) {
                $thm = $p['Thema'];
                $wa = $p['Wortarten'];
                if (!isset($themaWortartenMap[$thm])) {
                    $themaWortartenMap[$thm] = [];
                }
                if (!in_array($wa, $themaWortartenMap[$thm])) {
                    $themaWortartenMap[$thm][] = $wa;
                }
            }

            $aktivaCount = (int)userQuery($pdo, "SELECT COUNT(*) FROM meine_wortschatz WHERE user_id = ? AND Status = 'aktiva'", [$uid])->fetchColumn();

            echo json_encode([
                'success' => true,
                'lists' => $lists,
                'themen' => $themen,
                'wortarten' => $wortarten,
                'scores' => $scores,
                'statuses' => $statuses,
                'grundverben' => $grundverben,
                'praefixe' => $praefixe,
                'themaWortartenMap' => $themaWortartenMap,
                'aktiva_count' => $aktivaCount
            ]);
        } catch (Exception $e) {
            error_log($e->getMessage());
            echo json_encode(['success' => false, 'error' => 'Metadaten konnten nicht geladen werden.']);
        }
        exit;
    }

    if ($action === 'save') {
        $input = jsonInput();
        $sharepointList = trim($input['sharepoint_list'] ?? '');
        $thema = trim($input['Thema'] ?? '');
        $wort = trim($input['Wort'] ?? '');
        $artikel = trim($input['Artikel'] ?? '');
        $verbFlag = (isset($input['VerbFlag']) && (int)$input['VerbFlag'] === 1) ? 1 : 0;
        $plural = $verbFlag === 1 ? null : trim($input['Plural'] ?? '');
        $uebersetzung = trim($input['Übersetzung'] ?? '');
        $synonym = trim($input['synonym'] ?? '');
        $wortarten = trim($input['Wortarten'] ?? '');
        $beispiel = trim($input['Beispiel'] ?? '');
        // The score is never typed in: the form only sends an optional Kenntnisse rating
        $kenntnisse = trim($input['Kenntnisse'] ?? '');
        if (!in_array($kenntnisse, ['direkt_aktiv', 'sehr_gut', 'yes', 'wiederholen', 'passiv', 'warteschlange'], true)) {
            $kenntnisse = '';
        }

        $konjugation = trim($input['Konjugation'] ?? '');
        $grundverb = $verbFlag === 1 ? trim($input['grundverb'] ?? '') : null;
        $praefix = $verbFlag === 1 ? trim($input['praefix'] ?? '') : null;
        $praeposition_kollokation = $verbFlag === 1 ? trim($input['praeposition_kollokation'] ?? '') : null;
        $originalWort = trim($input['original_wort'] ?? '');

        if (empty($wort)) {
            echo json_encode(['success' => false, 'message' => 'Das Wort darf nicht leer sein.']);
            exit;
        }

        try {
        // Current score: 0 for a new word, the stored one when editing
        $score = 0;
        if (!empty($originalWort)) {
            $cur = $pdo->prepare("SELECT Score FROM meine_wortschatz WHERE Wort = ? AND user_id = ?");
            $cur->execute([$originalWort, $uid]);
            $score = (int)$cur->fetchColumn();
        }
        $status = statusFromScore($score);

        if (!empty($originalWort)) {
            $stmt = $pdo->prepare("UPDATE meine_wortschatz SET sharepoint_list = ?, Thema = ?, Wort = ?, Artikel = ?, Plural = ?, Übersetzung = ?, synonym = ?, Wortarten = ?, Beispiel = ?, Score = ?, Status = ?, VerbFlag = ?, Konjugation = ?, grundverb = ?, praefix = ?, praeposition_kollokation = ?, Modified = NOW() WHERE Wort = ? AND user_id = ?");
            $stmt->execute([$sharepointList, $thema, $wort, $artikel, $plural, $uebersetzung, $synonym, $wortarten, $beispiel, $score, $status, $verbFlag, $konjugation, $grundverb, $praefix, $praeposition_kollokation, $originalWort, $uid]);
            if ($kenntnisse !== '') saveWordScore($pdo, $uid, $wort, scoreAfterRating($score, $kenntnisse));
            echo json_encode(['success' => true, 'message' => "Wort '$wort' erfolgreich aktualisiert!"]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO meine_wortschatz (user_id, sharepoint_list, Thema, Wort, Artikel, Plural, Übersetzung, synonym, Wortarten, Beispiel, Score, Status, VerbFlag, Konjugation, grundverb, praefix, praeposition_kollokation, Created, Modified, NachsteUbungDatum) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), NOW())");
            $stmt->execute([$uid, $sharepointList, $thema, $wort, $artikel, $plural, $uebersetzung, $synonym, $wortarten, $beispiel, $score, $status, $verbFlag, $konjugation, $grundverb, $praefix, $praeposition_kollokation]);
            if ($kenntnisse !== '') saveWordScore($pdo, $uid, $wort, scoreAfterRating($score, $kenntnisse));
            echo json_encode(['success' => true, 'message' => "Wort '$wort' erfolgreich hinzugefügt!"]);
        }
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                echo json_encode(['success' => false, 'message' => "Das Wort '$wort' existiert bereits in deinem Wortschatz."]);
            } else {
                error_log($e->getMessage());
                echo json_encode(['success' => false, 'message' => 'Speichern fehlgeschlagen.']);
            }
        }
        exit;
    }

    if ($action === 'delete') {
        $input = jsonInput();
        $wortToDelete = $input['Wort'] ?? '';
        if (!empty($wortToDelete)) {
            $stmt = $pdo->prepare("DELETE FROM meine_wortschatz WHERE Wort = ? AND user_id = ?");
            $stmt->execute([$wortToDelete, $uid]);
            echo json_encode(['success' => true, 'message' => "Wort '$wortToDelete' gelöscht."]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Ungültiges Wort.']);
        }
        exit;
    }

    if ($action === 'direct_promote') {
        $input = jsonInput();
        $wortToPromote = trim($input['Wort'] ?? '');
        if (!empty($wortToPromote)) {
            saveWordScore($pdo, $uid, $wortToPromote, 10);
            echo json_encode(['success' => true, 'message' => "Wort '$wortToPromote' direkt zu 'aktiva' (Score 10) befördert!"]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Ungültiges Wort.']);
        }
        exit;
    }

    if ($action === 'ai_fill_word') {
        $input = jsonInput();
        $wort = trim($input['word'] ?? '');

        if (empty($wort)) {
            echo json_encode(['success' => false, 'error' => 'Bitte gib zuerst ein Wort ein.']);
            exit;
        }

        $userPrompt = "Analysiere das deutsche Wort '$wort' und antworte AUSSCHLIESSLICH als gültiges JSON-Objekt mit exakt diesen Schlüsseln:\n" .
                    "{\n" .
                    "  \"thema\": \"Passendes Thema\",\n" .
                    "  \"kategorie\": \"Wortart / Unterkategorie (z.B. Nomen, Verb, Adjektiv)\",\n" .
                    "  \"ist_verb\": true/false,\n" .
                    "  \"konjugation\": \"Nur wenn Verb: z.B. er läuft, lief, ist gelaufen (sonst leer)\",\n" .
                    "  \"grundverb\": \"Nur wenn Verb: das unpräfigierte Grundverb sofern vorhanden, z.B. bei 'interessieren' oder 'sich interessieren' -> 'interessieren' (sonst leer)\",\n" .
                    "  \"praefix\": \"Nur wenn Verb: das Verbpräfix sofern vorhanden, z.B. bei 'aufstehen' -> 'auf' (sonst leer)\",\n" .
                    "  \"praeposition_kollokation\": \"Nur wenn Verb: Präpositionalkollokation mit fett markierter Präposition oder Beispielspräposition, z.B. 'sich interessieren **für**' oder 'warten **auf**' (sonst leer)\",\n" .
                    "  \"artikel\": \"Nur wenn kein Verb und Artikel existiert: z.B. der, die, das (sonst leer)\",\n" .
                    "  \"plural\": \"Nur wenn kein Verb und Plural existiert: z.B. die Autos, die Häuser (sonst leer)\",\n" .
                    "  \"uebersetzung\": \"Englisch: ..., Französisch: ..., Italienisch: ...\",\n" .
                    "  \"synonym\": \"Synonym auf Deutsch (oder leer)\",\n" .
                    "  \"beispiel\": \"Beispielsatz auf Deutsch und Übersetzung auf Französisch\"\n" .
                    "}";

        $ai = callDeepSeek($userPrompt);
        if (!$ai['ok']) {
            echo json_encode(['success' => false, 'error' => $ai['error']]);
            exit;
        }

        $parsedData = parseAiJson($ai['content']);
        if (!$parsedData) {
            echo json_encode(['success' => true, 'raw' => $ai['content']]);
        } else {
            echo json_encode(['success' => true, 'ai_data' => $parsedData]);
        }
        exit;
    }

    if ($action === 'der_die_das_next') {
        [$word, $notice] = pickGameWord($pdo, $uid, jsonInput(), " AND Artikel IN ('der', 'die', 'das')");

        if ($word) {
            echo json_encode(['success' => true, 'word' => $word, 'notice' => $notice]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Keine Wörter mit Artikel (der, die, das) gefunden.']);
        }
        exit;
    }

    if ($action === 'deutsch_meister_next') {
        [$word, $notice] = pickGameWord($pdo, $uid, jsonInput());

        if ($word) {
            echo json_encode(['success' => true, 'word' => $word, 'notice' => $notice]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Keine Wörter gefunden.']);
        }
        exit;
    }

    if ($action === 'deutsch_meister_check_sentence') {
        $input = jsonInput();
        $sentence = trim($input['sentence'] ?? '');
        $word = trim($input['word'] ?? '');

        if (empty($sentence) || empty($word)) {
            echo json_encode(['success' => false, 'error' => 'Satz oder Wort fehlt.']);
            exit;
        }

        $userPrompt = "Überprüfe den folgenden Beispielsatz für das deutsche Wort '$word'.\n" .
                    "Beispielsatz: '$sentence'\n\n" .
                    "Bewerte nach folgenden Kriterien:\n" .
                    "1. Ist der Satz grammatikalisch und inhaltlich im perfekten Kontext ('ok')?\n" .
                    "2. Ist der Satz nur falsch aus der Grammatik-Perspektive ('grammar_wrong')?\n" .
                    "3. Wurde das vorgeschlagene Wort in einem völlig falschen Kontext verwendet ('wrong_context')?\n\n" .
                    "Antworte AUSSCHLIESSLICH als gültiges JSON-Objekt mit exakt diesen Schlüsseln:\n" .
                    "{\n" .
                    "  \"evaluation_result\": \"ok\" oder \"grammar_wrong\" oder \"wrong_context\",\n" .
                    "  \"feedback\": \"Deine Korrektur oder Erklärung auf Deutsch\"\n" .
                    "}";

        $ai = callDeepSeek($userPrompt);
        if (!$ai['ok']) {
            echo json_encode(['success' => false, 'error' => $ai['error']]);
            exit;
        }

        $parsedData = parseAiJson($ai['content']);
        $evalResult = 'grammar_wrong';
        $feedback = "";

        if ($parsedData && isset($parsedData['evaluation_result'])) {
            $evalResult = $parsedData['evaluation_result'];
            $feedback = $parsedData['feedback'] ?? '';
        } else {
            $feedback = $ai['content'];
        }

        $stmt = $pdo->prepare("SELECT Score, Status FROM meine_wortschatz WHERE Wort = ? AND user_id = ?");
        $stmt->execute([$word, $uid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $isAktiva = $row && statusFromScore((int)$row['Score']) === 'aktiva';

        $points = 0;
        if (!$isAktiva) {
            // Words that are not aktiva yet: +1 for a correct sentence, nothing otherwise
            $points = ($evalResult === 'ok') ? 1 : 0;
        } elseif ($evalResult === 'ok') {
            $letterCount = mb_strlen($sentence);
            if ($letterCount <= 21) {
                $points = 9;
            } else {
                $points = (int)ceil($letterCount / 3);
            }
        } elseif ($evalResult === 'wrong_context') {
            $points = -1;
        } else {
            $points = 1;
        }

        if ($row) {
            $currentScore = (int)$row['Score'];
            $newScore = $points === 0 ? $currentScore : applyScorePoints($currentScore, $points);
            saveWordScore($pdo, $uid, $word, $newScore);
        }

        $detailStmt = $pdo->prepare("SELECT " . WORD_COLS . " FROM meine_wortschatz WHERE Wort = ? AND user_id = ? LIMIT 1");
        $detailStmt->execute([$word, $uid]);
        $wordDetails = $detailStmt->fetch(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'evaluation_result' => $evalResult,
            'points' => $points,
            'feedback' => trim($feedback),
            'word_details' => $wordDetails
        ]);
        exit;
    }

    if ($action === 'der_die_das_answer') {
        $input = jsonInput();
        $wort = trim($input['wort'] ?? '');
        $guessedArtikel = strtolower(trim($input['artikel'] ?? ''));
        $currentStreak = (int)($input['current_streak'] ?? 0);

        if (empty($wort)) {
            echo json_encode(['success' => false, 'error' => 'Ungültiges Wort.']);
            exit;
        }

        $stmt = $pdo->prepare("SELECT Artikel, Score, Status FROM meine_wortschatz WHERE Wort = ? AND user_id = ?");
        $stmt->execute([$wort, $uid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            echo json_encode(['success' => false, 'error' => 'Wort nicht gefunden.']);
            exit;
        }

        $correctArtikel = strtolower(trim($row['Artikel'] ?? ''));
        $currentScore = (int)$row['Score'];
        $isCorrect = ($guessedArtikel === $correctArtikel);

        $isAktiva = (statusFromScore($currentScore) === 'aktiva');
        $superBoosterTriggered = false;

        if ($isCorrect) {
            $currentStreak++;
            if (!$isAktiva) {
                // Words that are not aktiva yet: +1 for a correct answer
                $points = 1;
            } elseif ($currentStreak % 10 === 0) {
                $superBoosterTriggered = true;
                $points = 9;
            } else {
                $points = 3;
            }
        } else {
            $currentStreak = 0;
            // Words that are not aktiva yet lose nothing on a wrong answer
            $points = $isAktiva ? -1 : 0;
        }
        $newScore = $points === 0 ? $currentScore : applyScorePoints($currentScore, $points);

        $newStatus = saveWordScore($pdo, $uid, $wort, $newScore);

        echo json_encode([
            'success' => true,
            'is_correct' => $isCorrect,
            'correct_artikel' => $correctArtikel,
            'points' => $points,
            'new_score' => $newScore,
            'new_status' => $newStatus,
            'new_streak' => $currentStreak,
            'super_booster' => $superBoosterTriggered
        ]);
        exit;
    }

    if ($action === 'der_die_das_check_sentence' || $action === 'check_sentence_booster') {
        $input = jsonInput();
        $sentence = trim($input['sentence'] ?? '');
        $word = trim($input['word'] ?? '');
        $previousWasRight = !empty($input['previous_was_right']);

        if (empty($sentence) || empty($word)) {
            echo json_encode(['success' => false, 'error' => 'Satz oder Wort fehlt.']);
            exit;
        }

        $userPrompt = "Überprüfe den folgenden Beispielsatz für das Wort '$word'.\n" .
                    "Beispielsatz: '$sentence'\n\n" .
                    "Bewerte nach folgenden Kriterien:\n" .
                    "1. Ist der Satz grammatikalisch korrekt?\n" .
                    "2. Ist der Satz idiomatischer Natur oder nur verständlich, aber nicht perfekt idiomatisch?\n\n" .
                    "Antworte AUSSCHLIESSLICH als gültiges JSON-Objekt mit exakt diesen Schlüsseln:\n" .
                    "{\n" .
                    "  \"is_grammatically_correct\": true/false,\n" .
                    "  \"is_idiomatically_correct\": true/false,\n" .
                    "  \"feedback\": \"Deine kurze Rückmeldung oder Korrektur auf Deutsch\"\n" .
                    "}";

        $ai = callDeepSeek($userPrompt);
        if (!$ai['ok']) {
            echo json_encode(['success' => false, 'error' => $ai['error']]);
            exit;
        }

        $parsedData = parseAiJson($ai['content']);
        $isGrammaticallyCorrect = false;
        $isIdiomaticallyCorrect = false;
        $feedbackMessage = "";

        if ($parsedData && isset($parsedData['is_grammatically_correct'])) {
            $isGrammaticallyCorrect = ($parsedData['is_grammatically_correct'] === true || strtolower((string)$parsedData['is_grammatically_correct']) === 'true');
            $isIdiomaticallyCorrect = isset($parsedData['is_idiomatically_correct']) && ($parsedData['is_idiomatically_correct'] === true || strtolower((string)$parsedData['is_idiomatically_correct']) === 'true');
            $feedbackMessage = $parsedData['feedback'] ?? '';
        } else {
            $feedbackMessage = $ai['content'];
        }

        $pointsToAdd = 0;

        $stmt = $pdo->prepare("SELECT Score, Status FROM meine_wortschatz WHERE Wort = ? AND user_id = ?");
        $stmt->execute([$word, $uid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            $currentScore = (int)$row['Score'];
            $isAktiva = (statusFromScore($currentScore) === 'aktiva');

            if ($action === 'der_die_das_check_sentence' && !$isAktiva) {
                // Words that are not aktiva yet: +1 for a correct sentence, nothing otherwise
                $pointsToAdd = $isGrammaticallyCorrect ? 1 : 0;
            } elseif ($isGrammaticallyCorrect && $isIdiomaticallyCorrect && $isAktiva) {
                if ($action === 'check_sentence_booster') {
                    $pointsToAdd = 9;
                } else {
                    $pointsToAdd = $previousWasRight ? 9 : 3;
                }
            } else {
                $pointsToAdd = 1;
            }

            $newScore = $pointsToAdd === 0 ? $currentScore : applyScorePoints($currentScore, $pointsToAdd);
            saveWordScore($pdo, $uid, $word, $newScore);
        }

        echo json_encode([
            'success' => true,
            'is_perfect' => $isGrammaticallyCorrect,
            'is_idiomatic' => $isIdiomaticallyCorrect,
            'points_added' => $pointsToAdd,
            'correction' => trim($feedbackMessage)
        ]);
        exit;
    }

    if ($action === 'game_next') {
        $input = jsonInput();

        $specificWord = trim($input['specific_word'] ?? '');

        if (!empty($specificWord)) {
            $stmt = $pdo->prepare("SELECT " . WORD_COLS . " FROM meine_wortschatz WHERE Wort = ? AND user_id = ? LIMIT 1");
            $stmt->execute([$specificWord, $uid]);
            $word = $stmt->fetch(PDO::FETCH_ASSOC);
            $notice = "Spezifisches Wort wird geübt: '$specificWord'";

            if (!$word) {
                $notice = "Spezifisches Wort nicht gefunden. Stattdessen wird ein zufälliges Wort angezeigt.";
                $fallback = userQuery($pdo, "SELECT * FROM (SELECT " . WORD_COLS . " FROM meine_wortschatz WHERE user_id = ? ORDER BY NachsteUbungDatum ASC, Created ASC LIMIT 1000) AS subset ORDER BY RAND() LIMIT 1", [$uid]);
                $word = $fallback->fetch(PDO::FETCH_ASSOC);
            }

            if ($word) {
                normalizeVerbFlag($word);
            }

            echo json_encode(['mode' => 'standard', 'word' => $word, 'notice' => $notice]);
            exit;
        }

        [$word, $notice] = pickGameWord($pdo, $uid, $input);

        echo json_encode(['mode' => 'standard', 'word' => $word, 'notice' => $notice]);
        exit;
    }

    if ($action === 'game_answer') {
        $input = jsonInput();
        $wort = $input['wort'] ?? '';
        $result = $input['result'] ?? '';

        if (!empty($wort)) {
            $stmt = $pdo->prepare("SELECT Score, Status FROM meine_wortschatz WHERE Wort = ? AND user_id = ?");
            $stmt->execute([$wort, $uid]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($row) {
                $newScore = scoreAfterRating((int)$row['Score'], $result === 'direkt_aktiv' ? '' : $result);

                saveWordScore($pdo, $uid, $wort, $newScore);
            }
        }
        echo json_encode(['success' => true]);
        exit;
    }

    // Unknown action
    echo json_encode(['success' => false, 'error' => 'Unbekannte Aktion.']);
    exit;
}

// Ensure login check for page render
if (!$isLoggedIn) {
    header('Location: login.php');
    exit;
}

try {
    $todayCountStmt = userQuery($pdo, "SELECT COUNT(*) FROM meine_wortschatz WHERE user_id = ? AND DATE(Modified) = CURDATE()", [$uid]);
    $todayReviewedCount = (int)$todayCountStmt->fetchColumn();
} catch (Exception $e) {
    $todayReviewedCount = 0;
}

try {
    // Make sure every stored status matches its score (fixes older rows)
    syncStatusesFromScore($pdo, $uid);

    $initialAktivaCount = (int)userQuery($pdo, "SELECT COUNT(*) FROM meine_wortschatz WHERE user_id = ? AND Status = 'aktiva'", [$uid])->fetchColumn();

    $initialWords = userQuery($pdo, "SELECT " . WORD_COLS . " FROM meine_wortschatz WHERE user_id = ? ORDER BY Wort ASC LIMIT 50", [$uid])->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($initialWords as &$w) {
        normalizeVerbFlag($w);
    }
    unset($w);

    $initialLists = userQuery($pdo, "SELECT DISTINCT sharepoint_list FROM meine_wortschatz WHERE user_id = ? AND sharepoint_list IS NOT NULL AND sharepoint_list != '' ORDER BY sharepoint_list ASC", [$uid])->fetchAll(PDO::FETCH_COLUMN) ?: [];
    $initialThemen = userQuery($pdo, "SELECT DISTINCT Thema FROM meine_wortschatz WHERE user_id = ? AND Thema IS NOT NULL AND Thema != '' ORDER BY Thema ASC", [$uid])->fetchAll(PDO::FETCH_COLUMN) ?: [];
    $initialWortarten = userQuery($pdo, "SELECT DISTINCT Wortarten FROM meine_wortschatz WHERE user_id = ? AND Wortarten IS NOT NULL AND Wortarten != '' ORDER BY Wortarten ASC", [$uid])->fetchAll(PDO::FETCH_COLUMN) ?: [];
    $initialScores = userQuery($pdo, "SELECT DISTINCT Score FROM meine_wortschatz WHERE user_id = ? AND Score IS NOT NULL ORDER BY Score ASC", [$uid])->fetchAll(PDO::FETCH_COLUMN) ?: [];
    $initialGrundverben = userQuery($pdo, "SELECT DISTINCT grundverb FROM meine_wortschatz WHERE user_id = ? AND grundverb IS NOT NULL AND grundverb != '' ORDER BY grundverb ASC", [$uid])->fetchAll(PDO::FETCH_COLUMN) ?: [];
    $initialPraefixe = userQuery($pdo, "SELECT DISTINCT praefix FROM meine_wortschatz WHERE user_id = ? AND praefix IS NOT NULL AND praefix != '' ORDER BY praefix ASC", [$uid])->fetchAll(PDO::FETCH_COLUMN) ?: [];

    $initialStatusesStmt = userQuery($pdo, "SELECT DISTINCT Status FROM meine_wortschatz WHERE user_id = ? AND Status IS NOT NULL AND Status != '' ORDER BY FIELD(Status, 'aktiva', 'wiederholen', 'neu', 'passiv', 'warteschlange')", [$uid]);
    $initialStatuses = $initialStatusesStmt->fetchAll(PDO::FETCH_COLUMN) ?: [];

    $initialMappingStmt = userQuery($pdo, "SELECT DISTINCT Thema, Wortarten FROM meine_wortschatz WHERE user_id = ? AND Thema IS NOT NULL AND Thema != '' AND Wortarten IS NOT NULL AND Wortarten != '' ORDER BY Thema ASC, Wortarten ASC", [$uid]);
    $initialPairs = $initialMappingStmt->fetchAll(PDO::FETCH_ASSOC);
    $initialThemaWortartenMap = [];
    foreach ($initialPairs as $p) {
        $thm = $p['Thema'];
        $wa = $p['Wortarten'];
        if (!isset($initialThemaWortartenMap[$thm])) {
            $initialThemaWortartenMap[$thm] = [];
        }
        if (!in_array($wa, $initialThemaWortartenMap[$thm])) {
            $initialThemaWortartenMap[$thm][] = $wa;
        }
    }
} catch (Exception $e) {
    error_log($e->getMessage());
    $initialWords = [];
    $initialLists = [];
    $initialThemen = [];
    $initialWortarten = [];
    $initialScores = [];
    $initialGrundverben = [];
    $initialPraefixe = [];
    $initialStatuses = [];
    $initialThemaWortartenMap = [];
    $initialAktivaCount = 0;
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php theme_head(); ?>
    <title>vokabiq</title>

    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Nunito:wght@800&text=vokabiqABCDEFGHIJKLMNOPQRSTUVWXYZ%C3%84%C3%96%C3%9C&display=swap">

    <meta name="theme-color" content="#1e88e5">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="vokabiq">
    <link rel="apple-touch-icon" href="data:image/jpeg;base64,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">

    <link rel="icon" type="image/png" sizes="32x32" href="data:image/png;base64,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">

    <script src="https://cdn.jsdelivr.net/npm/chart.js" defer></script>

    <style>
        :root {
            --md-primary: #3f7fd6;
            --md-primary-dark: #3169b8;
            --md-primary-light: #0d233a;
            --md-bg: #121212;
            --md-surface: #1e1e1e;
            --md-surface-card: #252525;
            --md-on-surface: #e0e0e0;
            --md-text-muted: #a0a0a0;
            --md-border: #333333;
            --md-danger: #e53935;
            --md-danger-dark: #c62828;
            --md-passiv-grey: #9e9e9e;
            --md-passiv-grey-dark: #757575;
            --md-success: #26a69a;
            --md-success-dark: #00897b;
            --md-aktiva-green: #ec407a;
            --md-aktiva-green-dark: #c2185b;
            --md-warning-yellow: #ffb74d;
            --md-elevation-1: 0 1px 3px rgba(0,0,0,0.5), 0 1px 2px rgba(0,0,0,0.4);
            --md-elevation-2: 0 3px 6px rgba(0,0,0,0.6), 0 2px 4px rgba(0,0,0,0.5);

            /* Theme-dependent colors (dark defaults) */
            --md-accent-text: #90caf9;
            --md-on-accent: #0d2a42;
            --md-control-bg: #303030;
            --md-control-hover: #3a3a3a;
            --md-control-border: #454545;
            --md-control-border-hover: #666666;
            --md-popover-bg: #252525;
            --md-menu-hover: #383838;
            --md-hover-bg: #282828;
            --md-subtle-hover: rgba(255,255,255,0.03);
            --md-th-bg: #242424;
            --md-track: #333333;
            --md-avatar-bg: linear-gradient(140deg, #ff5c93 0%, #ef2b6f 55%, #c8175a 100%);
            --md-avatar-text: #ffffff;
            --md-avatar-ring: rgba(239,43,111,0.45);
            --md-logout-text: #ff8a80;
            --md-logout-hover: #3b2424;
            --md-der: #64b5f6;
            --md-die: #f06292;
            --md-das: #ffb74d;
            --md-article-other: #b0b0b0;
            --md-text-purple: #b39ddb;
            --md-text-success: #81c784;
            --md-notice-bg: #332701;
            --md-notice-text: #ffecb3;
            --md-notice-border: #795548;
        }

        /* ===== Light theme ===== */
        :root[data-theme="light"] {
            --md-primary-light: #e3f2fd;
            --md-bg: #d5d9de;
            --md-surface: #e4e7eb;
            --md-surface-card: #dde1e5;
            --md-on-surface: #1f2933;
            --md-text-muted: #5f6b7a;
            --md-border: #c8ced5;
            --md-warning-yellow: #e68a00;
            --md-elevation-1: 0 1px 3px rgba(0,0,0,0.08), 0 1px 2px rgba(0,0,0,0.06);
            --md-elevation-2: 0 3px 6px rgba(0,0,0,0.10), 0 2px 4px rgba(0,0,0,0.08);

            --md-accent-text: #1565c0;
            --md-on-accent: #ffffff;
            --md-control-bg: #dadee3;
            --md-control-hover: #d0d5db;
            --md-control-border: #d0d7de;
            --md-control-border-hover: #b8c1cb;
            --md-popover-bg: #e8ebee;
            --md-menu-hover: #dbe0e5;
            --md-hover-bg: #d9e2ec;
            --md-subtle-hover: rgba(0,0,0,0.04);
            --md-th-bg: #d3d8de;
            --md-track: #cdd3da;
            --md-avatar-bg: linear-gradient(140deg, #ff5c93 0%, #ef2b6f 55%, #c8175a 100%);
            --md-avatar-text: #ffffff;
            --md-avatar-ring: rgba(239,43,111,0.35);
            --md-logout-text: #c62828;
            --md-logout-hover: #fdecea;
            --md-der: #1976d2;
            --md-die: #c2185b;
            --md-das: #e65100;
            --md-article-other: #616161;
            --md-text-purple: #6a1b9a;
            --md-text-success: #2e7d32;
            --md-notice-bg: #fff8e1;
            --md-notice-text: #6d4c00;
            --md-notice-border: #ffcc80;
        }
        * { box-sizing: border-box; }
        body {
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background-color: var(--md-bg); color: var(--md-on-surface);
            margin: 0; padding: 0; -webkit-text-size-adjust: 100%;
        }
        .container { max-width: 1750px; margin: 0 auto; padding: 24px 16px; }

        header {
            display: flex; justify-content: space-between; align-items: center;
            background: var(--md-surface); padding: 16px 24px; border-radius: 12px;
            box-shadow: var(--md-elevation-1); margin-bottom: 1.5rem; flex-wrap: wrap; gap: 10px;
            border: 1px solid var(--md-border);
        }
        .header-actions { display: flex; gap: 12px; align-items: center; flex-wrap: wrap; margin-left: auto; }
        .account-menu { position: relative; }
        .account-trigger {
            display: inline-flex; align-items: center; justify-content: center; gap: 0;
            width: 38px; height: 38px; padding: 0; border: 0; border-radius: 50%;
            background: transparent; color: var(--md-on-surface); cursor: pointer; list-style: none;
            font: inherit; font-size: 0.9rem; font-weight: 600;
        }
        .account-trigger::-webkit-details-marker { display: none; }
        .account-trigger:hover .account-avatar, .account-menu[open] .account-avatar { box-shadow: 0 0 0 3px var(--md-avatar-ring), 0 2px 6px rgba(239,43,111,0.35); transform: scale(1.04); }
        .account-trigger:focus-visible, .account-menu-item:focus-visible { outline: 2px solid var(--md-accent-text); outline-offset: 2px; }
        .account-avatar {
            display: grid; place-items: center; width: 36px; height: 36px; border-radius: 50%;
            background: var(--md-avatar-bg); color: var(--md-avatar-text); font-size: 1.05rem; line-height: 1;
            font-family: "Nunito", "Arial Rounded MT Bold", system-ui, sans-serif; font-weight: 800;
            box-shadow: 0 1px 3px rgba(239,43,111,0.30); transition: box-shadow 0.15s, transform 0.15s;
        }
        /* label stays readable for screen readers, chevron hidden: icon-only Konto button */
        .account-trigger .account-label {
            position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px;
            overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; border: 0;
        }
        .account-trigger .account-chevron { display: none; }
        .account-chevron {
            width: 7px; height: 7px; margin: -4px 0 0 3px;
            border-right: 1.5px solid currentColor; border-bottom: 1.5px solid currentColor;
            transform: rotate(45deg); transition: transform 0.18s ease;
        }
        .account-menu[open] .account-chevron { margin-top: 4px; transform: rotate(225deg); }
        .account-menu-identity { padding: 9px 10px 12px; border-bottom: 1px solid var(--md-border); }
        .account-menu-caption { display: block; margin-bottom: 4px; color: var(--md-text-muted); font-size: 0.75rem; }
        .account-menu-identity strong { display: block; overflow: hidden; color: var(--md-on-surface); font-size: 0.9rem; text-overflow: ellipsis; }
        .account-menu-item {
            display: flex; align-items: center; min-height: 42px; margin-top: 5px; padding: 0 10px;
            border-radius: 6px; color: var(--md-on-surface); text-decoration: none; font-size: 0.9rem;
        }
        button.account-menu-item { width: 100%; border: 0; background: transparent; font: inherit; font-size: 0.9rem; text-align: left; cursor: pointer; }
        .account-menu-item:hover { background: var(--md-menu-hover); }
        .account-menu-theme { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 8px 10px 10px; border-bottom: 1px solid var(--md-border); margin-bottom: 4px; font-size: 0.9rem; }
        .theme-label-light { display: none; }
        :root[data-theme="light"] .theme-label-dark { display: none; }
        :root[data-theme="light"] .theme-label-light { display: inline; }
        .account-menu-logout { color: var(--md-logout-text); }
        .account-menu-logout:hover { background: var(--md-logout-hover); }
        .header-title { display: flex; align-items: center; gap: 10px; min-width: 0; }
        .back-icon {
            display: inline-grid; place-items: center; flex-shrink: 0;
            width: 40px; height: 40px; padding: 0; border: 0; border-radius: 50%;
            background: transparent; color: var(--md-text-muted); cursor: pointer;
            transition: background-color 0.15s, color 0.15s;
        }
        .back-icon svg { width: 22px; height: 22px; }
        .back-icon:hover { background: var(--md-control-hover); color: var(--md-accent-text); }
        .back-icon:focus-visible { outline: 2px solid var(--md-accent-text); outline-offset: 2px; }
        @media (max-width: 600px) {
            .container { padding: 16px 12px; }
            header { padding: 10px 12px; flex-wrap: nowrap; }
            header h1 { font-size: 1.15rem; min-width: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
            .header-actions { flex-shrink: 0; }
        }
        .wordmark { font-family: "Nunito", "Arial Rounded MT Bold", system-ui, sans-serif; font-weight: 800; letter-spacing: -0.02em; color: #d3d7de; }
        .wordmark span { color: #ef2b6f; }
        :root[data-theme="light"] .wordmark { color: #2b3445; }
        .brand-logo { width: 1.6em; height: 1.6em; border-radius: 22%; flex-shrink: 0; }
        #dashboard-view header > h1 { font-size: 2.2rem; gap: 10px; flex-shrink: 0; }
        #dashboard-view header > h1 .brand-logo { width: 1.45em; height: 1.45em; }
        .form-toggle-bar .btn { display: inline-flex; align-items: center; justify-content: center; gap: 10px; }
        .btn-ico { display: inline-grid; place-items: center; width: 30px; height: 30px; border-radius: 50%; background: rgba(255,255,255,0.22); flex-shrink: 0; }
        .btn-ico svg { width: 18px; height: 18px; }
        .h-ico { display: inline-grid; place-items: center; width: 1.55em; height: 1.55em; margin-right: 0.35em; border-radius: 50%; background: rgba(236,64,122,0.15); color: #ec407a; vertical-align: middle; flex-shrink: 0; }
        .h-ico svg { width: 62%; height: 62%; }
        /* Shared button colours: blue = primary, violet = training/check, rose = brand */
        .btn-success { background-color: #7e57c2; }
        .btn-success:hover { background-color: #6a45ad; }
        .btn-info { background-color: #3f7fd6; }
        .btn-info:hover { background-color: #3169b8; }
        /* Main actions in the brand colours: rose, violet, blue */
        .form-toggle-bar .btn:nth-child(1) { background-color: var(--md-aktiva-green); }
        .form-toggle-bar .btn:nth-child(1):hover { background-color: var(--md-aktiva-green-dark); }
        .form-toggle-bar .btn:nth-child(2) { background-color: #7e57c2; }
        .form-toggle-bar .btn:nth-child(2):hover { background-color: #6a45ad; }
        .form-toggle-bar .btn:nth-child(3) { background-color: #3f7fd6; }
        .form-toggle-bar .btn:nth-child(3):hover { background-color: #3169b8; }
        h1 { font-size: 1.5rem; font-weight: 500; margin: 0; color: var(--md-accent-text); display: flex; align-items: center; gap: 8px; }
        h2 { font-size: 1.15rem; font-weight: 500; margin: 0 0 1rem 0; color: var(--md-on-surface); }

        .daily-tracker {
            background: var(--md-surface-card); border: 1px solid var(--md-border);
            border-radius: 8px; padding: 12px 18px; margin-bottom: 1.5rem;
            display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 15px;
            box-shadow: var(--md-elevation-1);
        }
        .tracker-info { display: flex; align-items: center; gap: 12px; }
        .tracker-icon { font-size: 2rem; }
        .tracker-text h3 { margin: 0; font-size: 1rem; color: var(--md-on-surface); }
        .tracker-text p { margin: 2px 0 0 0; font-size: 0.85rem; color: var(--md-text-muted); }
        .tracker-progress-bar {
            flex: 1; min-width: 200px; background: var(--md-track); height: 12px; border-radius: 6px; overflow: hidden; position: relative;
        }
        .tracker-progress-fill {
            background: linear-gradient(90deg, #7e57c2, var(--md-aktiva-green)); height: 100%; width: 0%; transition: width 0.4s ease;
        }

        .alert {
            background-color: var(--md-primary-light); color: var(--md-accent-text); padding: 12px 16px;
            border-radius: 8px; margin-bottom: 1rem; border: 1px solid #1565c0; font-size: 0.9rem;
            box-shadow: var(--md-elevation-1);
        }

        .card {
            background: var(--md-surface); border: 1px solid var(--md-border);
            border-radius: 12px; padding: 24px; box-shadow: var(--md-elevation-1);
            transition: box-shadow 0.3s ease;
        }
        .card:hover { box-shadow: var(--md-elevation-2); }

        form label { display: block; font-weight: 500; font-size: 0.85rem; margin-bottom: 0.3rem; color: var(--md-text-muted); }
        form input, form select, form textarea {
            width: 100%; padding: 10px 14px; border: 1px solid var(--md-border);
            border-radius: 8px; margin-bottom: 1rem; font-size: 0.95rem; background: var(--md-surface-card); color: var(--md-on-surface);
            transition: border-color 0.2s, box-shadow 0.2s;
        }
        form input:focus, form select:focus, form textarea:focus {
            outline: none; border-color: var(--md-primary); box-shadow: 0 0 0 3px rgba(30, 136, 229, 0.25);
        }

        .form-group-section {
            background: var(--md-surface-card);
            border: 1px solid var(--md-border);
            border-radius: 10px;
            padding: 16px 16px 6px 16px;
            margin-bottom: 1.2rem;
        }
        .word-hero {
            background: linear-gradient(135deg, rgba(144, 202, 249, 0.12), rgba(144, 202, 249, 0.03));
            border: 2px solid var(--md-accent-text); border-radius: 12px;
            padding: 18px 18px 14px; margin-bottom: 1.5rem;
        }
        .word-hero-label { display: flex; align-items: center; gap: 10px; margin-bottom: 10px; font-size: 1.05rem; font-weight: 600; color: var(--md-accent-text); }
        .required-badge {
            padding: 2px 8px; border-radius: 10px; background: var(--md-accent-text); color: var(--md-on-accent);
            font-size: 0.7rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em;
        }
        .word-hero-row { display: flex; gap: 10px; align-items: stretch; }
        .word-hero-row input { flex: 1; margin-bottom: 0; padding: 14px; font-size: 1.15rem; font-weight: 500; }
        .word-hero-row input:focus { border-color: var(--md-accent-text); box-shadow: 0 0 0 3px rgba(144, 202, 249, 0.25); }
        .word-hero-row .btn { white-space: nowrap; padding: 0 18px; font-size: 0.95rem; }
        .word-hero-hint { margin: 10px 0 0; font-size: 0.8rem; color: var(--md-text-muted); }
        @media (max-width: 600px) {
            .word-hero-row { flex-direction: column; }
            .word-hero-row .btn { padding: 12px 18px; }
        }
        .field-auto-hint { margin-left: 6px; font-size: 0.75rem; font-weight: 400; color: var(--md-text-muted); }
        .readonly-field, .readonly-field:focus {
            background: transparent; border-style: dashed; color: var(--md-accent-text); font-weight: 600;
            cursor: not-allowed; box-shadow: none; outline: none;
        }
        .score-status-legend { margin: -6px 0 12px; font-size: 0.75rem; color: var(--md-text-muted); }
        .form-group-section:last-of-type {
            margin-bottom: 1.5rem;
        }

        .btn {
            background-color: var(--md-primary); color: white; border: none;
            padding: 10px 20px; border-radius: 8px; font-weight: 500; cursor: pointer;
            text-decoration: none; display: inline-flex; align-items: center; justify-content: center; gap: 6px;
            font-size: 0.9rem; text-align: center; box-shadow: var(--md-elevation-1);
            transition: background-color 0.2s, box-shadow 0.2s;
        }
        .btn:hover { background-color: var(--md-primary-dark); box-shadow: var(--md-elevation-2); }
        .btn-success { background-color: var(--md-success); }
        .btn-success:hover { background-color: var(--md-success-dark); }
        .btn-aktiva { background-color: var(--md-aktiva-green); color: white; }
        .btn-aktiva:hover { background-color: var(--md-aktiva-green-dark); }
        .btn-danger { background-color: var(--md-danger); color: white; }
        .btn-danger:hover { background-color: var(--md-danger-dark); }
        .btn-passiv { background-color: var(--md-passiv-grey); color: white; }
        .btn-passiv:hover { background-color: var(--md-passiv-grey-dark); }
        .btn-secondary { background-color: #424242; color: #e0e0e0; }
        .btn-secondary:hover { background-color: #616161; }
        .btn-info { background-color: #00acc1; color: white; }
        /* KI-Symbol (Sterne) vor allen KI-Buttons */
        .ai-btn::before {
            content: ''; display: inline-block; width: 1.1em; height: 1.1em;
            margin-right: 0.4em; vertical-align: -0.18em; background-color: currentColor;
            -webkit-mask: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'%3E%3Cpath d='M9 3Q9.9 11.1 17 12Q9.9 12.9 9 21Q8.1 12.9 1 12Q8.1 11.1 9 3ZM19 1.5Q19.4 4.6 22.5 5Q19.4 5.4 19 8.5Q18.6 5.4 15.5 5Q18.6 4.6 19 1.5ZM19 16Q19.35 18.65 22 19Q19.35 19.35 19 22Q18.65 19.35 16 19Q18.65 18.65 19 16Z'/%3E%3C/svg%3E") center / contain no-repeat;
            mask: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'%3E%3Cpath d='M9 3Q9.9 11.1 17 12Q9.9 12.9 9 21Q8.1 12.9 1 12Q8.1 11.1 9 3ZM19 1.5Q19.4 4.6 22.5 5Q19.4 5.4 19 8.5Q18.6 5.4 15.5 5Q18.6 4.6 19 1.5ZM19 16Q19.35 18.65 22 19Q19.35 19.35 19 22Q18.65 19.35 16 19Q18.65 18.65 19 16Z'/%3E%3C/svg%3E") center / contain no-repeat;
        }
        /* "Mit KI trainieren" im Standard-Training */
        .ai-train-menu { margin-top: 1rem; border-top: 1px solid var(--md-border); padding-top: 12px; }
        .edit-image-container { margin-top: 12px; text-align: center; }
        .edit-image-container img {
            max-width: 100%; max-height: 240px; border-radius: 8px; cursor: zoom-in;
            box-shadow: 0 4px 12px rgba(0,0,0,0.3); display: block; margin: 0 auto;
        }
        .btn-info:hover { background-color: #00838f; }

        .filters { display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 0.8rem; align-items: center; }
        .filters input, .filters select { flex: 1; min-width: 140px; margin-bottom: 0; padding: 10px; }

        .alphabet-bar {
            display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 1.2rem; background: var(--md-surface); padding: 12px; border-radius: 8px; border: 1px solid var(--md-border);
            box-shadow: var(--md-elevation-1);
        }
        .alphabet-btn {
            background: var(--md-surface-card); border: 1px solid var(--md-border); padding: 6px 10px; font-size: 0.85rem; border-radius: 6px; cursor: pointer; font-weight: 500; color: var(--md-on-surface);
            transition: all 0.2s;
        }
        .alphabet-btn:hover, .alphabet-btn.active { background: var(--md-primary); color: #fff; border-color: var(--md-primary); box-shadow: var(--md-elevation-1); }

        .table-responsive { width: 100%; height: 650px; overflow-y: auto; overflow-x: auto; border: 1px solid var(--md-border); border-radius: 8px; position: relative; }
        table { width: 100%; border-collapse: collapse; text-align: left; font-size: 0.9rem; table-layout: fixed; }
        th, td { padding: 14px 10px; border-bottom: 1px solid var(--md-border); vertical-align: top; word-wrap: break-word; }
        th { background-color: var(--md-th-bg); font-weight: 600; white-space: nowrap; cursor: pointer; color: var(--md-on-surface); position: sticky; top: 0; z-index: 10; }
        th a { color: var(--md-on-surface); text-decoration: none; display: flex; align-items: center; gap: 4px; }

        th:nth-child(1), td:nth-child(1) { width: 6%; }
        th:nth-child(2), td:nth-child(2) { width: 13%; }
        th:nth-child(3), td:nth-child(3) { width: 13%; }
        th:nth-child(4), td:nth-child(4) { width: 38%; }
        th:nth-child(5), td:nth-child(5) { width: 9%; }
        th:nth-child(6), td:nth-child(6) { width: 21%; }

        .wort-cell { font-size: 1.3rem; font-weight: 600; }
        .wort-der { color: var(--md-der); }
        .wort-die { color: var(--md-die); }
        .wort-das { color: var(--md-das); }
        .wort-other { color: var(--md-article-other); }
        .kenntnisse-cell { min-width: 0; }

        /* Kenntnisse: one connected rating bar instead of 5 loose buttons */
        /* always one row of 5 equal segments; 1px gaps = separator lines */
        .rate-group {
            display: grid; grid-auto-flow: column; grid-auto-columns: minmax(0, 1fr); gap: 1px;
            width: 100%; border: 1px solid var(--md-border); border-radius: 10px; overflow: hidden;
            background: var(--md-border);
        }
        .rate-btn {
            --c: var(--md-primary);
            display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 2px;
            min-width: 0; padding: 6px 4px 5px; border: 0;
            background: var(--c); color: #ffffff; cursor: pointer;
            font: inherit; font-size: clamp(0.6rem, 0.55vw, 0.7rem); font-weight: 600; line-height: 1.1; white-space: nowrap;
            transition: background-color 0.15s, color 0.15s, transform 0.1s;
        }
        .rate-btn .rate-icon { font-size: 1.05rem; line-height: 1; }
        .rate-icon svg { display: block; width: 1.1em; height: 1.1em; }
        /* long labels break at the soft hyphen (warte-schlange) instead of being cut off */
        .rate-btn span:last-child { max-width: 100%; white-space: normal; text-align: center; overflow-wrap: anywhere; }
        .rate-btn:hover { background: color-mix(in srgb, var(--c) 82%, #000); }
        .rate-btn:active { transform: scale(0.94); }
        .rate-btn:focus-visible { outline: 2px solid #ffffff; outline-offset: -3px; }
        /* same colors as the rating buttons in the standard game */
        /* green -> blue scale: direkt aktiv (green) -> sehr gut -> ja -> wiederholen (blue) */
        .rate-btn.r-direkt-aktiv { --c: var(--md-aktiva-green); }
        .rate-btn.r-sehr-gut { --c: #b4498f; }
        .rate-btn.r-ja { --c: #7e57c2; }
        .rate-btn.r-wiederholen { --c: #3f7fd6; }
        .rate-btn.r-passiv { --c: #6b7785; }
        .rate-btn.r-warteschlange { --c: #434b57; }
        .rate-group.is-saving { opacity: 0.5; pointer-events: none; }
        /* word form: the bar works as a choice; once one is picked, the others fade */
        .rate-group.is-picker { margin-bottom: 4px; }
        .rate-group.is-picker.has-choice .rate-btn:not(.is-selected) { opacity: 0.35; }
        .rate-group.is-picker .rate-btn.is-selected { box-shadow: inset 0 0 0 3px #ffffff; }
        .status-cell { font-weight: 600; }
        .status-cell[data-status="aktiva"] { color: var(--md-aktiva-green); }
        .status-cell[data-status="wiederholen"] { color: #5b95e0; }
        .status-cell[data-status="neu"] { color: #9b7ad8; }
        .status-cell[data-status="passiv"], .status-cell[data-status="warteschlange"] { color: var(--md-text-muted); }
        .aktion-cell { min-width: 0; }
        /* Bearbeiten / Üben / Löschen: same segmented shape as the Kenntnisse bar */
        .rate-btn.a-edit { --c: #556173; }
        .rate-btn.a-train { --c: #3f7fd6; }
        .rate-btn.a-delete { --c: #c23a52; }
        /* compact version next to titles in the exercises */
        .action-group.is-inline { display: inline-grid; width: auto; }
        .action-group.is-inline .rate-btn { padding: 5px 12px 4px; font-size: 0.7rem; }
        .action-group.is-inline .rate-btn .rate-icon { font-size: 1rem; }

        .view { display: none; }
        .view.active { display: block; }
        .form-container { display: none; margin-bottom: 1.5rem; }
        .form-container.active { display: block; }
        .form-toggle-bar { margin-bottom: 1.2rem; display: flex; gap: 12px; flex-wrap: wrap; align-items: center; }
        @media (max-width: 600px) {
            .form-toggle-bar { flex-direction: column; align-items: stretch; gap: 10px; }
            .form-toggle-bar .btn { width: 100%; min-height: 48px; font-size: 1rem; }
        }

        .game-container { max-width: 800px; margin: 0 auto; }

        /* --- SETTINGS FORM STYLES --- */
        .settings-fieldset {
            background: var(--md-surface-card);
            border: 1px solid var(--md-border);
            border-radius: 10px;
            padding: 14px;
            margin-bottom: 12px;
        }
        .settings-summary {
            font-weight: 600;
            font-size: 0.95rem;
            cursor: pointer;
            color: var(--md-accent-text);
            padding: 4px 0;
            user-select: none;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .settings-summary::after {
            content: '▼';
            font-size: 0.75rem;
            transition: transform 0.2s;
        }
        details[open] .settings-summary::after {
            transform: rotate(180deg);
        }
        .settings-content {
            margin-top: 12px;
            padding-top: 10px;
            border-top: 1px solid var(--md-border);
        }
        .settings-controls-bar {
            display: flex;
            gap: 8px;
            margin-bottom: 10px;
        }
        .settings-action-link {
            background: none;
            border: none;
            color: var(--md-primary);
            font-size: 0.82rem;
            cursor: pointer;
            padding: 0;
            text-decoration: underline;
        }
        .settings-action-link:hover {
            color: var(--md-accent-text);
        }

        /* Modern Chip Multi-Select Pill UI */
        .status-pill-grid {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 8px;
        }
        .status-pill {
            background: var(--md-surface);
            border: 1px solid var(--md-border);
            color: var(--md-on-surface);
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 0.9rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s ease;
            user-select: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .status-pill:hover {
            border-color: var(--md-primary);
            background: var(--md-hover-bg);
        }
        .status-pill.active {
            background: var(--md-primary);
            color: #ffffff;
            border-color: var(--md-primary);
            box-shadow: 0 2px 8px rgba(30, 136, 229, 0.4);
        }
        .status-pill.active::before {
            content: '✓';
            font-weight: bold;
            font-size: 0.8rem;
        }

        .checkbox-group {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
            gap: 10px;
            margin-bottom: 0.5rem;
            background: var(--md-surface);
            padding: 12px;
            border-radius: 8px;
            border: 1px solid var(--md-border);
            max-height: 200px;
            overflow-y: auto;
        }
        #gameCategoryCheckboxes {
            max-height: 200px;
        }
        .checkbox-label {
            display: flex;
            align-items: center;
            justify-content: flex-start;
            text-align: left;
            gap: 12px;
            font-weight: 400;
            font-size: 0.95rem;
            cursor: pointer;
            color: var(--md-on-surface);
            padding: 6px 4px;
            border-radius: 6px;
            transition: background 0.15s;
        }
        .checkbox-label:hover {
            background: var(--md-subtle-hover);
        }
        .checkbox-label input[type="checkbox"] {
            margin: 0;
            width: 22px;
            height: 22px;
            accent-color: var(--md-primary);
            flex-shrink: 0;
            cursor: pointer;
        }

        .toggle-switch-btn {
            background: var(--md-surface);
            border: 1px solid var(--md-border);
            color: var(--md-on-surface);
            padding: 12px 16px;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 500;
            font-size: 0.95rem;
            width: 100%;
            display: flex;
            justify-content: space-between;
            align-items: center;
            transition: all 0.2s;
            box-shadow: var(--md-elevation-1);
        }
        .toggle-switch-btn:hover {
            border-color: var(--md-primary);
            background: var(--md-hover-bg);
        }
        .toggle-switch-badge {
            background: var(--md-primary);
            color: white;
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 0.85rem;
            font-weight: 600;
        }

        .word-display { font-size: 2.7rem; font-weight: 700; margin: 1rem 0 0.3rem 0; text-align: center; }
        .details-box { background: var(--md-surface-card); border: 1px solid var(--md-border); border-radius: 8px; padding: 16px; margin-bottom: 1.5rem; }
        .details-box p { margin: 8px 0; font-size: 0.95rem; }
        /* Rating bar in the standard game: same look as the table, but bigger */
        .game-rate-group { margin-top: 1.5rem; border-radius: 12px; }
        .game-rate-group .rate-btn { gap: 6px; min-height: 76px; padding: 12px 4px 10px; font-size: 0.85rem; }
        .game-rate-group .rate-btn .rate-icon { font-size: 1.9rem; }
        @media (max-width: 480px) {
            .game-rate-group .rate-btn { min-height: 68px; padding: 10px 1px 8px; font-size: 0.6rem; letter-spacing: -0.01em; }
            .game-rate-group .rate-btn .rate-icon { font-size: 1.6rem; }
        }
        @media (max-width: 380px) {
            .game-rate-group .rate-btn { font-size: 0.54rem; }
            .game-rate-group .rate-btn .rate-icon { font-size: 1.45rem; }
        }

        .modal-overlay {
            position: fixed; top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(0,0,0,0.7); display: none; align-items: center; justify-content: center; z-index: 1000;
        }
        .modal-content {
            background: var(--md-surface); border: 1px solid var(--md-border); border-radius: 12px;
            padding: 24px; max-width: 520px; width: 90%; text-align: center; box-shadow: var(--md-elevation-2);
        }

        /* Fullscreen Image Zoom Styles */
        #generatedImageTag {
            cursor: zoom-in;
            transition: transform 0.25s ease;
        }
        .image-fullscreen-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.9);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 2000;
            cursor: zoom-out;
            padding: 20px;
        }
        .image-fullscreen-overlay img {
            max-width: 100%;
            max-height: 100%;
            object-fit: contain;
            border-radius: 8px;
            box-shadow: 0 5px 25px rgba(0,0,0,0.8);
        }

        .stats-container { max-width: 900px; margin: 0 auto; }
        .chart-wrapper { position: relative; width: 100%; max-width: 450px; margin: 0 auto 1.5rem auto; height: 320px; }
        .stats-text-list { background: var(--md-surface-card); border: 1px solid var(--md-border); border-radius: 8px; padding: 16px; margin-bottom: 1.5rem; font-size: 1rem; }
        .stats-text-item { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid var(--md-border); }
        .stats-text-item:last-child { border-bottom: none; }

        /* ===== Filter + Konto panels — MOBILE FIRST =====
           Base (phones): bottom sheets with a dimmed backdrop.
           >= 768px: the same markup becomes an anchored dropdown. */
        .filter-toolbar {
            display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: 10px; margin-bottom: 1rem;
        }
        .filter-toolbar > #filterSearch { grid-column: 1; grid-row: 1; }
        .filter-toolbar > .filter-menu { grid-column: 2; grid-row: 1; }
        .filter-toolbar > #filterTranslationSearch { grid-column: 1 / -1; grid-row: 2; }
        .filter-toolbar > input {
            width: 100%; min-width: 0; min-height: 44px; margin-bottom: 0; padding: 10px 12px;
            font-size: 16px; /* 16px stops iOS from zooming into the field */
        }
        .filter-trigger {
            position: relative; display: inline-flex; align-items: center; justify-content: center; min-width: 44px; min-height: 44px; padding: 6px 10px;
            border: 1px solid var(--md-control-border); border-radius: 8px; background: var(--md-control-bg); color: var(--md-on-surface);
            cursor: pointer; list-style: none; font: inherit; font-size: 0.9rem; font-weight: 600; user-select: none;
        }
        .filter-trigger::-webkit-details-marker { display: none; }
        .filter-trigger:hover, .filter-menu[open] .filter-trigger { background: var(--md-control-hover); border-color: var(--md-control-border-hover); }
        .filter-trigger:focus-visible { outline: 2px solid var(--md-accent-text); outline-offset: 2px; }
        .filter-menu[open] .account-chevron { margin-top: 4px; transform: rotate(225deg); }
        .filter-icon { display: block; }
        .filter-trigger .filter-badge { position: absolute; top: -6px; right: -6px; }
        .filter-badge {
            display: inline-grid; place-items: center; min-width: 20px; height: 20px; padding: 0 6px;
            border-radius: 10px; background: var(--md-primary); color: #fff; font-size: 0.75rem;
        }
        .filter-badge[hidden] { display: none; }

        /* Sheet shell (shared by Filter and Konto) */
        .sheet-menu[open] > summary::before {
            /* backdrop: lives inside <summary>, so tapping it closes the <details> natively */
            content: ''; position: fixed; inset: 0; z-index: 900;
            background: rgba(0, 0, 0, 0.55); cursor: default;
        }
        .sheet-panel {
            position: fixed; z-index: 910; left: 0; right: 0; bottom: 0;
            display: flex; flex-direction: column;
            max-height: 85vh; max-height: 85dvh;
            background: var(--md-popover-bg); color: var(--md-on-surface);
            border: 1px solid var(--md-control-border); border-bottom: 0;
            border-radius: 16px 16px 0 0; box-shadow: 0 -8px 24px rgba(0, 0, 0, 0.35);
            padding-bottom: env(safe-area-inset-bottom, 0px);
            overscroll-behavior: contain; touch-action: pan-y;
        }
        .sheet-handle {
            flex-shrink: 0; width: 40px; height: 4px; margin: 8px auto 2px;
            border-radius: 2px; background: var(--md-control-border-hover);
        }
        .sheet-header {
            flex-shrink: 0; display: flex; align-items: center; justify-content: space-between; gap: 12px;
            padding: 4px 8px 4px 16px;
        }
        .sheet-title { margin: 0; font-size: 1.05rem; font-weight: 600; color: var(--md-on-surface); }
        .sheet-close {
            display: grid; place-items: center; width: 44px; height: 44px; padding: 0;
            border: 0; border-radius: 50%; background: transparent; color: var(--md-text-muted);
            font-size: 1.5rem; line-height: 1; cursor: pointer;
        }
        .sheet-close:hover { background: var(--md-control-hover); color: var(--md-on-surface); }
        .sheet-close:focus-visible { outline: 2px solid var(--md-accent-text); outline-offset: 2px; }
        .sheet-body {
            flex: 1 1 auto; min-height: 0; overflow-y: auto; -webkit-overflow-scrolling: touch;
            overscroll-behavior: contain; padding: 4px 16px 12px;
        }
        .sheet-footer {
            flex-shrink: 0; display: flex; gap: 10px; padding: 12px 16px;
            border-top: 1px solid var(--md-border); background: var(--md-popover-bg);
        }
        .sheet-footer .btn { flex: 1; min-height: 48px; }
        .sheet-panel.is-dragging { transition: none; }
        /* page behind an open sheet must not scroll */
        html:has(.sheet-menu[open]) { overflow: hidden; }
        @media (prefers-reduced-motion: no-preference) {
            .sheet-menu[open] > .sheet-panel { animation: sheet-up 0.24s cubic-bezier(0.2, 0.8, 0.2, 1); }
            .sheet-menu[open] > summary::before { animation: sheet-fade 0.24s ease-out; }
        }
        @keyframes sheet-up { from { transform: translateY(100%); } to { transform: translateY(0); } }
        @keyframes sheet-fade { from { opacity: 0; } to { opacity: 1; } }

        /* "Training wird geladen" animation */
        .training-loader { display: none; flex-direction: column; align-items: center; justify-content: center; gap: 14px; padding: 48px 16px; color: var(--md-text-muted); font-size: 0.95rem; text-align: center; }
        .training-loader.active { display: flex; }
        .training-loader-dots { display: flex; gap: 8px; }
        .training-loader-dots span { width: 12px; height: 12px; border-radius: 50%; background: var(--md-accent-text); animation: training-bounce 1s infinite ease-in-out; }
        .training-loader-dots span:nth-child(2) { animation-delay: 0.15s; }
        .training-loader-dots span:nth-child(3) { animation-delay: 0.3s; }
        @keyframes training-bounce { 0%, 80%, 100% { transform: scale(0.5); opacity: 0.4; } 40% { transform: scale(1); opacity: 1; } }
        @media (prefers-reduced-motion: reduce) { .training-loader-dots span { animation: none; opacity: 0.8; } }

        /* Filter sheet content */
        .filter-group { padding: 4px 0 14px; border-bottom: 1px solid var(--md-border); margin-bottom: 12px; }
        .sheet-body .filter-group:last-child { border-bottom: 0; margin-bottom: 0; padding-bottom: 4px; }
        .filter-group-title { margin: 0 0 10px; font-size: 0.85rem; font-weight: 600; color: var(--md-text-muted); }
        .filter-grid { display: grid; grid-template-columns: 1fr; gap: 10px; }
        .filter-field { display: flex; flex-direction: column; gap: 4px; margin: 0; font-size: 0.8rem; color: var(--md-text-muted); }
        .filter-field select { width: 100%; min-height: 44px; margin-bottom: 0; padding: 10px; font-size: 16px; }
        .filter-panel .alphabet-bar {
            display: grid; grid-template-columns: repeat(auto-fill, minmax(44px, 1fr)); gap: 6px;
            margin: 0; padding: 0; border: 0; background: none; box-shadow: none;
        }
        .filter-panel .alphabet-btn { min-height: 44px; padding: 0; font-size: 0.95rem; }
        .filter-panel .alphabet-btn:first-child { grid-column: span 2; }

        /* Konto sheet content */
        .account-panel .sheet-body { padding: 0 12px 12px; }
        .account-panel .account-menu-item { min-height: 48px; font-size: 0.95rem; }
        .account-panel .account-menu-theme { min-height: 48px; }

        @media (min-width: 480px) {
            .filter-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }

        /* Tablet / desktop: anchored dropdowns */
        @media (min-width: 768px) {
            .filter-toolbar { display: flex; flex-wrap: wrap; align-items: center; }
            .filter-toolbar > input { flex: 1; min-width: 180px; min-height: 42px; font-size: 0.95rem; }
            .filter-trigger { min-height: 42px; }

            .sheet-menu { position: relative; }
            .sheet-menu[open] > summary::before { content: none; }
            html:has(.sheet-menu[open]) { overflow: visible; }
            .sheet-panel {
                position: absolute; z-index: 30; top: calc(100% + 8px); bottom: auto; left: auto; right: auto;
                max-height: 70vh; border: 1px solid var(--md-control-border); border-radius: 8px;
                box-shadow: var(--md-elevation-2); padding-bottom: 0;
            }
            .sheet-menu[open] > .sheet-panel { animation: none; }
            .sheet-handle { display: none; }

            .filter-panel { left: 0; width: min(640px, calc(100vw - 32px)); }
            .filter-panel .sheet-body { padding: 4px 14px 10px; }
            .filter-grid { grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); }
            .filter-field select { min-height: 0; padding: 9px; font-size: 0.95rem; }
            .filter-panel .alphabet-bar { display: flex; flex-wrap: wrap; }
            .filter-panel .alphabet-btn { min-height: 0; padding: 6px 10px; font-size: 0.85rem; }
            .filter-panel .alphabet-btn:first-child { grid-column: auto; }
            .sheet-footer { justify-content: flex-end; padding: 10px 14px; }
            .sheet-footer .btn { flex: 0 0 auto; min-height: 0; }

            .account-panel { right: 0; width: 260px; max-height: calc(100vh - 120px); }
            .account-panel .sheet-header { display: none; }
            .account-panel .sheet-body { padding: 8px; }
            .account-panel .account-menu-item { min-height: 42px; font-size: 0.9rem; }
            .account-panel .account-menu-theme { min-height: 0; }
        }

        @media (max-width: 900px) {
            table, thead, tbody, th, td, tr { display: block; width: 100% !important; }
            thead tr { display: none; }
            table tr { background: var(--md-surface); border: 1px solid var(--md-border); border-radius: 8px; margin-bottom: 12px; padding: 14px; box-shadow: var(--md-elevation-1); }
            table td { border: none; padding: 6px 0; display: flex; justify-content: space-between; align-items: center; }
            table td::before { content: attr(data-label); font-weight: 600; color: var(--md-text-muted); font-size: 0.8rem; margin-right: 10px; }
            table td.wort-cell { justify-content: space-between; }
            table td.kenntnisse-cell, table td.aktion-cell { justify-content: flex-end; margin-top: 10px; padding-top: 10px; border-top: 1px solid var(--md-border); }
            table td.kenntnisse-cell, table td.aktion-cell { flex-direction: column; align-items: stretch; gap: 8px; }
            table tr { padding: 12px 10px; }
            /* 6 segments on a phone: keep labels small so "warte-schlange" still fits */
            .rate-btn { padding: 9px 1px 7px; font-size: 0.6rem; letter-spacing: -0.01em; }
            .rate-btn .rate-icon { font-size: 1.25rem; }
            .action-group:not(.is-inline) .rate-btn { font-size: 0.7rem; }
            .chart-wrapper { height: 260px; }
        }
        @media (max-width: 440px) {
            .rate-btn { font-size: 0.55rem; letter-spacing: -0.02em; }
            .rate-btn .rate-icon { font-size: 1.15rem; }
        }
        @media (max-width: 380px) {
            .rate-btn { font-size: 0.5rem; }
        }
        /* Level path in header (Sprachniveau nach aktiven Wörtern) */
        .level-path { flex: 1 1 420px; max-width: 700px; min-width: 0; min-height: 60px; margin: 0 auto; }
        .level-path svg { display: block; width: 100%; height: auto; overflow: visible; }
        .lp-track { fill: none; stroke: #555; stroke-width: 2; stroke-dasharray: 2 5; stroke-linecap: round; }
        .lp-fill { fill: none; stroke: var(--md-aktiva-green); stroke-width: 3; stroke-linecap: round; }
        .lp-dot { fill: var(--md-surface); stroke: #777; stroke-width: 2; }
        .lp-ring { fill: none; stroke: var(--md-aktiva-green); stroke-width: 3.5; stroke-linecap: round; }
        .lp-code { font: 700 12px ui-monospace, SFMono-Regular, Menlo, monospace; fill: #9e9e9e; text-anchor: middle; dominant-baseline: central; }
        .lp-name { font-size: 12px; fill: #8a8a8a; text-anchor: middle; }
        .lp-count { font: 11px ui-monospace, SFMono-Regular, Menlo, monospace; fill: #777; text-anchor: middle; }
        .lp-node.done .lp-dot { fill: #4a1a2c; stroke: var(--md-aktiva-green); }
        .lp-node.current .lp-dot { fill: #4a1a2c; stroke: var(--md-aktiva-green); stroke-width: 3.5; filter: drop-shadow(0 0 7px rgba(236,64,122,.6)); }
        .lp-node.done .lp-code, .lp-node.current .lp-code { fill: #e3f2fd; }
        .lp-node.done .lp-name { fill: #e0e0e0; }
        .lp-node.current .lp-name { fill: var(--md-aktiva-green); font-weight: 700; }
        .lp-node.done .lp-count, .lp-node.current .lp-count { fill: #a0a0a0; }
        .lp-node.next .lp-name { fill: #bdbdbd; }
        :root[data-theme="light"] .lp-name { fill: #6b7682; }
        :root[data-theme="light"] .lp-count { fill: #7a8592; }
        :root[data-theme="light"] .lp-node.done .lp-name, :root[data-theme="light"] .lp-node.next .lp-name { fill: var(--md-on-surface); }
        :root[data-theme="light"] .lp-node.done .lp-count, :root[data-theme="light"] .lp-node.current .lp-count { fill: #5f6b7a; }
        :root[data-theme="light"] .lp-node.done .lp-dot, :root[data-theme="light"] .lp-node.current .lp-dot { fill: #f8d7e3; }
        :root[data-theme="light"] .lp-track { stroke: #9aa3ad; }
        :root[data-theme="light"] .lp-node.current .lp-name { fill: var(--md-aktiva-green); }
        .lp-img { pointer-events: none; }
        .lp-node.next .lp-img, .lp-node.locked .lp-img { filter: grayscale(1); opacity: 0.35; }
        .lp-node.done, .lp-node.current { cursor: pointer; }
        .level-card-overlay { position: fixed; inset: 0; z-index: 1000; display: grid; place-items: center; padding: 16px; background: rgba(0,0,0,0.7); animation: sheet-fade 0.25s ease; }
        .level-card { width: min(360px, 100%); padding: 22px 20px 20px; border-radius: 16px; background: var(--md-surface); color: inherit; text-align: center; box-shadow: 0 10px 40px rgba(0,0,0,0.5); animation: level-pop 0.45s cubic-bezier(0.2, 1.4, 0.4, 1); }
        .level-card-kicker { font-size: 0.85rem; font-weight: 700; color: var(--md-accent-text); letter-spacing: 0.02em; }
        .level-card-img { width: 220px; height: 220px; max-width: 70vw; max-height: 70vw; margin: 14px auto 10px; border-radius: 50%; overflow: hidden; box-shadow: 0 0 0 3px #90caf9, 0 0 30px rgba(144,202,249,0.5); }
        .level-card-overlay.is-new .level-card-img { animation: level-glow 2s ease-in-out infinite; }
        .level-card-img img { display: block; width: 100%; height: 100%; object-fit: cover; }
        .level-card h2 { margin: 6px 0 4px; font-size: 1.5rem; color: var(--md-accent-text); }
        .level-card p { margin: 0 0 16px; color: var(--md-text-muted); }
        @keyframes level-pop { from { transform: scale(0.6); opacity: 0; } to { transform: scale(1); opacity: 1; } }
        @keyframes level-glow { 0%, 100% { box-shadow: 0 0 0 3px #90caf9, 0 0 24px rgba(144,202,249,0.4); } 50% { box-shadow: 0 0 0 3px #90caf9, 0 0 48px rgba(144,202,249,0.9); } }
        @media (prefers-reduced-motion: reduce) { .level-card, .level-card-overlay, .level-card-overlay.is-new .level-card-img { animation: none; } }
        .lp-caption { text-align: center; font-size: 0.8rem; color: var(--md-text-muted); margin-top: 2px; }
        .lp-caption strong { color: var(--md-aktiva-green); }
        @media (max-width: 760px) {
            /* Phone: logo + account on the first row, level path below at full width */
            #dashboard-view header { flex-wrap: wrap; padding: 10px 12px; row-gap: 4px; }
            #dashboard-view header > h1 { font-size: 1.9rem; order: 1; overflow: visible; }
            #dashboard-view header .header-actions { order: 2; }
            #dashboard-view header .level-path { order: 3; flex: 1 1 100%; }
            .level-path { flex: 1 1 0; max-width: none; min-height: 0; }
            .lp-caption { display: none; }
        }
        @media (max-width: 480px) {
            .lp-count { display: none; }
            .lp-name { font-size: 16px; }
            .lp-code { font-size: 15px; }
        }
        /* Doodle background with small brains */
        body { background-image: url("data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 width=%22360%22 height=%22360%22 viewBox=%220 0 360 360%22%3E%3Cg fill=%22none%22 stroke=%22rgba(255,255,255,0.09)%22 stroke-width=%221.7%22 stroke-linecap=%22round%22 stroke-linejoin=%22round%22 font-family=%22Nunito,Arial Rounded MT Bold,Arial,sans-serif%22 font-weight=%22700%22%3E%3Cdefs%3E%3Cg id=%22brain%22%3E%3Cpath d=%22M20 6C15 4 10 6 9 10C5 11 4 16 6 19C3 22 4 28 8 29C9 33 14 35 18 33L20 32M20 6C25 4 30 6 31 10C35 11 36 16 34 19C37 22 36 28 32 29C31 33 26 35 22 33L20 32M20 6V32M13 12C16 13 16 17 13 18M27 12C24 13 24 17 27 18M11 24C14 23 16 25 15 28M29 24C26 23 24 25 25 28%22/%3E%3C/g%3E%3Cg id=%22happy%22%3E%3Cpath d=%22M20 6C15 4 10 6 9 10C5 11 4 16 6 19C3 22 4 28 8 29C9 33 14 35 18 33L20 32M20 6C25 4 30 6 31 10C35 11 36 16 34 19C37 22 36 28 32 29C31 33 26 35 22 33L20 32M20 6V32%22/%3E%3Cpath d=%22M15 19h.01M25 19h.01M16 24q4 3 8 0%22/%3E%3C/g%3E%3Cg id=%22sleepy%22%3E%3Cpath d=%22M20 6C15 4 10 6 9 10C5 11 4 16 6 19C3 22 4 28 8 29C9 33 14 35 18 33L20 32M20 6C25 4 30 6 31 10C35 11 36 16 34 19C37 22 36 28 32 29C31 33 26 35 22 33L20 32M20 6V32%22/%3E%3Cpath d=%22M13 19q2 2 4 0M23 19q2 2 4 0M17 25q3 2 6 0%22/%3E%3Ctext fill=%22rgba(255,255,255,0.09)%22 stroke=%22none%22 x=%2233%22 y=%228%22 font-size=%228%22%3Ez%3C/text%3E%3Ctext fill=%22rgba(255,255,255,0.09)%22 stroke=%22none%22 x=%2238%22 y=%222%22 font-size=%226%22%3Ez%3C/text%3E%3C/g%3E%3Cg id=%22reader%22%3E%3Cpath d=%22M20 6C15 4 10 6 9 10C5 11 4 16 6 19C3 22 4 28 8 29C9 33 14 35 18 33L20 32M20 6C25 4 30 6 31 10C35 11 36 16 34 19C37 22 36 28 32 29C31 33 26 35 22 33L20 32M20 6V32%22/%3E%3Cpath d=%22M13 19q2 2 4 0M23 19q2 2 4 0M17 25q3 2 6 0%22/%3E%3Cpath d=%22M8 33l12 4 12-4V26l-12 4-12-4zM20 30v7%22/%3E%3C/g%3E%3Cg id=%22grad%22%3E%3Cpath d=%22M20 6C15 4 10 6 9 10C5 11 4 16 6 19C3 22 4 28 8 29C9 33 14 35 18 33L20 32M20 6C25 4 30 6 31 10C35 11 36 16 34 19C37 22 36 28 32 29C31 33 26 35 22 33L20 32M20 6V32%22/%3E%3Cpath d=%22M15 19h.01M25 19h.01M16 24q4 3 8 0%22/%3E%3Cpath d=%22M8 6l12-6 12 6-12 6zM32 6v6%22/%3E%3C/g%3E%3Cg id=%22idea%22%3E%3Cpath d=%22M20 6C15 4 10 6 9 10C5 11 4 16 6 19C3 22 4 28 8 29C9 33 14 35 18 33L20 32M20 6C25 4 30 6 31 10C35 11 36 16 34 19C37 22 36 28 32 29C31 33 26 35 22 33L20 32M20 6V32%22/%3E%3Cpath d=%22M15 19h.01M25 19h.01M16 24q4 3 8 0%22/%3E%3Cpath d=%22M38 2a5 5 0 0 0-3 9v2h6v-2a5 5 0 0 0-3-9zM36 15h4M30 4l2 1M44 4l-2 1M38-4v2%22/%3E%3C/g%3E%3Cg id=%22love%22%3E%3Cpath d=%22M20 6C15 4 10 6 9 10C5 11 4 16 6 19C3 22 4 28 8 29C9 33 14 35 18 33L20 32M20 6C25 4 30 6 31 10C35 11 36 16 34 19C37 22 36 28 32 29C31 33 26 35 22 33L20 32M20 6V32%22/%3E%3Cpath d=%22M15 19h.01M25 19h.01M16 24q4 3 8 0%22/%3E%3Cpath d=%22M38 6s-5-3-5-6a2.5 2.5 0 0 1 5-1 2.5 2.5 0 0 1 5 1c0 3-5 6-5 6z%22/%3E%3C/g%3E%3Cg id=%22star%22%3E%3Cpath d=%22M20 8l3 9 9 3-9 3-3 9-3-9-9-3 9-3z%22/%3E%3C/g%3E%3Cg id=%22spark%22%3E%3Cpath d=%22M20 14v12M14 20h12%22/%3E%3C/g%3E%3Cg id=%22heart%22%3E%3Cpath d=%22M20 30s-9-6-9-12a5 5 0 0 1 9-2 5 5 0 0 1 9 2c0 6-9 12-9 12z%22/%3E%3C/g%3E%3Cg id=%22book%22%3E%3Cpath d=%22M6 26l14 4 14-4V12l-14 4-14-4zM20 16v14%22/%3E%3C/g%3E%3Cg id=%22bubble%22%3E%3Cpath d=%22M8 10h24a4 4 0 0 1 4 4v10a4 4 0 0 1-4 4H18l-6 5v-5H8a4 4 0 0 1-4-4V14a4 4 0 0 1 4-4z%22/%3E%3Ctext fill=%22rgba(255,255,255,0.09)%22 stroke=%22none%22 x=%2220%22 y=%2224%22 font-size=%2211%22 text-anchor=%22middle%22%3E%C3%84%3C/text%3E%3C/g%3E%3Cg id=%22dots%22%3E%3Ccircle cx=%2212%22 cy=%2220%22 r=%221.6%22/%3E%3Ccircle cx=%2220%22 cy=%2214%22 r=%221.6%22/%3E%3Ccircle cx=%2228%22 cy=%2222%22 r=%221.6%22/%3E%3C/g%3E%3C/defs%3E%3Cg transform=%22translate(186 -42) rotate(-3 15 15) scale(0.76)%22%3E%3Cuse href=%22%23happy%22/%3E%3C/g%3E%3Cg transform=%22translate(186 318) rotate(-3 15 15) scale(0.76)%22%3E%3Cuse href=%22%23happy%22/%3E%3C/g%3E%3Cg transform=%22translate(52 273) rotate(9 17 17) scale(0.83)%22%3E%3Cuse href=%22%23happy%22/%3E%3C/g%3E%3Cg transform=%22translate(18 93) rotate(3 16 16) scale(0.81)%22%3E%3Cuse href=%22%23happy%22/%3E%3C/g%3E%3Cg transform=%22translate(378 93) rotate(3 16 16) scale(0.81)%22%3E%3Cuse href=%22%23happy%22/%3E%3C/g%3E%3Cg transform=%22translate(216 202) rotate(33 13 13) scale(0.63)%22%3E%3Cuse href=%22%23dots%22/%3E%3C/g%3E%3Cg transform=%22translate(210 45) rotate(23 12 12) scale(0.60)%22%3E%3Cuse href=%22%23star%22/%3E%3C/g%3E%3Cg transform=%22translate(-1 -57) rotate(-33 14 14) scale(0.69)%22%3E%3Cuse href=%22%23grad%22/%3E%3C/g%3E%3Cg transform=%22translate(-1 303) rotate(-33 14 14) scale(0.69)%22%3E%3Cuse href=%22%23grad%22/%3E%3C/g%3E%3Cg transform=%22translate(359 -57) rotate(-33 14 14) scale(0.69)%22%3E%3Cuse href=%22%23grad%22/%3E%3C/g%3E%3Cg transform=%22translate(359 303) rotate(-33 14 14) scale(0.69)%22%3E%3Cuse href=%22%23grad%22/%3E%3C/g%3E%3Cg transform=%22translate(143 288) rotate(-19 15 15) scale(0.76)%22%3E%3Cuse href=%22%23reader%22/%3E%3C/g%3E%3Cg transform=%22translate(-13 16) rotate(-7 15 15) scale(0.73)%22%3E%3Cuse href=%22%23grad%22/%3E%3C/g%3E%3Cg transform=%22translate(-13 376) rotate(-7 15 15) scale(0.73)%22%3E%3Cuse href=%22%23grad%22/%3E%3C/g%3E%3Cg transform=%22translate(347 16) rotate(-7 15 15) scale(0.73)%22%3E%3Cuse href=%22%23grad%22/%3E%3C/g%3E%3Cg transform=%22translate(347 376) rotate(-7 15 15) scale(0.73)%22%3E%3Cuse href=%22%23grad%22/%3E%3C/g%3E%3Cg transform=%22translate(262 174) rotate(-30 11 11) scale(0.54)%22%3E%3Cuse href=%22%23star%22/%3E%3C/g%3E%3Cg transform=%22translate(-26 294) rotate(-20 11 11) scale(0.56)%22%3E%3Cuse href=%22%23star%22/%3E%3C/g%3E%3Cg transform=%22translate(334 294) rotate(-20 11 11) scale(0.56)%22%3E%3Cuse href=%22%23star%22/%3E%3C/g%3E%3Cg transform=%22translate(135 188) rotate(19 16 16) scale(0.80)%22%3E%3Cuse href=%22%23reader%22/%3E%3C/g%3E%3Cg transform=%22translate(256 26) rotate(14 17 17) scale(0.84)%22%3E%3Cuse href=%22%23reader%22/%3E%3C/g%3E%3Cg transform=%22translate(256 386) rotate(14 17 17) scale(0.84)%22%3E%3Cuse href=%22%23reader%22/%3E%3C/g%3E%3Cg transform=%22translate(154 162) rotate(-22 14 14) scale(0.68)%22%3E%3Cuse href=%22%23reader%22/%3E%3C/g%3E%3Cg transform=%22translate(-17 266) rotate(10 12 12) scale(0.58)%22%3E%3Cuse href=%22%23dots%22/%3E%3C/g%3E%3Cg transform=%22translate(343 266) rotate(10 12 12) scale(0.58)%22%3E%3Cuse href=%22%23dots%22/%3E%3C/g%3E%3Cg transform=%22translate(141 66) rotate(26 10 10) scale(0.52)%22%3E%3Cuse href=%22%23bubble%22/%3E%3C/g%3E%3Cg transform=%22translate(54 -15) rotate(10 14 14) scale(0.68)%22%3E%3Cuse href=%22%23grad%22/%3E%3C/g%3E%3Cg transform=%22translate(54 345) rotate(10 14 14) scale(0.68)%22%3E%3Cuse href=%22%23grad%22/%3E%3C/g%3E%3Cg transform=%22translate(-18 63) rotate(-34 14 14) scale(0.70)%22%3E%3Cuse href=%22%23grad%22/%3E%3C/g%3E%3Cg transform=%22translate(342 63) rotate(-34 14 14) scale(0.70)%22%3E%3Cuse href=%22%23grad%22/%3E%3C/g%3E%3Cg transform=%22translate(283 123) rotate(-29 16 16) scale(0.78)%22%3E%3Cuse href=%22%23happy%22/%3E%3C/g%3E%3Cg transform=%22translate(76 205) rotate(9 12 12) scale(0.59)%22%3E%3Ctext fill=%22rgba(255,255,255,0.09)%22 stroke=%22none%22 x=%2220%22 y=%2226%22 font-size=%2218%22 text-anchor=%22middle%22%3E%C3%BC%3C/text%3E%3C/g%3E%3Cg transform=%22translate(53 43) rotate(22 13 13) scale(0.63)%22%3E%3Cuse href=%22%23book%22/%3E%3C/g%3E%3Cg transform=%22translate(201 135) rotate(-27 17 17) scale(0.83)%22%3E%3Cuse href=%22%23happy%22/%3E%3C/g%3E%3Cg transform=%22translate(29 156) rotate(8 17 17) scale(0.85)%22%3E%3Cuse href=%22%23grad%22/%3E%3C/g%3E%3Cg transform=%22translate(389 156) rotate(8 17 17) scale(0.85)%22%3E%3Cuse href=%22%23grad%22/%3E%3C/g%3E%3Cg transform=%22translate(83 147) rotate(-32 14 14) scale(0.68)%22%3E%3Cuse href=%22%23brain%22/%3E%3C/g%3E%3Cg transform=%22translate(117 101) rotate(-34 11 11) scale(0.57)%22%3E%3Cuse href=%22%23star%22/%3E%3C/g%3E%3Cg transform=%22translate(162 251) rotate(-25 12 12) scale(0.60)%22%3E%3Ctext fill=%22rgba(255,255,255,0.09)%22 stroke=%22none%22 x=%2220%22 y=%2226%22 font-size=%2218%22 text-anchor=%22middle%22%3E%C3%B6%3C/text%3E%3C/g%3E%3Cg transform=%22translate(249 237) rotate(-10 17 17) scale(0.83)%22%3E%3Cuse href=%22%23idea%22/%3E%3C/g%3E%3Cg transform=%22translate(270 -64) rotate(-34 15 15) scale(0.75)%22%3E%3Cuse href=%22%23grad%22/%3E%3C/g%3E%3Cg transform=%22translate(270 296) rotate(-34 15 15) scale(0.75)%22%3E%3Cuse href=%22%23grad%22/%3E%3C/g%3E%3Cg transform=%22translate(121 -11) rotate(-29 16 16) scale(0.79)%22%3E%3Cuse href=%22%23happy%22/%3E%3C/g%3E%3Cg transform=%22translate(121 349) rotate(-29 16 16) scale(0.79)%22%3E%3Cuse href=%22%23happy%22/%3E%3C/g%3E%3Cg transform=%22translate(128 252) rotate(-4 12 12) scale(0.61)%22%3E%3Cuse href=%22%23star%22/%3E%3C/g%3E%3Cg transform=%22translate(18 258) rotate(-13 13 13) scale(0.63)%22%3E%3Cuse href=%22%23star%22/%3E%3C/g%3E%3Cg transform=%22translate(378 258) rotate(-13 13 13) scale(0.63)%22%3E%3Cuse href=%22%23star%22/%3E%3C/g%3E%3Cg transform=%22translate(214 275) rotate(27 14 14) scale(0.72)%22%3E%3Cuse href=%22%23reader%22/%3E%3C/g%3E%3Cg transform=%22translate(195 99) rotate(-26 15 15) scale(0.75)%22%3E%3Cuse href=%22%23sleepy%22/%3E%3C/g%3E%3Cg transform=%22translate(-52 -0) rotate(32 15 15) scale(0.74)%22%3E%3Cuse href=%22%23happy%22/%3E%3C/g%3E%3Cg transform=%22translate(-52 360) rotate(32 15 15) scale(0.74)%22%3E%3Cuse href=%22%23happy%22/%3E%3C/g%3E%3Cg transform=%22translate(308 -0) rotate(32 15 15) scale(0.74)%22%3E%3Cuse href=%22%23happy%22/%3E%3C/g%3E%3Cg transform=%22translate(308 360) rotate(32 15 15) scale(0.74)%22%3E%3Cuse href=%22%23happy%22/%3E%3C/g%3E%3Cg transform=%22translate(166 102) rotate(-13 12 12) scale(0.59)%22%3E%3Cuse href=%22%23star%22/%3E%3C/g%3E%3Cg transform=%22translate(89 115) rotate(10 12 12) scale(0.60)%22%3E%3Cuse href=%22%23star%22/%3E%3C/g%3E%3Cg transform=%22translate(235 64) rotate(30 16 16) scale(0.82)%22%3E%3Cuse href=%22%23love%22/%3E%3C/g%3E%3Cg transform=%22translate(-59 271) rotate(-3 16 16) scale(0.78)%22%3E%3Cuse href=%22%23idea%22/%3E%3C/g%3E%3Cg transform=%22translate(301 271) rotate(-3 16 16) scale(0.78)%22%3E%3Cuse href=%22%23idea%22/%3E%3C/g%3E%3Cg transform=%22translate(-35 161) rotate(18 16 16) scale(0.80)%22%3E%3Cuse href=%22%23happy%22/%3E%3C/g%3E%3Cg transform=%22translate(325 161) rotate(18 16 16) scale(0.80)%22%3E%3Cuse href=%22%23happy%22/%3E%3C/g%3E%3Cg transform=%22translate(190 288) rotate(10 11 11) scale(0.57)%22%3E%3Ctext fill=%22rgba(255,255,255,0.09)%22 stroke=%22none%22 x=%2220%22 y=%2226%22 font-size=%2213%22 text-anchor=%22middle%22%3Edas%3C/text%3E%3C/g%3E%3Cg transform=%22translate(-45 81) rotate(32 12 12) scale(0.58)%22%3E%3Cuse href=%22%23dots%22/%3E%3C/g%3E%3Cg transform=%22translate(315 81) rotate(32 12 12) scale(0.58)%22%3E%3Cuse href=%22%23dots%22/%3E%3C/g%3E%3Cg transform=%22translate(133 26) rotate(7 15 15) scale(0.76)%22%3E%3Cuse href=%22%23brain%22/%3E%3C/g%3E%3Cg transform=%22translate(133 386) rotate(7 15 15) scale(0.76)%22%3E%3Cuse href=%22%23brain%22/%3E%3C/g%3E%3Cg transform=%22translate(267 -6) rotate(-24 14 14) scale(0.70)%22%3E%3Cuse href=%22%23reader%22/%3E%3C/g%3E%3Cg transform=%22translate(267 354) rotate(-24 14 14) scale(0.70)%22%3E%3Cuse href=%22%23reader%22/%3E%3C/g%3E%3Cg transform=%22translate(89 247) rotate(8 14 14) scale(0.68)%22%3E%3Cuse href=%22%23reader%22/%3E%3C/g%3E%3Cg transform=%22translate(79 60) rotate(27 12 12) scale(0.58)%22%3E%3Ctext fill=%22rgba(255,255,255,0.09)%22 stroke=%22none%22 x=%2220%22 y=%2226%22 font-size=%2218%22 text-anchor=%22middle%22%3E%C3%9F%3C/text%3E%3C/g%3E%3Cg transform=%22translate(102 -39) rotate(19 11 11) scale(0.55)%22%3E%3Cuse href=%22%23book%22/%3E%3C/g%3E%3Cg transform=%22translate(102 321) rotate(19 11 11) scale(0.55)%22%3E%3Cuse href=%22%23book%22/%3E%3C/g%3E%3Cg transform=%22translate(33 230) rotate(19 16 16) scale(0.78)%22%3E%3Cuse href=%22%23happy%22/%3E%3C/g%3E%3Cg transform=%22translate(393 230) rotate(19 16 16) scale(0.78)%22%3E%3Cuse href=%22%23happy%22/%3E%3C/g%3E%3Cg transform=%22translate(201 -10) rotate(24 15 15) scale(0.75)%22%3E%3Cuse href=%22%23brain%22/%3E%3C/g%3E%3Cg transform=%22translate(201 350) rotate(24 15 15) scale(0.75)%22%3E%3Cuse href=%22%23brain%22/%3E%3C/g%3E%3Cg transform=%22translate(-60 233) rotate(19 17 17) scale(0.83)%22%3E%3Cuse href=%22%23love%22/%3E%3C/g%3E%3Cg transform=%22translate(300 233) rotate(19 17 17) scale(0.83)%22%3E%3Cuse href=%22%23love%22/%3E%3C/g%3E%3Cg transform=%22translate(158 -7) rotate(34 11 11) scale(0.55)%22%3E%3Ctext fill=%22rgba(255,255,255,0.09)%22 stroke=%22none%22 x=%2220%22 y=%2226%22 font-size=%2218%22 text-anchor=%22middle%22%3E%C3%9F%3C/text%3E%3C/g%3E%3Cg transform=%22translate(158 353) rotate(34 11 11) scale(0.55)%22%3E%3Ctext fill=%22rgba(255,255,255,0.09)%22 stroke=%22none%22 x=%2220%22 y=%2226%22 font-size=%2218%22 text-anchor=%22middle%22%3E%C3%9F%3C/text%3E%3C/g%3E%3Cg transform=%22translate(-5 209) rotate(-17 13 13) scale(0.65)%22%3E%3Cuse href=%22%23book%22/%3E%3C/g%3E%3Cg transform=%22translate(355 209) rotate(-17 13 13) scale(0.65)%22%3E%3Cuse href=%22%23book%22/%3E%3C/g%3E%3Cg transform=%22translate(277 200) rotate(-31 17 17) scale(0.85)%22%3E%3Cuse href=%22%23happy%22/%3E%3C/g%3E%3Cg transform=%22translate(230 115) rotate(22 16 16) scale(0.79)%22%3E%3Cuse href=%22%23reader%22/%3E%3C/g%3E%3Cg transform=%22translate(90 32) rotate(-27 14 14) scale(0.70)%22%3E%3Cuse href=%22%23happy%22/%3E%3C/g%3E%3Cg transform=%22translate(90 392) rotate(-27 14 14) scale(0.70)%22%3E%3Cuse href=%22%23happy%22/%3E%3C/g%3E%3Cg transform=%22translate(56 77) rotate(27 12 12) scale(0.61)%22%3E%3Cuse href=%22%23book%22/%3E%3C/g%3E%3Cg transform=%22translate(186 215) rotate(-1 12 12) scale(0.60)%22%3E%3Ctext fill=%22rgba(255,255,255,0.09)%22 stroke=%22none%22 x=%2220%22 y=%2226%22 font-size=%2218%22 text-anchor=%22middle%22%3E%21%3C/text%3E%3C/g%3E%3Cg transform=%22translate(-45 -37) rotate(-4 16 16) scale(0.78)%22%3E%3Cuse href=%22%23grad%22/%3E%3C/g%3E%3Cg transform=%22translate(-45 323) rotate(-4 16 16) scale(0.78)%22%3E%3Cuse href=%22%23grad%22/%3E%3C/g%3E%3Cg transform=%22translate(315 -37) rotate(-4 16 16) scale(0.78)%22%3E%3Cuse href=%22%23grad%22/%3E%3C/g%3E%3Cg transform=%22translate(315 323) rotate(-4 16 16) scale(0.78)%22%3E%3Cuse href=%22%23grad%22/%3E%3C/g%3E%3Cg transform=%22translate(128 125) rotate(17 15 15) scale(0.74)%22%3E%3Cuse href=%22%23reader%22/%3E%3C/g%3E%3Cg transform=%22translate(6 43) rotate(33 14 14) scale(0.72)%22%3E%3Cuse href=%22%23grad%22/%3E%3C/g%3E%3Cg transform=%22translate(366 43) rotate(33 14 14) scale(0.72)%22%3E%3Cuse href=%22%23grad%22/%3E%3C/g%3E%3Cg transform=%22translate(229 165) rotate(21 13 13) scale(0.65)%22%3E%3Ctext fill=%22rgba(255,255,255,0.09)%22 stroke=%22none%22 x=%2220%22 y=%2226%22 font-size=%2218%22 text-anchor=%22middle%22%3E%C3%B6%3C/text%3E%3C/g%3E%3Cg transform=%22translate(-43 136) rotate(25 12 12) scale(0.61)%22%3E%3Ctext fill=%22rgba(255,255,255,0.09)%22 stroke=%22none%22 x=%2220%22 y=%2226%22 font-size=%2213%22 text-anchor=%22middle%22%3Eder%3C/text%3E%3C/g%3E%3Cg transform=%22translate(317 136) rotate(25 12 12) scale(0.61)%22%3E%3Ctext fill=%22rgba(255,255,255,0.09)%22 stroke=%22none%22 x=%2220%22 y=%2226%22 font-size=%2213%22 text-anchor=%22middle%22%3Eder%3C/text%3E%3C/g%3E%3Cg transform=%22translate(96 181) rotate(-1 16 16) scale(0.81)%22%3E%3Cuse href=%22%23reader%22/%3E%3C/g%3E%3Cg transform=%22translate(237 -21) rotate(11 15 15) scale(0.75)%22%3E%3Cuse href=%22%23reader%22/%3E%3C/g%3E%3Cg transform=%22translate(237 339) rotate(11 15 15) scale(0.75)%22%3E%3Cuse href=%22%23reader%22/%3E%3C/g%3E%3Cg transform=%22translate(165 36) rotate(34 15 15) scale(0.74)%22%3E%3Cuse href=%22%23reader%22/%3E%3C/g%3E%3Cg transform=%22translate(-30 215) rotate(19 11 11) scale(0.53)%22%3E%3Cuse href=%22%23book%22/%3E%3C/g%3E%3Cg transform=%22translate(330 215) rotate(19 11 11) scale(0.53)%22%3E%3Cuse href=%22%23book%22/%3E%3C/g%3E%3Cg transform=%22translate(1 179) rotate(5 11 11) scale(0.56)%22%3E%3Cuse href=%22%23spark%22/%3E%3C/g%3E%3Cg transform=%22translate(361 179) rotate(5 11 11) scale(0.56)%22%3E%3Cuse href=%22%23spark%22/%3E%3C/g%3E%3Cg transform=%22translate(104 215) rotate(16 16 16) scale(0.78)%22%3E%3Cuse href=%22%23brain%22/%3E%3C/g%3E%3Cg transform=%22translate(27 -36) rotate(2 14 14) scale(0.70)%22%3E%3Cuse href=%22%23idea%22/%3E%3C/g%3E%3Cg transform=%22translate(27 324) rotate(2 14 14) scale(0.70)%22%3E%3Cuse href=%22%23idea%22/%3E%3C/g%3E%3Cg transform=%22translate(387 -36) rotate(2 14 14) scale(0.70)%22%3E%3Cuse href=%22%23idea%22/%3E%3C/g%3E%3Cg transform=%22translate(387 324) rotate(2 14 14) scale(0.70)%22%3E%3Cuse href=%22%23idea%22/%3E%3C/g%3E%3Cg transform=%22translate(201 244) rotate(-4 14 14) scale(0.69)%22%3E%3Cuse href=%22%23grad%22/%3E%3C/g%3E%3Cg transform=%22translate(-12 135) rotate(-25 13 13) scale(0.65)%22%3E%3Ctext fill=%22rgba(255,255,255,0.09)%22 stroke=%22none%22 x=%2220%22 y=%2226%22 font-size=%2213%22 text-anchor=%22middle%22%3Eder%3C/text%3E%3C/g%3E%3Cg transform=%22translate(348 135) rotate(-25 13 13) scale(0.65)%22%3E%3Ctext fill=%22rgba(255,255,255,0.09)%22 stroke=%22none%22 x=%2220%22 y=%2226%22 font-size=%2213%22 text-anchor=%22middle%22%3Eder%3C/text%3E%3C/g%3E%3Cg transform=%22translate(158 -35) rotate(13 12 12) scale(0.58)%22%3E%3Cuse href=%22%23star%22/%3E%3C/g%3E%3Cg transform=%22translate(158 325) rotate(13 12 12) scale(0.58)%22%3E%3Cuse href=%22%23star%22/%3E%3C/g%3E%3Cg transform=%22translate(43 196) rotate(17 14 14) scale(0.72)%22%3E%3Cuse href=%22%23brain%22/%3E%3C/g%3E%3Cg transform=%22translate(292 45) rotate(-13 16 16) scale(0.82)%22%3E%3Cuse href=%22%23happy%22/%3E%3C/g%3E%3Cg transform=%22translate(194 68) rotate(16 14 14) scale(0.68)%22%3E%3Cuse href=%22%23love%22/%3E%3C/g%3E%3Cg transform=%22translate(88 -14) rotate(-10 12 12) scale(0.58)%22%3E%3Cuse href=%22%23star%22/%3E%3C/g%3E%3Cg transform=%22translate(88 346) rotate(-10 12 12) scale(0.58)%22%3E%3Cuse href=%22%23star%22/%3E%3C/g%3E%3Cg transform=%22translate(171 135) rotate(32 10 10) scale(0.50)%22%3E%3Ctext fill=%22rgba(255,255,255,0.09)%22 stroke=%22none%22 x=%2220%22 y=%2226%22 font-size=%2218%22 text-anchor=%22middle%22%3E%C3%84%3C/text%3E%3C/g%3E%3Cg transform=%22translate(127 -42) rotate(19 14 14) scale(0.69)%22%3E%3Cuse href=%22%23sleepy%22/%3E%3C/g%3E%3Cg transform=%22translate(127 318) rotate(19 14 14) scale(0.69)%22%3E%3Cuse href=%22%23sleepy%22/%3E%3C/g%3E%3Cg transform=%22translate(43 122) rotate(-7 15 15) scale(0.77)%22%3E%3Cuse href=%22%23idea%22/%3E%3C/g%3E%3Cg transform=%22translate(66 14) rotate(33 14 14) scale(0.69)%22%3E%3Cuse href=%22%23grad%22/%3E%3C/g%3E%3Cg transform=%22translate(66 374) rotate(33 14 14) scale(0.69)%22%3E%3Cuse href=%22%23grad%22/%3E%3C/g%3E%3Cg transform=%22translate(11 -3) rotate(-34 12 12) scale(0.59)%22%3E%3Ctext fill=%22rgba(255,255,255,0.09)%22 stroke=%22none%22 x=%2220%22 y=%2226%22 font-size=%2218%22 text-anchor=%22middle%22%3E%3F%3C/text%3E%3C/g%3E%3Cg transform=%22translate(11 357) rotate(-34 12 12) scale(0.59)%22%3E%3Ctext fill=%22rgba(255,255,255,0.09)%22 stroke=%22none%22 x=%2220%22 y=%2226%22 font-size=%2218%22 text-anchor=%22middle%22%3E%3F%3C/text%3E%3C/g%3E%3Cg transform=%22translate(371 -3) rotate(-34 12 12) scale(0.59)%22%3E%3Ctext fill=%22rgba(255,255,255,0.09)%22 stroke=%22none%22 x=%2220%22 y=%2226%22 font-size=%2218%22 text-anchor=%22middle%22%3E%3F%3C/text%3E%3C/g%3E%3Cg transform=%22translate(371 357) rotate(-34 12 12) scale(0.59)%22%3E%3Ctext fill=%22rgba(255,255,255,0.09)%22 stroke=%22none%22 x=%2220%22 y=%2226%22 font-size=%2218%22 text-anchor=%22middle%22%3E%3F%3C/text%3E%3C/g%3E%3Cg transform=%22translate(108 292) rotate(-9 13 13) scale(0.65)%22%3E%3Ctext fill=%22rgba(255,255,255,0.09)%22 stroke=%22none%22 x=%2220%22 y=%2226%22 font-size=%2213%22 text-anchor=%22middle%22%3Edie%3C/text%3E%3C/g%3E%3Cg transform=%22translate(194 171) rotate(-9 14 14) scale(0.70)%22%3E%3Cuse href=%22%23reader%22/%3E%3C/g%3E%3Cg transform=%22translate(28 17) rotate(17 14 14) scale(0.71)%22%3E%3Cuse href=%22%23brain%22/%3E%3C/g%3E%3Cg transform=%22translate(28 377) rotate(17 14 14) scale(0.71)%22%3E%3Cuse href=%22%23brain%22/%3E%3C/g%3E%3Cg transform=%22translate(388 17) rotate(17 14 14) scale(0.71)%22%3E%3Cuse href=%22%23brain%22/%3E%3C/g%3E%3Cg transform=%22translate(388 377) rotate(17 14 14) scale(0.71)%22%3E%3Cuse href=%22%23brain%22/%3E%3C/g%3E%3Cg transform=%22translate(69 -50) rotate(-2 14 14) scale(0.71)%22%3E%3Cuse href=%22%23brain%22/%3E%3C/g%3E%3Cg transform=%22translate(69 310) rotate(-2 14 14) scale(0.71)%22%3E%3Cuse href=%22%23brain%22/%3E%3C/g%3E%3Cg transform=%22translate(117 76) rotate(19 10 10) scale(0.52)%22%3E%3Ctext fill=%22rgba(255,255,255,0.09)%22 stroke=%22none%22 x=%2220%22 y=%2226%22 font-size=%2213%22 text-anchor=%22middle%22%3Eder%3C/text%3E%3C/g%3E%3Cg transform=%22translate(284 -31) rotate(-22 11 11) scale(0.53)%22%3E%3Cuse href=%22%23book%22/%3E%3C/g%3E%3Cg transform=%22translate(284 329) rotate(-22 11 11) scale(0.53)%22%3E%3Cuse href=%22%23book%22/%3E%3C/g%3E%3Cg transform=%22translate(270 79) rotate(29 15 15) scale(0.76)%22%3E%3Cuse href=%22%23happy%22/%3E%3C/g%3E%3Cg transform=%22translate(236 -56) rotate(-2 15 15) scale(0.73)%22%3E%3Cuse href=%22%23idea%22/%3E%3C/g%3E%3Cg transform=%22translate(236 304) rotate(-2 15 15) scale(0.73)%22%3E%3Cuse href=%22%23idea%22/%3E%3C/g%3E%3Cg transform=%22translate(289 162) rotate(-13 15 15) scale(0.77)%22%3E%3Cuse href=%22%23idea%22/%3E%3C/g%3E%3Cg transform=%22translate(-8 -18) rotate(4 11 11) scale(0.54)%22%3E%3Cuse href=%22%23dots%22/%3E%3C/g%3E%3Cg transform=%22translate(-8 342) rotate(4 11 11) scale(0.54)%22%3E%3Cuse href=%22%23dots%22/%3E%3C/g%3E%3Cg transform=%22translate(352 -18) rotate(4 11 11) scale(0.54)%22%3E%3Cuse href=%22%23dots%22/%3E%3C/g%3E%3Cg transform=%22translate(352 342) rotate(4 11 11) scale(0.54)%22%3E%3Cuse href=%22%23dots%22/%3E%3C/g%3E%3Cg transform=%22translate(157 222) rotate(32 11 11) scale(0.54)%22%3E%3Cuse href=%22%23heart%22/%3E%3C/g%3E%3Cg transform=%22translate(-18 97) rotate(7 15 15) scale(0.75)%22%3E%3Cuse href=%22%23brain%22/%3E%3C/g%3E%3Cg transform=%22translate(342 97) rotate(7 15 15) scale(0.75)%22%3E%3Cuse href=%22%23brain%22/%3E%3C/g%3E%3Cg transform=%22translate(224 16) rotate(23 15 15) scale(0.77)%22%3E%3Cuse href=%22%23reader%22/%3E%3C/g%3E%3Cg transform=%22translate(224 376) rotate(23 15 15) scale(0.77)%22%3E%3Cuse href=%22%23reader%22/%3E%3C/g%3E%3Cg transform=%22translate(163 71) rotate(7 14 14) scale(0.70)%22%3E%3Cuse href=%22%23grad%22/%3E%3C/g%3E%3Cg transform=%22translate(197 24) rotate(-25 10 10) scale(0.52)%22%3E%3Cuse href=%22%23heart%22/%3E%3C/g%3E%3Cg transform=%22translate(197 384) rotate(-25 10 10) scale(0.52)%22%3E%3Cuse href=%22%23heart%22/%3E%3C/g%3E%3Cg transform=%22translate(-7 237) rotate(20 12 12) scale(0.61)%22%3E%3Ctext fill=%22rgba(255,255,255,0.09)%22 stroke=%22none%22 x=%2220%22 y=%2226%22 font-size=%2218%22 text-anchor=%22middle%22%3E%21%3C/text%3E%3C/g%3E%3Cg transform=%22translate(353 237) rotate(20 12 12) scale(0.61)%22%3E%3Ctext fill=%22rgba(255,255,255,0.09)%22 stroke=%22none%22 x=%2220%22 y=%2226%22 font-size=%2218%22 text-anchor=%22middle%22%3E%21%3C/text%3E%3C/g%3E%3Cg transform=%22translate(123 160) rotate(-13 14 14) scale(0.68)%22%3E%3Cuse href=%22%23brain%22/%3E%3C/g%3E%3Cg transform=%22translate(253 143) rotate(16 14 14) scale(0.69)%22%3E%3Cuse href=%22%23reader%22/%3E%3C/g%3E%3Cg transform=%22translate(248 275) rotate(10 14 14) scale(0.69)%22%3E%3Cuse href=%22%23reader%22/%3E%3C/g%3E%3Cg transform=%22translate(250 199) rotate(10 11 11) scale(0.56)%22%3E%3Ctext fill=%22rgba(255,255,255,0.09)%22 stroke=%22none%22 x=%2220%22 y=%2226%22 font-size=%2213%22 text-anchor=%22middle%22%3Edie%3C/text%3E%3C/g%3E%3Cg transform=%22translate(279 260) rotate(2 11 11) scale(0.57)%22%3E%3Cuse href=%22%23dots%22/%3E%3C/g%3E%3C/g%3E%3C/svg%3E"); }
        :root[data-theme="light"] body { background-image: url("data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 width=%22360%22 height=%22360%22 viewBox=%220 0 360 360%22%3E%3Cg fill=%22none%22 stroke=%22rgba(30,40,60,0.15)%22 stroke-width=%221.7%22 stroke-linecap=%22round%22 stroke-linejoin=%22round%22 font-family=%22Nunito,Arial Rounded MT Bold,Arial,sans-serif%22 font-weight=%22700%22%3E%3Cdefs%3E%3Cg id=%22brain%22%3E%3Cpath d=%22M20 6C15 4 10 6 9 10C5 11 4 16 6 19C3 22 4 28 8 29C9 33 14 35 18 33L20 32M20 6C25 4 30 6 31 10C35 11 36 16 34 19C37 22 36 28 32 29C31 33 26 35 22 33L20 32M20 6V32M13 12C16 13 16 17 13 18M27 12C24 13 24 17 27 18M11 24C14 23 16 25 15 28M29 24C26 23 24 25 25 28%22/%3E%3C/g%3E%3Cg id=%22happy%22%3E%3Cpath d=%22M20 6C15 4 10 6 9 10C5 11 4 16 6 19C3 22 4 28 8 29C9 33 14 35 18 33L20 32M20 6C25 4 30 6 31 10C35 11 36 16 34 19C37 22 36 28 32 29C31 33 26 35 22 33L20 32M20 6V32%22/%3E%3Cpath d=%22M15 19h.01M25 19h.01M16 24q4 3 8 0%22/%3E%3C/g%3E%3Cg id=%22sleepy%22%3E%3Cpath d=%22M20 6C15 4 10 6 9 10C5 11 4 16 6 19C3 22 4 28 8 29C9 33 14 35 18 33L20 32M20 6C25 4 30 6 31 10C35 11 36 16 34 19C37 22 36 28 32 29C31 33 26 35 22 33L20 32M20 6V32%22/%3E%3Cpath d=%22M13 19q2 2 4 0M23 19q2 2 4 0M17 25q3 2 6 0%22/%3E%3Ctext fill=%22rgba(30,40,60,0.15)%22 stroke=%22none%22 x=%2233%22 y=%228%22 font-size=%228%22%3Ez%3C/text%3E%3Ctext fill=%22rgba(30,40,60,0.15)%22 stroke=%22none%22 x=%2238%22 y=%222%22 font-size=%226%22%3Ez%3C/text%3E%3C/g%3E%3Cg id=%22reader%22%3E%3Cpath d=%22M20 6C15 4 10 6 9 10C5 11 4 16 6 19C3 22 4 28 8 29C9 33 14 35 18 33L20 32M20 6C25 4 30 6 31 10C35 11 36 16 34 19C37 22 36 28 32 29C31 33 26 35 22 33L20 32M20 6V32%22/%3E%3Cpath d=%22M13 19q2 2 4 0M23 19q2 2 4 0M17 25q3 2 6 0%22/%3E%3Cpath d=%22M8 33l12 4 12-4V26l-12 4-12-4zM20 30v7%22/%3E%3C/g%3E%3Cg id=%22grad%22%3E%3Cpath d=%22M20 6C15 4 10 6 9 10C5 11 4 16 6 19C3 22 4 28 8 29C9 33 14 35 18 33L20 32M20 6C25 4 30 6 31 10C35 11 36 16 34 19C37 22 36 28 32 29C31 33 26 35 22 33L20 32M20 6V32%22/%3E%3Cpath d=%22M15 19h.01M25 19h.01M16 24q4 3 8 0%22/%3E%3Cpath d=%22M8 6l12-6 12 6-12 6zM32 6v6%22/%3E%3C/g%3E%3Cg id=%22idea%22%3E%3Cpath d=%22M20 6C15 4 10 6 9 10C5 11 4 16 6 19C3 22 4 28 8 29C9 33 14 35 18 33L20 32M20 6C25 4 30 6 31 10C35 11 36 16 34 19C37 22 36 28 32 29C31 33 26 35 22 33L20 32M20 6V32%22/%3E%3Cpath d=%22M15 19h.01M25 19h.01M16 24q4 3 8 0%22/%3E%3Cpath d=%22M38 2a5 5 0 0 0-3 9v2h6v-2a5 5 0 0 0-3-9zM36 15h4M30 4l2 1M44 4l-2 1M38-4v2%22/%3E%3C/g%3E%3Cg id=%22love%22%3E%3Cpath d=%22M20 6C15 4 10 6 9 10C5 11 4 16 6 19C3 22 4 28 8 29C9 33 14 35 18 33L20 32M20 6C25 4 30 6 31 10C35 11 36 16 34 19C37 22 36 28 32 29C31 33 26 35 22 33L20 32M20 6V32%22/%3E%3Cpath d=%22M15 19h.01M25 19h.01M16 24q4 3 8 0%22/%3E%3Cpath d=%22M38 6s-5-3-5-6a2.5 2.5 0 0 1 5-1 2.5 2.5 0 0 1 5 1c0 3-5 6-5 6z%22/%3E%3C/g%3E%3Cg id=%22star%22%3E%3Cpath d=%22M20 8l3 9 9 3-9 3-3 9-3-9-9-3 9-3z%22/%3E%3C/g%3E%3Cg id=%22spark%22%3E%3Cpath d=%22M20 14v12M14 20h12%22/%3E%3C/g%3E%3Cg id=%22heart%22%3E%3Cpath d=%22M20 30s-9-6-9-12a5 5 0 0 1 9-2 5 5 0 0 1 9 2c0 6-9 12-9 12z%22/%3E%3C/g%3E%3Cg id=%22book%22%3E%3Cpath d=%22M6 26l14 4 14-4V12l-14 4-14-4zM20 16v14%22/%3E%3C/g%3E%3Cg id=%22bubble%22%3E%3Cpath d=%22M8 10h24a4 4 0 0 1 4 4v10a4 4 0 0 1-4 4H18l-6 5v-5H8a4 4 0 0 1-4-4V14a4 4 0 0 1 4-4z%22/%3E%3Ctext fill=%22rgba(30,40,60,0.15)%22 stroke=%22none%22 x=%2220%22 y=%2224%22 font-size=%2211%22 text-anchor=%22middle%22%3E%C3%84%3C/text%3E%3C/g%3E%3Cg id=%22dots%22%3E%3Ccircle cx=%2212%22 cy=%2220%22 r=%221.6%22/%3E%3Ccircle cx=%2220%22 cy=%2214%22 r=%221.6%22/%3E%3Ccircle cx=%2228%22 cy=%2222%22 r=%221.6%22/%3E%3C/g%3E%3C/defs%3E%3Cg transform=%22translate(186 -42) rotate(-3 15 15) scale(0.76)%22%3E%3Cuse href=%22%23happy%22/%3E%3C/g%3E%3Cg transform=%22translate(186 318) rotate(-3 15 15) scale(0.76)%22%3E%3Cuse href=%22%23happy%22/%3E%3C/g%3E%3Cg transform=%22translate(52 273) rotate(9 17 17) scale(0.83)%22%3E%3Cuse href=%22%23happy%22/%3E%3C/g%3E%3Cg transform=%22translate(18 93) rotate(3 16 16) scale(0.81)%22%3E%3Cuse href=%22%23happy%22/%3E%3C/g%3E%3Cg transform=%22translate(378 93) rotate(3 16 16) scale(0.81)%22%3E%3Cuse href=%22%23happy%22/%3E%3C/g%3E%3Cg transform=%22translate(216 202) rotate(33 13 13) scale(0.63)%22%3E%3Cuse href=%22%23dots%22/%3E%3C/g%3E%3Cg transform=%22translate(210 45) rotate(23 12 12) scale(0.60)%22%3E%3Cuse href=%22%23star%22/%3E%3C/g%3E%3Cg transform=%22translate(-1 -57) rotate(-33 14 14) scale(0.69)%22%3E%3Cuse href=%22%23grad%22/%3E%3C/g%3E%3Cg transform=%22translate(-1 303) rotate(-33 14 14) scale(0.69)%22%3E%3Cuse href=%22%23grad%22/%3E%3C/g%3E%3Cg transform=%22translate(359 -57) rotate(-33 14 14) scale(0.69)%22%3E%3Cuse href=%22%23grad%22/%3E%3C/g%3E%3Cg transform=%22translate(359 303) rotate(-33 14 14) scale(0.69)%22%3E%3Cuse href=%22%23grad%22/%3E%3C/g%3E%3Cg transform=%22translate(143 288) rotate(-19 15 15) scale(0.76)%22%3E%3Cuse href=%22%23reader%22/%3E%3C/g%3E%3Cg transform=%22translate(-13 16) rotate(-7 15 15) scale(0.73)%22%3E%3Cuse href=%22%23grad%22/%3E%3C/g%3E%3Cg transform=%22translate(-13 376) rotate(-7 15 15) scale(0.73)%22%3E%3Cuse href=%22%23grad%22/%3E%3C/g%3E%3Cg transform=%22translate(347 16) rotate(-7 15 15) scale(0.73)%22%3E%3Cuse href=%22%23grad%22/%3E%3C/g%3E%3Cg transform=%22translate(347 376) rotate(-7 15 15) scale(0.73)%22%3E%3Cuse href=%22%23grad%22/%3E%3C/g%3E%3Cg transform=%22translate(262 174) rotate(-30 11 11) scale(0.54)%22%3E%3Cuse href=%22%23star%22/%3E%3C/g%3E%3Cg transform=%22translate(-26 294) rotate(-20 11 11) scale(0.56)%22%3E%3Cuse href=%22%23star%22/%3E%3C/g%3E%3Cg transform=%22translate(334 294) rotate(-20 11 11) scale(0.56)%22%3E%3Cuse href=%22%23star%22/%3E%3C/g%3E%3Cg transform=%22translate(135 188) rotate(19 16 16) scale(0.80)%22%3E%3Cuse href=%22%23reader%22/%3E%3C/g%3E%3Cg transform=%22translate(256 26) rotate(14 17 17) scale(0.84)%22%3E%3Cuse href=%22%23reader%22/%3E%3C/g%3E%3Cg transform=%22translate(256 386) rotate(14 17 17) scale(0.84)%22%3E%3Cuse href=%22%23reader%22/%3E%3C/g%3E%3Cg transform=%22translate(154 162) rotate(-22 14 14) scale(0.68)%22%3E%3Cuse href=%22%23reader%22/%3E%3C/g%3E%3Cg transform=%22translate(-17 266) rotate(10 12 12) scale(0.58)%22%3E%3Cuse href=%22%23dots%22/%3E%3C/g%3E%3Cg transform=%22translate(343 266) rotate(10 12 12) scale(0.58)%22%3E%3Cuse href=%22%23dots%22/%3E%3C/g%3E%3Cg transform=%22translate(141 66) rotate(26 10 10) scale(0.52)%22%3E%3Cuse href=%22%23bubble%22/%3E%3C/g%3E%3Cg transform=%22translate(54 -15) rotate(10 14 14) scale(0.68)%22%3E%3Cuse href=%22%23grad%22/%3E%3C/g%3E%3Cg transform=%22translate(54 345) rotate(10 14 14) scale(0.68)%22%3E%3Cuse href=%22%23grad%22/%3E%3C/g%3E%3Cg transform=%22translate(-18 63) rotate(-34 14 14) scale(0.70)%22%3E%3Cuse href=%22%23grad%22/%3E%3C/g%3E%3Cg transform=%22translate(342 63) rotate(-34 14 14) scale(0.70)%22%3E%3Cuse href=%22%23grad%22/%3E%3C/g%3E%3Cg transform=%22translate(283 123) rotate(-29 16 16) scale(0.78)%22%3E%3Cuse href=%22%23happy%22/%3E%3C/g%3E%3Cg transform=%22translate(76 205) rotate(9 12 12) scale(0.59)%22%3E%3Ctext fill=%22rgba(30,40,60,0.15)%22 stroke=%22none%22 x=%2220%22 y=%2226%22 font-size=%2218%22 text-anchor=%22middle%22%3E%C3%BC%3C/text%3E%3C/g%3E%3Cg transform=%22translate(53 43) rotate(22 13 13) scale(0.63)%22%3E%3Cuse href=%22%23book%22/%3E%3C/g%3E%3Cg transform=%22translate(201 135) rotate(-27 17 17) scale(0.83)%22%3E%3Cuse href=%22%23happy%22/%3E%3C/g%3E%3Cg transform=%22translate(29 156) rotate(8 17 17) scale(0.85)%22%3E%3Cuse href=%22%23grad%22/%3E%3C/g%3E%3Cg transform=%22translate(389 156) rotate(8 17 17) scale(0.85)%22%3E%3Cuse href=%22%23grad%22/%3E%3C/g%3E%3Cg transform=%22translate(83 147) rotate(-32 14 14) scale(0.68)%22%3E%3Cuse href=%22%23brain%22/%3E%3C/g%3E%3Cg transform=%22translate(117 101) rotate(-34 11 11) scale(0.57)%22%3E%3Cuse href=%22%23star%22/%3E%3C/g%3E%3Cg transform=%22translate(162 251) rotate(-25 12 12) scale(0.60)%22%3E%3Ctext fill=%22rgba(30,40,60,0.15)%22 stroke=%22none%22 x=%2220%22 y=%2226%22 font-size=%2218%22 text-anchor=%22middle%22%3E%C3%B6%3C/text%3E%3C/g%3E%3Cg transform=%22translate(249 237) rotate(-10 17 17) scale(0.83)%22%3E%3Cuse href=%22%23idea%22/%3E%3C/g%3E%3Cg transform=%22translate(270 -64) rotate(-34 15 15) scale(0.75)%22%3E%3Cuse href=%22%23grad%22/%3E%3C/g%3E%3Cg transform=%22translate(270 296) rotate(-34 15 15) scale(0.75)%22%3E%3Cuse href=%22%23grad%22/%3E%3C/g%3E%3Cg transform=%22translate(121 -11) rotate(-29 16 16) scale(0.79)%22%3E%3Cuse href=%22%23happy%22/%3E%3C/g%3E%3Cg transform=%22translate(121 349) rotate(-29 16 16) scale(0.79)%22%3E%3Cuse href=%22%23happy%22/%3E%3C/g%3E%3Cg transform=%22translate(128 252) rotate(-4 12 12) scale(0.61)%22%3E%3Cuse href=%22%23star%22/%3E%3C/g%3E%3Cg transform=%22translate(18 258) rotate(-13 13 13) scale(0.63)%22%3E%3Cuse href=%22%23star%22/%3E%3C/g%3E%3Cg transform=%22translate(378 258) rotate(-13 13 13) scale(0.63)%22%3E%3Cuse href=%22%23star%22/%3E%3C/g%3E%3Cg transform=%22translate(214 275) rotate(27 14 14) scale(0.72)%22%3E%3Cuse href=%22%23reader%22/%3E%3C/g%3E%3Cg transform=%22translate(195 99) rotate(-26 15 15) scale(0.75)%22%3E%3Cuse href=%22%23sleepy%22/%3E%3C/g%3E%3Cg transform=%22translate(-52 -0) rotate(32 15 15) scale(0.74)%22%3E%3Cuse href=%22%23happy%22/%3E%3C/g%3E%3Cg transform=%22translate(-52 360) rotate(32 15 15) scale(0.74)%22%3E%3Cuse href=%22%23happy%22/%3E%3C/g%3E%3Cg transform=%22translate(308 -0) rotate(32 15 15) scale(0.74)%22%3E%3Cuse href=%22%23happy%22/%3E%3C/g%3E%3Cg transform=%22translate(308 360) rotate(32 15 15) scale(0.74)%22%3E%3Cuse href=%22%23happy%22/%3E%3C/g%3E%3Cg transform=%22translate(166 102) rotate(-13 12 12) scale(0.59)%22%3E%3Cuse href=%22%23star%22/%3E%3C/g%3E%3Cg transform=%22translate(89 115) rotate(10 12 12) scale(0.60)%22%3E%3Cuse href=%22%23star%22/%3E%3C/g%3E%3Cg transform=%22translate(235 64) rotate(30 16 16) scale(0.82)%22%3E%3Cuse href=%22%23love%22/%3E%3C/g%3E%3Cg transform=%22translate(-59 271) rotate(-3 16 16) scale(0.78)%22%3E%3Cuse href=%22%23idea%22/%3E%3C/g%3E%3Cg transform=%22translate(301 271) rotate(-3 16 16) scale(0.78)%22%3E%3Cuse href=%22%23idea%22/%3E%3C/g%3E%3Cg transform=%22translate(-35 161) rotate(18 16 16) scale(0.80)%22%3E%3Cuse href=%22%23happy%22/%3E%3C/g%3E%3Cg transform=%22translate(325 161) rotate(18 16 16) scale(0.80)%22%3E%3Cuse href=%22%23happy%22/%3E%3C/g%3E%3Cg transform=%22translate(190 288) rotate(10 11 11) scale(0.57)%22%3E%3Ctext fill=%22rgba(30,40,60,0.15)%22 stroke=%22none%22 x=%2220%22 y=%2226%22 font-size=%2213%22 text-anchor=%22middle%22%3Edas%3C/text%3E%3C/g%3E%3Cg transform=%22translate(-45 81) rotate(32 12 12) scale(0.58)%22%3E%3Cuse href=%22%23dots%22/%3E%3C/g%3E%3Cg transform=%22translate(315 81) rotate(32 12 12) scale(0.58)%22%3E%3Cuse href=%22%23dots%22/%3E%3C/g%3E%3Cg transform=%22translate(133 26) rotate(7 15 15) scale(0.76)%22%3E%3Cuse href=%22%23brain%22/%3E%3C/g%3E%3Cg transform=%22translate(133 386) rotate(7 15 15) scale(0.76)%22%3E%3Cuse href=%22%23brain%22/%3E%3C/g%3E%3Cg transform=%22translate(267 -6) rotate(-24 14 14) scale(0.70)%22%3E%3Cuse href=%22%23reader%22/%3E%3C/g%3E%3Cg transform=%22translate(267 354) rotate(-24 14 14) scale(0.70)%22%3E%3Cuse href=%22%23reader%22/%3E%3C/g%3E%3Cg transform=%22translate(89 247) rotate(8 14 14) scale(0.68)%22%3E%3Cuse href=%22%23reader%22/%3E%3C/g%3E%3Cg transform=%22translate(79 60) rotate(27 12 12) scale(0.58)%22%3E%3Ctext fill=%22rgba(30,40,60,0.15)%22 stroke=%22none%22 x=%2220%22 y=%2226%22 font-size=%2218%22 text-anchor=%22middle%22%3E%C3%9F%3C/text%3E%3C/g%3E%3Cg transform=%22translate(102 -39) rotate(19 11 11) scale(0.55)%22%3E%3Cuse href=%22%23book%22/%3E%3C/g%3E%3Cg transform=%22translate(102 321) rotate(19 11 11) scale(0.55)%22%3E%3Cuse href=%22%23book%22/%3E%3C/g%3E%3Cg transform=%22translate(33 230) rotate(19 16 16) scale(0.78)%22%3E%3Cuse href=%22%23happy%22/%3E%3C/g%3E%3Cg transform=%22translate(393 230) rotate(19 16 16) scale(0.78)%22%3E%3Cuse href=%22%23happy%22/%3E%3C/g%3E%3Cg transform=%22translate(201 -10) rotate(24 15 15) scale(0.75)%22%3E%3Cuse href=%22%23brain%22/%3E%3C/g%3E%3Cg transform=%22translate(201 350) rotate(24 15 15) scale(0.75)%22%3E%3Cuse href=%22%23brain%22/%3E%3C/g%3E%3Cg transform=%22translate(-60 233) rotate(19 17 17) scale(0.83)%22%3E%3Cuse href=%22%23love%22/%3E%3C/g%3E%3Cg transform=%22translate(300 233) rotate(19 17 17) scale(0.83)%22%3E%3Cuse href=%22%23love%22/%3E%3C/g%3E%3Cg transform=%22translate(158 -7) rotate(34 11 11) scale(0.55)%22%3E%3Ctext fill=%22rgba(30,40,60,0.15)%22 stroke=%22none%22 x=%2220%22 y=%2226%22 font-size=%2218%22 text-anchor=%22middle%22%3E%C3%9F%3C/text%3E%3C/g%3E%3Cg transform=%22translate(158 353) rotate(34 11 11) scale(0.55)%22%3E%3Ctext fill=%22rgba(30,40,60,0.15)%22 stroke=%22none%22 x=%2220%22 y=%2226%22 font-size=%2218%22 text-anchor=%22middle%22%3E%C3%9F%3C/text%3E%3C/g%3E%3Cg transform=%22translate(-5 209) rotate(-17 13 13) scale(0.65)%22%3E%3Cuse href=%22%23book%22/%3E%3C/g%3E%3Cg transform=%22translate(355 209) rotate(-17 13 13) scale(0.65)%22%3E%3Cuse href=%22%23book%22/%3E%3C/g%3E%3Cg transform=%22translate(277 200) rotate(-31 17 17) scale(0.85)%22%3E%3Cuse href=%22%23happy%22/%3E%3C/g%3E%3Cg transform=%22translate(230 115) rotate(22 16 16) scale(0.79)%22%3E%3Cuse href=%22%23reader%22/%3E%3C/g%3E%3Cg transform=%22translate(90 32) rotate(-27 14 14) scale(0.70)%22%3E%3Cuse href=%22%23happy%22/%3E%3C/g%3E%3Cg transform=%22translate(90 392) rotate(-27 14 14) scale(0.70)%22%3E%3Cuse href=%22%23happy%22/%3E%3C/g%3E%3Cg transform=%22translate(56 77) rotate(27 12 12) scale(0.61)%22%3E%3Cuse href=%22%23book%22/%3E%3C/g%3E%3Cg transform=%22translate(186 215) rotate(-1 12 12) scale(0.60)%22%3E%3Ctext fill=%22rgba(30,40,60,0.15)%22 stroke=%22none%22 x=%2220%22 y=%2226%22 font-size=%2218%22 text-anchor=%22middle%22%3E%21%3C/text%3E%3C/g%3E%3Cg transform=%22translate(-45 -37) rotate(-4 16 16) scale(0.78)%22%3E%3Cuse href=%22%23grad%22/%3E%3C/g%3E%3Cg transform=%22translate(-45 323) rotate(-4 16 16) scale(0.78)%22%3E%3Cuse href=%22%23grad%22/%3E%3C/g%3E%3Cg transform=%22translate(315 -37) rotate(-4 16 16) scale(0.78)%22%3E%3Cuse href=%22%23grad%22/%3E%3C/g%3E%3Cg transform=%22translate(315 323) rotate(-4 16 16) scale(0.78)%22%3E%3Cuse href=%22%23grad%22/%3E%3C/g%3E%3Cg transform=%22translate(128 125) rotate(17 15 15) scale(0.74)%22%3E%3Cuse href=%22%23reader%22/%3E%3C/g%3E%3Cg transform=%22translate(6 43) rotate(33 14 14) scale(0.72)%22%3E%3Cuse href=%22%23grad%22/%3E%3C/g%3E%3Cg transform=%22translate(366 43) rotate(33 14 14) scale(0.72)%22%3E%3Cuse href=%22%23grad%22/%3E%3C/g%3E%3Cg transform=%22translate(229 165) rotate(21 13 13) scale(0.65)%22%3E%3Ctext fill=%22rgba(30,40,60,0.15)%22 stroke=%22none%22 x=%2220%22 y=%2226%22 font-size=%2218%22 text-anchor=%22middle%22%3E%C3%B6%3C/text%3E%3C/g%3E%3Cg transform=%22translate(-43 136) rotate(25 12 12) scale(0.61)%22%3E%3Ctext fill=%22rgba(30,40,60,0.15)%22 stroke=%22none%22 x=%2220%22 y=%2226%22 font-size=%2213%22 text-anchor=%22middle%22%3Eder%3C/text%3E%3C/g%3E%3Cg transform=%22translate(317 136) rotate(25 12 12) scale(0.61)%22%3E%3Ctext fill=%22rgba(30,40,60,0.15)%22 stroke=%22none%22 x=%2220%22 y=%2226%22 font-size=%2213%22 text-anchor=%22middle%22%3Eder%3C/text%3E%3C/g%3E%3Cg transform=%22translate(96 181) rotate(-1 16 16) scale(0.81)%22%3E%3Cuse href=%22%23reader%22/%3E%3C/g%3E%3Cg transform=%22translate(237 -21) rotate(11 15 15) scale(0.75)%22%3E%3Cuse href=%22%23reader%22/%3E%3C/g%3E%3Cg transform=%22translate(237 339) rotate(11 15 15) scale(0.75)%22%3E%3Cuse href=%22%23reader%22/%3E%3C/g%3E%3Cg transform=%22translate(165 36) rotate(34 15 15) scale(0.74)%22%3E%3Cuse href=%22%23reader%22/%3E%3C/g%3E%3Cg transform=%22translate(-30 215) rotate(19 11 11) scale(0.53)%22%3E%3Cuse href=%22%23book%22/%3E%3C/g%3E%3Cg transform=%22translate(330 215) rotate(19 11 11) scale(0.53)%22%3E%3Cuse href=%22%23book%22/%3E%3C/g%3E%3Cg transform=%22translate(1 179) rotate(5 11 11) scale(0.56)%22%3E%3Cuse href=%22%23spark%22/%3E%3C/g%3E%3Cg transform=%22translate(361 179) rotate(5 11 11) scale(0.56)%22%3E%3Cuse href=%22%23spark%22/%3E%3C/g%3E%3Cg transform=%22translate(104 215) rotate(16 16 16) scale(0.78)%22%3E%3Cuse href=%22%23brain%22/%3E%3C/g%3E%3Cg transform=%22translate(27 -36) rotate(2 14 14) scale(0.70)%22%3E%3Cuse href=%22%23idea%22/%3E%3C/g%3E%3Cg transform=%22translate(27 324) rotate(2 14 14) scale(0.70)%22%3E%3Cuse href=%22%23idea%22/%3E%3C/g%3E%3Cg transform=%22translate(387 -36) rotate(2 14 14) scale(0.70)%22%3E%3Cuse href=%22%23idea%22/%3E%3C/g%3E%3Cg transform=%22translate(387 324) rotate(2 14 14) scale(0.70)%22%3E%3Cuse href=%22%23idea%22/%3E%3C/g%3E%3Cg transform=%22translate(201 244) rotate(-4 14 14) scale(0.69)%22%3E%3Cuse href=%22%23grad%22/%3E%3C/g%3E%3Cg transform=%22translate(-12 135) rotate(-25 13 13) scale(0.65)%22%3E%3Ctext fill=%22rgba(30,40,60,0.15)%22 stroke=%22none%22 x=%2220%22 y=%2226%22 font-size=%2213%22 text-anchor=%22middle%22%3Eder%3C/text%3E%3C/g%3E%3Cg transform=%22translate(348 135) rotate(-25 13 13) scale(0.65)%22%3E%3Ctext fill=%22rgba(30,40,60,0.15)%22 stroke=%22none%22 x=%2220%22 y=%2226%22 font-size=%2213%22 text-anchor=%22middle%22%3Eder%3C/text%3E%3C/g%3E%3Cg transform=%22translate(158 -35) rotate(13 12 12) scale(0.58)%22%3E%3Cuse href=%22%23star%22/%3E%3C/g%3E%3Cg transform=%22translate(158 325) rotate(13 12 12) scale(0.58)%22%3E%3Cuse href=%22%23star%22/%3E%3C/g%3E%3Cg transform=%22translate(43 196) rotate(17 14 14) scale(0.72)%22%3E%3Cuse href=%22%23brain%22/%3E%3C/g%3E%3Cg transform=%22translate(292 45) rotate(-13 16 16) scale(0.82)%22%3E%3Cuse href=%22%23happy%22/%3E%3C/g%3E%3Cg transform=%22translate(194 68) rotate(16 14 14) scale(0.68)%22%3E%3Cuse href=%22%23love%22/%3E%3C/g%3E%3Cg transform=%22translate(88 -14) rotate(-10 12 12) scale(0.58)%22%3E%3Cuse href=%22%23star%22/%3E%3C/g%3E%3Cg transform=%22translate(88 346) rotate(-10 12 12) scale(0.58)%22%3E%3Cuse href=%22%23star%22/%3E%3C/g%3E%3Cg transform=%22translate(171 135) rotate(32 10 10) scale(0.50)%22%3E%3Ctext fill=%22rgba(30,40,60,0.15)%22 stroke=%22none%22 x=%2220%22 y=%2226%22 font-size=%2218%22 text-anchor=%22middle%22%3E%C3%84%3C/text%3E%3C/g%3E%3Cg transform=%22translate(127 -42) rotate(19 14 14) scale(0.69)%22%3E%3Cuse href=%22%23sleepy%22/%3E%3C/g%3E%3Cg transform=%22translate(127 318) rotate(19 14 14) scale(0.69)%22%3E%3Cuse href=%22%23sleepy%22/%3E%3C/g%3E%3Cg transform=%22translate(43 122) rotate(-7 15 15) scale(0.77)%22%3E%3Cuse href=%22%23idea%22/%3E%3C/g%3E%3Cg transform=%22translate(66 14) rotate(33 14 14) scale(0.69)%22%3E%3Cuse href=%22%23grad%22/%3E%3C/g%3E%3Cg transform=%22translate(66 374) rotate(33 14 14) scale(0.69)%22%3E%3Cuse href=%22%23grad%22/%3E%3C/g%3E%3Cg transform=%22translate(11 -3) rotate(-34 12 12) scale(0.59)%22%3E%3Ctext fill=%22rgba(30,40,60,0.15)%22 stroke=%22none%22 x=%2220%22 y=%2226%22 font-size=%2218%22 text-anchor=%22middle%22%3E%3F%3C/text%3E%3C/g%3E%3Cg transform=%22translate(11 357) rotate(-34 12 12) scale(0.59)%22%3E%3Ctext fill=%22rgba(30,40,60,0.15)%22 stroke=%22none%22 x=%2220%22 y=%2226%22 font-size=%2218%22 text-anchor=%22middle%22%3E%3F%3C/text%3E%3C/g%3E%3Cg transform=%22translate(371 -3) rotate(-34 12 12) scale(0.59)%22%3E%3Ctext fill=%22rgba(30,40,60,0.15)%22 stroke=%22none%22 x=%2220%22 y=%2226%22 font-size=%2218%22 text-anchor=%22middle%22%3E%3F%3C/text%3E%3C/g%3E%3Cg transform=%22translate(371 357) rotate(-34 12 12) scale(0.59)%22%3E%3Ctext fill=%22rgba(30,40,60,0.15)%22 stroke=%22none%22 x=%2220%22 y=%2226%22 font-size=%2218%22 text-anchor=%22middle%22%3E%3F%3C/text%3E%3C/g%3E%3Cg transform=%22translate(108 292) rotate(-9 13 13) scale(0.65)%22%3E%3Ctext fill=%22rgba(30,40,60,0.15)%22 stroke=%22none%22 x=%2220%22 y=%2226%22 font-size=%2213%22 text-anchor=%22middle%22%3Edie%3C/text%3E%3C/g%3E%3Cg transform=%22translate(194 171) rotate(-9 14 14) scale(0.70)%22%3E%3Cuse href=%22%23reader%22/%3E%3C/g%3E%3Cg transform=%22translate(28 17) rotate(17 14 14) scale(0.71)%22%3E%3Cuse href=%22%23brain%22/%3E%3C/g%3E%3Cg transform=%22translate(28 377) rotate(17 14 14) scale(0.71)%22%3E%3Cuse href=%22%23brain%22/%3E%3C/g%3E%3Cg transform=%22translate(388 17) rotate(17 14 14) scale(0.71)%22%3E%3Cuse href=%22%23brain%22/%3E%3C/g%3E%3Cg transform=%22translate(388 377) rotate(17 14 14) scale(0.71)%22%3E%3Cuse href=%22%23brain%22/%3E%3C/g%3E%3Cg transform=%22translate(69 -50) rotate(-2 14 14) scale(0.71)%22%3E%3Cuse href=%22%23brain%22/%3E%3C/g%3E%3Cg transform=%22translate(69 310) rotate(-2 14 14) scale(0.71)%22%3E%3Cuse href=%22%23brain%22/%3E%3C/g%3E%3Cg transform=%22translate(117 76) rotate(19 10 10) scale(0.52)%22%3E%3Ctext fill=%22rgba(30,40,60,0.15)%22 stroke=%22none%22 x=%2220%22 y=%2226%22 font-size=%2213%22 text-anchor=%22middle%22%3Eder%3C/text%3E%3C/g%3E%3Cg transform=%22translate(284 -31) rotate(-22 11 11) scale(0.53)%22%3E%3Cuse href=%22%23book%22/%3E%3C/g%3E%3Cg transform=%22translate(284 329) rotate(-22 11 11) scale(0.53)%22%3E%3Cuse href=%22%23book%22/%3E%3C/g%3E%3Cg transform=%22translate(270 79) rotate(29 15 15) scale(0.76)%22%3E%3Cuse href=%22%23happy%22/%3E%3C/g%3E%3Cg transform=%22translate(236 -56) rotate(-2 15 15) scale(0.73)%22%3E%3Cuse href=%22%23idea%22/%3E%3C/g%3E%3Cg transform=%22translate(236 304) rotate(-2 15 15) scale(0.73)%22%3E%3Cuse href=%22%23idea%22/%3E%3C/g%3E%3Cg transform=%22translate(289 162) rotate(-13 15 15) scale(0.77)%22%3E%3Cuse href=%22%23idea%22/%3E%3C/g%3E%3Cg transform=%22translate(-8 -18) rotate(4 11 11) scale(0.54)%22%3E%3Cuse href=%22%23dots%22/%3E%3C/g%3E%3Cg transform=%22translate(-8 342) rotate(4 11 11) scale(0.54)%22%3E%3Cuse href=%22%23dots%22/%3E%3C/g%3E%3Cg transform=%22translate(352 -18) rotate(4 11 11) scale(0.54)%22%3E%3Cuse href=%22%23dots%22/%3E%3C/g%3E%3Cg transform=%22translate(352 342) rotate(4 11 11) scale(0.54)%22%3E%3Cuse href=%22%23dots%22/%3E%3C/g%3E%3Cg transform=%22translate(157 222) rotate(32 11 11) scale(0.54)%22%3E%3Cuse href=%22%23heart%22/%3E%3C/g%3E%3Cg transform=%22translate(-18 97) rotate(7 15 15) scale(0.75)%22%3E%3Cuse href=%22%23brain%22/%3E%3C/g%3E%3Cg transform=%22translate(342 97) rotate(7 15 15) scale(0.75)%22%3E%3Cuse href=%22%23brain%22/%3E%3C/g%3E%3Cg transform=%22translate(224 16) rotate(23 15 15) scale(0.77)%22%3E%3Cuse href=%22%23reader%22/%3E%3C/g%3E%3Cg transform=%22translate(224 376) rotate(23 15 15) scale(0.77)%22%3E%3Cuse href=%22%23reader%22/%3E%3C/g%3E%3Cg transform=%22translate(163 71) rotate(7 14 14) scale(0.70)%22%3E%3Cuse href=%22%23grad%22/%3E%3C/g%3E%3Cg transform=%22translate(197 24) rotate(-25 10 10) scale(0.52)%22%3E%3Cuse href=%22%23heart%22/%3E%3C/g%3E%3Cg transform=%22translate(197 384) rotate(-25 10 10) scale(0.52)%22%3E%3Cuse href=%22%23heart%22/%3E%3C/g%3E%3Cg transform=%22translate(-7 237) rotate(20 12 12) scale(0.61)%22%3E%3Ctext fill=%22rgba(30,40,60,0.15)%22 stroke=%22none%22 x=%2220%22 y=%2226%22 font-size=%2218%22 text-anchor=%22middle%22%3E%21%3C/text%3E%3C/g%3E%3Cg transform=%22translate(353 237) rotate(20 12 12) scale(0.61)%22%3E%3Ctext fill=%22rgba(30,40,60,0.15)%22 stroke=%22none%22 x=%2220%22 y=%2226%22 font-size=%2218%22 text-anchor=%22middle%22%3E%21%3C/text%3E%3C/g%3E%3Cg transform=%22translate(123 160) rotate(-13 14 14) scale(0.68)%22%3E%3Cuse href=%22%23brain%22/%3E%3C/g%3E%3Cg transform=%22translate(253 143) rotate(16 14 14) scale(0.69)%22%3E%3Cuse href=%22%23reader%22/%3E%3C/g%3E%3Cg transform=%22translate(248 275) rotate(10 14 14) scale(0.69)%22%3E%3Cuse href=%22%23reader%22/%3E%3C/g%3E%3Cg transform=%22translate(250 199) rotate(10 11 11) scale(0.56)%22%3E%3Ctext fill=%22rgba(30,40,60,0.15)%22 stroke=%22none%22 x=%2220%22 y=%2226%22 font-size=%2213%22 text-anchor=%22middle%22%3Edie%3C/text%3E%3C/g%3E%3Cg transform=%22translate(279 260) rotate(2 11 11) scale(0.57)%22%3E%3Cuse href=%22%23dots%22/%3E%3C/g%3E%3C/g%3E%3C/svg%3E"); }
    </style>
</head>
<body>

<div class="modal-overlay" id="themaModalOverlay">
    <div class="modal-content">
        <h3 style="margin-top:0; color: var(--md-accent-text);" id="modalTitle">Auflösung</h3>
        <div id="modalThemaText" style="margin: 16px 0; font-size: 0.95rem; line-height: 1.6; text-align: left;"></div>
        <div id="modalActionButtons" style="display: flex; gap: 6px; margin-bottom: 10px; flex-wrap: wrap;"></div>
        <button type="button" class="btn" onclick="closeThemaModal()" style="width: 100%;">Weiter 🚀</button>
    </div>
</div>

<!-- Fullscreen Image Zoom Overlay -->
<div class="image-fullscreen-overlay" id="imageFullscreenOverlay" onclick="closeFullscreenImage()">
    <img id="fullscreenImageTag" src="" alt="Vollbild Ansicht">
</div>

<div class="container">
    <div id="dashboard-view" class="view active">
        <header>
            <h1><span class="wordmark">vokab<span>iq</span></span></h1>
            <div class="level-path" id="levelPath" role="img" aria-label="Sprachniveau"></div>
            <div class="header-actions">
                <details class="account-menu sheet-menu" id="accountMenu">
                    <summary class="account-trigger" title="Konto">
                        <span class="account-avatar" aria-hidden="true"><?= htmlspecialchars(mb_strtoupper(mb_substr(trim((string)(($_SESSION['display_name'] ?? '') ?: ($_SESSION['username'] ?? ''))) ?: 'V', 0, 1, 'UTF-8'), 'UTF-8'), ENT_QUOTES, 'UTF-8') ?></span>
                        <span class="account-label">Konto</span>
                        <span class="account-chevron" aria-hidden="true"></span>
                    </summary>
                    <div class="account-popover sheet-panel account-panel" role="dialog" aria-label="Konto">
                        <div class="sheet-handle" aria-hidden="true"></div>
                        <div class="sheet-header">
                            <h2 class="sheet-title">Konto</h2>
                            <button type="button" class="sheet-close" onclick="closeSheet('accountMenu')" aria-label="Schließen">&times;</button>
                        </div>
                        <div class="sheet-body">
                        <div class="account-menu-theme">
                            <span class="theme-label-dark">Dunkel Mode</span>
                            <span class="theme-label-light">Helles Mode</span>
                            <?php theme_toggle(); ?>
                        </div>
                        <div class="account-menu-identity">
                            <span class="account-menu-caption">Angemeldet als</span>
                            <strong><?= htmlspecialchars((string)(($_SESSION['display_name'] ?? '') ?: ($_SESSION['username'] ?? 'Benutzer')), ENT_QUOTES, 'UTF-8') ?></strong>
                        </div>
                        <a class="account-menu-item" href="profile.php">👤 Mein Profil</a>
                        <a class="account-menu-item" href="community.php">👥 Community</a>
                        <a class="account-menu-item" href="index.php?api=export_csv">📥 CSV exportieren</a>
                        <a class="account-menu-item account-menu-logout" href="logout.php">↪ Abmelden</a>
                        </div>
                    </div>
                </details>
            </div>
        </header>

        <div class="daily-tracker" id="dailyTrackerWidget">
            <div class="tracker-info">
                <div class="tracker-icon" id="trackerIcon">😴</div>
                <div class="tracker-text">
                    <h3 id="trackerTitle">Tagesziel: 0 / 100 Wörter</h3>
                    <p id="trackerSubtitle">Fangen wir mit dem Wiederholen an!</p>
                </div>
            </div>
            <div class="tracker-progress-bar">
                <div class="tracker-progress-fill" id="trackerProgressFill" style="width: 0%;"></div>
            </div>
        </div>

        <div id="alertBox" class="alert" style="display: none;"></div>

        <div class="form-toggle-bar" id="formToggleBar">
            <button type="button" class="btn" onclick="openAddForm()" id="formToggleBtn"><span class="btn-ico" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 4h10l4 4v12H5z"/><path d="M15 4v4h4"/><path d="M12 11v6M9 14h6"/></svg></span>Neues Wort hinzufügen</button>
            <button type="button" class="btn btn-success" onclick="openGameSelection()"><span class="btn-ico" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M13 2L4 14h7l-1 8 9-12h-7z"/></svg></span>Training starten</button>
            <button type="button" class="btn btn-info" onclick="switchView('statistics')"><span class="btn-ico" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 20h16"/><path d="M7 16v-4M12 16V8M17 16v-7"/><path d="M5 9l5-4 4 3 5-4"/></svg></span>Statistiken</button>
        </div>

        <div class="form-container" id="formContainer">
            <div class="card">
                <h2 id="formTitle">Neues Wort hinzufügen</h2>
                <form id="wordForm" onsubmit="submitWordForm(event)">
                    <input type="hidden" id="original_wort" name="original_wort">

                    <div class="word-hero">
                        <label for="Wort" class="word-hero-label">
                            Wort <span class="required-badge">Pflichtfeld</span>
                        </label>
                        <div class="word-hero-row">
                            <input type="text" id="Wort" name="Wort" required autocomplete="off" placeholder="z.B. Haus, laufen, schnell" onkeydown="handleWortKeydown(event)">
                            <button type="button" class="btn btn-info ai-btn" onclick="fillWordWithAI()" id="aiFillBtn">Mit KI ausfüllen</button>
                            <button type="button" class="btn btn-info ai-btn" onclick="generateAiImageForEditWord()" id="editImageBtn" style="display: none;">Bild generieren</button>
                        </div>
                        <p class="word-hero-hint">Gib das Wort ein und lass die KI die restlichen Felder ausfüllen – oder fülle sie unten selbst aus.</p>
                        <div class="edit-image-container" id="editImageContainer" style="display: none;">
                            <img id="editImageTag" src="" alt="Wort Bild" onclick="openFullscreenImage(this.src)" title="Zum Vergrößern anklicken">
                        </div>
                    </div>

                    <div class="form-group-section">
                        <label for="sharepoint_list">SharePoint-Liste</label>
                        <input type="text" id="sharepoint_list" name="sharepoint_list" placeholder="z.B., Listenname">

                        <label for="Thema">Thema</label>
                        <input type="text" id="Thema" name="Thema" placeholder="z.B., Wirtschaft, Alltag">

                        <label for="Wortarten">Wortart / Unterkategorie</label>
                        <input type="text" id="Wortarten" name="Wortarten" placeholder="z.B., Nomen, Verb">
                    </div>

                    <div class="form-group-section">
                        <label for="VerbFlag">Ist Verb?</label>
                        <select id="VerbFlag" name="VerbFlag" onchange="toggleVerbFields()">
                            <option value="0">Nein</option>
                            <option value="1">Ja</option>
                        </select>

                        <div id="verbFieldsContainer" style="display: none;">
                            <label for="Konjugation">Konjugation</label>
                            <input type="text" id="Konjugation" name="Konjugation" placeholder="z.B., du läufst, er lief, ist gelaufen">

                            <label for="grundverb">Grundverb</label>
                            <input type="text" id="grundverb" name="grundverb" placeholder="z.B., interessieren">

                            <label for="praefix">Präfix</label>
                            <input type="text" id="praefix" name="praefix" placeholder="z.B., auf">

                            <label for="praeposition_kollokation">Präpositionalkollokation</label>
                            <input type="text" id="praeposition_kollokation" name="praeposition_kollokation" placeholder="z.B., sich interessieren **für**">
                        </div>
                    </div>

                    <div class="form-group-section">
                        <div id="artikelGroup">
                            <label for="Artikel">Artikel</label>
                            <input type="text" id="Artikel" name="Artikel" placeholder="z.B., der, die, das">
                        </div>

                        <div id="pluralGroup">
                            <label for="Plural">Plural</label>
                            <input type="text" id="Plural" name="Plural" placeholder="z.B., die Autos">
                        </div>

                        <label for="Übersetzung">Übersetzung</label>
                        <input type="text" id="Übersetzung" name="Übersetzung" placeholder="z.B. Englisch, Französisch, Italienisch">

                        <label for="synonym">Synonym</label>
                        <input type="text" id="synonym" name="synonym">

                        <label for="Beispiel">Beispiel</label>
                        <textarea id="Beispiel" name="Beispiel" rows="2" placeholder="Beispiel auf Deutsch und Französisch"></textarea>
                    </div>

                    <div class="form-group-section">
                        <label>Kenntnisse <span class="field-auto-hint">optional – ohne Auswahl bleibt der Status, wie er ist</span></label>
                        <input type="hidden" id="Kenntnisse" value="">
                        <div class="rate-group is-picker" id="formKenntnisse" role="radiogroup" aria-label="Kenntnisse wählen">
                            <button type="button" class="rate-btn r-direkt-aktiv" data-rate="direkt_aktiv" onclick="pickFormKenntnisse('direkt_aktiv')" title="Direkt aktiv (Score 10)" role="radio" aria-checked="false"><span class="rate-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M7 13l5-5 5 5M7 19l5-5 5 5"/></svg></span><span>direkt aktiv</span></button>
                            <?php foreach (RATE_OPTIONS as [$rKey, $rClass, $rIcon, $rLabel]): ?>
                                <button type="button" class="rate-btn <?= $rClass ?>" data-rate="<?= $rKey ?>" onclick="pickFormKenntnisse('<?= $rKey ?>')" title="<?= $rLabel ?>" role="radio" aria-checked="false"><span class="rate-icon" aria-hidden="true"><?= $rIcon ?></span><span><?= $rLabel ?></span></button>
                            <?php endforeach; ?>
                        </div>

                        <label for="Status" style="margin-top: 12px;">Status <span class="field-auto-hint">wird automatisch berechnet</span></label>
                        <input type="text" id="Status" value="neu" readonly tabindex="-1" class="readonly-field" aria-readonly="true">
                    </div>

                    <div style="display: flex; gap: 12px; margin-top: 1rem;">
                        <button type="submit" class="btn" id="formSubmitBtn" style="flex: 1;">Wort speichern</button>
                        <button type="button" class="btn btn-secondary" onclick="cancelWordForm()">Abbrechen</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="card">
            <form id="filterForm" onsubmit="event.preventDefault(); loadDashboardData(true);">
                <div class="filter-toolbar">
                    <details class="filter-menu sheet-menu" id="filterMenu">
                        <summary class="filter-trigger" aria-label="Filter" title="Filter">
                            <svg class="filter-icon" viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false"><path d="M3 4h18l-7 8.5V19l-4 2v-8.5z" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/></svg>
                            <span class="filter-badge" id="filterBadge" hidden>0</span>
                        </summary>
                        <div class="filter-popover sheet-panel filter-panel" role="dialog" aria-label="Filter">
                            <div class="sheet-handle" aria-hidden="true"></div>
                            <div class="sheet-header">
                                <h3 class="sheet-title">Filter</h3>
                                <button type="button" class="sheet-close" onclick="closeFilterMenu()" aria-label="Schließen">&times;</button>
                            </div>
                            <div class="sheet-body">
                            <section class="filter-group">
                                <h3 class="filter-group-title">🔤 Anfangsbuchstabe</h3>
                                <div class="alphabet-bar" id="alphabetBar">
                                    <button type="button" class="alphabet-btn active" onclick="setLetterFilter('')">Alle</button>
                                    <button type="button" class="alphabet-btn" onclick="setLetterFilter('A')">A</button>
                                    <button type="button" class="alphabet-btn" onclick="setLetterFilter('B')">B</button>
                                    <button type="button" class="alphabet-btn" onclick="setLetterFilter('C')">C</button>
                                    <button type="button" class="alphabet-btn" onclick="setLetterFilter('D')">D</button>
                                    <button type="button" class="alphabet-btn" onclick="setLetterFilter('E')">E</button>
                                    <button type="button" class="alphabet-btn" onclick="setLetterFilter('F')">F</button>
                                    <button type="button" class="alphabet-btn" onclick="setLetterFilter('G')">G</button>
                                    <button type="button" class="alphabet-btn" onclick="setLetterFilter('H')">H</button>
                                    <button type="button" class="alphabet-btn" onclick="setLetterFilter('I')">I</button>
                                    <button type="button" class="alphabet-btn" onclick="setLetterFilter('J')">J</button>
                                    <button type="button" class="alphabet-btn" onclick="setLetterFilter('K')">K</button>
                                    <button type="button" class="alphabet-btn" onclick="setLetterFilter('L')">L</button>
                                    <button type="button" class="alphabet-btn" onclick="setLetterFilter('M')">M</button>
                                    <button type="button" class="alphabet-btn" onclick="setLetterFilter('N')">N</button>
                                    <button type="button" class="alphabet-btn" onclick="setLetterFilter('O')">O</button>
                                    <button type="button" class="alphabet-btn" onclick="setLetterFilter('P')">P</button>
                                    <button type="button" class="alphabet-btn" onclick="setLetterFilter('Q')">Q</button>
                                    <button type="button" class="alphabet-btn" onclick="setLetterFilter('R')">R</button>
                                    <button type="button" class="alphabet-btn" onclick="setLetterFilter('S')">S</button>
                                    <button type="button" class="alphabet-btn" onclick="setLetterFilter('T')">T</button>
                                    <button type="button" class="alphabet-btn" onclick="setLetterFilter('U')">U</button>
                                    <button type="button" class="alphabet-btn" onclick="setLetterFilter('V')">V</button>
                                    <button type="button" class="alphabet-btn" onclick="setLetterFilter('W')">W</button>
                                    <button type="button" class="alphabet-btn" onclick="setLetterFilter('X')">X</button>
                                    <button type="button" class="alphabet-btn" onclick="setLetterFilter('Y')">Y</button>
                                    <button type="button" class="alphabet-btn" onclick="setLetterFilter('Z')">Z</button>
                                    <button type="button" class="alphabet-btn" onclick="setLetterFilter('Ä')">Ä</button>
                                    <button type="button" class="alphabet-btn" onclick="setLetterFilter('Ö')">Ö</button>
                                    <button type="button" class="alphabet-btn" onclick="setLetterFilter('Ü')">Ü</button>
                                </div>
                            </section>

                            <section class="filter-group">
                                <h3 class="filter-group-title">🗂️ Kategorien</h3>
                                <div class="filter-grid">
                                <label class="filter-field">
                                    <span>Liste</span>
                                    <select id="filterList" name="sharepoint_list" onchange="loadDashboardData(true)">
                                        <option value="">Alle Listen</option>
                                        <?php foreach ($initialLists as $l): ?>
                                            <option value="<?= htmlspecialchars($l) ?>"><?= htmlspecialchars($l) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                                <label class="filter-field">
                                    <span>Thema</span>
                                    <select id="filterThema" name="thema" onchange="onDashboardThemaChange()">
                                        <option value="">Alle Themen</option>
                                        <?php foreach ($initialThemen as $thm): ?>
                                            <option value="<?= htmlspecialchars($thm) ?>"><?= htmlspecialchars($thm) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                                <label class="filter-field">
                                    <span>Wortart</span>
                                    <select id="filterWortart" name="wortart" onchange="loadDashboardData(true)">
                                        <option value="">Alle Wortarten</option>
                                        <?php foreach ($initialWortarten as $wa): ?>
                                            <option value="<?= htmlspecialchars($wa) ?>"><?= htmlspecialchars($wa) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                                </div>
                            </section>

                            <section class="filter-group">
                                <h3 class="filter-group-title">📈 Status</h3>
                                <div class="filter-grid">
                                <label class="filter-field">
                                    <span>Status</span>
                                    <select id="filterStatus" name="status" onchange="loadDashboardData(true)">
                                        <option value="">Alle Status</option>
                                        <?php foreach ($initialStatuses as $st): ?>
                                            <option value="<?= htmlspecialchars($st) ?>"><?= htmlspecialchars($st) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                                </div>
                            </section>

                            <section class="filter-group">
                                <h3 class="filter-group-title">🔧 Verben</h3>
                                <div class="filter-grid">
                                <label class="filter-field">
                                    <span>Verbtyp</span>
                                    <select id="filterVerbFlag" name="verb_flag" onchange="loadDashboardData(true)">
                                        <option value="">Alle Wörter</option>
                                        <option value="1">Nur Verben</option>
                                        <option value="0">Kein Verb</option>
                                    </select>
                                </label>
                                <label class="filter-field">
                                    <span>Grundverb</span>
                                    <select id="filterGrundverb" name="grundverb" onchange="loadDashboardData(true)">
                                        <option value="">Alle Grundverben</option>
                                        <?php foreach ($initialGrundverben as $gv): ?>
                                            <option value="<?= htmlspecialchars($gv) ?>"><?= htmlspecialchars($gv) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                                <label class="filter-field">
                                    <span>Präfix</span>
                                    <select id="filterPraefix" name="praefix" onchange="loadDashboardData(true)">
                                        <option value="">Alle Präfixe</option>
                                        <?php foreach ($initialPraefixe as $pr): ?>
                                            <option value="<?= htmlspecialchars($pr) ?>"><?= htmlspecialchars($pr) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                                </div>
                            </section>

                            </div>
                            <div class="filter-popover-footer sheet-footer">
                                <button type="button" class="btn btn-secondary" onclick="resetFilters()">Zurücksetzen</button>
                                <button type="button" class="btn" onclick="closeFilterMenu()">Fertig</button>
                            </div>
                        </div>
                    </details>

                    <input type="text" id="filterSearch" placeholder="Wort suchen (beginnt mit)..." oninput="handleSearchInput()">
                    <input type="text" id="filterTranslationSearch" placeholder="Übersetzung suchen..." oninput="handleTranslationSearchInput()">
                </div>
            </form>

            <div class="table-responsive" id="tableResponsiveContainer">
                <table>
                    <thead>
                        <tr>
                            <th onclick="setSort('Artikel')">Artikel ↕</th>
                            <th onclick="setSort('Wort')">Wort ↕</th>
                            <th>Übersetzung</th>
                            <th>Kenntnisse</th>
                            <th onclick="setSort('Status')">Status ↕</th>
                            <th>Aktion</th>
                        </tr>
                    </thead>
                    <tbody id="wordTableBody">
                        <?php if (empty($initialWords)): ?>
                            <tr><td colspan="6" style="text-align: center; color: var(--md-text-muted); padding: 2rem;">Keine Vokabeln gefunden.</td></tr>
                        <?php else: ?>
                            <?php foreach ($initialWords as $row):
                                $artClass = '';
                                $artLower = strtolower(trim($row['Artikel'] ?? ''));
                                if ($artLower === 'der') $artClass = 'wort-der';
                                elseif ($artLower === 'die') $artClass = 'wort-die';
                                elseif ($artLower === 'das') $artClass = 'wort-das';
                                else $artClass = 'wort-other';

                                $isVerb = (isset($row['VerbFlag']) && (int)$row['VerbFlag'] === 1);
                                $wortArg = jsArg($row['Wort'] ?? '');
                            ?>
                                <tr>
                                    <td data-label="Artikel"><strong><?= htmlspecialchars($row['Artikel'] ?? '') ?></strong></td>
                                    <td data-label="Wort" class="wort-cell <?= $artClass ?>"><?= htmlspecialchars($row['Wort'] ?? '') ?></td>
                                    <td data-label="Übersetzung">
                                        <span class="story-word-trans" style="display: none; color: var(--md-accent-text);"><?= htmlspecialchars($row['Übersetzung'] ?? '') ?></span>
                                        <button type="button" class="btn btn-secondary" style="padding: 2px 6px; font-size: 0.75rem; margin-top: 4px;" onclick="toggleStoryTransTable(this)">Übersetzung anzeigen</button>
                                    </td>
                                    <td class="kenntnisse-cell" data-label="Kenntnisse">
                                        <div class="rate-group" role="group" aria-label="Kenntnisse bewerten">
                                            <?php if (strtolower(trim($row['Status'] ?? '')) !== 'aktiva'): ?>
                                                <button type="button" class="rate-btn r-direkt-aktiv" onclick="inlinePromoteWord(<?= $wortArg ?>, this)" title="Direkt aktiv (Score 10)"><span class="rate-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M7 13l5-5 5 5M7 19l5-5 5 5"/></svg></span><span>direkt aktiv</span></button>
                                            <?php endif; ?>
                                            <?php foreach (RATE_OPTIONS as [$rKey, $rClass, $rIcon, $rLabel]): ?>
                                                <button type="button" class="rate-btn <?= $rClass ?>" onclick="inlineRateWord(<?= $wortArg ?>, '<?= $rKey ?>', this)" title="<?= $rLabel ?>"><span class="rate-icon" aria-hidden="true"><?= $rIcon ?></span><span><?= $rLabel ?></span></button>
                                            <?php endforeach; ?>
                                        </div>
                                    </td>
                                    <td data-label="Status" class="status-cell" data-status="<?= htmlspecialchars(strtolower(trim($row['Status'] ?? ''))) ?>"><?= htmlspecialchars($row['Status'] ?? '') ?></td>
                                    <td class="aktion-cell" data-label="Aktion">
                                        <div class="rate-group action-group" role="group" aria-label="Wort-Aktionen"><button type="button" class="rate-btn a-edit" onclick="editWord(<?= htmlspecialchars(json_encode($row), ENT_QUOTES, 'UTF-8') ?>)" title="Bearbeiten"><span class="rate-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 20h4L19 9l-4-4L4 16z"/><path d="M13.5 6.5l4 4"/></svg></span><span>Bear&shy;beiten</span></button><button type="button" class="rate-btn a-train" onclick="trainSpecificWord(<?= $wortArg ?>)" title="Üben"><span class="rate-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M13 2L4 14h7l-1 8 9-12h-7z"/></svg></span><span>Üben</span></button><button type="button" class="rate-btn a-delete" onclick="deleteWord(<?= $wortArg ?>)" title="Löschen"><span class="rate-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 7h16M9 7V4h6v3M6.5 7l1 13h9l1-13M10 11v5M14 11v5"/></svg></span><span>Löschen</span></button></div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div id="game-view" class="view">
        <div class="game-container">
            <header>
                <div class="header-title">
                    <button type="button" class="back-icon" onclick="switchView('dashboard')" title="Zurück zum Dashboard" aria-label="Zurück zum Dashboard">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 18l-6-6 6-6"/></svg>
                    </button>
                    <h1><span class="h-ico" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M13 2L4 14h7l-1 8 9-12h-7z"/></svg></span>Vokabeltraining</h1>
                </div>
            </header>

            <div id="trainingLoader" class="card training-loader" role="status" aria-live="polite">
                <div class="training-loader-dots" aria-hidden="true"><span></span><span></span><span></span></div>
                <div>Training wird geladen…</div>
            </div>

            <div id="gameSetupCard" class="card">
                <h2>Trainingseinstellungen</h2>
                <form id="gameSetupForm" onsubmit="startGameSession(event)">
                    <label for="gameModeSelect">Trainingsmodus auswählen:</label>
                    <select id="gameModeSelect" name="game_mode" style="margin-bottom: 1.2rem;" onchange="onGameModeChange()">
                        <option value="standard">Karteikarten</option>
                        <option value="der_die_das">Der-Die-Das Training</option>
                        <option value="deutsch_meister">Deutsch Meister</option>
                    </select>

                    <!-- Status Selection moved right under game selection with Modern Pill UI -->
                    <div id="statusSelectionGroup" class="settings-fieldset" style="margin-bottom: 1.2rem;">
                        <div class="settings-summary"><span id="statusLabelText">Status zum Wiederholen auswählen</span></div>
                        <div class="settings-content" style="border-top: none; margin-top: 6px; padding-top: 0;">
                            <div class="settings-controls-bar">
                                <button type="button" class="settings-action-link" onclick="toggleAllStatusPills(true)">Alle auswählen</button>
                                <span style="color: var(--md-border);">|</span>
                                <button type="button" class="settings-action-link" onclick="toggleAllStatusPills(false)">Alle abwählen</button>
                            </div>
                            <div class="status-pill-grid" id="statusPillContainer"></div>
                            <div id="hiddenStatusInputsContainer"></div>
                        </div>
                    </div>

                    <!-- Hidden Advanced Settings Accordion -->
                    <div class="settings-fieldset" style="margin-bottom: 1.2rem;">
                        <details id="advancedSettingsDetails">
                            <summary class="settings-summary">Erweiterte Einstellungen (Advanced Settings)</summary>
                            <div class="settings-content">
                                <div style="margin-bottom: 1.2rem;">
                                    <label style="margin-bottom: 6px;">Sortierreihenfolge (Top-N):</label>
                                    <input type="hidden" id="sortOrderInput" name="sort_order" value="ASC">
                                    <button type="button" class="toggle-switch-btn" id="sortOrderToggleBtn" onclick="toggleSortOrder()">
                                        <span>Sortierung: <strong id="sortOrderLabel">Original (Aufsteigend)</strong></span>
                                        <span class="toggle-switch-badge" id="sortOrderBadge">ASC</span>
                                    </button>
                                </div>

                                <div id="standardGameOptions">
                                    <div class="settings-fieldset" style="margin-bottom: 12px;">
                                        <details>
                                            <summary class="settings-summary">SharePoint-Listen auswählen (Optional)</summary>
                                            <div class="settings-content">
                                                <div class="settings-controls-bar">
                                                    <button type="button" class="settings-action-link" onclick="toggleAllCheckboxes('gameListCheckboxes', true)">Alle auswählen</button>
                                                    <span style="color: var(--md-border);">|</span>
                                                    <button type="button" class="settings-action-link" onclick="toggleAllCheckboxes('gameListCheckboxes', false)">Alle abwählen</button>
                                                </div>
                                                <div class="checkbox-group" id="gameListCheckboxes"></div>
                                            </div>
                                        </details>
                                    </div>

                                    <div style="margin-bottom: 12px;">
                                        <label for="gameThemaSelect">Thema auswählen:</label>
                                        <select id="gameThemaSelect" name="game_thema" onchange="onGameThemaChange()">
                                            <option value="">Alle Themen</option>
                                        </select>
                                    </div>

                                    <div class="settings-fieldset" style="margin-bottom: 0;">
                                        <details>
                                            <summary class="settings-summary">Wortarten für das gewählte Thema auswählen</summary>
                                            <div class="settings-content">
                                                <div class="settings-controls-bar">
                                                    <button type="button" class="settings-action-link" onclick="toggleAllCheckboxes('gameCategoryCheckboxes', true)">Alle auswählen</button>
                                                    <span style="color: var(--md-border);">|</span>
                                                    <button type="button" class="settings-action-link" onclick="toggleAllCheckboxes('gameCategoryCheckboxes', false)">Alle abwählen</button>
                                                </div>
                                                <div class="checkbox-group" id="gameCategoryCheckboxes"></div>
                                            </div>
                                        </details>
                                    </div>
                                </div>
                            </div>
                        </details>
                    </div>


                    <button type="submit" class="btn" style="width: 100%; margin-top: 1rem; padding: 14px; font-size: 1rem;">Mit dem Üben beginnen 🚀</button>
                </form>
            </div>

            <div id="gamePlayCard" style="display: none;">
                <div id="gameNotice" style="background: var(--md-notice-bg); color: var(--md-notice-text); padding:12px; border-radius:8px; margin-bottom:1rem; font-size:0.85rem; border: 1px solid var(--md-notice-border); display:none;"></div>

                <div class="card">
                    <div style="text-align: center; margin: 0 0 1rem 0; padding: 18px 14px 6px 14px; background: var(--md-surface-card); border: 1px solid var(--md-border); border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.15); min-height: 140px; display: flex; flex-direction: column; justify-content: center;">
                        <div id="gameWordDisplay" class="word-display"></div>
                        <div id="gameConjugationDisplay" style="font-size: 0.85rem; color: var(--md-text-purple); margin-top: 2px; margin-bottom: 2px; display: none;"></div>
                        <div id="gamePluralDisplay" style="font-size: 0.85rem; color: var(--md-accent-text); margin-top: 2px; margin-bottom: 15px; display: none;"></div>
                    </div>

                    <div style="margin-bottom: 1.0rem; text-align: center;">
                        <button type="button" class="btn btn-secondary" style="width: 100%;" onclick="toggleGameDetails()" id="revealBtn">Übersetzung anzeigen</button>
                    </div>

                    <div id="detailsBox" class="details-box" style="display: none;">
                        <p><strong>Übersetzung:</strong> <span id="gTrans" style="color: var(--md-accent-text); font-size: 1.1rem;"></span></p>
                        <p><strong>Beispiel:</strong> <em id="gEx"></em></p>

                        <div id="imageDisplayContainer" style="margin: 12px 0; text-align: center;">
                            <img id="generatedImageTag" src="" alt="Wort Bild" onclick="openFullscreenImage(this.src)" style="max-width: 100%; max-height: 300px; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.3); display: none; margin: 0 auto 10px auto;" title="Zum Vergrößern anklicken">
                            <button type="button" class="btn btn-info ai-btn" onclick="generateAiImageForCurrentWord()" id="generateImageBtn" style="font-size: 0.8rem; padding: 6px 12px;">Bild generieren</button>
                        </div>

                        <p><strong>Synonym:</strong> <span id="gSyn" style="color: var(--md-accent-text); font-size: 1.0rem;"></span></p>
                        <p id="gArtRow"><strong>Artikel:</strong> <span id="gArt"></span></p>
                        <p id="gPluralRow"><strong>Plural:</strong> <span id="gPlural"></span></p>
                        <p id="gGrundverbRow"><strong>Grundverb:</strong> <span id="gGrundverb"></span></p>
                        <p id="gPraefixRow"><strong>Präfix:</strong> <span id="gPraefix"></span></p>
                        <p id="gPraepositionRow"><strong>Präpositionalkollokation:</strong> <span id="gPraeposition"></span></p>
                        <p><strong>Ist Verb?:</strong> <span id="gIsVerb"></span></p>
                        <p id="gDates" style="font-size: 0.8rem; color: var(--md-text-muted); margin-top: 10px;"></p>
                        <div style="border: 1px solid var(--md-border); border-radius: 8px; padding: 8px 12px; margin-top: 12px;">
                            <p><strong>Liste:</strong> <span id="gList"></span></p>
                            <p><strong>Thema:</strong> <span id="gThema"></span></p>
                            <p><strong>Wortart:</strong> <span id="gWart"></span></p>
                        </div>
                    </div>

                    <div class="ai-train-menu">
                        <button type="button" class="btn btn-info ai-btn" onclick="toggleSentenceWriter()" id="toggleSentenceWriterBtn" style="width: 100%;">Mit KI trainieren</button>

                        <div id="sentenceWriterContainer" style="display: none; margin-top: 10px;">
                            <label for="userSentenceInput" style="font-size: 0.9rem; font-weight: 500; margin-bottom: 6px;">Schreibe einen Satz mit diesem Wort:</label>
                            <textarea id="userSentenceInput" rows="5" placeholder="z.B. Ich benutze dieses Wort..." style="width: 100%; max-width: 100%; margin-bottom: 8px; resize: vertical;"></textarea>
                            <button type="button" class="btn btn-success" onclick="checkStandardSentenceBooster()" style="width: 100%;">Satz prüfen & Booster holen 🚀</button>
                        </div>

                        <div id="aiCorrectionResult" style="display: none; background: var(--md-surface); border: 1px solid var(--md-border); padding: 12px; border-radius: 8px; font-size: 0.95rem; width: 100%; margin-top: 8px;">
                            <strong>Korrektur:</strong> <div id="aiCorrectionText" style="color: var(--md-text-success); margin-top: 4px; white-space: pre-wrap;"></div>
                        </div>
                    </div>

                    <div class="rate-group game-rate-group" role="group" aria-label="Kenntnisse bewerten">
                        <button type="button" class="rate-btn r-direkt-aktiv" id="directPromoteContainer" onclick="directPromoteCurrentWord()" title="Direkt aktiv (Score 10)"><span class="rate-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M7 13l5-5 5 5M7 19l5-5 5 5"/></svg></span><span>direkt aktiv</span></button>
                        <?php foreach (RATE_OPTIONS as [$rKey, $rClass, $rIcon, $rLabel]): ?>
                            <button type="button" class="rate-btn <?= $rClass ?>" onclick="submitGameAnswer('<?= $rKey ?>')" title="<?= $rLabel ?>"><span class="rate-icon" aria-hidden="true"><?= $rIcon ?></span><span><?= $rLabel ?></span></button>
                        <?php endforeach; ?>
                    </div>


                    <div style="display: flex; justify-content: center; align-items: center; flex-wrap: wrap; gap: 8px; margin-top: 1.5rem; border-top: 1px solid var(--md-border); padding-top: 1rem;">
                        <button type="button" onclick="showGameSetup()" class="btn btn-secondary" style="font-size: 0.85rem; padding: 8px 14px;">⚙️ Trainingseinstellungen ändern</button>
                        <div class="rate-group action-group is-inline" role="group" aria-label="Wort-Aktionen"><button type="button" class="rate-btn a-edit" onclick="openEditFromGame()" title="Bearbeiten"><span class="rate-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 20h4L19 9l-4-4L4 16z"/><path d="M13.5 6.5l4 4"/></svg></span><span>Bear&shy;beiten</span></button><button type="button" class="rate-btn a-delete" onclick="deleteWordFromGame()" title="Löschen"><span class="rate-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 7h16M9 7V4h6v3M6.5 7l1 13h9l1-13M10 11v5M14 11v5"/></svg></span><span>Löschen</span></button></div>
                    </div>
                </div>
            </div>

            <!-- Deutsch Meister Game Card -->
            <div id="deutschMeisterPlayCard" style="display: none;">
                <div class="card">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem; flex-wrap: wrap; gap: 8px;">
                        <h2 style="margin: 0;"><span class="h-ico" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 8l4.5 4 4.5-7 4.5 7L21 8l-2 11H5z"/></svg></span>Deutsch Meister</h2>
                    </div>
                    <p style="text-align: center; color: var(--md-text-muted); font-size: 0.9rem; margin-bottom: 1.5rem;">Schreibe einen Beispielsatz mit dem vorgeschlagenen Aktiva-Wort! (9 Punkte für bis zu 21 Zeichen, ansonsten Zeichen geteilt durch 3 aufgerundet. Grammatikfehler geben 1 Punkt).</p>

                    <div style="text-align: center; margin-top: 15px;">
                        <div id="dmWordDisplay" class="word-display" style="color: grey;">-</div>
                    </div>

                    <div style="margin: 1.5rem 0;">
                        <label for="dmUserSentence" style="font-size: 0.9rem; font-weight: 500; margin-bottom: 6px;">Dein Beispielsatz:</label>
                        <textarea id="dmUserSentence" rows="3" placeholder="Schreibe deinen Satz auf Deutsch..." style="width: 100%; resize: vertical; padding: 12px; font-size: 1rem;"></textarea>
                        <button type="button" class="btn btn-success" onclick="checkDeutschMeisterSentence()" id="dmCheckBtn" style="width: 100%; margin-top: 10px; padding: 12px;">Satz überprüfen 🚀</button>
                    </div>

                    <div id="dmResultContainer" style="display: none; background: var(--md-surface); border: 1px solid var(--md-border); padding: 16px; border-radius: 8px; margin-bottom: 1.5rem;">
                        <div id="dmEvaluationBadge" style="font-size: 1.1rem; font-weight: 600; margin-bottom: 10px;"></div>
                        <div id="dmFeedbackText" style="font-size: 0.95rem; margin-bottom: 15px; white-space: pre-wrap; line-height: 1.5;"></div>

                        <div id="dmHiddenDetails" style="border-top: 1px solid var(--md-border); padding-top: 12px; display: none;">
                            <h3 style="color: var(--md-accent-text); margin-top: 0; font-size: 1rem;">📚 Auflösung & Übersetzung</h3>
                            <p><strong>Wort:</strong> <span id="dmResWord"></span></p>
                            <p><strong>Artikel:</strong> <span id="dmResArt"></span></p>
                            <p><strong>Übersetzung:</strong> <span id="dmResTrans" style="color: var(--md-accent-text); font-weight: 600;"></span></p>
                            <p><strong>Beispiel:</strong> <em id="dmResEx"></em></p>
                            <button type="button" class="btn" onclick="fetchNextDeutschMeisterWord()" style="width: 100%; margin-top: 10px;">Nächste Aufgabe ➡️</button>
                        </div>
                    </div>

                    <div style="text-align: center; border-top: 1px solid var(--md-border); padding-top: 1rem; margin-top: 1.5rem;">
                        <button type="button" onclick="showGameSetup()" class="btn btn-secondary" style="font-size: 0.85rem; padding: 8px 14px;">⚙️ Trainingseinstellungen ändern</button>
                    </div>
                </div>
            </div>

            <div id="derDieDasPlayCard" style="display: none;">
                <div class="card">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem; flex-wrap: wrap; gap: 8px;">
                        <h2 style="margin: 0;" id="dddGameTitle"><span class="h-ico" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="8.5"/><circle cx="12" cy="12" r="4.5"/><circle cx="12" cy="12" r="0.8"/></svg></span>Der-Die-Das Training</h2>
                        <div style="display: flex; gap: 6px; align-items: center;">
                            <div class="rate-group action-group is-inline" role="group" aria-label="Wort-Aktionen"><button type="button" class="rate-btn a-edit" onclick="openEditFromDdd()" title="Bearbeiten"><span class="rate-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 20h4L19 9l-4-4L4 16z"/><path d="M13.5 6.5l4 4"/></svg></span><span>Bear&shy;beiten</span></button><button type="button" class="rate-btn a-delete" onclick="deleteWordFromDdd()" title="Löschen"><span class="rate-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 7h16M9 7V4h6v3M6.5 7l1 13h9l1-13M10 11v5M14 11v5"/></svg></span><span>Löschen</span></button></div>
                            <div id="dddStreakCounter" style="background: var(--md-surface-card); border: 1px solid var(--md-border); padding: 6px 14px; border-radius: 8px; font-weight: 600; color: var(--md-accent-text); font-size: 0.95rem;">
                                🌱 Streak: <span id="dddStreakValue">0</span> / 10
                            </div>
                        </div>
                    </div>
                    <p style="text-align: center; color: var(--md-text-muted); font-size: 0.9rem; margin-bottom: 1.5rem;" id="dddSubtitle">Rate den richtigen Artikel für dieses Aktiva-Wort! (10 in Folge = Super Booster 🚀)</p>

                    <div style="text-align: center; margin-top: 15px;">
                        <div id="dddWordDisplay" class="word-display" style="color: grey;">-</div>
                        <div id="dddTranslationDisplay" style="font-size: 1.05rem; color: var(--md-accent-text); margin-top: 6px; margin-bottom: 15px; font-weight: 500; display: none;"></div>
                    </div>

                    <div style="display: flex; gap: 12px; justify-content: center; margin: 2rem 0;">
                        <button type="button" class="btn" onclick="submitDerDieDasAnswer('der')" style="background-color: var(--md-der); flex: 1; padding: 16px; font-size: 1.1rem;">der</button>
                        <button type="button" class="btn" onclick="submitDerDieDasAnswer('die')" style="background-color: var(--md-die); flex: 1; padding: 16px; font-size: 1.1rem;">die</button>
                        <button type="button" class="btn" onclick="submitDerDieDasAnswer('das')" style="background-color: var(--md-das); flex: 1; padding: 16px; font-size: 1.1rem;">das</button>
                    </div>

                    <div id="dddFeedbackContainer" style="display: none; background: var(--md-surface); border: 1px solid var(--md-border); padding: 16px; border-radius: 8px; margin-bottom: 1.5rem; text-align: center;">
                        <div id="dddFeedbackText" style="font-size: 1.1rem; font-weight: 600; margin-bottom: 12px;"></div>

                        <div id="dddExtraActionSection" style="display: none; margin-top: 12px; border-top: 1px solid var(--md-border); padding-top: 12px;">
                            <button type="button" class="btn btn-secondary" onclick="loadNextDerDieDasWord()" style="width: 100%; margin-bottom: 8px;">Nächstes Wort laden ➡️</button>
                            <button type="button" class="btn btn-info" onclick="toggleDddSentenceWriter()" id="dddToggleSentenceBtn" style="width: 100%; margin-bottom: 8px;">✍️ Satz schreiben (Bonus Punkte)</button>

                            <div id="dddSentenceWriterBox" style="display: none; margin-top: 8px; text-align: left;">
                                <label for="dddUserSentence" style="font-size: 0.9rem; font-weight: 500; margin-bottom: 6px;">Schreibe einen Satz mit diesem Wort:</label>
                                <textarea id="dddUserSentence" rows="2" placeholder="Dein Satz hier..." style="width: 100%; margin-bottom: 8px; resize: vertical;"></textarea>
                                <button type="button" class="btn btn-success" onclick="checkDddSentence()" style="width: 100%;">Satz prüfen & Booster holen 🚀</button>
                            </div>
                            <div id="dddSentenceCorrection" style="display: none; margin-top: 8px; font-size: 0.95rem; text-align: left; white-space: pre-wrap;"></div>
                        </div>
                    </div>

                    <div style="text-align: center; border-top: 1px solid var(--md-border); padding-top: 1rem; margin-top: 1.5rem;">
                        <button type="button" onclick="showGameSetup()" class="btn btn-secondary" style="font-size: 0.85rem; padding: 8px 14px;">⚙️ Trainingseinstellungen ändern</button>
                    </div>
                </div>
            </div>

        </div>
    </div>

    <div id="statistics-view" class="view">
        <div class="stats-container">
            <header>
                <div class="header-title">
                    <button type="button" class="back-icon" onclick="switchView('dashboard')" title="Zurück zum Dashboard" aria-label="Zurück zum Dashboard">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 18l-6-6 6-6"/></svg>
                    </button>
                    <h1><span class="h-ico" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 20h16"/><path d="M7 16v-4M12 16V8M17 16v-7"/><path d="M5 9l5-4 4 3 5-4"/></svg></span>Wortschatz-Statistiken</h1>
                </div>
            </header>

            <div class="card">
                <h2>Statistiken filtern</h2>
                <form id="statsFilterForm" onsubmit="event.preventDefault(); loadStatisticsData();" class="filters">
                    <select id="statsFilterList" name="sharepoint_list" onchange="loadStatisticsData()">
                        <option value="">Alle Listen</option>
                        <?php foreach ($initialLists as $l): ?>
                            <option value="<?= htmlspecialchars($l) ?>"><?= htmlspecialchars($l) ?></option>
                        <?php endforeach; ?>
                    </select>

                    <select id="statsFilterThema" name="thema" onchange="onStatsThemaChange()">
                        <option value="">Alle Themen</option>
                        <?php foreach ($initialThemen as $thm): ?>
                            <option value="<?= htmlspecialchars($thm) ?>"><?= htmlspecialchars($thm) ?></option>
                        <?php endforeach; ?>
                    </select>

                    <select id="statsFilterWortart" name="wortart" onchange="loadStatisticsData()">
                        <option value="">Alle Wortarten</option>
                        <?php foreach ($initialWortarten as $wa): ?>
                            <option value="<?= htmlspecialchars($wa) ?>"><?= htmlspecialchars($wa) ?></option>
                        <?php endforeach; ?>
                    </select>

                    <select id="statsFilterScore" name="score" onchange="loadStatisticsData()">
                        <option value="">Alle Scores</option>
                        <?php foreach ($initialScores as $s): ?>
                            <option value="<?= htmlspecialchars($s) ?>">Score: <?= htmlspecialchars($s) ?></option>
                        <?php endforeach; ?>
                    </select>

                    <button type="button" class="btn btn-secondary" onclick="resetStatsFilters()" style="padding: 10px 14px;">Zurücksetzen</button>
                </form>

                <div class="chart-wrapper">
                    <canvas id="statusPieChart"></canvas>
                </div>

                <h2>Gesamtanzahl Wörter nach Status</h2>
                <div class="stats-text-list" id="statsTextList">
                    <div style="text-align: center; color: var(--md-text-muted);">Statistiken werden geladen...</div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
if ('serviceWorker' in navigator) {
    navigator.serviceWorker.getRegistrations().then(registrations => {
        for (let registration of registrations) {
            registration.unregister();
        }
    });
}
if ('caches' in window) {
    caches.keys().then(names => {
        for (let name of names) {
            caches.delete(name);
        }
    });
}

let currentSort = 'Wort';
let currentOrder = 'ASC';
let currentLetter = '';
let currentOffset = 0;
let isLoadingMore = false;
let hasMoreData = true;
let searchTimeout = null;
let transSearchTimeout = null;

let activeGameSettings = null;
let currentGameWord = null;
let currentDerDieDasWord = null;
let currentDeutschMeisterWord = null;
// Training card to go back to after editing a word from a training (null = normal dashboard edit)
let editReturnTraining = null;
let lastDddWasRight = false;
let dddStreakCount = 0;
let statusPieChartInstance = null;

let masterWordsList = <?= jsonForScript($initialWords) ?>;
let cachedLists = <?= jsonForScript($initialLists) ?>;
let cachedThemen = <?= jsonForScript($initialThemen) ?>;
let cachedWortarten = <?= jsonForScript($initialWortarten) ?>;
let cachedScores = <?= jsonForScript($initialScores) ?>;
let cachedGrundverben = <?= jsonForScript($initialGrundverben) ?>;
let cachedPraefixe = <?= jsonForScript($initialPraefixe) ?>;
let cachedStatuses = <?= jsonForScript($initialStatuses) ?>;
let cachedThemaWortartenMap = <?= jsonForScript($initialThemaWortartenMap) ?>;

let todayReviewedCount = <?= (int)$todayReviewedCount ?>;
let lastPopupWordObject = null;
const dailyTarget = 100;

document.addEventListener('DOMContentLoaded', () => {
    updateDailyTrackerUI();

    const filterMenu = document.getElementById('filterMenu');
    if (filterMenu) {
        document.addEventListener('click', event => {
            if (filterMenu.open && !filterMenu.contains(event.target)) filterMenu.open = false;
        });
        document.addEventListener('keydown', event => {
            if (event.key === 'Escape' && filterMenu.open) {
                filterMenu.open = false;
                filterMenu.querySelector('summary').focus();
            }
        });
    }

    const accountMenu = document.getElementById('accountMenu');
    if (accountMenu) {
        document.addEventListener('click', event => {
            if (!accountMenu.contains(event.target)) accountMenu.open = false;
        });
        document.addEventListener('keydown', event => {
            if (event.key === 'Escape' && accountMenu.open) {
                accountMenu.open = false;
                accountMenu.querySelector('summary').focus();
            }
        });
    }

    document.querySelectorAll('.sheet-menu').forEach(enableSheetSwipe);

    const scrollContainer = document.getElementById('tableResponsiveContainer');
    if (scrollContainer) {
        scrollContainer.addEventListener('scroll', () => {
            if (scrollContainer.scrollTop + scrollContainer.clientHeight >= scrollContainer.scrollHeight - 100) {
                if (!isLoadingMore && hasMoreData) {
                    loadMoreDashboardData();
                }
            }
        });
    }

    refreshMetadata();
    loadDashboardData(true);
    toggleVerbFields();
});

function openFullscreenImage(src) {
    if (!src) return;
    const overlay = document.getElementById('imageFullscreenOverlay');
    const fullImg = document.getElementById('fullscreenImageTag');
    fullImg.src = src;
    overlay.style.display = 'flex';
}

function closeFullscreenImage() {
    const overlay = document.getElementById('imageFullscreenOverlay');
    overlay.style.display = 'none';
}

async function generateAiImageForCurrentWord() {
    if (!currentGameWord || !currentGameWord.Wort) {
        alert('Kein aktives Wort gefunden.');
        return;
    }

    const btn = document.getElementById('generateImageBtn');
    const originalText = btn.textContent;
    btn.disabled = true;
    btn.textContent = 'Generiere KI-Szene...';

    try {
        const res = await fetch('index.php?api=generate_ai_image&_ts=' + Date.now(), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            cache: 'no-store',
            body: JSON.stringify({
                word: currentGameWord.Wort,
                translation: currentGameWord.Übersetzung || '',
                thema: currentGameWord.Thema || ''
            })
        });

        const textResponse = await res.text();
        let data;
        try {
            data = JSON.parse(textResponse);
        } catch (jsonErr) {
            console.error('Server raw response:', textResponse);
            throw new Error('Server returned invalid response format.');
        }

        if (data.error === 'Unauthorized') {
            window.location.href = 'login.php';
            return;
        }

        if (data.success && data.image_data) {
            const imgTag = document.getElementById('generatedImageTag');
            imgTag.src = data.image_data;
            imgTag.style.display = 'block';
            btn.textContent = '🔄 Bild neu generieren';
            showAlert('Visuelle Szene erfolgreich von Google AI generiert und gespeichert!');
        } else {
            alert(data.error || 'Fehler bei der Bildgenerierung.');
        }
    } catch (err) {
        console.error('Bildgenerierung fehlgeschlagen:', err);
        alert('Fehler: ' + err.message);
    } finally {
        btn.disabled = false;
        if (btn.textContent === 'Generiere KI-Szene...') {
            btn.textContent = originalText;
        }
    }
}

async function checkAndLoadImageForCurrentWord() {
    if (!currentGameWord || !currentGameWord.Wort) return;

    const imageUrl = 'index.php?api=view_image&word=' + encodeURIComponent(currentGameWord.Wort) + '&_ts=' + Date.now();
    const imgTag = document.getElementById('generatedImageTag');
    const btn = document.getElementById('generateImageBtn');

    imgTag.src = '';
    imgTag.style.display = 'none';
    btn.textContent = 'Bild generieren';

    try {
        const checkRes = await fetch(imageUrl, { cache: 'no-store' });
        if (checkRes.status === 204 || checkRes.status === 404 || checkRes.status === 401) {
            return;
        }

        imgTag.onload = () => {
            imgTag.style.display = 'block';
            btn.textContent = '🔄 Bild neu generieren';
        };

        imgTag.onerror = () => {
            imgTag.style.display = 'none';
        };

        imgTag.src = imageUrl;
    } catch (err) {
        console.error('Bild konnte nicht geladen werden', err);
    }
}

// ===== Bild im "Wort bearbeiten"-Panel (wie im Standard-Training) =====
function hideEditImage() {
    const box = document.getElementById('editImageContainer');
    const imgTag = document.getElementById('editImageTag');
    const btn = document.getElementById('editImageBtn');
    imgTag.onload = null;
    imgTag.onerror = null;
    imgTag.src = '';
    box.style.display = 'none';
    btn.style.display = 'none';
    btn.textContent = 'Bild generieren';
}

async function loadEditImage(word) {
    const box = document.getElementById('editImageContainer');
    const imgTag = document.getElementById('editImageTag');
    const btn = document.getElementById('editImageBtn');

    hideEditImage();
    btn.style.display = '';
    if (!word) return;

    const imageUrl = 'index.php?api=view_image&word=' + encodeURIComponent(word) + '&_ts=' + Date.now();
    try {
        const checkRes = await fetch(imageUrl, { cache: 'no-store' });
        if (checkRes.status === 204 || checkRes.status === 404 || checkRes.status === 401) return;
        // the panel may have been closed or switched to another word meanwhile
        if (document.getElementById('original_wort').value !== word) return;

        imgTag.onload = () => {
            box.style.display = 'block';
            btn.textContent = 'Bild neu generieren';
        };
        imgTag.onerror = () => { box.style.display = 'none'; };
        imgTag.src = imageUrl;
    } catch (err) {
        console.error('Bild konnte nicht geladen werden', err);
    }
}

async function generateAiImageForEditWord() {
    // the image is stored on the saved word, so use the original name (not unsaved edits)
    const word = document.getElementById('original_wort').value;
    if (!word) {
        alert('Bitte speichere das Wort zuerst.');
        return;
    }

    const btn = document.getElementById('editImageBtn');
    const originalText = btn.textContent;
    btn.disabled = true;
    btn.textContent = 'Generiere KI-Szene...';

    try {
        const res = await fetch('index.php?api=generate_ai_image&_ts=' + Date.now(), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            cache: 'no-store',
            body: JSON.stringify({
                word: word,
                translation: document.getElementById('Übersetzung').value || '',
                thema: document.getElementById('Thema').value || ''
            })
        });

        const textResponse = await res.text();
        let data;
        try {
            data = JSON.parse(textResponse);
        } catch (jsonErr) {
            console.error('Server raw response:', textResponse);
            throw new Error('Server returned invalid response format.');
        }

        if (data.error === 'Unauthorized') {
            window.location.href = 'login.php';
            return;
        }

        if (data.success && data.image_data) {
            if (document.getElementById('original_wort').value === word) {
                const imgTag = document.getElementById('editImageTag');
                imgTag.onload = null;
                imgTag.onerror = null;
                imgTag.src = data.image_data;
                document.getElementById('editImageContainer').style.display = 'block';
                btn.textContent = 'Bild neu generieren';
            }
            showAlert('Visuelle Szene erfolgreich von Google AI generiert und gespeichert!');
        } else {
            alert(data.error || 'Fehler bei der Bildgenerierung.');
        }
    } catch (err) {
        console.error('Bildgenerierung fehlgeschlagen:', err);
        alert('Fehler: ' + err.message);
    } finally {
        btn.disabled = false;
        if (btn.textContent === 'Generiere KI-Szene...') {
            btn.textContent = originalText;
        }
    }
}

async function fillWordWithAI() {
    const wortInput = document.getElementById('Wort');
    const wordValue = wortInput.value.trim();

    if (!wordValue) {
        alert('Bitte gib zuerst ein Wort in das Feld "Wort" ein!');
        wortInput.focus();
        return;
    }

    const btn = document.getElementById('aiFillBtn');
    const originalText = btn.textContent;
    btn.disabled = true;
    btn.textContent = 'Analysiere...';

    try {
        const res = await fetch('index.php?api=ai_fill_word&_ts=' + Date.now(), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            cache: 'no-store',
            body: JSON.stringify({ word: wordValue })
        });
        const data = await res.json();

        if (data.error === 'Unauthorized') {
            window.location.href = 'login.php';
            return;
        }

        if (data.success && data.ai_data) {
            const ai = data.ai_data;

            if (ai.thema) document.getElementById('Thema').value = ai.thema;
            if (ai.kategorie) document.getElementById('Wortarten').value = ai.kategorie;

            const isVerb = ai.ist_verb === true || String(ai.ist_verb).toLowerCase() === 'ja' || String(ai.ist_verb).toLowerCase() === 'true';
            document.getElementById('VerbFlag').value = isVerb ? '1' : '0';
            toggleVerbFields();

            if (isVerb) {
                if (ai.konjugation) document.getElementById('Konjugation').value = ai.konjugation;
                if (ai.grundverb) document.getElementById('grundverb').value = ai.grundverb;
                if (ai.praefix) document.getElementById('praefix').value = ai.praefix;
                if (ai.praeposition_kollokation) document.getElementById('praeposition_kollokation').value = ai.praeposition_kollokation;
            } else {
                if (ai.artikel) document.getElementById('Artikel').value = ai.artikel;
                if (ai.plural) document.getElementById('Plural').value = ai.plural;
            }

            if (ai.uebersetzung) document.getElementById('Übersetzung').value = ai.uebersetzung;
            if (ai.synonym) document.getElementById('synonym').value = ai.synonym;
            if (ai.beispiel) document.getElementById('Beispiel').value = ai.beispiel;

            showAlert('Wort erfolgreich mit KI ausgefüllt!');
        } else {
            alert('Konnte die KI-Antwort nicht verarbeiten.');
        }
    } catch (err) {
        console.error('KI-Ausfüllen fehlgeschlagen', err);
        alert('Netzwerkfehler beim Abfragen der KI.');
    } finally {
        btn.disabled = false;
        btn.textContent = originalText;
    }
}

// ===== Sprachniveau nach aktiven Wörtern =====
const LEVELS = [
    { name: 'Novize',            min: 500 },
    { name: 'Praktiker',         min: 1300 },
    { name: 'Fortgeschrittener', min: 2500 },
    { name: 'Experte',           min: 4000 },
    { name: 'Kenner',           min: 6000 },
    { name: 'Spezialist',        min: 8000 },
    { name: 'Meister',          min: 10000 },
    { name: 'Virtuose',          min: 15000 }
];
// Small round picture per level (full size: levels/level-N.jpg)
const LEVEL_THUMBS = ["data:image/jpeg;base64,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", "data:image/jpeg;base64,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", "data:image/jpeg;base64,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", "data:image/jpeg;base64,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", "data:image/jpeg;base64,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", "data:image/jpeg;base64,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", "data:image/jpeg;base64,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", "data:image/jpeg;base64,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"];
const LEVEL_STORE_KEY = 'vq-level-<?= (int)$uid ?>';

// On phones only a few levels around the current one are shown
const LEVEL_MOBILE_MQ = window.matchMedia('(max-width: 760px)');
const LEVEL_MOBILE_COUNT = 4;
let lastLevelCount = 0;
LEVEL_MOBILE_MQ.addEventListener?.('change', () => renderLevelPath(lastLevelCount));

function renderLevelPath(aktivaCount) {
    const box = document.getElementById('levelPath');
    if (!box) return;

    const n = Math.max(0, parseInt(aktivaCount, 10) || 0);
    lastLevelCount = n;
    const fmt = v => v.toLocaleString('de-DE');

    let reached = -1; // highest level reached (-1 = none yet)
    LEVELS.forEach((l, i) => { if (n >= l.min) reached = i; });
    const next = reached + 1 < LEVELS.length ? reached + 1 : -1;
    const prevMin = reached >= 0 ? LEVELS[reached].min : 0;
    const frac = next >= 0 ? Math.min(1, (n - prevMin) / (LEVELS[next].min - prevMin)) : 1;

    // Which levels to draw: all of them, or a window around the current level on phones
    const compact = LEVEL_MOBILE_MQ.matches;
    const count = compact ? LEVEL_MOBILE_COUNT : LEVELS.length;
    const start = compact ? Math.max(0, Math.min(reached - 1, LEVELS.length - count)) : 0;
    const shown = LEVELS.slice(start, start + count).map((l, k) => ({ l, i: start + k }));

    const W = compact ? 360 : 640, X0 = compact ? 62 : 45, R = 26;
    const Y_LOW = 112, Y_HIGH = 70, VB_H = compact ? 158 : 176;
    const DX = (W - 2 * X0) / (count - 1), H = DX / 2;
    const C = 2 * Math.PI * R;

    // wavy line through the shown nodes (low, high, low, high...)
    const pts = shown.map(({ i }, k) => ({ x: X0 + k * DX, y: i % 2 === 0 ? Y_LOW : Y_HIGH }));
    let d = `M ${pts[0].x} ${pts[0].y}`;
    for (let k = 1; k < pts.length; k++) {
        const a = pts[k - 1], b = pts[k];
        d += ` C ${a.x + H} ${a.y}, ${b.x - H} ${b.y}, ${b.x} ${b.y}`;
    }
    const progress = reached < 0 ? 0 : reached + (next >= 0 ? frac : 0) - start;
    const lineFill = Math.max(0, Math.min(1, progress / (count - 1))) * 100;

    const nodes = shown.map(({ l, i }, k) => {
        const { x, y } = pts[k];
        const state = i < reached ? 'done' : i === reached ? 'current' : i === next ? 'next' : 'locked';
        const up = i % 2 === 1;
        const nameY = up ? y - R - 9 : y + R + 16;
        const countY = up ? y - R - 25 : y + R + 31;
        const ring = state === 'next'
            ? `<circle class="lp-ring" cx="${x}" cy="${y}" r="${R}" stroke-dasharray="${(frac * C).toFixed(1)} ${C.toFixed(1)}" transform="rotate(-90 ${x} ${y})"/>`
            : '';
        const tip = n >= l.min
            ? `${l.name}: erreicht`
            : `${l.name}: noch ${fmt(l.min - n)} aktive Wörter`;
        return `<g class="lp-node ${state}" data-level="${i}">
            <title>${tip}</title>
            <clipPath id="lp-clip-${i}"><circle cx="${x}" cy="${y}" r="${R - 3}"/></clipPath>
            <circle class="lp-dot" cx="${x}" cy="${y}" r="${R}"/>
            <image class="lp-img" href="${LEVEL_THUMBS[i]}" x="${x - R}" y="${y - R}" width="${2 * R}" height="${2 * R}" clip-path="url(#lp-clip-${i})"/>${ring}
            <text class="lp-name" x="${x}" y="${nameY}">${l.name}</text>
            <text class="lp-count" x="${x}" y="${countY}">${fmt(l.min)}+</text>
        </g>`;
    }).join('');

    const caption = next >= 0
        ? `<strong>${fmt(n)}</strong> aktive Wörter · noch ${fmt(LEVELS[next].min - n)} bis ${LEVELS[next].name}`
        : `<strong>${fmt(n)}</strong> aktive Wörter · höchstes Niveau erreicht 🏆`;

    box.setAttribute('aria-label', `Sprachniveau: ${reached >= 0 ? LEVELS[reached].name : 'Einsteiger'}, ${n} aktive Wörter`);
    box.innerHTML = `
        <svg viewBox="0 0 ${W} ${VB_H}" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
            <path class="lp-track" d="${d}"/>
            <path class="lp-fill" d="${d}" pathLength="100" stroke-dasharray="${lineFill.toFixed(2)} 100"/>
            ${nodes}
        </svg>
        <div class="lp-caption">${caption}</div>`;

    checkLevelUp(reached, n);
}

// Tap a reached level to see its picture again
document.getElementById('levelPath')?.addEventListener('click', (e) => {
    const node = e.target.closest('.lp-node.done, .lp-node.current');
    if (node) showLevelCard(Number(node.dataset.level), null);
});

// Celebrate once when a new level is reached (remembered per user in this browser)
function checkLevelUp(reached, n) {
    let saved = null;
    try { saved = localStorage.getItem(LEVEL_STORE_KEY); } catch (e) {}
    try { localStorage.setItem(LEVEL_STORE_KEY, String(reached)); } catch (e) { return; }
    if (saved === null) return; // first visit: no pop-up for levels reached earlier
    if (reached > Number(saved)) showLevelCard(reached, n);
}

function showLevelCard(i, n) {
    const l = LEVELS[i];
    if (!l) return;
    document.getElementById('levelCard')?.remove();
    const isNew = n !== null;
    const wrap = document.createElement('div');
    wrap.id = 'levelCard';
    wrap.className = 'level-card-overlay' + (isNew ? ' is-new' : '');
    wrap.innerHTML = `
        <div class="level-card" role="dialog" aria-modal="true" aria-labelledby="levelCardTitle">
            <div class="level-card-kicker">${isNew ? '🎉 Neues Niveau erreicht!' : `Niveau ${i + 1} von ${LEVELS.length}`}</div>
            <div class="level-card-img"><img src="levels/level-${i + 1}.jpg" alt=""></div>
            <h2 id="levelCardTitle">${l.name}</h2>
            <p>${isNew
                ? `Du hast ${n.toLocaleString('de-DE')} aktive Wörter. Weiter so!`
                : `Ab ${l.min.toLocaleString('de-DE')} aktiven Wörtern`}</p>
            <button type="button" class="btn level-card-close">${isNew ? 'Weiter' : 'Schließen'}</button>
        </div>`;
    const img = wrap.querySelector('img');
    img.addEventListener('error', () => { img.src = LEVEL_THUMBS[i]; }, { once: true });
    const close = () => { wrap.remove(); document.removeEventListener('keydown', onKey); };
    const onKey = (e) => { if (e.key === 'Escape') close(); };
    wrap.addEventListener('click', (e) => { if (e.target === wrap || e.target.closest('.level-card-close')) close(); });
    document.addEventListener('keydown', onKey);
    document.body.appendChild(wrap);
    wrap.querySelector('.level-card-close').focus();
}
// Draw it right away from the server count; refreshMetadata() updates it later
renderLevelPath(<?= (int)$initialAktivaCount ?>);

async function refreshMetadata() {
    try {
        const res = await fetch('index.php?api=get_metadata&_ts=' + Date.now(), { cache: 'no-store' });
        const data = await res.json();
        if (data.error === 'Unauthorized') {
            window.location.href = 'login.php';
            return;
        }
        if (!data.success) return;

        renderLevelPath(data.aktiva_count);

        cachedLists = data.lists;
        cachedThemen = data.themen;
        cachedWortarten = data.wortarten;
        cachedScores = data.scores;
        cachedGrundverben = data.grundverben;
        cachedPraefixe = data.praefixe;
        cachedStatuses = data.statuses;
        cachedThemaWortartenMap = data.themaWortartenMap || {};

        updateDropdown('filterList', data.lists, 'Alle Listen');
        updateDropdown('filterThema', data.themen, 'Alle Themen');
        updateDropdown('filterStatus', data.statuses, 'Alle Status');
        updateDropdown('filterGrundverb', data.grundverben, 'Alle Grundverben');
        updateDropdown('filterPraefix', data.praefixe, 'Alle Präfixe');

        updateDashboardWortartenDropdown();

        updateDropdown('statsFilterList', data.lists, 'Alle Listen');
        updateDropdown('statsFilterThema', data.themen, 'Alle Themen');
        updateDropdown('statsFilterScore', data.scores, 'Alle Scores', 'Score: ');

        updateStatsWortartenDropdown();

        populateGameSetupForm();
    } catch (err) {
        console.error('Fehler beim Aktualisieren der Metadaten', err);
    }
}

function updateDropdown(elementId, items, defaultText, prefix = '') {
    const select = document.getElementById(elementId);
    if (!select) return;
    const currentValue = select.value;

    select.innerHTML = `<option value="">${defaultText}</option>`;
    items.forEach(item => {
        const opt = document.createElement('option');
        opt.value = item;
        opt.textContent = prefix + item;
        if (String(item) === String(currentValue)) {
            opt.selected = true;
        }
        select.appendChild(opt);
    });
}

function updateDashboardWortartenDropdown() {
    const themaSelect = document.getElementById('filterThema');
    const wortartSelect = document.getElementById('filterWortart');
    if (!wortartSelect) return;

    const selectedThema = themaSelect ? themaSelect.value : '';
    const currentWortart = wortartSelect.value;

    let availableWortarten = [];
    if (selectedThema && cachedThemaWortartenMap[selectedThema]) {
        availableWortarten = cachedThemaWortartenMap[selectedThema];
    } else {
        availableWortarten = cachedWortarten;
    }

    wortartSelect.innerHTML = `<option value="">Alle Wortarten</option>`;
    availableWortarten.forEach(wa => {
        const opt = document.createElement('option');
        opt.value = wa;
        opt.textContent = wa;
        if (String(wa) === String(currentWortart)) {
            opt.selected = true;
        }
        wortartSelect.appendChild(opt);
    });
}

function onDashboardThemaChange() {
    updateDashboardWortartenDropdown();
    loadDashboardData(true);
}

function updateStatsWortartenDropdown() {
    const themaSelect = document.getElementById('statsFilterThema');
    const wortartSelect = document.getElementById('statsFilterWortart');
    if (!wortartSelect) return;

    const selectedThema = themaSelect ? themaSelect.value : '';
    const currentWortart = wortartSelect.value;

    let availableWortarten = [];
    if (selectedThema && cachedThemaWortartenMap[selectedThema]) {
        availableWortarten = cachedThemaWortartenMap[selectedThema];
    } else {
        availableWortarten = cachedWortarten;
    }

    wortartSelect.innerHTML = `<option value="">Alle Wortarten</option>`;
    availableWortarten.forEach(wa => {
        const opt = document.createElement('option');
        opt.value = wa;
        opt.textContent = wa;
        if (String(wa) === String(currentWortart)) {
            opt.selected = true;
        }
        wortartSelect.appendChild(opt);
    });
}

function onStatsThemaChange() {
    updateStatsWortartenDropdown();
    loadStatisticsData();
}

function updateDailyTrackerUI() {
    const titleEl = document.getElementById('trackerTitle');
    const subtitleEl = document.getElementById('trackerSubtitle');
    const iconEl = document.getElementById('trackerIcon');
    const fillEl = document.getElementById('trackerProgressFill');

    const percentage = Math.min(Math.round((todayReviewedCount / dailyTarget) * 100), 100);
    fillEl.style.width = percentage + '%';

    titleEl.textContent = `Tagesziel: ${todayReviewedCount} / ${dailyTarget} Wörter wiederholt`;

    if (todayReviewedCount === 0) {
        iconEl.textContent = '😴';
        subtitleEl.textContent = 'Guten Morgen! Zeit, die grauen Zellen zu aktivieren.';
    } else if (todayReviewedCount < 25) {
        iconEl.textContent = '☕';
        subtitleEl.textContent = 'Guter Start! Bleib am Ball.';
    } else if (todayReviewedCount < 50) {
        iconEl.textContent = '🔥';
        subtitleEl.textContent = 'Du kommst in Fahrt! Halbzeit der ersten Etappe.';
    } else if (todayReviewedCount < 75) {
        iconEl.textContent = '🚀';
        subtitleEl.textContent = 'Unglaubliches Tempo! Das Ziel ist in Sicht.';
    } else if (todayReviewedCount < 100) {
        iconEl.textContent = '⚡';
        subtitleEl.textContent = 'Fast geschafft! Nur noch ein letzter Schub.';
    } else {
        iconEl.textContent = '🏆';
        subtitleEl.textContent = 'Ziel erreicht! Absoluter Vokabel-Meister heute!';
    }
}

function switchView(viewName, opts = {}) {
    editReturnTraining = null;
    document.querySelectorAll('.view').forEach(v => v.classList.remove('active'));
    if (viewName === 'dashboard') {
        document.getElementById('dashboard-view').classList.add('active');
        if (!opts.skipLoad) {
            loadDashboardData(true);
            refreshMetadata();
        }
    } else if (viewName === 'game') {
        document.getElementById('game-view').classList.add('active');
        // Settings render at once from the cached metadata; the refresh runs in the background
        initGameInstantly();
        refreshMetadata();
    } else if (viewName === 'statistics') {
        document.getElementById('statistics-view').classList.add('active');
        refreshMetadata().then(() => loadStatisticsData());
        setTimeout(() => {
            if (statusPieChartInstance) {
                statusPieChartInstance.resize();
            }
        }, 50);
    }
}

function openGameSelection() {
    activeGameSettings = null;
    switchView('game');
}

async function loadStatisticsData() {
    const list = document.getElementById('statsFilterList').value;
    const thema = document.getElementById('statsFilterThema').value;
    const wortart = document.getElementById('statsFilterWortart').value;
    const score = document.getElementById('statsFilterScore').value;

    const url = `index.php?api=get_stats&sharepoint_list=${encodeURIComponent(list)}&thema=${encodeURIComponent(thema)}&wortart=${encodeURIComponent(wortart)}&score=${encodeURIComponent(score)}&_ts=` + Date.now();

    try {
        const res = await fetch(url, { cache: 'no-store' });
        const data = await res.json();
        if (data.error === 'Unauthorized') { window.location.href = 'login.php'; return; }

        renderStatistics(data.stats);
    } catch (err) {
        console.error('Fehler beim Laden der Statistiken', err);
    }
}

function resetStatsFilters() {
    document.getElementById('statsFilterList').value = '';
    document.getElementById('statsFilterThema').value = '';
    document.getElementById('statsFilterWortart').value = '';
    document.getElementById('statsFilterScore').value = '';
    updateStatsWortartenDropdown();
    loadStatisticsData();
}

function renderStatistics(stats) {
    const textListContainer = document.getElementById('statsTextList');
    textListContainer.innerHTML = '';

    if (!stats || stats.length === 0) {
        textListContainer.innerHTML = '<div style="text-align: center; color: var(--md-text-muted);">Keine passenden Einträge gefunden.</div>';
        if (statusPieChartInstance) statusPieChartInstance.destroy();
        return;
    }

    let labels = [];
    let counts = [];
    let backgroundColors = [];
    let totalWords = 0;

    function getStatusColor(statusName) {
        const key = (statusName || '').toLowerCase().trim();
        if (key === 'aktiva') return '#ec407a';
        if (key === 'wiederholen') return '#3f7fd6';
        if (key === 'neu') return '#8e6fd8';
        if (key === 'passiv') return '#8a96a3';
        if (key === 'warteschlange') return '#4a5260';
        return '#78909c';
    }

    stats.forEach(row => {
        const statusName = row.Status || 'Nicht zugewiesen';
        const count = parseInt(row.count, 10);
        labels.push(statusName);
        counts.push(count);
        backgroundColors.push(getStatusColor(statusName));
        totalWords += count;

        textListContainer.innerHTML += `
            <div class="stats-text-item">
                <span><strong>${escapeHtml(statusName)}</strong></span>
                <span>${count} Wörter</span>
            </div>
        `;
    });

    textListContainer.innerHTML += `
        <div class="stats-text-item" style="border-top: 2px solid var(--md-border); margin-top: 4px; padding-top: 10px;">
            <span><strong>Gesamt</strong></span>
            <span><strong>${totalWords} Wörter</strong></span>
        </div>
    `;

    const ctx = document.getElementById('statusPieChart').getContext('2d');
    if (statusPieChartInstance) {
        statusPieChartInstance.destroy();
    }

    statusPieChartInstance = new Chart(ctx, {
        type: 'pie',
        data: {
            labels: labels,
            datasets: [{
                data: counts,
                backgroundColor: backgroundColors,
                borderWidth: 1,
                borderColor: cssVar('--md-surface')
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: { color: cssVar('--md-on-surface'), font: { family: 'system-ui' } }
                }
            }
        }
    });
}

// Read a CSS color variable of the current theme (used for Chart.js)
function cssVar(name) {
    return getComputedStyle(document.documentElement).getPropertyValue(name).trim();
}

// Recolor the chart when the user switches light/dark mode
document.addEventListener('themechange', () => {
    if (!statusPieChartInstance) return;
    statusPieChartInstance.data.datasets[0].borderColor = cssVar('--md-surface');
    statusPieChartInstance.options.plugins.legend.labels.color = cssVar('--md-on-surface');
    statusPieChartInstance.update();
});

// --- CHECKBOX BULK SELECT UTILITIES & STATUS PILL UI ---
function toggleAllCheckboxes(containerId, checkState) {
    const container = document.getElementById(containerId);
    if (!container) return;
    const checkboxes = container.querySelectorAll('input[type="checkbox"]');
    checkboxes.forEach(cb => {
        cb.checked = checkState;
    });
}

function toggleAllStatusPills(selectState) {
    const container = document.getElementById('statusPillContainer');
    if (!container) return;
    const pills = container.querySelectorAll('.status-pill');
    pills.forEach(pill => {
        if (selectState) {
            pill.classList.add('active');
        } else {
            pill.classList.remove('active');
        }
    });
    updateHiddenStatusInputs();
}

function toggleStatusPill(pillElement) {
    pillElement.classList.toggle('active');
    updateHiddenStatusInputs();
}

function updateHiddenStatusInputs() {
    const hiddenContainer = document.getElementById('hiddenStatusInputsContainer');
    if (!hiddenContainer) return;
    hiddenContainer.innerHTML = '';

    const activePills = document.querySelectorAll('#statusPillContainer .status-pill.active');
    activePills.forEach(pill => {
        const val = pill.getAttribute('data-value');
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'game_statuses[]';
        input.value = val;
        hiddenContainer.appendChild(input);
    });
}

function toggleSortOrder() {
    const input = document.getElementById('sortOrderInput');
    const label = document.getElementById('sortOrderLabel');
    const badge = document.getElementById('sortOrderBadge');

    if (input.value === 'ASC') {
        input.value = 'DESC';
        label.textContent = 'Invertiert (Absteigend)';
        badge.textContent = 'DESC';
    } else {
        input.value = 'ASC';
        label.textContent = 'Original (Aufsteigend)';
        badge.textContent = 'ASC';
    }
}

// Fills the training settings from the cached metadata, keeping what the user already selected
function populateGameSetupForm() {
    const pillContainer = document.getElementById('statusPillContainer');
    if (pillContainer) {
        const prevActive = pillContainer.children.length
            ? new Set([...pillContainer.querySelectorAll('.status-pill.active')].map(p => p.getAttribute('data-value')))
            : null;
        pillContainer.innerHTML = '';
        cachedStatuses.forEach(st => {
            const stLower = (st || '').toLowerCase().trim();
            // Make "wiederholen" the ONLY preselected option by default
            const isPreselected = prevActive ? prevActive.has(st) : (stLower === 'wiederholen');
            const activeClass = isPreselected ? 'active' : '';
            pillContainer.innerHTML += `<div class="status-pill ${activeClass}" data-value="${escapeAttr(st)}" onclick="toggleStatusPill(this)">${escapeHtml(st)}</div>`;
        });
        updateHiddenStatusInputs();
    }

    const listContainer = document.getElementById('gameListCheckboxes');
    if (listContainer) {
        const prevChecked = new Set([...listContainer.querySelectorAll('input:checked')].map(i => i.value));
        listContainer.innerHTML = '';
        cachedLists.forEach(l => {
            const checked = prevChecked.has(l) ? ' checked' : '';
            listContainer.innerHTML += `<label class="checkbox-label"><input type="checkbox" name="sharepoint_lists[]" value="${escapeAttr(l)}"${checked}> ${escapeHtml(l)}</label>`;
        });
    }

    const themaSelect = document.getElementById('gameThemaSelect');
    if (themaSelect) {
        const currentVal = themaSelect.value;
        themaSelect.innerHTML = `<option value="">Alle Themen</option>`;
        cachedThemen.forEach(thm => {
            const opt = document.createElement('option');
            opt.value = thm;
            opt.textContent = thm;
            if (thm === currentVal) opt.selected = true;
            themaSelect.appendChild(opt);
        });
    }

    updateGameCategoriesCheckboxes();
    onGameModeChange();
}

function initGameInstantly() {
    populateGameSetupForm();
    if (!activeGameSettings) {
        showGameSetup();
    } else {
        showGameCard(gameCardForMode(activeGameSettings.mode));
    }
}

const GAME_CARD_IDS = ['gameSetupCard', 'gamePlayCard', 'derDieDasPlayCard', 'deutschMeisterPlayCard'];

// Shows exactly one card of the training view ('trainingLoader' shows the loading animation)
function showGameCard(cardId) {
    GAME_CARD_IDS.forEach(id => {
        document.getElementById(id).style.display = (id === cardId) ? 'block' : 'none';
    });
    document.getElementById('trainingLoader').classList.toggle('active', cardId === 'trainingLoader');
}

function gameCardForMode(mode) {
    if (mode === 'der_die_das') return 'derDieDasPlayCard';
    if (mode === 'deutsch_meister') return 'deutschMeisterPlayCard';
    return 'gamePlayCard';
}

function onGameModeChange() {
    const stdOptions = document.getElementById('standardGameOptions');
    const statusGroup = document.getElementById('statusSelectionGroup');
    const statusLabel = document.getElementById('statusLabelText');

    // All modes share the same settings; Der-Die-Das only plays words with der/die/das (filtered on the server)
    stdOptions.style.display = 'block';
    statusGroup.style.display = 'block';
    statusLabel.textContent = 'Status zum Wiederholen auswählen';
}

function updateGameCategoriesCheckboxes() {
    const themaSelect = document.getElementById('gameThemaSelect');
    const catContainer = document.getElementById('gameCategoryCheckboxes');
    if (!catContainer) return;

    const selectedThema = themaSelect ? themaSelect.value : '';
    let availableWortarten = [];

    if (selectedThema && cachedThemaWortartenMap[selectedThema]) {
        availableWortarten = cachedThemaWortartenMap[selectedThema];
    } else {
        availableWortarten = cachedWortarten;
    }

    const prevChecked = new Set([...catContainer.querySelectorAll('input:checked')].map(i => i.value));
    catContainer.innerHTML = '';
    availableWortarten.forEach(wa => {
        const checked = prevChecked.has(wa) ? ' checked' : '';
        catContainer.innerHTML += `<label class="checkbox-label"><input type="checkbox" name="categories[]" value="${escapeAttr(wa)}"${checked}> ${escapeHtml(wa)}</label>`;
    });
}

function onGameThemaChange() {
    updateGameCategoriesCheckboxes();
}

function showGameSetup() {
    activeGameSettings = null;
    showGameCard('gameSetupCard');
}

async function startGameSession(e) {
    e.preventDefault();
    const form = document.getElementById('gameSetupForm');
    const formData = new FormData(form);
    const mode = formData.get('game_mode') || 'standard';
    const sortOrder = formData.get('sort_order') || 'ASC';
    const selectedThema = document.getElementById('gameThemaSelect').value;
    let themenList = [];
    if (selectedThema) {
        themenList = [selectedThema];
    }

    const filterSettings = {
        statuses: formData.getAll('game_statuses[]'),
        sharepoint_lists: formData.getAll('sharepoint_lists[]'),
        themen: themenList,
        categories: formData.getAll('categories[]'),
        sort_order: sortOrder
    };

    const settings = { mode: (mode === 'der_die_das' || mode === 'deutsch_meister') ? mode : 'standard', ...filterSettings };
    activeGameSettings = settings;
    // Show the loading animation instead of the previous word until the first word is there
    showGameCard('trainingLoader');
    window.scrollTo({ top: 0 });

    if (settings.mode === 'der_die_das') {
        dddStreakCount = 0;
        updateDddStreakUI();
        await fetchNextDerDieDasWord();
    } else if (settings.mode === 'deutsch_meister') {
        await fetchNextDeutschMeisterWord();
    } else {
        await fetchNextGameWord();
    }
    // Only if the user did not leave or restart in the meantime
    if (activeGameSettings === settings) showGameCard(gameCardForMode(settings.mode));
}

// --- Deutsch Meister Logic ---
async function fetchNextDeutschMeisterWord() {
    try {
        document.getElementById('dmResultContainer').style.display = 'none';
        document.getElementById('dmHiddenDetails').style.display = 'none';
        document.getElementById('dmUserSentence').value = '';
        document.getElementById('dmCheckBtn').disabled = false;
        document.getElementById('dmCheckBtn').textContent = 'Satz überprüfen 🚀';

        const wordDisplay = document.getElementById('dmWordDisplay');
        wordDisplay.textContent = 'Lade...';
        wordDisplay.style.color = 'grey';

        const res = await fetch('index.php?api=deutsch_meister_next&_ts=' + Date.now(), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            cache: 'no-store',
            body: JSON.stringify(activeGameSettings || {})
        });
        const data = await res.json();

        if (data.error === 'Unauthorized') {
            window.location.href = 'login.php';
            return;
        }

        if (!data.success || !data.word) {
            wordDisplay.textContent = 'Keine Wörter gefunden!';
            return;
        }

        currentDeutschMeisterWord = data.word;
        wordDisplay.textContent = currentDeutschMeisterWord.Wort;
        wordDisplay.style.color = 'grey';
    } catch (err) {
        console.error('Fehler beim Laden des Deutsch Meister Wortes', err);
    }
}

async function checkDeutschMeisterSentence() {
    const sentence = document.getElementById('dmUserSentence').value.trim();
    if (!sentence || !currentDeutschMeisterWord) {
        alert('Bitte gib zuerst einen Beispielsatz ein!');
        return;
    }

    const btn = document.getElementById('dmCheckBtn');
    btn.disabled = true;
    btn.textContent = 'Überprüfe Satz...';

    try {
        const res = await fetch('index.php?api=deutsch_meister_check_sentence&_ts=' + Date.now(), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            cache: 'no-store',
            body: JSON.stringify({
                sentence: sentence,
                word: currentDeutschMeisterWord.Wort
            })
        });
        const data = await res.json();

        if (data.error === 'Unauthorized') {
            window.location.href = 'login.php';
            return;
        }

        if (data.success) {
            const badge = document.getElementById('dmEvaluationBadge');
            const feedbackText = document.getElementById('dmFeedbackText');
            const hiddenDetails = document.getElementById('dmHiddenDetails');

            if (data.evaluation_result === 'ok') {
                badge.innerHTML = `<span style="color: var(--md-aktiva-green);">🎉 Perfekt! +${data.points} ${data.points === 1 ? 'Punkt' : 'Punkte'}</span>`;
            } else if (data.evaluation_result === 'wrong_context') {
                badge.innerHTML = `<span style="color: var(--md-danger);">❌ Falscher Kontext! ${data.points} Punkt</span>`;
            } else {
                badge.innerHTML = `<span style="color: var(--md-warning-yellow);">⚠️ Grammatikfehler! ${data.points > 0 ? '+' : ''}${data.points} Punkt</span>`;
            }

            feedbackText.innerHTML = parseMarkdown(data.feedback);

            const wDet = data.word_details || {};
            document.getElementById('dmResWord').textContent = wDet.Wort || '';
            document.getElementById('dmResArt').textContent = wDet.Artikel || 'Keiner';
            document.getElementById('dmResTrans').textContent = wDet.Übersetzung || '-';
            document.getElementById('dmResEx').textContent = wDet.Beispiel || '-';

            hiddenDetails.style.display = 'block';
            document.getElementById('dmResultContainer').style.display = 'block';

            todayReviewedCount++;
            updateDailyTrackerUI();
        } else {
            alert(data.error || 'Fehler bei der Auswertung.');
            btn.disabled = false;
            btn.textContent = 'Satz überprüfen 🚀';
        }
    } catch (err) {
        console.error('Fehler beim Prüfen des Deutsch Meister Satzes', err);
        alert('Netzwerkfehler.');
        btn.disabled = false;
        btn.textContent = 'Satz überprüfen 🚀';
    }
}

function updateDddStreakUI() {
    const valEl = document.getElementById('dddStreakValue');
    const counterEl = document.getElementById('dddStreakCounter');
    const titleEl = document.getElementById('dddGameTitle');
    const subtitleEl = document.getElementById('dddSubtitle');

    if (valEl) {
        valEl.textContent = dddStreakCount;
    }

    if (counterEl && titleEl && subtitleEl) {
        if (dddStreakCount >= 10) {
            counterEl.style.background = 'linear-gradient(135deg, #ab47bc, #ec407a)';
            counterEl.style.color = '#fff';
            titleEl.innerHTML = '<span class="h-ico" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 8l4.5 4 4.5-7 4.5 7L21 8l-2 11H5z"/></svg></span>DER-DIE-DAS-GODMODE';
            subtitleEl.innerHTML = '🔥 Absoluter Champion! Du zerschmetterst die Artikel!';
        } else if (dddStreakCount >= 7) {
            counterEl.style.background = '#e65100';
            counterEl.style.color = '#ffcc80';
            titleEl.innerHTML = '⚡ Blitzschneller Artikel-Ninja!';
            subtitleEl.innerHTML = '⚡ Unaufhaltsam! Noch 3 bis zum Olymp!';
        } else if (dddStreakCount >= 5) {
            counterEl.style.background = '#f57f17';
            counterEl.style.color = '#fff9c4';
            titleEl.innerHTML = '🔥 Der-Die-Das On Fire!';
            subtitleEl.innerHTML = '🚀 Perfekter Lauf! Weiter so!';
        } else if (dddStreakCount >= 3) {
            counterEl.style.background = '#00695c';
            counterEl.style.color = '#b2dfdb';
            titleEl.innerHTML = '<span class="h-ico" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="8.5"/><circle cx="12" cy="12" r="4.5"/><circle cx="12" cy="12" r="0.8"/></svg></span>Artikel-Detektiv im Einsatz';
            subtitleEl.innerHTML = '💡 Guter Start! Die Serie baut sich auf.';
        } else {
            counterEl.style.background = 'var(--md-surface-card)';
            counterEl.style.color = 'var(--md-accent-text)';
            titleEl.innerHTML = '<span class="h-ico" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="8.5"/><circle cx="12" cy="12" r="4.5"/><circle cx="12" cy="12" r="0.8"/></svg></span>Der-Die-Das Training';
            subtitleEl.innerHTML = 'Rate den richtigen Artikel für dieses Aktiva-Wort! (10 in Folge = Super Booster 🚀)';
        }
    }
}

async function fetchNextDerDieDasWord() {
    try {
        document.getElementById('dddFeedbackContainer').style.display = 'none';
        document.getElementById('dddExtraActionSection').style.display = 'none';
        document.getElementById('dddSentenceWriterBox').style.display = 'none';
        document.getElementById('dddSentenceCorrection').style.display = 'none';
        document.getElementById('dddUserSentence').value = '';
        document.getElementById('dddToggleSentenceBtn').textContent = '✍️ Satz schreiben (Bonus Punkte)';

        const wordDisplay = document.getElementById('dddWordDisplay');
        const transDisplay = document.getElementById('dddTranslationDisplay');
        wordDisplay.textContent = 'Lade...';
        wordDisplay.className = 'word-display';
        wordDisplay.style.color = 'grey';
        transDisplay.textContent = '';
        transDisplay.style.display = 'none';

        const res = await fetch('index.php?api=der_die_das_next&_ts=' + Date.now(), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            cache: 'no-store',
            body: JSON.stringify(activeGameSettings || {})
        });
        const data = await res.json();

        if (data.error === 'Unauthorized') {
            window.location.href = 'login.php';
            return;
        }

        if (!data.success || !data.word) {
            wordDisplay.textContent = 'Keine Wörter gefunden!';
            return;
        }

        currentDerDieDasWord = data.word;
        wordDisplay.textContent = currentDerDieDasWord.Wort;
        wordDisplay.style.color = 'grey';
    } catch (err) {
        console.error('Fehler beim Laden des Der-Die-Das Wordes', err);
    }
}

function openEditFromDdd() {
    if (!currentDerDieDasWord) return;
    openEditFromTraining(currentDerDieDasWord, 'derDieDasPlayCard');
}

async function deleteWordFromDdd() {
    if (!currentDerDieDasWord) return;
    if (!confirm(`Wort '${currentDerDieDasWord.Wort}' wirklich löschen?`)) return;

    const res = await fetch('index.php?api=delete&_ts=' + Date.now(), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        cache: 'no-store',
        body: JSON.stringify({ Wort: currentDerDieDasWord.Wort })
    });
    const result = await res.json();
    if (result.error === 'Unauthorized') {
        window.location.href = 'login.php';
        return;
    }
    if (result.success) {
        showAlert(result.message);
        await refreshMetadata();
        fetchNextDerDieDasWord();
        loadDashboardData(true);
    }
}

async function submitDerDieDasAnswer(guessedArtikel) {
    if (!currentDerDieDasWord) return;

    try {
        const res = await fetch('index.php?api=der_die_das_answer&_ts=' + Date.now(), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            cache: 'no-store',
            body: JSON.stringify({
                wort: currentDerDieDasWord.Wort,
                artikel: guessedArtikel,
                current_streak: dddStreakCount
            })
        });
        const data = await res.json();

        if (data.error === 'Unauthorized') {
            window.location.href = 'login.php';
            return;
        }

        if (data.success) {
            lastDddWasRight = data.is_correct;
            dddStreakCount = data.new_streak;
            updateDddStreakUI();

            const wordDisplay = document.getElementById('dddWordDisplay');
            const transDisplay = document.getElementById('dddTranslationDisplay');
            const correctArt = data.correct_artikel;

            if (correctArt === 'der') wordDisplay.style.color = 'var(--md-der)';
            else if (correctArt === 'die') wordDisplay.style.color = 'var(--md-die)';
            else if (correctArt === 'das') wordDisplay.style.color = 'var(--md-das)';
            else wordDisplay.style.color = 'var(--md-article-other)';

            if (currentDerDieDasWord.Übersetzung) {
                transDisplay.textContent = `Übersetzung: ${currentDerDieDasWord.Übersetzung}`;
                transDisplay.style.display = 'block';
            }

            const feedbackText = document.getElementById('dddFeedbackText');
            if (data.is_correct) {
                if (data.super_booster) {
                    feedbackText.innerHTML = `<span style="color: #ab47bc; font-size: 1.2rem;">🚀 GODMODE SUPER BOOSTER! 10er Streak geknackt! (+9 Score & Revisionsdatum aktualisiert)</span>`;
                } else {
                    feedbackText.innerHTML = `<span style="color: var(--md-aktiva-green);">Sehr gut! (+${data.points} Score, Status: ${escapeHtml(data.new_status)})</span>`;
                }
            } else {
                feedbackText.innerHTML = `<span style="color: var(--md-danger);">Schade! Streak auf 0 zurückgesetzt. Richtiger Artikel: <strong>${escapeHtml(correctArt)}</strong> (${data.points} Punkt)</span>`;
            }

            document.getElementById('dddFeedbackContainer').style.display = 'block';
            document.getElementById('dddExtraActionSection').style.display = 'block';

            todayReviewedCount++;
            updateDailyTrackerUI();
        }
    } catch (err) {
        console.error('Fehler beim Übermitteln der Der-Die-Das Antwort', err);
    }
}

function loadNextDerDieDasWord() {
    fetchNextDerDieDasWord();
}

function toggleDddSentenceWriter() {
    const box = document.getElementById('dddSentenceWriterBox');
    const btn = document.getElementById('dddToggleSentenceBtn');
    if (box.style.display === 'none') {
        box.style.display = 'block';
        btn.textContent = '✕ Satz schreiben schließen';
    } else {
        box.style.display = 'none';
        btn.textContent = '✍️ Satz schreiben (Bonus Punkte)';
    }
}

async function checkDddSentence() {
    const sentence = document.getElementById('dddUserSentence').value.trim();
    if (!sentence || !currentDerDieDasWord) {
        alert('Bitte gib zuerst einen Satz ein!');
        return;
    }

    const correctionEl = document.getElementById('dddSentenceCorrection');
    correctionEl.textContent = 'Prüfe Satz...';
    correctionEl.style.display = 'block';

    try {
        const res = await fetch('index.php?api=der_die_das_check_sentence&_ts=' + Date.now(), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            cache: 'no-store',
            body: JSON.stringify({
                sentence: sentence,
                word: currentDerDieDasWord.Wort,
                previous_was_right: lastDddWasRight ? 1 : 0
            })
        });
        const data = await res.json();

        if (data.error === 'Unauthorized') {
            window.location.href = 'login.php';
            return;
        }

        if (data.success) {
            if (data.is_perfect) {
                let msg = '';
                if (data.points_added === 9) {
                    msg = '<br><br>🎉 Absolut fehlerfrei und idiomatisch! Super-Booster angewendet (+9 Punkte / 3x sehr gut)!';
                } else if (data.points_added === 3) {
                    msg = '<br><br>🎉 Absolut fehlerfrei und idiomatisch! +3 Punkte Booster gutgeschrieben!';
                } else if (data.is_idiomatic) {
                    msg = `<br><br>✅ Richtig! +${data.points_added} Punkt (Wort ist noch nicht aktiva).`;
                } else {
                    msg = `<br><br>✅ Grammatikalisch korrekt! (Zwar nicht perfekt idiomatisch, daher +${data.points_added} Punkt vergeben).`;
                }
                correctionEl.innerHTML = `<span style="color: var(--md-text-success);">${parseMarkdown(data.correction)}</span>` + `<div style="color: var(--md-accent-text); font-weight: bold;">${msg}</div>`;
                todayReviewedCount++;
                updateDailyTrackerUI();
            } else {
                correctionEl.innerHTML = `<span style="color: #e53935; font-weight: bold;">⚠️ Der Satz enthält Grammatikfehler (${data.points_added > 0 ? '+' + data.points_added + ' Punkt gutgeschrieben' : 'keine Punkte'}).</span><br><br><span style="color: var(--md-on-surface);">${parseMarkdown(data.correction)}</span>`;
                todayReviewedCount++;
                updateDailyTrackerUI();
            }
        } else {
            correctionEl.textContent = data.error || 'Fehler bei der Prüfung.';
        }
    } catch (err) {
        console.error('Fehler beim Prüfen des Satzes', err);
        correctionEl.textContent = 'Netzwerkfehler.';
    }
}

function toggleStoryTransTable(btn) {
    const td = btn.closest('td');
    const transSpan = td.querySelector('.story-word-trans');
    if (transSpan.style.display === 'none') {
        transSpan.style.display = 'inline';
        btn.textContent = 'Übersetzung ausblenden';
    } else {
        transSpan.style.display = 'none';
        btn.textContent = 'Übersetzung anzeigen';
    }
}

async function trainSpecificWord(wort) {
    document.getElementById('themaModalOverlay').style.display = 'none';
    switchView('game');
    const settings = { mode: 'standard', specific_word: wort };
    activeGameSettings = settings;
    showGameCard('trainingLoader');
    await fetchNextGameWord();
    if (activeGameSettings === settings) showGameCard('gamePlayCard');
}

async function fetchNextGameWord() {

    try {
        const res = await fetch('index.php?api=game_next&_ts=' + Date.now(), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            cache: 'no-store',
            body: JSON.stringify(activeGameSettings || {})
        });
        const data = await res.json();

        if (data.error === 'Unauthorized') {
            window.location.href = 'login.php';
            return;
        }

        currentGameWord = data.word;
        lastPopupWordObject = data.word;

        const noticeEl = document.getElementById('gameNotice');
        if (data.notice) {
            noticeEl.textContent = data.notice;
            noticeEl.style.display = 'block';
        } else {
            noticeEl.style.display = 'none';
        }

        if (!currentGameWord) {
            document.getElementById('gamePlayCard').innerHTML = `<div class="card" style="text-align: center;"><p>Deine Datenbank ist komplett leer oder keine Wörter entsprechen den Filtern!</p><button onclick="switchView('dashboard')" class="btn">Zum Dashboard zurückkehren</button></div>`;
            return;
        }

        renderGameWord(true);
    } catch (err) {
        console.error('Fehler beim Laden des nächsten Vokabelworts', err);
    }
}

// Fills the standard training card with currentGameWord; resetUi closes the details and the KI box
function renderGameWord(resetUi) {
    const wordDisplay = document.getElementById('gameWordDisplay');
    wordDisplay.textContent = currentGameWord.Wort;
    wordDisplay.className = `word-display ${getArticleColorClass(currentGameWord.Artikel)}`;

    const conjDisplay = document.getElementById('gameConjugationDisplay');
    if (currentGameWord.VerbFlag == 1 && currentGameWord.Konjugation) {
        conjDisplay.textContent = `${currentGameWord.Konjugation}`;
        conjDisplay.style.display = 'block';
    } else {
        conjDisplay.style.display = 'none';
    }

    const pluralDisplay = document.getElementById('gamePluralDisplay');
    if (currentGameWord.VerbFlag != 1 && currentGameWord.Plural) {
        pluralDisplay.textContent = `${currentGameWord.Plural}`;
        pluralDisplay.style.display = 'block';
    } else {
        pluralDisplay.style.display = 'none';
    }

    const promoteContainer = document.getElementById('directPromoteContainer');
    const currentStatusLower = (currentGameWord.Status || '').toLowerCase().trim();
    if (currentStatusLower === 'aktiva') {
        promoteContainer.style.display = 'none';
    } else {
        promoteContainer.style.display = '';
    }

    document.getElementById('gTrans').textContent = currentGameWord.Übersetzung;
    document.getElementById('gSyn').textContent = currentGameWord.synonym || '-';
    document.getElementById('gList').textContent = currentGameWord.sharepoint_list || '-';
    document.getElementById('gThema').textContent = currentGameWord.Thema || '-';
    document.getElementById('gArt').textContent = currentGameWord.Artikel || 'Keiner';
    document.getElementById('gPlural').textContent = currentGameWord.Plural || '-';
    document.getElementById('gGrundverb').textContent = currentGameWord.grundverb || '-';
    document.getElementById('gPraefix').textContent = currentGameWord.praefix || '-';
    document.getElementById('gPraeposition').textContent = currentGameWord.praeposition_kollokation || '-';
    const gIsVerbWord = (currentGameWord.VerbFlag == 1);
    ['gGrundverbRow', 'gPraefixRow', 'gPraepositionRow'].forEach(id => {
        document.getElementById(id).style.display = gIsVerbWord ? '' : 'none';
    });
    ['gArtRow', 'gPluralRow'].forEach(id => {
        document.getElementById(id).style.display = gIsVerbWord ? 'none' : '';
    });
    document.getElementById('gWart').textContent = currentGameWord.Wortarten;
    document.getElementById('gIsVerb').textContent = (currentGameWord.VerbFlag == 1) ? 'Ja' : 'Nein';
    document.getElementById('gEx').textContent = currentGameWord.Beispiel;
    document.getElementById('gDates').textContent = `Erstellt: ${currentGameWord.Created || '-'} | Geändert: ${currentGameWord.Modified || '-'} | Nächste Übung: ${currentGameWord.NachsteUbungDatum || '-'}`;

    if (!resetUi) return;
    document.getElementById('userSentenceInput').value = '';
    document.getElementById('aiCorrectionResult').style.display = 'none';
    document.getElementById('sentenceWriterContainer').style.display = 'none';
    document.getElementById('toggleSentenceWriterBtn').textContent = 'Mit KI trainieren';

    document.getElementById('detailsBox').style.display = 'none';
    document.getElementById('revealBtn').innerHTML = 'Übersetzung anzeigen';
}

async function directPromoteCurrentWord() {
    if (!currentGameWord) return;
    const res = await fetch('index.php?api=direct_promote&_ts=' + Date.now(), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        cache: 'no-store',
        body: JSON.stringify({ Wort: currentGameWord.Wort })
    });
    const result = await res.json();
    if (result.error === 'Unauthorized') {
        window.location.href = 'login.php';
        return;
    }
    if (result.success) {
        showAlert(result.message);
        todayReviewedCount++;
        updateDailyTrackerUI();

        if (activeGameSettings && activeGameSettings.specific_word) {
            activeGameSettings = null;
            switchView('dashboard');
            return;
        }

        fetchNextGameWord();
    }
}

async function directPromoteFromPopup(wort) {
    const res = await fetch('index.php?api=direct_promote&_ts=' + Date.now(), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        cache: 'no-store',
        body: JSON.stringify({ Wort: wort })
    });
    const result = await res.json();
    if (result.error === 'Unauthorized') {
        window.location.href = 'login.php';
        return;
    }
    if (result.success) {
        showAlert(result.message);
        todayReviewedCount++;
        updateDailyTrackerUI();
        document.getElementById('themaModalOverlay').style.display = 'none';
        await refreshMetadata();
        fetchNextGameWord();
    }
}

function renderPopupActionButtons(wordObj) {
    const container = document.getElementById('modalActionButtons');
    if (!container || !wordObj) return;

    const rowJson = escapeAttr(JSON.stringify(wordObj));
    const isAlreadyAktiva = (wordObj.Status || '').toLowerCase().trim() === 'aktiva';

    container.innerHTML = `
        <div class="rate-group action-group" role="group" aria-label="Wort-Aktionen"><button type="button" class="rate-btn a-edit" onclick="editWordFromPopup(${rowJson})" title="Bearbeiten"><span class="rate-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 20h4L19 9l-4-4L4 16z"/><path d="M13.5 6.5l4 4"/></svg></span><span>Bear&shy;beiten</span></button><button type="button" class="rate-btn a-train" onclick="trainSpecificWord('${escapeJs(wordObj.Wort)}')" title="Üben"><span class="rate-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M13 2L4 14h7l-1 8 9-12h-7z"/></svg></span><span>Üben</span></button>${!isAlreadyAktiva ? `<button type="button" class="rate-btn r-direkt-aktiv" onclick="directPromoteFromPopup('${escapeJs(wordObj.Wort)}')" title="Direkt aktiv (Score 10)"><span class="rate-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M7 13l5-5 5 5M7 19l5-5 5 5"/></svg></span><span>direkt aktiv</span></button>` : ''}<button type="button" class="rate-btn a-delete" onclick="deleteWordFromPopup('${escapeJs(wordObj.Wort)}')" title="Löschen"><span class="rate-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 7h16M9 7V4h6v3M6.5 7l1 13h9l1-13M10 11v5M14 11v5"/></svg></span><span>Löschen</span></button></div>
    `;
}

function editWordFromPopup(rowObj) {
    document.getElementById('themaModalOverlay').style.display = 'none';
    switchView('dashboard');
    editWord(rowObj);
}

async function deleteWordFromPopup(wort) {
    if (!confirm(`Wort '${wort}' wirklich löschen?`)) return;
    const res = await fetch('index.php?api=delete&_ts=' + Date.now(), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        cache: 'no-store',
        body: JSON.stringify({ Wort: wort })
    });
    const result = await res.json();
    if (result.error === 'Unauthorized') {
        window.location.href = 'login.php';
        return;
    }
    if (result.success) {
        showAlert(result.message);
        document.getElementById('themaModalOverlay').style.display = 'none';
        await refreshMetadata();
        fetchNextGameWord();
        loadDashboardData(true);
    }
}

function closeThemaModal() {
    document.getElementById('themaModalOverlay').style.display = 'none';
    fetchNextGameWord();
}

function toggleGameDetails() {
    const box = document.getElementById('detailsBox');
    const btn = document.getElementById('revealBtn');
    if (box.style.display === 'none') {
        box.style.display = 'block';
        btn.innerHTML = 'Übersetzung ausblenden';
        checkAndLoadImageForCurrentWord();
    } else {
        box.style.display = 'none';
        btn.innerHTML = 'Übersetzung anzeigen';
    }
}

function toggleSentenceWriter() {
    const container = document.getElementById('sentenceWriterContainer');
    const btn = document.getElementById('toggleSentenceWriterBtn');
    if (container.style.display === 'none') {
        container.style.display = 'block';
        btn.textContent = 'KI-Training schließen';
    } else {
        container.style.display = 'none';
        btn.textContent = 'Mit KI trainieren';
    }
}

function parseMarkdown(text) {
    let safeText = escapeHtml(text);
    safeText = safeText.replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>');
    return safeText.replace(/\n/g, '<br>');
}

async function checkStandardSentenceBooster() {
    const sentence = document.getElementById('userSentenceInput').value.trim();
    if (!sentence || !currentGameWord) {
        alert('Bitte gib zuerst einen Satz ein!');
        return;
    }

    const correctionEl = document.getElementById('aiCorrectionResult');
    const correctionTextEl = document.getElementById('aiCorrectionText');
    correctionTextEl.textContent = 'Prüfe Satz...';
    correctionEl.style.display = 'block';

    try {
        const res = await fetch('index.php?api=check_sentence_booster&_ts=' + Date.now(), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            cache: 'no-store',
            body: JSON.stringify({
                sentence: sentence,
                word: currentGameWord.Wort
            })
        });
        const data = await res.json();

        if (data.error === 'Unauthorized') {
            window.location.href = 'login.php';
            return;
        }

        if (data.success) {
            if (data.is_perfect) {
                let msg = '';
                if (data.points_added === 9) {
                    msg = '<br><br>🎉 Absolut fehlerfrei und idiomatisch! Super-Booster angewendet (+9 Punkte Bonus / 3x sehr gut)!';
                } else if (data.points_added === 3) {
                    msg = '<br><br>🎉 Absolut fehlerfrei und idiomatisch! +3 Punkte Booster gutgeschrieben!';
                } else {
                    msg = `<br><br>✅ Grammatikalisch korrekt! (Zwar nicht perfekt idiomatisch, daher +${data.points_added} Punkt vergeben).`;
                }
                correctionTextEl.innerHTML = `<span style="color: var(--md-text-success);">${parseMarkdown(data.correction)}</span>` + `<div style="color: var(--md-accent-text); font-weight: bold; margin-top: 8px;">${msg}</div>`;
                todayReviewedCount++;
                updateDailyTrackerUI();
            } else {
                correctionTextEl.innerHTML = `<span style="color: #e53935; font-weight: bold;">⚠️ Der Satz enthält Grammatikfehler (+1 Punkt gutgeschrieben).</span><br><br><span style="color: var(--md-on-surface);">${parseMarkdown(data.correction)}</span>`;
                todayReviewedCount++;
                updateDailyTrackerUI();
            }
        } else {
            correctionTextEl.textContent = data.error || 'Fehler bei der Prüfung.';
        }
    } catch (err) {
        console.error('Fehler beim Prüfen des Standardsatzes', err);
        correctionTextEl.textContent = 'Netzwerkfehler.';
    }
}

async function submitGameAnswer(result) {
    if (!currentGameWord) return;
    const res = await fetch('index.php?api=game_answer&_ts=' + Date.now(), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        cache: 'no-store',
        body: JSON.stringify({ wort: currentGameWord.Wort, result: result })
    });
    const data = await res.json();
    if (data.error === 'Unauthorized') {
        window.location.href = 'login.php';
        return;
    }

    todayReviewedCount++;
    updateDailyTrackerUI();

    if (activeGameSettings && activeGameSettings.specific_word) {
        activeGameSettings = null;
        switchView('dashboard');
        return;
    }

    fetchNextGameWord();
}

async function inlineRateWord(wort, result, btn) {
    const group = btn ? btn.closest('.rate-group') : null;
    if (group) group.classList.add('is-saving');
    try {
        const res = await fetch('index.php?api=game_answer&_ts=' + Date.now(), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            cache: 'no-store',
            body: JSON.stringify({ wort: wort, result: result })
        });
        const data = await res.json();
        if (data.error === 'Unauthorized') {
            window.location.href = 'login.php';
            return;
        }

        todayReviewedCount++;
        updateDailyTrackerUI();
        showAlert(`Wort '${wort}' aktualisiert.`);
        await refreshMetadata();
        loadDashboardData(true);
    } catch (err) {
        console.error('Fehler beim Aktualisieren des Wortstatus', err);
        if (group) group.classList.remove('is-saving');
    }
}

async function inlinePromoteWord(wort, btn) {
    const group = btn ? btn.closest('.rate-group') : null;
    if (group) group.classList.add('is-saving');
    try {
        const res = await fetch('index.php?api=direct_promote&_ts=' + Date.now(), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            cache: 'no-store',
            body: JSON.stringify({ Wort: wort })
        });
        const result = await res.json();
        if (result.error === 'Unauthorized') {
            window.location.href = 'login.php';
            return;
        }
        if (result.success) {
            todayReviewedCount++;
            updateDailyTrackerUI();
            showAlert(result.message || `Wort '${wort}' ist jetzt aktiv.`);
            await refreshMetadata();
            loadDashboardData(true);
        } else if (group) {
            group.classList.remove('is-saving');
        }
    } catch (err) {
        console.error('Fehler beim direkten Aktivieren', err);
        if (group) group.classList.remove('is-saving');
    }
}

function openEditFromGame() {
    if (!currentGameWord) return;
    openEditFromTraining(currentGameWord, 'gamePlayCard');
}

function openEditFromTraining(word, cardId) {
    switchView('dashboard', { skipLoad: true });
    editReturnTraining = cardId;
    editWord(word);
}

// Back to the training exactly where it was, without loading a new word
function returnToTraining() {
    const cardId = editReturnTraining;
    editReturnTraining = null;
    document.querySelectorAll('.view').forEach(v => v.classList.remove('active'));
    document.getElementById('game-view').classList.add('active');
    showGameCard(cardId);
    window.scrollTo({ top: 0 });
}

function cancelWordForm() {
    const backToTraining = !!editReturnTraining;
    resetForm();
    if (backToTraining) returnToTraining();
}

// Shows the saved changes on the word that is being trained
function applyEditToTrainingWord(formData) {
    const fields = ['sharepoint_list', 'Thema', 'Wort', 'Artikel', 'Plural', 'Übersetzung', 'synonym', 'Wortarten', 'VerbFlag', 'Konjugation', 'grundverb', 'praefix', 'praeposition_kollokation', 'Beispiel'];
    const changes = {};
    fields.forEach(f => { changes[f] = (formData[f] ?? '').trim(); });
    if (changes.VerbFlag === '1') changes.Plural = ''; else { changes.grundverb = ''; changes.praefix = ''; changes.praeposition_kollokation = ''; }

    if (editReturnTraining === 'gamePlayCard' && currentGameWord) {
        Object.assign(currentGameWord, changes);
        renderGameWord(false);
    } else if (editReturnTraining === 'derDieDasPlayCard' && currentDerDieDasWord) {
        Object.assign(currentDerDieDasWord, changes);
        const wordDisplay = document.getElementById('dddWordDisplay');
        if (wordDisplay.textContent !== 'Lade...') wordDisplay.textContent = currentDerDieDasWord.Wort;
    }
}

async function deleteWordFromGame() {
    if (!currentGameWord) return;
    if (!confirm(`Wort '${currentGameWord.Wort}' wirklich löschen?`)) return;

    const res = await fetch('index.php?api=delete&_ts=' + Date.now(), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        cache: 'no-store',
        body: JSON.stringify({ Wort: currentGameWord.Wort })
    });
    const result = await res.json();
    if (result.error === 'Unauthorized') {
        window.location.href = 'login.php';
        return;
    }
    if (result.success) {
        showAlert(result.message);
        await refreshMetadata();
        fetchNextGameWord();
        loadDashboardData(true);
    }
}

function setLetterFilter(letter) {
    currentLetter = letter;
    document.querySelectorAll('.alphabet-btn').forEach(btn => {
        if (btn.textContent.trim() === (letter === '' ? 'Alle' : letter)) {
            btn.classList.add('active');
        } else {
            btn.classList.remove('active');
        }
    });
    loadDashboardData(true);
}

function handleSearchInput() {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => {
        loadDashboardData(true);
    }, 300);
}

function handleTranslationSearchInput() {
    clearTimeout(transSearchTimeout);
    transSearchTimeout = setTimeout(() => {
        loadDashboardData(true);
    }, 300);
}

// Close a sheet/dropdown and return focus to its trigger
function closeSheet(id) {
    const menu = document.getElementById(id);
    if (!menu) return;
    menu.open = false;
    const summary = menu.querySelector('summary');
    if (summary) summary.focus();
}

// Phones: drag a sheet down (from its handle/header, or when its content is scrolled to the top) to close it
function enableSheetSwipe(menu) {
    const panel = menu.querySelector('.sheet-panel');
    const body = menu.querySelector('.sheet-body');
    if (!panel) return;
    const phone = window.matchMedia('(max-width: 767.98px)');
    let startY = null, dy = 0;

    panel.addEventListener('touchstart', e => {
        if (!phone.matches || e.touches.length !== 1) return;
        const onGrip = e.target.closest('.sheet-handle, .sheet-header');
        if (!onGrip && body && body.scrollTop > 0) return;
        startY = e.touches[0].clientY;
        dy = 0;
    }, { passive: true });

    panel.addEventListener('touchmove', e => {
        if (startY === null) return;
        dy = e.touches[0].clientY - startY;
        if (dy <= 0) { panel.style.transform = ''; return; }
        panel.classList.add('is-dragging');
        panel.style.transform = `translateY(${dy}px)`;
    }, { passive: true });

    const end = () => {
        if (startY === null) return;
        startY = null;
        panel.classList.remove('is-dragging');
        panel.style.transform = '';
        if (dy > 90) closeSheet(menu.id);
        dy = 0;
    };
    panel.addEventListener('touchend', end);
    panel.addEventListener('touchcancel', end);
}

function closeFilterMenu() {
    const menu = document.getElementById('filterMenu');
    if (menu) {
        menu.open = false;
        menu.querySelector('summary').focus();
    }
}

function updateFilterBadge() {
    const badge = document.getElementById('filterBadge');
    if (!badge) return;
    const ids = ['filterList', 'filterThema', 'filterWortart', 'filterStatus', 'filterVerbFlag', 'filterGrundverb', 'filterPraefix'];
    let count = currentLetter ? 1 : 0;
    ids.forEach(id => {
        const el = document.getElementById(id);
        if (el && el.value !== '') count++;
    });
    badge.textContent = count;
    badge.hidden = count === 0;
}

async function loadDashboardData(reset = false) {
    updateFilterBadge();
    if (reset) {
        currentOffset = 0;
        hasMoreData = true;
    }

    const list = document.getElementById('filterList').value;
    const thema = document.getElementById('filterThema').value;
    const wortart = document.getElementById('filterWortart').value;
    const status = document.getElementById('filterStatus').value;
    const verbFlag = document.getElementById('filterVerbFlag').value;
    const grundverb = document.getElementById('filterGrundverb').value;
    const praefix = document.getElementById('filterPraefix').value;
    const searchVal = document.getElementById('filterSearch').value.trim();
    const transSearchVal = document.getElementById('filterTranslationSearch').value.trim();

    const url = `index.php?api=get_data&letter=${encodeURIComponent(currentLetter)}&search=${encodeURIComponent(searchVal)}&trans_search=${encodeURIComponent(transSearchVal)}&sharepoint_list=${encodeURIComponent(list)}&thema=${encodeURIComponent(thema)}&wortart=${encodeURIComponent(wortart)}&status=${encodeURIComponent(status)}&verb_flag=${encodeURIComponent(verbFlag)}&grundverb=${encodeURIComponent(grundverb)}&praefix=${encodeURIComponent(praefix)}&sort=${currentSort}&order=${currentOrder}&offset=${currentOffset}&_ts=` + Date.now();

    try {
        const res = await fetch(url, { cache: 'no-store' });
        const data = await res.json();
        if (data.error === 'Unauthorized') { window.location.href = 'login.php'; return; }

        if (data.words.length < 50) {
            hasMoreData = false;
        }

        if (reset) {
            masterWordsList = data.words;
        } else {
            masterWordsList = masterWordsList.concat(data.words);
        }

        renderTable(masterWordsList);
    } catch (err) {
        console.error('Fehler beim Laden der Daten', err);
    }
}

async function loadMoreDashboardData() {
    if (isLoadingMore || !hasMoreData) return;
    isLoadingMore = true;
    currentOffset += 50;
    await loadDashboardData(false);
    isLoadingMore = false;
}

const RATE_OPTIONS = [
    ['sehr_gut',      'r-sehr-gut',      '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3.5l2.6 5.3 5.9.9-4.3 4.1 1 5.8L12 16.9l-5.2 2.7 1-5.8-4.3-4.1 5.9-.9z"/></svg>', 'sehr gut'],
    ['yes',           'r-ja',            '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>', 'ja'],
    ['wiederholen',   'r-wiederholen',   '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 12a8 8 0 1 1-2.3-5.7"/><path d="M20 4v5h-5"/></svg>', 'wieder\u00ADholen'],
    ['passiv',        'r-passiv',        '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M19.5 14.5A8 8 0 1 1 9.5 4.5a6.5 6.5 0 0 0 10 10z"/></svg>', 'passiv'],
    ['warteschlange', 'r-warteschlange', '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/></svg>', 'warte\u00ADschlange']
];

function rateGroupHtml(wort, status) {
    const w = escapeJs(wort);
    const isAktiva = (status || '').toLowerCase().trim() === 'aktiva';
    const promote = isAktiva ? '' :
        `<button type="button" class="rate-btn r-direkt-aktiv" onclick="inlinePromoteWord('${w}', this)" title="Direkt aktiv (Score 10)"><span class="rate-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M7 13l5-5 5 5M7 19l5-5 5 5"/></svg></span><span>direkt aktiv</span></button>`;
    const buttons = promote + RATE_OPTIONS.map(([key, cls, icon, label]) =>
        `<button type="button" class="rate-btn ${cls}" onclick="inlineRateWord('${w}', '${key}', this)" title="${label}"><span class="rate-icon" aria-hidden="true">${icon}</span><span>${label}</span></button>`
    ).join('');
    return `<div class="rate-group" role="group" aria-label="Kenntnisse bewerten">${buttons}</div>`;
}

function renderTable(words) {
    const tbody = document.getElementById('wordTableBody');

    if (words.length === 0) {
        tbody.innerHTML = `<tr><td colspan="6" style="text-align: center; color: var(--md-text-muted); padding: 2rem;">Keine Vokabeln gefunden.</td></tr>`;
        return;
    }

    tbody.innerHTML = '';
    words.forEach(row => {
        const artClass = getArticleColorClass(row.Artikel);
        const isVerb = (row.VerbFlag == 1);

        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td data-label="Artikel"><strong>${escapeHtml(row.Artikel || '')}</strong></td>
            <td data-label="Wort" class="wort-cell ${artClass}">${escapeHtml(row.Wort || '')}</td>
            <td data-label="Übersetzung">
                <span class="story-word-trans" style="display: none; color: var(--md-accent-text);">${escapeHtml(row.Übersetzung || '')}</span>
                <button type="button" class="btn btn-secondary" style="padding: 2px 6px; font-size: 0.75rem; margin-top: 4px;" onclick="toggleStoryTransTable(this)">Übersetzung anzeigen</button>
            </td>
            <td class="kenntnisse-cell" data-label="Kenntnisse">${rateGroupHtml(row.Wort, row.Status)}</td>
            <td data-label="Status" class="status-cell" data-status="${escapeHtml((row.Status || '').toLowerCase().trim())}">${escapeHtml(row.Status || '')}</td>
            <td class="aktion-cell" data-label="Aktion">
                <div class="rate-group action-group" role="group" aria-label="Wort-Aktionen"><button type="button" class="rate-btn a-edit" onclick="editWord(${escapeAttr(JSON.stringify(row))})" title="Bearbeiten"><span class="rate-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 20h4L19 9l-4-4L4 16z"/><path d="M13.5 6.5l4 4"/></svg></span><span>Bear&shy;beiten</span></button><button type="button" class="rate-btn a-train" onclick="trainSpecificWord('${escapeJs(row.Wort)}')" title="Üben"><span class="rate-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M13 2L4 14h7l-1 8 9-12h-7z"/></svg></span><span>Üben</span></button><button type="button" class="rate-btn a-delete" onclick="deleteWord('${escapeJs(row.Wort)}')" title="Löschen"><span class="rate-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 7h16M9 7V4h6v3M6.5 7l1 13h9l1-13M10 11v5M14 11v5"/></svg></span><span>Löschen</span></button></div>
            </td>
        `;
        tbody.appendChild(tr);
    });
}

function setSort(column) {
    if (currentSort === column) {
        currentOrder = currentOrder === 'ASC' ? 'DESC' : 'ASC';
    } else {
        currentSort = column;
        currentOrder = 'ASC';
    }
    loadDashboardData(true);
}

function resetFilters() {
    currentLetter = '';
    document.querySelectorAll('.alphabet-btn').forEach((btn, idx) => {
        if (idx === 0) btn.classList.add('active');
        else btn.classList.remove('active');
    });
    document.getElementById('filterSearch').value = '';
    document.getElementById('filterTranslationSearch').value = '';
    document.getElementById('filterList').value = '';
    document.getElementById('filterThema').value = '';
    updateDashboardWortartenDropdown();
    document.getElementById('filterWortart').value = '';
    document.getElementById('filterStatus').value = '';
    document.getElementById('filterVerbFlag').value = '';
    document.getElementById('filterGrundverb').value = '';
    document.getElementById('filterPraefix').value = '';
    loadDashboardData(true);
}

function openAddForm() {
    editReturnTraining = null;
    resetForm();
    document.getElementById('formContainer').classList.add('active');
    document.getElementById('formToggleBar').style.display = 'none';
    document.getElementById('Wort').focus();
}

// Mirror of statusFromScore() in PHP – the server always recalculates it anyway
function statusFromScore(score) {
    if (score < -1) return 'warteschlange';
    if (score === -1) return 'passiv';
    if (score === 0) return 'neu';
    if (score < 10) return 'wiederholen';
    return 'aktiva';
}

// Mirror of scoreAfterRating() in PHP, only for the status preview in the word form
function scoreAfterRating(current, result) {
    switch (result) {
        case 'direkt_aktiv': return 10;
        case 'sehr_gut': return Math.max(current, 0) + 3;
        case 'yes': return Math.max(current, 0) + 1;
        case 'wiederholen': return statusFromScore(current) === 'wiederholen' ? Math.max(1, current - 1) : 1;
        case 'passiv': return -1;
        case 'warteschlange': return Math.min(current, -2);
        default: return current;
    }
}

// Score of the word currently in the form (0 for a new word)
let formCurrentScore = 0;

function setFormKenntnisse(key) {
    document.getElementById('Kenntnisse').value = key;
    const group = document.getElementById('formKenntnisse');
    group.classList.toggle('has-choice', key !== '');
    group.querySelectorAll('.rate-btn').forEach(btn => {
        const on = btn.dataset.rate === key;
        btn.classList.toggle('is-selected', on);
        btn.setAttribute('aria-checked', on ? 'true' : 'false');
    });
    // "direkt aktiv" only makes sense for words that are not aktiva yet (same as the main page)
    group.querySelector('.r-direkt-aktiv').style.display = statusFromScore(formCurrentScore) === 'aktiva' ? 'none' : '';
    const current = statusFromScore(formCurrentScore);
    const next = key ? statusFromScore(scoreAfterRating(formCurrentScore, key)) : current;
    document.getElementById('Status').value = next === current ? current : `${current} → ${next}`;
}

// Clicking the chosen option again removes the choice
function pickFormKenntnisse(key) {
    setFormKenntnisse(document.getElementById('Kenntnisse').value === key ? '' : key);
}

// Enter in the Wort field starts the AI fill for a new word instead of saving it half-empty
function handleWortKeydown(event) {
    if (event.key !== 'Enter') return;
    if (document.getElementById('original_wort').value) return; // editing: keep normal submit
    event.preventDefault();
    if (!document.getElementById('aiFillBtn').disabled) fillWordWithAI();
}

function toggleVerbFields() {
    const verbFlagSelect = document.getElementById('VerbFlag');
    const verbFieldsContainer = document.getElementById('verbFieldsContainer');
    const artikelGroup = document.getElementById('artikelGroup');
    const pluralGroup = document.getElementById('pluralGroup');

    if (verbFlagSelect && verbFieldsContainer && artikelGroup && pluralGroup) {
        if (verbFlagSelect.value === '1') {
            verbFieldsContainer.style.display = 'block';
            artikelGroup.style.display = 'none';
            pluralGroup.style.display = 'none';
        } else {
            verbFieldsContainer.style.display = 'none';
            artikelGroup.style.display = 'block';
            pluralGroup.style.display = 'block';
        }
    }
}

function resetForm() {
    document.getElementById('wordForm').reset();
    document.getElementById('original_wort').value = '';
    formCurrentScore = 0;
    setFormKenntnisse('');
    toggleVerbFields();
    hideEditImage();
    document.getElementById('formTitle').textContent = 'Neues Wort hinzufügen';
    document.getElementById('formSubmitBtn').textContent = 'Wort speichern';
    document.getElementById('formContainer').classList.remove('active');
    document.getElementById('formToggleBar').style.display = '';
}

function editWord(row) {
    document.getElementById('original_wort').value = row.Wort;
    document.getElementById('sharepoint_list').value = row.sharepoint_list || '';
    document.getElementById('Thema').value = row.Thema || '';
    document.getElementById('Wort').value = row.Wort || '';
    document.getElementById('Artikel').value = row.Artikel || '';
    document.getElementById('Plural').value = row.Plural || '';
    document.getElementById('Übersetzung').value = row.Übersetzung || '';
    document.getElementById('synonym').value = row.synonym || '';
    document.getElementById('Wortarten').value = row.Wortarten || '';

    const isVerb = (row.VerbFlag == 1) ? '1' : '0';
    document.getElementById('VerbFlag').value = isVerb;

    document.getElementById('Konjugation').value = row.Konjugation || '';
    document.getElementById('grundverb').value = row.grundverb || '';
    document.getElementById('praefix').value = row.praefix || '';
    document.getElementById('praeposition_kollokation').value = row.praeposition_kollokation || '';
    toggleVerbFields();

    document.getElementById('Beispiel').value = row.Beispiel || '';
    formCurrentScore = parseInt(row.Score, 10) || 0;
    setFormKenntnisse('');

    loadEditImage(row.Wort);

    document.getElementById('formTitle').textContent = 'Wort bearbeiten';
    document.getElementById('formSubmitBtn').textContent = 'Wort aktualisieren';
    document.getElementById('formContainer').classList.add('active');
    document.getElementById('formToggleBar').style.display = 'none';
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

async function submitWordForm(e) {
    e.preventDefault();
    const formData = {
        original_wort: document.getElementById('original_wort').value,
        sharepoint_list: document.getElementById('sharepoint_list').value,
        Thema: document.getElementById('Thema').value,
        Wort: document.getElementById('Wort').value,
        Artikel: document.getElementById('Artikel').value,
        Plural: document.getElementById('Plural').value,
        Übersetzung: document.getElementById('Übersetzung').value,
        synonym: document.getElementById('synonym').value,
        Wortarten: document.getElementById('Wortarten').value,
        VerbFlag: document.getElementById('VerbFlag').value,
        Konjugation: document.getElementById('Konjugation').value,
        grundverb: document.getElementById('grundverb').value,
        praefix: document.getElementById('praefix').value,
        praeposition_kollokation: document.getElementById('praeposition_kollokation').value,
        Beispiel: document.getElementById('Beispiel').value,
        Kenntnisse: document.getElementById('Kenntnisse').value
    };

    const res = await fetch('index.php?api=save&_ts=' + Date.now(), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        cache: 'no-store',
        body: JSON.stringify(formData)
    });
    const result = await res.json();
    if (result.error === 'Unauthorized') {
        window.location.href = 'login.php';
        return;
    }

    if (result.success) {
        showAlert(result.message);
        todayReviewedCount++;
        updateDailyTrackerUI();
        if (editReturnTraining) {
            // Edited from a training: go straight back to it, the lists refresh in the background
            applyEditToTrainingWord(formData);
            resetForm();
            returnToTraining();
            refreshMetadata();
            return;
        }
        resetForm();
        await refreshMetadata();
        loadDashboardData(true);
    } else {
        alert(result.message || 'Fehler beim Speichern des Wortes.');
    }
}

async function deleteWord(wort) {
    if (!confirm('Dieses Wort wirklich löschen?')) return;
    const res = await fetch('index.php?api=delete&_ts=' + Date.now(), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        cache: 'no-store',
        body: JSON.stringify({ Wort: wort })
    });
    const result = await res.json();
    if (result.error === 'Unauthorized') {
        window.location.href = 'login.php';
        return;
    }
    if (result.success) {
        showAlert(result.message);
        await refreshMetadata();
        loadDashboardData(true);
    }
}

function getArticleColorClass(artikel) {
    const art = (artikel || '').toLowerCase().trim();
    if (art === 'der') return 'wort-der';
    if (art === 'die') return 'wort-die';
    if (art === 'das') return 'wort-das';
    return 'wort-other';
}

function showAlert(msg) {
    const box = document.getElementById('alertBox');
    box.textContent = msg;
    box.style.display = 'block';
    setTimeout(() => { box.style.display = 'none'; }, 4000);
}

function escapeHtml(str) {
    return String(str ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}
// Safe inside HTML attributes (onclick="...")
function escapeAttr(str) {
    return String(str ?? '').replace(/&/g, '&amp;').replace(/'/g, '&#39;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}
// Safe inside a JS string literal that sits inside an HTML attribute: uses \xNN / \uNNNN escapes only
function escapeJs(str) {
    return String(str ?? '').replace(/[\\'"<>&\n\r\u2028\u2029]/g, c => {
        const code = c.charCodeAt(0);
        return code < 256 ? '\\x' + ('0' + code.toString(16)).slice(-2) : '\\u' + ('0000' + code.toString(16)).slice(-4);
    });
}
</script>

</body>
</html>