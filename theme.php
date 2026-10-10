<?php
/**
 * Light/dark theme switch – everything in one file.
 *
 * Usage on every page:
 *   1. At the top:            require_once 'theme.php';
 *   2. Inside <head>:         <?php theme_head(); ?>
 *   3. Where the switch goes: <?php theme_toggle(); ?>
 *      (login page, floating top-right: <?php theme_toggle(true); ?>)
 */

function theme_head(): void
{
    echo <<<'HTML'
<script>
/* Set the saved theme before the page paints (no white flash) */
(function () {
    var t = 'dark';
    try {
        var s = localStorage.getItem('mw-theme');
        if (s === 'light' || s === 'dark') t = s;
    } catch (e) {}
    document.documentElement.setAttribute('data-theme', t);
})();
</script>
<style>
:root { color-scheme: dark; }
:root[data-theme="light"] { color-scheme: light; }

/* Doodle background with small brains (all pages) */
body { background-image: url("data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 width=%22280%22 height=%22280%22 viewBox=%220 0 280 280%22%3E%3Cg fill=%22none%22 stroke=%22rgba(255,255,255,0.11)%22 stroke-width=%222.2%22 stroke-linecap=%22round%22 stroke-linejoin=%22round%22%3E%3Cg transform=%22translate(12 10) rotate(-15 20 20) scale(1.0)%22%3E%3Cpath d=%22M20 6C15 4 10 6 9 10C5 11 4 16 6 19C3 22 4 28 8 29C9 33 14 35 18 33L20 32M20 6C25 4 30 6 31 10C35 11 36 16 34 19C37 22 36 28 32 29C31 33 26 35 22 33L20 32M20 6V32M13 12C16 13 16 17 13 18M27 12C24 13 24 17 27 18M11 24C14 23 16 25 15 28M29 24C26 23 24 25 25 28%22/%3E%3C/g%3E%3Cg transform=%22translate(130 4) rotate(20 20 20) scale(0.8)%22%3E%3Cpath d=%22M20 6C15 4 10 6 9 10C5 11 4 16 6 19C3 22 4 28 8 29C9 33 14 35 18 33L20 32M20 6C25 4 30 6 31 10C35 11 36 16 34 19C37 22 36 28 32 29C31 33 26 35 22 33L20 32M20 6V32M13 12C16 13 16 17 13 18M27 12C24 13 24 17 27 18M11 24C14 23 16 25 15 28M29 24C26 23 24 25 25 28%22/%3E%3C/g%3E%3Cg transform=%22translate(214 60) rotate(-8 20 20) scale(1.05)%22%3E%3Cpath d=%22M20 6C15 4 10 6 9 10C5 11 4 16 6 19C3 22 4 28 8 29C9 33 14 35 18 33L20 32M20 6C25 4 30 6 31 10C35 11 36 16 34 19C37 22 36 28 32 29C31 33 26 35 22 33L20 32M20 6V32M13 12C16 13 16 17 13 18M27 12C24 13 24 17 27 18M11 24C14 23 16 25 15 28M29 24C26 23 24 25 25 28%22/%3E%3C/g%3E%3Cg transform=%22translate(70 84) rotate(30 20 20) scale(0.85)%22%3E%3Cpath d=%22M20 6C15 4 10 6 9 10C5 11 4 16 6 19C3 22 4 28 8 29C9 33 14 35 18 33L20 32M20 6C25 4 30 6 31 10C35 11 36 16 34 19C37 22 36 28 32 29C31 33 26 35 22 33L20 32M20 6V32M13 12C16 13 16 17 13 18M27 12C24 13 24 17 27 18M11 24C14 23 16 25 15 28M29 24C26 23 24 25 25 28%22/%3E%3C/g%3E%3Cg transform=%22translate(168 140) rotate(-25 20 20) scale(0.95)%22%3E%3Cpath d=%22M20 6C15 4 10 6 9 10C5 11 4 16 6 19C3 22 4 28 8 29C9 33 14 35 18 33L20 32M20 6C25 4 30 6 31 10C35 11 36 16 34 19C37 22 36 28 32 29C31 33 26 35 22 33L20 32M20 6V32M13 12C16 13 16 17 13 18M27 12C24 13 24 17 27 18M11 24C14 23 16 25 15 28M29 24C26 23 24 25 25 28%22/%3E%3C/g%3E%3Cg transform=%22translate(6 166) rotate(12 20 20) scale(0.8)%22%3E%3Cpath d=%22M20 6C15 4 10 6 9 10C5 11 4 16 6 19C3 22 4 28 8 29C9 33 14 35 18 33L20 32M20 6C25 4 30 6 31 10C35 11 36 16 34 19C37 22 36 28 32 29C31 33 26 35 22 33L20 32M20 6V32M13 12C16 13 16 17 13 18M27 12C24 13 24 17 27 18M11 24C14 23 16 25 15 28M29 24C26 23 24 25 25 28%22/%3E%3C/g%3E%3Cg transform=%22translate(100 200) rotate(-5 20 20) scale(1.05)%22%3E%3Cpath d=%22M20 6C15 4 10 6 9 10C5 11 4 16 6 19C3 22 4 28 8 29C9 33 14 35 18 33L20 32M20 6C25 4 30 6 31 10C35 11 36 16 34 19C37 22 36 28 32 29C31 33 26 35 22 33L20 32M20 6V32M13 12C16 13 16 17 13 18M27 12C24 13 24 17 27 18M11 24C14 23 16 25 15 28M29 24C26 23 24 25 25 28%22/%3E%3C/g%3E%3Cg transform=%22translate(220 214) rotate(35 20 20) scale(0.75)%22%3E%3Cpath d=%22M20 6C15 4 10 6 9 10C5 11 4 16 6 19C3 22 4 28 8 29C9 33 14 35 18 33L20 32M20 6C25 4 30 6 31 10C35 11 36 16 34 19C37 22 36 28 32 29C31 33 26 35 22 33L20 32M20 6V32M13 12C16 13 16 17 13 18M27 12C24 13 24 17 27 18M11 24C14 23 16 25 15 28M29 24C26 23 24 25 25 28%22/%3E%3C/g%3E%3Cg transform=%22translate(42 246) rotate(-30 20 20) scale(0.7)%22%3E%3Cpath d=%22M20 6C15 4 10 6 9 10C5 11 4 16 6 19C3 22 4 28 8 29C9 33 14 35 18 33L20 32M20 6C25 4 30 6 31 10C35 11 36 16 34 19C37 22 36 28 32 29C31 33 26 35 22 33L20 32M20 6V32M13 12C16 13 16 17 13 18M27 12C24 13 24 17 27 18M11 24C14 23 16 25 15 28M29 24C26 23 24 25 25 28%22/%3E%3C/g%3E%3C/g%3E%3C/svg%3E"); }
:root[data-theme="light"] body { background-image: url("data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 width=%22280%22 height=%22280%22 viewBox=%220 0 280 280%22%3E%3Cg fill=%22none%22 stroke=%22rgba(30,45,70,0.13)%22 stroke-width=%222.2%22 stroke-linecap=%22round%22 stroke-linejoin=%22round%22%3E%3Cg transform=%22translate(12 10) rotate(-15 20 20) scale(1.0)%22%3E%3Cpath d=%22M20 6C15 4 10 6 9 10C5 11 4 16 6 19C3 22 4 28 8 29C9 33 14 35 18 33L20 32M20 6C25 4 30 6 31 10C35 11 36 16 34 19C37 22 36 28 32 29C31 33 26 35 22 33L20 32M20 6V32M13 12C16 13 16 17 13 18M27 12C24 13 24 17 27 18M11 24C14 23 16 25 15 28M29 24C26 23 24 25 25 28%22/%3E%3C/g%3E%3Cg transform=%22translate(130 4) rotate(20 20 20) scale(0.8)%22%3E%3Cpath d=%22M20 6C15 4 10 6 9 10C5 11 4 16 6 19C3 22 4 28 8 29C9 33 14 35 18 33L20 32M20 6C25 4 30 6 31 10C35 11 36 16 34 19C37 22 36 28 32 29C31 33 26 35 22 33L20 32M20 6V32M13 12C16 13 16 17 13 18M27 12C24 13 24 17 27 18M11 24C14 23 16 25 15 28M29 24C26 23 24 25 25 28%22/%3E%3C/g%3E%3Cg transform=%22translate(214 60) rotate(-8 20 20) scale(1.05)%22%3E%3Cpath d=%22M20 6C15 4 10 6 9 10C5 11 4 16 6 19C3 22 4 28 8 29C9 33 14 35 18 33L20 32M20 6C25 4 30 6 31 10C35 11 36 16 34 19C37 22 36 28 32 29C31 33 26 35 22 33L20 32M20 6V32M13 12C16 13 16 17 13 18M27 12C24 13 24 17 27 18M11 24C14 23 16 25 15 28M29 24C26 23 24 25 25 28%22/%3E%3C/g%3E%3Cg transform=%22translate(70 84) rotate(30 20 20) scale(0.85)%22%3E%3Cpath d=%22M20 6C15 4 10 6 9 10C5 11 4 16 6 19C3 22 4 28 8 29C9 33 14 35 18 33L20 32M20 6C25 4 30 6 31 10C35 11 36 16 34 19C37 22 36 28 32 29C31 33 26 35 22 33L20 32M20 6V32M13 12C16 13 16 17 13 18M27 12C24 13 24 17 27 18M11 24C14 23 16 25 15 28M29 24C26 23 24 25 25 28%22/%3E%3C/g%3E%3Cg transform=%22translate(168 140) rotate(-25 20 20) scale(0.95)%22%3E%3Cpath d=%22M20 6C15 4 10 6 9 10C5 11 4 16 6 19C3 22 4 28 8 29C9 33 14 35 18 33L20 32M20 6C25 4 30 6 31 10C35 11 36 16 34 19C37 22 36 28 32 29C31 33 26 35 22 33L20 32M20 6V32M13 12C16 13 16 17 13 18M27 12C24 13 24 17 27 18M11 24C14 23 16 25 15 28M29 24C26 23 24 25 25 28%22/%3E%3C/g%3E%3Cg transform=%22translate(6 166) rotate(12 20 20) scale(0.8)%22%3E%3Cpath d=%22M20 6C15 4 10 6 9 10C5 11 4 16 6 19C3 22 4 28 8 29C9 33 14 35 18 33L20 32M20 6C25 4 30 6 31 10C35 11 36 16 34 19C37 22 36 28 32 29C31 33 26 35 22 33L20 32M20 6V32M13 12C16 13 16 17 13 18M27 12C24 13 24 17 27 18M11 24C14 23 16 25 15 28M29 24C26 23 24 25 25 28%22/%3E%3C/g%3E%3Cg transform=%22translate(100 200) rotate(-5 20 20) scale(1.05)%22%3E%3Cpath d=%22M20 6C15 4 10 6 9 10C5 11 4 16 6 19C3 22 4 28 8 29C9 33 14 35 18 33L20 32M20 6C25 4 30 6 31 10C35 11 36 16 34 19C37 22 36 28 32 29C31 33 26 35 22 33L20 32M20 6V32M13 12C16 13 16 17 13 18M27 12C24 13 24 17 27 18M11 24C14 23 16 25 15 28M29 24C26 23 24 25 25 28%22/%3E%3C/g%3E%3Cg transform=%22translate(220 214) rotate(35 20 20) scale(0.75)%22%3E%3Cpath d=%22M20 6C15 4 10 6 9 10C5 11 4 16 6 19C3 22 4 28 8 29C9 33 14 35 18 33L20 32M20 6C25 4 30 6 31 10C35 11 36 16 34 19C37 22 36 28 32 29C31 33 26 35 22 33L20 32M20 6V32M13 12C16 13 16 17 13 18M27 12C24 13 24 17 27 18M11 24C14 23 16 25 15 28M29 24C26 23 24 25 25 28%22/%3E%3C/g%3E%3Cg transform=%22translate(42 246) rotate(-30 20 20) scale(0.7)%22%3E%3Cpath d=%22M20 6C15 4 10 6 9 10C5 11 4 16 6 19C3 22 4 28 8 29C9 33 14 35 18 33L20 32M20 6C25 4 30 6 31 10C35 11 36 16 34 19C37 22 36 28 32 29C31 33 26 35 22 33L20 32M20 6V32M13 12C16 13 16 17 13 18M27 12C24 13 24 17 27 18M11 24C14 23 16 25 15 28M29 24C26 23 24 25 25 28%22/%3E%3C/g%3E%3C/g%3E%3C/svg%3E"); }

.theme-toggle {
    position: relative; display: inline-flex; align-items: center; justify-content: space-between;
    flex-shrink: 0; width: 60px; height: 32px; padding: 0 8px;
    border: 1px solid #3d4a5c; border-radius: 999px; background: #1b2433;
    cursor: pointer; transition: background-color 0.3s ease, border-color 0.3s ease;
}
.theme-toggle:hover { border-color: #5b7290; }
.theme-toggle:focus-visible { outline: 2px solid #90caf9; outline-offset: 3px; }
.tt-track-icon { width: 14px; height: 14px; }
.tt-track-icon.tt-sun  { color: #ffca28; }
.tt-track-icon.tt-moon { color: #c5d3ff; }
.tt-knob {
    position: absolute; top: 3px; left: 3px; display: grid; place-items: center;
    width: 24px; height: 24px; border-radius: 50%;
    background: #e8eefc; color: #34497a; box-shadow: 0 1px 3px rgba(0,0,0,0.4);
    transition: transform 0.3s cubic-bezier(0.4,0,0.2,1), background-color 0.3s ease, color 0.3s ease;
}
.tt-knob svg, .tt-track-icon svg { display: block; width: 14px; height: 14px; }
.tt-knob-sun { display: none; }
:root:not([data-theme="light"]) .tt-knob { transform: translateX(28px); }

:root[data-theme="light"] .theme-toggle { background: #bfe1ff; border-color: #8fc3f0; }
:root[data-theme="light"] .theme-toggle:hover { border-color: #5aa5e6; }
:root[data-theme="light"] .theme-toggle:focus-visible { outline-color: #1565c0; }
:root[data-theme="light"] .tt-knob { background: #fff; color: #f59e0b; box-shadow: 0 1px 3px rgba(0,0,0,0.2); }
:root[data-theme="light"] .tt-knob-sun { display: block; }
:root[data-theme="light"] .tt-knob-moon { display: none; }

.theme-toggle-corner { position: fixed; top: 16px; right: 16px; z-index: 50; }

html.theme-switching *:not(.tt-knob),
html.theme-switching *::before,
html.theme-switching *::after {
    transition: background-color 0.25s ease, color 0.25s ease, border-color 0.25s ease, box-shadow 0.25s ease !important;
}
@media (prefers-reduced-motion: reduce) {
    .tt-knob, .theme-toggle { transition: none; }
    html.theme-switching *, html.theme-switching *::before, html.theme-switching *::after { transition: none !important; }
}
</style>
<script>
(function () {
    var KEY = 'mw-theme';
    var root = document.documentElement;

    function getTheme() {
        return root.getAttribute('data-theme') === 'light' ? 'light' : 'dark';
    }
    function syncButtons(theme) {
        document.querySelectorAll('.theme-toggle').forEach(function (btn) {
            btn.setAttribute('aria-checked', theme === 'light' ? 'true' : 'false');
            btn.title = theme === 'light' ? 'Zum dunklen Modus wechseln' : 'Zum hellen Modus wechseln';
        });
    }
    function applyTheme(theme, save) {
        root.classList.add('theme-switching');
        root.setAttribute('data-theme', theme);
        if (save) { try { localStorage.setItem(KEY, theme); } catch (e) {} }
        syncButtons(theme);
        /* Pages can listen for this, e.g. to recolor Chart.js charts */
        document.dispatchEvent(new CustomEvent('themechange', { detail: { theme: theme } }));
        setTimeout(function () { root.classList.remove('theme-switching'); }, 300);
    }

    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.theme-toggle');
        if (btn) applyTheme(getTheme() === 'light' ? 'dark' : 'light', true);
    });
    /* Follow changes made in another open tab */
    window.addEventListener('storage', function (e) {
        if (e.key === KEY && (e.newValue === 'light' || e.newValue === 'dark')) applyTheme(e.newValue, false);
    });
    document.addEventListener('DOMContentLoaded', function () { syncButtons(getTheme()); });
})();
</script>
HTML;
}

function theme_toggle(bool $floating = false): void
{
    $sun  = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg>';
    $moon = '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/></svg>';

    $button = '<button type="button" class="theme-toggle" role="switch" aria-checked="false" aria-label="Heller Modus" title="Zum hellen Modus wechseln">'
        . '<span class="tt-track-icon tt-sun" aria-hidden="true">' . $sun . '</span>'
        . '<span class="tt-track-icon tt-moon" aria-hidden="true">' . $moon . '</span>'
        . '<span class="tt-knob" aria-hidden="true">'
        .   '<span class="tt-knob-sun">' . $sun . '</span>'
        .   '<span class="tt-knob-moon">' . $moon . '</span>'
        . '</span>'
        . '</button>';

    echo $floating ? '<div class="theme-toggle-corner">' . $button . '</div>' : $button;
}