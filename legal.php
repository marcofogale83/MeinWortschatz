<?php
// legal.php - shared pieces for the legal pages (Impressum, Datenschutz)
// and the small footer with links to them on every page.
//
// Operator details live in ONE place: $LEGAL below.
// Everything in [eckigen Klammern] must be filled in before going live.

require_once __DIR__ . '/theme.php';

$LEGAL = [
    'name'        => '[VORNAME NACHNAME]',
    'business'    => '[ggf. Firmenname / Einzelunternehmen, sonst Zeile leer lassen]',
    'street'      => '[STRASSE NR.]',
    'city'        => '[PLZ ORT]',
    'country'     => 'Schweiz',
    'email'       => '[KONTAKT-E-MAIL, z. B. kontakt@vokabiq.ch]',
    'phone'       => '[TELEFON (optional), sonst leer lassen]',
    'uid'         => '[UID / MWST-Nr., falls im Handelsregister eingetragen oder MWST-pflichtig, sonst leer lassen]',
    'eu_rep'      => '[Vertreter in der EU nach Art. 27 DSGVO, falls bestellt, sonst leer lassen]',
    'min_age'     => 16,
    'updated'     => '11. Oktober 2026',
];

// Optional lines (business, phone, uid, eu_rep) are hidden when set to ''.
// Placeholders still in [brackets] are shown highlighted so they are easy to spot.

function legal_e(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

// Small footer: "Impressum · Datenschutz"
function legal_footer(): void {
    echo '<footer class="legal-footer"><a href="impressum">Impressum</a><span aria-hidden="true">·</span><a href="datenschutz">Datenschutz</a></footer>';
    echo '<style>.legal-footer{display:flex;justify-content:center;gap:10px;margin:28px auto 8px;font-size:.82rem;color:var(--muted,#a0a0a0)}.legal-footer a{color:inherit;text-decoration:none}.legal-footer a:hover{text-decoration:underline}</style>';
}

function legal_page_start(string $title): void {
    ?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php theme_head(); ?>
    <title><?= legal_e($title) ?> - vokabiq</title>
    <link rel="icon" href="favicon.ico">
    <style>
        :root {
            --bg: #121212; --surface: #1e1e1e; --text: #e0e0e0; --muted: #a0a0a0;
            --border: #383838; --accent: #90caf9; --mark-bg: #4a3b12; --mark-text: #ffe08a;
        }
        :root[data-theme="light"] {
            --bg: #d5d9de; --surface: #e4e7eb; --text: #1f2933; --muted: #5f6b7a;
            --border: #c8ced5; --accent: #1565c0; --mark-bg: #fff3c4; --mark-text: #6b4e00;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0; padding: 24px 16px; min-height: 100vh;
            background: var(--bg); color: var(--text); line-height: 1.6;
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }
        .container { max-width: 760px; margin: 0 auto; }
        header {
            display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap;
            margin-bottom: 20px; padding: 14px 18px;
            background: var(--surface); border: 1px solid var(--border); border-radius: 8px;
        }
        header a.back { color: var(--accent); text-decoration: none; font-weight: 600; }
        article {
            padding: 22px; background: var(--surface);
            border: 1px solid var(--border); border-radius: 8px; overflow-wrap: anywhere;
        }
        h1 { margin: 0 0 4px; font-size: 1.5rem; color: var(--accent); }
        h2 { margin: 28px 0 8px; font-size: 1.12rem; }
        h3 { margin: 18px 0 6px; font-size: 1rem; }
        p, li { font-size: 0.95rem; }
        ul { padding-left: 1.2em; }
        a { color: var(--accent); }
        .muted { color: var(--muted); font-size: 0.85rem; }
        .ph { background: var(--mark-bg); color: var(--mark-text); padding: 0 4px; border-radius: 3px; }
        table { width: 100%; border-collapse: collapse; font-size: 0.88rem; margin: 8px 0; }
        th, td { text-align: left; vertical-align: top; padding: 8px 6px; border-top: 1px solid var(--border); }
        @media (max-width: 520px) { article { padding: 18px 14px; } }
    </style>
</head>
<body>
<main class="container">
    <header>
        <a class="back" href="./">← vokabiq</a>
        <?php theme_toggle(); ?>
    </header>
    <article>
    <?php
}

function legal_page_end(): void {
    echo "    </article>\n";
    legal_footer();
    echo "</main>\n</body>\n</html>\n";
}

// Print a value, highlighting it while it is still a [placeholder]
function legal_v(string $value): string {
    $safe = legal_e($value);
    return strncmp($value, '[', 1) === 0 ? '<span class="ph">' . $safe . '</span>' : $safe;
}
