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

// --- CONFIGURATION: API keys come from config.php (never hardcode them here) ---
define('GEMINI_API_KEY', $config['gemini_api_key'] ?? '');
define('RAPIDAPI_KEY', $config['rapidapi_key'] ?? '');

if (isset($pdo) && method_exists($pdo, 'setAttribute')) {
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
}

// --- HELPERS ---
const WORD_COLS = "sharepoint_list, Thema, Wortarten, Wort, Artikel, Plural, Score, Status, Created, Modified, NachsteUbungDatum, VerbFlag, Konjugation, grundverb, praefix, praeposition_kollokation, Übersetzung, synonym, Beispiel";

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

// --- API / AJAX BACKEND CONTROLLER ---
if (isset($_GET['api'])) {
    $action = $_GET['api'] ?? '';

    if ($action === 'export_csv') {
        if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
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

        $stmt = $pdo->query("SELECT * FROM meine_wortschatz ORDER BY Wort ASC");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($rows)) {
            // image_data (huge base64) is excluded from the export
            $header = array_values(array_filter(array_keys($rows[0]), fn($k) => $k !== 'image_data'));
            fputcsv($output, $header, ';', '"', '\\');
            foreach ($rows as $row) {
                if (isset($row['VerbFlag'])) {
                    $row['VerbFlag'] = (int)$row['VerbFlag'] === 1 ? 1 : 0;
                }
                unset($row['image_data']);
                // Prevent CSV/Excel formula injection
                foreach ($row as $k => $v) {
                    if (is_string($v) && $v !== '' && in_array($v[0], ['=', '+', '-', '@'], true)) {
                        $row[$k] = "'" . $v;
                    }
                }
                fputcsv($output, $row, ';', '"', '\\');
            }
        }

        fclose($output);
        exit;
    }

    if ($action === 'view_image') {
        // SECURITY FIX: images are only visible to logged-in users
        if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
            http_response_code(401);
            exit;
        }

        $word = trim($_GET['word'] ?? '');
        if (empty($word)) {
            http_response_code(400);
            exit('Wort fehlt.');
        }

        $stmt = $pdo->prepare("SELECT image_data, mime_type FROM meine_wortschatz WHERE Wort = ? LIMIT 1");
        $stmt->execute([$word]);
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

    if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
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

        $update = $pdo->prepare("UPDATE meine_wortschatz SET image_data = ?, mime_type = ? WHERE Wort = ?");
        $update->execute([$dataUri, $mimeType, $word]);

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

        $query = "SELECT " . WORD_COLS . " FROM meine_wortschatz WHERE 1=1";
        $params = [];

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

        $query = "SELECT Status, COUNT(*) as count FROM meine_wortschatz WHERE 1=1";
        $params = [];

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
            $lists = $pdo->query("SELECT DISTINCT sharepoint_list FROM meine_wortschatz WHERE sharepoint_list IS NOT NULL AND sharepoint_list != '' ORDER BY sharepoint_list ASC")->fetchAll(PDO::FETCH_COLUMN) ?: [];
            $themen = $pdo->query("SELECT DISTINCT Thema FROM meine_wortschatz WHERE Thema IS NOT NULL AND Thema != '' ORDER BY Thema ASC")->fetchAll(PDO::FETCH_COLUMN) ?: [];
            $wortarten = $pdo->query("SELECT DISTINCT Wortarten FROM meine_wortschatz WHERE Wortarten IS NOT NULL AND Wortarten != '' ORDER BY Wortarten ASC")->fetchAll(PDO::FETCH_COLUMN) ?: [];
            $scores = $pdo->query("SELECT DISTINCT Score FROM meine_wortschatz WHERE Score IS NOT NULL ORDER BY Score ASC")->fetchAll(PDO::FETCH_COLUMN) ?: [];
            $grundverben = $pdo->query("SELECT DISTINCT grundverb FROM meine_wortschatz WHERE grundverb IS NOT NULL AND grundverb != '' ORDER BY grundverb ASC")->fetchAll(PDO::FETCH_COLUMN) ?: [];
            $praefixe = $pdo->query("SELECT DISTINCT praefix FROM meine_wortschatz WHERE praefix IS NOT NULL AND praefix != '' ORDER BY praefix ASC")->fetchAll(PDO::FETCH_COLUMN) ?: [];

            $statusesStmt = $pdo->query("SELECT DISTINCT Status FROM meine_wortschatz WHERE Status IS NOT NULL AND Status != '' ORDER BY FIELD(Status, 'aktiva', 'wiederholen', 'neu', 'passiv', 'warteschlange')");
            $statuses = $statusesStmt->fetchAll(PDO::FETCH_COLUMN) ?: [];

            $mappingStmt = $pdo->query("SELECT DISTINCT Thema, Wortarten FROM meine_wortschatz WHERE Thema IS NOT NULL AND Thema != '' AND Wortarten IS NOT NULL AND Wortarten != '' ORDER BY Thema ASC, Wortarten ASC");
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

            echo json_encode([
                'success' => true,
                'lists' => $lists,
                'themen' => $themen,
                'wortarten' => $wortarten,
                'scores' => $scores,
                'statuses' => $statuses,
                'grundverben' => $grundverben,
                'praefixe' => $praefixe,
                'themaWortartenMap' => $themaWortartenMap
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
        $score = !empty($input['Score']) ? (int)$input['Score'] : 0;
        $status = trim($input['Status'] ?? '') ?: 'neu';

        $konjugation = trim($input['Konjugation'] ?? '');
        $grundverb = $verbFlag === 1 ? trim($input['grundverb'] ?? '') : null;
        $praefix = $verbFlag === 1 ? trim($input['praefix'] ?? '') : null;
        $praeposition_kollokation = $verbFlag === 1 ? trim($input['praeposition_kollokation'] ?? '') : null;
        $originalWort = trim($input['original_wort'] ?? '');

        if (empty($wort)) {
            echo json_encode(['success' => false, 'message' => 'Das Wort darf nicht leer sein.']);
            exit;
        }

        if (!empty($originalWort)) {
            $stmt = $pdo->prepare("UPDATE meine_wortschatz SET sharepoint_list = ?, Thema = ?, Wort = ?, Artikel = ?, Plural = ?, Übersetzung = ?, synonym = ?, Wortarten = ?, Beispiel = ?, Score = ?, Status = ?, VerbFlag = ?, Konjugation = ?, grundverb = ?, praefix = ?, praeposition_kollokation = ?, Modified = NOW() WHERE Wort = ?");
            $stmt->execute([$sharepointList, $thema, $wort, $artikel, $plural, $uebersetzung, $synonym, $wortarten, $beispiel, $score, $status, $verbFlag, $konjugation, $grundverb, $praefix, $praeposition_kollokation, $originalWort]);
            echo json_encode(['success' => true, 'message' => "Wort '$wort' erfolgreich aktualisiert!"]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO meine_wortschatz (sharepoint_list, Thema, Wort, Artikel, Plural, Übersetzung, synonym, Wortarten, Beispiel, Score, Status, VerbFlag, Konjugation, grundverb, praefix, praeposition_kollokation, Created, Modified, NachsteUbungDatum) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), NOW())");
            $stmt->execute([$sharepointList, $thema, $wort, $artikel, $plural, $uebersetzung, $synonym, $wortarten, $beispiel, $score, $status, $verbFlag, $konjugation, $grundverb, $praefix, $praeposition_kollokation]);
            echo json_encode(['success' => true, 'message' => "Wort '$wort' erfolgreich hinzugefügt!"]);
        }
        exit;
    }

    if ($action === 'delete') {
        $input = jsonInput();
        $wortToDelete = $input['Wort'] ?? '';
        if (!empty($wortToDelete)) {
            $stmt = $pdo->prepare("DELETE FROM meine_wortschatz WHERE Wort = ?");
            $stmt->execute([$wortToDelete]);
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
            $stmt = $pdo->prepare("UPDATE meine_wortschatz SET Score = 10, Status = 'aktiva', Modified = NOW(), NachsteUbungDatum = DATE_ADD(NOW(), INTERVAL (10 * 10) DAY) WHERE Wort = ?");
            $stmt->execute([$wortToPromote]);
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

    if ($action === 'ai_story_game_next' || $action === 'ai_story_writer_next') {
        $input = jsonInput();
        [$where, $params] = buildGameFilter($input);

        $stmt = $pdo->prepare("SELECT " . WORD_COLS . " FROM meine_wortschatz WHERE 1=1" . $where . " ORDER BY RAND() LIMIT 5");
        $stmt->execute($params);
        $chosenWords = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (count($chosenWords) < 5) {
            $needed = 5 - count($chosenWords);
            $fallbackStmt = $pdo->prepare("SELECT " . WORD_COLS . " FROM meine_wortschatz ORDER BY RAND() LIMIT ?");
            $fallbackStmt->execute([$needed]);
            $chosenWords = array_merge($chosenWords, $fallbackStmt->fetchAll(PDO::FETCH_ASSOC));
        }

        if (empty($chosenWords)) {
            echo json_encode(['success' => false, 'error' => 'Keine passenden Wörter gefunden.']);
            exit;
        }

        foreach ($chosenWords as &$cw) {
            normalizeVerbFlag($cw);
        }
        unset($cw);

        if ($action === 'ai_story_writer_next') {
            echo json_encode(['success' => true, 'words' => $chosenWords]);
            exit;
        }

        $wordsListStr = implode(', ', array_column($chosenWords, 'Wort'));
        $ai = callDeepSeek("Schreibe eine kurze Geschichte auf Deutsch (maximal 300 Wörter), die unbedingt die folgenden 5 Wörter enthält: $wordsListStr. Verwende diese Wörter natürlich im Kontext.");
        if (!$ai['ok']) {
            echo json_encode(['success' => false, 'error' => $ai['error']]);
            exit;
        }

        echo json_encode([
            'success' => true,
            'story' => $ai['content'] !== '' ? $ai['content'] : "Es konnte keine Geschichte generiert werden.",
            'words' => $chosenWords
        ]);
        exit;
    }

    if ($action === 'ai_story_writer_check') {
        $input = jsonInput();
        $userStory = trim($input['story'] ?? '');
        $words = $input['words'] ?? [];

        if (empty($userStory)) {
            echo json_encode(['success' => false, 'error' => 'Die Geschichte darf nicht leer sein.']);
            exit;
        }

        $wordTokens = [];
        foreach ((array)$words as $w) {
            if (is_array($w) && isset($w['Wort'])) {
                $wordTokens[] = $w['Wort'];
            } elseif (is_string($w)) {
                $wordTokens[] = $w;
            }
        }
        $wordsListStr = implode(', ', $wordTokens);

        $userPrompt = "Der Benutzer hat eine eigene Geschichte auf Deutsch geschrieben und musste dabei folgende 5 Wörter verwenden: $wordsListStr.\n\n" .
                    "Hier ist die Geschichte des Benutzers:\n\"$userStory\"\n\n" .
                    "Überprüfe bitte:\n" .
                    "1. Ob alle 5 Wörter korrekt verwendet wurden.\n" .
                    "2. Korrigiere eventuelle Grammatik- oder Rechtschreibfehler in der Geschichte.\n" .
                    "3. Gib eine kurze, konstruktive Rückmeldung auf Deutsch.";

        $ai = callDeepSeek($userPrompt);
        if (!$ai['ok']) {
            echo json_encode(['success' => false, 'error' => $ai['error']]);
            exit;
        }

        echo json_encode([
            'success' => true,
            'correction' => $ai['content'] !== '' ? $ai['content'] : "Es konnte keine Korrektur generiert werden."
        ]);
        exit;
    }

    if ($action === 'der_die_das_next') {
        $stmt = $pdo->query("SELECT " . WORD_COLS . " FROM meine_wortschatz WHERE Status = 'aktiva' AND Artikel IN ('der', 'die', 'das') ORDER BY RAND() LIMIT 1");
        $word = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($word) {
            normalizeVerbFlag($word);
            echo json_encode(['success' => true, 'word' => $word]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Keine passenden aktiva Wörter mit Artikel (der, die, das) gefunden.']);
        }
        exit;
    }

    if ($action === 'deutsch_meister_next') {
        $stmt = $pdo->query("SELECT " . WORD_COLS . " FROM meine_wortschatz WHERE Status = 'aktiva' ORDER BY RAND() LIMIT 1");
        $word = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($word) {
            normalizeVerbFlag($word);
            echo json_encode(['success' => true, 'word' => $word]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Keine passenden aktiva Wörter gefunden.']);
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

        $points = 0;
        if ($evalResult === 'ok') {
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

        $stmt = $pdo->prepare("SELECT Score, Status FROM meine_wortschatz WHERE Wort = ?");
        $stmt->execute([$word]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            $currentScore = (int)$row['Score'];
            $newScore = max(0, $currentScore + $points);
            $newStatus = ($newScore >= 10) ? 'aktiva' : 'wiederholen';
            $newAddDatum = ($newStatus === 'aktiva') ? ($newScore * 10) : max(1, $newScore * 3);

            $update = $pdo->prepare("UPDATE meine_wortschatz SET Score = ?, Status = ?, Modified = NOW(), NachsteUbungDatum = DATE_ADD(NOW(), INTERVAL ? DAY) WHERE Wort = ?");
            $update->execute([$newScore, $newStatus, $newAddDatum, $word]);
        }

        $detailStmt = $pdo->prepare("SELECT " . WORD_COLS . " FROM meine_wortschatz WHERE Wort = ? LIMIT 1");
        $detailStmt->execute([$word]);
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

        $stmt = $pdo->prepare("SELECT Artikel, Score, Status FROM meine_wortschatz WHERE Wort = ?");
        $stmt->execute([$wort]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            echo json_encode(['success' => false, 'error' => 'Wort nicht gefunden.']);
            exit;
        }

        $correctArtikel = strtolower(trim($row['Artikel'] ?? ''));
        $currentScore = (int)$row['Score'];
        $isCorrect = ($guessedArtikel === $correctArtikel);

        $superBoosterTriggered = false;

        if ($isCorrect) {
            $currentStreak++;
            if ($currentStreak % 10 === 0) {
                $superBoosterTriggered = true;
                $newScore = $currentScore + 9;
            } else {
                $newScore = $currentScore + 3;
            }
        } else {
            $currentStreak = 0;
            $newScore = max(0, $currentScore - 1);
        }

        $newStatus = ($newScore < 10) ? 'wiederholen' : ($row['Status'] ?: 'aktiva');
        $newAddDatum = ($newStatus === 'aktiva') ? ($newScore * 10) : max(1, $newScore * 3);

        $update = $pdo->prepare("UPDATE meine_wortschatz SET Score = ?, Status = ?, Modified = NOW(), NachsteUbungDatum = DATE_ADD(NOW(), INTERVAL ? DAY) WHERE Wort = ?");
        $update->execute([$newScore, $newStatus, $newAddDatum, $wort]);

        echo json_encode([
            'success' => true,
            'is_correct' => $isCorrect,
            'correct_artikel' => $correctArtikel,
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

        $stmt = $pdo->prepare("SELECT Score, Status FROM meine_wortschatz WHERE Wort = ?");
        $stmt->execute([$word]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            $currentScore = (int)$row['Score'];
            $isAktiva = (strtolower(trim($row['Status'] ?? '')) === 'aktiva');

            if ($isGrammaticallyCorrect && $isIdiomaticallyCorrect && $isAktiva) {
                if ($action === 'check_sentence_booster') {
                    $pointsToAdd = 9;
                } else {
                    $pointsToAdd = $previousWasRight ? 9 : 3;
                }
            } else {
                $pointsToAdd = 1;
            }

            $newScore = $currentScore + $pointsToAdd;
            $newStatus = ($newScore >= 10) ? 'aktiva' : 'wiederholen';
            $newAddDatum = ($newStatus === 'aktiva') ? ($newScore * 10) : max(1, $newScore * 3);

            $update = $pdo->prepare("UPDATE meine_wortschatz SET Score = ?, Status = ?, Modified = NOW(), NachsteUbungDatum = DATE_ADD(NOW(), INTERVAL ? DAY) WHERE Wort = ?");
            $update->execute([$newScore, $newStatus, $newAddDatum, $word]);
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
        $mode = $input['mode'] ?? 'standard';
        $selectedStatuses = $input['statuses'] ?? [];
        $sortOrder = ($input['sort_order'] ?? 'ASC') === 'DESC' ? 'DESC' : 'ASC';

        if ($mode === 'quiz') {
            $statusClause = " AND Übersetzung IS NOT NULL AND Übersetzung != ''";
            $statusParams = [];
            if (!empty($selectedStatuses)) {
                $placeholders = implode(',', array_fill(0, count($selectedStatuses), '?'));
                $statusClause .= " AND Status IN ($placeholders)";
                $statusParams = $selectedStatuses;
            }

            $stmt = $pdo->prepare("SELECT " . WORD_COLS . " FROM meine_wortschatz WHERE 1=1" . $statusClause . " ORDER BY NachsteUbungDatum $sortOrder, Created $sortOrder LIMIT 500");
            $stmt->execute($statusParams);
            $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($candidates)) {
                $fallback = $pdo->query("SELECT " . WORD_COLS . " FROM meine_wortschatz WHERE Übersetzung IS NOT NULL AND Übersetzung != '' ORDER BY RAND() LIMIT 1");
                $targetWord = $fallback->fetch(PDO::FETCH_ASSOC);
            } else {
                $targetWord = $candidates[array_rand($candidates)];
            }

            if ($targetWord) {
                normalizeVerbFlag($targetWord);
                $wrongStmt = $pdo->prepare("SELECT DISTINCT Übersetzung FROM meine_wortschatz WHERE Übersetzung IS NOT NULL AND Übersetzung != '' AND Übersetzung != ? ORDER BY RAND() LIMIT 3");
                $wrongStmt->execute([$targetWord['Übersetzung']]);
                $wrongTrans = $wrongStmt->fetchAll(PDO::FETCH_COLUMN);

                $options = array_merge([$targetWord['Übersetzung']], $wrongTrans);
                shuffle($options);

                echo json_encode([
                    'mode' => 'quiz',
                    'word' => $targetWord,
                    'options' => $options,
                    'correct_translation' => $targetWord['Übersetzung']
                ]);
                exit;
            } else {
                $mode = 'standard';
            }
        }

        $specificWord = trim($input['specific_word'] ?? '');

        if (!empty($specificWord)) {
            $stmt = $pdo->prepare("SELECT " . WORD_COLS . " FROM meine_wortschatz WHERE Wort = ? LIMIT 1");
            $stmt->execute([$specificWord]);
            $word = $stmt->fetch(PDO::FETCH_ASSOC);
            $notice = "Spezifisches Wort wird geübt: '$specificWord'";

            if (!$word) {
                $notice = "Spezifisches Wort nicht gefunden. Stattdessen wird ein zufälliges Wort angezeigt.";
                $fallback = $pdo->query("SELECT * FROM (SELECT " . WORD_COLS . " FROM meine_wortschatz ORDER BY NachsteUbungDatum ASC, Created ASC LIMIT 1000) AS subset ORDER BY RAND() LIMIT 1");
                $word = $fallback->fetch(PDO::FETCH_ASSOC);
            }

            if ($word) {
                normalizeVerbFlag($word);
            }

            echo json_encode(['mode' => 'standard', 'word' => $word, 'notice' => $notice]);
            exit;
        }

        [$filterWhere, $params] = buildGameFilter($input, true);
        $query = "SELECT " . WORD_COLS . " FROM meine_wortschatz WHERE 1=1" . $filterWhere;

        $hasFilters = !empty($input['statuses']) || !empty($input['sharepoint_lists']) || !empty($input['themen']) || !empty($input['categories']);

        if ($hasFilters) {
            $countStmt = $pdo->prepare("SELECT COUNT(*) FROM meine_wortschatz WHERE 1=1" . $filterWhere);
            $countStmt->execute($params);
            $totalMatches = (int)$countStmt->fetchColumn();

            $dynamicLimit = max(1, (int)floor($totalMatches / 2));
        } else {
            $dynamicLimit = 1000;
        }

        $wrappedQuery = "SELECT * FROM ($query ORDER BY NachsteUbungDatum $sortOrder, Created $sortOrder LIMIT $dynamicLimit) AS subset ORDER BY RAND() LIMIT 1";

        $stmt = $pdo->prepare($wrappedQuery);
        $stmt->execute($params);
        $word = $stmt->fetch(PDO::FETCH_ASSOC);
        $notice = '';

        if (!$word) {
            $fallback = $pdo->query("SELECT * FROM (SELECT " . WORD_COLS . " FROM meine_wortschatz ORDER BY NachsteUbungDatum ASC, Created ASC LIMIT 1000) AS subset ORDER BY RAND() LIMIT 1");
            $word = $fallback->fetch(PDO::FETCH_ASSOC);
            $notice = "Keine Wörter gefunden, die den genauen Filtern entsprechen. Stattdessen wird ein zufälliges Wort aus der Warteschlange angezeigt.";
        }

        if ($word) {
            normalizeVerbFlag($word);
        }

        echo json_encode(['mode' => 'standard', 'word' => $word, 'notice' => $notice]);
        exit;
    }

    if ($action === 'game_answer') {
        $input = jsonInput();
        $wort = $input['wort'] ?? '';
        $result = $input['result'] ?? '';

        if (!empty($wort)) {
            $stmt = $pdo->prepare("SELECT Score, Status FROM meine_wortschatz WHERE Wort = ?");
            $stmt->execute([$wort]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($row) {
                $currentScore = (int)$row['Score'];
                $currentStatus = trim((string)$row['Status']);

                if ($result === 'sehr_gut') {
                    $newScore = $currentScore + 3;
                } elseif ($result === 'yes') {
                    $newScore = $currentScore + 1;
                } elseif ($result === 'wiederholen') {
                    $newScore = max(0, $currentScore - 1);
                } else {
                    $newScore = $currentScore;
                }

                $newStatus = ($result === 'passiv') ? 'passiv' : (($result === 'warteschlange') ? 'warteschlange' : (($newScore >= 10) ? 'aktiva' : 'wiederholen'));

                $newAddDatum = ($newStatus === 'aktiva') ? ($newScore * 10) : max(1, $newScore * 3);

                if ($result === 'wiederholen' && strtolower($currentStatus) !== 'wiederholen') {
                    $newScore = 1;
                    $newAddDatum = 3;
                    $newStatus = 'wiederholen';
                }

                $update = $pdo->prepare("UPDATE meine_wortschatz SET Score = ?, Status = ?, Modified = NOW(), NachsteUbungDatum = DATE_ADD(NOW(), INTERVAL ? DAY) WHERE Wort = ?");
                $update->execute([$newScore, $newStatus, $newAddDatum, $wort]);
            }
        }
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'suggest_sentence') {
        $input = jsonInput();
        $word = trim($input['word'] ?? '');

        if (empty($word)) {
            echo json_encode(['success' => false, 'error' => 'Das Wort darf nicht leer sein.']);
            exit;
        }

        $ai = callDeepSeek("Schreibe einen natürlichen Beispielsatz auf Deutsch für das Wort '$word' und erkläre kurz die Bedeutung auf Deutsch.");
        if (!$ai['ok']) {
            echo json_encode(['success' => false, 'error' => $ai['error']]);
            exit;
        }

        echo json_encode(['success' => true, 'suggestion' => $ai['content']]);
        exit;
    }

    if ($action === 'check_sentence') {
        $input = jsonInput();
        $sentence = trim($input['sentence'] ?? '');
        $word = trim($input['word'] ?? '');

        if (empty($sentence)) {
            echo json_encode(['success' => false, 'error' => 'Der Satz darf nicht leer sein.']);
            exit;
        }

        $ai = callDeepSeek("Überprüfe, ob das Wort '$word' im folgenden Satz korrekt und natürlich verwendet wurde. " .
                    "Korrigiere den Satz falls nötig und erkläre kurz die Bedeutung von '$word' im Kontext: " .
                    "'$sentence'");
        if (!$ai['ok']) {
            echo json_encode(['success' => false, 'error' => $ai['error']]);
            exit;
        }

        echo json_encode(['success' => true, 'correction' => $ai['content']]);
        exit;
    }

    // Unknown action
    echo json_encode(['success' => false, 'error' => 'Unbekannte Aktion.']);
    exit;
}

// Ensure login check for page render
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

try {
    $todayCountStmt = $pdo->query("SELECT COUNT(*) FROM meine_wortschatz WHERE DATE(Modified) = CURDATE()");
    $todayReviewedCount = (int)$todayCountStmt->fetchColumn();
} catch (Exception $e) {
    $todayReviewedCount = 0;
}

try {
    $initialWords = $pdo->query("SELECT " . WORD_COLS . " FROM meine_wortschatz ORDER BY Wort ASC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($initialWords as &$w) {
        normalizeVerbFlag($w);
    }
    unset($w);

    $initialLists = $pdo->query("SELECT DISTINCT sharepoint_list FROM meine_wortschatz WHERE sharepoint_list IS NOT NULL AND sharepoint_list != '' ORDER BY sharepoint_list ASC")->fetchAll(PDO::FETCH_COLUMN) ?: [];
    $initialThemen = $pdo->query("SELECT DISTINCT Thema FROM meine_wortschatz WHERE Thema IS NOT NULL AND Thema != '' ORDER BY Thema ASC")->fetchAll(PDO::FETCH_COLUMN) ?: [];
    $initialWortarten = $pdo->query("SELECT DISTINCT Wortarten FROM meine_wortschatz WHERE Wortarten IS NOT NULL AND Wortarten != '' ORDER BY Wortarten ASC")->fetchAll(PDO::FETCH_COLUMN) ?: [];
    $initialScores = $pdo->query("SELECT DISTINCT Score FROM meine_wortschatz WHERE Score IS NOT NULL ORDER BY Score ASC")->fetchAll(PDO::FETCH_COLUMN) ?: [];
    $initialGrundverben = $pdo->query("SELECT DISTINCT grundverb FROM meine_wortschatz WHERE grundverb IS NOT NULL AND grundverb != '' ORDER BY grundverb ASC")->fetchAll(PDO::FETCH_COLUMN) ?: [];
    $initialPraefixe = $pdo->query("SELECT DISTINCT praefix FROM meine_wortschatz WHERE praefix IS NOT NULL AND praefix != '' ORDER BY praefix ASC")->fetchAll(PDO::FETCH_COLUMN) ?: [];

    $initialStatusesStmt = $pdo->query("SELECT DISTINCT Status FROM meine_wortschatz WHERE Status IS NOT NULL AND Status != '' ORDER BY FIELD(Status, 'aktiva', 'wiederholen', 'neu', 'passiv', 'warteschlange')");
    $initialStatuses = $initialStatusesStmt->fetchAll(PDO::FETCH_COLUMN) ?: [];

    $initialMappingStmt = $pdo->query("SELECT DISTINCT Thema, Wortarten FROM meine_wortschatz WHERE Thema IS NOT NULL AND Thema != '' AND Wortarten IS NOT NULL AND Wortarten != '' ORDER BY Thema ASC, Wortarten ASC");
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
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mein Wortschatz - SPA</title>

    <meta name="theme-color" content="#1e88e5">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="Mein Wortschatz">

    <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><text y=%22.9em%22 font-size=%2290%22>📚</text></svg>">

    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <style>
        :root {
            --md-primary: #1e88e5;
            --md-primary-dark: #1565c0;
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
            --md-aktiva-green: #43a047;
            --md-aktiva-green-dark: #2e7d32;
            --md-warning-yellow: #ffb74d;
            --md-elevation-1: 0 1px 3px rgba(0,0,0,0.5), 0 1px 2px rgba(0,0,0,0.4);
            --md-elevation-2: 0 3px 6px rgba(0,0,0,0.6), 0 2px 4px rgba(0,0,0,0.5);
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
        .header-actions { display: flex; gap: 12px; align-items: center; flex-wrap: wrap; }
        .account-menu { position: relative; }
        .account-trigger {
            display: inline-flex; align-items: center; gap: 9px; min-height: 42px;
            padding: 6px 12px 6px 7px; border: 1px solid #454545; border-radius: 8px;
            background: #303030; color: var(--md-on-surface); cursor: pointer; list-style: none;
            font: inherit; font-size: 0.9rem; font-weight: 600;
        }
        .account-trigger::-webkit-details-marker { display: none; }
        .account-trigger:hover, .account-menu[open] .account-trigger { background: #3a3a3a; border-color: #666; }
        .account-trigger:focus-visible, .account-menu-item:focus-visible { outline: 2px solid #90caf9; outline-offset: 2px; }
        .account-avatar {
            display: grid; place-items: center; width: 28px; height: 28px; border-radius: 50%;
            background: #17466b; color: #bbdefb; font-size: 0.85rem;
        }
        .account-chevron {
            width: 7px; height: 7px; margin: -4px 0 0 3px;
            border-right: 1.5px solid currentColor; border-bottom: 1.5px solid currentColor;
            transform: rotate(45deg); transition: transform 0.18s ease;
        }
        .account-menu[open] .account-chevron { margin-top: 4px; transform: rotate(225deg); }
        .account-popover {
            position: absolute; z-index: 30; top: calc(100% + 8px); right: 0; width: 240px;
            padding: 8px; border: 1px solid #454545; border-radius: 8px;
            background: #252525; box-shadow: var(--md-elevation-2);
        }
        .account-menu-identity { padding: 9px 10px 12px; border-bottom: 1px solid var(--md-border); }
        .account-menu-caption { display: block; margin-bottom: 4px; color: var(--md-text-muted); font-size: 0.75rem; }
        .account-menu-identity strong { display: block; overflow: hidden; color: var(--md-on-surface); font-size: 0.9rem; text-overflow: ellipsis; }
        .account-menu-item {
            display: flex; align-items: center; min-height: 42px; margin-top: 5px; padding: 0 10px;
            border-radius: 6px; color: var(--md-on-surface); text-decoration: none; font-size: 0.9rem;
        }
        .account-menu-item:hover { background: #383838; }
        .account-menu-logout { color: #ff8a80; }
        .account-menu-logout:hover { background: #3b2424; }
        h1 { font-size: 1.5rem; font-weight: 500; margin: 0; color: #90caf9; display: flex; align-items: center; gap: 8px; }
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
            flex: 1; min-width: 200px; background: #333; height: 12px; border-radius: 6px; overflow: hidden; position: relative;
        }
        .tracker-progress-fill {
            background: linear-gradient(90deg, #1e88e5, var(--md-success)); height: 100%; width: 0%; transition: width 0.4s ease;
        }

        .alert {
            background-color: var(--md-primary-light); color: #90caf9; padding: 12px 16px;
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
        th { background-color: #242424; font-weight: 600; white-space: nowrap; cursor: pointer; color: var(--md-on-surface); position: sticky; top: 0; z-index: 10; }
        th a { color: var(--md-on-surface); text-decoration: none; display: flex; align-items: center; gap: 4px; }

        th:nth-child(1), td:nth-child(1) { width: 5%; }
        th:nth-child(2), td:nth-child(2) { width: 11%; }
        th:nth-child(3), td:nth-child(3) { width: 7%; }
        th:nth-child(4), td:nth-child(4) { width: 12%; }
        th:nth-child(5), td:nth-child(5) { width: 9%; }
        th:nth-child(6), td:nth-child(6) { width: 5%; }
        th:nth-child(7), td:nth-child(7) { width: 27%; }
        th:nth-child(8), td:nth-child(8) { width: 9%; }
        th:nth-child(9), td:nth-child(9) { width: 15%; }

        .wort-cell { font-size: 1.15rem; font-weight: 600; }
        .wort-der { color: #64b5f6; }
        .wort-die { color: #f06292; }
        .wort-das { color: #ffb74d; }
        .wort-other { color: #b0b0b0; }
        .kenntnisse-cell { display: flex; gap: 4px; flex-wrap: wrap; align-items: center; }
        .status-cell { font-weight: 500; }
        .aktion-cell { display: flex; gap: 4px; flex-wrap: wrap; align-items: center; }

        .view { display: none; }
        .view.active { display: block; }
        .form-container { display: none; margin-bottom: 1.5rem; }
        .form-container.active { display: block; }
        .form-toggle-bar { margin-bottom: 1.2rem; }

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
            color: #90caf9;
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
            color: #90caf9;
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
            background: #282828;
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
            background: rgba(255,255,255,0.03);
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
            background: #222;
        }
        .toggle-switch-badge {
            background: var(--md-primary);
            color: white;
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 0.85rem;
            font-weight: 600;
        }

        .word-display { font-size: 2.2rem; font-weight: 700; margin: 1rem 0 0.3rem 0; text-align: center; }
        .details-box { background: var(--md-surface-card); border: 1px solid var(--md-border); border-radius: 8px; padding: 16px; margin-bottom: 1.5rem; }
        .details-box p { margin: 8px 0; font-size: 0.95rem; }
        .action-row { display: flex; gap: 8px; margin-top: 1.5rem; flex-wrap: wrap; }
        .action-row button { flex: 1; min-width: 80px; padding: 14px; font-size: 0.85rem; }

        .quiz-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 16px;
            margin: 1.5rem 0;
        }
        .quiz-card {
            background: var(--md-surface-card);
            border: 2px solid var(--md-border);
            border-radius: 12px;
            padding: 20px;
            text-align: center;
            cursor: pointer;
            font-size: 1.1rem;
            font-weight: 500;
            transition: all 0.2s ease;
        }
        .quiz-card:hover {
            border-color: var(--md-primary);
            transform: translateY(-2px);
        }
        .quiz-card.correct {
            background-color: rgba(38, 166, 154, 0.2);
            border-color: var(--md-success);
        }
        .quiz-card.wrong {
            background-color: rgba(229, 57, 53, 0.2);
            border-color: var(--md-danger);
        }

        .timer-bar-container {
            width: 100%;
            background: #333;
            height: 10px;
            border-radius: 5px;
            overflow: hidden;
            margin-bottom: 1rem;
            box-shadow: inset 0 1px 3px rgba(0,0,0,0.5);
        }
        .timer-bar-fill {
            background: var(--md-success);
            height: 100%;
            width: 100%;
            transition: width 0.1s linear, background-color 0.3s ease;
        }

        .story-box {
            background: var(--md-surface-card);
            border: 1px solid var(--md-border);
            border-radius: 10px;
            padding: 20px;
            font-size: 1.05rem;
            line-height: 1.6;
            margin-bottom: 1.5rem;
            color: #eceff1;
        }

        .word-rating-item {
            background: var(--md-surface-card);
            border: 1px solid var(--md-border);
            border-radius: 8px;
            padding: 16px;
            margin-bottom: 12px;
            display: flex;
            flex-direction: column;
            gap: 12px;
            transition: opacity 0.3s ease;
        }
        .word-rating-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 8px;
        }
        .word-rating-buttons {
            display: flex;
            gap: 5px;
            flex-wrap: wrap;
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

        @media (max-width: 900px) {
            table, thead, tbody, th, td, tr { display: block; width: 100% !important; }
            thead tr { display: none; }
            table tr { background: var(--md-surface); border: 1px solid var(--md-border); border-radius: 8px; margin-bottom: 12px; padding: 14px; box-shadow: var(--md-elevation-1); }
            table td { border: none; padding: 6px 0; display: flex; justify-content: space-between; align-items: center; }
            table td::before { content: attr(data-label); font-weight: 600; color: var(--md-text-muted); font-size: 0.8rem; margin-right: 10px; }
            table td.wort-cell { justify-content: space-between; }
            table td.kenntnisse-cell, table td.aktion-cell { justify-content: flex-end; margin-top: 10px; padding-top: 10px; border-top: 1px solid var(--md-border); }
            .chart-wrapper { height: 260px; }
            .quiz-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>

<div class="modal-overlay" id="themaModalOverlay">
    <div class="modal-content">
        <h3 style="margin-top:0; color: #90caf9;" id="modalTitle">Auflösung</h3>
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
            <h1>📚 Mein Wortschatz</h1>
            <div class="header-actions">
                <button onclick="openGameSelection()" class="btn btn-success">🎮 Spiel starten</button>
                <button onclick="switchView('statistics')" class="btn btn-info">📊 Statistiken</button>
                <a href="index.php?api=export_csv" class="btn btn-secondary">📥 CSV exportieren</a>
                <details class="account-menu" id="accountMenu">
                    <summary class="account-trigger">
                        <span class="account-avatar" aria-hidden="true">👤</span>
                        <span>Konto</span>
                        <span class="account-chevron" aria-hidden="true"></span>
                    </summary>
                    <div class="account-popover">
                        <div class="account-menu-identity">
                            <span class="account-menu-caption">Angemeldet als</span>
                            <strong><?= htmlspecialchars((string)($_SESSION['username'] ?? 'Benutzer'), ENT_QUOTES, 'UTF-8') ?></strong>
                        </div>
                        <a class="account-menu-item" href="profile.php">👤 Mein Profil</a>
                        <a class="account-menu-item account-menu-logout" href="logout.php">↪ Abmelden</a>
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
            <button type="button" class="btn" onclick="openAddForm()" id="formToggleBtn">➕ Neues Wort hinzufügen</button>
        </div>

        <div class="form-container" id="formContainer">
            <div class="card">
                <h2 id="formTitle">Neues Wort hinzufügen</h2>
                <form id="wordForm" onsubmit="submitWordForm(event)">
                    <input type="hidden" id="original_wort" name="original_wort">

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

                        <label for="Wort">Wort (Primärschlüssel) *</label>
                        <div style="display: flex; gap: 8px; margin-bottom: 1rem;">
                            <input type="text" id="Wort" name="Wort" required style="margin-bottom: 0; flex: 1;">
                            <button type="button" class="btn btn-info" onclick="fillWordWithAI()" id="aiFillBtn" style="white-space: nowrap; padding: 10px 14px;">🤖 Mit KI ausfüllen</button>
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
                        <label for="Score">Punktzahl (Score)</label>
                        <input type="number" id="Score" name="Score" value="0">

                        <label for="Status">Status</label>
                        <input type="text" id="Status" name="Status" placeholder="z.B., neu, passiv, wiederholen, warteschlange" value="neu">
                    </div>

                    <div style="display: flex; gap: 12px; margin-top: 1rem;">
                        <button type="submit" class="btn" id="formSubmitBtn" style="flex: 1;">Wort speichern</button>
                        <button type="button" class="btn btn-secondary" onclick="resetForm()">Abbrechen</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="card">
            <h2>Wortschatz-Datenbank</h2>

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

            <form id="filterForm" onsubmit="event.preventDefault(); loadDashboardData(true);">
                <div class="filters">
                    <select id="filterList" name="sharepoint_list" onchange="loadDashboardData(true)">
                        <option value="">Alle Listen</option>
                        <?php foreach ($initialLists as $l): ?>
                            <option value="<?= htmlspecialchars($l) ?>"><?= htmlspecialchars($l) ?></option>
                        <?php endforeach; ?>
                    </select>

                    <select id="filterThema" name="thema" onchange="onDashboardThemaChange()">
                        <option value="">Alle Themen</option>
                        <?php foreach ($initialThemen as $thm): ?>
                            <option value="<?= htmlspecialchars($thm) ?>"><?= htmlspecialchars($thm) ?></option>
                        <?php endforeach; ?>
                    </select>

                    <select id="filterWortart" name="wortart" onchange="loadDashboardData(true)">
                        <option value="">Alle Wortarten</option>
                        <?php foreach ($initialWortarten as $wa): ?>
                            <option value="<?= htmlspecialchars($wa) ?>"><?= htmlspecialchars($wa) ?></option>
                        <?php endforeach; ?>
                    </select>

                    <select id="filterScore" name="score" onchange="loadDashboardData(true)">
                        <option value="">Alle Scores</option>
                        <?php foreach ($initialScores as $s): ?>
                            <option value="<?= htmlspecialchars($s) ?>">Score: <?= htmlspecialchars($s) ?></option>
                        <?php endforeach; ?>
                    </select>

                    <select id="filterStatus" name="status" onchange="loadDashboardData(true)">
                        <option value="">Alle Status</option>
                        <?php foreach ($initialStatuses as $st): ?>
                            <option value="<?= htmlspecialchars($st) ?>"><?= htmlspecialchars($st) ?></option>
                        <?php endforeach; ?>
                    </select>

                    <select id="filterVerbFlag" name="verb_flag" onchange="loadDashboardData(true)">
                        <option value="">Alle Wörter</option>
                        <option value="1">Nur Verben</option>
                        <option value="0">Kein Verb</option>
                    </select>

                    <select id="filterGrundverb" name="grundverb" onchange="loadDashboardData(true)">
                        <option value="">Alle Grundverben</option>
                        <?php foreach ($initialGrundverben as $gv): ?>
                            <option value="<?= htmlspecialchars($gv) ?>"><?= htmlspecialchars($gv) ?></option>
                        <?php endforeach; ?>
                    </select>

                    <select id="filterPraefix" name="praefix" onchange="loadDashboardData(true)">
                        <option value="">Alle Präfixe</option>
                        <?php foreach ($initialPraefixe as $pr): ?>
                            <option value="<?= htmlspecialchars($pr) ?>"><?= htmlspecialchars($pr) ?></option>
                        <?php endforeach; ?>
                    </select>

                    <button type="button" class="btn btn-secondary" onclick="resetFilters()" style="padding: 10px 14px;">Zurücksetzen</button>
                </div>
                <div class="filters">
                    <input type="text" id="filterSearch" placeholder="Wort suchen (beginnt mit)..." oninput="handleSearchInput()" style="flex: 1;">
                    <input type="text" id="filterTranslationSearch" placeholder="Übersetzung suchen..." oninput="handleTranslationSearchInput()" style="flex: 1;">
                </div>
            </form>

            <div class="table-responsive" id="tableResponsiveContainer">
                <table>
                    <thead>
                        <tr>
                            <th onclick="setSort('Artikel')">Artikel ↕</th>
                            <th onclick="setSort('Wort')">Wort ↕</th>
                            <th onclick="setSort('Plural')">Plural ↕</th>
                            <th>Übersetzung</th>
                            <th>Werkzeuge</th>
                            <th onclick="setSort('Score')">Score ↕</th>
                            <th>Kenntnisse</th>
                            <th onclick="setSort('Status')">Status ↕</th>
                            <th>Aktion</th>
                        </tr>
                    </thead>
                    <tbody id="wordTableBody">
                        <?php if (empty($initialWords)): ?>
                            <tr><td colspan="9" style="text-align: center; color: var(--md-text-muted); padding: 2rem;">Keine Vokabeln gefunden.</td></tr>
                        <?php else: ?>
                            <?php foreach ($initialWords as $row):
                                $artClass = '';
                                $artLower = strtolower(trim($row['Artikel'] ?? ''));
                                if ($artLower === 'der') $artClass = 'wort-der';
                                elseif ($artLower === 'die') $artClass = 'wort-die';
                                elseif ($artLower === 'das') $artClass = 'wort-das';
                                else $artClass = 'wort-other';

                                $googleQueryUrl = 'https://www.google.ch/search?q=' . urlencode($row['Wort'] ?? '');
                                $translateUrl = 'https://translate.google.com/?sl=de&tl=fr&text=' . urlencode($row['Wort'] ?? '') . '&op=translate';
                                $isVerb = (isset($row['VerbFlag']) && (int)$row['VerbFlag'] === 1);
                                $wortArg = jsArg($row['Wort'] ?? '');
                            ?>
                                <tr>
                                    <td data-label="Artikel"><strong><?= htmlspecialchars($row['Artikel'] ?? '') ?></strong></td>
                                    <td data-label="Wort" class="wort-cell <?= $artClass ?>"><?= htmlspecialchars($row['Wort'] ?? '') ?></td>
                                    <td data-label="Plural"><?= !$isVerb ? htmlspecialchars($row['Plural'] ?? '') : '' ?></td>
                                    <td data-label="Übersetzung">
                                        <span class="story-word-trans" style="display: none; color: #90caf9;"><?= htmlspecialchars($row['Übersetzung'] ?? '') ?></span>
                                        <button type="button" class="btn btn-secondary" style="padding: 2px 6px; font-size: 0.75rem; margin-top: 4px;" onclick="toggleStoryTransTable(this)">Übersetzung anzeigen</button>
                                    </td>
                                    <td data-label="Werkzeuge">
                                        <div style="display: flex; gap: 4px; flex-wrap: wrap;">
                                            <a href="<?= htmlspecialchars($googleQueryUrl) ?>" target="_blank" rel="noopener noreferrer" class="btn btn-secondary" style="padding: 4px 8px; font-size: 0.78rem;">🔍 Google</a>
                                            <a href="<?= htmlspecialchars($translateUrl) ?>" target="_blank" rel="noopener noreferrer" class="btn btn-info" style="padding: 4px 8px; font-size: 0.78rem;">🌐 Übersetzung</a>
                                            <?php if ($isVerb): ?>
                                                <a href="https://www.verbformen.de/konjugation/<?= urlencode($row['Wort'] ?? '') ?>.htm" target="_blank" rel="noopener noreferrer" class="btn" style="padding: 4px 8px; font-size: 0.78rem; background-color: #6a1b9a;">📖 Konjugation</a>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td data-label="Score"><?= htmlspecialchars($row['Score'] ?? 0) ?></td>
                                    <td class="kenntnisse-cell" data-label="Kenntnisse">
                                        <button onclick="inlineRateWord(<?= $wortArg ?>, 'sehr_gut')" class="btn btn-aktiva" style="padding: 8px 12px; font-size: 0.85rem;" title="sehr gut">sehr gut</button>
                                        <button onclick="inlineRateWord(<?= $wortArg ?>, 'yes')" class="btn btn-success" style="padding: 8px 12px; font-size: 0.85rem;" title="ja">ja</button>
                                        <button onclick="inlineRateWord(<?= $wortArg ?>, 'wiederholen')" class="btn" style="padding: 8px 12px; font-size: 0.85rem; background-color: var(--md-primary);" title="wiederholen">wiederholen</button>
                                        <button onclick="inlineRateWord(<?= $wortArg ?>, 'passiv')" class="btn btn-passiv" style="padding: 8px 12px; font-size: 0.85rem;" title="passiv">passiv</button>
                                        <button onclick="inlineRateWord(<?= $wortArg ?>, 'warteschlange')" class="btn" style="padding: 8px 12px; font-size: 0.85rem; background-color: #424242;" title="warteschlange">warteschlange</button>
                                    </td>
                                    <td data-label="Status" class="status-cell"><?= htmlspecialchars($row['Status'] ?? '') ?></td>
                                    <td class="aktion-cell" data-label="Aktion">
                                        <button onclick="editWord(<?= htmlspecialchars(json_encode($row), ENT_QUOTES, 'UTF-8') ?>)" class="btn" style="padding: 6px 10px; font-size: 0.75rem;" title="Bearbeiten">Bearbeiten</button>
                                        <button onclick="trainSpecificWord(<?= $wortArg ?>)" class="btn btn-success" style="padding: 6px 10px; font-size: 0.75rem;" title="Üben">Üben</button>
                                        <button onclick="deleteWord(<?= $wortArg ?>)" class="btn btn-danger" style="padding: 6px 10px; font-size: 0.75rem;" title="Löschen">Löschen</button>
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
                <h1>🎮 Vokabelspiel</h1>
                <button onclick="switchView('dashboard')" class="btn btn-secondary" style="padding: 8px 14px; font-size: 0.85rem;">🚪 Dashboard</button>
            </header>

            <div id="gameSetupCard" class="card">
                <h2>Spieleinstellungen</h2>
                <form id="gameSetupForm" onsubmit="startGameSession(event)">
                    <label for="gameModeSelect">Spielemodus auswählen:</label>
                    <select id="gameModeSelect" name="game_mode" style="margin-bottom: 1.2rem;" onchange="onGameModeChange()">
                        <option value="standard">Standard Vokabeltrainer</option>
                        <option value="der_die_das">Der-Die-Das Spiel</option>
                        <option value="deutsch_meister">Deutsch Meister</option>
                        <option value="ai_story">KI-Geschichte (lesen)</option>
                        <option value="ai_story_writer">KI-Geschichtenspiel (selbst schreiben)</option>
                        <option value="quiz">Multiple-Choice Übersetzung Quiz</option>
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

                    <!-- Toggle Button for Original or Invertiert -->
                    <div style="margin-bottom: 1.2rem;">
                        <label style="margin-bottom: 6px;">Reihenfolge-Modus:</label>
                        <button type="button" class="toggle-switch-btn" id="orderModeToggleBtn" onclick="toggleOrderMode()">
                            <span>Modus: <strong id="orderModeLabel">Original</strong></span>
                            <span class="toggle-switch-badge" id="orderModeBadge">STANDARD</span>
                        </button>
                    </div>

                    <button type="submit" class="btn" style="width: 100%; margin-top: 1rem; padding: 14px; font-size: 1rem;">Mit dem Üben beginnen 🚀</button>
                </form>
            </div>

            <div id="gamePlayCard" style="display: none;">
                <div id="gameNotice" style="background:#332701; color:#ffecb3; padding:12px; border-radius:8px; margin-bottom:1rem; font-size:0.85rem; border:1px solid #795548; display:none;"></div>

                <div class="card">
                    <div style="display: flex; justify-content: space-between; align-items:center; font-size: 0.85rem; color: var(--md-text-muted); flex-wrap: wrap; gap: 6px;">
                        <span id="gameMetaInfo"></span>
                        <div style="display: flex; gap: 6px;">
                            <button onclick="openEditFromGame()" class="btn btn-secondary" style="padding: 6px 10px; font-size: 0.75rem;">✏️ Wort bearbeiten</button>
                            <button onclick="deleteWordFromGame()" class="btn btn-danger" style="padding: 6px 10px; font-size: 0.75rem;">🗑️ Wort löschen</button>
                        </div>
                    </div>

                    <div style="text-align: center; margin-top: 15px;">
                        <span style="font-size: 0.8rem; text-transform: uppercase; color: var(--md-text-muted); letter-spacing: 1px;">Übersetzen oder erinnern:</span>
                        <div id="gameWordDisplay" class="word-display"></div>
                        <div id="gameConjugationDisplay" style="font-size: 0.85rem; color: #b39ddb; margin-top: 2px; margin-bottom: 2px; display: none;"></div>
                        <div id="gamePraepositionDisplay" style="font-size: 0.85rem; color: #ffb74d; margin-top: 2px; margin-bottom: 2px; display: none;"></div>
                        <div id="gamePluralDisplay" style="font-size: 0.85rem; color: #90caf9; margin-top: 2px; margin-bottom: 15px; display: none;"></div>
                    </div>

                    <div style="margin-bottom: 1.0rem; text-align: center;">
                        <button type="button" class="btn btn-secondary" style="width: 100%;" onclick="toggleGameDetails()" id="revealBtn">Übersetzung anzeigen</button>
                    </div>

                    <div id="detailsBox" class="details-box" style="display: none;">
                        <p><strong>Übersetzung:</strong> <span id="gTrans" style="color: #90caf9; font-size: 1.1rem;"></span></p>

                        <div id="imageDisplayContainer" style="margin: 12px 0; text-align: center;">
                            <img id="generatedImageTag" src="" alt="Wort Bild" onclick="openFullscreenImage(this.src)" style="max-width: 100%; max-height: 300px; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.3); display: none; margin: 0 auto 10px auto;" title="Zum Vergrößern anklicken">
                            <button type="button" class="btn btn-info" onclick="generateAiImageForCurrentWord()" id="generateImageBtn" style="font-size: 0.8rem; padding: 6px 12px;">🤖 Bild generieren</button>
                        </div>

                        <p><strong>Synonym:</strong> <span id="gSyn" style="color: #90caf9; font-size: 1.0rem;"></span></p>
                        <p><strong>Externe Werkzeuge:</strong>
                            <a id="gSearchLink" href="#" target="_blank" rel="noopener noreferrer" class="btn btn-secondary" style="padding: 2px 8px; font-size: 0.8rem; margin-left: 4px;">🔍 Google</a>
                            <a id="gTranslateLink" href="#" target="_blank" rel="noopener noreferrer" class="btn btn-info" style="padding: 2px 8px; font-size: 0.8rem; margin-left: 4px;">🌐 Übersetzung</a>
                            <a id="gVerbLink" href="#" target="_blank" rel="noopener noreferrer" class="btn" style="padding: 2px 8px; font-size: 0.8rem; margin-left: 4px; background-color: #6a1b9a; display:none;">📖 Konjugation</a>
                        </p>
                        <p><strong>Thema:</strong> <span id="gThema"></span></p>
                        <p><strong>Artikel:</strong> <span id="gArt"></span></p>
                        <p><strong>Plural:</strong> <span id="gPlural"></span></p>
                        <p><strong>Grundverb:</strong> <span id="gGrundverb"></span></p>
                        <p><strong>Präfix:</strong> <span id="gPraefix"></span></p>
                        <p><strong>Präpositionalkollokation:</strong> <span id="gPraeposition"></span></p>
                        <p><strong>Wortart:</strong> <span id="gWart"></span></p>
                        <p><strong>Ist Verb?:</strong> <span id="gIsVerb"></span></p>
                        <p><strong>Beispiel:</strong> <em id="gEx"></em></p>
                        <p id="gDates" style="font-size: 0.8rem; color: var(--md-text-muted); margin-top: 10px;"></p>
                    </div>

                    <div style="margin-top: 1rem; border-top: 1px solid var(--md-border); padding-top: 12px; display: flex; flex-direction: column; gap: 12px;">
                        <div>
                            <button type="button" class="btn btn-info" onclick="suggestSentenceWithAI()" id="suggestSentenceBtn" style="width: 100%; margin-bottom: 8px;">💡 Satz vorschlagen</button>
                            <div id="aiSuggestionResult" style="display: none; background: var(--md-surface); border: 1px solid var(--md-border); padding: 12px; border-radius: 8px; font-size: 0.95rem; width: 100%;">
                                <strong>Vorschlag:</strong> <div id="aiSuggestionText" style="color: #90caf9; margin-top: 4px; white-space: pre-wrap;"></div>
                            </div>
                        </div>

                        <div>
                            <button type="button" class="btn btn-secondary" onclick="toggleSentenceWriter()" id="toggleSentenceWriterBtn" style="width: 100%; margin-bottom: 8px;">✍️ Satz schreiben</button>

                            <div id="sentenceWriterContainer" style="display: none; margin-top: 8px;">
                                <label for="userSentenceInput" style="font-size: 0.9rem; font-weight: 500; margin-bottom: 6px;">Schreibe einen Satz mit diesem Wort:</label>
                                <textarea id="userSentenceInput" rows="2" placeholder="z.B. Ich benutze dieses Wort..." style="width: 100%; max-width: 100%; margin-bottom: 8px; resize: vertical;"></textarea>
                                <div style="display: flex; gap: 8px;">
                                    <button type="button" class="btn btn-secondary" onclick="checkSentenceWithAI()" id="checkSentenceBtn" style="flex: 1;">Normal prüfen</button>
                                    <button type="button" class="btn btn-success" onclick="checkStandardSentenceBooster()" style="flex: 1;">Satz prüfen & Booster holen 🚀</button>
                                </div>
                            </div>

                            <div id="aiCorrectionResult" style="display: none; background: var(--md-surface); border: 1px solid var(--md-border); padding: 12px; border-radius: 8px; font-size: 0.95rem; width: 100%; margin-top: 8px;">
                                <strong>Korrektur:</strong> <div id="aiCorrectionText" style="color: #81c784; margin-top: 4px; white-space: pre-wrap;"></div>
                            </div>
                        </div>
                    </div>

                    <div class="action-row">
                        <button type="button" onclick="submitGameAnswer('sehr_gut')" class="btn btn-aktiva">sehr gut ⭐</button>
                        <button type="button" onclick="submitGameAnswer('yes')" class="btn btn-success">ja 👍</button>
                        <button type="button" onclick="submitGameAnswer('wiederholen')" class="btn" style="background-color: var(--md-primary);">wiederholen</button>
                        <button type="button" onclick="submitGameAnswer('passiv')" class="btn btn-passiv">passiv</button>
                        <button type="button" onclick="submitGameAnswer('warteschlange')" class="btn" style="background-color: #424242;">warteschlange</button>
                    </div>

                    <div style="margin-top: 10px;" id="directPromoteContainer">
                        <button type="button" onclick="directPromoteCurrentWord()" class="btn btn-aktiva" style="width: 100%;">🚀 Direkt aktiv (Score 10)</button>
                    </div>

                    <div style="text-align: center; margin-top: 1.5rem; border-top: 1px solid var(--md-border); padding-top: 1rem;">
                        <button type="button" onclick="showGameSetup()" class="btn btn-secondary" style="font-size: 0.85rem; padding: 8px 14px;">⚙️ Spieleinstellungen ändern</button>
                    </div>
                </div>
            </div>

            <!-- Deutsch Meister Game Card -->
            <div id="deutschMeisterPlayCard" style="display: none;">
                <div class="card">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem; flex-wrap: wrap; gap: 8px;">
                        <h2 style="margin: 0;">👑 Deutsch Meister</h2>
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
                            <h3 style="color: #90caf9; margin-top: 0; font-size: 1rem;">📚 Auflösung & Übersetzung</h3>
                            <p><strong>Wort:</strong> <span id="dmResWord"></span></p>
                            <p><strong>Artikel:</strong> <span id="dmResArt"></span></p>
                            <p><strong>Übersetzung:</strong> <span id="dmResTrans" style="color: #90caf9; font-weight: 600;"></span></p>
                            <p><strong>Beispiel:</strong> <em id="dmResEx"></em></p>
                            <button type="button" class="btn" onclick="fetchNextDeutschMeisterWord()" style="width: 100%; margin-top: 10px;">Nächste Aufgabe ➡️</button>
                        </div>
                    </div>

                    <div style="text-align: center; border-top: 1px solid var(--md-border); padding-top: 1rem; margin-top: 1.5rem;">
                        <button type="button" onclick="showGameSetup()" class="btn btn-secondary" style="font-size: 0.85rem; padding: 8px 14px;">⚙️ Spieleinstellungen ändern</button>
                    </div>
                </div>
            </div>

            <div id="derDieDasPlayCard" style="display: none;">
                <div class="card">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem; flex-wrap: wrap; gap: 8px;">
                        <h2 style="margin: 0;" id="dddGameTitle">🎯 Der-Die-Das Spiel</h2>
                        <div style="display: flex; gap: 6px; align-items: center;">
                            <button onclick="openEditFromDdd()" class="btn btn-secondary" style="padding: 6px 10px; font-size: 0.75rem;">✏️ Wort bearbeiten</button>
                            <button onclick="deleteWordFromDdd()" class="btn btn-danger" style="padding: 6px 10px; font-size: 0.75rem;">🗑️ Wort löschen</button>
                            <div id="dddStreakCounter" style="background: var(--md-surface-card); border: 1px solid var(--md-border); padding: 6px 14px; border-radius: 8px; font-weight: 600; color: #90caf9; font-size: 0.95rem;">
                                🌱 Streak: <span id="dddStreakValue">0</span> / 10
                            </div>
                        </div>
                    </div>
                    <p style="text-align: center; color: var(--md-text-muted); font-size: 0.9rem; margin-bottom: 1.5rem;" id="dddSubtitle">Rate den richtigen Artikel für dieses Aktiva-Wort! (10 in Folge = Super Booster 🚀)</p>

                    <div style="text-align: center; margin-top: 15px;">
                        <div id="dddWordDisplay" class="word-display" style="color: grey;">-</div>
                        <div id="dddTranslationDisplay" style="font-size: 1.05rem; color: #90caf9; margin-top: 6px; margin-bottom: 15px; font-weight: 500; display: none;"></div>
                    </div>

                    <div style="display: flex; gap: 12px; justify-content: center; margin: 2rem 0;">
                        <button type="button" class="btn" onclick="submitDerDieDasAnswer('der')" style="background-color: #64b5f6; flex: 1; padding: 16px; font-size: 1.1rem;">der</button>
                        <button type="button" class="btn" onclick="submitDerDieDasAnswer('die')" style="background-color: #f06292; flex: 1; padding: 16px; font-size: 1.1rem;">die</button>
                        <button type="button" class="btn" onclick="submitDerDieDasAnswer('das')" style="background-color: #ffb74d; flex: 1; padding: 16px; font-size: 1.1rem;">das</button>
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
                        <button type="button" onclick="showGameSetup()" class="btn btn-secondary" style="font-size: 0.85rem; padding: 8px 14px;">⚙️ Spieleinstellungen ändern</button>
                    </div>
                </div>
            </div>

            <div id="aiStoryPlayCard" style="display: none;">
                <div class="card">
                    <h2 style="text-align: center; margin-bottom: 1rem;">📖 KI-Geschichte (Deepseek)</h2>
                    <div id="aiStoryDisplay" class="story-box"></div>

                    <h3 style="margin-top: 1.5rem; margin-bottom: 1.0rem;" id="ratingHeaderTitle">Wörter bewerten:</h3>
                    <div id="wordsRatingContainer"></div>

                    <div style="text-align: center; margin-top: 1.5rem; border-top: 1px solid var(--md-border); padding-top: 1rem; display: flex; gap: 10px; flex-wrap: wrap;">
                        <button type="button" onclick="loadNewStory()" class="btn btn-success" style="flex: 1; min-width: 200px;">Nächste Geschichte generieren 🔄</button>
                        <button type="button" onclick="showGameSetup()" class="btn btn-secondary" style="font-size: 0.85rem; padding: 8px 14px;">⚙️ Spieleinstellungen ändern</button>
                    </div>
                </div>
            </div>

            <div id="aiStoryWriterPlayCard" style="display: none;">
                <div class="card">
                    <h2 style="text-align: center; margin-bottom: 0.5rem;">✍️ KI-Geschichtenspiel (Selbst schreiben)</h2>
                    <p style="text-align: center; color: var(--md-text-muted); font-size: 0.9rem; margin-bottom: 1.5rem;">Schreibe eine Geschichte, die alle 5 vorgegebenen Wörter enthält, und lasse sie von der KI korrigieren!</p>

                    <div style="margin-bottom: 1rem;">
                        <label style="font-weight: 600; margin-bottom: 8px; display: block;">Verwende diese 5 Wörter in deiner Geschichte:</label>
                        <div id="writerWordsList" style="display: flex; flex-direction: column; gap: 12px; margin-bottom: 1rem;"></div>
                    </div>

                    <div style="margin-bottom: 1rem;">
                        <label for="userStoryInput" style="font-weight: 600; margin-bottom: 6px; display: block;">Deine Geschichte:</label>
                        <textarea id="userStoryInput" rows="6" placeholder="Schreibe deine Geschichte hier..." style="width: 100%; max-width: 100%; resize: vertical; padding: 12px; font-size: 1rem;"></textarea>
                    </div>

                    <div style="display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 1.5rem;">
                        <button type="button" onclick="checkUserStoryWithAI()" class="btn btn-success" id="checkStoryBtn" style="flex: 1; min-width: 150px;">🤖 Geschichte korrigieren</button>
                        <button type="button" onclick="loadNewStoryWriter()" class="btn btn-secondary" style="flex: 1; min-width: 150px;">🔄 Neue Wörter laden</button>
                    </div>

                    <div id="storyCorrectionResult" style="display: none; background: var(--md-surface); border: 1px solid var(--md-border); padding: 16px; border-radius: 8px; font-size: 0.95rem; margin-bottom: 1.5rem;">
                        <strong style="color: #90caf9; display: block; margin-bottom: 8px;">KI-Korrektur & Feedback:</strong>
                        <div id="storyCorrectionText" style="color: #eceff1; white-space: pre-wrap; line-height: 1.5;"></div>
                    </div>

                    <div style="text-align: center; border-top: 1px solid var(--md-border); padding-top: 1rem; margin-top: 1.5rem;">
                        <button type="button" onclick="showGameSetup()" class="btn btn-secondary" style="font-size: 0.85rem; padding: 8px 14px;">⚙️ Spieleinstellungen ändern</button>
                    </div>
                </div>
            </div>

            <div id="quizPlayCard" style="display: none;">
                <div class="card">
                    <h2 style="text-align: center;">🎯 Blitz-Übersetzungs-Quiz</h2>
                    <p style="text-align: center; color: var(--md-text-muted); font-size: 0.9rem; margin-bottom: 0.5rem;">Schnell! Wähle die korrekte Übersetzung aus!</p>

                    <div class="timer-bar-container">
                        <div class="timer-bar-fill" id="quizTimerFill"></div>
                    </div>

                    <div style="text-align: center; margin-top: 15px;">
                        <span style="font-size: 0.8rem; text-transform: uppercase; color: var(--md-text-muted); letter-spacing: 1px;">Gesuchtes Wort:</span>
                        <div id="quizWordDisplay" class="word-display"></div>
                    </div>

                    <div class="quiz-grid" id="quizGrid"></div>

                    <div style="text-align: center; margin-top: 1.5rem; border-top: 1px solid var(--md-border); padding-top: 1rem;">
                        <button type="button" onclick="showGameSetup()" class="btn btn-secondary" style="font-size: 0.85rem; padding: 8px 14px;">⚙️ Spieleinstellungen ändern</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div id="statistics-view" class="view">
        <div class="stats-container">
            <header>
                <h1>📊 Wortschatz-Statistiken</h1>
                <button onclick="switchView('dashboard')" class="btn btn-secondary" style="padding: 8px 14px; font-size: 0.85rem;">🚪 Dashboard</button>
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
let currentQuizData = null;
let currentStoryData = null;
let currentWriterData = null;
let currentDerDieDasWord = null;
let currentDeutschMeisterWord = null;
let lastDddWasRight = false;
let dddStreakCount = 0;
let statusPieChartInstance = null;

let quizTimerInterval = null;
let quizTimeLeft = 45;
let quizStartTime = 0;
const quizTotalTime = 45;

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
    btn.textContent = '🤖 Generiere KI-Szene...';

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
        if (btn.textContent === '🤖 Generiere KI-Szene...') {
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
    btn.textContent = '🤖 Bild generieren';

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
    btn.textContent = '🤖 Analysiere...';

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

async function refreshMetadata() {
    try {
        const res = await fetch('index.php?api=get_metadata&_ts=' + Date.now(), { cache: 'no-store' });
        const data = await res.json();
        if (data.error === 'Unauthorized') {
            window.location.href = 'login.php';
            return;
        }
        if (!data.success) return;

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
        updateDropdown('filterScore', data.scores, 'Alle Scores', 'Score: ');
        updateDropdown('filterStatus', data.statuses, 'Alle Status');
        updateDropdown('filterGrundverb', data.grundverben, 'Alle Grundverben');
        updateDropdown('filterPraefix', data.praefixe, 'Alle Präfixe');

        updateDashboardWortartenDropdown();

        updateDropdown('statsFilterList', data.lists, 'Alle Listen');
        updateDropdown('statsFilterThema', data.themen, 'Alle Themen');
        updateDropdown('statsFilterScore', data.scores, 'Alle Scores', 'Score: ');

        updateStatsWortartenDropdown();

        if (typeof initGameInstantly === 'function') {
            initGameInstantly();
        }
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

function switchView(viewName) {
    if (quizTimerInterval) clearInterval(quizTimerInterval);
    document.querySelectorAll('.view').forEach(v => v.classList.remove('active'));
    if (viewName === 'dashboard') {
        document.getElementById('dashboard-view').classList.add('active');
        loadDashboardData(true);
        refreshMetadata();
    } else if (viewName === 'game') {
        document.getElementById('game-view').classList.add('active');
        refreshMetadata().then(() => initGameInstantly());
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
        if (key === 'aktiva') return '#43a047';
        if (key === 'wiederholen') return '#1e88e5';
        if (key === 'neu') return '#4fc3f7';
        if (key === 'passiv') return '#9e9e9e';
        if (key === 'warteschlange') return '#424242';
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
                borderColor: '#1e1e1e'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: { color: '#e0e0e0', font: { family: 'system-ui' } }
                }
            }
        }
    });
}

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

function toggleOrderMode() {
    const input = document.getElementById('sortOrderInput');
    const label = document.getElementById('orderModeLabel');
    const badge = document.getElementById('orderModeBadge');

    if (input.value === 'ASC') {
        input.value = 'DESC';
        label.textContent = 'Invertiert';
        badge.textContent = 'INVERTIERT';
        badge.style.backgroundColor = '#e53935';
    } else {
        input.value = 'ASC';
        label.textContent = 'Original';
        badge.textContent = 'STANDARD';
        badge.style.backgroundColor = 'var(--md-primary)';
    }
}

function initGameInstantly() {
    const pillContainer = document.getElementById('statusPillContainer');
    if (pillContainer) {
        pillContainer.innerHTML = '';
        cachedStatuses.forEach(st => {
            const stLower = (st || '').toLowerCase().trim();
            // Make "wiederholen" the ONLY preselected option by default
            const isPreselected = (stLower === 'wiederholen');
            const activeClass = isPreselected ? 'active' : '';
            pillContainer.innerHTML += `<div class="status-pill ${activeClass}" data-value="${escapeAttr(st)}" onclick="toggleStatusPill(this)">${escapeHtml(st)}</div>`;
        });
        updateHiddenStatusInputs();
    }

    const listContainer = document.getElementById('gameListCheckboxes');
    if (listContainer) {
        listContainer.innerHTML = '';
        cachedLists.forEach(l => {
            listContainer.innerHTML += `<label class="checkbox-label"><input type="checkbox" name="sharepoint_lists[]" value="${escapeAttr(l)}"> ${escapeHtml(l)}</label>`;
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

    if (!activeGameSettings) {
        showGameSetup();
    } else {
        document.getElementById('gameSetupCard').style.display = 'none';
        if (activeGameSettings.mode === 'ai_story') {
            document.getElementById('gamePlayCard').style.display = 'none';
            document.getElementById('derDieDasPlayCard').style.display = 'none';
            document.getElementById('deutschMeisterPlayCard').style.display = 'none';
            document.getElementById('quizPlayCard').style.display = 'none';
            document.getElementById('aiStoryWriterPlayCard').style.display = 'none';
            document.getElementById('aiStoryPlayCard').style.display = 'block';
        } else if (activeGameSettings.mode === 'ai_story_writer') {
            document.getElementById('gamePlayCard').style.display = 'none';
            document.getElementById('derDieDasPlayCard').style.display = 'none';
            document.getElementById('deutschMeisterPlayCard').style.display = 'none';
            document.getElementById('quizPlayCard').style.display = 'none';
            document.getElementById('aiStoryPlayCard').style.display = 'none';
            document.getElementById('aiStoryWriterPlayCard').style.display = 'block';
        } else if (activeGameSettings.mode === 'quiz') {
            document.getElementById('gamePlayCard').style.display = 'none';
            document.getElementById('derDieDasPlayCard').style.display = 'none';
            document.getElementById('deutschMeisterPlayCard').style.display = 'none';
            document.getElementById('aiStoryPlayCard').style.display = 'none';
            document.getElementById('aiStoryWriterPlayCard').style.display = 'none';
            document.getElementById('quizPlayCard').style.display = 'block';
        } else if (activeGameSettings.mode === 'der_die_das') {
            document.getElementById('gamePlayCard').style.display = 'none';
            document.getElementById('aiStoryPlayCard').style.display = 'none';
            document.getElementById('aiStoryWriterPlayCard').style.display = 'none';
            document.getElementById('quizPlayCard').style.display = 'none';
            document.getElementById('deutschMeisterPlayCard').style.display = 'none';
            document.getElementById('derDieDasPlayCard').style.display = 'block';

            dddStreakCount = 0;
            updateDddStreakUI();
        } else if (activeGameSettings.mode === 'deutsch_meister') {
            document.getElementById('gamePlayCard').style.display = 'none';
            document.getElementById('derDieDasPlayCard').style.display = 'none';
            document.getElementById('aiStoryPlayCard').style.display = 'none';
            document.getElementById('aiStoryWriterPlayCard').style.display = 'none';
            document.getElementById('quizPlayCard').style.display = 'none';
            document.getElementById('deutschMeisterPlayCard').style.display = 'block';
            fetchNextDeutschMeisterWord();
        } else {
            document.getElementById('aiStoryPlayCard').style.display = 'none';
            document.getElementById('aiStoryWriterPlayCard').style.display = 'none';
            document.getElementById('quizPlayCard').style.display = 'none';
            document.getElementById('derDieDasPlayCard').style.display = 'none';
            document.getElementById('deutschMeisterPlayCard').style.display = 'none';
            document.getElementById('gamePlayCard').style.display = 'block';
        }
    }
}

function onGameModeChange() {
    const mode = document.getElementById('gameModeSelect').value;
    const stdOptions = document.getElementById('standardGameOptions');
    const statusGroup = document.getElementById('statusSelectionGroup');
    const statusLabel = document.getElementById('statusLabelText');

    if (mode === 'ai_story' || mode === 'ai_story_writer') {
        stdOptions.style.display = 'block';
        statusGroup.style.display = 'block';
        statusLabel.textContent = 'Status für KI-Spiel auswählen';
    } else if (mode === 'quiz' || mode === 'standard') {
        stdOptions.style.display = (mode === 'standard') ? 'block' : 'none';
        statusGroup.style.display = 'block';
        statusLabel.textContent = 'Status zum Wiederholen auswählen';
    } else if (mode === 'der_die_das' || mode === 'deutsch_meister') {
        stdOptions.style.display = 'none';
        statusGroup.style.display = 'none';
    }
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

    catContainer.innerHTML = '';
    availableWortarten.forEach(wa => {
        catContainer.innerHTML += `<label class="checkbox-label"><input type="checkbox" name="categories[]" value="${escapeAttr(wa)}"> ${escapeHtml(wa)}</label>`;
    });
}

function onGameThemaChange() {
    updateGameCategoriesCheckboxes();
}

function showGameSetup() {
    if (quizTimerInterval) clearInterval(quizTimerInterval);
    activeGameSettings = null;
    document.getElementById('gameSetupCard').style.display = 'block';
    document.getElementById('gamePlayCard').style.display = 'none';
    document.getElementById('derDieDasPlayCard').style.display = 'none';
    document.getElementById('deutschMeisterPlayCard').style.display = 'none';
    document.getElementById('aiStoryPlayCard').style.display = 'none';
    document.getElementById('aiStoryWriterPlayCard').style.display = 'none';
    document.getElementById('quizPlayCard').style.display = 'none';
}

function startGameSession(e) {
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

    if (mode === 'ai_story') {
        activeGameSettings = {
            mode: 'ai_story',
            statuses: formData.getAll('game_statuses[]'),
            sharepoint_lists: formData.getAll('sharepoint_lists[]'),
            themen: themenList,
            categories: formData.getAll('categories[]')
        };
        document.getElementById('gameSetupCard').style.display = 'none';
        document.getElementById('gamePlayCard').style.display = 'none';
        document.getElementById('derDieDasPlayCard').style.display = 'none';
        document.getElementById('deutschMeisterPlayCard').style.display = 'none';
        document.getElementById('quizPlayCard').style.display = 'none';
        document.getElementById('aiStoryWriterPlayCard').style.display = 'none';
        document.getElementById('aiStoryPlayCard').style.display = 'block';
        fetchNewStory();
    } else if (mode === 'ai_story_writer') {
        activeGameSettings = {
            mode: 'ai_story_writer',
            statuses: formData.getAll('game_statuses[]'),
            sharepoint_lists: formData.getAll('sharepoint_lists[]'),
            themen: themenList,
            categories: formData.getAll('categories[]')
        };
        document.getElementById('gameSetupCard').style.display = 'none';
        document.getElementById('gamePlayCard').style.display = 'none';
        document.getElementById('derDieDasPlayCard').style.display = 'none';
        document.getElementById('deutschMeisterPlayCard').style.display = 'none';
        document.getElementById('quizPlayCard').style.display = 'none';
        document.getElementById('aiStoryPlayCard').style.display = 'none';
        document.getElementById('aiStoryWriterPlayCard').style.display = 'block';
        fetchNewStoryWriter();
    } else if (mode === 'quiz') {
        activeGameSettings = {
            mode: 'quiz',
            statuses: formData.getAll('game_statuses[]'),
            sort_order: sortOrder
        };
        document.getElementById('gameSetupCard').style.display = 'none';
        document.getElementById('gamePlayCard').style.display = 'none';
        document.getElementById('derDieDasPlayCard').style.display = 'none';
        document.getElementById('deutschMeisterPlayCard').style.display = 'none';
        document.getElementById('aiStoryPlayCard').style.display = 'none';
        document.getElementById('aiStoryWriterPlayCard').style.display = 'none';
        document.getElementById('quizPlayCard').style.display = 'block';
        fetchNextGameWord();
    } else if (mode === 'der_die_das') {
        activeGameSettings = { mode: 'der_die_das' };
        dddStreakCount = 0;
        updateDddStreakUI();
        document.getElementById('gameSetupCard').style.display = 'none';
        document.getElementById('gamePlayCard').style.display = 'none';
        document.getElementById('aiStoryPlayCard').style.display = 'none';
        document.getElementById('aiStoryWriterPlayCard').style.display = 'none';
        document.getElementById('quizPlayCard').style.display = 'none';
        document.getElementById('deutschMeisterPlayCard').style.display = 'none';
        document.getElementById('derDieDasPlayCard').style.display = 'block';
        fetchNextDerDieDasWord();
    } else if (mode === 'deutsch_meister') {
        activeGameSettings = { mode: 'deutsch_meister' };
        document.getElementById('gameSetupCard').style.display = 'none';
        document.getElementById('gamePlayCard').style.display = 'none';
        document.getElementById('derDieDasPlayCard').style.display = 'none';
        document.getElementById('aiStoryPlayCard').style.display = 'none';
        document.getElementById('aiStoryWriterPlayCard').style.display = 'none';
        document.getElementById('quizPlayCard').style.display = 'none';
        document.getElementById('deutschMeisterPlayCard').style.display = 'block';
        fetchNextDeutschMeisterWord();
    } else {
        activeGameSettings = {
            mode: 'standard',
            statuses: formData.getAll('game_statuses[]'),
            sharepoint_lists: formData.getAll('sharepoint_lists[]'),
            themen: themenList,
            categories: formData.getAll('categories[]'),
            sort_order: sortOrder
        };

        document.getElementById('gameSetupCard').style.display = 'none';
        document.getElementById('derDieDasPlayCard').style.display = 'none';
        document.getElementById('deutschMeisterPlayCard').style.display = 'none';
        document.getElementById('aiStoryPlayCard').style.display = 'none';
        document.getElementById('aiStoryWriterPlayCard').style.display = 'none';
        document.getElementById('quizPlayCard').style.display = 'none';
        document.getElementById('gamePlayCard').style.display = 'block';
        fetchNextGameWord();
    }
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

        const res = await fetch('index.php?api=deutsch_meister_next&_ts=' + Date.now(), { cache: 'no-store' });
        const data = await res.json();

        if (data.error === 'Unauthorized') {
            window.location.href = 'login.php';
            return;
        }

        if (!data.success || !data.word) {
            wordDisplay.textContent = 'Keine aktiven Wörter gefunden!';
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
                badge.innerHTML = `<span style="color: var(--md-aktiva-green);">🎉 Perfekt! +${data.points} Punkte</span>`;
            } else if (data.evaluation_result === 'wrong_context') {
                badge.innerHTML = `<span style="color: var(--md-danger);">❌ Falscher Kontext! -1 Punkt</span>`;
            } else {
                badge.innerHTML = `<span style="color: var(--md-warning-yellow);">⚠️ Grammatikfehler! +${data.points} Punkt</span>`;
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
            titleEl.innerHTML = '👑 DER-DIE-DAS-GODMODE 👑';
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
            titleEl.innerHTML = '🎯 Artikel-Detektiv im Einsatz';
            subtitleEl.innerHTML = '💡 Guter Start! Die Serie baut sich auf.';
        } else {
            counterEl.style.background = 'var(--md-surface-card)';
            counterEl.style.color = '#90caf9';
            titleEl.innerHTML = '🎯 Der-Die-Das Spiel';
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

        const res = await fetch('index.php?api=der_die_das_next&_ts=' + Date.now(), { cache: 'no-store' });
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
    switchView('dashboard');
    editWord(currentDerDieDasWord);
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

            if (correctArt === 'der') wordDisplay.style.color = '#64b5f6';
            else if (correctArt === 'die') wordDisplay.style.color = '#f06292';
            else if (correctArt === 'das') wordDisplay.style.color = '#ffb74d';
            else wordDisplay.style.color = '#b0b0b0';

            if (currentDerDieDasWord.Übersetzung) {
                transDisplay.textContent = `Übersetzung: ${currentDerDieDasWord.Übersetzung}`;
                transDisplay.style.display = 'block';
            }

            const feedbackText = document.getElementById('dddFeedbackText');
            if (data.is_correct) {
                if (data.super_booster) {
                    feedbackText.innerHTML = `<span style="color: #ab47bc; font-size: 1.2rem;">🚀 GODMODE SUPER BOOSTER! 10er Streak geknackt! (+9 Score & Revisionsdatum aktualisiert)</span>`;
                } else {
                    feedbackText.innerHTML = `<span style="color: var(--md-aktiva-green);">Sehr gut! (+3 Score, Status: ${escapeHtml(data.new_status)})</span>`;
                }
            } else {
                feedbackText.innerHTML = `<span style="color: var(--md-danger);">Schade! Streak auf 0 zurückgesetzt. Richtiger Artikel: <strong>${escapeHtml(correctArt)}</strong> (-1 Punkt)</span>`;
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
                } else {
                    msg = `<br><br>✅ Grammatikalisch korrekt! (Zwar nicht perfekt idiomatisch, daher +${data.points_added} Punkt vergeben).`;
                }
                correctionEl.innerHTML = `<span style="color: #81c784;">${parseMarkdown(data.correction)}</span>` + `<div style="color: #90caf9; font-weight: bold;">${msg}</div>`;
                todayReviewedCount++;
                updateDailyTrackerUI();
            } else {
                correctionEl.innerHTML = `<span style="color: #e53935; font-weight: bold;">⚠️ Der Satz enthält Grammatikfehler (+1 Punkt gutgeschrieben).</span><br><br><span style="color: #e0e0e0;">${parseMarkdown(data.correction)}</span>`;
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

async function fetchNewStory() {
    try {
        document.getElementById('aiStoryDisplay').innerHTML = 'Lade Geschichte von Deepseek...';
        document.getElementById('wordsRatingContainer').innerHTML = '';
        document.getElementById('ratingHeaderTitle').style.display = 'block';

        const res = await fetch('index.php?api=ai_story_game_next&_ts=' + Date.now(), {
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

        if (!data.success) {
            document.getElementById('aiStoryDisplay').innerHTML = `<span style="color: var(--md-danger);">${escapeHtml(data.error || 'Fehler beim Laden der Geschichte.')}</span>`;
            return;
        }

        currentStoryData = data;
        renderStoryGame(data);
    } catch (err) {
        console.error('Fehler beim Laden der KI-Geschichte', err);
        document.getElementById('aiStoryDisplay').innerHTML = '<span style="color: var(--md-danger);">Netzwerkfehler beim Laden der Geschichte.</span>';
    }
}

function loadNewStory() {
    fetchNewStory();
}

async function fetchNewStoryWriter() {
    try {
        document.getElementById('writerWordsList').innerHTML = 'Lade Wörter...';
        document.getElementById('userStoryInput').value = '';
        document.getElementById('storyCorrectionResult').style.display = 'none';

        const res = await fetch('index.php?api=ai_story_writer_next&_ts=' + Date.now(), {
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

        if (!data.success) {
            document.getElementById('writerWordsList').innerHTML = `<span style="color: var(--md-danger);">${escapeHtml(data.error || 'Fehler beim Laden der Wörter.')}</span>`;
            return;
        }

        currentWriterData = data;
        renderStoryWriter(data);
    } catch (err) {
        console.error('Fehler beim Laden der Wörter für das Schreibspiel', err);
        document.getElementById('writerWordsList').innerHTML = '<span style="color: var(--md-danger);">Netzwerkfehler beim Laden der Wörter.</span>';
    }
}

function loadNewStoryWriter() {
    fetchNewStoryWriter();
}

function renderStoryWriter(data) {
    const container = document.getElementById('writerWordsList');
    container.innerHTML = '';

    if (!data.words || data.words.length === 0) {
        container.innerHTML = '<div style="color: var(--md-text-muted);">Keine Wörter gefunden.</div>';
        return;
    }

    data.words.forEach(wordObj => {
        const artClass = getArticleColorClass(wordObj.Artikel);
        const itemDiv = document.createElement('div');
        itemDiv.className = 'word-rating-item';
        itemDiv.setAttribute('data-word', wordObj.Wort);
        itemDiv.innerHTML = `
            <div class="word-rating-header">
                <div>
                    <span class="${artClass}" style="font-size: 1.1rem; font-weight: 600;">${escapeHtml(wordObj.Wort)}</span>
                    <div style="margin-top: 6px;">
                        <span class="story-word-trans" style="font-size: 0.95rem; color: #90caf9; display: none;">${escapeHtml(wordObj.Übersetzung || 'Keine Übersetzung')}</span>
                        <button type="button" class="btn btn-secondary" style="padding: 2px 6px; font-size: 0.75rem;" onclick="toggleStoryTrans(this)">Übersetzung anzeigen</button>
                    </div>
                </div>
                <div class="word-rating-buttons">
                    <button type="button" class="btn btn-aktiva" style="padding: 6px 6px; font-size: 0.7rem;" onclick="rateWriterWord('${escapeJs(wordObj.Wort)}', 'sehr_gut', this)">sehr gut ⭐</button>
                    <button type="button" class="btn btn-success" style="padding: 6px 6px; font-size: 0.7rem;" onclick="rateWriterWord('${escapeJs(wordObj.Wort)}', 'yes', this)">ja 👍</button>
                    <button type="button" class="btn" style="padding: 6px 6px; font-size: 0.7rem; background-color: var(--md-primary);" onclick="rateWriterWord('${escapeJs(wordObj.Wort)}', 'wiederholen', this)">wiederholen</button>
                    <button type="button" class="btn btn-passiv" style="padding: 6px 6px; font-size: 0.7rem;" onclick="rateWriterWord('${escapeJs(wordObj.Wort)}', 'passiv', this)">passiv</button>
                    <button type="button" class="btn" style="padding: 6px 6px; font-size: 0.7rem; background-color: #424242;" onclick="rateWriterWord('${escapeJs(wordObj.Wort)}', 'warteschlange', this)">warteschlange</button>
                </div>
            </div>
        `;
        container.appendChild(itemDiv);
    });
}

async function checkUserStoryWithAI() {
    const storyText = document.getElementById('userStoryInput').value.trim();
    if (!storyText) {
        alert('Bitte schreibe zuerst eine Geschichte!');
        return;
    }

    if (!currentWriterData || !currentWriterData.words) {
        alert('Keine Wörter geladen.');
        return;
    }

    const btn = document.getElementById('checkStoryBtn');
    const resultBox = document.getElementById('storyCorrectionResult');
    const correctionText = document.getElementById('storyCorrectionText');

    btn.disabled = true;
    btn.textContent = 'Korrigiere...';
    resultBox.style.display = 'none';

    try {
        const res = await fetch('index.php?api=ai_story_writer_check&_ts=' + Date.now(), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            cache: 'no-store',
            body: JSON.stringify({
                story: storyText,
                words: currentWriterData.words
            })
        });
        const data = await res.json();

        if (data.error === 'Unauthorized') {
            window.location.href = 'login.php';
            return;
        }

        if (data.success) {
            correctionText.innerHTML = parseMarkdown(data.correction);
            resultBox.style.display = 'block';
            resultBox.scrollIntoView({ behavior: 'smooth' });
        } else {
            correctionText.textContent = data.error || 'Fehler bei der Korrektur.';
            resultBox.style.display = 'block';
        }
    } catch (err) {
        console.error('KI-Korrektur fehlgeschlagen', err);
        correctionText.textContent = 'Netzwerkfehler.';
        resultBox.style.display = 'block';
    } finally {
        btn.disabled = false;
        btn.textContent = '🤖 Geschichte korrigieren';
    }
}

async function rateWriterWord(wort, result, btnElement) {
    const parentContainer = btnElement.closest('.word-rating-item');

    parentContainer.style.opacity = '0';
    parentContainer.style.transform = 'translateY(-10px)';
    setTimeout(() => {
        parentContainer.remove();
    }, 300);

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
    } catch (err) {
        console.error('Fehler beim Speichern der Bewertung', err);
    }
}

function renderStoryGame(data) {
    document.getElementById('aiStoryDisplay').innerHTML = parseMarkdown(data.story);

    const container = document.getElementById('wordsRatingContainer');
    container.innerHTML = '';

    if (!data.words || data.words.length === 0) {
        container.innerHTML = '<div style="text-align: center; color: var(--md-text-muted);">Keine Wörter gefunden.</div>';
        return;
    }

    data.words.forEach(wordObj => {
        const artClass = getArticleColorClass(wordObj.Artikel);
        const itemDiv = document.createElement('div');
        itemDiv.className = 'word-rating-item';
        itemDiv.setAttribute('data-word', wordObj.Wort);
        itemDiv.innerHTML = `
            <div class="word-rating-header">
                <div>
                    <span class="${artClass}" style="font-size: 1.1rem; font-weight: 600;">${escapeHtml(wordObj.Wort)}</span>
                    <div style="margin-top: 6px;">
                        <span class="story-word-trans" style="font-size: 0.95rem; color: #90caf9; display: none;">${escapeHtml(wordObj.Übersetzung || 'Keine Übersetzung')}</span>
                        <button type="button" class="btn btn-secondary" style="padding: 2px 6px; font-size: 0.75rem;" onclick="toggleStoryTrans(this)">Übersetzung anzeigen</button>
                    </div>
                </div>
                <div class="word-rating-buttons">
                    <button type="button" class="btn btn-aktiva" style="padding: 6px 6px; font-size: 0.7rem;" onclick="rateWord('${escapeJs(wordObj.Wort)}', 'sehr_gut', this)">sehr gut ⭐</button>
                    <button type="button" class="btn btn-success" style="padding: 6px 6px; font-size: 0.7rem;" onclick="rateWord('${escapeJs(wordObj.Wort)}', 'yes', this)">ja 👍</button>
                    <button type="button" class="btn" style="padding: 6px 6px; font-size: 0.7rem; background-color: var(--md-primary);" onclick="rateWord('${escapeJs(wordObj.Wort)}', 'wiederholen', this)">wiederholen</button>
                    <button type="button" class="btn btn-passiv" style="padding: 6px 6px; font-size: 0.7rem;" onclick="rateWord('${escapeJs(wordObj.Wort)}', 'passiv', this)">passiv</button>
                    <button type="button" class="btn" style="padding: 6px 6px; font-size: 0.7rem; background-color: #424242;" onclick="rateWord('${escapeJs(wordObj.Wort)}', 'warteschlange', this)">warteschlange</button>
                </div>
            </div>
        `;
        container.appendChild(itemDiv);
    });
}

function toggleStoryTrans(btn) {
    const parentContainer = btn.closest('div');
    const transSpan = parentContainer.querySelector('.story-word-trans');
    if (transSpan.style.display === 'none') {
        transSpan.style.display = 'inline';
        btn.textContent = 'Übersetzung ausblenden';
    } else {
        transSpan.style.display = 'none';
        btn.textContent = 'Übersetzung anzeigen';
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

async function rateWord(wort, result, btnElement) {
    const parentContainer = btnElement.closest('.word-rating-item');

    parentContainer.style.opacity = '0';
    parentContainer.style.transform = 'translateY(-10px)';
    setTimeout(() => {
        parentContainer.remove();

        const container = document.getElementById('wordsRatingContainer');
        if (container && container.children.length === 0) {
            document.getElementById('ratingHeaderTitle').style.display = 'none';
        }
    }, 300);

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
    } catch (err) {
        console.error('Fehler beim Speichern der Bewertung', err);
    }
}

async function trainSpecificWord(wort) {
    document.getElementById('themaModalOverlay').style.display = 'none';
    switchView('game');
    activeGameSettings = { mode: 'standard', specific_word: wort };
    document.getElementById('gameSetupCard').style.display = 'none';
    document.getElementById('derDieDasPlayCard').style.display = 'none';
    document.getElementById('deutschMeisterPlayCard').style.display = 'none';
    document.getElementById('aiStoryPlayCard').style.display = 'none';
    document.getElementById('aiStoryWriterPlayCard').style.display = 'none';
    document.getElementById('quizPlayCard').style.display = 'none';
    document.getElementById('gamePlayCard').style.display = 'block';
    await fetchNextGameWord();
}

async function fetchNextGameWord() {
    if (quizTimerInterval) clearInterval(quizTimerInterval);

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

        if (data.mode === 'quiz') {
            currentQuizData = data;
            lastPopupWordObject = data.word;
            renderQuizGame(data);
            startQuizTimer();
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

        document.getElementById('gameMetaInfo').innerHTML = `Liste: <strong>${escapeHtml(currentGameWord.sharepoint_list || 'N/A')}</strong> | Thema: <strong>${escapeHtml(currentGameWord.Thema || 'N/A')}</strong> | Status: <strong>${escapeHtml(currentGameWord.Status)}</strong> | Score: <strong>${escapeHtml(currentGameWord.Score)}</strong>`;

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

        const praepDisplay = document.getElementById('gamePraepositionDisplay');
        if (currentGameWord.VerbFlag == 1 && currentGameWord.praeposition_kollokation) {
            // escaped first, then **bold** is converted safely
            praepDisplay.innerHTML = `Kollokation: ${parseMarkdown(currentGameWord.praeposition_kollokation)}`;
            praepDisplay.style.display = 'block';
        } else {
            praepDisplay.style.display = 'none';
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
            promoteContainer.style.display = 'block';
        }

        document.getElementById('gTrans').textContent = currentGameWord.Übersetzung;
        document.getElementById('gSyn').textContent = currentGameWord.synonym || '-';
        document.getElementById('gSearchLink').href = 'https://www.google.ch/search?q=' + encodeURIComponent(currentGameWord.Wort || '');
        document.getElementById('gTranslateLink').href = 'https://translate.google.com/?sl=de&tl=fr&text=' + encodeURIComponent(currentGameWord.Wort || '') + '&op=translate';

        const verbLink = document.getElementById('gVerbLink');
        if (currentGameWord.VerbFlag == 1) {
            verbLink.href = 'https://www.verbformen.de/konjugation/' + encodeURIComponent(currentGameWord.Wort || '') + '.htm';
            verbLink.style.display = 'inline-flex';
        } else {
            verbLink.style.display = 'none';
        }

        document.getElementById('gThema').textContent = currentGameWord.Thema || '-';
        document.getElementById('gArt').textContent = currentGameWord.Artikel || 'Keiner';
        document.getElementById('gPlural').textContent = currentGameWord.Plural || '-';
        document.getElementById('gGrundverb').textContent = currentGameWord.grundverb || '-';
        document.getElementById('gPraefix').textContent = currentGameWord.praefix || '-';
        document.getElementById('gPraeposition').textContent = currentGameWord.praeposition_kollokation || '-';
        document.getElementById('gWart').textContent = currentGameWord.Wortarten;
        document.getElementById('gIsVerb').textContent = (currentGameWord.VerbFlag == 1) ? 'Ja' : 'Nein';
        document.getElementById('gEx').textContent = currentGameWord.Beispiel;
        document.getElementById('gDates').textContent = `Erstellt: ${currentGameWord.Created || '-'} | Geändert: ${currentGameWord.Modified || '-'} | Nächste Übung: ${currentGameWord.NachsteUbungDatum || '-'}`;

        document.getElementById('userSentenceInput').value = '';
        document.getElementById('aiCorrectionResult').style.display = 'none';
        document.getElementById('aiSuggestionResult').style.display = 'none';
        document.getElementById('sentenceWriterContainer').style.display = 'none';
        document.getElementById('toggleSentenceWriterBtn').textContent = '✍️ Satz schreiben';

        document.getElementById('detailsBox').style.display = 'none';
        document.getElementById('revealBtn').innerHTML = 'Übersetzung anzeigen';
    } catch (err) {
        console.error('Fehler beim Laden des nächsten Vokabelworts', err);
    }
}

function renderQuizGame(data) {
    const grid = document.getElementById('quizGrid');
    grid.innerHTML = '';

    if (!data.options || data.options.length === 0) {
        grid.innerHTML = `<div style="grid-column: span 2; text-align: center; color: var(--md-text-muted);">Keine Optionen verfügbar.</div>`;
        return;
    }

    const wordDisplay = document.getElementById('quizWordDisplay');
    wordDisplay.textContent = data.word.Wort;
    wordDisplay.className = `word-display ${getArticleColorClass(data.word.Artikel)}`;

    data.options.forEach(optText => {
        const card = document.createElement('div');
        card.className = 'quiz-card';
        card.textContent = optText;
        card.onclick = () => handleQuizClick(optText, data.correct_translation);
        grid.appendChild(card);
    });
}

function startQuizTimer() {
    if (quizTimerInterval) clearInterval(quizTimerInterval);
    quizTimeLeft = quizTotalTime;
    quizStartTime = Date.now();
    const fillEl = document.getElementById('quizTimerFill');
    if (fillEl) {
        fillEl.style.width = '100%';
        fillEl.style.backgroundColor = 'var(--md-success)';
    }

    quizTimerInterval = setInterval(() => {
        quizTimeLeft -= 0.1;
        if (fillEl) {
            const pct = Math.max(0, (quizTimeLeft / quizTotalTime) * 100);
            fillEl.style.width = pct + '%';
            if (quizTimeLeft <= 10) {
                fillEl.style.backgroundColor = 'var(--md-primary)';
            }
        }

        if (quizTimeLeft <= 0) {
            clearInterval(quizTimerInterval);
            handleQuizTimeout();
        }
    }, 100);
}

async function handleQuizTimeout() {
    if (!currentQuizData || !currentQuizData.word) return;
    const cards = document.querySelectorAll('#quizGrid .quiz-card');
    cards.forEach(card => { card.onclick = null; });

    cards.forEach(card => {
        if (card.textContent.trim() === currentQuizData.correct_translation) {
            card.classList.add('correct');
        }
    });

    await fetch('index.php?api=game_answer&_ts=' + Date.now(), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        cache: 'no-store',
        body: JSON.stringify({ wort: currentQuizData.word.Wort, result: 'passiv' })
    });
    todayReviewedCount++;
    updateDailyTrackerUI();

    showQuizModal(false, currentQuizData.word.Wort, 'passiv', 45);
}

async function handleQuizClick(selectedTranslation, correctTranslation) {
    if (quizTimerInterval) clearInterval(quizTimerInterval);
    const elapsedSeconds = (Date.now() - quizStartTime) / 1000;
    const cards = document.querySelectorAll('#quizGrid .quiz-card');
    const isCorrect = (selectedTranslation === correctTranslation);

    cards.forEach(card => {
        const txt = card.textContent.trim();
        if (txt === correctTranslation) {
            card.classList.add('correct');
        } else if (txt === selectedTranslation && !isCorrect) {
            card.classList.add('wrong');
        }
        card.onclick = null;
    });

    let answerResult = 'passiv';
    if (isCorrect) {
        if (elapsedSeconds <= 5) {
            answerResult = 'sehr_gut';
        } else {
            answerResult = 'yes';
        }
    } else {
        answerResult = 'passiv';
    }

    await fetch('index.php?api=game_answer&_ts=' + Date.now(), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        cache: 'no-store',
        body: JSON.stringify({ wort: currentQuizData.word.Wort, result: answerResult })
    });
    todayReviewedCount++;
    updateDailyTrackerUI();

    showQuizModal(isCorrect, currentQuizData.word.Wort, answerResult, elapsedSeconds);
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
        <button type="button" class="btn" style="flex: 1; min-width: 45%; padding: 6px; font-size: 0.8rem;" onclick="editWordFromPopup(${rowJson})">✏️ Bearbeiten</button>
        <button type="button" class="btn btn-success" style="flex: 1; min-width: 45%; padding: 6px; font-size: 0.8rem;" onclick="trainSpecificWord('${escapeJs(wordObj.Wort)}')">🚀 Üben</button>
        ${!isAlreadyAktiva ? `<button type="button" class="btn btn-aktiva" style="flex: 1; min-width: 45%; padding: 6px; font-size: 0.8rem;" onclick="directPromoteFromPopup('${escapeJs(wordObj.Wort)}')">🚀 Direkt aktiv</button>` : ''}
        <button type="button" class="btn btn-danger" style="flex: 1; min-width: 45%; padding: 6px; font-size: 0.8rem;" onclick="deleteWordFromPopup('${escapeJs(wordObj.Wort)}')">🗑️ Löschen</button>
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

function showQuizModal(isCorrect, wordKey, resultType, elapsedSeconds) {
    if (!currentQuizData || !currentQuizData.word) return;
    const w = currentQuizData.word;

    let rewardHtml = '';
    let titleEmoji = '🎉';
    let titleText = 'Richtig!';

    if (!isCorrect) {
        titleEmoji = '⏰';
        titleText = 'Falsch oder Zeit abgelaufen!';
        rewardHtml = '<div style="color: #e53935; font-weight: 600; margin-top: 6px;">❌ Ergebnis: Falsch (Score zurückgesetzt)</div>';
    } else if (resultType === 'sehr_gut') {
        titleEmoji = '🚀';
        titleText = 'Blitzschnell!';
        rewardHtml = `<div style="color: var(--md-aktiva-green); font-weight: 600; margin-top: 6px;">⚡ Sehr gut ⭐ (${elapsedSeconds.toFixed(1)}s): +3 Score ⭐⭐⭐</div>`;
    } else {
        titleEmoji = '🎯';
        titleText = 'Perfekt!';
        rewardHtml = `<div style="color: var(--md-success); font-weight: 600; margin-top: 6px;">✅ Standard-Erfolg 👍 (${elapsedSeconds.toFixed(1)}s): Richtig beantwortet! (+1 Score / Ja) 👍</div>`;
    }

    document.getElementById('modalTitle').innerHTML = `${titleEmoji} ${titleText}`;
    const modalText = document.getElementById('modalThemaText');
    const artClass = getArticleColorClass(w.Artikel);
    modalText.innerHTML = `
        <div style="margin-bottom: 8px; font-size: 1.05rem;"><strong>Wort:</strong> <span class="${artClass}" style="font-weight: 600;">${escapeHtml(w.Wort)}</span></div>
        <div style="margin-bottom: 8px;"><strong>Übersetzung:</strong> ${escapeHtml(w.Übersetzung)}</div>
        <div style="margin-bottom: 8px;"><strong>Thema:</strong> <em>${escapeHtml(w.Thema)}</em></div>
        <hr style="border: 0; border-top: 1px solid var(--md-border); margin: 10px 0;">
        ${rewardHtml}
    `;

    renderPopupActionButtons(w);
    document.getElementById('themaModalOverlay').style.display = 'flex';
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
        btn.textContent = '✕ Satz schreiben schließen';
    } else {
        container.style.display = 'none';
        btn.textContent = '✍️ Satz schreiben';
    }
}

function parseMarkdown(text) {
    let safeText = escapeHtml(text);
    safeText = safeText.replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>');
    return safeText.replace(/\n/g, '<br>');
}

async function suggestSentenceWithAI() {
    if (!currentGameWord) return;

    const btn = document.getElementById('suggestSentenceBtn');
    const resultBox = document.getElementById('aiSuggestionResult');
    const suggestionText = document.getElementById('aiSuggestionText');

    btn.disabled = true;
    btn.textContent = 'Generiere Vorschlag...';
    resultBox.style.display = 'none';

    try {
        const res = await fetch('index.php?api=suggest_sentence&_ts=' + Date.now(), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            cache: 'no-store',
            body: JSON.stringify({ word: currentGameWord.Wort })
        });
        const data = await res.json();

        if (data.error === 'Unauthorized') {
            window.location.href = 'login.php';
            return;
        }

        if (data.success) {
            suggestionText.innerHTML = parseMarkdown(data.suggestion);
            resultBox.style.display = 'block';
        } else {
            suggestionText.textContent = data.error || 'Fehler bei der Vorschlagserstellung.';
            resultBox.style.display = 'block';
        }
    } catch (err) {
        console.error('KI-Vorschlag fehlgeschlagen', err);
        suggestionText.textContent = 'Netzwerkfehler.';
        resultBox.style.display = 'block';
    } finally {
        btn.disabled = false;
        btn.textContent = '💡 Satz vorschlagen';
    }
}

async function checkSentenceWithAI() {
    const sentence = document.getElementById('userSentenceInput').value.trim();
    if (!sentence || !currentGameWord) return;

    const btn = document.getElementById('checkSentenceBtn');
    const resultBox = document.getElementById('aiCorrectionResult');
    const correctionText = document.getElementById('aiCorrectionText');

    btn.disabled = true;
    btn.textContent = 'Prüfe...';
    resultBox.style.display = 'none';

    try {
        const res = await fetch('index.php?api=check_sentence&_ts=' + Date.now(), {
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
            correctionText.innerHTML = parseMarkdown(data.correction);
            resultBox.style.display = 'block';
        } else {
            correctionText.textContent = data.error || 'Fehler bei der Prüfung.';
            resultBox.style.display = 'block';
        }
    } catch (err) {
        console.error('KI-Prüfung fehlgeschlagen', err);
        correctionText.textContent = 'Netzwerkfehler.';
        resultBox.style.display = 'block';
    } finally {
        btn.disabled = false;
        btn.textContent = 'Normal prüfen';
    }
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
                correctionTextEl.innerHTML = `<span style="color: #81c784;">${parseMarkdown(data.correction)}</span>` + `<div style="color: #90caf9; font-weight: bold; margin-top: 8px;">${msg}</div>`;
                todayReviewedCount++;
                updateDailyTrackerUI();
            } else {
                correctionTextEl.innerHTML = `<span style="color: #e53935; font-weight: bold;">⚠️ Der Satz enthält Grammatikfehler (+1 Punkt gutgeschrieben).</span><br><br><span style="color: #e0e0e0;">${parseMarkdown(data.correction)}</span>`;
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

async function inlineRateWord(wort, result) {
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
    }
}

function openEditFromGame() {
    if (!currentGameWord) return;
    switchView('dashboard');
    editWord(currentGameWord);
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

async function loadDashboardData(reset = false) {
    if (reset) {
        currentOffset = 0;
        hasMoreData = true;
    }

    const list = document.getElementById('filterList').value;
    const thema = document.getElementById('filterThema').value;
    const wortart = document.getElementById('filterWortart').value;
    const score = document.getElementById('filterScore').value;
    const status = document.getElementById('filterStatus').value;
    const verbFlag = document.getElementById('filterVerbFlag').value;
    const grundverb = document.getElementById('filterGrundverb').value;
    const praefix = document.getElementById('filterPraefix').value;
    const searchVal = document.getElementById('filterSearch').value.trim();
    const transSearchVal = document.getElementById('filterTranslationSearch').value.trim();

    const url = `index.php?api=get_data&letter=${encodeURIComponent(currentLetter)}&search=${encodeURIComponent(searchVal)}&trans_search=${encodeURIComponent(transSearchVal)}&sharepoint_list=${encodeURIComponent(list)}&thema=${encodeURIComponent(thema)}&wortart=${encodeURIComponent(wortart)}&score=${encodeURIComponent(score)}&status=${encodeURIComponent(status)}&verb_flag=${encodeURIComponent(verbFlag)}&grundverb=${encodeURIComponent(grundverb)}&praefix=${encodeURIComponent(praefix)}&sort=${currentSort}&order=${currentOrder}&offset=${currentOffset}&_ts=` + Date.now();

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

function renderTable(words) {
    const tbody = document.getElementById('wordTableBody');

    if (words.length === 0) {
        tbody.innerHTML = `<tr><td colspan="9" style="text-align: center; color: var(--md-text-muted); padding: 2rem;">Keine Vokabeln gefunden.</td></tr>`;
        return;
    }

    tbody.innerHTML = '';
    words.forEach(row => {
        const artClass = getArticleColorClass(row.Artikel);
        const googleUrl = 'https://www.google.ch/search?q=' + encodeURIComponent(row.Wort || '');
        const translateUrl = 'https://translate.google.com/?sl=de&tl=fr&text=' + encodeURIComponent(row.Wort || '') + '&op=translate';
        const isVerb = (row.VerbFlag == 1);

        let verbButtonHtml = '';
        if (isVerb) {
            const verbformenUrl = 'https://www.verbformen.de/konjugation/' + encodeURIComponent(row.Wort || '') + '.htm';
            verbButtonHtml = `<a href="${verbformenUrl}" target="_blank" rel="noopener noreferrer" class="btn" style="padding: 4px 8px; font-size: 0.78rem; background-color: #6a1b9a;">📖 Konjugation</a>`;
        }

        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td data-label="Artikel"><strong>${escapeHtml(row.Artikel || '')}</strong></td>
            <td data-label="Wort" class="wort-cell ${artClass}">${escapeHtml(row.Wort || '')}</td>
            <td data-label="Plural">${!isVerb ? escapeHtml(row.Plural || '') : ''}</td>
            <td data-label="Übersetzung">
                <span class="story-word-trans" style="display: none; color: #90caf9;">${escapeHtml(row.Übersetzung || '')}</span>
                <button type="button" class="btn btn-secondary" style="padding: 2px 6px; font-size: 0.75rem; margin-top: 4px;" onclick="toggleStoryTransTable(this)">Übersetzung anzeigen</button>
            </td>
            <td data-label="Werkzeuge">
                <div style="display: flex; gap: 4px; flex-wrap: wrap;">
                    <a href="${googleUrl}" target="_blank" rel="noopener noreferrer" class="btn btn-secondary" style="padding: 4px 8px; font-size: 0.78rem;">🔍 Google</a>
                    <a href="${translateUrl}" target="_blank" rel="noopener noreferrer" class="btn btn-info" style="padding: 4px 8px; font-size: 0.78rem;">🌐 Übersetzung</a>
                    ${verbButtonHtml}
                </div>
            </td>
            <td data-label="Score">${escapeHtml(row.Score || 0)}</td>
            <td class="kenntnisse-cell" data-label="Kenntnisse">
                <button onclick="inlineRateWord('${escapeJs(row.Wort)}', 'sehr_gut')" class="btn btn-aktiva" style="padding: 8px 12px; font-size: 0.85rem;" title="sehr gut">sehr gut</button>
                <button onclick="inlineRateWord('${escapeJs(row.Wort)}', 'yes')" class="btn btn-success" style="padding: 8px 12px; font-size: 0.85rem;" title="ja">ja</button>
                <button onclick="inlineRateWord('${escapeJs(row.Wort)}', 'wiederholen')" class="btn" style="padding: 8px 12px; font-size: 0.85rem; background-color: var(--md-primary);" title="wiederholen">wiederholen</button>
                <button onclick="inlineRateWord('${escapeJs(row.Wort)}', 'passiv')" class="btn btn-passiv" style="padding: 8px 12px; font-size: 0.85rem;" title="passiv">passiv</button>
                <button onclick="inlineRateWord('${escapeJs(row.Wort)}', 'warteschlange')" class="btn" style="padding: 8px 12px; font-size: 0.85rem; background-color: #424242;" title="warteschlange">warteschlange</button>
            </td>
            <td data-label="Status" class="status-cell">${escapeHtml(row.Status || '')}</td>
            <td class="aktion-cell" data-label="Aktion">
                <button onclick="editWord(${escapeAttr(JSON.stringify(row))})" class="btn" style="padding: 6px 10px; font-size: 0.75rem;" title="Bearbeiten">Bearbeiten</button>
                <button onclick="trainSpecificWord('${escapeJs(row.Wort)}')" class="btn btn-success" style="padding: 6px 10px; font-size: 0.75rem;" title="Üben">Üben</button>
                <button onclick="deleteWord('${escapeJs(row.Wort)}')" class="btn btn-danger" style="padding: 6px 10px; font-size: 0.75rem;" title="Löschen">Löschen</button>
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
    document.getElementById('filterScore').value = '';
    document.getElementById('filterStatus').value = '';
    document.getElementById('filterVerbFlag').value = '';
    document.getElementById('filterGrundverb').value = '';
    document.getElementById('filterPraefix').value = '';
    loadDashboardData(true);
}

function openAddForm() {
    resetForm();
    document.getElementById('formContainer').classList.add('active');
    document.getElementById('formToggleBar').style.display = 'none';
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
    document.getElementById('Status').value = 'neu';
    document.getElementById('Score').value = '0';
    toggleVerbFields();
    document.getElementById('formTitle').textContent = 'Neues Wort hinzufügen';
    document.getElementById('formSubmitBtn').textContent = 'Wort speichern';
    document.getElementById('formContainer').classList.remove('active');
    document.getElementById('formToggleBar').style.display = 'block';
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
    document.getElementById('Score').value = row.Score || 0;
    document.getElementById('Status').value = row.Status || 'neu';

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
        Score: document.getElementById('Score').value,
        Status: document.getElementById('Status').value
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