<?php
// user_settings.php - per-user settings shared by index.php, profile.php and willkommen.php:
// the subscription plans and the languages the AI translates new words into.

const PLAN_OPTIONS = [
    'free'    => ['Gratis', 'Wörter lernen, alle Spiele ohne KI, Statistik'],
    'ai'      => ['KI', 'Alles aus Gratis, plus KI-Ausfüllen, Satzprüfung und Grammatik-Challenge'],
    'ai_full' => ['KI Plus', 'Alles aus KI, plus Bildgenerierung zu Ihren Wörtern'],
];

// Code => German name (used in the UI and in the AI prompt)
const TRANSLATION_LANGUAGES = [
    'en' => 'Englisch',
    'fr' => 'Französisch',
    'it' => 'Italienisch',
    'es' => 'Spanisch',
    'pt' => 'Portugiesisch',
    'nl' => 'Niederländisch',
    'zh' => 'Chinesisch (Mandarin)',
];
const MAX_TRANSLATION_LANGUAGES = 3;
// What accounts got before languages could be chosen
const DEFAULT_TRANSLATION_LANGUAGES = ['en', 'fr', 'it'];

// Keeps known codes only, in the order of TRANSLATION_LANGUAGES, at most MAX_TRANSLATION_LANGUAGES
function cleanLanguageList($codes): array {
    if (is_string($codes)) {
        $codes = explode(',', $codes);
    }
    if (!is_array($codes)) {
        return [];
    }
    $codes = array_map(fn($c) => strtolower(trim((string)$c)), $codes);
    $clean = array_values(array_filter(array_keys(TRANSLATION_LANGUAGES), fn($c) => in_array($c, $codes, true)));
    return array_slice($clean, 0, MAX_TRANSLATION_LANGUAGES);
}

// The user's languages; null when the user has not chosen yet (first visit).
// Before the database column exists, everybody gets the old default.
function getTranslationLanguages(PDO $pdo, int $uid): ?array {
    try {
        $stmt = $pdo->prepare('SELECT translation_langs FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$uid]);
        $value = $stmt->fetchColumn();
    } catch (Throwable $e) {
        return DEFAULT_TRANSLATION_LANGUAGES;
    }
    if ($value === null || $value === false || $value === '') {
        return null;
    }
    $langs = cleanLanguageList((string)$value);
    return $langs ?: DEFAULT_TRANSLATION_LANGUAGES;
}

function saveTranslationLanguages(PDO $pdo, int $uid, array $langs): void {
    $pdo->prepare('UPDATE users SET translation_langs = ? WHERE id = ?')->execute([implode(',', $langs), $uid]);
}

function languageNames(array $langs): array {
    return array_map(fn($c) => TRANSLATION_LANGUAGES[$c], $langs);
}

// Checkbox list used on the profile and the welcome page (max 3 enforced in the browser and on the server)
function renderLanguageCheckboxes(array $selected): void {
    foreach (TRANSLATION_LANGUAGES as $code => $name) {
        $checked = in_array($code, $selected, true) ? ' checked' : '';
        echo '<label class="lang-option"><input type="checkbox" name="langs[]" value="' . $code . '"' . $checked . '><span>'
            . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '</span></label>';
    }
    // Disable the other boxes once three are ticked
    echo '<script>(function(){var s=document.currentScript,b=s.parentNode.querySelectorAll(\'input[name="langs[]"]\');'
        . 'function u(){var n=0;b.forEach(function(x){if(x.checked)n++;});b.forEach(function(x){x.disabled=!x.checked&&n>=' . MAX_TRANSLATION_LANGUAGES . ';});}'
        . 'b.forEach(function(x){x.addEventListener("change",u);});u();})();</script>';
}

// Plan radio list used on the profile and the welcome page
function renderPlanOptions(string $current, bool $markCurrent = true): void {
    foreach (PLAN_OPTIONS as $value => [$title, $desc]) {
        $isCurrent = $value === $current;
        echo '<label class="plan-option"><input type="radio" name="plan" value="' . $value . '"' . ($isCurrent ? ' checked' : '') . '>'
            . '<span class="plan-text"><strong>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8')
            . ($isCurrent && $markCurrent ? ' <span class="plan-badge">aktuell</span>' : '') . '</strong>'
            . '<span>' . htmlspecialchars($desc, ENT_QUOTES, 'UTF-8') . '</span></span></label>';
    }
}

// Styles for both lists (pages define --primary, --muted, --row-border)
function settingsStyles(): void {
    echo '<style>
        .plan-form { display: grid; gap: 10px; margin-bottom: 28px; }
        .plan-option, .lang-option { display: flex; align-items: flex-start; gap: 12px; margin: 0; padding: 12px 14px; border: 1px solid var(--row-border); border-radius: 10px; cursor: pointer; font-weight: 400; }
        .plan-option:has(input:checked), .lang-option:has(input:checked) { border-color: var(--primary); box-shadow: 0 0 0 1px var(--primary); }
        .lang-option:has(input:disabled) { opacity: 0.5; cursor: not-allowed; }
        .plan-option input, .lang-option input { width: 18px; height: 18px; min-height: 0; margin: 2px 0 0; padding: 0; accent-color: var(--primary); flex-shrink: 0; }
        .plan-text { display: grid; gap: 2px; min-width: 0; }
        .plan-text span { color: var(--muted); font-size: 0.88rem; }
        .plan-badge { margin-left: 6px; padding: 1px 8px; border-radius: 999px; font-size: 0.72rem; color: var(--primary); background: var(--row-border); vertical-align: middle; }
        .lang-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 8px; }
        .lang-option { align-items: center; padding: 10px 12px; }
        .plan-form .submit-button { margin-top: 4px; }
    </style>';
}
