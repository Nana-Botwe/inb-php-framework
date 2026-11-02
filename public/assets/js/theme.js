// Light/dark theme switch for every page. The choice is remembered in localStorage ("inb-theme").
// Until the visitor chooses, the operating system's preference is followed.
(function () {
    'use strict';

    var KEY = 'inb-theme';
    var root = document.documentElement;

    function current() {
        return root.getAttribute('data-theme') === 'dark' ? 'dark' : 'light';
    }

    function apply(theme) {
        root.setAttribute('data-theme', theme);
        root.setAttribute('data-bs-theme', theme); // Bootstrap 5.3 pages
        var next = theme === 'dark' ? 'light' : 'dark';
        document.querySelectorAll('[data-theme-toggle]').forEach(function (button) {
            button.setAttribute('aria-pressed', theme === 'dark' ? 'true' : 'false');
            button.setAttribute('aria-label', 'Switch to ' + next + ' theme');
            button.setAttribute('title', 'Switch to ' + next + ' theme');
        });
    }

    function saved() {
        try {
            return localStorage.getItem(KEY);
        } catch (e) {
            return null;
        }
    }

    apply(current());

    document.addEventListener('click', function (event) {
        var button = event.target.closest ? event.target.closest('[data-theme-toggle]') : null;
        if (!button) {
            return;
        }
        var theme = current() === 'dark' ? 'light' : 'dark';
        try {
            localStorage.setItem(KEY, theme);
        } catch (e) { /* private mode: still switch for this page */ }
        root.classList.add('theme-switching');
        apply(theme);
        window.setTimeout(function () { root.classList.remove('theme-switching'); }, 300);
    });

    // Follow the OS setting live, unless the visitor picked a theme themselves.
    if (window.matchMedia) {
        var media = window.matchMedia('(prefers-color-scheme: dark)');
        var listener = function (e) {
            if (saved() !== 'light' && saved() !== 'dark') {
                apply(e.matches ? 'dark' : 'light');
            }
        };
        if (media.addEventListener) {
            media.addEventListener('change', listener);
        } else if (media.addListener) {
            media.addListener(listener);
        }
    }
})();
