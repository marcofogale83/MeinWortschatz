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

// --- SUBSCRIPTION PLAN: free (no AI), ai (text AI), ai_full (text AI + image generation) ---
// Read on every request so a plan change applies at once. Unknown or missing values count as free.
$subscription = 'free';
if ($isLoggedIn) {
    try {
        $planStmt = $pdo->prepare("SELECT subscription FROM users WHERE id = ? LIMIT 1");
        $planStmt->execute([$uid]);
        $planValue = (string)$planStmt->fetchColumn();
        if (in_array($planValue, ['free', 'ai', 'ai_full'], true)) {
            $subscription = $planValue;
        }
    } catch (Throwable $e) {
        error_log('Subscription lookup failed: ' . $e->getMessage());
    }
}

// 'llm' = text AI (fill word, sentence checks, Deutsch Meister), 'image' = image generation
function planAllows(string $plan, string $feature): bool {
    if ($feature === 'llm') return $plan === 'ai' || $plan === 'ai_full';
    if ($feature === 'image') return $plan === 'ai_full';
    return true;
}

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

// True when the browser says the request comes from this site (Origin, else Referer).
// Browsers always send Origin on fetch() POSTs; a missing header is allowed for old clients.
// Compares host names only (no port), and also accepts the host a proxy forwards,
// because some hosts report "example.ch:443" or an internal name in HTTP_HOST.
function isSameOriginRequest(): bool {
    $source = $_SERVER['HTTP_ORIGIN'] ?? ($_SERVER['HTTP_REFERER'] ?? '');
    if ($source === '' || $source === 'null') {
        return true;
    }
    $origin = parse_url($source, PHP_URL_HOST);
    if (!is_string($origin) || $origin === '') {
        return false;
    }
    $origin = strtolower($origin);
    $hostOnly = fn($h) => strtolower(preg_replace('/:\d+$/', '', trim(explode(',', (string)$h)[0])));
    $candidates = [
        $_SERVER['HTTP_HOST'] ?? '',
        $_SERVER['HTTP_X_FORWARDED_HOST'] ?? '',
        $_SERVER['SERVER_NAME'] ?? '',
    ];
    foreach ($candidates as $c) {
        if ($c !== '' && hash_equals($hostOnly($c), $origin)) {
            return true;
        }
    }
    error_log('Blocked API POST: origin ' . $origin . ' does not match host ' . ($_SERVER['HTTP_HOST'] ?? ''));
    return false;
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

    // CSRF defence in depth (on top of the SameSite=Lax cookie): a POST must come from this site
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isSameOriginRequest()) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Ungültige Anfrage.']);
        exit;
    }

    session_write_close();

    // Plan gate: AI endpoints refuse requests the user's plan does not include
    $planFeatureByAction = [
        'ai_fill_word' => 'llm',
        'deutsch_meister_next' => 'llm',
        'deutsch_meister_check_sentence' => 'llm',
        'der_die_das_check_sentence' => 'llm',
        'check_sentence_booster' => 'llm',
        'generate_ai_image' => 'image',
    ];
    if (isset($planFeatureByAction[$action]) && !planAllows($subscription, $planFeatureByAction[$action])) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'upgrade_required' => true,
            'error' => $planFeatureByAction[$action] === 'image'
                ? 'Die Bildgenerierung ist nur im Abo „KI Plus“ enthalten.'
                : 'KI-Funktionen sind ab dem Abo „KI“ verfügbar.'
        ]);
        exit;
    }

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

        $prompt = "Create a single clear image that shows only the core meaning of the German vocabulary word '$word' (Translation: '$translation', Theme: '$thema'). Depict just the main concept, with no extra objects or busy background. The image must contain absolutely NO text: no words, letters, numbers, captions, labels, signs or watermarks. High quality, educational, professional photography or clear illustration style.";

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

    if ($action === 'get_plan') {
        echo json_encode(['name' => $subscription, 'llm' => planAllows($subscription, 'llm'), 'image' => planAllows($subscription, 'image')]);
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
            $saved = userQuery($pdo, "SELECT " . WORD_COLS . " FROM meine_wortschatz WHERE user_id = ? AND Wort = ?", [$uid, $wort])->fetch(PDO::FETCH_ASSOC) ?: null;
            if ($saved) normalizeVerbFlag($saved);
            echo json_encode(['success' => true, 'word' => $saved, 'message' => "Wort '$wort' erfolgreich aktualisiert!"]);
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
            $newStatus = saveWordScore($pdo, $uid, $wortToPromote, 10);
            echo json_encode(['success' => true, 'score' => 10, 'status' => $newStatus, 'message' => "Wort '$wortToPromote' direkt zu 'aktiva' (Score 10) befördert!"]);
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
                    "  \"konjugation\": \"Nur wenn Verb: IMMER nur die 3. Person Singular in Präsens, Präteritum und Perfekt, genau in diesem Format: 'er läuft, lief, ist gelaufen' (keine anderen Personen oder Zeiten; sonst leer)\",\n" .
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

                $newStatus = saveWordScore($pdo, $uid, $wort, $newScore);
                echo json_encode(['success' => true, 'score' => $newScore, 'status' => $newStatus]);
                exit;
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
    header('Location: login');
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

    <link rel="stylesheet" href="assets/fonts/nunito.css">

    <meta name="theme-color" content="#1e88e5">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="vokabiq">
    <link rel="apple-touch-icon" href="data:image/jpeg;base64,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">

    <link rel="icon" type="image/png" sizes="32x32" href="data:image/png;base64,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">

    <!-- Chart.js 4.5.1 (MIT), embedded so no separate file is needed -->
    <script>
/*!
 * Chart.js v4.5.1
 * https://www.chartjs.org
 * (c) 2025 Chart.js Contributors
 * Released under the MIT License
 */
!function(t,e){"object"==typeof exports&&"undefined"!=typeof module?module.exports=e():"function"==typeof define&&define.amd?define(e):(t="undefined"!=typeof globalThis?globalThis:t||self).Chart=e()}(this,(function(){"use strict";var t=Object.freeze({__proto__:null,get Colors(){return Jo},get Decimation(){return ta},get Filler(){return ba},get Legend(){return Ma},get SubTitle(){return Pa},get Title(){return ka},get Tooltip(){return Na}});function e(){}const i=(()=>{let t=0;return()=>t++})();function s(t){return null==t}function n(t){if(Array.isArray&&Array.isArray(t))return!0;const e=Object.prototype.toString.call(t);return"[object"===e.slice(0,7)&&"Array]"===e.slice(-6)}function o(t){return null!==t&&"[object Object]"===Object.prototype.toString.call(t)}function a(t){return("number"==typeof t||t instanceof Number)&&isFinite(+t)}function r(t,e){return a(t)?t:e}function l(t,e){return void 0===t?e:t}const h=(t,e)=>"string"==typeof t&&t.endsWith("%")?parseFloat(t)/100:+t/e,c=(t,e)=>"string"==typeof t&&t.endsWith("%")?parseFloat(t)/100*e:+t;function d(t,e,i){if(t&&"function"==typeof t.call)return t.apply(i,e)}function u(t,e,i,s){let a,r,l;if(n(t))if(r=t.length,s)for(a=r-1;a>=0;a--)e.call(i,t[a],a);else for(a=0;a<r;a++)e.call(i,t[a],a);else if(o(t))for(l=Object.keys(t),r=l.length,a=0;a<r;a++)e.call(i,t[l[a]],l[a])}function f(t,e){let i,s,n,o;if(!t||!e||t.length!==e.length)return!1;for(i=0,s=t.length;i<s;++i)if(n=t[i],o=e[i],n.datasetIndex!==o.datasetIndex||n.index!==o.index)return!1;return!0}function g(t){if(n(t))return t.map(g);if(o(t)){const e=Object.create(null),i=Object.keys(t),s=i.length;let n=0;for(;n<s;++n)e[i[n]]=g(t[i[n]]);return e}return t}function p(t){return-1===["__proto__","prototype","constructor"].indexOf(t)}function m(t,e,i,s){if(!p(t))return;const n=e[t],a=i[t];o(n)&&o(a)?x(n,a,s):e[t]=g(a)}function x(t,e,i){const s=n(e)?e:[e],a=s.length;if(!o(t))return t;const r=(i=i||{}).merger||m;let l;for(let e=0;e<a;++e){if(l=s[e],!o(l))continue;const n=Object.keys(l);for(let e=0,s=n.length;e<s;++e)r(n[e],t,l,i)}return t}function b(t,e){return x(t,e,{merger:_})}function _(t,e,i){if(!p(t))return;const s=e[t],n=i[t];o(s)&&o(n)?b(s,n):Object.prototype.hasOwnProperty.call(e,t)||(e[t]=g(n))}const y={"":t=>t,x:t=>t.x,y:t=>t.y};function v(t){const e=t.split("."),i=[];let s="";for(const t of e)s+=t,s.endsWith("\\")?s=s.slice(0,-1)+".":(i.push(s),s="");return i}function M(t,e){const i=y[e]||(y[e]=function(t){const e=v(t);return t=>{for(const i of e){if(""===i)break;t=t&&t[i]}return t}}(e));return i(t)}function w(t){return t.charAt(0).toUpperCase()+t.slice(1)}const k=t=>void 0!==t,S=t=>"function"==typeof t,P=(t,e)=>{if(t.size!==e.size)return!1;for(const i of t)if(!e.has(i))return!1;return!0};function D(t){return"mouseup"===t.type||"click"===t.type||"contextmenu"===t.type}const C=Math.PI,O=2*C,A=O+C,T=Number.POSITIVE_INFINITY,L=C/180,E=C/2,R=C/4,I=2*C/3,z=Math.log10,F=Math.sign;function V(t,e,i){return Math.abs(t-e)<i}function B(t){const e=Math.round(t);t=V(t,e,t/1e3)?e:t;const i=Math.pow(10,Math.floor(z(t))),s=t/i;return(s<=1?1:s<=2?2:s<=5?5:10)*i}function W(t){const e=[],i=Math.sqrt(t);let s;for(s=1;s<i;s++)t%s==0&&(e.push(s),e.push(t/s));return i===(0|i)&&e.push(i),e.sort(((t,e)=>t-e)).pop(),e}function N(t){return!function(t){return"symbol"==typeof t||"object"==typeof t&&null!==t&&!(Symbol.toPrimitive in t||"toString"in t||"valueOf"in t)}(t)&&!isNaN(parseFloat(t))&&isFinite(t)}function H(t,e){const i=Math.round(t);return i-e<=t&&i+e>=t}function j(t,e,i){let s,n,o;for(s=0,n=t.length;s<n;s++)o=t[s][i],isNaN(o)||(e.min=Math.min(e.min,o),e.max=Math.max(e.max,o))}function $(t){return t*(C/180)}function Y(t){return t*(180/C)}function U(t){if(!a(t))return;let e=1,i=0;for(;Math.round(t*e)/e!==t;)e*=10,i++;return i}function X(t,e){const i=e.x-t.x,s=e.y-t.y,n=Math.sqrt(i*i+s*s);let o=Math.atan2(s,i);return o<-.5*C&&(o+=O),{angle:o,distance:n}}function q(t,e){return Math.sqrt(Math.pow(e.x-t.x,2)+Math.pow(e.y-t.y,2))}function K(t,e){return(t-e+A)%O-C}function G(t){return(t%O+O)%O}function J(t,e,i,s){const n=G(t),o=G(e),a=G(i),r=G(o-n),l=G(a-n),h=G(n-o),c=G(n-a);return n===o||n===a||s&&o===a||r>l&&h<c}function Z(t,e,i){return Math.max(e,Math.min(i,t))}function Q(t){return Z(t,-32768,32767)}function tt(t,e,i,s=1e-6){return t>=Math.min(e,i)-s&&t<=Math.max(e,i)+s}function et(t,e,i){i=i||(i=>t[i]<e);let s,n=t.length-1,o=0;for(;n-o>1;)s=o+n>>1,i(s)?o=s:n=s;return{lo:o,hi:n}}const it=(t,e,i,s)=>et(t,i,s?s=>{const n=t[s][e];return n<i||n===i&&t[s+1][e]===i}:s=>t[s][e]<i),st=(t,e,i)=>et(t,i,(s=>t[s][e]>=i));function nt(t,e,i){let s=0,n=t.length;for(;s<n&&t[s]<e;)s++;for(;n>s&&t[n-1]>i;)n--;return s>0||n<t.length?t.slice(s,n):t}const ot=["push","pop","shift","splice","unshift"];function at(t,e){t._chartjs?t._chartjs.listeners.push(e):(Object.defineProperty(t,"_chartjs",{configurable:!0,enumerable:!1,value:{listeners:[e]}}),ot.forEach((e=>{const i="_onData"+w(e),s=t[e];Object.defineProperty(t,e,{configurable:!0,enumerable:!1,value(...e){const n=s.apply(this,e);return t._chartjs.listeners.forEach((t=>{"function"==typeof t[i]&&t[i](...e)})),n}})})))}function rt(t,e){const i=t._chartjs;if(!i)return;const s=i.listeners,n=s.indexOf(e);-1!==n&&s.splice(n,1),s.length>0||(ot.forEach((e=>{delete t[e]})),delete t._chartjs)}function lt(t){const e=new Set(t);return e.size===t.length?t:Array.from(e)}const ht="undefined"==typeof window?function(t){return t()}:window.requestAnimationFrame;function ct(t,e){let i=[],s=!1;return function(...n){i=n,s||(s=!0,ht.call(window,(()=>{s=!1,t.apply(e,i)})))}}function dt(t,e){let i;return function(...s){return e?(clearTimeout(i),i=setTimeout(t,e,s)):t.apply(this,s),e}}const ut=t=>"start"===t?"left":"end"===t?"right":"center",ft=(t,e,i)=>"start"===t?e:"end"===t?i:(e+i)/2,gt=(t,e,i,s)=>t===(s?"left":"right")?i:"center"===t?(e+i)/2:e;function pt(t,e,i){const n=e.length;let o=0,a=n;if(t._sorted){const{iScale:r,vScale:l,_parsed:h}=t,c=t.dataset&&t.dataset.options?t.dataset.options.spanGaps:null,d=r.axis,{min:u,max:f,minDefined:g,maxDefined:p}=r.getUserBounds();if(g){if(o=Math.min(it(h,d,u).lo,i?n:it(e,d,r.getPixelForValue(u)).lo),c){const t=h.slice(0,o+1).reverse().findIndex((t=>!s(t[l.axis])));o-=Math.max(0,t)}o=Z(o,0,n-1)}if(p){let t=Math.max(it(h,r.axis,f,!0).hi+1,i?0:it(e,d,r.getPixelForValue(f),!0).hi+1);if(c){const e=h.slice(t-1).findIndex((t=>!s(t[l.axis])));t+=Math.max(0,e)}a=Z(t,o,n)-o}else a=n-o}return{start:o,count:a}}function mt(t){const{xScale:e,yScale:i,_scaleRanges:s}=t,n={xmin:e.min,xmax:e.max,ymin:i.min,ymax:i.max};if(!s)return t._scaleRanges=n,!0;const o=s.xmin!==e.min||s.xmax!==e.max||s.ymin!==i.min||s.ymax!==i.max;return Object.assign(s,n),o}class xt{constructor(){this._request=null,this._charts=new Map,this._running=!1,this._lastDate=void 0}_notify(t,e,i,s){const n=e.listeners[s],o=e.duration;n.forEach((s=>s({chart:t,initial:e.initial,numSteps:o,currentStep:Math.min(i-e.start,o)})))}_refresh(){this._request||(this._running=!0,this._request=ht.call(window,(()=>{this._update(),this._request=null,this._running&&this._refresh()})))}_update(t=Date.now()){let e=0;this._charts.forEach(((i,s)=>{if(!i.running||!i.items.length)return;const n=i.items;let o,a=n.length-1,r=!1;for(;a>=0;--a)o=n[a],o._active?(o._total>i.duration&&(i.duration=o._total),o.tick(t),r=!0):(n[a]=n[n.length-1],n.pop());r&&(s.draw(),this._notify(s,i,t,"progress")),n.length||(i.running=!1,this._notify(s,i,t,"complete"),i.initial=!1),e+=n.length})),this._lastDate=t,0===e&&(this._running=!1)}_getAnims(t){const e=this._charts;let i=e.get(t);return i||(i={running:!1,initial:!0,items:[],listeners:{complete:[],progress:[]}},e.set(t,i)),i}listen(t,e,i){this._getAnims(t).listeners[e].push(i)}add(t,e){e&&e.length&&this._getAnims(t).items.push(...e)}has(t){return this._getAnims(t).items.length>0}start(t){const e=this._charts.get(t);e&&(e.running=!0,e.start=Date.now(),e.duration=e.items.reduce(((t,e)=>Math.max(t,e._duration)),0),this._refresh())}running(t){if(!this._running)return!1;const e=this._charts.get(t);return!!(e&&e.running&&e.items.length)}stop(t){const e=this._charts.get(t);if(!e||!e.items.length)return;const i=e.items;let s=i.length-1;for(;s>=0;--s)i[s].cancel();e.items=[],this._notify(t,e,Date.now(),"complete")}remove(t){return this._charts.delete(t)}}var bt=new xt;
/*!
 * @kurkle/color v0.3.2
 * https://github.com/kurkle/color#readme
 * (c) 2023 Jukka Kurkela
 * Released under the MIT License
 */function _t(t){return t+.5|0}const yt=(t,e,i)=>Math.max(Math.min(t,i),e);function vt(t){return yt(_t(2.55*t),0,255)}function Mt(t){return yt(_t(255*t),0,255)}function wt(t){return yt(_t(t/2.55)/100,0,1)}function kt(t){return yt(_t(100*t),0,100)}const St={0:0,1:1,2:2,3:3,4:4,5:5,6:6,7:7,8:8,9:9,A:10,B:11,C:12,D:13,E:14,F:15,a:10,b:11,c:12,d:13,e:14,f:15},Pt=[..."0123456789ABCDEF"],Dt=t=>Pt[15&t],Ct=t=>Pt[(240&t)>>4]+Pt[15&t],Ot=t=>(240&t)>>4==(15&t);function At(t){var e=(t=>Ot(t.r)&&Ot(t.g)&&Ot(t.b)&&Ot(t.a))(t)?Dt:Ct;return t?"#"+e(t.r)+e(t.g)+e(t.b)+((t,e)=>t<255?e(t):"")(t.a,e):void 0}const Tt=/^(hsla?|hwb|hsv)\(\s*([-+.e\d]+)(?:deg)?[\s,]+([-+.e\d]+)%[\s,]+([-+.e\d]+)%(?:[\s,]+([-+.e\d]+)(%)?)?\s*\)$/;function Lt(t,e,i){const s=e*Math.min(i,1-i),n=(e,n=(e+t/30)%12)=>i-s*Math.max(Math.min(n-3,9-n,1),-1);return[n(0),n(8),n(4)]}function Et(t,e,i){const s=(s,n=(s+t/60)%6)=>i-i*e*Math.max(Math.min(n,4-n,1),0);return[s(5),s(3),s(1)]}function Rt(t,e,i){const s=Lt(t,1,.5);let n;for(e+i>1&&(n=1/(e+i),e*=n,i*=n),n=0;n<3;n++)s[n]*=1-e-i,s[n]+=e;return s}function It(t){const e=t.r/255,i=t.g/255,s=t.b/255,n=Math.max(e,i,s),o=Math.min(e,i,s),a=(n+o)/2;let r,l,h;return n!==o&&(h=n-o,l=a>.5?h/(2-n-o):h/(n+o),r=function(t,e,i,s,n){return t===n?(e-i)/s+(e<i?6:0):e===n?(i-t)/s+2:(t-e)/s+4}(e,i,s,h,n),r=60*r+.5),[0|r,l||0,a]}function zt(t,e,i,s){return(Array.isArray(e)?t(e[0],e[1],e[2]):t(e,i,s)).map(Mt)}function Ft(t,e,i){return zt(Lt,t,e,i)}function Vt(t){return(t%360+360)%360}function Bt(t){const e=Tt.exec(t);let i,s=255;if(!e)return;e[5]!==i&&(s=e[6]?vt(+e[5]):Mt(+e[5]));const n=Vt(+e[2]),o=+e[3]/100,a=+e[4]/100;return i="hwb"===e[1]?function(t,e,i){return zt(Rt,t,e,i)}(n,o,a):"hsv"===e[1]?function(t,e,i){return zt(Et,t,e,i)}(n,o,a):Ft(n,o,a),{r:i[0],g:i[1],b:i[2],a:s}}const Wt={x:"dark",Z:"light",Y:"re",X:"blu",W:"gr",V:"medium",U:"slate",A:"ee",T:"ol",S:"or",B:"ra",C:"lateg",D:"ights",R:"in",Q:"turquois",E:"hi",P:"ro",O:"al",N:"le",M:"de",L:"yello",F:"en",K:"ch",G:"arks",H:"ea",I:"ightg",J:"wh"},Nt={OiceXe:"f0f8ff",antiquewEte:"faebd7",aqua:"ffff",aquamarRe:"7fffd4",azuY:"f0ffff",beige:"f5f5dc",bisque:"ffe4c4",black:"0",blanKedOmond:"ffebcd",Xe:"ff",XeviTet:"8a2be2",bPwn:"a52a2a",burlywood:"deb887",caMtXe:"5f9ea0",KartYuse:"7fff00",KocTate:"d2691e",cSO:"ff7f50",cSnflowerXe:"6495ed",cSnsilk:"fff8dc",crimson:"dc143c",cyan:"ffff",xXe:"8b",xcyan:"8b8b",xgTMnPd:"b8860b",xWay:"a9a9a9",xgYF:"6400",xgYy:"a9a9a9",xkhaki:"bdb76b",xmagFta:"8b008b",xTivegYF:"556b2f",xSange:"ff8c00",xScEd:"9932cc",xYd:"8b0000",xsOmon:"e9967a",xsHgYF:"8fbc8f",xUXe:"483d8b",xUWay:"2f4f4f",xUgYy:"2f4f4f",xQe:"ced1",xviTet:"9400d3",dAppRk:"ff1493",dApskyXe:"bfff",dimWay:"696969",dimgYy:"696969",dodgerXe:"1e90ff",fiYbrick:"b22222",flSOwEte:"fffaf0",foYstWAn:"228b22",fuKsia:"ff00ff",gaRsbSo:"dcdcdc",ghostwEte:"f8f8ff",gTd:"ffd700",gTMnPd:"daa520",Way:"808080",gYF:"8000",gYFLw:"adff2f",gYy:"808080",honeyMw:"f0fff0",hotpRk:"ff69b4",RdianYd:"cd5c5c",Rdigo:"4b0082",ivSy:"fffff0",khaki:"f0e68c",lavFMr:"e6e6fa",lavFMrXsh:"fff0f5",lawngYF:"7cfc00",NmoncEffon:"fffacd",ZXe:"add8e6",ZcSO:"f08080",Zcyan:"e0ffff",ZgTMnPdLw:"fafad2",ZWay:"d3d3d3",ZgYF:"90ee90",ZgYy:"d3d3d3",ZpRk:"ffb6c1",ZsOmon:"ffa07a",ZsHgYF:"20b2aa",ZskyXe:"87cefa",ZUWay:"778899",ZUgYy:"778899",ZstAlXe:"b0c4de",ZLw:"ffffe0",lime:"ff00",limegYF:"32cd32",lRF:"faf0e6",magFta:"ff00ff",maPon:"800000",VaquamarRe:"66cdaa",VXe:"cd",VScEd:"ba55d3",VpurpN:"9370db",VsHgYF:"3cb371",VUXe:"7b68ee",VsprRggYF:"fa9a",VQe:"48d1cc",VviTetYd:"c71585",midnightXe:"191970",mRtcYam:"f5fffa",mistyPse:"ffe4e1",moccasR:"ffe4b5",navajowEte:"ffdead",navy:"80",Tdlace:"fdf5e6",Tive:"808000",TivedBb:"6b8e23",Sange:"ffa500",SangeYd:"ff4500",ScEd:"da70d6",pOegTMnPd:"eee8aa",pOegYF:"98fb98",pOeQe:"afeeee",pOeviTetYd:"db7093",papayawEp:"ffefd5",pHKpuff:"ffdab9",peru:"cd853f",pRk:"ffc0cb",plum:"dda0dd",powMrXe:"b0e0e6",purpN:"800080",YbeccapurpN:"663399",Yd:"ff0000",Psybrown:"bc8f8f",PyOXe:"4169e1",saddNbPwn:"8b4513",sOmon:"fa8072",sandybPwn:"f4a460",sHgYF:"2e8b57",sHshell:"fff5ee",siFna:"a0522d",silver:"c0c0c0",skyXe:"87ceeb",UXe:"6a5acd",UWay:"708090",UgYy:"708090",snow:"fffafa",sprRggYF:"ff7f",stAlXe:"4682b4",tan:"d2b48c",teO:"8080",tEstN:"d8bfd8",tomato:"ff6347",Qe:"40e0d0",viTet:"ee82ee",JHt:"f5deb3",wEte:"ffffff",wEtesmoke:"f5f5f5",Lw:"ffff00",LwgYF:"9acd32"};let Ht;function jt(t){Ht||(Ht=function(){const t={},e=Object.keys(Nt),i=Object.keys(Wt);let s,n,o,a,r;for(s=0;s<e.length;s++){for(a=r=e[s],n=0;n<i.length;n++)o=i[n],r=r.replace(o,Wt[o]);o=parseInt(Nt[a],16),t[r]=[o>>16&255,o>>8&255,255&o]}return t}(),Ht.transparent=[0,0,0,0]);const e=Ht[t.toLowerCase()];return e&&{r:e[0],g:e[1],b:e[2],a:4===e.length?e[3]:255}}const $t=/^rgba?\(\s*([-+.\d]+)(%)?[\s,]+([-+.e\d]+)(%)?[\s,]+([-+.e\d]+)(%)?(?:[\s,/]+([-+.e\d]+)(%)?)?\s*\)$/;const Yt=t=>t<=.0031308?12.92*t:1.055*Math.pow(t,1/2.4)-.055,Ut=t=>t<=.04045?t/12.92:Math.pow((t+.055)/1.055,2.4);function Xt(t,e,i){if(t){let s=It(t);s[e]=Math.max(0,Math.min(s[e]+s[e]*i,0===e?360:1)),s=Ft(s),t.r=s[0],t.g=s[1],t.b=s[2]}}function qt(t,e){return t?Object.assign(e||{},t):t}function Kt(t){var e={r:0,g:0,b:0,a:255};return Array.isArray(t)?t.length>=3&&(e={r:t[0],g:t[1],b:t[2],a:255},t.length>3&&(e.a=Mt(t[3]))):(e=qt(t,{r:0,g:0,b:0,a:1})).a=Mt(e.a),e}function Gt(t){return"r"===t.charAt(0)?function(t){const e=$t.exec(t);let i,s,n,o=255;if(e){if(e[7]!==i){const t=+e[7];o=e[8]?vt(t):yt(255*t,0,255)}return i=+e[1],s=+e[3],n=+e[5],i=255&(e[2]?vt(i):yt(i,0,255)),s=255&(e[4]?vt(s):yt(s,0,255)),n=255&(e[6]?vt(n):yt(n,0,255)),{r:i,g:s,b:n,a:o}}}(t):Bt(t)}class Jt{constructor(t){if(t instanceof Jt)return t;const e=typeof t;let i;var s,n,o;"object"===e?i=Kt(t):"string"===e&&(o=(s=t).length,"#"===s[0]&&(4===o||5===o?n={r:255&17*St[s[1]],g:255&17*St[s[2]],b:255&17*St[s[3]],a:5===o?17*St[s[4]]:255}:7!==o&&9!==o||(n={r:St[s[1]]<<4|St[s[2]],g:St[s[3]]<<4|St[s[4]],b:St[s[5]]<<4|St[s[6]],a:9===o?St[s[7]]<<4|St[s[8]]:255})),i=n||jt(t)||Gt(t)),this._rgb=i,this._valid=!!i}get valid(){return this._valid}get rgb(){var t=qt(this._rgb);return t&&(t.a=wt(t.a)),t}set rgb(t){this._rgb=Kt(t)}rgbString(){return this._valid?(t=this._rgb)&&(t.a<255?`rgba(${t.r}, ${t.g}, ${t.b}, ${wt(t.a)})`:`rgb(${t.r}, ${t.g}, ${t.b})`):void 0;var t}hexString(){return this._valid?At(this._rgb):void 0}hslString(){return this._valid?function(t){if(!t)return;const e=It(t),i=e[0],s=kt(e[1]),n=kt(e[2]);return t.a<255?`hsla(${i}, ${s}%, ${n}%, ${wt(t.a)})`:`hsl(${i}, ${s}%, ${n}%)`}(this._rgb):void 0}mix(t,e){if(t){const i=this.rgb,s=t.rgb;let n;const o=e===n?.5:e,a=2*o-1,r=i.a-s.a,l=((a*r==-1?a:(a+r)/(1+a*r))+1)/2;n=1-l,i.r=255&l*i.r+n*s.r+.5,i.g=255&l*i.g+n*s.g+.5,i.b=255&l*i.b+n*s.b+.5,i.a=o*i.a+(1-o)*s.a,this.rgb=i}return this}interpolate(t,e){return t&&(this._rgb=function(t,e,i){const s=Ut(wt(t.r)),n=Ut(wt(t.g)),o=Ut(wt(t.b));return{r:Mt(Yt(s+i*(Ut(wt(e.r))-s))),g:Mt(Yt(n+i*(Ut(wt(e.g))-n))),b:Mt(Yt(o+i*(Ut(wt(e.b))-o))),a:t.a+i*(e.a-t.a)}}(this._rgb,t._rgb,e)),this}clone(){return new Jt(this.rgb)}alpha(t){return this._rgb.a=Mt(t),this}clearer(t){return this._rgb.a*=1-t,this}greyscale(){const t=this._rgb,e=_t(.3*t.r+.59*t.g+.11*t.b);return t.r=t.g=t.b=e,this}opaquer(t){return this._rgb.a*=1+t,this}negate(){const t=this._rgb;return t.r=255-t.r,t.g=255-t.g,t.b=255-t.b,this}lighten(t){return Xt(this._rgb,2,t),this}darken(t){return Xt(this._rgb,2,-t),this}saturate(t){return Xt(this._rgb,1,t),this}desaturate(t){return Xt(this._rgb,1,-t),this}rotate(t){return function(t,e){var i=It(t);i[0]=Vt(i[0]+e),i=Ft(i),t.r=i[0],t.g=i[1],t.b=i[2]}(this._rgb,t),this}}function Zt(t){if(t&&"object"==typeof t){const e=t.toString();return"[object CanvasPattern]"===e||"[object CanvasGradient]"===e}return!1}function Qt(t){return Zt(t)?t:new Jt(t)}function te(t){return Zt(t)?t:new Jt(t).saturate(.5).darken(.1).hexString()}const ee=["x","y","borderWidth","radius","tension"],ie=["color","borderColor","backgroundColor"];const se=new Map;function ne(t,e,i){return function(t,e){e=e||{};const i=t+JSON.stringify(e);let s=se.get(i);return s||(s=new Intl.NumberFormat(t,e),se.set(i,s)),s}(e,i).format(t)}const oe={values:t=>n(t)?t:""+t,numeric(t,e,i){if(0===t)return"0";const s=this.chart.options.locale;let n,o=t;if(i.length>1){const e=Math.max(Math.abs(i[0].value),Math.abs(i[i.length-1].value));(e<1e-4||e>1e15)&&(n="scientific"),o=function(t,e){let i=e.length>3?e[2].value-e[1].value:e[1].value-e[0].value;Math.abs(i)>=1&&t!==Math.floor(t)&&(i=t-Math.floor(t));return i}(t,i)}const a=z(Math.abs(o)),r=isNaN(a)?1:Math.max(Math.min(-1*Math.floor(a),20),0),l={notation:n,minimumFractionDigits:r,maximumFractionDigits:r};return Object.assign(l,this.options.ticks.format),ne(t,s,l)},logarithmic(t,e,i){if(0===t)return"0";const s=i[e].significand||t/Math.pow(10,Math.floor(z(t)));return[1,2,3,5,10,15].includes(s)||e>.8*i.length?oe.numeric.call(this,t,e,i):""}};var ae={formatters:oe};const re=Object.create(null),le=Object.create(null);function he(t,e){if(!e)return t;const i=e.split(".");for(let e=0,s=i.length;e<s;++e){const s=i[e];t=t[s]||(t[s]=Object.create(null))}return t}function ce(t,e,i){return"string"==typeof e?x(he(t,e),i):x(he(t,""),e)}class de{constructor(t,e){this.animation=void 0,this.backgroundColor="rgba(0,0,0,0.1)",this.borderColor="rgba(0,0,0,0.1)",this.color="#666",this.datasets={},this.devicePixelRatio=t=>t.chart.platform.getDevicePixelRatio(),this.elements={},this.events=["mousemove","mouseout","click","touchstart","touchmove"],this.font={family:"'Helvetica Neue', 'Helvetica', 'Arial', sans-serif",size:12,style:"normal",lineHeight:1.2,weight:null},this.hover={},this.hoverBackgroundColor=(t,e)=>te(e.backgroundColor),this.hoverBorderColor=(t,e)=>te(e.borderColor),this.hoverColor=(t,e)=>te(e.color),this.indexAxis="x",this.interaction={mode:"nearest",intersect:!0,includeInvisible:!1},this.maintainAspectRatio=!0,this.onHover=null,this.onClick=null,this.parsing=!0,this.plugins={},this.responsive=!0,this.scale=void 0,this.scales={},this.showLine=!0,this.drawActiveElementsOnTop=!0,this.describe(t),this.apply(e)}set(t,e){return ce(this,t,e)}get(t){return he(this,t)}describe(t,e){return ce(le,t,e)}override(t,e){return ce(re,t,e)}route(t,e,i,s){const n=he(this,t),a=he(this,i),r="_"+e;Object.defineProperties(n,{[r]:{value:n[e],writable:!0},[e]:{enumerable:!0,get(){const t=this[r],e=a[s];return o(t)?Object.assign({},e,t):l(t,e)},set(t){this[r]=t}}})}apply(t){t.forEach((t=>t(this)))}}var ue=new de({_scriptable:t=>!t.startsWith("on"),_indexable:t=>"events"!==t,hover:{_fallback:"interaction"},interaction:{_scriptable:!1,_indexable:!1}},[function(t){t.set("animation",{delay:void 0,duration:1e3,easing:"easeOutQuart",fn:void 0,from:void 0,loop:void 0,to:void 0,type:void 0}),t.describe("animation",{_fallback:!1,_indexable:!1,_scriptable:t=>"onProgress"!==t&&"onComplete"!==t&&"fn"!==t}),t.set("animations",{colors:{type:"color",properties:ie},numbers:{type:"number",properties:ee}}),t.describe("animations",{_fallback:"animation"}),t.set("transitions",{active:{animation:{duration:400}},resize:{animation:{duration:0}},show:{animations:{colors:{from:"transparent"},visible:{type:"boolean",duration:0}}},hide:{animations:{colors:{to:"transparent"},visible:{type:"boolean",easing:"linear",fn:t=>0|t}}}})},function(t){t.set("layout",{autoPadding:!0,padding:{top:0,right:0,bottom:0,left:0}})},function(t){t.set("scale",{display:!0,offset:!1,reverse:!1,beginAtZero:!1,bounds:"ticks",clip:!0,grace:0,grid:{display:!0,lineWidth:1,drawOnChartArea:!0,drawTicks:!0,tickLength:8,tickWidth:(t,e)=>e.lineWidth,tickColor:(t,e)=>e.color,offset:!1},border:{display:!0,dash:[],dashOffset:0,width:1},title:{display:!1,text:"",padding:{top:4,bottom:4}},ticks:{minRotation:0,maxRotation:50,mirror:!1,textStrokeWidth:0,textStrokeColor:"",padding:3,display:!0,autoSkip:!0,autoSkipPadding:3,labelOffset:0,callback:ae.formatters.values,minor:{},major:{},align:"center",crossAlign:"near",showLabelBackdrop:!1,backdropColor:"rgba(255, 255, 255, 0.75)",backdropPadding:2}}),t.route("scale.ticks","color","","color"),t.route("scale.grid","color","","borderColor"),t.route("scale.border","color","","borderColor"),t.route("scale.title","color","","color"),t.describe("scale",{_fallback:!1,_scriptable:t=>!t.startsWith("before")&&!t.startsWith("after")&&"callback"!==t&&"parser"!==t,_indexable:t=>"borderDash"!==t&&"tickBorderDash"!==t&&"dash"!==t}),t.describe("scales",{_fallback:"scale"}),t.describe("scale.ticks",{_scriptable:t=>"backdropPadding"!==t&&"callback"!==t,_indexable:t=>"backdropPadding"!==t})}]);function fe(){return"undefined"!=typeof window&&"undefined"!=typeof document}function ge(t){let e=t.parentNode;return e&&"[object ShadowRoot]"===e.toString()&&(e=e.host),e}function pe(t,e,i){let s;return"string"==typeof t?(s=parseInt(t,10),-1!==t.indexOf("%")&&(s=s/100*e.parentNode[i])):s=t,s}const me=t=>t.ownerDocument.defaultView.getComputedStyle(t,null);function xe(t,e){return me(t).getPropertyValue(e)}const be=["top","right","bottom","left"];function _e(t,e,i){const s={};i=i?"-"+i:"";for(let n=0;n<4;n++){const o=be[n];s[o]=parseFloat(t[e+"-"+o+i])||0}return s.width=s.left+s.right,s.height=s.top+s.bottom,s}const ye=(t,e,i)=>(t>0||e>0)&&(!i||!i.shadowRoot);function ve(t,e){if("native"in t)return t;const{canvas:i,currentDevicePixelRatio:s}=e,n=me(i),o="border-box"===n.boxSizing,a=_e(n,"padding"),r=_e(n,"border","width"),{x:l,y:h,box:c}=function(t,e){const i=t.touches,s=i&&i.length?i[0]:t,{offsetX:n,offsetY:o}=s;let a,r,l=!1;if(ye(n,o,t.target))a=n,r=o;else{const t=e.getBoundingClientRect();a=s.clientX-t.left,r=s.clientY-t.top,l=!0}return{x:a,y:r,box:l}}(t,i),d=a.left+(c&&r.left),u=a.top+(c&&r.top);let{width:f,height:g}=e;return o&&(f-=a.width+r.width,g-=a.height+r.height),{x:Math.round((l-d)/f*i.width/s),y:Math.round((h-u)/g*i.height/s)}}const Me=t=>Math.round(10*t)/10;function we(t,e,i,s){const n=me(t),o=_e(n,"margin"),a=pe(n.maxWidth,t,"clientWidth")||T,r=pe(n.maxHeight,t,"clientHeight")||T,l=function(t,e,i){let s,n;if(void 0===e||void 0===i){const o=t&&ge(t);if(o){const t=o.getBoundingClientRect(),a=me(o),r=_e(a,"border","width"),l=_e(a,"padding");e=t.width-l.width-r.width,i=t.height-l.height-r.height,s=pe(a.maxWidth,o,"clientWidth"),n=pe(a.maxHeight,o,"clientHeight")}else e=t.clientWidth,i=t.clientHeight}return{width:e,height:i,maxWidth:s||T,maxHeight:n||T}}(t,e,i);let{width:h,height:c}=l;if("content-box"===n.boxSizing){const t=_e(n,"border","width"),e=_e(n,"padding");h-=e.width+t.width,c-=e.height+t.height}h=Math.max(0,h-o.width),c=Math.max(0,s?h/s:c-o.height),h=Me(Math.min(h,a,l.maxWidth)),c=Me(Math.min(c,r,l.maxHeight)),h&&!c&&(c=Me(h/2));return(void 0!==e||void 0!==i)&&s&&l.height&&c>l.height&&(c=l.height,h=Me(Math.floor(c*s))),{width:h,height:c}}function ke(t,e,i){const s=e||1,n=Me(t.height*s),o=Me(t.width*s);t.height=Me(t.height),t.width=Me(t.width);const a=t.canvas;return a.style&&(i||!a.style.height&&!a.style.width)&&(a.style.height=`${t.height}px`,a.style.width=`${t.width}px`),(t.currentDevicePixelRatio!==s||a.height!==n||a.width!==o)&&(t.currentDevicePixelRatio=s,a.height=n,a.width=o,t.ctx.setTransform(s,0,0,s,0,0),!0)}const Se=function(){let t=!1;try{const e={get passive(){return t=!0,!1}};fe()&&(window.addEventListener("test",null,e),window.removeEventListener("test",null,e))}catch(t){}return t}();function Pe(t,e){const i=xe(t,e),s=i&&i.match(/^(\d+)(\.\d+)?px$/);return s?+s[1]:void 0}function De(t){return!t||s(t.size)||s(t.family)?null:(t.style?t.style+" ":"")+(t.weight?t.weight+" ":"")+t.size+"px "+t.family}function Ce(t,e,i,s,n){let o=e[n];return o||(o=e[n]=t.measureText(n).width,i.push(n)),o>s&&(s=o),s}function Oe(t,e,i,s){let o=(s=s||{}).data=s.data||{},a=s.garbageCollect=s.garbageCollect||[];s.font!==e&&(o=s.data={},a=s.garbageCollect=[],s.font=e),t.save(),t.font=e;let r=0;const l=i.length;let h,c,d,u,f;for(h=0;h<l;h++)if(u=i[h],null==u||n(u)){if(n(u))for(c=0,d=u.length;c<d;c++)f=u[c],null==f||n(f)||(r=Ce(t,o,a,r,f))}else r=Ce(t,o,a,r,u);t.restore();const g=a.length/2;if(g>i.length){for(h=0;h<g;h++)delete o[a[h]];a.splice(0,g)}return r}function Ae(t,e,i){const s=t.currentDevicePixelRatio,n=0!==i?Math.max(i/2,.5):0;return Math.round((e-n)*s)/s+n}function Te(t,e){(e||t)&&((e=e||t.getContext("2d")).save(),e.resetTransform(),e.clearRect(0,0,t.width,t.height),e.restore())}function Le(t,e,i,s){Ee(t,e,i,s,null)}function Ee(t,e,i,s,n){let o,a,r,l,h,c,d,u;const f=e.pointStyle,g=e.rotation,p=e.radius;let m=(g||0)*L;if(f&&"object"==typeof f&&(o=f.toString(),"[object HTMLImageElement]"===o||"[object HTMLCanvasElement]"===o))return t.save(),t.translate(i,s),t.rotate(m),t.drawImage(f,-f.width/2,-f.height/2,f.width,f.height),void t.restore();if(!(isNaN(p)||p<=0)){switch(t.beginPath(),f){default:n?t.ellipse(i,s,n/2,p,0,0,O):t.arc(i,s,p,0,O),t.closePath();break;case"triangle":c=n?n/2:p,t.moveTo(i+Math.sin(m)*c,s-Math.cos(m)*p),m+=I,t.lineTo(i+Math.sin(m)*c,s-Math.cos(m)*p),m+=I,t.lineTo(i+Math.sin(m)*c,s-Math.cos(m)*p),t.closePath();break;case"rectRounded":h=.516*p,l=p-h,a=Math.cos(m+R)*l,d=Math.cos(m+R)*(n?n/2-h:l),r=Math.sin(m+R)*l,u=Math.sin(m+R)*(n?n/2-h:l),t.arc(i-d,s-r,h,m-C,m-E),t.arc(i+u,s-a,h,m-E,m),t.arc(i+d,s+r,h,m,m+E),t.arc(i-u,s+a,h,m+E,m+C),t.closePath();break;case"rect":if(!g){l=Math.SQRT1_2*p,c=n?n/2:l,t.rect(i-c,s-l,2*c,2*l);break}m+=R;case"rectRot":d=Math.cos(m)*(n?n/2:p),a=Math.cos(m)*p,r=Math.sin(m)*p,u=Math.sin(m)*(n?n/2:p),t.moveTo(i-d,s-r),t.lineTo(i+u,s-a),t.lineTo(i+d,s+r),t.lineTo(i-u,s+a),t.closePath();break;case"crossRot":m+=R;case"cross":d=Math.cos(m)*(n?n/2:p),a=Math.cos(m)*p,r=Math.sin(m)*p,u=Math.sin(m)*(n?n/2:p),t.moveTo(i-d,s-r),t.lineTo(i+d,s+r),t.moveTo(i+u,s-a),t.lineTo(i-u,s+a);break;case"star":d=Math.cos(m)*(n?n/2:p),a=Math.cos(m)*p,r=Math.sin(m)*p,u=Math.sin(m)*(n?n/2:p),t.moveTo(i-d,s-r),t.lineTo(i+d,s+r),t.moveTo(i+u,s-a),t.lineTo(i-u,s+a),m+=R,d=Math.cos(m)*(n?n/2:p),a=Math.cos(m)*p,r=Math.sin(m)*p,u=Math.sin(m)*(n?n/2:p),t.moveTo(i-d,s-r),t.lineTo(i+d,s+r),t.moveTo(i+u,s-a),t.lineTo(i-u,s+a);break;case"line":a=n?n/2:Math.cos(m)*p,r=Math.sin(m)*p,t.moveTo(i-a,s-r),t.lineTo(i+a,s+r);break;case"dash":t.moveTo(i,s),t.lineTo(i+Math.cos(m)*(n?n/2:p),s+Math.sin(m)*p);break;case!1:t.closePath()}t.fill(),e.borderWidth>0&&t.stroke()}}function Re(t,e,i){return i=i||.5,!e||t&&t.x>e.left-i&&t.x<e.right+i&&t.y>e.top-i&&t.y<e.bottom+i}function Ie(t,e){t.save(),t.beginPath(),t.rect(e.left,e.top,e.right-e.left,e.bottom-e.top),t.clip()}function ze(t){t.restore()}function Fe(t,e,i,s,n){if(!e)return t.lineTo(i.x,i.y);if("middle"===n){const s=(e.x+i.x)/2;t.lineTo(s,e.y),t.lineTo(s,i.y)}else"after"===n!=!!s?t.lineTo(e.x,i.y):t.lineTo(i.x,e.y);t.lineTo(i.x,i.y)}function Ve(t,e,i,s){if(!e)return t.lineTo(i.x,i.y);t.bezierCurveTo(s?e.cp1x:e.cp2x,s?e.cp1y:e.cp2y,s?i.cp2x:i.cp1x,s?i.cp2y:i.cp1y,i.x,i.y)}function Be(t,e,i,s,n){if(n.strikethrough||n.underline){const o=t.measureText(s),a=e-o.actualBoundingBoxLeft,r=e+o.actualBoundingBoxRight,l=i-o.actualBoundingBoxAscent,h=i+o.actualBoundingBoxDescent,c=n.strikethrough?(l+h)/2:h;t.strokeStyle=t.fillStyle,t.beginPath(),t.lineWidth=n.decorationWidth||2,t.moveTo(a,c),t.lineTo(r,c),t.stroke()}}function We(t,e){const i=t.fillStyle;t.fillStyle=e.color,t.fillRect(e.left,e.top,e.width,e.height),t.fillStyle=i}function Ne(t,e,i,o,a,r={}){const l=n(e)?e:[e],h=r.strokeWidth>0&&""!==r.strokeColor;let c,d;for(t.save(),t.font=a.string,function(t,e){e.translation&&t.translate(e.translation[0],e.translation[1]),s(e.rotation)||t.rotate(e.rotation),e.color&&(t.fillStyle=e.color),e.textAlign&&(t.textAlign=e.textAlign),e.textBaseline&&(t.textBaseline=e.textBaseline)}(t,r),c=0;c<l.length;++c)d=l[c],r.backdrop&&We(t,r.backdrop),h&&(r.strokeColor&&(t.strokeStyle=r.strokeColor),s(r.strokeWidth)||(t.lineWidth=r.strokeWidth),t.strokeText(d,i,o,r.maxWidth)),t.fillText(d,i,o,r.maxWidth),Be(t,i,o,d,r),o+=Number(a.lineHeight);t.restore()}function He(t,e){const{x:i,y:s,w:n,h:o,radius:a}=e;t.arc(i+a.topLeft,s+a.topLeft,a.topLeft,1.5*C,C,!0),t.lineTo(i,s+o-a.bottomLeft),t.arc(i+a.bottomLeft,s+o-a.bottomLeft,a.bottomLeft,C,E,!0),t.lineTo(i+n-a.bottomRight,s+o),t.arc(i+n-a.bottomRight,s+o-a.bottomRight,a.bottomRight,E,0,!0),t.lineTo(i+n,s+a.topRight),t.arc(i+n-a.topRight,s+a.topRight,a.topRight,0,-E,!0),t.lineTo(i+a.topLeft,s)}function je(t,e=[""],i,s,n=(()=>t[0])){const o=i||t;void 0===s&&(s=ti("_fallback",t));const a={[Symbol.toStringTag]:"Object",_cacheable:!0,_scopes:t,_rootScopes:o,_fallback:s,_getTarget:n,override:i=>je([i,...t],e,o,s)};return new Proxy(a,{deleteProperty:(e,i)=>(delete e[i],delete e._keys,delete t[0][i],!0),get:(i,s)=>qe(i,s,(()=>function(t,e,i,s){let n;for(const o of e)if(n=ti(Ue(o,t),i),void 0!==n)return Xe(t,n)?Ze(i,s,t,n):n}(s,e,t,i))),getOwnPropertyDescriptor:(t,e)=>Reflect.getOwnPropertyDescriptor(t._scopes[0],e),getPrototypeOf:()=>Reflect.getPrototypeOf(t[0]),has:(t,e)=>ei(t).includes(e),ownKeys:t=>ei(t),set(t,e,i){const s=t._storage||(t._storage=n());return t[e]=s[e]=i,delete t._keys,!0}})}function $e(t,e,i,s){const a={_cacheable:!1,_proxy:t,_context:e,_subProxy:i,_stack:new Set,_descriptors:Ye(t,s),setContext:e=>$e(t,e,i,s),override:n=>$e(t.override(n),e,i,s)};return new Proxy(a,{deleteProperty:(e,i)=>(delete e[i],delete t[i],!0),get:(t,e,i)=>qe(t,e,(()=>function(t,e,i){const{_proxy:s,_context:a,_subProxy:r,_descriptors:l}=t;let h=s[e];S(h)&&l.isScriptable(e)&&(h=function(t,e,i,s){const{_proxy:n,_context:o,_subProxy:a,_stack:r}=i;if(r.has(t))throw new Error("Recursion detected: "+Array.from(r).join("->")+"->"+t);r.add(t);let l=e(o,a||s);r.delete(t),Xe(t,l)&&(l=Ze(n._scopes,n,t,l));return l}(e,h,t,i));n(h)&&h.length&&(h=function(t,e,i,s){const{_proxy:n,_context:a,_subProxy:r,_descriptors:l}=i;if(void 0!==a.index&&s(t))return e[a.index%e.length];if(o(e[0])){const i=e,s=n._scopes.filter((t=>t!==i));e=[];for(const o of i){const i=Ze(s,n,t,o);e.push($e(i,a,r&&r[t],l))}}return e}(e,h,t,l.isIndexable));Xe(e,h)&&(h=$e(h,a,r&&r[e],l));return h}(t,e,i))),getOwnPropertyDescriptor:(e,i)=>e._descriptors.allKeys?Reflect.has(t,i)?{enumerable:!0,configurable:!0}:void 0:Reflect.getOwnPropertyDescriptor(t,i),getPrototypeOf:()=>Reflect.getPrototypeOf(t),has:(e,i)=>Reflect.has(t,i),ownKeys:()=>Reflect.ownKeys(t),set:(e,i,s)=>(t[i]=s,delete e[i],!0)})}function Ye(t,e={scriptable:!0,indexable:!0}){const{_scriptable:i=e.scriptable,_indexable:s=e.indexable,_allKeys:n=e.allKeys}=t;return{allKeys:n,scriptable:i,indexable:s,isScriptable:S(i)?i:()=>i,isIndexable:S(s)?s:()=>s}}const Ue=(t,e)=>t?t+w(e):e,Xe=(t,e)=>o(e)&&"adapters"!==t&&(null===Object.getPrototypeOf(e)||e.constructor===Object);function qe(t,e,i){if(Object.prototype.hasOwnProperty.call(t,e)||"constructor"===e)return t[e];const s=i();return t[e]=s,s}function Ke(t,e,i){return S(t)?t(e,i):t}const Ge=(t,e)=>!0===t?e:"string"==typeof t?M(e,t):void 0;function Je(t,e,i,s,n){for(const o of e){const e=Ge(i,o);if(e){t.add(e);const o=Ke(e._fallback,i,n);if(void 0!==o&&o!==i&&o!==s)return o}else if(!1===e&&void 0!==s&&i!==s)return null}return!1}function Ze(t,e,i,s){const a=e._rootScopes,r=Ke(e._fallback,i,s),l=[...t,...a],h=new Set;h.add(s);let c=Qe(h,l,i,r||i,s);return null!==c&&((void 0===r||r===i||(c=Qe(h,l,r,c,s),null!==c))&&je(Array.from(h),[""],a,r,(()=>function(t,e,i){const s=t._getTarget();e in s||(s[e]={});const a=s[e];if(n(a)&&o(i))return i;return a||{}}(e,i,s))))}function Qe(t,e,i,s,n){for(;i;)i=Je(t,e,i,s,n);return i}function ti(t,e){for(const i of e){if(!i)continue;const e=i[t];if(void 0!==e)return e}}function ei(t){let e=t._keys;return e||(e=t._keys=function(t){const e=new Set;for(const i of t)for(const t of Object.keys(i).filter((t=>!t.startsWith("_"))))e.add(t);return Array.from(e)}(t._scopes)),e}function ii(t,e,i,s){const{iScale:n}=t,{key:o="r"}=this._parsing,a=new Array(s);let r,l,h,c;for(r=0,l=s;r<l;++r)h=r+i,c=e[h],a[r]={r:n.parse(M(c,o),h)};return a}const si=Number.EPSILON||1e-14,ni=(t,e)=>e<t.length&&!t[e].skip&&t[e],oi=t=>"x"===t?"y":"x";function ai(t,e,i,s){const n=t.skip?e:t,o=e,a=i.skip?e:i,r=q(o,n),l=q(a,o);let h=r/(r+l),c=l/(r+l);h=isNaN(h)?0:h,c=isNaN(c)?0:c;const d=s*h,u=s*c;return{previous:{x:o.x-d*(a.x-n.x),y:o.y-d*(a.y-n.y)},next:{x:o.x+u*(a.x-n.x),y:o.y+u*(a.y-n.y)}}}function ri(t,e="x"){const i=oi(e),s=t.length,n=Array(s).fill(0),o=Array(s);let a,r,l,h=ni(t,0);for(a=0;a<s;++a)if(r=l,l=h,h=ni(t,a+1),l){if(h){const t=h[e]-l[e];n[a]=0!==t?(h[i]-l[i])/t:0}o[a]=r?h?F(n[a-1])!==F(n[a])?0:(n[a-1]+n[a])/2:n[a-1]:n[a]}!function(t,e,i){const s=t.length;let n,o,a,r,l,h=ni(t,0);for(let c=0;c<s-1;++c)l=h,h=ni(t,c+1),l&&h&&(V(e[c],0,si)?i[c]=i[c+1]=0:(n=i[c]/e[c],o=i[c+1]/e[c],r=Math.pow(n,2)+Math.pow(o,2),r<=9||(a=3/Math.sqrt(r),i[c]=n*a*e[c],i[c+1]=o*a*e[c])))}(t,n,o),function(t,e,i="x"){const s=oi(i),n=t.length;let o,a,r,l=ni(t,0);for(let h=0;h<n;++h){if(a=r,r=l,l=ni(t,h+1),!r)continue;const n=r[i],c=r[s];a&&(o=(n-a[i])/3,r[`cp1${i}`]=n-o,r[`cp1${s}`]=c-o*e[h]),l&&(o=(l[i]-n)/3,r[`cp2${i}`]=n+o,r[`cp2${s}`]=c+o*e[h])}}(t,o,e)}function li(t,e,i){return Math.max(Math.min(t,i),e)}function hi(t,e,i,s,n){let o,a,r,l;if(e.spanGaps&&(t=t.filter((t=>!t.skip))),"monotone"===e.cubicInterpolationMode)ri(t,n);else{let i=s?t[t.length-1]:t[0];for(o=0,a=t.length;o<a;++o)r=t[o],l=ai(i,r,t[Math.min(o+1,a-(s?0:1))%a],e.tension),r.cp1x=l.previous.x,r.cp1y=l.previous.y,r.cp2x=l.next.x,r.cp2y=l.next.y,i=r}e.capBezierPoints&&function(t,e){let i,s,n,o,a,r=Re(t[0],e);for(i=0,s=t.length;i<s;++i)a=o,o=r,r=i<s-1&&Re(t[i+1],e),o&&(n=t[i],a&&(n.cp1x=li(n.cp1x,e.left,e.right),n.cp1y=li(n.cp1y,e.top,e.bottom)),r&&(n.cp2x=li(n.cp2x,e.left,e.right),n.cp2y=li(n.cp2y,e.top,e.bottom)))}(t,i)}const ci=t=>0===t||1===t,di=(t,e,i)=>-Math.pow(2,10*(t-=1))*Math.sin((t-e)*O/i),ui=(t,e,i)=>Math.pow(2,-10*t)*Math.sin((t-e)*O/i)+1,fi={linear:t=>t,easeInQuad:t=>t*t,easeOutQuad:t=>-t*(t-2),easeInOutQuad:t=>(t/=.5)<1?.5*t*t:-.5*(--t*(t-2)-1),easeInCubic:t=>t*t*t,easeOutCubic:t=>(t-=1)*t*t+1,easeInOutCubic:t=>(t/=.5)<1?.5*t*t*t:.5*((t-=2)*t*t+2),easeInQuart:t=>t*t*t*t,easeOutQuart:t=>-((t-=1)*t*t*t-1),easeInOutQuart:t=>(t/=.5)<1?.5*t*t*t*t:-.5*((t-=2)*t*t*t-2),easeInQuint:t=>t*t*t*t*t,easeOutQuint:t=>(t-=1)*t*t*t*t+1,easeInOutQuint:t=>(t/=.5)<1?.5*t*t*t*t*t:.5*((t-=2)*t*t*t*t+2),easeInSine:t=>1-Math.cos(t*E),easeOutSine:t=>Math.sin(t*E),easeInOutSine:t=>-.5*(Math.cos(C*t)-1),easeInExpo:t=>0===t?0:Math.pow(2,10*(t-1)),easeOutExpo:t=>1===t?1:1-Math.pow(2,-10*t),easeInOutExpo:t=>ci(t)?t:t<.5?.5*Math.pow(2,10*(2*t-1)):.5*(2-Math.pow(2,-10*(2*t-1))),easeInCirc:t=>t>=1?t:-(Math.sqrt(1-t*t)-1),easeOutCirc:t=>Math.sqrt(1-(t-=1)*t),easeInOutCirc:t=>(t/=.5)<1?-.5*(Math.sqrt(1-t*t)-1):.5*(Math.sqrt(1-(t-=2)*t)+1),easeInElastic:t=>ci(t)?t:di(t,.075,.3),easeOutElastic:t=>ci(t)?t:ui(t,.075,.3),easeInOutElastic(t){const e=.1125;return ci(t)?t:t<.5?.5*di(2*t,e,.45):.5+.5*ui(2*t-1,e,.45)},easeInBack(t){const e=1.70158;return t*t*((e+1)*t-e)},easeOutBack(t){const e=1.70158;return(t-=1)*t*((e+1)*t+e)+1},easeInOutBack(t){let e=1.70158;return(t/=.5)<1?t*t*((1+(e*=1.525))*t-e)*.5:.5*((t-=2)*t*((1+(e*=1.525))*t+e)+2)},easeInBounce:t=>1-fi.easeOutBounce(1-t),easeOutBounce(t){const e=7.5625,i=2.75;return t<1/i?e*t*t:t<2/i?e*(t-=1.5/i)*t+.75:t<2.5/i?e*(t-=2.25/i)*t+.9375:e*(t-=2.625/i)*t+.984375},easeInOutBounce:t=>t<.5?.5*fi.easeInBounce(2*t):.5*fi.easeOutBounce(2*t-1)+.5};function gi(t,e,i,s){return{x:t.x+i*(e.x-t.x),y:t.y+i*(e.y-t.y)}}function pi(t,e,i,s){return{x:t.x+i*(e.x-t.x),y:"middle"===s?i<.5?t.y:e.y:"after"===s?i<1?t.y:e.y:i>0?e.y:t.y}}function mi(t,e,i,s){const n={x:t.cp2x,y:t.cp2y},o={x:e.cp1x,y:e.cp1y},a=gi(t,n,i),r=gi(n,o,i),l=gi(o,e,i),h=gi(a,r,i),c=gi(r,l,i);return gi(h,c,i)}const xi=/^(normal|(\d+(?:\.\d+)?)(px|em|%)?)$/,bi=/^(normal|italic|initial|inherit|unset|(oblique( -?[0-9]?[0-9]deg)?))$/;function _i(t,e){const i=(""+t).match(xi);if(!i||"normal"===i[1])return 1.2*e;switch(t=+i[2],i[3]){case"px":return t;case"%":t/=100}return e*t}const yi=t=>+t||0;function vi(t,e){const i={},s=o(e),n=s?Object.keys(e):e,a=o(t)?s?i=>l(t[i],t[e[i]]):e=>t[e]:()=>t;for(const t of n)i[t]=yi(a(t));return i}function Mi(t){return vi(t,{top:"y",right:"x",bottom:"y",left:"x"})}function wi(t){return vi(t,["topLeft","topRight","bottomLeft","bottomRight"])}function ki(t){const e=Mi(t);return e.width=e.left+e.right,e.height=e.top+e.bottom,e}function Si(t,e){t=t||{},e=e||ue.font;let i=l(t.size,e.size);"string"==typeof i&&(i=parseInt(i,10));let s=l(t.style,e.style);s&&!(""+s).match(bi)&&(console.warn('Invalid font style specified: "'+s+'"'),s=void 0);const n={family:l(t.family,e.family),lineHeight:_i(l(t.lineHeight,e.lineHeight),i),size:i,style:s,weight:l(t.weight,e.weight),string:""};return n.string=De(n),n}function Pi(t,e,i,s){let o,a,r,l=!0;for(o=0,a=t.length;o<a;++o)if(r=t[o],void 0!==r&&(void 0!==e&&"function"==typeof r&&(r=r(e),l=!1),void 0!==i&&n(r)&&(r=r[i%r.length],l=!1),void 0!==r))return s&&!l&&(s.cacheable=!1),r}function Di(t,e,i){const{min:s,max:n}=t,o=c(e,(n-s)/2),a=(t,e)=>i&&0===t?0:t+e;return{min:a(s,-Math.abs(o)),max:a(n,o)}}function Ci(t,e){return Object.assign(Object.create(t),e)}function Oi(t,e,i){return t?function(t,e){return{x:i=>t+t+e-i,setWidth(t){e=t},textAlign:t=>"center"===t?t:"right"===t?"left":"right",xPlus:(t,e)=>t-e,leftForLtr:(t,e)=>t-e}}(e,i):{x:t=>t,setWidth(t){},textAlign:t=>t,xPlus:(t,e)=>t+e,leftForLtr:(t,e)=>t}}function Ai(t,e){let i,s;"ltr"!==e&&"rtl"!==e||(i=t.canvas.style,s=[i.getPropertyValue("direction"),i.getPropertyPriority("direction")],i.setProperty("direction",e,"important"),t.prevTextDirection=s)}function Ti(t,e){void 0!==e&&(delete t.prevTextDirection,t.canvas.style.setProperty("direction",e[0],e[1]))}function Li(t){return"angle"===t?{between:J,compare:K,normalize:G}:{between:tt,compare:(t,e)=>t-e,normalize:t=>t}}function Ei({start:t,end:e,count:i,loop:s,style:n}){return{start:t%i,end:e%i,loop:s&&(e-t+1)%i==0,style:n}}function Ri(t,e,i){if(!i)return[t];const{property:s,start:n,end:o}=i,a=e.length,{compare:r,between:l,normalize:h}=Li(s),{start:c,end:d,loop:u,style:f}=function(t,e,i){const{property:s,start:n,end:o}=i,{between:a,normalize:r}=Li(s),l=e.length;let h,c,{start:d,end:u,loop:f}=t;if(f){for(d+=l,u+=l,h=0,c=l;h<c&&a(r(e[d%l][s]),n,o);++h)d--,u--;d%=l,u%=l}return u<d&&(u+=l),{start:d,end:u,loop:f,style:t.style}}(t,e,i),g=[];let p,m,x,b=!1,_=null;const y=()=>b||l(n,x,p)&&0!==r(n,x),v=()=>!b||0===r(o,p)||l(o,x,p);for(let t=c,i=c;t<=d;++t)m=e[t%a],m.skip||(p=h(m[s]),p!==x&&(b=l(p,n,o),null===_&&y()&&(_=0===r(p,n)?t:i),null!==_&&v()&&(g.push(Ei({start:_,end:t,loop:u,count:a,style:f})),_=null),i=t,x=p));return null!==_&&g.push(Ei({start:_,end:d,loop:u,count:a,style:f})),g}function Ii(t,e){const i=[],s=t.segments;for(let n=0;n<s.length;n++){const o=Ri(s[n],t.points,e);o.length&&i.push(...o)}return i}function zi(t,e){const i=t.points,s=t.options.spanGaps,n=i.length;if(!n)return[];const o=!!t._loop,{start:a,end:r}=function(t,e,i,s){let n=0,o=e-1;if(i&&!s)for(;n<e&&!t[n].skip;)n++;for(;n<e&&t[n].skip;)n++;for(n%=e,i&&(o+=n);o>n&&t[o%e].skip;)o--;return o%=e,{start:n,end:o}}(i,n,o,s);if(!0===s)return Fi(t,[{start:a,end:r,loop:o}],i,e);return Fi(t,function(t,e,i,s){const n=t.length,o=[];let a,r=e,l=t[e];for(a=e+1;a<=i;++a){const i=t[a%n];i.skip||i.stop?l.skip||(s=!1,o.push({start:e%n,end:(a-1)%n,loop:s}),e=r=i.stop?a:null):(r=a,l.skip&&(e=a)),l=i}return null!==r&&o.push({start:e%n,end:r%n,loop:s}),o}(i,a,r<a?r+n:r,!!t._fullLoop&&0===a&&r===n-1),i,e)}function Fi(t,e,i,s){return s&&s.setContext&&i?function(t,e,i,s){const n=t._chart.getContext(),o=Vi(t.options),{_datasetIndex:a,options:{spanGaps:r}}=t,l=i.length,h=[];let c=o,d=e[0].start,u=d;function f(t,e,s,n){const o=r?-1:1;if(t!==e){for(t+=l;i[t%l].skip;)t-=o;for(;i[e%l].skip;)e+=o;t%l!=e%l&&(h.push({start:t%l,end:e%l,loop:s,style:n}),c=n,d=e%l)}}for(const t of e){d=r?d:t.start;let e,o=i[d%l];for(u=d+1;u<=t.end;u++){const r=i[u%l];e=Vi(s.setContext(Ci(n,{type:"segment",p0:o,p1:r,p0DataIndex:(u-1)%l,p1DataIndex:u%l,datasetIndex:a}))),Bi(e,c)&&f(d,u-1,t.loop,c),o=r,c=e}d<u-1&&f(d,u-1,t.loop,c)}return h}(t,e,i,s):e}function Vi(t){return{backgroundColor:t.backgroundColor,borderCapStyle:t.borderCapStyle,borderDash:t.borderDash,borderDashOffset:t.borderDashOffset,borderJoinStyle:t.borderJoinStyle,borderWidth:t.borderWidth,borderColor:t.borderColor}}function Bi(t,e){if(!e)return!1;const i=[],s=function(t,e){return Zt(e)?(i.includes(e)||i.push(e),i.indexOf(e)):e};return JSON.stringify(t,s)!==JSON.stringify(e,s)}function Wi(t,e,i){return t.options.clip?t[i]:e[i]}function Ni(t,e){const i=e._clip;if(i.disabled)return!1;const s=function(t,e){const{xScale:i,yScale:s}=t;return i&&s?{left:Wi(i,e,"left"),right:Wi(i,e,"right"),top:Wi(s,e,"top"),bottom:Wi(s,e,"bottom")}:e}(e,t.chartArea);return{left:!1===i.left?0:s.left-(!0===i.left?0:i.left),right:!1===i.right?t.width:s.right+(!0===i.right?0:i.right),top:!1===i.top?0:s.top-(!0===i.top?0:i.top),bottom:!1===i.bottom?t.height:s.bottom+(!0===i.bottom?0:i.bottom)}}var Hi=Object.freeze({__proto__:null,HALF_PI:E,INFINITY:T,PI:C,PITAU:A,QUARTER_PI:R,RAD_PER_DEG:L,TAU:O,TWO_THIRDS_PI:I,_addGrace:Di,_alignPixel:Ae,_alignStartEnd:ft,_angleBetween:J,_angleDiff:K,_arrayUnique:lt,_attachContext:$e,_bezierCurveTo:Ve,_bezierInterpolation:mi,_boundSegment:Ri,_boundSegments:Ii,_capitalize:w,_computeSegments:zi,_createResolver:je,_decimalPlaces:U,_deprecated:function(t,e,i,s){void 0!==e&&console.warn(t+': "'+i+'" is deprecated. Please use "'+s+'" instead')},_descriptors:Ye,_elementsEqual:f,_factorize:W,_filterBetween:nt,_getParentNode:ge,_getStartAndCountOfVisiblePoints:pt,_int16Range:Q,_isBetween:tt,_isClickEvent:D,_isDomSupported:fe,_isPointInArea:Re,_limitValue:Z,_longestText:Oe,_lookup:et,_lookupByKey:it,_measureText:Ce,_merger:m,_mergerIf:_,_normalizeAngle:G,_parseObjectDataRadialScale:ii,_pointInLine:gi,_readValueToProps:vi,_rlookupByKey:st,_scaleRangesChanged:mt,_setMinAndMaxByKey:j,_splitKey:v,_steppedInterpolation:pi,_steppedLineTo:Fe,_textX:gt,_toLeftRightCenter:ut,_updateBezierControlPoints:hi,addRoundedRectPath:He,almostEquals:V,almostWhole:H,callback:d,clearCanvas:Te,clipArea:Ie,clone:g,color:Qt,createContext:Ci,debounce:dt,defined:k,distanceBetweenPoints:q,drawPoint:Le,drawPointLegend:Ee,each:u,easingEffects:fi,finiteOrDefault:r,fontString:function(t,e,i){return e+" "+t+"px "+i},formatNumber:ne,getAngleFromPoint:X,getDatasetClipArea:Ni,getHoverColor:te,getMaximumSize:we,getRelativePosition:ve,getRtlAdapter:Oi,getStyle:xe,isArray:n,isFinite:a,isFunction:S,isNullOrUndef:s,isNumber:N,isObject:o,isPatternOrGradient:Zt,listenArrayEvents:at,log10:z,merge:x,mergeIf:b,niceNum:B,noop:e,overrideTextDirection:Ai,readUsedSize:Pe,renderText:Ne,requestAnimFrame:ht,resolve:Pi,resolveObjectKey:M,restoreTextDirection:Ti,retinaScale:ke,setsEqual:P,sign:F,splineCurve:ai,splineCurveMonotone:ri,supportsEventListenerOptions:Se,throttled:ct,toDegrees:Y,toDimension:c,toFont:Si,toFontString:De,toLineHeight:_i,toPadding:ki,toPercentage:h,toRadians:$,toTRBL:Mi,toTRBLCorners:wi,uid:i,unclipArea:ze,unlistenArrayEvents:rt,valueOrDefault:l});function ji(t,e,i,n){const{controller:o,data:a,_sorted:r}=t,l=o._cachedMeta.iScale,h=t.dataset&&t.dataset.options?t.dataset.options.spanGaps:null;if(l&&e===l.axis&&"r"!==e&&r&&a.length){const r=l._reversePixels?st:it;if(!n){const n=r(a,e,i);if(h){const{vScale:e}=o._cachedMeta,{_parsed:i}=t,a=i.slice(0,n.lo+1).reverse().findIndex((t=>!s(t[e.axis])));n.lo-=Math.max(0,a);const r=i.slice(n.hi).findIndex((t=>!s(t[e.axis])));n.hi+=Math.max(0,r)}return n}if(o._sharedOptions){const t=a[0],s="function"==typeof t.getRange&&t.getRange(e);if(s){const t=r(a,e,i-s),n=r(a,e,i+s);return{lo:t.lo,hi:n.hi}}}}return{lo:0,hi:a.length-1}}function $i(t,e,i,s,n){const o=t.getSortedVisibleDatasetMetas(),a=i[e];for(let t=0,i=o.length;t<i;++t){const{index:i,data:r}=o[t],{lo:l,hi:h}=ji(o[t],e,a,n);for(let t=l;t<=h;++t){const e=r[t];e.skip||s(e,i,t)}}}function Yi(t,e,i,s,n){const o=[];if(!n&&!t.isPointInArea(e))return o;return $i(t,i,e,(function(i,a,r){(n||Re(i,t.chartArea,0))&&i.inRange(e.x,e.y,s)&&o.push({element:i,datasetIndex:a,index:r})}),!0),o}function Ui(t,e,i,s,n,o){let a=[];const r=function(t){const e=-1!==t.indexOf("x"),i=-1!==t.indexOf("y");return function(t,s){const n=e?Math.abs(t.x-s.x):0,o=i?Math.abs(t.y-s.y):0;return Math.sqrt(Math.pow(n,2)+Math.pow(o,2))}}(i);let l=Number.POSITIVE_INFINITY;return $i(t,i,e,(function(i,h,c){const d=i.inRange(e.x,e.y,n);if(s&&!d)return;const u=i.getCenterPoint(n);if(!(!!o||t.isPointInArea(u))&&!d)return;const f=r(e,u);f<l?(a=[{element:i,datasetIndex:h,index:c}],l=f):f===l&&a.push({element:i,datasetIndex:h,index:c})})),a}function Xi(t,e,i,s,n,o){return o||t.isPointInArea(e)?"r"!==i||s?Ui(t,e,i,s,n,o):function(t,e,i,s){let n=[];return $i(t,i,e,(function(t,i,o){const{startAngle:a,endAngle:r}=t.getProps(["startAngle","endAngle"],s),{angle:l}=X(t,{x:e.x,y:e.y});J(l,a,r)&&n.push({element:t,datasetIndex:i,index:o})})),n}(t,e,i,n):[]}function qi(t,e,i,s,n){const o=[],a="x"===i?"inXRange":"inYRange";let r=!1;return $i(t,i,e,((t,s,l)=>{t[a]&&t[a](e[i],n)&&(o.push({element:t,datasetIndex:s,index:l}),r=r||t.inRange(e.x,e.y,n))})),s&&!r?[]:o}var Ki={evaluateInteractionItems:$i,modes:{index(t,e,i,s){const n=ve(e,t),o=i.axis||"x",a=i.includeInvisible||!1,r=i.intersect?Yi(t,n,o,s,a):Xi(t,n,o,!1,s,a),l=[];return r.length?(t.getSortedVisibleDatasetMetas().forEach((t=>{const e=r[0].index,i=t.data[e];i&&!i.skip&&l.push({element:i,datasetIndex:t.index,index:e})})),l):[]},dataset(t,e,i,s){const n=ve(e,t),o=i.axis||"xy",a=i.includeInvisible||!1;let r=i.intersect?Yi(t,n,o,s,a):Xi(t,n,o,!1,s,a);if(r.length>0){const e=r[0].datasetIndex,i=t.getDatasetMeta(e).data;r=[];for(let t=0;t<i.length;++t)r.push({element:i[t],datasetIndex:e,index:t})}return r},point:(t,e,i,s)=>Yi(t,ve(e,t),i.axis||"xy",s,i.includeInvisible||!1),nearest(t,e,i,s){const n=ve(e,t),o=i.axis||"xy",a=i.includeInvisible||!1;return Xi(t,n,o,i.intersect,s,a)},x:(t,e,i,s)=>qi(t,ve(e,t),"x",i.intersect,s),y:(t,e,i,s)=>qi(t,ve(e,t),"y",i.intersect,s)}};const Gi=["left","top","right","bottom"];function Ji(t,e){return t.filter((t=>t.pos===e))}function Zi(t,e){return t.filter((t=>-1===Gi.indexOf(t.pos)&&t.box.axis===e))}function Qi(t,e){return t.sort(((t,i)=>{const s=e?i:t,n=e?t:i;return s.weight===n.weight?s.index-n.index:s.weight-n.weight}))}function ts(t,e){const i=function(t){const e={};for(const i of t){const{stack:t,pos:s,stackWeight:n}=i;if(!t||!Gi.includes(s))continue;const o=e[t]||(e[t]={count:0,placed:0,weight:0,size:0});o.count++,o.weight+=n}return e}(t),{vBoxMaxWidth:s,hBoxMaxHeight:n}=e;let o,a,r;for(o=0,a=t.length;o<a;++o){r=t[o];const{fullSize:a}=r.box,l=i[r.stack],h=l&&r.stackWeight/l.weight;r.horizontal?(r.width=h?h*s:a&&e.availableWidth,r.height=n):(r.width=s,r.height=h?h*n:a&&e.availableHeight)}return i}function es(t,e,i,s){return Math.max(t[i],e[i])+Math.max(t[s],e[s])}function is(t,e){t.top=Math.max(t.top,e.top),t.left=Math.max(t.left,e.left),t.bottom=Math.max(t.bottom,e.bottom),t.right=Math.max(t.right,e.right)}function ss(t,e,i,s){const{pos:n,box:a}=i,r=t.maxPadding;if(!o(n)){i.size&&(t[n]-=i.size);const e=s[i.stack]||{size:0,count:1};e.size=Math.max(e.size,i.horizontal?a.height:a.width),i.size=e.size/e.count,t[n]+=i.size}a.getPadding&&is(r,a.getPadding());const l=Math.max(0,e.outerWidth-es(r,t,"left","right")),h=Math.max(0,e.outerHeight-es(r,t,"top","bottom")),c=l!==t.w,d=h!==t.h;return t.w=l,t.h=h,i.horizontal?{same:c,other:d}:{same:d,other:c}}function ns(t,e){const i=e.maxPadding;function s(t){const s={left:0,top:0,right:0,bottom:0};return t.forEach((t=>{s[t]=Math.max(e[t],i[t])})),s}return s(t?["left","right"]:["top","bottom"])}function os(t,e,i,s){const n=[];let o,a,r,l,h,c;for(o=0,a=t.length,h=0;o<a;++o){r=t[o],l=r.box,l.update(r.width||e.w,r.height||e.h,ns(r.horizontal,e));const{same:a,other:d}=ss(e,i,r,s);h|=a&&n.length,c=c||d,l.fullSize||n.push(r)}return h&&os(n,e,i,s)||c}function as(t,e,i,s,n){t.top=i,t.left=e,t.right=e+s,t.bottom=i+n,t.width=s,t.height=n}function rs(t,e,i,s){const n=i.padding;let{x:o,y:a}=e;for(const r of t){const t=r.box,l=s[r.stack]||{count:1,placed:0,weight:1},h=r.stackWeight/l.weight||1;if(r.horizontal){const s=e.w*h,o=l.size||t.height;k(l.start)&&(a=l.start),t.fullSize?as(t,n.left,a,i.outerWidth-n.right-n.left,o):as(t,e.left+l.placed,a,s,o),l.start=a,l.placed+=s,a=t.bottom}else{const s=e.h*h,a=l.size||t.width;k(l.start)&&(o=l.start),t.fullSize?as(t,o,n.top,a,i.outerHeight-n.bottom-n.top):as(t,o,e.top+l.placed,a,s),l.start=o,l.placed+=s,o=t.right}}e.x=o,e.y=a}var ls={addBox(t,e){t.boxes||(t.boxes=[]),e.fullSize=e.fullSize||!1,e.position=e.position||"top",e.weight=e.weight||0,e._layers=e._layers||function(){return[{z:0,draw(t){e.draw(t)}}]},t.boxes.push(e)},removeBox(t,e){const i=t.boxes?t.boxes.indexOf(e):-1;-1!==i&&t.boxes.splice(i,1)},configure(t,e,i){e.fullSize=i.fullSize,e.position=i.position,e.weight=i.weight},update(t,e,i,s){if(!t)return;const n=ki(t.options.layout.padding),o=Math.max(e-n.width,0),a=Math.max(i-n.height,0),r=function(t){const e=function(t){const e=[];let i,s,n,o,a,r;for(i=0,s=(t||[]).length;i<s;++i)n=t[i],({position:o,options:{stack:a,stackWeight:r=1}}=n),e.push({index:i,box:n,pos:o,horizontal:n.isHorizontal(),weight:n.weight,stack:a&&o+a,stackWeight:r});return e}(t),i=Qi(e.filter((t=>t.box.fullSize)),!0),s=Qi(Ji(e,"left"),!0),n=Qi(Ji(e,"right")),o=Qi(Ji(e,"top"),!0),a=Qi(Ji(e,"bottom")),r=Zi(e,"x"),l=Zi(e,"y");return{fullSize:i,leftAndTop:s.concat(o),rightAndBottom:n.concat(l).concat(a).concat(r),chartArea:Ji(e,"chartArea"),vertical:s.concat(n).concat(l),horizontal:o.concat(a).concat(r)}}(t.boxes),l=r.vertical,h=r.horizontal;u(t.boxes,(t=>{"function"==typeof t.beforeLayout&&t.beforeLayout()}));const c=l.reduce(((t,e)=>e.box.options&&!1===e.box.options.display?t:t+1),0)||1,d=Object.freeze({outerWidth:e,outerHeight:i,padding:n,availableWidth:o,availableHeight:a,vBoxMaxWidth:o/2/c,hBoxMaxHeight:a/2}),f=Object.assign({},n);is(f,ki(s));const g=Object.assign({maxPadding:f,w:o,h:a,x:n.left,y:n.top},n),p=ts(l.concat(h),d);os(r.fullSize,g,d,p),os(l,g,d,p),os(h,g,d,p)&&os(l,g,d,p),function(t){const e=t.maxPadding;function i(i){const s=Math.max(e[i]-t[i],0);return t[i]+=s,s}t.y+=i("top"),t.x+=i("left"),i("right"),i("bottom")}(g),rs(r.leftAndTop,g,d,p),g.x+=g.w,g.y+=g.h,rs(r.rightAndBottom,g,d,p),t.chartArea={left:g.left,top:g.top,right:g.left+g.w,bottom:g.top+g.h,height:g.h,width:g.w},u(r.chartArea,(e=>{const i=e.box;Object.assign(i,t.chartArea),i.update(g.w,g.h,{left:0,top:0,right:0,bottom:0})}))}};class hs{acquireContext(t,e){}releaseContext(t){return!1}addEventListener(t,e,i){}removeEventListener(t,e,i){}getDevicePixelRatio(){return 1}getMaximumSize(t,e,i,s){return e=Math.max(0,e||t.width),i=i||t.height,{width:e,height:Math.max(0,s?Math.floor(e/s):i)}}isAttached(t){return!0}updateConfig(t){}}class cs extends hs{acquireContext(t){return t&&t.getContext&&t.getContext("2d")||null}updateConfig(t){t.options.animation=!1}}const ds="$chartjs",us={touchstart:"mousedown",touchmove:"mousemove",touchend:"mouseup",pointerenter:"mouseenter",pointerdown:"mousedown",pointermove:"mousemove",pointerup:"mouseup",pointerleave:"mouseout",pointerout:"mouseout"},fs=t=>null===t||""===t;const gs=!!Se&&{passive:!0};function ps(t,e,i){t&&t.canvas&&t.canvas.removeEventListener(e,i,gs)}function ms(t,e){for(const i of t)if(i===e||i.contains(e))return!0}function xs(t,e,i){const s=t.canvas,n=new MutationObserver((t=>{let e=!1;for(const i of t)e=e||ms(i.addedNodes,s),e=e&&!ms(i.removedNodes,s);e&&i()}));return n.observe(document,{childList:!0,subtree:!0}),n}function bs(t,e,i){const s=t.canvas,n=new MutationObserver((t=>{let e=!1;for(const i of t)e=e||ms(i.removedNodes,s),e=e&&!ms(i.addedNodes,s);e&&i()}));return n.observe(document,{childList:!0,subtree:!0}),n}const _s=new Map;let ys=0;function vs(){const t=window.devicePixelRatio;t!==ys&&(ys=t,_s.forEach(((e,i)=>{i.currentDevicePixelRatio!==t&&e()})))}function Ms(t,e,i){const s=t.canvas,n=s&&ge(s);if(!n)return;const o=ct(((t,e)=>{const s=n.clientWidth;i(t,e),s<n.clientWidth&&i()}),window),a=new ResizeObserver((t=>{const e=t[0],i=e.contentRect.width,s=e.contentRect.height;0===i&&0===s||o(i,s)}));return a.observe(n),function(t,e){_s.size||window.addEventListener("resize",vs),_s.set(t,e)}(t,o),a}function ws(t,e,i){i&&i.disconnect(),"resize"===e&&function(t){_s.delete(t),_s.size||window.removeEventListener("resize",vs)}(t)}function ks(t,e,i){const s=t.canvas,n=ct((e=>{null!==t.ctx&&i(function(t,e){const i=us[t.type]||t.type,{x:s,y:n}=ve(t,e);return{type:i,chart:e,native:t,x:void 0!==s?s:null,y:void 0!==n?n:null}}(e,t))}),t);return function(t,e,i){t&&t.addEventListener(e,i,gs)}(s,e,n),n}class Ss extends hs{acquireContext(t,e){const i=t&&t.getContext&&t.getContext("2d");return i&&i.canvas===t?(function(t,e){const i=t.style,s=t.getAttribute("height"),n=t.getAttribute("width");if(t[ds]={initial:{height:s,width:n,style:{display:i.display,height:i.height,width:i.width}}},i.display=i.display||"block",i.boxSizing=i.boxSizing||"border-box",fs(n)){const e=Pe(t,"width");void 0!==e&&(t.width=e)}if(fs(s))if(""===t.style.height)t.height=t.width/(e||2);else{const e=Pe(t,"height");void 0!==e&&(t.height=e)}}(t,e),i):null}releaseContext(t){const e=t.canvas;if(!e[ds])return!1;const i=e[ds].initial;["height","width"].forEach((t=>{const n=i[t];s(n)?e.removeAttribute(t):e.setAttribute(t,n)}));const n=i.style||{};return Object.keys(n).forEach((t=>{e.style[t]=n[t]})),e.width=e.width,delete e[ds],!0}addEventListener(t,e,i){this.removeEventListener(t,e);const s=t.$proxies||(t.$proxies={}),n={attach:xs,detach:bs,resize:Ms}[e]||ks;s[e]=n(t,e,i)}removeEventListener(t,e){const i=t.$proxies||(t.$proxies={}),s=i[e];if(!s)return;({attach:ws,detach:ws,resize:ws}[e]||ps)(t,e,s),i[e]=void 0}getDevicePixelRatio(){return window.devicePixelRatio}getMaximumSize(t,e,i,s){return we(t,e,i,s)}isAttached(t){const e=t&&ge(t);return!(!e||!e.isConnected)}}function Ps(t){return!fe()||"undefined"!=typeof OffscreenCanvas&&t instanceof OffscreenCanvas?cs:Ss}var Ds=Object.freeze({__proto__:null,BasePlatform:hs,BasicPlatform:cs,DomPlatform:Ss,_detectPlatform:Ps});const Cs="transparent",Os={boolean:(t,e,i)=>i>.5?e:t,color(t,e,i){const s=Qt(t||Cs),n=s.valid&&Qt(e||Cs);return n&&n.valid?n.mix(s,i).hexString():e},number:(t,e,i)=>t+(e-t)*i};class As{constructor(t,e,i,s){const n=e[i];s=Pi([t.to,s,n,t.from]);const o=Pi([t.from,n,s]);this._active=!0,this._fn=t.fn||Os[t.type||typeof o],this._easing=fi[t.easing]||fi.linear,this._start=Math.floor(Date.now()+(t.delay||0)),this._duration=this._total=Math.floor(t.duration),this._loop=!!t.loop,this._target=e,this._prop=i,this._from=o,this._to=s,this._promises=void 0}active(){return this._active}update(t,e,i){if(this._active){this._notify(!1);const s=this._target[this._prop],n=i-this._start,o=this._duration-n;this._start=i,this._duration=Math.floor(Math.max(o,t.duration)),this._total+=n,this._loop=!!t.loop,this._to=Pi([t.to,e,s,t.from]),this._from=Pi([t.from,s,e])}}cancel(){this._active&&(this.tick(Date.now()),this._active=!1,this._notify(!1))}tick(t){const e=t-this._start,i=this._duration,s=this._prop,n=this._from,o=this._loop,a=this._to;let r;if(this._active=n!==a&&(o||e<i),!this._active)return this._target[s]=a,void this._notify(!0);e<0?this._target[s]=n:(r=e/i%2,r=o&&r>1?2-r:r,r=this._easing(Math.min(1,Math.max(0,r))),this._target[s]=this._fn(n,a,r))}wait(){const t=this._promises||(this._promises=[]);return new Promise(((e,i)=>{t.push({res:e,rej:i})}))}_notify(t){const e=t?"res":"rej",i=this._promises||[];for(let t=0;t<i.length;t++)i[t][e]()}}class Ts{constructor(t,e){this._chart=t,this._properties=new Map,this.configure(e)}configure(t){if(!o(t))return;const e=Object.keys(ue.animation),i=this._properties;Object.getOwnPropertyNames(t).forEach((s=>{const a=t[s];if(!o(a))return;const r={};for(const t of e)r[t]=a[t];(n(a.properties)&&a.properties||[s]).forEach((t=>{t!==s&&i.has(t)||i.set(t,r)}))}))}_animateOptions(t,e){const i=e.options,s=function(t,e){if(!e)return;let i=t.options;if(!i)return void(t.options=e);i.$shared&&(t.options=i=Object.assign({},i,{$shared:!1,$animations:{}}));return i}(t,i);if(!s)return[];const n=this._createAnimations(s,i);return i.$shared&&function(t,e){const i=[],s=Object.keys(e);for(let e=0;e<s.length;e++){const n=t[s[e]];n&&n.active()&&i.push(n.wait())}return Promise.all(i)}(t.options.$animations,i).then((()=>{t.options=i}),(()=>{})),n}_createAnimations(t,e){const i=this._properties,s=[],n=t.$animations||(t.$animations={}),o=Object.keys(e),a=Date.now();let r;for(r=o.length-1;r>=0;--r){const l=o[r];if("$"===l.charAt(0))continue;if("options"===l){s.push(...this._animateOptions(t,e));continue}const h=e[l];let c=n[l];const d=i.get(l);if(c){if(d&&c.active()){c.update(d,h,a);continue}c.cancel()}d&&d.duration?(n[l]=c=new As(d,t,l,h),s.push(c)):t[l]=h}return s}update(t,e){if(0===this._properties.size)return void Object.assign(t,e);const i=this._createAnimations(t,e);return i.length?(bt.add(this._chart,i),!0):void 0}}function Ls(t,e){const i=t&&t.options||{},s=i.reverse,n=void 0===i.min?e:0,o=void 0===i.max?e:0;return{start:s?o:n,end:s?n:o}}function Es(t,e){const i=[],s=t._getSortedDatasetMetas(e);let n,o;for(n=0,o=s.length;n<o;++n)i.push(s[n].index);return i}function Rs(t,e,i,s={}){const n=t.keys,o="single"===s.mode;let r,l,h,c;if(null===e)return;let d=!1;for(r=0,l=n.length;r<l;++r){if(h=+n[r],h===i){if(d=!0,s.all)continue;break}c=t.values[h],a(c)&&(o||0===e||F(e)===F(c))&&(e+=c)}return d||s.all?e:0}function Is(t,e){const i=t&&t.options.stacked;return i||void 0===i&&void 0!==e.stack}function zs(t,e,i){const s=t[e]||(t[e]={});return s[i]||(s[i]={})}function Fs(t,e,i,s){for(const n of e.getMatchingVisibleMetas(s).reverse()){const e=t[n.index];if(i&&e>0||!i&&e<0)return n.index}return null}function Vs(t,e){const{chart:i,_cachedMeta:s}=t,n=i._stacks||(i._stacks={}),{iScale:o,vScale:a,index:r}=s,l=o.axis,h=a.axis,c=function(t,e,i){return`${t.id}.${e.id}.${i.stack||i.type}`}(o,a,s),d=e.length;let u;for(let t=0;t<d;++t){const i=e[t],{[l]:o,[h]:d}=i;u=(i._stacks||(i._stacks={}))[h]=zs(n,c,o),u[r]=d,u._top=Fs(u,a,!0,s.type),u._bottom=Fs(u,a,!1,s.type);(u._visualValues||(u._visualValues={}))[r]=d}}function Bs(t,e){const i=t.scales;return Object.keys(i).filter((t=>i[t].axis===e)).shift()}function Ws(t,e){const i=t.controller.index,s=t.vScale&&t.vScale.axis;if(s){e=e||t._parsed;for(const t of e){const e=t._stacks;if(!e||void 0===e[s]||void 0===e[s][i])return;delete e[s][i],void 0!==e[s]._visualValues&&void 0!==e[s]._visualValues[i]&&delete e[s]._visualValues[i]}}}const Ns=t=>"reset"===t||"none"===t,Hs=(t,e)=>e?t:Object.assign({},t);class js{static defaults={};static datasetElementType=null;static dataElementType=null;constructor(t,e){this.chart=t,this._ctx=t.ctx,this.index=e,this._cachedDataOpts={},this._cachedMeta=this.getMeta(),this._type=this._cachedMeta.type,this.options=void 0,this._parsing=!1,this._data=void 0,this._objectData=void 0,this._sharedOptions=void 0,this._drawStart=void 0,this._drawCount=void 0,this.enableOptionSharing=!1,this.supportsDecimation=!1,this.$context=void 0,this._syncList=[],this.datasetElementType=new.target.datasetElementType,this.dataElementType=new.target.dataElementType,this.initialize()}initialize(){const t=this._cachedMeta;this.configure(),this.linkScales(),t._stacked=Is(t.vScale,t),this.addElements(),this.options.fill&&!this.chart.isPluginEnabled("filler")&&console.warn("Tried to use the 'fill' option without the 'Filler' plugin enabled. Please import and register the 'Filler' plugin and make sure it is not disabled in the options")}updateIndex(t){this.index!==t&&Ws(this._cachedMeta),this.index=t}linkScales(){const t=this.chart,e=this._cachedMeta,i=this.getDataset(),s=(t,e,i,s)=>"x"===t?e:"r"===t?s:i,n=e.xAxisID=l(i.xAxisID,Bs(t,"x")),o=e.yAxisID=l(i.yAxisID,Bs(t,"y")),a=e.rAxisID=l(i.rAxisID,Bs(t,"r")),r=e.indexAxis,h=e.iAxisID=s(r,n,o,a),c=e.vAxisID=s(r,o,n,a);e.xScale=this.getScaleForId(n),e.yScale=this.getScaleForId(o),e.rScale=this.getScaleForId(a),e.iScale=this.getScaleForId(h),e.vScale=this.getScaleForId(c)}getDataset(){return this.chart.data.datasets[this.index]}getMeta(){return this.chart.getDatasetMeta(this.index)}getScaleForId(t){return this.chart.scales[t]}_getOtherScale(t){const e=this._cachedMeta;return t===e.iScale?e.vScale:e.iScale}reset(){this._update("reset")}_destroy(){const t=this._cachedMeta;this._data&&rt(this._data,this),t._stacked&&Ws(t)}_dataCheck(){const t=this.getDataset(),e=t.data||(t.data=[]),i=this._data;if(o(e)){const t=this._cachedMeta;this._data=function(t,e){const{iScale:i,vScale:s}=e,n="x"===i.axis?"x":"y",o="x"===s.axis?"x":"y",a=Object.keys(t),r=new Array(a.length);let l,h,c;for(l=0,h=a.length;l<h;++l)c=a[l],r[l]={[n]:c,[o]:t[c]};return r}(e,t)}else if(i!==e){if(i){rt(i,this);const t=this._cachedMeta;Ws(t),t._parsed=[]}e&&Object.isExtensible(e)&&at(e,this),this._syncList=[],this._data=e}}addElements(){const t=this._cachedMeta;this._dataCheck(),this.datasetElementType&&(t.dataset=new this.datasetElementType)}buildOrUpdateElements(t){const e=this._cachedMeta,i=this.getDataset();let s=!1;this._dataCheck();const n=e._stacked;e._stacked=Is(e.vScale,e),e.stack!==i.stack&&(s=!0,Ws(e),e.stack=i.stack),this._resyncElements(t),(s||n!==e._stacked)&&(Vs(this,e._parsed),e._stacked=Is(e.vScale,e))}configure(){const t=this.chart.config,e=t.datasetScopeKeys(this._type),i=t.getOptionScopes(this.getDataset(),e,!0);this.options=t.createResolver(i,this.getContext()),this._parsing=this.options.parsing,this._cachedDataOpts={}}parse(t,e){const{_cachedMeta:i,_data:s}=this,{iScale:a,_stacked:r}=i,l=a.axis;let h,c,d,u=0===t&&e===s.length||i._sorted,f=t>0&&i._parsed[t-1];if(!1===this._parsing)i._parsed=s,i._sorted=!0,d=s;else{d=n(s[t])?this.parseArrayData(i,s,t,e):o(s[t])?this.parseObjectData(i,s,t,e):this.parsePrimitiveData(i,s,t,e);const a=()=>null===c[l]||f&&c[l]<f[l];for(h=0;h<e;++h)i._parsed[h+t]=c=d[h],u&&(a()&&(u=!1),f=c);i._sorted=u}r&&Vs(this,d)}parsePrimitiveData(t,e,i,s){const{iScale:n,vScale:o}=t,a=n.axis,r=o.axis,l=n.getLabels(),h=n===o,c=new Array(s);let d,u,f;for(d=0,u=s;d<u;++d)f=d+i,c[d]={[a]:h||n.parse(l[f],f),[r]:o.parse(e[f],f)};return c}parseArrayData(t,e,i,s){const{xScale:n,yScale:o}=t,a=new Array(s);let r,l,h,c;for(r=0,l=s;r<l;++r)h=r+i,c=e[h],a[r]={x:n.parse(c[0],h),y:o.parse(c[1],h)};return a}parseObjectData(t,e,i,s){const{xScale:n,yScale:o}=t,{xAxisKey:a="x",yAxisKey:r="y"}=this._parsing,l=new Array(s);let h,c,d,u;for(h=0,c=s;h<c;++h)d=h+i,u=e[d],l[h]={x:n.parse(M(u,a),d),y:o.parse(M(u,r),d)};return l}getParsed(t){return this._cachedMeta._parsed[t]}getDataElement(t){return this._cachedMeta.data[t]}applyStack(t,e,i){const s=this.chart,n=this._cachedMeta,o=e[t.axis];return Rs({keys:Es(s,!0),values:e._stacks[t.axis]._visualValues},o,n.index,{mode:i})}updateRangeFromParsed(t,e,i,s){const n=i[e.axis];let o=null===n?NaN:n;const a=s&&i._stacks[e.axis];s&&a&&(s.values=a,o=Rs(s,n,this._cachedMeta.index)),t.min=Math.min(t.min,o),t.max=Math.max(t.max,o)}getMinMax(t,e){const i=this._cachedMeta,s=i._parsed,n=i._sorted&&t===i.iScale,o=s.length,r=this._getOtherScale(t),l=((t,e,i)=>t&&!e.hidden&&e._stacked&&{keys:Es(i,!0),values:null})(e,i,this.chart),h={min:Number.POSITIVE_INFINITY,max:Number.NEGATIVE_INFINITY},{min:c,max:d}=function(t){const{min:e,max:i,minDefined:s,maxDefined:n}=t.getUserBounds();return{min:s?e:Number.NEGATIVE_INFINITY,max:n?i:Number.POSITIVE_INFINITY}}(r);let u,f;function g(){f=s[u];const e=f[r.axis];return!a(f[t.axis])||c>e||d<e}for(u=0;u<o&&(g()||(this.updateRangeFromParsed(h,t,f,l),!n));++u);if(n)for(u=o-1;u>=0;--u)if(!g()){this.updateRangeFromParsed(h,t,f,l);break}return h}getAllParsedValues(t){const e=this._cachedMeta._parsed,i=[];let s,n,o;for(s=0,n=e.length;s<n;++s)o=e[s][t.axis],a(o)&&i.push(o);return i}getMaxOverflow(){return!1}getLabelAndValue(t){const e=this._cachedMeta,i=e.iScale,s=e.vScale,n=this.getParsed(t);return{label:i?""+i.getLabelForValue(n[i.axis]):"",value:s?""+s.getLabelForValue(n[s.axis]):""}}_update(t){const e=this._cachedMeta;this.update(t||"default"),e._clip=function(t){let e,i,s,n;return o(t)?(e=t.top,i=t.right,s=t.bottom,n=t.left):e=i=s=n=t,{top:e,right:i,bottom:s,left:n,disabled:!1===t}}(l(this.options.clip,function(t,e,i){if(!1===i)return!1;const s=Ls(t,i),n=Ls(e,i);return{top:n.end,right:s.end,bottom:n.start,left:s.start}}(e.xScale,e.yScale,this.getMaxOverflow())))}update(t){}draw(){const t=this._ctx,e=this.chart,i=this._cachedMeta,s=i.data||[],n=e.chartArea,o=[],a=this._drawStart||0,r=this._drawCount||s.length-a,l=this.options.drawActiveElementsOnTop;let h;for(i.dataset&&i.dataset.draw(t,n,a,r),h=a;h<a+r;++h){const e=s[h];e.hidden||(e.active&&l?o.push(e):e.draw(t,n))}for(h=0;h<o.length;++h)o[h].draw(t,n)}getStyle(t,e){const i=e?"active":"default";return void 0===t&&this._cachedMeta.dataset?this.resolveDatasetElementOptions(i):this.resolveDataElementOptions(t||0,i)}getContext(t,e,i){const s=this.getDataset();let n;if(t>=0&&t<this._cachedMeta.data.length){const e=this._cachedMeta.data[t];n=e.$context||(e.$context=function(t,e,i){return Ci(t,{active:!1,dataIndex:e,parsed:void 0,raw:void 0,element:i,index:e,mode:"default",type:"data"})}(this.getContext(),t,e)),n.parsed=this.getParsed(t),n.raw=s.data[t],n.index=n.dataIndex=t}else n=this.$context||(this.$context=function(t,e){return Ci(t,{active:!1,dataset:void 0,datasetIndex:e,index:e,mode:"default",type:"dataset"})}(this.chart.getContext(),this.index)),n.dataset=s,n.index=n.datasetIndex=this.index;return n.active=!!e,n.mode=i,n}resolveDatasetElementOptions(t){return this._resolveElementOptions(this.datasetElementType.id,t)}resolveDataElementOptions(t,e){return this._resolveElementOptions(this.dataElementType.id,e,t)}_resolveElementOptions(t,e="default",i){const s="active"===e,n=this._cachedDataOpts,o=t+"-"+e,a=n[o],r=this.enableOptionSharing&&k(i);if(a)return Hs(a,r);const l=this.chart.config,h=l.datasetElementScopeKeys(this._type,t),c=s?[`${t}Hover`,"hover",t,""]:[t,""],d=l.getOptionScopes(this.getDataset(),h),u=Object.keys(ue.elements[t]),f=l.resolveNamedOptions(d,u,(()=>this.getContext(i,s,e)),c);return f.$shared&&(f.$shared=r,n[o]=Object.freeze(Hs(f,r))),f}_resolveAnimations(t,e,i){const s=this.chart,n=this._cachedDataOpts,o=`animation-${e}`,a=n[o];if(a)return a;let r;if(!1!==s.options.animation){const s=this.chart.config,n=s.datasetAnimationScopeKeys(this._type,e),o=s.getOptionScopes(this.getDataset(),n);r=s.createResolver(o,this.getContext(t,i,e))}const l=new Ts(s,r&&r.animations);return r&&r._cacheable&&(n[o]=Object.freeze(l)),l}getSharedOptions(t){if(t.$shared)return this._sharedOptions||(this._sharedOptions=Object.assign({},t))}includeOptions(t,e){return!e||Ns(t)||this.chart._animationsDisabled}_getSharedOptions(t,e){const i=this.resolveDataElementOptions(t,e),s=this._sharedOptions,n=this.getSharedOptions(i),o=this.includeOptions(e,n)||n!==s;return this.updateSharedOptions(n,e,i),{sharedOptions:n,includeOptions:o}}updateElement(t,e,i,s){Ns(s)?Object.assign(t,i):this._resolveAnimations(e,s).update(t,i)}updateSharedOptions(t,e,i){t&&!Ns(e)&&this._resolveAnimations(void 0,e).update(t,i)}_setStyle(t,e,i,s){t.active=s;const n=this.getStyle(e,s);this._resolveAnimations(e,i,s).update(t,{options:!s&&this.getSharedOptions(n)||n})}removeHoverStyle(t,e,i){this._setStyle(t,i,"active",!1)}setHoverStyle(t,e,i){this._setStyle(t,i,"active",!0)}_removeDatasetHoverStyle(){const t=this._cachedMeta.dataset;t&&this._setStyle(t,void 0,"active",!1)}_setDatasetHoverStyle(){const t=this._cachedMeta.dataset;t&&this._setStyle(t,void 0,"active",!0)}_resyncElements(t){const e=this._data,i=this._cachedMeta.data;for(const[t,e,i]of this._syncList)this[t](e,i);this._syncList=[];const s=i.length,n=e.length,o=Math.min(n,s);o&&this.parse(0,o),n>s?this._insertElements(s,n-s,t):n<s&&this._removeElements(n,s-n)}_insertElements(t,e,i=!0){const s=this._cachedMeta,n=s.data,o=t+e;let a;const r=t=>{for(t.length+=e,a=t.length-1;a>=o;a--)t[a]=t[a-e]};for(r(n),a=t;a<o;++a)n[a]=new this.dataElementType;this._parsing&&r(s._parsed),this.parse(t,e),i&&this.updateElements(n,t,e,"reset")}updateElements(t,e,i,s){}_removeElements(t,e){const i=this._cachedMeta;if(this._parsing){const s=i._parsed.splice(t,e);i._stacked&&Ws(i,s)}i.data.splice(t,e)}_sync(t){if(this._parsing)this._syncList.push(t);else{const[e,i,s]=t;this[e](i,s)}this.chart._dataChanges.push([this.index,...t])}_onDataPush(){const t=arguments.length;this._sync(["_insertElements",this.getDataset().data.length-t,t])}_onDataPop(){this._sync(["_removeElements",this._cachedMeta.data.length-1,1])}_onDataShift(){this._sync(["_removeElements",0,1])}_onDataSplice(t,e){e&&this._sync(["_removeElements",t,e]);const i=arguments.length-2;i&&this._sync(["_insertElements",t,i])}_onDataUnshift(){this._sync(["_insertElements",0,arguments.length])}}class $s{static defaults={};static defaultRoutes=void 0;x;y;active=!1;options;$animations;tooltipPosition(t){const{x:e,y:i}=this.getProps(["x","y"],t);return{x:e,y:i}}hasValue(){return N(this.x)&&N(this.y)}getProps(t,e){const i=this.$animations;if(!e||!i)return this;const s={};return t.forEach((t=>{s[t]=i[t]&&i[t].active()?i[t]._to:this[t]})),s}}function Ys(t,e){const i=t.options.ticks,n=function(t){const e=t.options.offset,i=t._tickSize(),s=t._length/i+(e?0:1),n=t._maxLength/i;return Math.floor(Math.min(s,n))}(t),o=Math.min(i.maxTicksLimit||n,n),a=i.major.enabled?function(t){const e=[];let i,s;for(i=0,s=t.length;i<s;i++)t[i].major&&e.push(i);return e}(e):[],r=a.length,l=a[0],h=a[r-1],c=[];if(r>o)return function(t,e,i,s){let n,o=0,a=i[0];for(s=Math.ceil(s),n=0;n<t.length;n++)n===a&&(e.push(t[n]),o++,a=i[o*s])}(e,c,a,r/o),c;const d=function(t,e,i){const s=function(t){const e=t.length;let i,s;if(e<2)return!1;for(s=t[0],i=1;i<e;++i)if(t[i]-t[i-1]!==s)return!1;return s}(t),n=e.length/i;if(!s)return Math.max(n,1);const o=W(s);for(let t=0,e=o.length-1;t<e;t++){const e=o[t];if(e>n)return e}return Math.max(n,1)}(a,e,o);if(r>0){let t,i;const n=r>1?Math.round((h-l)/(r-1)):null;for(Us(e,c,d,s(n)?0:l-n,l),t=0,i=r-1;t<i;t++)Us(e,c,d,a[t],a[t+1]);return Us(e,c,d,h,s(n)?e.length:h+n),c}return Us(e,c,d),c}function Us(t,e,i,s,n){const o=l(s,0),a=Math.min(l(n,t.length),t.length);let r,h,c,d=0;for(i=Math.ceil(i),n&&(r=n-s,i=r/Math.floor(r/i)),c=o;c<0;)d++,c=Math.round(o+d*i);for(h=Math.max(o,0);h<a;h++)h===c&&(e.push(t[h]),d++,c=Math.round(o+d*i))}const Xs=(t,e,i)=>"top"===e||"left"===e?t[e]+i:t[e]-i,qs=(t,e)=>Math.min(e||t,t);function Ks(t,e){const i=[],s=t.length/e,n=t.length;let o=0;for(;o<n;o+=s)i.push(t[Math.floor(o)]);return i}function Gs(t,e,i){const s=t.ticks.length,n=Math.min(e,s-1),o=t._startPixel,a=t._endPixel,r=1e-6;let l,h=t.getPixelForTick(n);if(!(i&&(l=1===s?Math.max(h-o,a-h):0===e?(t.getPixelForTick(1)-h)/2:(h-t.getPixelForTick(n-1))/2,h+=n<e?l:-l,h<o-r||h>a+r)))return h}function Js(t){return t.drawTicks?t.tickLength:0}function Zs(t,e){if(!t.display)return 0;const i=Si(t.font,e),s=ki(t.padding);return(n(t.text)?t.text.length:1)*i.lineHeight+s.height}function Qs(t,e,i){let s=ut(t);return(i&&"right"!==e||!i&&"right"===e)&&(s=(t=>"left"===t?"right":"right"===t?"left":t)(s)),s}class tn extends $s{constructor(t){super(),this.id=t.id,this.type=t.type,this.options=void 0,this.ctx=t.ctx,this.chart=t.chart,this.top=void 0,this.bottom=void 0,this.left=void 0,this.right=void 0,this.width=void 0,this.height=void 0,this._margins={left:0,right:0,top:0,bottom:0},this.maxWidth=void 0,this.maxHeight=void 0,this.paddingTop=void 0,this.paddingBottom=void 0,this.paddingLeft=void 0,this.paddingRight=void 0,this.axis=void 0,this.labelRotation=void 0,this.min=void 0,this.max=void 0,this._range=void 0,this.ticks=[],this._gridLineItems=null,this._labelItems=null,this._labelSizes=null,this._length=0,this._maxLength=0,this._longestTextCache={},this._startPixel=void 0,this._endPixel=void 0,this._reversePixels=!1,this._userMax=void 0,this._userMin=void 0,this._suggestedMax=void 0,this._suggestedMin=void 0,this._ticksLength=0,this._borderValue=0,this._cache={},this._dataLimitsCached=!1,this.$context=void 0}init(t){this.options=t.setContext(this.getContext()),this.axis=t.axis,this._userMin=this.parse(t.min),this._userMax=this.parse(t.max),this._suggestedMin=this.parse(t.suggestedMin),this._suggestedMax=this.parse(t.suggestedMax)}parse(t,e){return t}getUserBounds(){let{_userMin:t,_userMax:e,_suggestedMin:i,_suggestedMax:s}=this;return t=r(t,Number.POSITIVE_INFINITY),e=r(e,Number.NEGATIVE_INFINITY),i=r(i,Number.POSITIVE_INFINITY),s=r(s,Number.NEGATIVE_INFINITY),{min:r(t,i),max:r(e,s),minDefined:a(t),maxDefined:a(e)}}getMinMax(t){let e,{min:i,max:s,minDefined:n,maxDefined:o}=this.getUserBounds();if(n&&o)return{min:i,max:s};const a=this.getMatchingVisibleMetas();for(let r=0,l=a.length;r<l;++r)e=a[r].controller.getMinMax(this,t),n||(i=Math.min(i,e.min)),o||(s=Math.max(s,e.max));return i=o&&i>s?s:i,s=n&&i>s?i:s,{min:r(i,r(s,i)),max:r(s,r(i,s))}}getPadding(){return{left:this.paddingLeft||0,top:this.paddingTop||0,right:this.paddingRight||0,bottom:this.paddingBottom||0}}getTicks(){return this.ticks}getLabels(){const t=this.chart.data;return this.options.labels||(this.isHorizontal()?t.xLabels:t.yLabels)||t.labels||[]}getLabelItems(t=this.chart.chartArea){return this._labelItems||(this._labelItems=this._computeLabelItems(t))}beforeLayout(){this._cache={},this._dataLimitsCached=!1}beforeUpdate(){d(this.options.beforeUpdate,[this])}update(t,e,i){const{beginAtZero:s,grace:n,ticks:o}=this.options,a=o.sampleSize;this.beforeUpdate(),this.maxWidth=t,this.maxHeight=e,this._margins=i=Object.assign({left:0,right:0,top:0,bottom:0},i),this.ticks=null,this._labelSizes=null,this._gridLineItems=null,this._labelItems=null,this.beforeSetDimensions(),this.setDimensions(),this.afterSetDimensions(),this._maxLength=this.isHorizontal()?this.width+i.left+i.right:this.height+i.top+i.bottom,this._dataLimitsCached||(this.beforeDataLimits(),this.determineDataLimits(),this.afterDataLimits(),this._range=Di(this,n,s),this._dataLimitsCached=!0),this.beforeBuildTicks(),this.ticks=this.buildTicks()||[],this.afterBuildTicks();const r=a<this.ticks.length;this._convertTicksToLabels(r?Ks(this.ticks,a):this.ticks),this.configure(),this.beforeCalculateLabelRotation(),this.calculateLabelRotation(),this.afterCalculateLabelRotation(),o.display&&(o.autoSkip||"auto"===o.source)&&(this.ticks=Ys(this,this.ticks),this._labelSizes=null,this.afterAutoSkip()),r&&this._convertTicksToLabels(this.ticks),this.beforeFit(),this.fit(),this.afterFit(),this.afterUpdate()}configure(){let t,e,i=this.options.reverse;this.isHorizontal()?(t=this.left,e=this.right):(t=this.top,e=this.bottom,i=!i),this._startPixel=t,this._endPixel=e,this._reversePixels=i,this._length=e-t,this._alignToPixels=this.options.alignToPixels}afterUpdate(){d(this.options.afterUpdate,[this])}beforeSetDimensions(){d(this.options.beforeSetDimensions,[this])}setDimensions(){this.isHorizontal()?(this.width=this.maxWidth,this.left=0,this.right=this.width):(this.height=this.maxHeight,this.top=0,this.bottom=this.height),this.paddingLeft=0,this.paddingTop=0,this.paddingRight=0,this.paddingBottom=0}afterSetDimensions(){d(this.options.afterSetDimensions,[this])}_callHooks(t){this.chart.notifyPlugins(t,this.getContext()),d(this.options[t],[this])}beforeDataLimits(){this._callHooks("beforeDataLimits")}determineDataLimits(){}afterDataLimits(){this._callHooks("afterDataLimits")}beforeBuildTicks(){this._callHooks("beforeBuildTicks")}buildTicks(){return[]}afterBuildTicks(){this._callHooks("afterBuildTicks")}beforeTickToLabelConversion(){d(this.options.beforeTickToLabelConversion,[this])}generateTickLabels(t){const e=this.options.ticks;let i,s,n;for(i=0,s=t.length;i<s;i++)n=t[i],n.label=d(e.callback,[n.value,i,t],this)}afterTickToLabelConversion(){d(this.options.afterTickToLabelConversion,[this])}beforeCalculateLabelRotation(){d(this.options.beforeCalculateLabelRotation,[this])}calculateLabelRotation(){const t=this.options,e=t.ticks,i=qs(this.ticks.length,t.ticks.maxTicksLimit),s=e.minRotation||0,n=e.maxRotation;let o,a,r,l=s;if(!this._isVisible()||!e.display||s>=n||i<=1||!this.isHorizontal())return void(this.labelRotation=s);const h=this._getLabelSizes(),c=h.widest.width,d=h.highest.height,u=Z(this.chart.width-c,0,this.maxWidth);o=t.offset?this.maxWidth/i:u/(i-1),c+6>o&&(o=u/(i-(t.offset?.5:1)),a=this.maxHeight-Js(t.grid)-e.padding-Zs(t.title,this.chart.options.font),r=Math.sqrt(c*c+d*d),l=Y(Math.min(Math.asin(Z((h.highest.height+6)/o,-1,1)),Math.asin(Z(a/r,-1,1))-Math.asin(Z(d/r,-1,1)))),l=Math.max(s,Math.min(n,l))),this.labelRotation=l}afterCalculateLabelRotation(){d(this.options.afterCalculateLabelRotation,[this])}afterAutoSkip(){}beforeFit(){d(this.options.beforeFit,[this])}fit(){const t={width:0,height:0},{chart:e,options:{ticks:i,title:s,grid:n}}=this,o=this._isVisible(),a=this.isHorizontal();if(o){const o=Zs(s,e.options.font);if(a?(t.width=this.maxWidth,t.height=Js(n)+o):(t.height=this.maxHeight,t.width=Js(n)+o),i.display&&this.ticks.length){const{first:e,last:s,widest:n,highest:o}=this._getLabelSizes(),r=2*i.padding,l=$(this.labelRotation),h=Math.cos(l),c=Math.sin(l);if(a){const e=i.mirror?0:c*n.width+h*o.height;t.height=Math.min(this.maxHeight,t.height+e+r)}else{const e=i.mirror?0:h*n.width+c*o.height;t.width=Math.min(this.maxWidth,t.width+e+r)}this._calculatePadding(e,s,c,h)}}this._handleMargins(),a?(this.width=this._length=e.width-this._margins.left-this._margins.right,this.height=t.height):(this.width=t.width,this.height=this._length=e.height-this._margins.top-this._margins.bottom)}_calculatePadding(t,e,i,s){const{ticks:{align:n,padding:o},position:a}=this.options,r=0!==this.labelRotation,l="top"!==a&&"x"===this.axis;if(this.isHorizontal()){const a=this.getPixelForTick(0)-this.left,h=this.right-this.getPixelForTick(this.ticks.length-1);let c=0,d=0;r?l?(c=s*t.width,d=i*e.height):(c=i*t.height,d=s*e.width):"start"===n?d=e.width:"end"===n?c=t.width:"inner"!==n&&(c=t.width/2,d=e.width/2),this.paddingLeft=Math.max((c-a+o)*this.width/(this.width-a),0),this.paddingRight=Math.max((d-h+o)*this.width/(this.width-h),0)}else{let i=e.height/2,s=t.height/2;"start"===n?(i=0,s=t.height):"end"===n&&(i=e.height,s=0),this.paddingTop=i+o,this.paddingBottom=s+o}}_handleMargins(){this._margins&&(this._margins.left=Math.max(this.paddingLeft,this._margins.left),this._margins.top=Math.max(this.paddingTop,this._margins.top),this._margins.right=Math.max(this.paddingRight,this._margins.right),this._margins.bottom=Math.max(this.paddingBottom,this._margins.bottom))}afterFit(){d(this.options.afterFit,[this])}isHorizontal(){const{axis:t,position:e}=this.options;return"top"===e||"bottom"===e||"x"===t}isFullSize(){return this.options.fullSize}_convertTicksToLabels(t){let e,i;for(this.beforeTickToLabelConversion(),this.generateTickLabels(t),e=0,i=t.length;e<i;e++)s(t[e].label)&&(t.splice(e,1),i--,e--);this.afterTickToLabelConversion()}_getLabelSizes(){let t=this._labelSizes;if(!t){const e=this.options.ticks.sampleSize;let i=this.ticks;e<i.length&&(i=Ks(i,e)),this._labelSizes=t=this._computeLabelSizes(i,i.length,this.options.ticks.maxTicksLimit)}return t}_computeLabelSizes(t,e,i){const{ctx:o,_longestTextCache:a}=this,r=[],l=[],h=Math.floor(e/qs(e,i));let c,d,f,g,p,m,x,b,_,y,v,M=0,w=0;for(c=0;c<e;c+=h){if(g=t[c].label,p=this._resolveTickFontOptions(c),o.font=m=p.string,x=a[m]=a[m]||{data:{},gc:[]},b=p.lineHeight,_=y=0,s(g)||n(g)){if(n(g))for(d=0,f=g.length;d<f;++d)v=g[d],s(v)||n(v)||(_=Ce(o,x.data,x.gc,_,v),y+=b)}else _=Ce(o,x.data,x.gc,_,g),y=b;r.push(_),l.push(y),M=Math.max(_,M),w=Math.max(y,w)}!function(t,e){u(t,(t=>{const i=t.gc,s=i.length/2;let n;if(s>e){for(n=0;n<s;++n)delete t.data[i[n]];i.splice(0,s)}}))}(a,e);const k=r.indexOf(M),S=l.indexOf(w),P=t=>({width:r[t]||0,height:l[t]||0});return{first:P(0),last:P(e-1),widest:P(k),highest:P(S),widths:r,heights:l}}getLabelForValue(t){return t}getPixelForValue(t,e){return NaN}getValueForPixel(t){}getPixelForTick(t){const e=this.ticks;return t<0||t>e.length-1?null:this.getPixelForValue(e[t].value)}getPixelForDecimal(t){this._reversePixels&&(t=1-t);const e=this._startPixel+t*this._length;return Q(this._alignToPixels?Ae(this.chart,e,0):e)}getDecimalForPixel(t){const e=(t-this._startPixel)/this._length;return this._reversePixels?1-e:e}getBasePixel(){return this.getPixelForValue(this.getBaseValue())}getBaseValue(){const{min:t,max:e}=this;return t<0&&e<0?e:t>0&&e>0?t:0}getContext(t){const e=this.ticks||[];if(t>=0&&t<e.length){const i=e[t];return i.$context||(i.$context=function(t,e,i){return Ci(t,{tick:i,index:e,type:"tick"})}(this.getContext(),t,i))}return this.$context||(this.$context=Ci(this.chart.getContext(),{scale:this,type:"scale"}))}_tickSize(){const t=this.options.ticks,e=$(this.labelRotation),i=Math.abs(Math.cos(e)),s=Math.abs(Math.sin(e)),n=this._getLabelSizes(),o=t.autoSkipPadding||0,a=n?n.widest.width+o:0,r=n?n.highest.height+o:0;return this.isHorizontal()?r*i>a*s?a/i:r/s:r*s<a*i?r/i:a/s}_isVisible(){const t=this.options.display;return"auto"!==t?!!t:this.getMatchingVisibleMetas().length>0}_computeGridLineItems(t){const e=this.axis,i=this.chart,s=this.options,{grid:n,position:a,border:r}=s,h=n.offset,c=this.isHorizontal(),d=this.ticks.length+(h?1:0),u=Js(n),f=[],g=r.setContext(this.getContext()),p=g.display?g.width:0,m=p/2,x=function(t){return Ae(i,t,p)};let b,_,y,v,M,w,k,S,P,D,C,O;if("top"===a)b=x(this.bottom),w=this.bottom-u,S=b-m,D=x(t.top)+m,O=t.bottom;else if("bottom"===a)b=x(this.top),D=t.top,O=x(t.bottom)-m,w=b+m,S=this.top+u;else if("left"===a)b=x(this.right),M=this.right-u,k=b-m,P=x(t.left)+m,C=t.right;else if("right"===a)b=x(this.left),P=t.left,C=x(t.right)-m,M=b+m,k=this.left+u;else if("x"===e){if("center"===a)b=x((t.top+t.bottom)/2+.5);else if(o(a)){const t=Object.keys(a)[0],e=a[t];b=x(this.chart.scales[t].getPixelForValue(e))}D=t.top,O=t.bottom,w=b+m,S=w+u}else if("y"===e){if("center"===a)b=x((t.left+t.right)/2);else if(o(a)){const t=Object.keys(a)[0],e=a[t];b=x(this.chart.scales[t].getPixelForValue(e))}M=b-m,k=M-u,P=t.left,C=t.right}const A=l(s.ticks.maxTicksLimit,d),T=Math.max(1,Math.ceil(d/A));for(_=0;_<d;_+=T){const t=this.getContext(_),e=n.setContext(t),s=r.setContext(t),o=e.lineWidth,a=e.color,l=s.dash||[],d=s.dashOffset,u=e.tickWidth,g=e.tickColor,p=e.tickBorderDash||[],m=e.tickBorderDashOffset;y=Gs(this,_,h),void 0!==y&&(v=Ae(i,y,o),c?M=k=P=C=v:w=S=D=O=v,f.push({tx1:M,ty1:w,tx2:k,ty2:S,x1:P,y1:D,x2:C,y2:O,width:o,color:a,borderDash:l,borderDashOffset:d,tickWidth:u,tickColor:g,tickBorderDash:p,tickBorderDashOffset:m}))}return this._ticksLength=d,this._borderValue=b,f}_computeLabelItems(t){const e=this.axis,i=this.options,{position:s,ticks:a}=i,r=this.isHorizontal(),l=this.ticks,{align:h,crossAlign:c,padding:d,mirror:u}=a,f=Js(i.grid),g=f+d,p=u?-d:g,m=-$(this.labelRotation),x=[];let b,_,y,v,M,w,k,S,P,D,C,O,A="middle";if("top"===s)w=this.bottom-p,k=this._getXAxisLabelAlignment();else if("bottom"===s)w=this.top+p,k=this._getXAxisLabelAlignment();else if("left"===s){const t=this._getYAxisLabelAlignment(f);k=t.textAlign,M=t.x}else if("right"===s){const t=this._getYAxisLabelAlignment(f);k=t.textAlign,M=t.x}else if("x"===e){if("center"===s)w=(t.top+t.bottom)/2+g;else if(o(s)){const t=Object.keys(s)[0],e=s[t];w=this.chart.scales[t].getPixelForValue(e)+g}k=this._getXAxisLabelAlignment()}else if("y"===e){if("center"===s)M=(t.left+t.right)/2-g;else if(o(s)){const t=Object.keys(s)[0],e=s[t];M=this.chart.scales[t].getPixelForValue(e)}k=this._getYAxisLabelAlignment(f).textAlign}"y"===e&&("start"===h?A="top":"end"===h&&(A="bottom"));const T=this._getLabelSizes();for(b=0,_=l.length;b<_;++b){y=l[b],v=y.label;const t=a.setContext(this.getContext(b));S=this.getPixelForTick(b)+a.labelOffset,P=this._resolveTickFontOptions(b),D=P.lineHeight,C=n(v)?v.length:1;const e=C/2,i=t.color,o=t.textStrokeColor,h=t.textStrokeWidth;let d,f=k;if(r?(M=S,"inner"===k&&(f=b===_-1?this.options.reverse?"left":"right":0===b?this.options.reverse?"right":"left":"center"),O="top"===s?"near"===c||0!==m?-C*D+D/2:"center"===c?-T.highest.height/2-e*D+D:-T.highest.height+D/2:"near"===c||0!==m?D/2:"center"===c?T.highest.height/2-e*D:T.highest.height-C*D,u&&(O*=-1),0===m||t.showLabelBackdrop||(M+=D/2*Math.sin(m))):(w=S,O=(1-C)*D/2),t.showLabelBackdrop){const e=ki(t.backdropPadding),i=T.heights[b],s=T.widths[b];let n=O-e.top,o=0-e.left;switch(A){case"middle":n-=i/2;break;case"bottom":n-=i}switch(k){case"center":o-=s/2;break;case"right":o-=s;break;case"inner":b===_-1?o-=s:b>0&&(o-=s/2)}d={left:o,top:n,width:s+e.width,height:i+e.height,color:t.backdropColor}}x.push({label:v,font:P,textOffset:O,options:{rotation:m,color:i,strokeColor:o,strokeWidth:h,textAlign:f,textBaseline:A,translation:[M,w],backdrop:d}})}return x}_getXAxisLabelAlignment(){const{position:t,ticks:e}=this.options;if(-$(this.labelRotation))return"top"===t?"left":"right";let i="center";return"start"===e.align?i="left":"end"===e.align?i="right":"inner"===e.align&&(i="inner"),i}_getYAxisLabelAlignment(t){const{position:e,ticks:{crossAlign:i,mirror:s,padding:n}}=this.options,o=t+n,a=this._getLabelSizes().widest.width;let r,l;return"left"===e?s?(l=this.right+n,"near"===i?r="left":"center"===i?(r="center",l+=a/2):(r="right",l+=a)):(l=this.right-o,"near"===i?r="right":"center"===i?(r="center",l-=a/2):(r="left",l=this.left)):"right"===e?s?(l=this.left+n,"near"===i?r="right":"center"===i?(r="center",l-=a/2):(r="left",l-=a)):(l=this.left+o,"near"===i?r="left":"center"===i?(r="center",l+=a/2):(r="right",l=this.right)):r="right",{textAlign:r,x:l}}_computeLabelArea(){if(this.options.ticks.mirror)return;const t=this.chart,e=this.options.position;return"left"===e||"right"===e?{top:0,left:this.left,bottom:t.height,right:this.right}:"top"===e||"bottom"===e?{top:this.top,left:0,bottom:this.bottom,right:t.width}:void 0}drawBackground(){const{ctx:t,options:{backgroundColor:e},left:i,top:s,width:n,height:o}=this;e&&(t.save(),t.fillStyle=e,t.fillRect(i,s,n,o),t.restore())}getLineWidthForValue(t){const e=this.options.grid;if(!this._isVisible()||!e.display)return 0;const i=this.ticks.findIndex((e=>e.value===t));if(i>=0){return e.setContext(this.getContext(i)).lineWidth}return 0}drawGrid(t){const e=this.options.grid,i=this.ctx,s=this._gridLineItems||(this._gridLineItems=this._computeGridLineItems(t));let n,o;const a=(t,e,s)=>{s.width&&s.color&&(i.save(),i.lineWidth=s.width,i.strokeStyle=s.color,i.setLineDash(s.borderDash||[]),i.lineDashOffset=s.borderDashOffset,i.beginPath(),i.moveTo(t.x,t.y),i.lineTo(e.x,e.y),i.stroke(),i.restore())};if(e.display)for(n=0,o=s.length;n<o;++n){const t=s[n];e.drawOnChartArea&&a({x:t.x1,y:t.y1},{x:t.x2,y:t.y2},t),e.drawTicks&&a({x:t.tx1,y:t.ty1},{x:t.tx2,y:t.ty2},{color:t.tickColor,width:t.tickWidth,borderDash:t.tickBorderDash,borderDashOffset:t.tickBorderDashOffset})}}drawBorder(){const{chart:t,ctx:e,options:{border:i,grid:s}}=this,n=i.setContext(this.getContext()),o=i.display?n.width:0;if(!o)return;const a=s.setContext(this.getContext(0)).lineWidth,r=this._borderValue;let l,h,c,d;this.isHorizontal()?(l=Ae(t,this.left,o)-o/2,h=Ae(t,this.right,a)+a/2,c=d=r):(c=Ae(t,this.top,o)-o/2,d=Ae(t,this.bottom,a)+a/2,l=h=r),e.save(),e.lineWidth=n.width,e.strokeStyle=n.color,e.beginPath(),e.moveTo(l,c),e.lineTo(h,d),e.stroke(),e.restore()}drawLabels(t){if(!this.options.ticks.display)return;const e=this.ctx,i=this._computeLabelArea();i&&Ie(e,i);const s=this.getLabelItems(t);for(const t of s){const i=t.options,s=t.font;Ne(e,t.label,0,t.textOffset,s,i)}i&&ze(e)}drawTitle(){const{ctx:t,options:{position:e,title:i,reverse:s}}=this;if(!i.display)return;const a=Si(i.font),r=ki(i.padding),l=i.align;let h=a.lineHeight/2;"bottom"===e||"center"===e||o(e)?(h+=r.bottom,n(i.text)&&(h+=a.lineHeight*(i.text.length-1))):h+=r.top;const{titleX:c,titleY:d,maxWidth:u,rotation:f}=function(t,e,i,s){const{top:n,left:a,bottom:r,right:l,chart:h}=t,{chartArea:c,scales:d}=h;let u,f,g,p=0;const m=r-n,x=l-a;if(t.isHorizontal()){if(f=ft(s,a,l),o(i)){const t=Object.keys(i)[0],s=i[t];g=d[t].getPixelForValue(s)+m-e}else g="center"===i?(c.bottom+c.top)/2+m-e:Xs(t,i,e);u=l-a}else{if(o(i)){const t=Object.keys(i)[0],s=i[t];f=d[t].getPixelForValue(s)-x+e}else f="center"===i?(c.left+c.right)/2-x+e:Xs(t,i,e);g=ft(s,r,n),p="left"===i?-E:E}return{titleX:f,titleY:g,maxWidth:u,rotation:p}}(this,h,e,l);Ne(t,i.text,0,0,a,{color:i.color,maxWidth:u,rotation:f,textAlign:Qs(l,e,s),textBaseline:"middle",translation:[c,d]})}draw(t){this._isVisible()&&(this.drawBackground(),this.drawGrid(t),this.drawBorder(),this.drawTitle(),this.drawLabels(t))}_layers(){const t=this.options,e=t.ticks&&t.ticks.z||0,i=l(t.grid&&t.grid.z,-1),s=l(t.border&&t.border.z,0);return this._isVisible()&&this.draw===tn.prototype.draw?[{z:i,draw:t=>{this.drawBackground(),this.drawGrid(t),this.drawTitle()}},{z:s,draw:()=>{this.drawBorder()}},{z:e,draw:t=>{this.drawLabels(t)}}]:[{z:e,draw:t=>{this.draw(t)}}]}getMatchingVisibleMetas(t){const e=this.chart.getSortedVisibleDatasetMetas(),i=this.axis+"AxisID",s=[];let n,o;for(n=0,o=e.length;n<o;++n){const o=e[n];o[i]!==this.id||t&&o.type!==t||s.push(o)}return s}_resolveTickFontOptions(t){return Si(this.options.ticks.setContext(this.getContext(t)).font)}_maxDigits(){const t=this._resolveTickFontOptions(0).lineHeight;return(this.isHorizontal()?this.width:this.height)/t}}class en{constructor(t,e,i){this.type=t,this.scope=e,this.override=i,this.items=Object.create(null)}isForType(t){return Object.prototype.isPrototypeOf.call(this.type.prototype,t.prototype)}register(t){const e=Object.getPrototypeOf(t);let i;(function(t){return"id"in t&&"defaults"in t})(e)&&(i=this.register(e));const s=this.items,n=t.id,o=this.scope+"."+n;if(!n)throw new Error("class does not have id: "+t);return n in s||(s[n]=t,function(t,e,i){const s=x(Object.create(null),[i?ue.get(i):{},ue.get(e),t.defaults]);ue.set(e,s),t.defaultRoutes&&function(t,e){Object.keys(e).forEach((i=>{const s=i.split("."),n=s.pop(),o=[t].concat(s).join("."),a=e[i].split("."),r=a.pop(),l=a.join(".");ue.route(o,n,l,r)}))}(e,t.defaultRoutes);t.descriptors&&ue.describe(e,t.descriptors)}(t,o,i),this.override&&ue.override(t.id,t.overrides)),o}get(t){return this.items[t]}unregister(t){const e=this.items,i=t.id,s=this.scope;i in e&&delete e[i],s&&i in ue[s]&&(delete ue[s][i],this.override&&delete re[i])}}class sn{constructor(){this.controllers=new en(js,"datasets",!0),this.elements=new en($s,"elements"),this.plugins=new en(Object,"plugins"),this.scales=new en(tn,"scales"),this._typedRegistries=[this.controllers,this.scales,this.elements]}add(...t){this._each("register",t)}remove(...t){this._each("unregister",t)}addControllers(...t){this._each("register",t,this.controllers)}addElements(...t){this._each("register",t,this.elements)}addPlugins(...t){this._each("register",t,this.plugins)}addScales(...t){this._each("register",t,this.scales)}getController(t){return this._get(t,this.controllers,"controller")}getElement(t){return this._get(t,this.elements,"element")}getPlugin(t){return this._get(t,this.plugins,"plugin")}getScale(t){return this._get(t,this.scales,"scale")}removeControllers(...t){this._each("unregister",t,this.controllers)}removeElements(...t){this._each("unregister",t,this.elements)}removePlugins(...t){this._each("unregister",t,this.plugins)}removeScales(...t){this._each("unregister",t,this.scales)}_each(t,e,i){[...e].forEach((e=>{const s=i||this._getRegistryForType(e);i||s.isForType(e)||s===this.plugins&&e.id?this._exec(t,s,e):u(e,(e=>{const s=i||this._getRegistryForType(e);this._exec(t,s,e)}))}))}_exec(t,e,i){const s=w(t);d(i["before"+s],[],i),e[t](i),d(i["after"+s],[],i)}_getRegistryForType(t){for(let e=0;e<this._typedRegistries.length;e++){const i=this._typedRegistries[e];if(i.isForType(t))return i}return this.plugins}_get(t,e,i){const s=e.get(t);if(void 0===s)throw new Error('"'+t+'" is not a registered '+i+".");return s}}var nn=new sn;class on{constructor(){this._init=void 0}notify(t,e,i,s){if("beforeInit"===e&&(this._init=this._createDescriptors(t,!0),this._notify(this._init,t,"install")),void 0===this._init)return;const n=s?this._descriptors(t).filter(s):this._descriptors(t),o=this._notify(n,t,e,i);return"afterDestroy"===e&&(this._notify(n,t,"stop"),this._notify(this._init,t,"uninstall"),this._init=void 0),o}_notify(t,e,i,s){s=s||{};for(const n of t){const t=n.plugin;if(!1===d(t[i],[e,s,n.options],t)&&s.cancelable)return!1}return!0}invalidate(){s(this._cache)||(this._oldCache=this._cache,this._cache=void 0)}_descriptors(t){if(this._cache)return this._cache;const e=this._cache=this._createDescriptors(t);return this._notifyStateChanges(t),e}_createDescriptors(t,e){const i=t&&t.config,s=l(i.options&&i.options.plugins,{}),n=function(t){const e={},i=[],s=Object.keys(nn.plugins.items);for(let t=0;t<s.length;t++)i.push(nn.getPlugin(s[t]));const n=t.plugins||[];for(let t=0;t<n.length;t++){const s=n[t];-1===i.indexOf(s)&&(i.push(s),e[s.id]=!0)}return{plugins:i,localIds:e}}(i);return!1!==s||e?function(t,{plugins:e,localIds:i},s,n){const o=[],a=t.getContext();for(const r of e){const e=r.id,l=an(s[e],n);null!==l&&o.push({plugin:r,options:rn(t.config,{plugin:r,local:i[e]},l,a)})}return o}(t,n,s,e):[]}_notifyStateChanges(t){const e=this._oldCache||[],i=this._cache,s=(t,e)=>t.filter((t=>!e.some((e=>t.plugin.id===e.plugin.id))));this._notify(s(e,i),t,"stop"),this._notify(s(i,e),t,"start")}}function an(t,e){return e||!1!==t?!0===t?{}:t:null}function rn(t,{plugin:e,local:i},s,n){const o=t.pluginScopeKeys(e),a=t.getOptionScopes(s,o);return i&&e.defaults&&a.push(e.defaults),t.createResolver(a,n,[""],{scriptable:!1,indexable:!1,allKeys:!0})}function ln(t,e){const i=ue.datasets[t]||{};return((e.datasets||{})[t]||{}).indexAxis||e.indexAxis||i.indexAxis||"x"}function hn(t){if("x"===t||"y"===t||"r"===t)return t}function cn(t,...e){if(hn(t))return t;for(const s of e){const e=s.axis||("top"===(i=s.position)||"bottom"===i?"x":"left"===i||"right"===i?"y":void 0)||t.length>1&&hn(t[0].toLowerCase());if(e)return e}var i;throw new Error(`Cannot determine type of '${t}' axis. Please provide 'axis' or 'position' option.`)}function dn(t,e,i){if(i[e+"AxisID"]===t)return{axis:e}}function un(t,e){const i=re[t.type]||{scales:{}},s=e.scales||{},n=ln(t.type,e),a=Object.create(null);return Object.keys(s).forEach((e=>{const r=s[e];if(!o(r))return console.error(`Invalid scale configuration for scale: ${e}`);if(r._proxy)return console.warn(`Ignoring resolver passed as options for scale: ${e}`);const l=cn(e,r,function(t,e){if(e.data&&e.data.datasets){const i=e.data.datasets.filter((e=>e.xAxisID===t||e.yAxisID===t));if(i.length)return dn(t,"x",i[0])||dn(t,"y",i[0])}return{}}(e,t),ue.scales[r.type]),h=function(t,e){return t===e?"_index_":"_value_"}(l,n),c=i.scales||{};a[e]=b(Object.create(null),[{axis:l},r,c[l],c[h]])})),t.data.datasets.forEach((i=>{const n=i.type||t.type,o=i.indexAxis||ln(n,e),r=(re[n]||{}).scales||{};Object.keys(r).forEach((t=>{const e=function(t,e){let i=t;return"_index_"===t?i=e:"_value_"===t&&(i="x"===e?"y":"x"),i}(t,o),n=i[e+"AxisID"]||e;a[n]=a[n]||Object.create(null),b(a[n],[{axis:e},s[n],r[t]])}))})),Object.keys(a).forEach((t=>{const e=a[t];b(e,[ue.scales[e.type],ue.scale])})),a}function fn(t){const e=t.options||(t.options={});e.plugins=l(e.plugins,{}),e.scales=un(t,e)}function gn(t){return(t=t||{}).datasets=t.datasets||[],t.labels=t.labels||[],t}const pn=new Map,mn=new Set;function xn(t,e){let i=pn.get(t);return i||(i=e(),pn.set(t,i),mn.add(i)),i}const bn=(t,e,i)=>{const s=M(e,i);void 0!==s&&t.add(s)};class _n{constructor(t){this._config=function(t){return(t=t||{}).data=gn(t.data),fn(t),t}(t),this._scopeCache=new Map,this._resolverCache=new Map}get platform(){return this._config.platform}get type(){return this._config.type}set type(t){this._config.type=t}get data(){return this._config.data}set data(t){this._config.data=gn(t)}get options(){return this._config.options}set options(t){this._config.options=t}get plugins(){return this._config.plugins}update(){const t=this._config;this.clearCache(),fn(t)}clearCache(){this._scopeCache.clear(),this._resolverCache.clear()}datasetScopeKeys(t){return xn(t,(()=>[[`datasets.${t}`,""]]))}datasetAnimationScopeKeys(t,e){return xn(`${t}.transition.${e}`,(()=>[[`datasets.${t}.transitions.${e}`,`transitions.${e}`],[`datasets.${t}`,""]]))}datasetElementScopeKeys(t,e){return xn(`${t}-${e}`,(()=>[[`datasets.${t}.elements.${e}`,`datasets.${t}`,`elements.${e}`,""]]))}pluginScopeKeys(t){const e=t.id;return xn(`${this.type}-plugin-${e}`,(()=>[[`plugins.${e}`,...t.additionalOptionScopes||[]]]))}_cachedScopes(t,e){const i=this._scopeCache;let s=i.get(t);return s&&!e||(s=new Map,i.set(t,s)),s}getOptionScopes(t,e,i){const{options:s,type:n}=this,o=this._cachedScopes(t,i),a=o.get(e);if(a)return a;const r=new Set;e.forEach((e=>{t&&(r.add(t),e.forEach((e=>bn(r,t,e)))),e.forEach((t=>bn(r,s,t))),e.forEach((t=>bn(r,re[n]||{},t))),e.forEach((t=>bn(r,ue,t))),e.forEach((t=>bn(r,le,t)))}));const l=Array.from(r);return 0===l.length&&l.push(Object.create(null)),mn.has(e)&&o.set(e,l),l}chartOptionScopes(){const{options:t,type:e}=this;return[t,re[e]||{},ue.datasets[e]||{},{type:e},ue,le]}resolveNamedOptions(t,e,i,s=[""]){const o={$shared:!0},{resolver:a,subPrefixes:r}=yn(this._resolverCache,t,s);let l=a;if(function(t,e){const{isScriptable:i,isIndexable:s}=Ye(t);for(const o of e){const e=i(o),a=s(o),r=(a||e)&&t[o];if(e&&(S(r)||vn(r))||a&&n(r))return!0}return!1}(a,e)){o.$shared=!1;l=$e(a,i=S(i)?i():i,this.createResolver(t,i,r))}for(const t of e)o[t]=l[t];return o}createResolver(t,e,i=[""],s){const{resolver:n}=yn(this._resolverCache,t,i);return o(e)?$e(n,e,void 0,s):n}}function yn(t,e,i){let s=t.get(e);s||(s=new Map,t.set(e,s));const n=i.join();let o=s.get(n);if(!o){o={resolver:je(e,i),subPrefixes:i.filter((t=>!t.toLowerCase().includes("hover")))},s.set(n,o)}return o}const vn=t=>o(t)&&Object.getOwnPropertyNames(t).some((e=>S(t[e])));const Mn=["top","bottom","left","right","chartArea"];function wn(t,e){return"top"===t||"bottom"===t||-1===Mn.indexOf(t)&&"x"===e}function kn(t,e){return function(i,s){return i[t]===s[t]?i[e]-s[e]:i[t]-s[t]}}function Sn(t){const e=t.chart,i=e.options.animation;e.notifyPlugins("afterRender"),d(i&&i.onComplete,[t],e)}function Pn(t){const e=t.chart,i=e.options.animation;d(i&&i.onProgress,[t],e)}function Dn(t){return fe()&&"string"==typeof t?t=document.getElementById(t):t&&t.length&&(t=t[0]),t&&t.canvas&&(t=t.canvas),t}const Cn={},On=t=>{const e=Dn(t);return Object.values(Cn).filter((t=>t.canvas===e)).pop()};function An(t,e,i){const s=Object.keys(t);for(const n of s){const s=+n;if(s>=e){const o=t[n];delete t[n],(i>0||s>e)&&(t[s+i]=o)}}}class Tn{static defaults=ue;static instances=Cn;static overrides=re;static registry=nn;static version="4.5.1";static getChart=On;static register(...t){nn.add(...t),Ln()}static unregister(...t){nn.remove(...t),Ln()}constructor(t,e){const s=this.config=new _n(e),n=Dn(t),o=On(n);if(o)throw new Error("Canvas is already in use. Chart with ID '"+o.id+"' must be destroyed before the canvas with ID '"+o.canvas.id+"' can be reused.");const a=s.createResolver(s.chartOptionScopes(),this.getContext());this.platform=new(s.platform||Ps(n)),this.platform.updateConfig(s);const r=this.platform.acquireContext(n,a.aspectRatio),l=r&&r.canvas,h=l&&l.height,c=l&&l.width;this.id=i(),this.ctx=r,this.canvas=l,this.width=c,this.height=h,this._options=a,this._aspectRatio=this.aspectRatio,this._layers=[],this._metasets=[],this._stacks=void 0,this.boxes=[],this.currentDevicePixelRatio=void 0,this.chartArea=void 0,this._active=[],this._lastEvent=void 0,this._listeners={},this._responsiveListeners=void 0,this._sortedMetasets=[],this.scales={},this._plugins=new on,this.$proxies={},this._hiddenIndices={},this.attached=!1,this._animationsDisabled=void 0,this.$context=void 0,this._doResize=dt((t=>this.update(t)),a.resizeDelay||0),this._dataChanges=[],Cn[this.id]=this,r&&l?(bt.listen(this,"complete",Sn),bt.listen(this,"progress",Pn),this._initialize(),this.attached&&this.update()):console.error("Failed to create chart: can't acquire context from the given item")}get aspectRatio(){const{options:{aspectRatio:t,maintainAspectRatio:e},width:i,height:n,_aspectRatio:o}=this;return s(t)?e&&o?o:n?i/n:null:t}get data(){return this.config.data}set data(t){this.config.data=t}get options(){return this._options}set options(t){this.config.options=t}get registry(){return nn}_initialize(){return this.notifyPlugins("beforeInit"),this.options.responsive?this.resize():ke(this,this.options.devicePixelRatio),this.bindEvents(),this.notifyPlugins("afterInit"),this}clear(){return Te(this.canvas,this.ctx),this}stop(){return bt.stop(this),this}resize(t,e){bt.running(this)?this._resizeBeforeDraw={width:t,height:e}:this._resize(t,e)}_resize(t,e){const i=this.options,s=this.canvas,n=i.maintainAspectRatio&&this.aspectRatio,o=this.platform.getMaximumSize(s,t,e,n),a=i.devicePixelRatio||this.platform.getDevicePixelRatio(),r=this.width?"resize":"attach";this.width=o.width,this.height=o.height,this._aspectRatio=this.aspectRatio,ke(this,a,!0)&&(this.notifyPlugins("resize",{size:o}),d(i.onResize,[this,o],this),this.attached&&this._doResize(r)&&this.render())}ensureScalesHaveIDs(){u(this.options.scales||{},((t,e)=>{t.id=e}))}buildOrUpdateScales(){const t=this.options,e=t.scales,i=this.scales,s=Object.keys(i).reduce(((t,e)=>(t[e]=!1,t)),{});let n=[];e&&(n=n.concat(Object.keys(e).map((t=>{const i=e[t],s=cn(t,i),n="r"===s,o="x"===s;return{options:i,dposition:n?"chartArea":o?"bottom":"left",dtype:n?"radialLinear":o?"category":"linear"}})))),u(n,(e=>{const n=e.options,o=n.id,a=cn(o,n),r=l(n.type,e.dtype);void 0!==n.position&&wn(n.position,a)===wn(e.dposition)||(n.position=e.dposition),s[o]=!0;let h=null;if(o in i&&i[o].type===r)h=i[o];else{h=new(nn.getScale(r))({id:o,type:r,ctx:this.ctx,chart:this}),i[h.id]=h}h.init(n,t)})),u(s,((t,e)=>{t||delete i[e]})),u(i,(t=>{ls.configure(this,t,t.options),ls.addBox(this,t)}))}_updateMetasets(){const t=this._metasets,e=this.data.datasets.length,i=t.length;if(t.sort(((t,e)=>t.index-e.index)),i>e){for(let t=e;t<i;++t)this._destroyDatasetMeta(t);t.splice(e,i-e)}this._sortedMetasets=t.slice(0).sort(kn("order","index"))}_removeUnreferencedMetasets(){const{_metasets:t,data:{datasets:e}}=this;t.length>e.length&&delete this._stacks,t.forEach(((t,i)=>{0===e.filter((e=>e===t._dataset)).length&&this._destroyDatasetMeta(i)}))}buildOrUpdateControllers(){const t=[],e=this.data.datasets;let i,s;for(this._removeUnreferencedMetasets(),i=0,s=e.length;i<s;i++){const s=e[i];let n=this.getDatasetMeta(i);const o=s.type||this.config.type;if(n.type&&n.type!==o&&(this._destroyDatasetMeta(i),n=this.getDatasetMeta(i)),n.type=o,n.indexAxis=s.indexAxis||ln(o,this.options),n.order=s.order||0,n.index=i,n.label=""+s.label,n.visible=this.isDatasetVisible(i),n.controller)n.controller.updateIndex(i),n.controller.linkScales();else{const e=nn.getController(o),{datasetElementType:s,dataElementType:a}=ue.datasets[o];Object.assign(e,{dataElementType:nn.getElement(a),datasetElementType:s&&nn.getElement(s)}),n.controller=new e(this,i),t.push(n.controller)}}return this._updateMetasets(),t}_resetElements(){u(this.data.datasets,((t,e)=>{this.getDatasetMeta(e).controller.reset()}),this)}reset(){this._resetElements(),this.notifyPlugins("reset")}update(t){const e=this.config;e.update();const i=this._options=e.createResolver(e.chartOptionScopes(),this.getContext()),s=this._animationsDisabled=!i.animation;if(this._updateScales(),this._checkEventBindings(),this._updateHiddenIndices(),this._plugins.invalidate(),!1===this.notifyPlugins("beforeUpdate",{mode:t,cancelable:!0}))return;const n=this.buildOrUpdateControllers();this.notifyPlugins("beforeElementsUpdate");let o=0;for(let t=0,e=this.data.datasets.length;t<e;t++){const{controller:e}=this.getDatasetMeta(t),i=!s&&-1===n.indexOf(e);e.buildOrUpdateElements(i),o=Math.max(+e.getMaxOverflow(),o)}o=this._minPadding=i.layout.autoPadding?o:0,this._updateLayout(o),s||u(n,(t=>{t.reset()})),this._updateDatasets(t),this.notifyPlugins("afterUpdate",{mode:t}),this._layers.sort(kn("z","_idx"));const{_active:a,_lastEvent:r}=this;r?this._eventHandler(r,!0):a.length&&this._updateHoverStyles(a,a,!0),this.render()}_updateScales(){u(this.scales,(t=>{ls.removeBox(this,t)})),this.ensureScalesHaveIDs(),this.buildOrUpdateScales()}_checkEventBindings(){const t=this.options,e=new Set(Object.keys(this._listeners)),i=new Set(t.events);P(e,i)&&!!this._responsiveListeners===t.responsive||(this.unbindEvents(),this.bindEvents())}_updateHiddenIndices(){const{_hiddenIndices:t}=this,e=this._getUniformDataChanges()||[];for(const{method:i,start:s,count:n}of e){An(t,s,"_removeElements"===i?-n:n)}}_getUniformDataChanges(){const t=this._dataChanges;if(!t||!t.length)return;this._dataChanges=[];const e=this.data.datasets.length,i=e=>new Set(t.filter((t=>t[0]===e)).map(((t,e)=>e+","+t.splice(1).join(",")))),s=i(0);for(let t=1;t<e;t++)if(!P(s,i(t)))return;return Array.from(s).map((t=>t.split(","))).map((t=>({method:t[1],start:+t[2],count:+t[3]})))}_updateLayout(t){if(!1===this.notifyPlugins("beforeLayout",{cancelable:!0}))return;ls.update(this,this.width,this.height,t);const e=this.chartArea,i=e.width<=0||e.height<=0;this._layers=[],u(this.boxes,(t=>{i&&"chartArea"===t.position||(t.configure&&t.configure(),this._layers.push(...t._layers()))}),this),this._layers.forEach(((t,e)=>{t._idx=e})),this.notifyPlugins("afterLayout")}_updateDatasets(t){if(!1!==this.notifyPlugins("beforeDatasetsUpdate",{mode:t,cancelable:!0})){for(let t=0,e=this.data.datasets.length;t<e;++t)this.getDatasetMeta(t).controller.configure();for(let e=0,i=this.data.datasets.length;e<i;++e)this._updateDataset(e,S(t)?t({datasetIndex:e}):t);this.notifyPlugins("afterDatasetsUpdate",{mode:t})}}_updateDataset(t,e){const i=this.getDatasetMeta(t),s={meta:i,index:t,mode:e,cancelable:!0};!1!==this.notifyPlugins("beforeDatasetUpdate",s)&&(i.controller._update(e),s.cancelable=!1,this.notifyPlugins("afterDatasetUpdate",s))}render(){!1!==this.notifyPlugins("beforeRender",{cancelable:!0})&&(bt.has(this)?this.attached&&!bt.running(this)&&bt.start(this):(this.draw(),Sn({chart:this})))}draw(){let t;if(this._resizeBeforeDraw){const{width:t,height:e}=this._resizeBeforeDraw;this._resizeBeforeDraw=null,this._resize(t,e)}if(this.clear(),this.width<=0||this.height<=0)return;if(!1===this.notifyPlugins("beforeDraw",{cancelable:!0}))return;const e=this._layers;for(t=0;t<e.length&&e[t].z<=0;++t)e[t].draw(this.chartArea);for(this._drawDatasets();t<e.length;++t)e[t].draw(this.chartArea);this.notifyPlugins("afterDraw")}_getSortedDatasetMetas(t){const e=this._sortedMetasets,i=[];let s,n;for(s=0,n=e.length;s<n;++s){const n=e[s];t&&!n.visible||i.push(n)}return i}getSortedVisibleDatasetMetas(){return this._getSortedDatasetMetas(!0)}_drawDatasets(){if(!1===this.notifyPlugins("beforeDatasetsDraw",{cancelable:!0}))return;const t=this.getSortedVisibleDatasetMetas();for(let e=t.length-1;e>=0;--e)this._drawDataset(t[e]);this.notifyPlugins("afterDatasetsDraw")}_drawDataset(t){const e=this.ctx,i={meta:t,index:t.index,cancelable:!0},s=Ni(this,t);!1!==this.notifyPlugins("beforeDatasetDraw",i)&&(s&&Ie(e,s),t.controller.draw(),s&&ze(e),i.cancelable=!1,this.notifyPlugins("afterDatasetDraw",i))}isPointInArea(t){return Re(t,this.chartArea,this._minPadding)}getElementsAtEventForMode(t,e,i,s){const n=Ki.modes[e];return"function"==typeof n?n(this,t,i,s):[]}getDatasetMeta(t){const e=this.data.datasets[t],i=this._metasets;let s=i.filter((t=>t&&t._dataset===e)).pop();return s||(s={type:null,data:[],dataset:null,controller:null,hidden:null,xAxisID:null,yAxisID:null,order:e&&e.order||0,index:t,_dataset:e,_parsed:[],_sorted:!1},i.push(s)),s}getContext(){return this.$context||(this.$context=Ci(null,{chart:this,type:"chart"}))}getVisibleDatasetCount(){return this.getSortedVisibleDatasetMetas().length}isDatasetVisible(t){const e=this.data.datasets[t];if(!e)return!1;const i=this.getDatasetMeta(t);return"boolean"==typeof i.hidden?!i.hidden:!e.hidden}setDatasetVisibility(t,e){this.getDatasetMeta(t).hidden=!e}toggleDataVisibility(t){this._hiddenIndices[t]=!this._hiddenIndices[t]}getDataVisibility(t){return!this._hiddenIndices[t]}_updateVisibility(t,e,i){const s=i?"show":"hide",n=this.getDatasetMeta(t),o=n.controller._resolveAnimations(void 0,s);k(e)?(n.data[e].hidden=!i,this.update()):(this.setDatasetVisibility(t,i),o.update(n,{visible:i}),this.update((e=>e.datasetIndex===t?s:void 0)))}hide(t,e){this._updateVisibility(t,e,!1)}show(t,e){this._updateVisibility(t,e,!0)}_destroyDatasetMeta(t){const e=this._metasets[t];e&&e.controller&&e.controller._destroy(),delete this._metasets[t]}_stop(){let t,e;for(this.stop(),bt.remove(this),t=0,e=this.data.datasets.length;t<e;++t)this._destroyDatasetMeta(t)}destroy(){this.notifyPlugins("beforeDestroy");const{canvas:t,ctx:e}=this;this._stop(),this.config.clearCache(),t&&(this.unbindEvents(),Te(t,e),this.platform.releaseContext(e),this.canvas=null,this.ctx=null),delete Cn[this.id],this.notifyPlugins("afterDestroy")}toBase64Image(...t){return this.canvas.toDataURL(...t)}bindEvents(){this.bindUserEvents(),this.options.responsive?this.bindResponsiveEvents():this.attached=!0}bindUserEvents(){const t=this._listeners,e=this.platform,i=(i,s)=>{e.addEventListener(this,i,s),t[i]=s},s=(t,e,i)=>{t.offsetX=e,t.offsetY=i,this._eventHandler(t)};u(this.options.events,(t=>i(t,s)))}bindResponsiveEvents(){this._responsiveListeners||(this._responsiveListeners={});const t=this._responsiveListeners,e=this.platform,i=(i,s)=>{e.addEventListener(this,i,s),t[i]=s},s=(i,s)=>{t[i]&&(e.removeEventListener(this,i,s),delete t[i])},n=(t,e)=>{this.canvas&&this.resize(t,e)};let o;const a=()=>{s("attach",a),this.attached=!0,this.resize(),i("resize",n),i("detach",o)};o=()=>{this.attached=!1,s("resize",n),this._stop(),this._resize(0,0),i("attach",a)},e.isAttached(this.canvas)?a():o()}unbindEvents(){u(this._listeners,((t,e)=>{this.platform.removeEventListener(this,e,t)})),this._listeners={},u(this._responsiveListeners,((t,e)=>{this.platform.removeEventListener(this,e,t)})),this._responsiveListeners=void 0}updateHoverStyle(t,e,i){const s=i?"set":"remove";let n,o,a,r;for("dataset"===e&&(n=this.getDatasetMeta(t[0].datasetIndex),n.controller["_"+s+"DatasetHoverStyle"]()),a=0,r=t.length;a<r;++a){o=t[a];const e=o&&this.getDatasetMeta(o.datasetIndex).controller;e&&e[s+"HoverStyle"](o.element,o.datasetIndex,o.index)}}getActiveElements(){return this._active||[]}setActiveElements(t){const e=this._active||[],i=t.map((({datasetIndex:t,index:e})=>{const i=this.getDatasetMeta(t);if(!i)throw new Error("No dataset found at index "+t);return{datasetIndex:t,element:i.data[e],index:e}}));!f(i,e)&&(this._active=i,this._lastEvent=null,this._updateHoverStyles(i,e))}notifyPlugins(t,e,i){return this._plugins.notify(this,t,e,i)}isPluginEnabled(t){return 1===this._plugins._cache.filter((e=>e.plugin.id===t)).length}_updateHoverStyles(t,e,i){const s=this.options.hover,n=(t,e)=>t.filter((t=>!e.some((e=>t.datasetIndex===e.datasetIndex&&t.index===e.index)))),o=n(e,t),a=i?t:n(t,e);o.length&&this.updateHoverStyle(o,s.mode,!1),a.length&&s.mode&&this.updateHoverStyle(a,s.mode,!0)}_eventHandler(t,e){const i={event:t,replay:e,cancelable:!0,inChartArea:this.isPointInArea(t)},s=e=>(e.options.events||this.options.events).includes(t.native.type);if(!1===this.notifyPlugins("beforeEvent",i,s))return;const n=this._handleEvent(t,e,i.inChartArea);return i.cancelable=!1,this.notifyPlugins("afterEvent",i,s),(n||i.changed)&&this.render(),this}_handleEvent(t,e,i){const{_active:s=[],options:n}=this,o=e,a=this._getActiveElements(t,s,i,o),r=D(t),l=function(t,e,i,s){return i&&"mouseout"!==t.type?s?e:t:null}(t,this._lastEvent,i,r);i&&(this._lastEvent=null,d(n.onHover,[t,a,this],this),r&&d(n.onClick,[t,a,this],this));const h=!f(a,s);return(h||e)&&(this._active=a,this._updateHoverStyles(a,s,e)),this._lastEvent=l,h}_getActiveElements(t,e,i,s){if("mouseout"===t.type)return[];if(!i)return e;const n=this.options.hover;return this.getElementsAtEventForMode(t,n.mode,n,s)}}function Ln(){return u(Tn.instances,(t=>t._plugins.invalidate()))}function En(){throw new Error("This method is not implemented: Check that a complete date adapter is provided.")}class Rn{static override(t){Object.assign(Rn.prototype,t)}options;constructor(t){this.options=t||{}}init(){}formats(){return En()}parse(){return En()}format(){return En()}add(){return En()}diff(){return En()}startOf(){return En()}endOf(){return En()}}var In={_date:Rn};function zn(t){const e=t.iScale,i=function(t,e){if(!t._cache.$bar){const i=t.getMatchingVisibleMetas(e);let s=[];for(let e=0,n=i.length;e<n;e++)s=s.concat(i[e].controller.getAllParsedValues(t));t._cache.$bar=lt(s.sort(((t,e)=>t-e)))}return t._cache.$bar}(e,t.type);let s,n,o,a,r=e._length;const l=()=>{32767!==o&&-32768!==o&&(k(a)&&(r=Math.min(r,Math.abs(o-a)||r)),a=o)};for(s=0,n=i.length;s<n;++s)o=e.getPixelForValue(i[s]),l();for(a=void 0,s=0,n=e.ticks.length;s<n;++s)o=e.getPixelForTick(s),l();return r}function Fn(t,e,i,s){return n(t)?function(t,e,i,s){const n=i.parse(t[0],s),o=i.parse(t[1],s),a=Math.min(n,o),r=Math.max(n,o);let l=a,h=r;Math.abs(a)>Math.abs(r)&&(l=r,h=a),e[i.axis]=h,e._custom={barStart:l,barEnd:h,start:n,end:o,min:a,max:r}}(t,e,i,s):e[i.axis]=i.parse(t,s),e}function Vn(t,e,i,s){const n=t.iScale,o=t.vScale,a=n.getLabels(),r=n===o,l=[];let h,c,d,u;for(h=i,c=i+s;h<c;++h)u=e[h],d={},d[n.axis]=r||n.parse(a[h],h),l.push(Fn(u,d,o,h));return l}function Bn(t){return t&&void 0!==t.barStart&&void 0!==t.barEnd}function Wn(t,e,i,s){let n=e.borderSkipped;const o={};if(!n)return void(t.borderSkipped=o);if(!0===n)return void(t.borderSkipped={top:!0,right:!0,bottom:!0,left:!0});const{start:a,end:r,reverse:l,top:h,bottom:c}=function(t){let e,i,s,n,o;return t.horizontal?(e=t.base>t.x,i="left",s="right"):(e=t.base<t.y,i="bottom",s="top"),e?(n="end",o="start"):(n="start",o="end"),{start:i,end:s,reverse:e,top:n,bottom:o}}(t);"middle"===n&&i&&(t.enableBorderRadius=!0,(i._top||0)===s?n=h:(i._bottom||0)===s?n=c:(o[Nn(c,a,r,l)]=!0,n=h)),o[Nn(n,a,r,l)]=!0,t.borderSkipped=o}function Nn(t,e,i,s){var n,o,a;return s?(a=i,t=Hn(t=(n=t)===(o=e)?a:n===a?o:n,i,e)):t=Hn(t,e,i),t}function Hn(t,e,i){return"start"===t?e:"end"===t?i:t}function jn(t,{inflateAmount:e},i){t.inflateAmount="auto"===e?1===i?.33:0:e}class $n extends js{static id="doughnut";static defaults={datasetElementType:!1,dataElementType:"arc",animation:{animateRotate:!0,animateScale:!1},animations:{numbers:{type:"number",properties:["circumference","endAngle","innerRadius","outerRadius","startAngle","x","y","offset","borderWidth","spacing"]}},cutout:"50%",rotation:0,circumference:360,radius:"100%",spacing:0,indexAxis:"r"};static descriptors={_scriptable:t=>"spacing"!==t,_indexable:t=>"spacing"!==t&&!t.startsWith("borderDash")&&!t.startsWith("hoverBorderDash")};static overrides={aspectRatio:1,plugins:{legend:{labels:{generateLabels(t){const e=t.data,{labels:{pointStyle:i,textAlign:s,color:n,useBorderRadius:o,borderRadius:a}}=t.legend.options;return e.labels.length&&e.datasets.length?e.labels.map(((e,r)=>{const l=t.getDatasetMeta(0).controller.getStyle(r);return{text:e,fillStyle:l.backgroundColor,fontColor:n,hidden:!t.getDataVisibility(r),lineDash:l.borderDash,lineDashOffset:l.borderDashOffset,lineJoin:l.borderJoinStyle,lineWidth:l.borderWidth,strokeStyle:l.borderColor,textAlign:s,pointStyle:i,borderRadius:o&&(a||l.borderRadius),index:r}})):[]}},onClick(t,e,i){i.chart.toggleDataVisibility(e.index),i.chart.update()}}}};constructor(t,e){super(t,e),this.enableOptionSharing=!0,this.innerRadius=void 0,this.outerRadius=void 0,this.offsetX=void 0,this.offsetY=void 0}linkScales(){}parse(t,e){const i=this.getDataset().data,s=this._cachedMeta;if(!1===this._parsing)s._parsed=i;else{let n,a,r=t=>+i[t];if(o(i[t])){const{key:t="value"}=this._parsing;r=e=>+M(i[e],t)}for(n=t,a=t+e;n<a;++n)s._parsed[n]=r(n)}}_getRotation(){return $(this.options.rotation-90)}_getCircumference(){return $(this.options.circumference)}_getRotationExtents(){let t=O,e=-O;for(let i=0;i<this.chart.data.datasets.length;++i)if(this.chart.isDatasetVisible(i)&&this.chart.getDatasetMeta(i).type===this._type){const s=this.chart.getDatasetMeta(i).controller,n=s._getRotation(),o=s._getCircumference();t=Math.min(t,n),e=Math.max(e,n+o)}return{rotation:t,circumference:e-t}}update(t){const e=this.chart,{chartArea:i}=e,s=this._cachedMeta,n=s.data,o=this.getMaxBorderWidth()+this.getMaxOffset(n)+this.options.spacing,a=Math.max((Math.min(i.width,i.height)-o)/2,0),r=Math.min(h(this.options.cutout,a),1),l=this._getRingWeight(this.index),{circumference:d,rotation:u}=this._getRotationExtents(),{ratioX:f,ratioY:g,offsetX:p,offsetY:m}=function(t,e,i){let s=1,n=1,o=0,a=0;if(e<O){const r=t,l=r+e,h=Math.cos(r),c=Math.sin(r),d=Math.cos(l),u=Math.sin(l),f=(t,e,s)=>J(t,r,l,!0)?1:Math.max(e,e*i,s,s*i),g=(t,e,s)=>J(t,r,l,!0)?-1:Math.min(e,e*i,s,s*i),p=f(0,h,d),m=f(E,c,u),x=g(C,h,d),b=g(C+E,c,u);s=(p-x)/2,n=(m-b)/2,o=-(p+x)/2,a=-(m+b)/2}return{ratioX:s,ratioY:n,offsetX:o,offsetY:a}}(u,d,r),x=(i.width-o)/f,b=(i.height-o)/g,_=Math.max(Math.min(x,b)/2,0),y=c(this.options.radius,_),v=(y-Math.max(y*r,0))/this._getVisibleDatasetWeightTotal();this.offsetX=p*y,this.offsetY=m*y,s.total=this.calculateTotal(),this.outerRadius=y-v*this._getRingWeightOffset(this.index),this.innerRadius=Math.max(this.outerRadius-v*l,0),this.updateElements(n,0,n.length,t)}_circumference(t,e){const i=this.options,s=this._cachedMeta,n=this._getCircumference();return e&&i.animation.animateRotate||!this.chart.getDataVisibility(t)||null===s._parsed[t]||s.data[t].hidden?0:this.calculateCircumference(s._parsed[t]*n/O)}updateElements(t,e,i,s){const n="reset"===s,o=this.chart,a=o.chartArea,r=o.options.animation,l=(a.left+a.right)/2,h=(a.top+a.bottom)/2,c=n&&r.animateScale,d=c?0:this.innerRadius,u=c?0:this.outerRadius,{sharedOptions:f,includeOptions:g}=this._getSharedOptions(e,s);let p,m=this._getRotation();for(p=0;p<e;++p)m+=this._circumference(p,n);for(p=e;p<e+i;++p){const e=this._circumference(p,n),i=t[p],o={x:l+this.offsetX,y:h+this.offsetY,startAngle:m,endAngle:m+e,circumference:e,outerRadius:u,innerRadius:d};g&&(o.options=f||this.resolveDataElementOptions(p,i.active?"active":s)),m+=e,this.updateElement(i,p,o,s)}}calculateTotal(){const t=this._cachedMeta,e=t.data;let i,s=0;for(i=0;i<e.length;i++){const n=t._parsed[i];null===n||isNaN(n)||!this.chart.getDataVisibility(i)||e[i].hidden||(s+=Math.abs(n))}return s}calculateCircumference(t){const e=this._cachedMeta.total;return e>0&&!isNaN(t)?O*(Math.abs(t)/e):0}getLabelAndValue(t){const e=this._cachedMeta,i=this.chart,s=i.data.labels||[],n=ne(e._parsed[t],i.options.locale);return{label:s[t]||"",value:n}}getMaxBorderWidth(t){let e=0;const i=this.chart;let s,n,o,a,r;if(!t)for(s=0,n=i.data.datasets.length;s<n;++s)if(i.isDatasetVisible(s)){o=i.getDatasetMeta(s),t=o.data,a=o.controller;break}if(!t)return 0;for(s=0,n=t.length;s<n;++s)r=a.resolveDataElementOptions(s),"inner"!==r.borderAlign&&(e=Math.max(e,r.borderWidth||0,r.hoverBorderWidth||0));return e}getMaxOffset(t){let e=0;for(let i=0,s=t.length;i<s;++i){const t=this.resolveDataElementOptions(i);e=Math.max(e,t.offset||0,t.hoverOffset||0)}return e}_getRingWeightOffset(t){let e=0;for(let i=0;i<t;++i)this.chart.isDatasetVisible(i)&&(e+=this._getRingWeight(i));return e}_getRingWeight(t){return Math.max(l(this.chart.data.datasets[t].weight,1),0)}_getVisibleDatasetWeightTotal(){return this._getRingWeightOffset(this.chart.data.datasets.length)||1}}class Yn extends js{static id="polarArea";static defaults={dataElementType:"arc",animation:{animateRotate:!0,animateScale:!0},animations:{numbers:{type:"number",properties:["x","y","startAngle","endAngle","innerRadius","outerRadius"]}},indexAxis:"r",startAngle:0};static overrides={aspectRatio:1,plugins:{legend:{labels:{generateLabels(t){const e=t.data;if(e.labels.length&&e.datasets.length){const{labels:{pointStyle:i,color:s}}=t.legend.options;return e.labels.map(((e,n)=>{const o=t.getDatasetMeta(0).controller.getStyle(n);return{text:e,fillStyle:o.backgroundColor,strokeStyle:o.borderColor,fontColor:s,lineWidth:o.borderWidth,pointStyle:i,hidden:!t.getDataVisibility(n),index:n}}))}return[]}},onClick(t,e,i){i.chart.toggleDataVisibility(e.index),i.chart.update()}}},scales:{r:{type:"radialLinear",angleLines:{display:!1},beginAtZero:!0,grid:{circular:!0},pointLabels:{display:!1},startAngle:0}}};constructor(t,e){super(t,e),this.innerRadius=void 0,this.outerRadius=void 0}getLabelAndValue(t){const e=this._cachedMeta,i=this.chart,s=i.data.labels||[],n=ne(e._parsed[t].r,i.options.locale);return{label:s[t]||"",value:n}}parseObjectData(t,e,i,s){return ii.bind(this)(t,e,i,s)}update(t){const e=this._cachedMeta.data;this._updateRadius(),this.updateElements(e,0,e.length,t)}getMinMax(){const t=this._cachedMeta,e={min:Number.POSITIVE_INFINITY,max:Number.NEGATIVE_INFINITY};return t.data.forEach(((t,i)=>{const s=this.getParsed(i).r;!isNaN(s)&&this.chart.getDataVisibility(i)&&(s<e.min&&(e.min=s),s>e.max&&(e.max=s))})),e}_updateRadius(){const t=this.chart,e=t.chartArea,i=t.options,s=Math.min(e.right-e.left,e.bottom-e.top),n=Math.max(s/2,0),o=(n-Math.max(i.cutoutPercentage?n/100*i.cutoutPercentage:1,0))/t.getVisibleDatasetCount();this.outerRadius=n-o*this.index,this.innerRadius=this.outerRadius-o}updateElements(t,e,i,s){const n="reset"===s,o=this.chart,a=o.options.animation,r=this._cachedMeta.rScale,l=r.xCenter,h=r.yCenter,c=r.getIndexAngle(0)-.5*C;let d,u=c;const f=360/this.countVisibleElements();for(d=0;d<e;++d)u+=this._computeAngle(d,s,f);for(d=e;d<e+i;d++){const e=t[d];let i=u,g=u+this._computeAngle(d,s,f),p=o.getDataVisibility(d)?r.getDistanceFromCenterForValue(this.getParsed(d).r):0;u=g,n&&(a.animateScale&&(p=0),a.animateRotate&&(i=g=c));const m={x:l,y:h,innerRadius:0,outerRadius:p,startAngle:i,endAngle:g,options:this.resolveDataElementOptions(d,e.active?"active":s)};this.updateElement(e,d,m,s)}}countVisibleElements(){const t=this._cachedMeta;let e=0;return t.data.forEach(((t,i)=>{!isNaN(this.getParsed(i).r)&&this.chart.getDataVisibility(i)&&e++})),e}_computeAngle(t,e,i){return this.chart.getDataVisibility(t)?$(this.resolveDataElementOptions(t,e).angle||i):0}}var Un=Object.freeze({__proto__:null,BarController:class extends js{static id="bar";static defaults={datasetElementType:!1,dataElementType:"bar",categoryPercentage:.8,barPercentage:.9,grouped:!0,animations:{numbers:{type:"number",properties:["x","y","base","width","height"]}}};static overrides={scales:{_index_:{type:"category",offset:!0,grid:{offset:!0}},_value_:{type:"linear",beginAtZero:!0}}};parsePrimitiveData(t,e,i,s){return Vn(t,e,i,s)}parseArrayData(t,e,i,s){return Vn(t,e,i,s)}parseObjectData(t,e,i,s){const{iScale:n,vScale:o}=t,{xAxisKey:a="x",yAxisKey:r="y"}=this._parsing,l="x"===n.axis?a:r,h="x"===o.axis?a:r,c=[];let d,u,f,g;for(d=i,u=i+s;d<u;++d)g=e[d],f={},f[n.axis]=n.parse(M(g,l),d),c.push(Fn(M(g,h),f,o,d));return c}updateRangeFromParsed(t,e,i,s){super.updateRangeFromParsed(t,e,i,s);const n=i._custom;n&&e===this._cachedMeta.vScale&&(t.min=Math.min(t.min,n.min),t.max=Math.max(t.max,n.max))}getMaxOverflow(){return 0}getLabelAndValue(t){const e=this._cachedMeta,{iScale:i,vScale:s}=e,n=this.getParsed(t),o=n._custom,a=Bn(o)?"["+o.start+", "+o.end+"]":""+s.getLabelForValue(n[s.axis]);return{label:""+i.getLabelForValue(n[i.axis]),value:a}}initialize(){this.enableOptionSharing=!0,super.initialize();this._cachedMeta.stack=this.getDataset().stack}update(t){const e=this._cachedMeta;this.updateElements(e.data,0,e.data.length,t)}updateElements(t,e,i,n){const o="reset"===n,{index:a,_cachedMeta:{vScale:r}}=this,l=r.getBasePixel(),h=r.isHorizontal(),c=this._getRuler(),{sharedOptions:d,includeOptions:u}=this._getSharedOptions(e,n);for(let f=e;f<e+i;f++){const e=this.getParsed(f),i=o||s(e[r.axis])?{base:l,head:l}:this._calculateBarValuePixels(f),g=this._calculateBarIndexPixels(f,c),p=(e._stacks||{})[r.axis],m={horizontal:h,base:i.base,enableBorderRadius:!p||Bn(e._custom)||a===p._top||a===p._bottom,x:h?i.head:g.center,y:h?g.center:i.head,height:h?g.size:Math.abs(i.size),width:h?Math.abs(i.size):g.size};u&&(m.options=d||this.resolveDataElementOptions(f,t[f].active?"active":n));const x=m.options||t[f].options;Wn(m,x,p,a),jn(m,x,c.ratio),this.updateElement(t[f],f,m,n)}}_getStacks(t,e){const{iScale:i}=this._cachedMeta,n=i.getMatchingVisibleMetas(this._type).filter((t=>t.controller.options.grouped)),o=i.options.stacked,a=[],r=this._cachedMeta.controller.getParsed(e),l=r&&r[i.axis],h=t=>{const e=t._parsed.find((t=>t[i.axis]===l)),n=e&&e[t.vScale.axis];if(s(n)||isNaN(n))return!0};for(const i of n)if((void 0===e||!h(i))&&((!1===o||-1===a.indexOf(i.stack)||void 0===o&&void 0===i.stack)&&a.push(i.stack),i.index===t))break;return a.length||a.push(void 0),a}_getStackCount(t){return this._getStacks(void 0,t).length}_getAxisCount(){return this._getAxis().length}getFirstScaleIdForIndexAxis(){const t=this.chart.scales,e=this.chart.options.indexAxis;return Object.keys(t).filter((i=>t[i].axis===e)).shift()}_getAxis(){const t={},e=this.getFirstScaleIdForIndexAxis();for(const i of this.chart.data.datasets)t[l("x"===this.chart.options.indexAxis?i.xAxisID:i.yAxisID,e)]=!0;return Object.keys(t)}_getStackIndex(t,e,i){const s=this._getStacks(t,i),n=void 0!==e?s.indexOf(e):-1;return-1===n?s.length-1:n}_getRuler(){const t=this.options,e=this._cachedMeta,i=e.iScale,s=[];let n,o;for(n=0,o=e.data.length;n<o;++n)s.push(i.getPixelForValue(this.getParsed(n)[i.axis],n));const a=t.barThickness;return{min:a||zn(e),pixels:s,start:i._startPixel,end:i._endPixel,stackCount:this._getStackCount(),scale:i,grouped:t.grouped,ratio:a?1:t.categoryPercentage*t.barPercentage}}_calculateBarValuePixels(t){const{_cachedMeta:{vScale:e,_stacked:i,index:n},options:{base:o,minBarLength:a}}=this,r=o||0,l=this.getParsed(t),h=l._custom,c=Bn(h);let d,u,f=l[e.axis],g=0,p=i?this.applyStack(e,l,i):f;p!==f&&(g=p-f,p=f),c&&(f=h.barStart,p=h.barEnd-h.barStart,0!==f&&F(f)!==F(h.barEnd)&&(g=0),g+=f);const m=s(o)||c?g:o;let x=e.getPixelForValue(m);if(d=this.chart.getDataVisibility(t)?e.getPixelForValue(g+p):x,u=d-x,Math.abs(u)<a){u=function(t,e,i){return 0!==t?F(t):(e.isHorizontal()?1:-1)*(e.min>=i?1:-1)}(u,e,r)*a,f===r&&(x-=u/2);const t=e.getPixelForDecimal(0),s=e.getPixelForDecimal(1),o=Math.min(t,s),h=Math.max(t,s);x=Math.max(Math.min(x,h),o),d=x+u,i&&!c&&(l._stacks[e.axis]._visualValues[n]=e.getValueForPixel(d)-e.getValueForPixel(x))}if(x===e.getPixelForValue(r)){const t=F(u)*e.getLineWidthForValue(r)/2;x+=t,u-=t}return{size:u,base:x,head:d,center:d+u/2}}_calculateBarIndexPixels(t,e){const i=e.scale,n=this.options,o=n.skipNull,a=l(n.maxBarThickness,1/0);let r,h;const c=this._getAxisCount();if(e.grouped){const i=o?this._getStackCount(t):e.stackCount,d="flex"===n.barThickness?function(t,e,i,s){const n=e.pixels,o=n[t];let a=t>0?n[t-1]:null,r=t<n.length-1?n[t+1]:null;const l=i.categoryPercentage;null===a&&(a=o-(null===r?e.end-e.start:r-o)),null===r&&(r=o+o-a);const h=o-(o-Math.min(a,r))/2*l;return{chunk:Math.abs(r-a)/2*l/s,ratio:i.barPercentage,start:h}}(t,e,n,i*c):function(t,e,i,n){const o=i.barThickness;let a,r;return s(o)?(a=e.min*i.categoryPercentage,r=i.barPercentage):(a=o*n,r=1),{chunk:a/n,ratio:r,start:e.pixels[t]-a/2}}(t,e,n,i*c),u="x"===this.chart.options.indexAxis?this.getDataset().xAxisID:this.getDataset().yAxisID,f=this._getAxis().indexOf(l(u,this.getFirstScaleIdForIndexAxis())),g=this._getStackIndex(this.index,this._cachedMeta.stack,o?t:void 0)+f;r=d.start+d.chunk*g+d.chunk/2,h=Math.min(a,d.chunk*d.ratio)}else r=i.getPixelForValue(this.getParsed(t)[i.axis],t),h=Math.min(a,e.min*e.ratio);return{base:r-h/2,head:r+h/2,center:r,size:h}}draw(){const t=this._cachedMeta,e=t.vScale,i=t.data,s=i.length;let n=0;for(;n<s;++n)null===this.getParsed(n)[e.axis]||i[n].hidden||i[n].draw(this._ctx)}},BubbleController:class extends js{static id="bubble";static defaults={datasetElementType:!1,dataElementType:"point",animations:{numbers:{type:"number",properties:["x","y","borderWidth","radius"]}}};static overrides={scales:{x:{type:"linear"},y:{type:"linear"}}};initialize(){this.enableOptionSharing=!0,super.initialize()}parsePrimitiveData(t,e,i,s){const n=super.parsePrimitiveData(t,e,i,s);for(let t=0;t<n.length;t++)n[t]._custom=this.resolveDataElementOptions(t+i).radius;return n}parseArrayData(t,e,i,s){const n=super.parseArrayData(t,e,i,s);for(let t=0;t<n.length;t++){const s=e[i+t];n[t]._custom=l(s[2],this.resolveDataElementOptions(t+i).radius)}return n}parseObjectData(t,e,i,s){const n=super.parseObjectData(t,e,i,s);for(let t=0;t<n.length;t++){const s=e[i+t];n[t]._custom=l(s&&s.r&&+s.r,this.resolveDataElementOptions(t+i).radius)}return n}getMaxOverflow(){const t=this._cachedMeta.data;let e=0;for(let i=t.length-1;i>=0;--i)e=Math.max(e,t[i].size(this.resolveDataElementOptions(i))/2);return e>0&&e}getLabelAndValue(t){const e=this._cachedMeta,i=this.chart.data.labels||[],{xScale:s,yScale:n}=e,o=this.getParsed(t),a=s.getLabelForValue(o.x),r=n.getLabelForValue(o.y),l=o._custom;return{label:i[t]||"",value:"("+a+", "+r+(l?", "+l:"")+")"}}update(t){const e=this._cachedMeta.data;this.updateElements(e,0,e.length,t)}updateElements(t,e,i,s){const n="reset"===s,{iScale:o,vScale:a}=this._cachedMeta,{sharedOptions:r,includeOptions:l}=this._getSharedOptions(e,s),h=o.axis,c=a.axis;for(let d=e;d<e+i;d++){const e=t[d],i=!n&&this.getParsed(d),u={},f=u[h]=n?o.getPixelForDecimal(.5):o.getPixelForValue(i[h]),g=u[c]=n?a.getBasePixel():a.getPixelForValue(i[c]);u.skip=isNaN(f)||isNaN(g),l&&(u.options=r||this.resolveDataElementOptions(d,e.active?"active":s),n&&(u.options.radius=0)),this.updateElement(e,d,u,s)}}resolveDataElementOptions(t,e){const i=this.getParsed(t);let s=super.resolveDataElementOptions(t,e);s.$shared&&(s=Object.assign({},s,{$shared:!1}));const n=s.radius;return"active"!==e&&(s.radius=0),s.radius+=l(i&&i._custom,n),s}},DoughnutController:$n,LineController:class extends js{static id="line";static defaults={datasetElementType:"line",dataElementType:"point",showLine:!0,spanGaps:!1};static overrides={scales:{_index_:{type:"category"},_value_:{type:"linear"}}};initialize(){this.enableOptionSharing=!0,this.supportsDecimation=!0,super.initialize()}update(t){const e=this._cachedMeta,{dataset:i,data:s=[],_dataset:n}=e,o=this.chart._animationsDisabled;let{start:a,count:r}=pt(e,s,o);this._drawStart=a,this._drawCount=r,mt(e)&&(a=0,r=s.length),i._chart=this.chart,i._datasetIndex=this.index,i._decimated=!!n._decimated,i.points=s;const l=this.resolveDatasetElementOptions(t);this.options.showLine||(l.borderWidth=0),l.segment=this.options.segment,this.updateElement(i,void 0,{animated:!o,options:l},t),this.updateElements(s,a,r,t)}updateElements(t,e,i,n){const o="reset"===n,{iScale:a,vScale:r,_stacked:l,_dataset:h}=this._cachedMeta,{sharedOptions:c,includeOptions:d}=this._getSharedOptions(e,n),u=a.axis,f=r.axis,{spanGaps:g,segment:p}=this.options,m=N(g)?g:Number.POSITIVE_INFINITY,x=this.chart._animationsDisabled||o||"none"===n,b=e+i,_=t.length;let y=e>0&&this.getParsed(e-1);for(let i=0;i<_;++i){const g=t[i],_=x?g:{};if(i<e||i>=b){_.skip=!0;continue}const v=this.getParsed(i),M=s(v[f]),w=_[u]=a.getPixelForValue(v[u],i),k=_[f]=o||M?r.getBasePixel():r.getPixelForValue(l?this.applyStack(r,v,l):v[f],i);_.skip=isNaN(w)||isNaN(k)||M,_.stop=i>0&&Math.abs(v[u]-y[u])>m,p&&(_.parsed=v,_.raw=h.data[i]),d&&(_.options=c||this.resolveDataElementOptions(i,g.active?"active":n)),x||this.updateElement(g,i,_,n),y=v}}getMaxOverflow(){const t=this._cachedMeta,e=t.dataset,i=e.options&&e.options.borderWidth||0,s=t.data||[];if(!s.length)return i;const n=s[0].size(this.resolveDataElementOptions(0)),o=s[s.length-1].size(this.resolveDataElementOptions(s.length-1));return Math.max(i,n,o)/2}draw(){const t=this._cachedMeta;t.dataset.updateControlPoints(this.chart.chartArea,t.iScale.axis),super.draw()}},PieController:class extends $n{static id="pie";static defaults={cutout:0,rotation:0,circumference:360,radius:"100%"}},PolarAreaController:Yn,RadarController:class extends js{static id="radar";static defaults={datasetElementType:"line",dataElementType:"point",indexAxis:"r",showLine:!0,elements:{line:{fill:"start"}}};static overrides={aspectRatio:1,scales:{r:{type:"radialLinear"}}};getLabelAndValue(t){const e=this._cachedMeta.vScale,i=this.getParsed(t);return{label:e.getLabels()[t],value:""+e.getLabelForValue(i[e.axis])}}parseObjectData(t,e,i,s){return ii.bind(this)(t,e,i,s)}update(t){const e=this._cachedMeta,i=e.dataset,s=e.data||[],n=e.iScale.getLabels();if(i.points=s,"resize"!==t){const e=this.resolveDatasetElementOptions(t);this.options.showLine||(e.borderWidth=0);const o={_loop:!0,_fullLoop:n.length===s.length,options:e};this.updateElement(i,void 0,o,t)}this.updateElements(s,0,s.length,t)}updateElements(t,e,i,s){const n=this._cachedMeta.rScale,o="reset"===s;for(let a=e;a<e+i;a++){const e=t[a],i=this.resolveDataElementOptions(a,e.active?"active":s),r=n.getPointPositionForValue(a,this.getParsed(a).r),l=o?n.xCenter:r.x,h=o?n.yCenter:r.y,c={x:l,y:h,angle:r.angle,skip:isNaN(l)||isNaN(h),options:i};this.updateElement(e,a,c,s)}}},ScatterController:class extends js{static id="scatter";static defaults={datasetElementType:!1,dataElementType:"point",showLine:!1,fill:!1};static overrides={interaction:{mode:"point"},scales:{x:{type:"linear"},y:{type:"linear"}}};getLabelAndValue(t){const e=this._cachedMeta,i=this.chart.data.labels||[],{xScale:s,yScale:n}=e,o=this.getParsed(t),a=s.getLabelForValue(o.x),r=n.getLabelForValue(o.y);return{label:i[t]||"",value:"("+a+", "+r+")"}}update(t){const e=this._cachedMeta,{data:i=[]}=e,s=this.chart._animationsDisabled;let{start:n,count:o}=pt(e,i,s);if(this._drawStart=n,this._drawCount=o,mt(e)&&(n=0,o=i.length),this.options.showLine){this.datasetElementType||this.addElements();const{dataset:n,_dataset:o}=e;n._chart=this.chart,n._datasetIndex=this.index,n._decimated=!!o._decimated,n.points=i;const a=this.resolveDatasetElementOptions(t);a.segment=this.options.segment,this.updateElement(n,void 0,{animated:!s,options:a},t)}else this.datasetElementType&&(delete e.dataset,this.datasetElementType=!1);this.updateElements(i,n,o,t)}addElements(){const{showLine:t}=this.options;!this.datasetElementType&&t&&(this.datasetElementType=this.chart.registry.getElement("line")),super.addElements()}updateElements(t,e,i,n){const o="reset"===n,{iScale:a,vScale:r,_stacked:l,_dataset:h}=this._cachedMeta,c=this.resolveDataElementOptions(e,n),d=this.getSharedOptions(c),u=this.includeOptions(n,d),f=a.axis,g=r.axis,{spanGaps:p,segment:m}=this.options,x=N(p)?p:Number.POSITIVE_INFINITY,b=this.chart._animationsDisabled||o||"none"===n;let _=e>0&&this.getParsed(e-1);for(let c=e;c<e+i;++c){const e=t[c],i=this.getParsed(c),p=b?e:{},y=s(i[g]),v=p[f]=a.getPixelForValue(i[f],c),M=p[g]=o||y?r.getBasePixel():r.getPixelForValue(l?this.applyStack(r,i,l):i[g],c);p.skip=isNaN(v)||isNaN(M)||y,p.stop=c>0&&Math.abs(i[f]-_[f])>x,m&&(p.parsed=i,p.raw=h.data[c]),u&&(p.options=d||this.resolveDataElementOptions(c,e.active?"active":n)),b||this.updateElement(e,c,p,n),_=i}this.updateSharedOptions(d,n,c)}getMaxOverflow(){const t=this._cachedMeta,e=t.data||[];if(!this.options.showLine){let t=0;for(let i=e.length-1;i>=0;--i)t=Math.max(t,e[i].size(this.resolveDataElementOptions(i))/2);return t>0&&t}const i=t.dataset,s=i.options&&i.options.borderWidth||0;if(!e.length)return s;const n=e[0].size(this.resolveDataElementOptions(0)),o=e[e.length-1].size(this.resolveDataElementOptions(e.length-1));return Math.max(s,n,o)/2}}});function Xn(t,e,i,s){const n=vi(t.options.borderRadius,["outerStart","outerEnd","innerStart","innerEnd"]);const o=(i-e)/2,a=Math.min(o,s*e/2),r=t=>{const e=(i-Math.min(o,t))*s/2;return Z(t,0,Math.min(o,e))};return{outerStart:r(n.outerStart),outerEnd:r(n.outerEnd),innerStart:Z(n.innerStart,0,a),innerEnd:Z(n.innerEnd,0,a)}}function qn(t,e,i,s){return{x:i+t*Math.cos(e),y:s+t*Math.sin(e)}}function Kn(t,e,i,s,n,o){const{x:a,y:r,startAngle:l,pixelMargin:h,innerRadius:c}=e,d=Math.max(e.outerRadius+s+i-h,0),u=c>0?c+s+i+h:0;let f=0;const g=n-l;if(s){const t=((c>0?c-s:0)+(d>0?d-s:0))/2;f=(g-(0!==t?g*t/(t+s):g))/2}const p=(g-Math.max(.001,g*d-i/C)/d)/2,m=l+p+f,x=n-p-f,{outerStart:b,outerEnd:_,innerStart:y,innerEnd:v}=Xn(e,u,d,x-m),M=d-b,w=d-_,k=m+b/M,S=x-_/w,P=u+y,D=u+v,O=m+y/P,A=x-v/D;if(t.beginPath(),o){const e=(k+S)/2;if(t.arc(a,r,d,k,e),t.arc(a,r,d,e,S),_>0){const e=qn(w,S,a,r);t.arc(e.x,e.y,_,S,x+E)}const i=qn(D,x,a,r);if(t.lineTo(i.x,i.y),v>0){const e=qn(D,A,a,r);t.arc(e.x,e.y,v,x+E,A+Math.PI)}const s=(x-v/u+(m+y/u))/2;if(t.arc(a,r,u,x-v/u,s,!0),t.arc(a,r,u,s,m+y/u,!0),y>0){const e=qn(P,O,a,r);t.arc(e.x,e.y,y,O+Math.PI,m-E)}const n=qn(M,m,a,r);if(t.lineTo(n.x,n.y),b>0){const e=qn(M,k,a,r);t.arc(e.x,e.y,b,m-E,k)}}else{t.moveTo(a,r);const e=Math.cos(k)*d+a,i=Math.sin(k)*d+r;t.lineTo(e,i);const s=Math.cos(S)*d+a,n=Math.sin(S)*d+r;t.lineTo(s,n)}t.closePath()}function Gn(t,e,i,s,n){const{fullCircles:o,startAngle:a,circumference:r,options:l}=e,{borderWidth:h,borderJoinStyle:c,borderDash:d,borderDashOffset:u,borderRadius:f}=l,g="inner"===l.borderAlign;if(!h)return;t.setLineDash(d||[]),t.lineDashOffset=u,g?(t.lineWidth=2*h,t.lineJoin=c||"round"):(t.lineWidth=h,t.lineJoin=c||"bevel");let p=e.endAngle;if(o){Kn(t,e,i,s,p,n);for(let e=0;e<o;++e)t.stroke();isNaN(r)||(p=a+(r%O||O))}g&&function(t,e,i){const{startAngle:s,pixelMargin:n,x:o,y:a,outerRadius:r,innerRadius:l}=e;let h=n/r;t.beginPath(),t.arc(o,a,r,s-h,i+h),l>n?(h=n/l,t.arc(o,a,l,i+h,s-h,!0)):t.arc(o,a,n,i+E,s-E),t.closePath(),t.clip()}(t,e,p),l.selfJoin&&p-a>=C&&0===f&&"miter"!==c&&function(t,e,i){const{startAngle:s,x:n,y:o,outerRadius:a,innerRadius:r,options:l}=e,{borderWidth:h,borderJoinStyle:c}=l,d=Math.min(h/a,G(s-i));if(t.beginPath(),t.arc(n,o,a-h/2,s+d/2,i-d/2),r>0){const e=Math.min(h/r,G(s-i));t.arc(n,o,r+h/2,i-e/2,s+e/2,!0)}else{const e=Math.min(h/2,a*G(s-i));if("round"===c)t.arc(n,o,e,i-C/2,s+C/2,!0);else if("bevel"===c){const a=2*e*e,r=-a*Math.cos(i+C/2)+n,l=-a*Math.sin(i+C/2)+o,h=a*Math.cos(s+C/2)+n,c=a*Math.sin(s+C/2)+o;t.lineTo(r,l),t.lineTo(h,c)}}t.closePath(),t.moveTo(0,0),t.rect(0,0,t.canvas.width,t.canvas.height),t.clip("evenodd")}(t,e,p),o||(Kn(t,e,i,s,p,n),t.stroke())}function Jn(t,e,i=e){t.lineCap=l(i.borderCapStyle,e.borderCapStyle),t.setLineDash(l(i.borderDash,e.borderDash)),t.lineDashOffset=l(i.borderDashOffset,e.borderDashOffset),t.lineJoin=l(i.borderJoinStyle,e.borderJoinStyle),t.lineWidth=l(i.borderWidth,e.borderWidth),t.strokeStyle=l(i.borderColor,e.borderColor)}function Zn(t,e,i){t.lineTo(i.x,i.y)}function Qn(t,e,i={}){const s=t.length,{start:n=0,end:o=s-1}=i,{start:a,end:r}=e,l=Math.max(n,a),h=Math.min(o,r),c=n<a&&o<a||n>r&&o>r;return{count:s,start:l,loop:e.loop,ilen:h<l&&!c?s+h-l:h-l}}function to(t,e,i,s){const{points:n,options:o}=e,{count:a,start:r,loop:l,ilen:h}=Qn(n,i,s),c=function(t){return t.stepped?Fe:t.tension||"monotone"===t.cubicInterpolationMode?Ve:Zn}(o);let d,u,f,{move:g=!0,reverse:p}=s||{};for(d=0;d<=h;++d)u=n[(r+(p?h-d:d))%a],u.skip||(g?(t.moveTo(u.x,u.y),g=!1):c(t,f,u,p,o.stepped),f=u);return l&&(u=n[(r+(p?h:0))%a],c(t,f,u,p,o.stepped)),!!l}function eo(t,e,i,s){const n=e.points,{count:o,start:a,ilen:r}=Qn(n,i,s),{move:l=!0,reverse:h}=s||{};let c,d,u,f,g,p,m=0,x=0;const b=t=>(a+(h?r-t:t))%o,_=()=>{f!==g&&(t.lineTo(m,g),t.lineTo(m,f),t.lineTo(m,p))};for(l&&(d=n[b(0)],t.moveTo(d.x,d.y)),c=0;c<=r;++c){if(d=n[b(c)],d.skip)continue;const e=d.x,i=d.y,s=0|e;s===u?(i<f?f=i:i>g&&(g=i),m=(x*m+e)/++x):(_(),t.lineTo(e,i),u=s,x=0,f=g=i),p=i}_()}function io(t){const e=t.options,i=e.borderDash&&e.borderDash.length;return!(t._decimated||t._loop||e.tension||"monotone"===e.cubicInterpolationMode||e.stepped||i)?eo:to}const so="function"==typeof Path2D;function no(t,e,i,s){so&&!e.options.segment?function(t,e,i,s){let n=e._path;n||(n=e._path=new Path2D,e.path(n,i,s)&&n.closePath()),Jn(t,e.options),t.stroke(n)}(t,e,i,s):function(t,e,i,s){const{segments:n,options:o}=e,a=io(e);for(const r of n)Jn(t,o,r.style),t.beginPath(),a(t,e,r,{start:i,end:i+s-1})&&t.closePath(),t.stroke()}(t,e,i,s)}class oo extends $s{static id="line";static defaults={borderCapStyle:"butt",borderDash:[],borderDashOffset:0,borderJoinStyle:"miter",borderWidth:3,capBezierPoints:!0,cubicInterpolationMode:"default",fill:!1,spanGaps:!1,stepped:!1,tension:0};static defaultRoutes={backgroundColor:"backgroundColor",borderColor:"borderColor"};static descriptors={_scriptable:!0,_indexable:t=>"borderDash"!==t&&"fill"!==t};constructor(t){super(),this.animated=!0,this.options=void 0,this._chart=void 0,this._loop=void 0,this._fullLoop=void 0,this._path=void 0,this._points=void 0,this._segments=void 0,this._decimated=!1,this._pointsUpdated=!1,this._datasetIndex=void 0,t&&Object.assign(this,t)}updateControlPoints(t,e){const i=this.options;if((i.tension||"monotone"===i.cubicInterpolationMode)&&!i.stepped&&!this._pointsUpdated){const s=i.spanGaps?this._loop:this._fullLoop;hi(this._points,i,t,s,e),this._pointsUpdated=!0}}set points(t){this._points=t,delete this._segments,delete this._path,this._pointsUpdated=!1}get points(){return this._points}get segments(){return this._segments||(this._segments=zi(this,this.options.segment))}first(){const t=this.segments,e=this.points;return t.length&&e[t[0].start]}last(){const t=this.segments,e=this.points,i=t.length;return i&&e[t[i-1].end]}interpolate(t,e){const i=this.options,s=t[e],n=this.points,o=Ii(this,{property:e,start:s,end:s});if(!o.length)return;const a=[],r=function(t){return t.stepped?pi:t.tension||"monotone"===t.cubicInterpolationMode?mi:gi}(i);let l,h;for(l=0,h=o.length;l<h;++l){const{start:h,end:c}=o[l],d=n[h],u=n[c];if(d===u){a.push(d);continue}const f=r(d,u,Math.abs((s-d[e])/(u[e]-d[e])),i.stepped);f[e]=t[e],a.push(f)}return 1===a.length?a[0]:a}pathSegment(t,e,i){return io(this)(t,this,e,i)}path(t,e,i){const s=this.segments,n=io(this);let o=this._loop;e=e||0,i=i||this.points.length-e;for(const a of s)o&=n(t,this,a,{start:e,end:e+i-1});return!!o}draw(t,e,i,s){const n=this.options||{};(this.points||[]).length&&n.borderWidth&&(t.save(),no(t,this,i,s),t.restore()),this.animated&&(this._pointsUpdated=!1,this._path=void 0)}}function ao(t,e,i,s){const n=t.options,{[i]:o}=t.getProps([i],s);return Math.abs(e-o)<n.radius+n.hitRadius}function ro(t,e){const{x:i,y:s,base:n,width:o,height:a}=t.getProps(["x","y","base","width","height"],e);let r,l,h,c,d;return t.horizontal?(d=a/2,r=Math.min(i,n),l=Math.max(i,n),h=s-d,c=s+d):(d=o/2,r=i-d,l=i+d,h=Math.min(s,n),c=Math.max(s,n)),{left:r,top:h,right:l,bottom:c}}function lo(t,e,i,s){return t?0:Z(e,i,s)}function ho(t){const e=ro(t),i=e.right-e.left,s=e.bottom-e.top,n=function(t,e,i){const s=t.options.borderWidth,n=t.borderSkipped,o=Mi(s);return{t:lo(n.top,o.top,0,i),r:lo(n.right,o.right,0,e),b:lo(n.bottom,o.bottom,0,i),l:lo(n.left,o.left,0,e)}}(t,i/2,s/2),a=function(t,e,i){const{enableBorderRadius:s}=t.getProps(["enableBorderRadius"]),n=t.options.borderRadius,a=wi(n),r=Math.min(e,i),l=t.borderSkipped,h=s||o(n);return{topLeft:lo(!h||l.top||l.left,a.topLeft,0,r),topRight:lo(!h||l.top||l.right,a.topRight,0,r),bottomLeft:lo(!h||l.bottom||l.left,a.bottomLeft,0,r),bottomRight:lo(!h||l.bottom||l.right,a.bottomRight,0,r)}}(t,i/2,s/2);return{outer:{x:e.left,y:e.top,w:i,h:s,radius:a},inner:{x:e.left+n.l,y:e.top+n.t,w:i-n.l-n.r,h:s-n.t-n.b,radius:{topLeft:Math.max(0,a.topLeft-Math.max(n.t,n.l)),topRight:Math.max(0,a.topRight-Math.max(n.t,n.r)),bottomLeft:Math.max(0,a.bottomLeft-Math.max(n.b,n.l)),bottomRight:Math.max(0,a.bottomRight-Math.max(n.b,n.r))}}}}function co(t,e,i,s){const n=null===e,o=null===i,a=t&&!(n&&o)&&ro(t,s);return a&&(n||tt(e,a.left,a.right))&&(o||tt(i,a.top,a.bottom))}function uo(t,e){t.rect(e.x,e.y,e.w,e.h)}function fo(t,e,i={}){const s=t.x!==i.x?-e:0,n=t.y!==i.y?-e:0,o=(t.x+t.w!==i.x+i.w?e:0)-s,a=(t.y+t.h!==i.y+i.h?e:0)-n;return{x:t.x+s,y:t.y+n,w:t.w+o,h:t.h+a,radius:t.radius}}var go=Object.freeze({__proto__:null,ArcElement:class extends $s{static id="arc";static defaults={borderAlign:"center",borderColor:"#fff",borderDash:[],borderDashOffset:0,borderJoinStyle:void 0,borderRadius:0,borderWidth:2,offset:0,spacing:0,angle:void 0,circular:!0,selfJoin:!1};static defaultRoutes={backgroundColor:"backgroundColor"};static descriptors={_scriptable:!0,_indexable:t=>"borderDash"!==t};circumference;endAngle;fullCircles;innerRadius;outerRadius;pixelMargin;startAngle;constructor(t){super(),this.options=void 0,this.circumference=void 0,this.startAngle=void 0,this.endAngle=void 0,this.innerRadius=void 0,this.outerRadius=void 0,this.pixelMargin=0,this.fullCircles=0,t&&Object.assign(this,t)}inRange(t,e,i){const s=this.getProps(["x","y"],i),{angle:n,distance:o}=X(s,{x:t,y:e}),{startAngle:a,endAngle:r,innerRadius:h,outerRadius:c,circumference:d}=this.getProps(["startAngle","endAngle","innerRadius","outerRadius","circumference"],i),u=(this.options.spacing+this.options.borderWidth)/2,f=l(d,r-a),g=J(n,a,r)&&a!==r,p=f>=O||g,m=tt(o,h+u,c+u);return p&&m}getCenterPoint(t){const{x:e,y:i,startAngle:s,endAngle:n,innerRadius:o,outerRadius:a}=this.getProps(["x","y","startAngle","endAngle","innerRadius","outerRadius"],t),{offset:r,spacing:l}=this.options,h=(s+n)/2,c=(o+a+l+r)/2;return{x:e+Math.cos(h)*c,y:i+Math.sin(h)*c}}tooltipPosition(t){return this.getCenterPoint(t)}draw(t){const{options:e,circumference:i}=this,s=(e.offset||0)/4,n=(e.spacing||0)/2,o=e.circular;if(this.pixelMargin="inner"===e.borderAlign?.33:0,this.fullCircles=i>O?Math.floor(i/O):0,0===i||this.innerRadius<0||this.outerRadius<0)return;t.save();const a=(this.startAngle+this.endAngle)/2;t.translate(Math.cos(a)*s,Math.sin(a)*s);const r=s*(1-Math.sin(Math.min(C,i||0)));t.fillStyle=e.backgroundColor,t.strokeStyle=e.borderColor,function(t,e,i,s,n){const{fullCircles:o,startAngle:a,circumference:r}=e;let l=e.endAngle;if(o){Kn(t,e,i,s,l,n);for(let e=0;e<o;++e)t.fill();isNaN(r)||(l=a+(r%O||O))}Kn(t,e,i,s,l,n),t.fill()}(t,this,r,n,o),Gn(t,this,r,n,o),t.restore()}},BarElement:class extends $s{static id="bar";static defaults={borderSkipped:"start",borderWidth:0,borderRadius:0,inflateAmount:"auto",pointStyle:void 0};static defaultRoutes={backgroundColor:"backgroundColor",borderColor:"borderColor"};constructor(t){super(),this.options=void 0,this.horizontal=void 0,this.base=void 0,this.width=void 0,this.height=void 0,this.inflateAmount=void 0,t&&Object.assign(this,t)}draw(t){const{inflateAmount:e,options:{borderColor:i,backgroundColor:s}}=this,{inner:n,outer:o}=ho(this),a=(r=o.radius).topLeft||r.topRight||r.bottomLeft||r.bottomRight?He:uo;var r;t.save(),o.w===n.w&&o.h===n.h||(t.beginPath(),a(t,fo(o,e,n)),t.clip(),a(t,fo(n,-e,o)),t.fillStyle=i,t.fill("evenodd")),t.beginPath(),a(t,fo(n,e)),t.fillStyle=s,t.fill(),t.restore()}inRange(t,e,i){return co(this,t,e,i)}inXRange(t,e){return co(this,t,null,e)}inYRange(t,e){return co(this,null,t,e)}getCenterPoint(t){const{x:e,y:i,base:s,horizontal:n}=this.getProps(["x","y","base","horizontal"],t);return{x:n?(e+s)/2:e,y:n?i:(i+s)/2}}getRange(t){return"x"===t?this.width/2:this.height/2}},LineElement:oo,PointElement:class extends $s{static id="point";parsed;skip;stop;static defaults={borderWidth:1,hitRadius:1,hoverBorderWidth:1,hoverRadius:4,pointStyle:"circle",radius:3,rotation:0};static defaultRoutes={backgroundColor:"backgroundColor",borderColor:"borderColor"};constructor(t){super(),this.options=void 0,this.parsed=void 0,this.skip=void 0,this.stop=void 0,t&&Object.assign(this,t)}inRange(t,e,i){const s=this.options,{x:n,y:o}=this.getProps(["x","y"],i);return Math.pow(t-n,2)+Math.pow(e-o,2)<Math.pow(s.hitRadius+s.radius,2)}inXRange(t,e){return ao(this,t,"x",e)}inYRange(t,e){return ao(this,t,"y",e)}getCenterPoint(t){const{x:e,y:i}=this.getProps(["x","y"],t);return{x:e,y:i}}size(t){let e=(t=t||this.options||{}).radius||0;e=Math.max(e,e&&t.hoverRadius||0);return 2*(e+(e&&t.borderWidth||0))}draw(t,e){const i=this.options;this.skip||i.radius<.1||!Re(this,e,this.size(i)/2)||(t.strokeStyle=i.borderColor,t.lineWidth=i.borderWidth,t.fillStyle=i.backgroundColor,Le(t,i,this.x,this.y))}getRange(){const t=this.options||{};return t.radius+t.hitRadius}}});function po(t,e,i,s){const n=t.indexOf(e);if(-1===n)return((t,e,i,s)=>("string"==typeof e?(i=t.push(e)-1,s.unshift({index:i,label:e})):isNaN(e)&&(i=null),i))(t,e,i,s);return n!==t.lastIndexOf(e)?i:n}function mo(t){const e=this.getLabels();return t>=0&&t<e.length?e[t]:t}function xo(t,e,{horizontal:i,minRotation:s}){const n=$(s),o=(i?Math.sin(n):Math.cos(n))||.001,a=.75*e*(""+t).length;return Math.min(e/o,a)}class bo extends tn{constructor(t){super(t),this.start=void 0,this.end=void 0,this._startValue=void 0,this._endValue=void 0,this._valueRange=0}parse(t,e){return s(t)||("number"==typeof t||t instanceof Number)&&!isFinite(+t)?null:+t}handleTickRangeOptions(){const{beginAtZero:t}=this.options,{minDefined:e,maxDefined:i}=this.getUserBounds();let{min:s,max:n}=this;const o=t=>s=e?s:t,a=t=>n=i?n:t;if(t){const t=F(s),e=F(n);t<0&&e<0?a(0):t>0&&e>0&&o(0)}if(s===n){let e=0===n?1:Math.abs(.05*n);a(n+e),t||o(s-e)}this.min=s,this.max=n}getTickLimit(){const t=this.options.ticks;let e,{maxTicksLimit:i,stepSize:s}=t;return s?(e=Math.ceil(this.max/s)-Math.floor(this.min/s)+1,e>1e3&&(console.warn(`scales.${this.id}.ticks.stepSize: ${s} would result generating up to ${e} ticks. Limiting to 1000.`),e=1e3)):(e=this.computeTickLimit(),i=i||11),i&&(e=Math.min(i,e)),e}computeTickLimit(){return Number.POSITIVE_INFINITY}buildTicks(){const t=this.options,e=t.ticks;let i=this.getTickLimit();i=Math.max(2,i);const n=function(t,e){const i=[],{bounds:n,step:o,min:a,max:r,precision:l,count:h,maxTicks:c,maxDigits:d,includeBounds:u}=t,f=o||1,g=c-1,{min:p,max:m}=e,x=!s(a),b=!s(r),_=!s(h),y=(m-p)/(d+1);let v,M,w,k,S=B((m-p)/g/f)*f;if(S<1e-14&&!x&&!b)return[{value:p},{value:m}];k=Math.ceil(m/S)-Math.floor(p/S),k>g&&(S=B(k*S/g/f)*f),s(l)||(v=Math.pow(10,l),S=Math.ceil(S*v)/v),"ticks"===n?(M=Math.floor(p/S)*S,w=Math.ceil(m/S)*S):(M=p,w=m),x&&b&&o&&H((r-a)/o,S/1e3)?(k=Math.round(Math.min((r-a)/S,c)),S=(r-a)/k,M=a,w=r):_?(M=x?a:M,w=b?r:w,k=h-1,S=(w-M)/k):(k=(w-M)/S,k=V(k,Math.round(k),S/1e3)?Math.round(k):Math.ceil(k));const P=Math.max(U(S),U(M));v=Math.pow(10,s(l)?P:l),M=Math.round(M*v)/v,w=Math.round(w*v)/v;let D=0;for(x&&(u&&M!==a?(i.push({value:a}),M<a&&D++,V(Math.round((M+D*S)*v)/v,a,xo(a,y,t))&&D++):M<a&&D++);D<k;++D){const t=Math.round((M+D*S)*v)/v;if(b&&t>r)break;i.push({value:t})}return b&&u&&w!==r?i.length&&V(i[i.length-1].value,r,xo(r,y,t))?i[i.length-1].value=r:i.push({value:r}):b&&w!==r||i.push({value:w}),i}({maxTicks:i,bounds:t.bounds,min:t.min,max:t.max,precision:e.precision,step:e.stepSize,count:e.count,maxDigits:this._maxDigits(),horizontal:this.isHorizontal(),minRotation:e.minRotation||0,includeBounds:!1!==e.includeBounds},this._range||this);return"ticks"===t.bounds&&j(n,this,"value"),t.reverse?(n.reverse(),this.start=this.max,this.end=this.min):(this.start=this.min,this.end=this.max),n}configure(){const t=this.ticks;let e=this.min,i=this.max;if(super.configure(),this.options.offset&&t.length){const s=(i-e)/Math.max(t.length-1,1)/2;e-=s,i+=s}this._startValue=e,this._endValue=i,this._valueRange=i-e}getLabelForValue(t){return ne(t,this.chart.options.locale,this.options.ticks.format)}}class _o extends bo{static id="linear";static defaults={ticks:{callback:ae.formatters.numeric}};determineDataLimits(){const{min:t,max:e}=this.getMinMax(!0);this.min=a(t)?t:0,this.max=a(e)?e:1,this.handleTickRangeOptions()}computeTickLimit(){const t=this.isHorizontal(),e=t?this.width:this.height,i=$(this.options.ticks.minRotation),s=(t?Math.sin(i):Math.cos(i))||.001,n=this._resolveTickFontOptions(0);return Math.ceil(e/Math.min(40,n.lineHeight/s))}getPixelForValue(t){return null===t?NaN:this.getPixelForDecimal((t-this._startValue)/this._valueRange)}getValueForPixel(t){return this._startValue+this.getDecimalForPixel(t)*this._valueRange}}const yo=t=>Math.floor(z(t)),vo=(t,e)=>Math.pow(10,yo(t)+e);function Mo(t){return 1===t/Math.pow(10,yo(t))}function wo(t,e,i){const s=Math.pow(10,i),n=Math.floor(t/s);return Math.ceil(e/s)-n}function ko(t,{min:e,max:i}){e=r(t.min,e);const s=[],n=yo(e);let o=function(t,e){let i=yo(e-t);for(;wo(t,e,i)>10;)i++;for(;wo(t,e,i)<10;)i--;return Math.min(i,yo(t))}(e,i),a=o<0?Math.pow(10,Math.abs(o)):1;const l=Math.pow(10,o),h=n>o?Math.pow(10,n):0,c=Math.round((e-h)*a)/a,d=Math.floor((e-h)/l/10)*l*10;let u=Math.floor((c-d)/Math.pow(10,o)),f=r(t.min,Math.round((h+d+u*Math.pow(10,o))*a)/a);for(;f<i;)s.push({value:f,major:Mo(f),significand:u}),u>=10?u=u<15?15:20:u++,u>=20&&(o++,u=2,a=o>=0?1:a),f=Math.round((h+d+u*Math.pow(10,o))*a)/a;const g=r(t.max,f);return s.push({value:g,major:Mo(g),significand:u}),s}class So extends tn{static id="logarithmic";static defaults={ticks:{callback:ae.formatters.logarithmic,major:{enabled:!0}}};constructor(t){super(t),this.start=void 0,this.end=void 0,this._startValue=void 0,this._valueRange=0}parse(t,e){const i=bo.prototype.parse.apply(this,[t,e]);if(0!==i)return a(i)&&i>0?i:null;this._zero=!0}determineDataLimits(){const{min:t,max:e}=this.getMinMax(!0);this.min=a(t)?Math.max(0,t):null,this.max=a(e)?Math.max(0,e):null,this.options.beginAtZero&&(this._zero=!0),this._zero&&this.min!==this._suggestedMin&&!a(this._userMin)&&(this.min=t===vo(this.min,0)?vo(this.min,-1):vo(this.min,0)),this.handleTickRangeOptions()}handleTickRangeOptions(){const{minDefined:t,maxDefined:e}=this.getUserBounds();let i=this.min,s=this.max;const n=e=>i=t?i:e,o=t=>s=e?s:t;i===s&&(i<=0?(n(1),o(10)):(n(vo(i,-1)),o(vo(s,1)))),i<=0&&n(vo(s,-1)),s<=0&&o(vo(i,1)),this.min=i,this.max=s}buildTicks(){const t=this.options,e=ko({min:this._userMin,max:this._userMax},this);return"ticks"===t.bounds&&j(e,this,"value"),t.reverse?(e.reverse(),this.start=this.max,this.end=this.min):(this.start=this.min,this.end=this.max),e}getLabelForValue(t){return void 0===t?"0":ne(t,this.chart.options.locale,this.options.ticks.format)}configure(){const t=this.min;super.configure(),this._startValue=z(t),this._valueRange=z(this.max)-z(t)}getPixelForValue(t){return void 0!==t&&0!==t||(t=this.min),null===t||isNaN(t)?NaN:this.getPixelForDecimal(t===this.min?0:(z(t)-this._startValue)/this._valueRange)}getValueForPixel(t){const e=this.getDecimalForPixel(t);return Math.pow(10,this._startValue+e*this._valueRange)}}function Po(t){const e=t.ticks;if(e.display&&t.display){const t=ki(e.backdropPadding);return l(e.font&&e.font.size,ue.font.size)+t.height}return 0}function Do(t,e,i,s,n){return t===s||t===n?{start:e-i/2,end:e+i/2}:t<s||t>n?{start:e-i,end:e}:{start:e,end:e+i}}function Co(t){const e={l:t.left+t._padding.left,r:t.right-t._padding.right,t:t.top+t._padding.top,b:t.bottom-t._padding.bottom},i=Object.assign({},e),s=[],o=[],a=t._pointLabels.length,r=t.options.pointLabels,l=r.centerPointLabels?C/a:0;for(let u=0;u<a;u++){const a=r.setContext(t.getPointLabelContext(u));o[u]=a.padding;const f=t.getPointPosition(u,t.drawingArea+o[u],l),g=Si(a.font),p=(h=t.ctx,c=g,d=n(d=t._pointLabels[u])?d:[d],{w:Oe(h,c.string,d),h:d.length*c.lineHeight});s[u]=p;const m=G(t.getIndexAngle(u)+l),x=Math.round(Y(m));Oo(i,e,m,Do(x,f.x,p.w,0,180),Do(x,f.y,p.h,90,270))}var h,c,d;t.setCenterPoint(e.l-i.l,i.r-e.r,e.t-i.t,i.b-e.b),t._pointLabelItems=function(t,e,i){const s=[],n=t._pointLabels.length,o=t.options,{centerPointLabels:a,display:r}=o.pointLabels,l={extra:Po(o)/2,additionalAngle:a?C/n:0};let h;for(let o=0;o<n;o++){l.padding=i[o],l.size=e[o];const n=Ao(t,o,l);s.push(n),"auto"===r&&(n.visible=To(n,h),n.visible&&(h=n))}return s}(t,s,o)}function Oo(t,e,i,s,n){const o=Math.abs(Math.sin(i)),a=Math.abs(Math.cos(i));let r=0,l=0;s.start<e.l?(r=(e.l-s.start)/o,t.l=Math.min(t.l,e.l-r)):s.end>e.r&&(r=(s.end-e.r)/o,t.r=Math.max(t.r,e.r+r)),n.start<e.t?(l=(e.t-n.start)/a,t.t=Math.min(t.t,e.t-l)):n.end>e.b&&(l=(n.end-e.b)/a,t.b=Math.max(t.b,e.b+l))}function Ao(t,e,i){const s=t.drawingArea,{extra:n,additionalAngle:o,padding:a,size:r}=i,l=t.getPointPosition(e,s+n+a,o),h=Math.round(Y(G(l.angle+E))),c=function(t,e,i){90===i||270===i?t-=e/2:(i>270||i<90)&&(t-=e);return t}(l.y,r.h,h),d=function(t){if(0===t||180===t)return"center";if(t<180)return"left";return"right"}(h),u=function(t,e,i){"right"===i?t-=e:"center"===i&&(t-=e/2);return t}(l.x,r.w,d);return{visible:!0,x:l.x,y:c,textAlign:d,left:u,top:c,right:u+r.w,bottom:c+r.h}}function To(t,e){if(!e)return!0;const{left:i,top:s,right:n,bottom:o}=t;return!(Re({x:i,y:s},e)||Re({x:i,y:o},e)||Re({x:n,y:s},e)||Re({x:n,y:o},e))}function Lo(t,e,i){const{left:n,top:o,right:a,bottom:r}=i,{backdropColor:l}=e;if(!s(l)){const i=wi(e.borderRadius),s=ki(e.backdropPadding);t.fillStyle=l;const h=n-s.left,c=o-s.top,d=a-n+s.width,u=r-o+s.height;Object.values(i).some((t=>0!==t))?(t.beginPath(),He(t,{x:h,y:c,w:d,h:u,radius:i}),t.fill()):t.fillRect(h,c,d,u)}}function Eo(t,e,i,s){const{ctx:n}=t;if(i)n.arc(t.xCenter,t.yCenter,e,0,O);else{let i=t.getPointPosition(0,e);n.moveTo(i.x,i.y);for(let o=1;o<s;o++)i=t.getPointPosition(o,e),n.lineTo(i.x,i.y)}}class Ro extends bo{static id="radialLinear";static defaults={display:!0,animate:!0,position:"chartArea",angleLines:{display:!0,lineWidth:1,borderDash:[],borderDashOffset:0},grid:{circular:!1},startAngle:0,ticks:{showLabelBackdrop:!0,callback:ae.formatters.numeric},pointLabels:{backdropColor:void 0,backdropPadding:2,display:!0,font:{size:10},callback:t=>t,padding:5,centerPointLabels:!1}};static defaultRoutes={"angleLines.color":"borderColor","pointLabels.color":"color","ticks.color":"color"};static descriptors={angleLines:{_fallback:"grid"}};constructor(t){super(t),this.xCenter=void 0,this.yCenter=void 0,this.drawingArea=void 0,this._pointLabels=[],this._pointLabelItems=[]}setDimensions(){const t=this._padding=ki(Po(this.options)/2),e=this.width=this.maxWidth-t.width,i=this.height=this.maxHeight-t.height;this.xCenter=Math.floor(this.left+e/2+t.left),this.yCenter=Math.floor(this.top+i/2+t.top),this.drawingArea=Math.floor(Math.min(e,i)/2)}determineDataLimits(){const{min:t,max:e}=this.getMinMax(!1);this.min=a(t)&&!isNaN(t)?t:0,this.max=a(e)&&!isNaN(e)?e:0,this.handleTickRangeOptions()}computeTickLimit(){return Math.ceil(this.drawingArea/Po(this.options))}generateTickLabels(t){bo.prototype.generateTickLabels.call(this,t),this._pointLabels=this.getLabels().map(((t,e)=>{const i=d(this.options.pointLabels.callback,[t,e],this);return i||0===i?i:""})).filter(((t,e)=>this.chart.getDataVisibility(e)))}fit(){const t=this.options;t.display&&t.pointLabels.display?Co(this):this.setCenterPoint(0,0,0,0)}setCenterPoint(t,e,i,s){this.xCenter+=Math.floor((t-e)/2),this.yCenter+=Math.floor((i-s)/2),this.drawingArea-=Math.min(this.drawingArea/2,Math.max(t,e,i,s))}getIndexAngle(t){return G(t*(O/(this._pointLabels.length||1))+$(this.options.startAngle||0))}getDistanceFromCenterForValue(t){if(s(t))return NaN;const e=this.drawingArea/(this.max-this.min);return this.options.reverse?(this.max-t)*e:(t-this.min)*e}getValueForDistanceFromCenter(t){if(s(t))return NaN;const e=t/(this.drawingArea/(this.max-this.min));return this.options.reverse?this.max-e:this.min+e}getPointLabelContext(t){const e=this._pointLabels||[];if(t>=0&&t<e.length){const i=e[t];return function(t,e,i){return Ci(t,{label:i,index:e,type:"pointLabel"})}(this.getContext(),t,i)}}getPointPosition(t,e,i=0){const s=this.getIndexAngle(t)-E+i;return{x:Math.cos(s)*e+this.xCenter,y:Math.sin(s)*e+this.yCenter,angle:s}}getPointPositionForValue(t,e){return this.getPointPosition(t,this.getDistanceFromCenterForValue(e))}getBasePosition(t){return this.getPointPositionForValue(t||0,this.getBaseValue())}getPointLabelPosition(t){const{left:e,top:i,right:s,bottom:n}=this._pointLabelItems[t];return{left:e,top:i,right:s,bottom:n}}drawBackground(){const{backgroundColor:t,grid:{circular:e}}=this.options;if(t){const i=this.ctx;i.save(),i.beginPath(),Eo(this,this.getDistanceFromCenterForValue(this._endValue),e,this._pointLabels.length),i.closePath(),i.fillStyle=t,i.fill(),i.restore()}}drawGrid(){const t=this.ctx,e=this.options,{angleLines:i,grid:s,border:n}=e,o=this._pointLabels.length;let a,r,l;if(e.pointLabels.display&&function(t,e){const{ctx:i,options:{pointLabels:s}}=t;for(let n=e-1;n>=0;n--){const e=t._pointLabelItems[n];if(!e.visible)continue;const o=s.setContext(t.getPointLabelContext(n));Lo(i,o,e);const a=Si(o.font),{x:r,y:l,textAlign:h}=e;Ne(i,t._pointLabels[n],r,l+a.lineHeight/2,a,{color:o.color,textAlign:h,textBaseline:"middle"})}}(this,o),s.display&&this.ticks.forEach(((t,e)=>{if(0!==e||0===e&&this.min<0){r=this.getDistanceFromCenterForValue(t.value);const i=this.getContext(e),a=s.setContext(i),l=n.setContext(i);!function(t,e,i,s,n){const o=t.ctx,a=e.circular,{color:r,lineWidth:l}=e;!a&&!s||!r||!l||i<0||(o.save(),o.strokeStyle=r,o.lineWidth=l,o.setLineDash(n.dash||[]),o.lineDashOffset=n.dashOffset,o.beginPath(),Eo(t,i,a,s),o.closePath(),o.stroke(),o.restore())}(this,a,r,o,l)}})),i.display){for(t.save(),a=o-1;a>=0;a--){const s=i.setContext(this.getPointLabelContext(a)),{color:n,lineWidth:o}=s;o&&n&&(t.lineWidth=o,t.strokeStyle=n,t.setLineDash(s.borderDash),t.lineDashOffset=s.borderDashOffset,r=this.getDistanceFromCenterForValue(e.reverse?this.min:this.max),l=this.getPointPosition(a,r),t.beginPath(),t.moveTo(this.xCenter,this.yCenter),t.lineTo(l.x,l.y),t.stroke())}t.restore()}}drawBorder(){}drawLabels(){const t=this.ctx,e=this.options,i=e.ticks;if(!i.display)return;const s=this.getIndexAngle(0);let n,o;t.save(),t.translate(this.xCenter,this.yCenter),t.rotate(s),t.textAlign="center",t.textBaseline="middle",this.ticks.forEach(((s,a)=>{if(0===a&&this.min>=0&&!e.reverse)return;const r=i.setContext(this.getContext(a)),l=Si(r.font);if(n=this.getDistanceFromCenterForValue(this.ticks[a].value),r.showLabelBackdrop){t.font=l.string,o=t.measureText(s.label).width,t.fillStyle=r.backdropColor;const e=ki(r.backdropPadding);t.fillRect(-o/2-e.left,-n-l.size/2-e.top,o+e.width,l.size+e.height)}Ne(t,s.label,0,-n,l,{color:r.color,strokeColor:r.textStrokeColor,strokeWidth:r.textStrokeWidth})})),t.restore()}drawTitle(){}}const Io={millisecond:{common:!0,size:1,steps:1e3},second:{common:!0,size:1e3,steps:60},minute:{common:!0,size:6e4,steps:60},hour:{common:!0,size:36e5,steps:24},day:{common:!0,size:864e5,steps:30},week:{common:!1,size:6048e5,steps:4},month:{common:!0,size:2628e6,steps:12},quarter:{common:!1,size:7884e6,steps:4},year:{common:!0,size:3154e7}},zo=Object.keys(Io);function Fo(t,e){return t-e}function Vo(t,e){if(s(e))return null;const i=t._adapter,{parser:n,round:o,isoWeekday:r}=t._parseOpts;let l=e;return"function"==typeof n&&(l=n(l)),a(l)||(l="string"==typeof n?i.parse(l,n):i.parse(l)),null===l?null:(o&&(l="week"!==o||!N(r)&&!0!==r?i.startOf(l,o):i.startOf(l,"isoWeek",r)),+l)}function Bo(t,e,i,s){const n=zo.length;for(let o=zo.indexOf(t);o<n-1;++o){const t=Io[zo[o]],n=t.steps?t.steps:Number.MAX_SAFE_INTEGER;if(t.common&&Math.ceil((i-e)/(n*t.size))<=s)return zo[o]}return zo[n-1]}function Wo(t,e,i){if(i){if(i.length){const{lo:s,hi:n}=et(i,e);t[i[s]>=e?i[s]:i[n]]=!0}}else t[e]=!0}function No(t,e,i){const s=[],n={},o=e.length;let a,r;for(a=0;a<o;++a)r=e[a],n[r]=a,s.push({value:r,major:!1});return 0!==o&&i?function(t,e,i,s){const n=t._adapter,o=+n.startOf(e[0].value,s),a=e[e.length-1].value;let r,l;for(r=o;r<=a;r=+n.add(r,1,s))l=i[r],l>=0&&(e[l].major=!0);return e}(t,s,n,i):s}class Ho extends tn{static id="time";static defaults={bounds:"data",adapters:{},time:{parser:!1,unit:!1,round:!1,isoWeekday:!1,minUnit:"millisecond",displayFormats:{}},ticks:{source:"auto",callback:!1,major:{enabled:!1}}};constructor(t){super(t),this._cache={data:[],labels:[],all:[]},this._unit="day",this._majorUnit=void 0,this._offsets={},this._normalized=!1,this._parseOpts=void 0}init(t,e={}){const i=t.time||(t.time={}),s=this._adapter=new In._date(t.adapters.date);s.init(e),b(i.displayFormats,s.formats()),this._parseOpts={parser:i.parser,round:i.round,isoWeekday:i.isoWeekday},super.init(t),this._normalized=e.normalized}parse(t,e){return void 0===t?null:Vo(this,t)}beforeLayout(){super.beforeLayout(),this._cache={data:[],labels:[],all:[]}}determineDataLimits(){const t=this.options,e=this._adapter,i=t.time.unit||"day";let{min:s,max:n,minDefined:o,maxDefined:r}=this.getUserBounds();function l(t){o||isNaN(t.min)||(s=Math.min(s,t.min)),r||isNaN(t.max)||(n=Math.max(n,t.max))}o&&r||(l(this._getLabelBounds()),"ticks"===t.bounds&&"labels"===t.ticks.source||l(this.getMinMax(!1))),s=a(s)&&!isNaN(s)?s:+e.startOf(Date.now(),i),n=a(n)&&!isNaN(n)?n:+e.endOf(Date.now(),i)+1,this.min=Math.min(s,n-1),this.max=Math.max(s+1,n)}_getLabelBounds(){const t=this.getLabelTimestamps();let e=Number.POSITIVE_INFINITY,i=Number.NEGATIVE_INFINITY;return t.length&&(e=t[0],i=t[t.length-1]),{min:e,max:i}}buildTicks(){const t=this.options,e=t.time,i=t.ticks,s="labels"===i.source?this.getLabelTimestamps():this._generate();"ticks"===t.bounds&&s.length&&(this.min=this._userMin||s[0],this.max=this._userMax||s[s.length-1]);const n=this.min,o=nt(s,n,this.max);return this._unit=e.unit||(i.autoSkip?Bo(e.minUnit,this.min,this.max,this._getLabelCapacity(n)):function(t,e,i,s,n){for(let o=zo.length-1;o>=zo.indexOf(i);o--){const i=zo[o];if(Io[i].common&&t._adapter.diff(n,s,i)>=e-1)return i}return zo[i?zo.indexOf(i):0]}(this,o.length,e.minUnit,this.min,this.max)),this._majorUnit=i.major.enabled&&"year"!==this._unit?function(t){for(let e=zo.indexOf(t)+1,i=zo.length;e<i;++e)if(Io[zo[e]].common)return zo[e]}(this._unit):void 0,this.initOffsets(s),t.reverse&&o.reverse(),No(this,o,this._majorUnit)}afterAutoSkip(){this.options.offsetAfterAutoskip&&this.initOffsets(this.ticks.map((t=>+t.value)))}initOffsets(t=[]){let e,i,s=0,n=0;this.options.offset&&t.length&&(e=this.getDecimalForValue(t[0]),s=1===t.length?1-e:(this.getDecimalForValue(t[1])-e)/2,i=this.getDecimalForValue(t[t.length-1]),n=1===t.length?i:(i-this.getDecimalForValue(t[t.length-2]))/2);const o=t.length<3?.5:.25;s=Z(s,0,o),n=Z(n,0,o),this._offsets={start:s,end:n,factor:1/(s+1+n)}}_generate(){const t=this._adapter,e=this.min,i=this.max,s=this.options,n=s.time,o=n.unit||Bo(n.minUnit,e,i,this._getLabelCapacity(e)),a=l(s.ticks.stepSize,1),r="week"===o&&n.isoWeekday,h=N(r)||!0===r,c={};let d,u,f=e;if(h&&(f=+t.startOf(f,"isoWeek",r)),f=+t.startOf(f,h?"day":o),t.diff(i,e,o)>1e5*a)throw new Error(e+" and "+i+" are too far apart with stepSize of "+a+" "+o);const g="data"===s.ticks.source&&this.getDataTimestamps();for(d=f,u=0;d<i;d=+t.add(d,a,o),u++)Wo(c,d,g);return d!==i&&"ticks"!==s.bounds&&1!==u||Wo(c,d,g),Object.keys(c).sort(Fo).map((t=>+t))}getLabelForValue(t){const e=this._adapter,i=this.options.time;return i.tooltipFormat?e.format(t,i.tooltipFormat):e.format(t,i.displayFormats.datetime)}format(t,e){const i=this.options.time.displayFormats,s=this._unit,n=e||i[s];return this._adapter.format(t,n)}_tickFormatFunction(t,e,i,s){const n=this.options,o=n.ticks.callback;if(o)return d(o,[t,e,i],this);const a=n.time.displayFormats,r=this._unit,l=this._majorUnit,h=r&&a[r],c=l&&a[l],u=i[e],f=l&&c&&u&&u.major;return this._adapter.format(t,s||(f?c:h))}generateTickLabels(t){let e,i,s;for(e=0,i=t.length;e<i;++e)s=t[e],s.label=this._tickFormatFunction(s.value,e,t)}getDecimalForValue(t){return null===t?NaN:(t-this.min)/(this.max-this.min)}getPixelForValue(t){const e=this._offsets,i=this.getDecimalForValue(t);return this.getPixelForDecimal((e.start+i)*e.factor)}getValueForPixel(t){const e=this._offsets,i=this.getDecimalForPixel(t)/e.factor-e.end;return this.min+i*(this.max-this.min)}_getLabelSize(t){const e=this.options.ticks,i=this.ctx.measureText(t).width,s=$(this.isHorizontal()?e.maxRotation:e.minRotation),n=Math.cos(s),o=Math.sin(s),a=this._resolveTickFontOptions(0).size;return{w:i*n+a*o,h:i*o+a*n}}_getLabelCapacity(t){const e=this.options.time,i=e.displayFormats,s=i[e.unit]||i.millisecond,n=this._tickFormatFunction(t,0,No(this,[t],this._majorUnit),s),o=this._getLabelSize(n),a=Math.floor(this.isHorizontal()?this.width/o.w:this.height/o.h)-1;return a>0?a:1}getDataTimestamps(){let t,e,i=this._cache.data||[];if(i.length)return i;const s=this.getMatchingVisibleMetas();if(this._normalized&&s.length)return this._cache.data=s[0].controller.getAllParsedValues(this);for(t=0,e=s.length;t<e;++t)i=i.concat(s[t].controller.getAllParsedValues(this));return this._cache.data=this.normalize(i)}getLabelTimestamps(){const t=this._cache.labels||[];let e,i;if(t.length)return t;const s=this.getLabels();for(e=0,i=s.length;e<i;++e)t.push(Vo(this,s[e]));return this._cache.labels=this._normalized?t:this.normalize(t)}normalize(t){return lt(t.sort(Fo))}}function jo(t,e,i){let s,n,o,a,r=0,l=t.length-1;i?(e>=t[r].pos&&e<=t[l].pos&&({lo:r,hi:l}=it(t,"pos",e)),({pos:s,time:o}=t[r]),({pos:n,time:a}=t[l])):(e>=t[r].time&&e<=t[l].time&&({lo:r,hi:l}=it(t,"time",e)),({time:s,pos:o}=t[r]),({time:n,pos:a}=t[l]));const h=n-s;return h?o+(a-o)*(e-s)/h:o}var $o=Object.freeze({__proto__:null,CategoryScale:class extends tn{static id="category";static defaults={ticks:{callback:mo}};constructor(t){super(t),this._startValue=void 0,this._valueRange=0,this._addedLabels=[]}init(t){const e=this._addedLabels;if(e.length){const t=this.getLabels();for(const{index:i,label:s}of e)t[i]===s&&t.splice(i,1);this._addedLabels=[]}super.init(t)}parse(t,e){if(s(t))return null;const i=this.getLabels();return((t,e)=>null===t?null:Z(Math.round(t),0,e))(e=isFinite(e)&&i[e]===t?e:po(i,t,l(e,t),this._addedLabels),i.length-1)}determineDataLimits(){const{minDefined:t,maxDefined:e}=this.getUserBounds();let{min:i,max:s}=this.getMinMax(!0);"ticks"===this.options.bounds&&(t||(i=0),e||(s=this.getLabels().length-1)),this.min=i,this.max=s}buildTicks(){const t=this.min,e=this.max,i=this.options.offset,s=[];let n=this.getLabels();n=0===t&&e===n.length-1?n:n.slice(t,e+1),this._valueRange=Math.max(n.length-(i?0:1),1),this._startValue=this.min-(i?.5:0);for(let i=t;i<=e;i++)s.push({value:i});return s}getLabelForValue(t){return mo.call(this,t)}configure(){super.configure(),this.isHorizontal()||(this._reversePixels=!this._reversePixels)}getPixelForValue(t){return"number"!=typeof t&&(t=this.parse(t)),null===t?NaN:this.getPixelForDecimal((t-this._startValue)/this._valueRange)}getPixelForTick(t){const e=this.ticks;return t<0||t>e.length-1?null:this.getPixelForValue(e[t].value)}getValueForPixel(t){return Math.round(this._startValue+this.getDecimalForPixel(t)*this._valueRange)}getBasePixel(){return this.bottom}},LinearScale:_o,LogarithmicScale:So,RadialLinearScale:Ro,TimeScale:Ho,TimeSeriesScale:class extends Ho{static id="timeseries";static defaults=Ho.defaults;constructor(t){super(t),this._table=[],this._minPos=void 0,this._tableRange=void 0}initOffsets(){const t=this._getTimestampsForTable(),e=this._table=this.buildLookupTable(t);this._minPos=jo(e,this.min),this._tableRange=jo(e,this.max)-this._minPos,super.initOffsets(t)}buildLookupTable(t){const{min:e,max:i}=this,s=[],n=[];let o,a,r,l,h;for(o=0,a=t.length;o<a;++o)l=t[o],l>=e&&l<=i&&s.push(l);if(s.length<2)return[{time:e,pos:0},{time:i,pos:1}];for(o=0,a=s.length;o<a;++o)h=s[o+1],r=s[o-1],l=s[o],Math.round((h+r)/2)!==l&&n.push({time:l,pos:o/(a-1)});return n}_generate(){const t=this.min,e=this.max;let i=super.getDataTimestamps();return i.includes(t)&&i.length||i.splice(0,0,t),i.includes(e)&&1!==i.length||i.push(e),i.sort(((t,e)=>t-e))}_getTimestampsForTable(){let t=this._cache.all||[];if(t.length)return t;const e=this.getDataTimestamps(),i=this.getLabelTimestamps();return t=e.length&&i.length?this.normalize(e.concat(i)):e.length?e:i,t=this._cache.all=t,t}getDecimalForValue(t){return(jo(this._table,t)-this._minPos)/this._tableRange}getValueForPixel(t){const e=this._offsets,i=this.getDecimalForPixel(t)/e.factor-e.end;return jo(this._table,i*this._tableRange+this._minPos,!0)}}});const Yo=["rgb(54, 162, 235)","rgb(255, 99, 132)","rgb(255, 159, 64)","rgb(255, 205, 86)","rgb(75, 192, 192)","rgb(153, 102, 255)","rgb(201, 203, 207)"],Uo=Yo.map((t=>t.replace("rgb(","rgba(").replace(")",", 0.5)")));function Xo(t){return Yo[t%Yo.length]}function qo(t){return Uo[t%Uo.length]}function Ko(t){let e=0;return(i,s)=>{const n=t.getDatasetMeta(s).controller;n instanceof $n?e=function(t,e){return t.backgroundColor=t.data.map((()=>Xo(e++))),e}(i,e):n instanceof Yn?e=function(t,e){return t.backgroundColor=t.data.map((()=>qo(e++))),e}(i,e):n&&(e=function(t,e){return t.borderColor=Xo(e),t.backgroundColor=qo(e),++e}(i,e))}}function Go(t){let e;for(e in t)if(t[e].borderColor||t[e].backgroundColor)return!0;return!1}var Jo={id:"colors",defaults:{enabled:!0,forceOverride:!1},beforeLayout(t,e,i){if(!i.enabled)return;const{data:{datasets:s},options:n}=t.config,{elements:o}=n,a=Go(s)||(r=n)&&(r.borderColor||r.backgroundColor)||o&&Go(o)||"rgba(0,0,0,0.1)"!==ue.borderColor||"rgba(0,0,0,0.1)"!==ue.backgroundColor;var r;if(!i.forceOverride&&a)return;const l=Ko(t);s.forEach(l)}};function Zo(t){if(t._decimated){const e=t._data;delete t._decimated,delete t._data,Object.defineProperty(t,"data",{configurable:!0,enumerable:!0,writable:!0,value:e})}}function Qo(t){t.data.datasets.forEach((t=>{Zo(t)}))}var ta={id:"decimation",defaults:{algorithm:"min-max",enabled:!1},beforeElementsUpdate:(t,e,i)=>{if(!i.enabled)return void Qo(t);const n=t.width;t.data.datasets.forEach(((e,o)=>{const{_data:a,indexAxis:r}=e,l=t.getDatasetMeta(o),h=a||e.data;if("y"===Pi([r,t.options.indexAxis]))return;if(!l.controller.supportsDecimation)return;const c=t.scales[l.xAxisID];if("linear"!==c.type&&"time"!==c.type)return;if(t.options.parsing)return;let{start:d,count:u}=function(t,e){const i=e.length;let s,n=0;const{iScale:o}=t,{min:a,max:r,minDefined:l,maxDefined:h}=o.getUserBounds();return l&&(n=Z(it(e,o.axis,a).lo,0,i-1)),s=h?Z(it(e,o.axis,r).hi+1,n,i)-n:i-n,{start:n,count:s}}(l,h);if(u<=(i.threshold||4*n))return void Zo(e);let f;switch(s(a)&&(e._data=h,delete e.data,Object.defineProperty(e,"data",{configurable:!0,enumerable:!0,get:function(){return this._decimated},set:function(t){this._data=t}})),i.algorithm){case"lttb":f=function(t,e,i,s,n){const o=n.samples||s;if(o>=i)return t.slice(e,e+i);const a=[],r=(i-2)/(o-2);let l=0;const h=e+i-1;let c,d,u,f,g,p=e;for(a[l++]=t[p],c=0;c<o-2;c++){let s,n=0,o=0;const h=Math.floor((c+1)*r)+1+e,m=Math.min(Math.floor((c+2)*r)+1,i)+e,x=m-h;for(s=h;s<m;s++)n+=t[s].x,o+=t[s].y;n/=x,o/=x;const b=Math.floor(c*r)+1+e,_=Math.min(Math.floor((c+1)*r)+1,i)+e,{x:y,y:v}=t[p];for(u=f=-1,s=b;s<_;s++)f=.5*Math.abs((y-n)*(t[s].y-v)-(y-t[s].x)*(o-v)),f>u&&(u=f,d=t[s],g=s);a[l++]=d,p=g}return a[l++]=t[h],a}(h,d,u,n,i);break;case"min-max":f=function(t,e,i,n){let o,a,r,l,h,c,d,u,f,g,p=0,m=0;const x=[],b=e+i-1,_=t[e].x,y=t[b].x-_;for(o=e;o<e+i;++o){a=t[o],r=(a.x-_)/y*n,l=a.y;const e=0|r;if(e===h)l<f?(f=l,c=o):l>g&&(g=l,d=o),p=(m*p+a.x)/++m;else{const i=o-1;if(!s(c)&&!s(d)){const e=Math.min(c,d),s=Math.max(c,d);e!==u&&e!==i&&x.push({...t[e],x:p}),s!==u&&s!==i&&x.push({...t[s],x:p})}o>0&&i!==u&&x.push(t[i]),x.push(a),h=e,m=0,f=g=l,c=d=u=o}}return x}(h,d,u,n);break;default:throw new Error(`Unsupported decimation algorithm '${i.algorithm}'`)}e._decimated=f}))},destroy(t){Qo(t)}};function ea(t,e,i,s){if(s)return;let n=e[t],o=i[t];return"angle"===t&&(n=G(n),o=G(o)),{property:t,start:n,end:o}}function ia(t,e,i){for(;e>t;e--){const t=i[e];if(!isNaN(t.x)&&!isNaN(t.y))break}return e}function sa(t,e,i,s){return t&&e?s(t[i],e[i]):t?t[i]:e?e[i]:0}function na(t,e){let i=[],s=!1;return n(t)?(s=!0,i=t):i=function(t,e){const{x:i=null,y:s=null}=t||{},n=e.points,o=[];return e.segments.forEach((({start:t,end:e})=>{e=ia(t,e,n);const a=n[t],r=n[e];null!==s?(o.push({x:a.x,y:s}),o.push({x:r.x,y:s})):null!==i&&(o.push({x:i,y:a.y}),o.push({x:i,y:r.y}))})),o}(t,e),i.length?new oo({points:i,options:{tension:0},_loop:s,_fullLoop:s}):null}function oa(t){return t&&!1!==t.fill}function aa(t,e,i){let s=t[e].fill;const n=[e];let o;if(!i)return s;for(;!1!==s&&-1===n.indexOf(s);){if(!a(s))return s;if(o=t[s],!o)return!1;if(o.visible)return s;n.push(s),s=o.fill}return!1}function ra(t,e,i){const s=function(t){const e=t.options,i=e.fill;let s=l(i&&i.target,i);void 0===s&&(s=!!e.backgroundColor);if(!1===s||null===s)return!1;if(!0===s)return"origin";return s}(t);if(o(s))return!isNaN(s.value)&&s;let n=parseFloat(s);return a(n)&&Math.floor(n)===n?function(t,e,i,s){"-"!==t&&"+"!==t||(i=e+i);if(i===e||i<0||i>=s)return!1;return i}(s[0],e,n,i):["origin","start","end","stack","shape"].indexOf(s)>=0&&s}function la(t,e,i){const s=[];for(let n=0;n<i.length;n++){const o=i[n],{first:a,last:r,point:l}=ha(o,e,"x");if(!(!l||a&&r))if(a)s.unshift(l);else if(t.push(l),!r)break}t.push(...s)}function ha(t,e,i){const s=t.interpolate(e,i);if(!s)return{};const n=s[i],o=t.segments,a=t.points;let r=!1,l=!1;for(let t=0;t<o.length;t++){const e=o[t],s=a[e.start][i],h=a[e.end][i];if(tt(n,s,h)){r=n===s,l=n===h;break}}return{first:r,last:l,point:s}}class ca{constructor(t){this.x=t.x,this.y=t.y,this.radius=t.radius}pathSegment(t,e,i){const{x:s,y:n,radius:o}=this;return e=e||{start:0,end:O},t.arc(s,n,o,e.end,e.start,!0),!i.bounds}interpolate(t){const{x:e,y:i,radius:s}=this,n=t.angle;return{x:e+Math.cos(n)*s,y:i+Math.sin(n)*s,angle:n}}}function da(t){const{chart:e,fill:i,line:s}=t;if(a(i))return function(t,e){const i=t.getDatasetMeta(e),s=i&&t.isDatasetVisible(e);return s?i.dataset:null}(e,i);if("stack"===i)return function(t){const{scale:e,index:i,line:s}=t,n=[],o=s.segments,a=s.points,r=function(t,e){const i=[],s=t.getMatchingVisibleMetas("line");for(let t=0;t<s.length;t++){const n=s[t];if(n.index===e)break;n.hidden||i.unshift(n.dataset)}return i}(e,i);r.push(na({x:null,y:e.bottom},s));for(let t=0;t<o.length;t++){const e=o[t];for(let t=e.start;t<=e.end;t++)la(n,a[t],r)}return new oo({points:n,options:{}})}(t);if("shape"===i)return!0;const n=function(t){const e=t.scale||{};if(e.getPointPositionForValue)return function(t){const{scale:e,fill:i}=t,s=e.options,n=e.getLabels().length,a=s.reverse?e.max:e.min,r=function(t,e,i){let s;return s="start"===t?i:"end"===t?e.options.reverse?e.min:e.max:o(t)?t.value:e.getBaseValue(),s}(i,e,a),l=[];if(s.grid.circular){const t=e.getPointPositionForValue(0,a);return new ca({x:t.x,y:t.y,radius:e.getDistanceFromCenterForValue(r)})}for(let t=0;t<n;++t)l.push(e.getPointPositionForValue(t,r));return l}(t);return function(t){const{scale:e={},fill:i}=t,s=function(t,e){let i=null;return"start"===t?i=e.bottom:"end"===t?i=e.top:o(t)?i=e.getPixelForValue(t.value):e.getBasePixel&&(i=e.getBasePixel()),i}(i,e);if(a(s)){const t=e.isHorizontal();return{x:t?s:null,y:t?null:s}}return null}(t)}(t);return n instanceof ca?n:na(n,s)}function ua(t,e,i){const s=da(e),{chart:n,index:o,line:a,scale:r,axis:l}=e,h=a.options,c=h.fill,d=h.backgroundColor,{above:u=d,below:f=d}=c||{},g=n.getDatasetMeta(o),p=Ni(n,g);s&&a.points.length&&(Ie(t,i),function(t,e){const{line:i,target:s,above:n,below:o,area:a,scale:r,clip:l}=e,h=i._loop?"angle":e.axis;t.save();let c=o;o!==n&&("x"===h?(fa(t,s,a.top),pa(t,{line:i,target:s,color:n,scale:r,property:h,clip:l}),t.restore(),t.save(),fa(t,s,a.bottom)):"y"===h&&(ga(t,s,a.left),pa(t,{line:i,target:s,color:o,scale:r,property:h,clip:l}),t.restore(),t.save(),ga(t,s,a.right),c=n));pa(t,{line:i,target:s,color:c,scale:r,property:h,clip:l}),t.restore()}(t,{line:a,target:s,above:u,below:f,area:i,scale:r,axis:l,clip:p}),ze(t))}function fa(t,e,i){const{segments:s,points:n}=e;let o=!0,a=!1;t.beginPath();for(const r of s){const{start:s,end:l}=r,h=n[s],c=n[ia(s,l,n)];o?(t.moveTo(h.x,h.y),o=!1):(t.lineTo(h.x,i),t.lineTo(h.x,h.y)),a=!!e.pathSegment(t,r,{move:a}),a?t.closePath():t.lineTo(c.x,i)}t.lineTo(e.first().x,i),t.closePath(),t.clip()}function ga(t,e,i){const{segments:s,points:n}=e;let o=!0,a=!1;t.beginPath();for(const r of s){const{start:s,end:l}=r,h=n[s],c=n[ia(s,l,n)];o?(t.moveTo(h.x,h.y),o=!1):(t.lineTo(i,h.y),t.lineTo(h.x,h.y)),a=!!e.pathSegment(t,r,{move:a}),a?t.closePath():t.lineTo(i,c.y)}t.lineTo(i,e.first().y),t.closePath(),t.clip()}function pa(t,e){const{line:i,target:s,property:n,color:o,scale:a,clip:r}=e,l=function(t,e,i){const s=t.segments,n=t.points,o=e.points,a=[];for(const t of s){let{start:s,end:r}=t;r=ia(s,r,n);const l=ea(i,n[s],n[r],t.loop);if(!e.segments){a.push({source:t,target:l,start:n[s],end:n[r]});continue}const h=Ii(e,l);for(const e of h){const s=ea(i,o[e.start],o[e.end],e.loop),r=Ri(t,n,s);for(const t of r)a.push({source:t,target:e,start:{[i]:sa(l,s,"start",Math.max)},end:{[i]:sa(l,s,"end",Math.min)}})}}return a}(i,s,n);for(const{source:e,target:h,start:c,end:d}of l){const{style:{backgroundColor:l=o}={}}=e,u=!0!==s;t.save(),t.fillStyle=l,ma(t,a,r,u&&ea(n,c,d)),t.beginPath();const f=!!i.pathSegment(t,e);let g;if(u){f?t.closePath():xa(t,s,d,n);const e=!!s.pathSegment(t,h,{move:f,reverse:!0});g=f&&e,g||xa(t,s,c,n)}t.closePath(),t.fill(g?"evenodd":"nonzero"),t.restore()}}function ma(t,e,i,s){const n=e.chart.chartArea,{property:o,start:a,end:r}=s||{};if("x"===o||"y"===o){let e,s,l,h;"x"===o?(e=a,s=n.top,l=r,h=n.bottom):(e=n.left,s=a,l=n.right,h=r),t.beginPath(),i&&(e=Math.max(e,i.left),l=Math.min(l,i.right),s=Math.max(s,i.top),h=Math.min(h,i.bottom)),t.rect(e,s,l-e,h-s),t.clip()}}function xa(t,e,i,s){const n=e.interpolate(i,s);n&&t.lineTo(n.x,n.y)}var ba={id:"filler",afterDatasetsUpdate(t,e,i){const s=(t.data.datasets||[]).length,n=[];let o,a,r,l;for(a=0;a<s;++a)o=t.getDatasetMeta(a),r=o.dataset,l=null,r&&r.options&&r instanceof oo&&(l={visible:t.isDatasetVisible(a),index:a,fill:ra(r,a,s),chart:t,axis:o.controller.options.indexAxis,scale:o.vScale,line:r}),o.$filler=l,n.push(l);for(a=0;a<s;++a)l=n[a],l&&!1!==l.fill&&(l.fill=aa(n,a,i.propagate))},beforeDraw(t,e,i){const s="beforeDraw"===i.drawTime,n=t.getSortedVisibleDatasetMetas(),o=t.chartArea;for(let e=n.length-1;e>=0;--e){const i=n[e].$filler;i&&(i.line.updateControlPoints(o,i.axis),s&&i.fill&&ua(t.ctx,i,o))}},beforeDatasetsDraw(t,e,i){if("beforeDatasetsDraw"!==i.drawTime)return;const s=t.getSortedVisibleDatasetMetas();for(let e=s.length-1;e>=0;--e){const i=s[e].$filler;oa(i)&&ua(t.ctx,i,t.chartArea)}},beforeDatasetDraw(t,e,i){const s=e.meta.$filler;oa(s)&&"beforeDatasetDraw"===i.drawTime&&ua(t.ctx,s,t.chartArea)},defaults:{propagate:!0,drawTime:"beforeDatasetDraw"}};const _a=(t,e)=>{let{boxHeight:i=e,boxWidth:s=e}=t;return t.usePointStyle&&(i=Math.min(i,e),s=t.pointStyleWidth||Math.min(s,e)),{boxWidth:s,boxHeight:i,itemHeight:Math.max(e,i)}};class ya extends $s{constructor(t){super(),this._added=!1,this.legendHitBoxes=[],this._hoveredItem=null,this.doughnutMode=!1,this.chart=t.chart,this.options=t.options,this.ctx=t.ctx,this.legendItems=void 0,this.columnSizes=void 0,this.lineWidths=void 0,this.maxHeight=void 0,this.maxWidth=void 0,this.top=void 0,this.bottom=void 0,this.left=void 0,this.right=void 0,this.height=void 0,this.width=void 0,this._margins=void 0,this.position=void 0,this.weight=void 0,this.fullSize=void 0}update(t,e,i){this.maxWidth=t,this.maxHeight=e,this._margins=i,this.setDimensions(),this.buildLabels(),this.fit()}setDimensions(){this.isHorizontal()?(this.width=this.maxWidth,this.left=this._margins.left,this.right=this.width):(this.height=this.maxHeight,this.top=this._margins.top,this.bottom=this.height)}buildLabels(){const t=this.options.labels||{};let e=d(t.generateLabels,[this.chart],this)||[];t.filter&&(e=e.filter((e=>t.filter(e,this.chart.data)))),t.sort&&(e=e.sort(((e,i)=>t.sort(e,i,this.chart.data)))),this.options.reverse&&e.reverse(),this.legendItems=e}fit(){const{options:t,ctx:e}=this;if(!t.display)return void(this.width=this.height=0);const i=t.labels,s=Si(i.font),n=s.size,o=this._computeTitleHeight(),{boxWidth:a,itemHeight:r}=_a(i,n);let l,h;e.font=s.string,this.isHorizontal()?(l=this.maxWidth,h=this._fitRows(o,n,a,r)+10):(h=this.maxHeight,l=this._fitCols(o,s,a,r)+10),this.width=Math.min(l,t.maxWidth||this.maxWidth),this.height=Math.min(h,t.maxHeight||this.maxHeight)}_fitRows(t,e,i,s){const{ctx:n,maxWidth:o,options:{labels:{padding:a}}}=this,r=this.legendHitBoxes=[],l=this.lineWidths=[0],h=s+a;let c=t;n.textAlign="left",n.textBaseline="middle";let d=-1,u=-h;return this.legendItems.forEach(((t,f)=>{const g=i+e/2+n.measureText(t.text).width;(0===f||l[l.length-1]+g+2*a>o)&&(c+=h,l[l.length-(f>0?0:1)]=0,u+=h,d++),r[f]={left:0,top:u,row:d,width:g,height:s},l[l.length-1]+=g+a})),c}_fitCols(t,e,i,s){const{ctx:n,maxHeight:o,options:{labels:{padding:a}}}=this,r=this.legendHitBoxes=[],l=this.columnSizes=[],h=o-t;let c=a,d=0,u=0,f=0,g=0;return this.legendItems.forEach(((t,o)=>{const{itemWidth:p,itemHeight:m}=function(t,e,i,s,n){const o=function(t,e,i,s){let n=t.text;n&&"string"!=typeof n&&(n=n.reduce(((t,e)=>t.length>e.length?t:e)));return e+i.size/2+s.measureText(n).width}(s,t,e,i),a=function(t,e,i){let s=t;"string"!=typeof e.text&&(s=va(e,i));return s}(n,s,e.lineHeight);return{itemWidth:o,itemHeight:a}}(i,e,n,t,s);o>0&&u+m+2*a>h&&(c+=d+a,l.push({width:d,height:u}),f+=d+a,g++,d=u=0),r[o]={left:f,top:u,col:g,width:p,height:m},d=Math.max(d,p),u+=m+a})),c+=d,l.push({width:d,height:u}),c}adjustHitBoxes(){if(!this.options.display)return;const t=this._computeTitleHeight(),{legendHitBoxes:e,options:{align:i,labels:{padding:s},rtl:n}}=this,o=Oi(n,this.left,this.width);if(this.isHorizontal()){let n=0,a=ft(i,this.left+s,this.right-this.lineWidths[n]);for(const r of e)n!==r.row&&(n=r.row,a=ft(i,this.left+s,this.right-this.lineWidths[n])),r.top+=this.top+t+s,r.left=o.leftForLtr(o.x(a),r.width),a+=r.width+s}else{let n=0,a=ft(i,this.top+t+s,this.bottom-this.columnSizes[n].height);for(const r of e)r.col!==n&&(n=r.col,a=ft(i,this.top+t+s,this.bottom-this.columnSizes[n].height)),r.top=a,r.left+=this.left+s,r.left=o.leftForLtr(o.x(r.left),r.width),a+=r.height+s}}isHorizontal(){return"top"===this.options.position||"bottom"===this.options.position}draw(){if(this.options.display){const t=this.ctx;Ie(t,this),this._draw(),ze(t)}}_draw(){const{options:t,columnSizes:e,lineWidths:i,ctx:s}=this,{align:n,labels:o}=t,a=ue.color,r=Oi(t.rtl,this.left,this.width),h=Si(o.font),{padding:c}=o,d=h.size,u=d/2;let f;this.drawTitle(),s.textAlign=r.textAlign("left"),s.textBaseline="middle",s.lineWidth=.5,s.font=h.string;const{boxWidth:g,boxHeight:p,itemHeight:m}=_a(o,d),x=this.isHorizontal(),b=this._computeTitleHeight();f=x?{x:ft(n,this.left+c,this.right-i[0]),y:this.top+c+b,line:0}:{x:this.left+c,y:ft(n,this.top+b+c,this.bottom-e[0].height),line:0},Ai(this.ctx,t.textDirection);const _=m+c;this.legendItems.forEach(((y,v)=>{s.strokeStyle=y.fontColor,s.fillStyle=y.fontColor;const M=s.measureText(y.text).width,w=r.textAlign(y.textAlign||(y.textAlign=o.textAlign)),k=g+u+M;let S=f.x,P=f.y;r.setWidth(this.width),x?v>0&&S+k+c>this.right&&(P=f.y+=_,f.line++,S=f.x=ft(n,this.left+c,this.right-i[f.line])):v>0&&P+_>this.bottom&&(S=f.x=S+e[f.line].width+c,f.line++,P=f.y=ft(n,this.top+b+c,this.bottom-e[f.line].height));if(function(t,e,i){if(isNaN(g)||g<=0||isNaN(p)||p<0)return;s.save();const n=l(i.lineWidth,1);if(s.fillStyle=l(i.fillStyle,a),s.lineCap=l(i.lineCap,"butt"),s.lineDashOffset=l(i.lineDashOffset,0),s.lineJoin=l(i.lineJoin,"miter"),s.lineWidth=n,s.strokeStyle=l(i.strokeStyle,a),s.setLineDash(l(i.lineDash,[])),o.usePointStyle){const a={radius:p*Math.SQRT2/2,pointStyle:i.pointStyle,rotation:i.rotation,borderWidth:n},l=r.xPlus(t,g/2);Ee(s,a,l,e+u,o.pointStyleWidth&&g)}else{const o=e+Math.max((d-p)/2,0),a=r.leftForLtr(t,g),l=wi(i.borderRadius);s.beginPath(),Object.values(l).some((t=>0!==t))?He(s,{x:a,y:o,w:g,h:p,radius:l}):s.rect(a,o,g,p),s.fill(),0!==n&&s.stroke()}s.restore()}(r.x(S),P,y),S=gt(w,S+g+u,x?S+k:this.right,t.rtl),function(t,e,i){Ne(s,i.text,t,e+m/2,h,{strikethrough:i.hidden,textAlign:r.textAlign(i.textAlign)})}(r.x(S),P,y),x)f.x+=k+c;else if("string"!=typeof y.text){const t=h.lineHeight;f.y+=va(y,t)+c}else f.y+=_})),Ti(this.ctx,t.textDirection)}drawTitle(){const t=this.options,e=t.title,i=Si(e.font),s=ki(e.padding);if(!e.display)return;const n=Oi(t.rtl,this.left,this.width),o=this.ctx,a=e.position,r=i.size/2,l=s.top+r;let h,c=this.left,d=this.width;if(this.isHorizontal())d=Math.max(...this.lineWidths),h=this.top+l,c=ft(t.align,c,this.right-d);else{const e=this.columnSizes.reduce(((t,e)=>Math.max(t,e.height)),0);h=l+ft(t.align,this.top,this.bottom-e-t.labels.padding-this._computeTitleHeight())}const u=ft(a,c,c+d);o.textAlign=n.textAlign(ut(a)),o.textBaseline="middle",o.strokeStyle=e.color,o.fillStyle=e.color,o.font=i.string,Ne(o,e.text,u,h,i)}_computeTitleHeight(){const t=this.options.title,e=Si(t.font),i=ki(t.padding);return t.display?e.lineHeight+i.height:0}_getLegendItemAt(t,e){let i,s,n;if(tt(t,this.left,this.right)&&tt(e,this.top,this.bottom))for(n=this.legendHitBoxes,i=0;i<n.length;++i)if(s=n[i],tt(t,s.left,s.left+s.width)&&tt(e,s.top,s.top+s.height))return this.legendItems[i];return null}handleEvent(t){const e=this.options;if(!function(t,e){if(("mousemove"===t||"mouseout"===t)&&(e.onHover||e.onLeave))return!0;if(e.onClick&&("click"===t||"mouseup"===t))return!0;return!1}(t.type,e))return;const i=this._getLegendItemAt(t.x,t.y);if("mousemove"===t.type||"mouseout"===t.type){const o=this._hoveredItem,a=(n=i,null!==(s=o)&&null!==n&&s.datasetIndex===n.datasetIndex&&s.index===n.index);o&&!a&&d(e.onLeave,[t,o,this],this),this._hoveredItem=i,i&&!a&&d(e.onHover,[t,i,this],this)}else i&&d(e.onClick,[t,i,this],this);var s,n}}function va(t,e){return e*(t.text?t.text.length:0)}var Ma={id:"legend",_element:ya,start(t,e,i){const s=t.legend=new ya({ctx:t.ctx,options:i,chart:t});ls.configure(t,s,i),ls.addBox(t,s)},stop(t){ls.removeBox(t,t.legend),delete t.legend},beforeUpdate(t,e,i){const s=t.legend;ls.configure(t,s,i),s.options=i},afterUpdate(t){const e=t.legend;e.buildLabels(),e.adjustHitBoxes()},afterEvent(t,e){e.replay||t.legend.handleEvent(e.event)},defaults:{display:!0,position:"top",align:"center",fullSize:!0,reverse:!1,weight:1e3,onClick(t,e,i){const s=e.datasetIndex,n=i.chart;n.isDatasetVisible(s)?(n.hide(s),e.hidden=!0):(n.show(s),e.hidden=!1)},onHover:null,onLeave:null,labels:{color:t=>t.chart.options.color,boxWidth:40,padding:10,generateLabels(t){const e=t.data.datasets,{labels:{usePointStyle:i,pointStyle:s,textAlign:n,color:o,useBorderRadius:a,borderRadius:r}}=t.legend.options;return t._getSortedDatasetMetas().map((t=>{const l=t.controller.getStyle(i?0:void 0),h=ki(l.borderWidth);return{text:e[t.index].label,fillStyle:l.backgroundColor,fontColor:o,hidden:!t.visible,lineCap:l.borderCapStyle,lineDash:l.borderDash,lineDashOffset:l.borderDashOffset,lineJoin:l.borderJoinStyle,lineWidth:(h.width+h.height)/4,strokeStyle:l.borderColor,pointStyle:s||l.pointStyle,rotation:l.rotation,textAlign:n||l.textAlign,borderRadius:a&&(r||l.borderRadius),datasetIndex:t.index}}),this)}},title:{color:t=>t.chart.options.color,display:!1,position:"center",text:""}},descriptors:{_scriptable:t=>!t.startsWith("on"),labels:{_scriptable:t=>!["generateLabels","filter","sort"].includes(t)}}};class wa extends $s{constructor(t){super(),this.chart=t.chart,this.options=t.options,this.ctx=t.ctx,this._padding=void 0,this.top=void 0,this.bottom=void 0,this.left=void 0,this.right=void 0,this.width=void 0,this.height=void 0,this.position=void 0,this.weight=void 0,this.fullSize=void 0}update(t,e){const i=this.options;if(this.left=0,this.top=0,!i.display)return void(this.width=this.height=this.right=this.bottom=0);this.width=this.right=t,this.height=this.bottom=e;const s=n(i.text)?i.text.length:1;this._padding=ki(i.padding);const o=s*Si(i.font).lineHeight+this._padding.height;this.isHorizontal()?this.height=o:this.width=o}isHorizontal(){const t=this.options.position;return"top"===t||"bottom"===t}_drawArgs(t){const{top:e,left:i,bottom:s,right:n,options:o}=this,a=o.align;let r,l,h,c=0;return this.isHorizontal()?(l=ft(a,i,n),h=e+t,r=n-i):("left"===o.position?(l=i+t,h=ft(a,s,e),c=-.5*C):(l=n-t,h=ft(a,e,s),c=.5*C),r=s-e),{titleX:l,titleY:h,maxWidth:r,rotation:c}}draw(){const t=this.ctx,e=this.options;if(!e.display)return;const i=Si(e.font),s=i.lineHeight/2+this._padding.top,{titleX:n,titleY:o,maxWidth:a,rotation:r}=this._drawArgs(s);Ne(t,e.text,0,0,i,{color:e.color,maxWidth:a,rotation:r,textAlign:ut(e.align),textBaseline:"middle",translation:[n,o]})}}var ka={id:"title",_element:wa,start(t,e,i){!function(t,e){const i=new wa({ctx:t.ctx,options:e,chart:t});ls.configure(t,i,e),ls.addBox(t,i),t.titleBlock=i}(t,i)},stop(t){const e=t.titleBlock;ls.removeBox(t,e),delete t.titleBlock},beforeUpdate(t,e,i){const s=t.titleBlock;ls.configure(t,s,i),s.options=i},defaults:{align:"center",display:!1,font:{weight:"bold"},fullSize:!0,padding:10,position:"top",text:"",weight:2e3},defaultRoutes:{color:"color"},descriptors:{_scriptable:!0,_indexable:!1}};const Sa=new WeakMap;var Pa={id:"subtitle",start(t,e,i){const s=new wa({ctx:t.ctx,options:i,chart:t});ls.configure(t,s,i),ls.addBox(t,s),Sa.set(t,s)},stop(t){ls.removeBox(t,Sa.get(t)),Sa.delete(t)},beforeUpdate(t,e,i){const s=Sa.get(t);ls.configure(t,s,i),s.options=i},defaults:{align:"center",display:!1,font:{weight:"normal"},fullSize:!0,padding:0,position:"top",text:"",weight:1500},defaultRoutes:{color:"color"},descriptors:{_scriptable:!0,_indexable:!1}};const Da={average(t){if(!t.length)return!1;let e,i,s=new Set,n=0,o=0;for(e=0,i=t.length;e<i;++e){const i=t[e].element;if(i&&i.hasValue()){const t=i.tooltipPosition();s.add(t.x),n+=t.y,++o}}if(0===o||0===s.size)return!1;return{x:[...s].reduce(((t,e)=>t+e))/s.size,y:n/o}},nearest(t,e){if(!t.length)return!1;let i,s,n,o=e.x,a=e.y,r=Number.POSITIVE_INFINITY;for(i=0,s=t.length;i<s;++i){const s=t[i].element;if(s&&s.hasValue()){const t=q(e,s.getCenterPoint());t<r&&(r=t,n=s)}}if(n){const t=n.tooltipPosition();o=t.x,a=t.y}return{x:o,y:a}}};function Ca(t,e){return e&&(n(e)?Array.prototype.push.apply(t,e):t.push(e)),t}function Oa(t){return("string"==typeof t||t instanceof String)&&t.indexOf("\n")>-1?t.split("\n"):t}function Aa(t,e){const{element:i,datasetIndex:s,index:n}=e,o=t.getDatasetMeta(s).controller,{label:a,value:r}=o.getLabelAndValue(n);return{chart:t,label:a,parsed:o.getParsed(n),raw:t.data.datasets[s].data[n],formattedValue:r,dataset:o.getDataset(),dataIndex:n,datasetIndex:s,element:i}}function Ta(t,e){const i=t.chart.ctx,{body:s,footer:n,title:o}=t,{boxWidth:a,boxHeight:r}=e,l=Si(e.bodyFont),h=Si(e.titleFont),c=Si(e.footerFont),d=o.length,f=n.length,g=s.length,p=ki(e.padding);let m=p.height,x=0,b=s.reduce(((t,e)=>t+e.before.length+e.lines.length+e.after.length),0);if(b+=t.beforeBody.length+t.afterBody.length,d&&(m+=d*h.lineHeight+(d-1)*e.titleSpacing+e.titleMarginBottom),b){m+=g*(e.displayColors?Math.max(r,l.lineHeight):l.lineHeight)+(b-g)*l.lineHeight+(b-1)*e.bodySpacing}f&&(m+=e.footerMarginTop+f*c.lineHeight+(f-1)*e.footerSpacing);let _=0;const y=function(t){x=Math.max(x,i.measureText(t).width+_)};return i.save(),i.font=h.string,u(t.title,y),i.font=l.string,u(t.beforeBody.concat(t.afterBody),y),_=e.displayColors?a+2+e.boxPadding:0,u(s,(t=>{u(t.before,y),u(t.lines,y),u(t.after,y)})),_=0,i.font=c.string,u(t.footer,y),i.restore(),x+=p.width,{width:x,height:m}}function La(t,e,i,s){const{x:n,width:o}=i,{width:a,chartArea:{left:r,right:l}}=t;let h="center";return"center"===s?h=n<=(r+l)/2?"left":"right":n<=o/2?h="left":n>=a-o/2&&(h="right"),function(t,e,i,s){const{x:n,width:o}=s,a=i.caretSize+i.caretPadding;return"left"===t&&n+o+a>e.width||"right"===t&&n-o-a<0||void 0}(h,t,e,i)&&(h="center"),h}function Ea(t,e,i){const s=i.yAlign||e.yAlign||function(t,e){const{y:i,height:s}=e;return i<s/2?"top":i>t.height-s/2?"bottom":"center"}(t,i);return{xAlign:i.xAlign||e.xAlign||La(t,e,i,s),yAlign:s}}function Ra(t,e,i,s){const{caretSize:n,caretPadding:o,cornerRadius:a}=t,{xAlign:r,yAlign:l}=i,h=n+o,{topLeft:c,topRight:d,bottomLeft:u,bottomRight:f}=wi(a);let g=function(t,e){let{x:i,width:s}=t;return"right"===e?i-=s:"center"===e&&(i-=s/2),i}(e,r);const p=function(t,e,i){let{y:s,height:n}=t;return"top"===e?s+=i:s-="bottom"===e?n+i:n/2,s}(e,l,h);return"center"===l?"left"===r?g+=h:"right"===r&&(g-=h):"left"===r?g-=Math.max(c,u)+n:"right"===r&&(g+=Math.max(d,f)+n),{x:Z(g,0,s.width-e.width),y:Z(p,0,s.height-e.height)}}function Ia(t,e,i){const s=ki(i.padding);return"center"===e?t.x+t.width/2:"right"===e?t.x+t.width-s.right:t.x+s.left}function za(t){return Ca([],Oa(t))}function Fa(t,e){const i=e&&e.dataset&&e.dataset.tooltip&&e.dataset.tooltip.callbacks;return i?t.override(i):t}const Va={beforeTitle:e,title(t){if(t.length>0){const e=t[0],i=e.chart.data.labels,s=i?i.length:0;if(this&&this.options&&"dataset"===this.options.mode)return e.dataset.label||"";if(e.label)return e.label;if(s>0&&e.dataIndex<s)return i[e.dataIndex]}return""},afterTitle:e,beforeBody:e,beforeLabel:e,label(t){if(this&&this.options&&"dataset"===this.options.mode)return t.label+": "+t.formattedValue||t.formattedValue;let e=t.dataset.label||"";e&&(e+=": ");const i=t.formattedValue;return s(i)||(e+=i),e},labelColor(t){const e=t.chart.getDatasetMeta(t.datasetIndex).controller.getStyle(t.dataIndex);return{borderColor:e.borderColor,backgroundColor:e.backgroundColor,borderWidth:e.borderWidth,borderDash:e.borderDash,borderDashOffset:e.borderDashOffset,borderRadius:0}},labelTextColor(){return this.options.bodyColor},labelPointStyle(t){const e=t.chart.getDatasetMeta(t.datasetIndex).controller.getStyle(t.dataIndex);return{pointStyle:e.pointStyle,rotation:e.rotation}},afterLabel:e,afterBody:e,beforeFooter:e,footer:e,afterFooter:e};function Ba(t,e,i,s){const n=t[e].call(i,s);return void 0===n?Va[e].call(i,s):n}class Wa extends $s{static positioners=Da;constructor(t){super(),this.opacity=0,this._active=[],this._eventPosition=void 0,this._size=void 0,this._cachedAnimations=void 0,this._tooltipItems=[],this.$animations=void 0,this.$context=void 0,this.chart=t.chart,this.options=t.options,this.dataPoints=void 0,this.title=void 0,this.beforeBody=void 0,this.body=void 0,this.afterBody=void 0,this.footer=void 0,this.xAlign=void 0,this.yAlign=void 0,this.x=void 0,this.y=void 0,this.height=void 0,this.width=void 0,this.caretX=void 0,this.caretY=void 0,this.labelColors=void 0,this.labelPointStyles=void 0,this.labelTextColors=void 0}initialize(t){this.options=t,this._cachedAnimations=void 0,this.$context=void 0}_resolveAnimations(){const t=this._cachedAnimations;if(t)return t;const e=this.chart,i=this.options.setContext(this.getContext()),s=i.enabled&&e.options.animation&&i.animations,n=new Ts(this.chart,s);return s._cacheable&&(this._cachedAnimations=Object.freeze(n)),n}getContext(){return this.$context||(this.$context=(t=this.chart.getContext(),e=this,i=this._tooltipItems,Ci(t,{tooltip:e,tooltipItems:i,type:"tooltip"})));var t,e,i}getTitle(t,e){const{callbacks:i}=e,s=Ba(i,"beforeTitle",this,t),n=Ba(i,"title",this,t),o=Ba(i,"afterTitle",this,t);let a=[];return a=Ca(a,Oa(s)),a=Ca(a,Oa(n)),a=Ca(a,Oa(o)),a}getBeforeBody(t,e){return za(Ba(e.callbacks,"beforeBody",this,t))}getBody(t,e){const{callbacks:i}=e,s=[];return u(t,(t=>{const e={before:[],lines:[],after:[]},n=Fa(i,t);Ca(e.before,Oa(Ba(n,"beforeLabel",this,t))),Ca(e.lines,Ba(n,"label",this,t)),Ca(e.after,Oa(Ba(n,"afterLabel",this,t))),s.push(e)})),s}getAfterBody(t,e){return za(Ba(e.callbacks,"afterBody",this,t))}getFooter(t,e){const{callbacks:i}=e,s=Ba(i,"beforeFooter",this,t),n=Ba(i,"footer",this,t),o=Ba(i,"afterFooter",this,t);let a=[];return a=Ca(a,Oa(s)),a=Ca(a,Oa(n)),a=Ca(a,Oa(o)),a}_createItems(t){const e=this._active,i=this.chart.data,s=[],n=[],o=[];let a,r,l=[];for(a=0,r=e.length;a<r;++a)l.push(Aa(this.chart,e[a]));return t.filter&&(l=l.filter(((e,s,n)=>t.filter(e,s,n,i)))),t.itemSort&&(l=l.sort(((e,s)=>t.itemSort(e,s,i)))),u(l,(e=>{const i=Fa(t.callbacks,e);s.push(Ba(i,"labelColor",this,e)),n.push(Ba(i,"labelPointStyle",this,e)),o.push(Ba(i,"labelTextColor",this,e))})),this.labelColors=s,this.labelPointStyles=n,this.labelTextColors=o,this.dataPoints=l,l}update(t,e){const i=this.options.setContext(this.getContext()),s=this._active;let n,o=[];if(s.length){const t=Da[i.position].call(this,s,this._eventPosition);o=this._createItems(i),this.title=this.getTitle(o,i),this.beforeBody=this.getBeforeBody(o,i),this.body=this.getBody(o,i),this.afterBody=this.getAfterBody(o,i),this.footer=this.getFooter(o,i);const e=this._size=Ta(this,i),a=Object.assign({},t,e),r=Ea(this.chart,i,a),l=Ra(i,a,r,this.chart);this.xAlign=r.xAlign,this.yAlign=r.yAlign,n={opacity:1,x:l.x,y:l.y,width:e.width,height:e.height,caretX:t.x,caretY:t.y}}else 0!==this.opacity&&(n={opacity:0});this._tooltipItems=o,this.$context=void 0,n&&this._resolveAnimations().update(this,n),t&&i.external&&i.external.call(this,{chart:this.chart,tooltip:this,replay:e})}drawCaret(t,e,i,s){const n=this.getCaretPosition(t,i,s);e.lineTo(n.x1,n.y1),e.lineTo(n.x2,n.y2),e.lineTo(n.x3,n.y3)}getCaretPosition(t,e,i){const{xAlign:s,yAlign:n}=this,{caretSize:o,cornerRadius:a}=i,{topLeft:r,topRight:l,bottomLeft:h,bottomRight:c}=wi(a),{x:d,y:u}=t,{width:f,height:g}=e;let p,m,x,b,_,y;return"center"===n?(_=u+g/2,"left"===s?(p=d,m=p-o,b=_+o,y=_-o):(p=d+f,m=p+o,b=_-o,y=_+o),x=p):(m="left"===s?d+Math.max(r,h)+o:"right"===s?d+f-Math.max(l,c)-o:this.caretX,"top"===n?(b=u,_=b-o,p=m-o,x=m+o):(b=u+g,_=b+o,p=m+o,x=m-o),y=b),{x1:p,x2:m,x3:x,y1:b,y2:_,y3:y}}drawTitle(t,e,i){const s=this.title,n=s.length;let o,a,r;if(n){const l=Oi(i.rtl,this.x,this.width);for(t.x=Ia(this,i.titleAlign,i),e.textAlign=l.textAlign(i.titleAlign),e.textBaseline="middle",o=Si(i.titleFont),a=i.titleSpacing,e.fillStyle=i.titleColor,e.font=o.string,r=0;r<n;++r)e.fillText(s[r],l.x(t.x),t.y+o.lineHeight/2),t.y+=o.lineHeight+a,r+1===n&&(t.y+=i.titleMarginBottom-a)}}_drawColorBox(t,e,i,s,n){const a=this.labelColors[i],r=this.labelPointStyles[i],{boxHeight:l,boxWidth:h}=n,c=Si(n.bodyFont),d=Ia(this,"left",n),u=s.x(d),f=l<c.lineHeight?(c.lineHeight-l)/2:0,g=e.y+f;if(n.usePointStyle){const e={radius:Math.min(h,l)/2,pointStyle:r.pointStyle,rotation:r.rotation,borderWidth:1},i=s.leftForLtr(u,h)+h/2,o=g+l/2;t.strokeStyle=n.multiKeyBackground,t.fillStyle=n.multiKeyBackground,Le(t,e,i,o),t.strokeStyle=a.borderColor,t.fillStyle=a.backgroundColor,Le(t,e,i,o)}else{t.lineWidth=o(a.borderWidth)?Math.max(...Object.values(a.borderWidth)):a.borderWidth||1,t.strokeStyle=a.borderColor,t.setLineDash(a.borderDash||[]),t.lineDashOffset=a.borderDashOffset||0;const e=s.leftForLtr(u,h),i=s.leftForLtr(s.xPlus(u,1),h-2),r=wi(a.borderRadius);Object.values(r).some((t=>0!==t))?(t.beginPath(),t.fillStyle=n.multiKeyBackground,He(t,{x:e,y:g,w:h,h:l,radius:r}),t.fill(),t.stroke(),t.fillStyle=a.backgroundColor,t.beginPath(),He(t,{x:i,y:g+1,w:h-2,h:l-2,radius:r}),t.fill()):(t.fillStyle=n.multiKeyBackground,t.fillRect(e,g,h,l),t.strokeRect(e,g,h,l),t.fillStyle=a.backgroundColor,t.fillRect(i,g+1,h-2,l-2))}t.fillStyle=this.labelTextColors[i]}drawBody(t,e,i){const{body:s}=this,{bodySpacing:n,bodyAlign:o,displayColors:a,boxHeight:r,boxWidth:l,boxPadding:h}=i,c=Si(i.bodyFont);let d=c.lineHeight,f=0;const g=Oi(i.rtl,this.x,this.width),p=function(i){e.fillText(i,g.x(t.x+f),t.y+d/2),t.y+=d+n},m=g.textAlign(o);let x,b,_,y,v,M,w;for(e.textAlign=o,e.textBaseline="middle",e.font=c.string,t.x=Ia(this,m,i),e.fillStyle=i.bodyColor,u(this.beforeBody,p),f=a&&"right"!==m?"center"===o?l/2+h:l+2+h:0,y=0,M=s.length;y<M;++y){for(x=s[y],b=this.labelTextColors[y],e.fillStyle=b,u(x.before,p),_=x.lines,a&&_.length&&(this._drawColorBox(e,t,y,g,i),d=Math.max(c.lineHeight,r)),v=0,w=_.length;v<w;++v)p(_[v]),d=c.lineHeight;u(x.after,p)}f=0,d=c.lineHeight,u(this.afterBody,p),t.y-=n}drawFooter(t,e,i){const s=this.footer,n=s.length;let o,a;if(n){const r=Oi(i.rtl,this.x,this.width);for(t.x=Ia(this,i.footerAlign,i),t.y+=i.footerMarginTop,e.textAlign=r.textAlign(i.footerAlign),e.textBaseline="middle",o=Si(i.footerFont),e.fillStyle=i.footerColor,e.font=o.string,a=0;a<n;++a)e.fillText(s[a],r.x(t.x),t.y+o.lineHeight/2),t.y+=o.lineHeight+i.footerSpacing}}drawBackground(t,e,i,s){const{xAlign:n,yAlign:o}=this,{x:a,y:r}=t,{width:l,height:h}=i,{topLeft:c,topRight:d,bottomLeft:u,bottomRight:f}=wi(s.cornerRadius);e.fillStyle=s.backgroundColor,e.strokeStyle=s.borderColor,e.lineWidth=s.borderWidth,e.beginPath(),e.moveTo(a+c,r),"top"===o&&this.drawCaret(t,e,i,s),e.lineTo(a+l-d,r),e.quadraticCurveTo(a+l,r,a+l,r+d),"center"===o&&"right"===n&&this.drawCaret(t,e,i,s),e.lineTo(a+l,r+h-f),e.quadraticCurveTo(a+l,r+h,a+l-f,r+h),"bottom"===o&&this.drawCaret(t,e,i,s),e.lineTo(a+u,r+h),e.quadraticCurveTo(a,r+h,a,r+h-u),"center"===o&&"left"===n&&this.drawCaret(t,e,i,s),e.lineTo(a,r+c),e.quadraticCurveTo(a,r,a+c,r),e.closePath(),e.fill(),s.borderWidth>0&&e.stroke()}_updateAnimationTarget(t){const e=this.chart,i=this.$animations,s=i&&i.x,n=i&&i.y;if(s||n){const i=Da[t.position].call(this,this._active,this._eventPosition);if(!i)return;const o=this._size=Ta(this,t),a=Object.assign({},i,this._size),r=Ea(e,t,a),l=Ra(t,a,r,e);s._to===l.x&&n._to===l.y||(this.xAlign=r.xAlign,this.yAlign=r.yAlign,this.width=o.width,this.height=o.height,this.caretX=i.x,this.caretY=i.y,this._resolveAnimations().update(this,l))}}_willRender(){return!!this.opacity}draw(t){const e=this.options.setContext(this.getContext());let i=this.opacity;if(!i)return;this._updateAnimationTarget(e);const s={width:this.width,height:this.height},n={x:this.x,y:this.y};i=Math.abs(i)<.001?0:i;const o=ki(e.padding),a=this.title.length||this.beforeBody.length||this.body.length||this.afterBody.length||this.footer.length;e.enabled&&a&&(t.save(),t.globalAlpha=i,this.drawBackground(n,t,s,e),Ai(t,e.textDirection),n.y+=o.top,this.drawTitle(n,t,e),this.drawBody(n,t,e),this.drawFooter(n,t,e),Ti(t,e.textDirection),t.restore())}getActiveElements(){return this._active||[]}setActiveElements(t,e){const i=this._active,s=t.map((({datasetIndex:t,index:e})=>{const i=this.chart.getDatasetMeta(t);if(!i)throw new Error("Cannot find a dataset at index "+t);return{datasetIndex:t,element:i.data[e],index:e}})),n=!f(i,s),o=this._positionChanged(s,e);(n||o)&&(this._active=s,this._eventPosition=e,this._ignoreReplayEvents=!0,this.update(!0))}handleEvent(t,e,i=!0){if(e&&this._ignoreReplayEvents)return!1;this._ignoreReplayEvents=!1;const s=this.options,n=this._active||[],o=this._getActiveElements(t,n,e,i),a=this._positionChanged(o,t),r=e||!f(o,n)||a;return r&&(this._active=o,(s.enabled||s.external)&&(this._eventPosition={x:t.x,y:t.y},this.update(!0,e))),r}_getActiveElements(t,e,i,s){const n=this.options;if("mouseout"===t.type)return[];if(!s)return e.filter((t=>this.chart.data.datasets[t.datasetIndex]&&void 0!==this.chart.getDatasetMeta(t.datasetIndex).controller.getParsed(t.index)));const o=this.chart.getElementsAtEventForMode(t,n.mode,n,i);return n.reverse&&o.reverse(),o}_positionChanged(t,e){const{caretX:i,caretY:s,options:n}=this,o=Da[n.position].call(this,t,e);return!1!==o&&(i!==o.x||s!==o.y)}}var Na={id:"tooltip",_element:Wa,positioners:Da,afterInit(t,e,i){i&&(t.tooltip=new Wa({chart:t,options:i}))},beforeUpdate(t,e,i){t.tooltip&&t.tooltip.initialize(i)},reset(t,e,i){t.tooltip&&t.tooltip.initialize(i)},afterDraw(t){const e=t.tooltip;if(e&&e._willRender()){const i={tooltip:e};if(!1===t.notifyPlugins("beforeTooltipDraw",{...i,cancelable:!0}))return;e.draw(t.ctx),t.notifyPlugins("afterTooltipDraw",i)}},afterEvent(t,e){if(t.tooltip){const i=e.replay;t.tooltip.handleEvent(e.event,i,e.inChartArea)&&(e.changed=!0)}},defaults:{enabled:!0,external:null,position:"average",backgroundColor:"rgba(0,0,0,0.8)",titleColor:"#fff",titleFont:{weight:"bold"},titleSpacing:2,titleMarginBottom:6,titleAlign:"left",bodyColor:"#fff",bodySpacing:2,bodyFont:{},bodyAlign:"left",footerColor:"#fff",footerSpacing:2,footerMarginTop:6,footerFont:{weight:"bold"},footerAlign:"left",padding:6,caretPadding:2,caretSize:5,cornerRadius:6,boxHeight:(t,e)=>e.bodyFont.size,boxWidth:(t,e)=>e.bodyFont.size,multiKeyBackground:"#fff",displayColors:!0,boxPadding:0,borderColor:"rgba(0,0,0,0)",borderWidth:0,animation:{duration:400,easing:"easeOutQuart"},animations:{numbers:{type:"number",properties:["x","y","width","height","caretX","caretY"]},opacity:{easing:"linear",duration:200}},callbacks:Va},defaultRoutes:{bodyFont:"font",footerFont:"font",titleFont:"font"},descriptors:{_scriptable:t=>"filter"!==t&&"itemSort"!==t&&"external"!==t,_indexable:!1,callbacks:{_scriptable:!1,_indexable:!1},animation:{_fallback:!1},animations:{_fallback:"animation"}},additionalOptionScopes:["interaction"]};return Tn.register(Un,$o,go,t),Tn.helpers={...Hi},Tn._adapters=In,Tn.Animation=As,Tn.Animations=Ts,Tn.animator=bt,Tn.controllers=nn.controllers.items,Tn.DatasetController=js,Tn.Element=$s,Tn.elements=go,Tn.Interaction=Ki,Tn.layouts=ls,Tn.platforms=Ds,Tn.Scale=tn,Tn.Ticks=ae,Object.assign(Tn,Un,$o,go,t,Ds),Tn.Chart=Tn,"undefined"!=typeof window&&(window.Chart=Tn),Tn}));
    </script>

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
        .account-menu-plan { display: block; margin-top: 4px; color: var(--md-text-muted); font-size: 0.75rem; }
        .account-menu-identity strong { display: block; overflow: hidden; color: var(--md-on-surface); font-size: 0.9rem; text-overflow: ellipsis; }
        .account-menu-item {
            display: flex; align-items: center; min-height: 42px; margin-top: 5px; padding: 0 10px;
            border-radius: 6px; color: var(--md-on-surface); text-decoration: none; font-size: 0.9rem;
        }
        button.account-menu-item { width: 100%; border: 0; background: transparent; font: inherit; font-size: 0.9rem; text-align: left; cursor: pointer; }
        .account-menu-item:hover { background: var(--md-menu-hover); }
        /* Level brain (Profil) in the account menu */
        .menu-ico { display: inline-flex; align-items: center; justify-content: center; width: 58px; margin-right: 10px; flex-shrink: 0; }
        .menu-ico img:not([src]) { visibility: hidden; }
        .menu-brain { width: 32px; height: 32px; border-radius: 50%; border: 2px solid var(--md-aktiva-green); object-fit: cover; }
        .menu-brain.is-locked { filter: grayscale(1); opacity: 0.75; border-color: var(--md-border); }
        .menu-lvl { margin-left: auto; padding: 1px 8px; border-radius: 999px; font-size: 0.72rem; font-weight: 700; color: var(--md-aktiva-green); background: rgba(236,64,122,0.14); }
        .menu-lvl:empty { display: none; }
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
        /* main word list: rows change in place instead of the whole list reloading */
        #wordTableBody tr { transition: opacity 0.25s ease, transform 0.25s ease; }
        #wordTableBody tr.row-removing { opacity: 0; transform: translateX(24px); pointer-events: none; }
        #wordTableBody tr.row-updated { animation: row-flash 1.2s ease-out; }
        @keyframes row-flash { from { background-color: rgba(102, 187, 106, 0.28); } }
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

        /* Training settings in the vokabiq style */
        #gameSetupCard .setup-title { display: flex; align-items: center; font-size: 1.3rem; font-weight: 700; margin-bottom: 1.2rem; }
        #gameSetupCard .setup-label { font-size: 0.8rem; font-weight: 700; letter-spacing: 0.04em; text-transform: uppercase; color: var(--md-text-muted); margin-bottom: 8px; }
        .mode-tiles { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; margin-bottom: 1.2rem; }
        .mode-tile {
            display: flex; flex-direction: column; align-items: center; gap: 8px;
            padding: 14px 8px; border-radius: 12px; cursor: pointer;
            background: var(--md-surface-card); border: 1.5px solid var(--md-border);
            color: var(--md-on-surface); font: inherit; font-weight: 600; font-size: 0.92rem;
            transition: border-color 0.2s, background 0.2s, box-shadow 0.2s, transform 0.15s;
        }
        .mode-tile:hover { border-color: var(--md-aktiva-green); transform: translateY(-1px); }
        .mode-ico { display: grid; place-items: center; width: 40px; height: 40px; border-radius: 50%; background: rgba(236,64,122,0.13); color: var(--md-aktiva-green); transition: background 0.2s, color 0.2s; }
        .mode-ico svg { width: 22px; height: 22px; }
        .mode-tile.active { border-color: var(--md-aktiva-green); background: rgba(236,64,122,0.10); box-shadow: 0 2px 10px rgba(236,64,122,0.22); }
        .mode-tile.active .mode-ico { background: var(--md-aktiva-green); color: #fff; }
        @media (max-width: 480px) { .mode-tile { font-size: 0.8rem; padding: 12px 4px; } }
        /* Features not included in the user's plan: visible but locked */
        .plan-locked { opacity: 0.55; }
        .plan-locked::after { content: ' 🔒'; }
        .mode-tile.plan-locked { position: relative; }
        .mode-tile.plan-locked::after { position: absolute; top: 6px; right: 8px; font-size: 0.8rem; }
        #gameSetupCard .settings-fieldset { border-radius: 12px; }
        #gameSetupCard .settings-summary { color: var(--md-on-surface); font-weight: 700; }
        #gameSetupCard .settings-summary::after {
            content: ''; width: 9px; height: 9px; margin: 0 4px 0 10px; flex-shrink: 0;
            border-right: 2px solid var(--md-aktiva-green); border-bottom: 2px solid var(--md-aktiva-green);
            transform: rotate(45deg) translateY(-3px);
        }
        #gameSetupCard details[open] > .settings-summary::after { transform: rotate(225deg) translateY(-3px); }
        #gameSetupCard #statusSelectionGroup > .settings-summary::after { display: none; }
        #gameSetupCard .settings-summary::-webkit-details-marker { display: none; }
        #gameSetupCard summary.settings-summary { list-style: none; }
        #gameSetupCard .settings-controls-bar { align-items: center; }
        #gameSetupCard .settings-controls-bar > span { display: none; }
        #gameSetupCard .settings-action-link {
            text-decoration: none; color: var(--md-text-muted); font-weight: 600; font-size: 0.78rem;
            padding: 4px 12px; border-radius: 999px; border: 1px solid var(--md-border); background: var(--md-surface);
        }
        #gameSetupCard .settings-action-link:hover { color: var(--md-aktiva-green); border-color: var(--md-aktiva-green); }
        #gameSetupCard .status-pill:hover { border-color: var(--md-aktiva-green); }
        #gameSetupCard .status-pill.active { background: var(--md-aktiva-green); border-color: var(--md-aktiva-green); box-shadow: 0 2px 8px rgba(236,64,122,0.35); }
        #gameSetupCard .checkbox-label input[type="checkbox"] { accent-color: var(--md-aktiva-green); }
        #gameSetupCard .toggle-switch-btn { background: var(--md-surface-card); box-shadow: none; border-radius: 10px; }
        #gameSetupCard .toggle-switch-btn:hover { border-color: var(--md-aktiva-green); }
        #gameSetupCard .toggle-switch-badge { background: var(--md-aktiva-green); border-radius: 999px; padding: 4px 12px; }
        #gameSetupCard form select:focus, #gameSetupCard form input:focus { border-color: var(--md-aktiva-green); box-shadow: 0 0 0 3px rgba(236,64,122,0.22); }
        .setup-start-btn {
            width: 100%; margin-top: 1rem; padding: 12px; font-size: 1.05rem; font-weight: 700;
            display: inline-flex; align-items: center; justify-content: center; gap: 10px;
            background: linear-gradient(135deg, #7e57c2, var(--md-aktiva-green)); border-radius: 12px;
        }
        .setup-start-btn:hover { background: linear-gradient(135deg, #6a45ad, var(--md-aktiva-green-dark)); }
        .setup-start-btn .btn-ico svg { width: 16px; height: 16px; margin-left: 2px; }
        .setup-change-btn { font-size: 0.85rem; padding: 8px 14px; display: inline-flex; align-items: center; gap: 8px; }
        .setup-change-btn svg { width: 16px; height: 16px; }
        /* Long words shrink a little on small screens and wrap (with hyphen) instead of leaving the card */
        .word-display { font-size: clamp(1.7rem, 8vw, 2.7rem); font-weight: 700; margin: 1rem 0 0.3rem 0; text-align: center; max-width: 100%; overflow-wrap: anywhere; word-break: break-word; hyphens: auto; line-height: 1.15; }
        #gameConjugationDisplay, #gamePluralDisplay { overflow-wrap: anywhere; }
        .details-box { background: var(--md-surface-card); border: 1px solid var(--md-border); border-radius: 8px; padding: 16px; margin-bottom: 1.5rem; overflow-wrap: anywhere; }
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
        /* back on the main page: short loader, then the fresh list fades in */
        #tableResponsiveContainer.is-refreshing { display: none; }
        #tableResponsiveContainer.fade-in { animation: sheet-fade 0.3s ease-out; }

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
        /* Light theme: buttons are soft tints of their colour instead of solid fills */
        :root[data-theme="light"] .btn { --tone: var(--md-primary); }
        :root[data-theme="light"] .btn-success, :root[data-theme="light"] .form-toggle-bar .btn:nth-child(2) { --tone: #7e57c2; }
        :root[data-theme="light"] .btn-info { --tone: #0097a7; }
        :root[data-theme="light"] .btn-aktiva, :root[data-theme="light"] .form-toggle-bar .btn:nth-child(1) { --tone: var(--md-aktiva-green); }
        :root[data-theme="light"] .form-toggle-bar .btn:nth-child(3) { --tone: #3f7fd6; }
        :root[data-theme="light"] .btn-danger { --tone: #c23a52; }
        :root[data-theme="light"] .btn-passiv, :root[data-theme="light"] .btn-secondary { --tone: #556173; }
        :root[data-theme="light"] .setup-start-btn { --tone: #b4498f; }
        :root[data-theme="light"] .btn, :root[data-theme="light"] .form-toggle-bar .btn:nth-child(n) {
            background: color-mix(in srgb, var(--tone) 19%, var(--md-surface));
            color: color-mix(in srgb, var(--tone) 82%, #000);
            border: 1px solid color-mix(in srgb, var(--tone) 32%, transparent);
            box-shadow: none;
        }
        :root[data-theme="light"] .btn:hover, :root[data-theme="light"] .form-toggle-bar .btn:nth-child(n):hover {
            background: color-mix(in srgb, var(--tone) 29%, var(--md-surface));
            box-shadow: none;
        }
        :root[data-theme="light"] .btn .btn-ico { background: color-mix(in srgb, var(--tone) 22%, transparent); }
        :root[data-theme="light"] .rate-btn {
            background: color-mix(in srgb, var(--c) 17%, var(--md-surface));
            color: color-mix(in srgb, var(--c) 85%, #000);
        }
        :root[data-theme="light"] .rate-btn:hover { background: color-mix(in srgb, var(--c) 28%, var(--md-surface)); }
        :root[data-theme="light"] .rate-btn:focus-visible { outline-color: var(--c); }
        :root[data-theme="light"] .rate-group.is-picker .rate-btn.is-selected { box-shadow: inset 0 0 0 3px var(--c); }
        :root[data-theme="light"] #gameSetupCard .status-pill.active {
            background: color-mix(in srgb, var(--md-aktiva-green) 18%, var(--md-surface));
            color: var(--md-aktiva-green-dark); box-shadow: none;
        }
        :root[data-theme="light"] .mode-tile.active { box-shadow: none; }
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
        /* Instant theme switch: fading every element at once froze long word lists for seconds */
        html.theme-switching *:not(#_):not(.tt-knob), html.theme-switching *:not(#_)::before, html.theme-switching *:not(#_)::after { transition: none !important; }
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
                            <span class="account-menu-plan">Abo: <span id="menuPlanName"><?= htmlspecialchars(['free' => 'Gratis', 'ai' => 'KI', 'ai_full' => 'KI Plus'][$subscription] ?? 'Gratis', ENT_QUOTES, 'UTF-8') ?></span></span>
                        </div>
                        <a class="account-menu-item" href="profil"><span class="menu-ico"><img class="menu-brain" id="menuLevelBrain" alt=""></span>Mein Profil<span class="menu-lvl" id="menuLevelName"></span></a>
                        <a class="account-menu-item" href="./?api=export_csv">📥 CSV exportieren</a>
                        <a class="account-menu-item" href="datenschutz">🔒 Datenschutz</a>
                        <a class="account-menu-item" href="impressum">ℹ️ Impressum</a>
                        <a class="account-menu-item account-menu-logout" href="logout">↪ Abmelden</a>
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
                            <button type="button" class="btn btn-info ai-btn" onclick="generateAiImageForEditWord()" id="editImageBtn">Bild generieren</button>
                        </div>
                        <p class="word-hero-hint">Gib das Wort ein und lass die KI die restlichen Felder ausfüllen – oder fülle sie unten selbst aus.</p>
                        <div class="edit-image-container" id="editImageContainer" style="display: none;">
                            <img id="editImageTag" src="" alt="Wort Bild" onclick="openFullscreenImage(this.src)" title="Zum Vergrößern anklicken">
                        </div>
                    </div>

                    <div class="form-group-section">
                        <label for="sharepoint_list">Persönliche Liste</label>
                        <input type="text" id="sharepoint_list" name="sharepoint_list" placeholder="z.B., Reisen, Arbeit, Prüfung">

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
                            <input type="text" id="Konjugation" name="Konjugation" placeholder="z.B. er läuft, lief, ist gelaufen">

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
                                    <span>Persönliche Liste</span>
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

            <div id="listLoader" class="training-loader" role="status" aria-live="polite">
                <div class="training-loader-dots" aria-hidden="true"><span></span><span></span><span></span></div>
                <span>Wörter werden geladen…</span>
            </div>
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
                <h2 class="setup-title"><span class="h-ico" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09a1.65 1.65 0 0 0-1-1.51 1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09a1.65 1.65 0 0 0 1.51-1 1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33h0a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51h0a1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82v0a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg></span>Trainingseinstellungen</h2>
                <form id="gameSetupForm" onsubmit="startGameSession(event)">
                    <div class="setup-label">Trainingsmodus</div>
                    <div class="mode-tiles" role="radiogroup" aria-label="Trainingsmodus">
                        <button type="button" class="mode-tile active" data-mode="standard" role="radio" aria-checked="true" onclick="pickGameMode(this)"><span class="mode-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="6" width="14" height="15" rx="2"/><path d="M7 3h12a2 2 0 0 1 2 2v12"/></svg></span><span>Karteikarten</span></button>
                        <button type="button" class="mode-tile" data-mode="der_die_das" role="radio" aria-checked="false" onclick="pickGameMode(this)"><span class="mode-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 7h16M4 12h10M4 17h6"/><circle cx="18" cy="17" r="3"/></svg></span><span>Der-Die-Das</span></button>
                        <button type="button" class="mode-tile" data-mode="deutsch_meister" role="radio" aria-checked="false" onclick="pickGameMode(this)"><span class="mode-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7l4.5 4L12 4l4.5 7L21 7l-2 12H5z"/></svg></span><span>Deutsch Meister</span></button>
                    </div>
                    <select id="gameModeSelect" name="game_mode" hidden onchange="onGameModeChange()">
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
                            <summary class="settings-summary">Erweiterte Einstellungen</summary>
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
                                            <summary class="settings-summary">Persönliche Listen auswählen (Optional)</summary>
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


                    <button type="submit" class="btn setup-start-btn"><span class="btn-ico" aria-hidden="true"><svg viewBox="0 0 24 24" fill="currentColor"><path d="M8 5.5v13a1 1 0 0 0 1.5.86l10.5-6.5a1 1 0 0 0 0-1.72L9.5 4.64A1 1 0 0 0 8 5.5z"/></svg></span>Mit dem Üben beginnen</button>
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
                        <button type="button" onclick="showGameSetup()" class="btn btn-secondary setup-change-btn"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09a1.65 1.65 0 0 0-1-1.51 1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09a1.65 1.65 0 0 0 1.51-1 1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33h0a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51h0a1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82v0a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>Trainingseinstellungen ändern</button>
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
                        <button type="button" onclick="showGameSetup()" class="btn btn-secondary setup-change-btn"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09a1.65 1.65 0 0 0-1-1.51 1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09a1.65 1.65 0 0 0 1.51-1 1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33h0a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51h0a1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82v0a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>Trainingseinstellungen ändern</button>
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
                        <button type="button" onclick="showGameSetup()" class="btn btn-secondary setup-change-btn"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09a1.65 1.65 0 0 0-1-1.51 1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09a1.65 1.65 0 0 0 1.51-1 1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33h0a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51h0a1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82v0a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>Trainingseinstellungen ändern</button>
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
let editFromListWort = null; // word being edited from the main list, redrawn in place after saving
let cachedLists = <?= jsonForScript($initialLists) ?>;
let cachedThemen = <?= jsonForScript($initialThemen) ?>;
let cachedWortarten = <?= jsonForScript($initialWortarten) ?>;
let cachedScores = <?= jsonForScript($initialScores) ?>;
let cachedGrundverben = <?= jsonForScript($initialGrundverben) ?>;
let cachedPraefixe = <?= jsonForScript($initialPraefixe) ?>;
let cachedStatuses = <?= jsonForScript($initialStatuses) ?>;
let cachedThemaWortartenMap = <?= jsonForScript($initialThemaWortartenMap) ?>;

// Subscription plan: which AI features this user may use (the server enforces the same rules)
let PLAN = <?= jsonForScript(['name' => $subscription, 'llm' => planAllows($subscription, 'llm'), 'image' => planAllows($subscription, 'image')]) ?>;
const PLAN_UPGRADE_HINT = {
    llm: 'KI-Funktionen sind ab dem Abo „KI“ verfügbar. Upgrade, um sie freizuschalten.',
    image: 'Die Bildgenerierung ist nur im Abo „KI Plus“ enthalten. Upgrade, um sie freizuschalten.'
};

// Shows the upgrade hint and returns true when the feature is not in the user's plan
function planLocked(feature) {
    if (PLAN[feature]) return false;
    alert(PLAN_UPGRADE_HINT[feature]);
    return true;
}

// Marks the AI controls the plan does not include, so users see what an upgrade unlocks.
// Also unlocks them again, so it can run whenever the plan changes.
const PLAN_NAMES = { free: 'Gratis', ai: 'KI', ai_full: 'KI Plus' };
const WORD_HERO_HINT_DEFAULT = document.querySelector('.word-hero-hint')?.textContent || '';
function setPlanLock(el, feature) {
    if (!el) return;
    const locked = !PLAN[feature];
    el.classList.toggle('plan-locked', locked);
    if (locked) el.title = PLAN_UPGRADE_HINT[feature];
    else if (el.title === PLAN_UPGRADE_HINT[feature]) el.removeAttribute('title');
}
function applyPlanLocks() {
    const lockedIds = { llm: ['aiFillBtn', 'toggleSentenceWriterBtn', 'dddToggleSentenceBtn'], image: ['editImageBtn', 'generateImageBtn'] };
    Object.keys(lockedIds).forEach(feature => {
        lockedIds[feature].forEach(id => setPlanLock(document.getElementById(id), feature));
    });
    setPlanLock(document.querySelector('.mode-tile[data-mode="deutsch_meister"]'), 'llm');
    const opt = document.querySelector('#gameModeSelect option[value="deutsch_meister"]');
    if (opt) opt.disabled = !PLAN.llm;
    const hint = document.querySelector('.word-hero-hint');
    if (hint) hint.textContent = PLAN.llm ? WORD_HERO_HINT_DEFAULT : 'KI-Ausfüllen ist ab dem Abo „KI“ verfügbar. Fülle die Felder unten selbst aus.';
    const menuPlan = document.getElementById('menuPlanName');
    if (menuPlan) menuPlan.textContent = PLAN_NAMES[PLAN.name] || 'Gratis';
}
applyPlanLocks();

// The plan can change in the profile while this page stays open (other tab, back button,
// app in the background): ask the server again whenever the page becomes visible.
async function refreshPlan() {
    try {
        const res = await fetch('index.php?api=get_plan&_ts=' + Date.now(), { cache: 'no-store' });
        const data = await res.json();
        if (!data || typeof data.llm !== 'boolean') return;
        if (data.name === PLAN.name && data.llm === PLAN.llm && data.image === PLAN.image) return;
        PLAN = data;
        applyPlanLocks();
    } catch (err) {
        console.error('Abo konnte nicht geladen werden', err);
    }
}
document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'visible') refreshPlan(); });
window.addEventListener('pageshow', e => { if (e.persisted) refreshPlan(); });

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
    if (planLocked('image')) return;
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
            window.location.href = 'login';
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
    if (planLocked('image')) return;
    // the image is stored on the saved word, so use the original name (not unsaved edits)
    let word = document.getElementById('original_wort').value;
    if (!word) {
        // New word: save it first so the image has a word to belong to
        const newWord = document.getElementById('Wort').value.trim();
        if (!newWord) {
            alert('Bitte gib zuerst das Wort ein.');
            return;
        }
        const result = await saveWordRequest();
        if (!result) return;
        if (!result.success) {
            alert(result.message || 'Fehler beim Speichern des Wortes.');
            return;
        }
        showAlert(result.message);
        todayReviewedCount++;
        updateDailyTrackerUI();
        refreshMetadata();
        loadDashboardData(true);
        // from now on the form edits the saved word
        document.getElementById('original_wort').value = newWord;
        document.getElementById('formTitle').textContent = 'Wort bearbeiten';
        document.getElementById('formSubmitBtn').textContent = 'Wort aktualisieren';
        word = newWord;
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
            window.location.href = 'login';
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
    if (planLocked('llm')) return;
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
            window.location.href = 'login';
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
const LEVEL_THUMBS = ["data:image/jpeg;base64,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", "data:image/jpeg;base64,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", "data:image/jpeg;base64,/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAAcFBQYFBAcGBgYIBwcICxILCwoKCxYPEA0SGhYbGhkWGRgcICgiHB4mHhgZIzAkJiorLS4tGyIyNTEsNSgsLSz/2wBDAQcICAsJCxULCxUsHRkdLCwsLCwsLCwsLCwsLCwsLCwsLCwsLCwsLCwsLCwsLCwsLCwsLCwsLCwsLCwsLCwsLCz/wAARCABIAEgDASIAAhEBAxEB/8QAGwAAAQUBAQAAAAAAAAAAAAAAAAMEBQYHAQL/xAAzEAACAQMCAwYGAQMFAAAAAAABAgMABBEFIQYSMRMiQVFhgRQjMnGRoUIHUrEzYsHw8f/EABoBAAEFAQAAAAAAAAAAAAAAAAQAAQIDBQb/xAApEQACAgEDAQYHAAAAAAAAAAABAgARAwQSIUEFEzFRobEiMmFxgZHw/9oADAMBAAIRAxEAPwDFAtKKmaFXNP7S1MrgAUbAmahcShtGkOwqUtdFkmZVVGZnOFUAkk+g6mr7wZ/T9dT099Uv5nt7FH7NFiUGad+mFB2AB2zvvnbbNXuxt7Dg+2uWhtzCxVu0lkcPKFA3XOBgegqsuBwJXtLVuNX+5i8nDNxbELcwSwE9O1jZM/kUk/D7GMuq8yjqwGVHv0rVGtobqygu9TmmufimDpZxN3Iwd1RtiXbpkdN8b1IS8Q3AlSGWJoIsNGq9NwcYIHl9qQZj0kciInO8+kwu50eSL+JqLmtmQ7itpl0XTL9p5FnMCucoqKCq567HcDPrVK4g4cksp3jYKWAyGXdWHmPwfsRVkrXIy8k2JQmXBop3dW5jYgiimhgaxOQpzMKvnAvDEnEGsx2iP2SBTLLJjPIgxkgHqSSAAfE+lUuyTmkFa9/Ti2uIIrq7WHNrMBa84O4de+dvLDDfzqORti3I48Zz5QgFy8G2W3srOLSLSY2GnkIHZhzHrv6nJJyPWq9q8Ftf8VWFreK0kLFmnUOQZAFyFJHgSRn0B86e2+v6ZDxLFp7T3nxN2rJb9qGEEnIcMsZ6Eggg+uRmn8mniTXPiwoX5XLkD1yaEGQDxhzaTJYIkXqtvcm9ivbaAJBAQqugCnu+QFQF9ZvJeiZrxbaIkc7MvNjPiPE75z96sfEWs2Nj2Nvdaq1g5ZQrhCygnPKHOCoBwcAkZxSN3YwL28wiQzGJlyegJx3see37qWLLzKdVoSibz0lO06G6uL0ae8xjSYM4mAySApOKVmjlu4lheXnZRhGKjBzjusfuBgio+TFsyyuTlO5tuPLp/wB2pzHNILR0IaJWkXOVOcA+XUeH4o/xmPtNkVx1+vNe0pmuWXZyEhSvXIPUHxFFTfEkfO07FSpMjNg+ROQfcEfuiomXY22gr5SpWB+YK1LgrVp7WCW3IzbQnttt2YsPpHupP4rKLR+VxWpcI6zp1vpKW95p0dwjDLMH5Z0bpzK3kcdDt60PqFLJQHM1ez8owajvH+WqMeaDwbYNxcNfE03JFcNPHDJklWYkkA5xjLE0+g13jd9fayk0uxjt1ugolEUhDQc31c/Nyju++fCvEWsW8N3yJfZtzupK80vqvKPEYO/SnF7xJZCAxWtrPK5H+tcTkEfZEwB7mszblyHwnRZtVpMPKm758/72kVx9whLq2pTXQ1WWKwumjMtuXblEiryK/KNieX/mpW4mN98HY2JzLOREnaeAA3ZvQAZNRycSSSWUqXHzZoifADmGMgkfr2p1YLFDoaahFKvxM+zTOCc+i+memNtqIxqwIDdIJrHxdzeMElufx9onJpulaLfiSG5kuriIc7PLgAN5gDbbwqMj1L49rgOh+LlYn6TvnI5j6Y/Neb1oEPOtsJGV+/JK+C5PTA6bUrHFEIpb65PZRx4JVXPMMf7h4k4A/wDa00WuZyDkseZCcXPGty8aMG7KNImI/uGSR7cwHtRUBrF18pIv7FAP38aKcx8akizK7E+CD5VaLTiOSSw06xnWMR2bELN/IIc90+m/6FVFWpxFKV3zVZUNVzRTK+IME6ijNBFwJLJihDG3cTpg5BX+Q/z+aVkueZspup3HqKplhqklpLzxkYIIKt9Jz6U8t9QjeaNbuSRrf6WVTgKPMDxx5HwqXAFyhF3uFJq5ZNM1zTbONIbzSbSe9UkmaSPtGffr4+fTauazxNdagcjHLH3gqEfvH0j9+lRV5PZ2VzJbW8kNxAyAsVAK53H+MU70K606W1ktpzHFIkZBZyArpjB98dfzQ2xR8Ymyupfc2mYAEesVkN9fQqswS0tlIdpOfPMR0Jc4wB5AfmmOo6sxUgzyzIrHsu1b6RjGcdAT1898VCSX68i4Cggdcfuo+e6Zyd6KHEw8l5eKoTt5cmVzvRTJ2zRTS1VAFTwDXsNRRSk50yOD3VB96PiJx0WiilGoTvxVwP4/quG5nI3XPtRRSioTyZpid0FdLE9aKKUeJsaKKKaKf//Z", "data:image/jpeg;base64,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", "data:image/jpeg;base64,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", "data:image/jpeg;base64,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", "data:image/jpeg;base64,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", "data:image/jpeg;base64,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"];
const LEVEL_STORE_KEY = 'vq-level-<?= (int)$uid ?>';

// On phones only a few levels around the current one are shown
const LEVEL_MOBILE_MQ = window.matchMedia('(max-width: 760px)');
const LEVEL_MOBILE_COUNT = 4;
let lastLevelCount = 0;
LEVEL_MOBILE_MQ.addEventListener?.('change', () => renderLevelPath(lastLevelCount));

// Account menu: Profil shows the brain of the current level
function updateMenuBrains(reached) {
    const img = document.getElementById('menuLevelBrain');
    if (img) {
        img.src = LEVEL_THUMBS[Math.max(0, reached)];
        img.classList.toggle('is-locked', reached < 0);
    }
    const name = document.getElementById('menuLevelName');
    if (name) name.textContent = reached >= 0 ? LEVELS[reached].name : '';
}
function renderLevelPath(aktivaCount) {
    const box = document.getElementById('levelPath');
    if (!box) return;

    const n = Math.max(0, parseInt(aktivaCount, 10) || 0);
    lastLevelCount = n;
    const fmt = v => v.toLocaleString('de-DE');

    let reached = -1; // highest level reached (-1 = none yet)
    LEVELS.forEach((l, i) => { if (n >= l.min) reached = i; });
    updateMenuBrains(reached);
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
            window.location.href = 'login';
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
        if (!opts.skipLoad) refreshDashboardSmoothly();
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

// Coming back to the main page: show a short loader instead of the old list jumping to the new one
async function refreshDashboardSmoothly() {
    const table = document.getElementById('tableResponsiveContainer');
    const loader = document.getElementById('listLoader');
    table.classList.remove('fade-in');
    table.classList.add('is-refreshing');
    loader.classList.add('active');
    window.scrollTo({ top: 0 });
    await Promise.all([loadDashboardData(true), refreshMetadata(), new Promise(r => setTimeout(r, 250))]);
    loader.classList.remove('active');
    table.classList.remove('is-refreshing');
    void table.offsetWidth; // restart the fade
    table.classList.add('fade-in');
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
        if (data.error === 'Unauthorized') { window.location.href = 'login'; return; }

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

function pickGameMode(tile) {
    if (tile.dataset.mode === 'deutsch_meister' && planLocked('llm')) return;
    const sel = document.getElementById('gameModeSelect');
    sel.value = tile.dataset.mode;
    syncGameModeTiles();
    onGameModeChange();
}
function syncGameModeTiles() {
    const mode = document.getElementById('gameModeSelect').value;
    document.querySelectorAll('.mode-tile').forEach(t => {
        const on = t.dataset.mode === mode;
        t.classList.toggle('active', on);
        t.setAttribute('aria-checked', on ? 'true' : 'false');
    });
}
function showGameSetup() {
    activeGameSettings = null;
    syncGameModeTiles();
    showGameCard('gameSetupCard');
}

async function startGameSession(e) {
    e.preventDefault();
    const form = document.getElementById('gameSetupForm');
    const formData = new FormData(form);
    const mode = formData.get('game_mode') || 'standard';
    if (mode === 'deutsch_meister' && planLocked('llm')) return;
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
            window.location.href = 'login';
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
            window.location.href = 'login';
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
            window.location.href = 'login';
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
        window.location.href = 'login';
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
            window.location.href = 'login';
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
    if (planLocked('llm')) return;
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
            window.location.href = 'login';
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
            window.location.href = 'login';
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
        window.location.href = 'login';
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
        window.location.href = 'login';
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
        window.location.href = 'login';
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
    if (planLocked('llm')) return;
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
            window.location.href = 'login';
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
        window.location.href = 'login';
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
            window.location.href = 'login';
            return;
        }

        todayReviewedCount++;
        updateDailyTrackerUI();
        showAlert(`Wort '${wort}' aktualisiert.`);
        refreshMetadata();
        if (!data.status || !updateWordInList(wort, { Score: data.score, Status: data.status })) loadDashboardData(true);
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
            window.location.href = 'login';
            return;
        }
        if (result.success) {
            todayReviewedCount++;
            updateDailyTrackerUI();
            showAlert(result.message || `Wort '${wort}' ist jetzt aktiv.`);
            refreshMetadata();
            if (!result.status || !updateWordInList(wort, { Score: result.score, Status: result.status })) loadDashboardData(true);
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
        window.location.href = 'login';
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
        if (data.error === 'Unauthorized') { window.location.href = 'login'; return; }

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
    words.forEach(row => tbody.appendChild(buildWordRow(row)));
}

function buildWordRow(row) {
    const artClass = getArticleColorClass(row.Artikel);

    const tr = document.createElement('tr');
    tr.dataset.wort = row.Wort || '';
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
            <div class="rate-group action-group" role="group" aria-label="Wort-Aktionen"><button type="button" class="rate-btn a-edit" onclick="editWordFromList('${escapeJs(row.Wort)}')" title="Bearbeiten"><span class="rate-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 20h4L19 9l-4-4L4 16z"/><path d="M13.5 6.5l4 4"/></svg></span><span>Bear&shy;beiten</span></button><button type="button" class="rate-btn a-train" onclick="trainSpecificWord('${escapeJs(row.Wort)}')" title="Üben"><span class="rate-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M13 2L4 14h7l-1 8 9-12h-7z"/></svg></span><span>Üben</span></button><button type="button" class="rate-btn a-delete" onclick="deleteWord('${escapeJs(row.Wort)}')" title="Löschen"><span class="rate-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 7h16M9 7V4h6v3M6.5 7l1 13h9l1-13M10 11v5M14 11v5"/></svg></span><span>Löschen</span></button></div>
        </td>
    `;
    return tr;
}

// --- Main word list: change one row in place instead of reloading the whole list ---
function findWordRow(wort) {
    return Array.from(document.getElementById('wordTableBody').rows)
        .find(tr => tr.dataset.wort === wort && !tr.classList.contains('row-removing')) || null;
}

function flashWordRow(tr) {
    tr.classList.remove('row-updated');
    void tr.offsetWidth; // restart the animation
    tr.classList.add('row-updated');
}

// With a status filter on, a word that left that status leaves the list
function wordMatchesStatusFilter(word) {
    const status = document.getElementById('filterStatus').value.toLowerCase().trim();
    return !status || (word.Status || '').toLowerCase().trim() === status;
}

// Returns false when the word isn't shown, so the caller can fall back to a full reload
function updateWordInList(wort, changes) {
    const idx = masterWordsList.findIndex(w => w.Wort === wort);
    const tr = findWordRow(wort);
    if (idx < 0 || !tr) return false;
    const word = Object.assign({}, masterWordsList[idx], changes);
    if (!wordMatchesStatusFilter(word)) return removeWordFromList(wort);
    masterWordsList[idx] = word;
    const fresh = buildWordRow(word);
    // keep an open translation open
    const oldTrans = tr.querySelector('.story-word-trans');
    if (oldTrans && oldTrans.style.display !== 'none') {
        const t = fresh.querySelector('.story-word-trans');
        const b = t.nextElementSibling;
        t.style.display = oldTrans.style.display;
        if (b) b.textContent = oldTrans.nextElementSibling.textContent;
    }
    tr.replaceWith(fresh);
    flashWordRow(fresh);
    return fresh;
}

function removeWordFromList(wort) {
    const idx = masterWordsList.findIndex(w => w.Wort === wort);
    const tr = findWordRow(wort);
    if (idx < 0 || !tr) return false;
    masterWordsList.splice(idx, 1);
    currentOffset--; // the server list shifted by one, so the next page starts one word earlier
    tr.classList.add('row-removing');
    setTimeout(() => {
        tr.remove();
        if (masterWordsList.length === 0) renderTable(masterWordsList);
    }, 260);
    return true;
}

function editWordFromList(wort) {
    const row = masterWordsList.find(w => w.Wort === wort);
    if (!row) return;
    editReturnTraining = null;
    editFromListWort = wort;
    editWord(row);
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
    if (PLAN.llm && !document.getElementById('aiFillBtn').disabled) fillWordWithAI();
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
    editFromListWort = null;
    document.getElementById('wordForm').reset();
    document.getElementById('original_wort').value = '';
    formCurrentScore = 0;
    setFormKenntnisse('');
    toggleVerbFields();
    loadEditImage(''); // no image yet, but the button is offered for new words too
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

// Sends the word form to the server; returns the server's answer, or null after a login redirect
async function saveWordRequest() {
    const formData = collectWordForm();
    const res = await fetch('index.php?api=save&_ts=' + Date.now(), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        cache: 'no-store',
        body: JSON.stringify(formData)
    });
    const result = await res.json();
    if (result.error === 'Unauthorized') {
        window.location.href = 'login';
        return null;
    }
    result.formData = formData;
    return result;
}

function collectWordForm() {
    return {
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
}

async function submitWordForm(e) {
    e.preventDefault();
    const result = await saveWordRequest();
    if (!result) return;
    const formData = result.formData;

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
        const listWort = editFromListWort;
        resetForm();
        refreshMetadata();
        const row = (listWort && result.word) ? updateWordInList(listWort, result.word) : false;
        if (row && row !== true) {
            row.scrollIntoView({ block: 'center', behavior: 'smooth' });
        } else if (!row) {
            loadDashboardData(true);
        }
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
        window.location.href = 'login';
        return;
    }
    if (result.success) {
        showAlert(result.message);
        refreshMetadata();
        if (!removeWordFromList(wort)) loadDashboardData(true);
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