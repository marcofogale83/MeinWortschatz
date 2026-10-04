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