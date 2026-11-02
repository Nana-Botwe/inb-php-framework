// INB PHP Framework docs: mobile menu, "/" to search, copy buttons, light syntax colouring, active TOC link.
(function () {
    'use strict';

    // Mobile navigation
    var menu = document.querySelector('[data-docs-menu]');
    var sidebar = document.getElementById('sidebar');
    if (menu && sidebar) {
        menu.addEventListener('click', function () {
            var open = sidebar.classList.toggle('open');
            menu.setAttribute('aria-expanded', open ? 'true' : 'false');
        });
    }

    // Press "/" to focus the search box
    var search = document.getElementById('docs-q');
    document.addEventListener('keydown', function (e) {
        if (e.key === '/' && search && document.activeElement !== search && !/input|textarea/i.test(document.activeElement.tagName)) {
            e.preventDefault();
            search.focus();
        }
    });

    // Minimal, safe syntax colouring (works on text nodes only, never on HTML strings)
    var KEYWORDS = /\b(function|return|new|class|extends|implements|public|private|protected|static|final|abstract|use|namespace|if|else|elseif|foreach|as|for|while|fn|match|throw|try|catch|null|true|false|array|string|int|bool|void|mixed|self|readonly|const)\b/;
    function colour(code) {
        var text = code.textContent;
        var lang = (code.className.match(/language-(\w+)/) || [])[1] || '';
        if (!/^(php|blade|js|javascript)$/.test(lang)) { return; }
        var pattern = /(\/\/[^\n]*|#[^\n]*|\/\*[\s\S]*?\*\/)|('(?:\\.|[^'\\])*'|"(?:\\.|[^"\\])*")|(\$[A-Za-z_]\w*)|(\b[A-Za-z_]\w*\b)/g;
        var fragment = document.createDocumentFragment();
        var last = 0, m;
        while ((m = pattern.exec(text)) !== null) {
            if (m.index > last) { fragment.appendChild(document.createTextNode(text.slice(last, m.index))); }
            var span = null;
            if (m[1] && !(lang === 'blade' && m[1][0] === '#')) { span = 'tok-c'; }
            else if (m[2]) { span = 'tok-s'; }
            else if (m[3]) { span = 'tok-v'; }
            else if (m[4] && KEYWORDS.test(m[4]) && m[4].length === m[4].match(KEYWORDS)[0].length) { span = 'tok-k'; }
            else if (m[4] && /^[A-Z]/.test(m[4])) { span = 'tok-n'; }
            if (span) {
                var el = document.createElement('span');
                el.className = span;
                el.textContent = m[0];
                fragment.appendChild(el);
            } else {
                fragment.appendChild(document.createTextNode(m[0]));
            }
            last = pattern.lastIndex;
        }
        fragment.appendChild(document.createTextNode(text.slice(last)));
        code.textContent = '';
        code.appendChild(fragment);
    }

    document.querySelectorAll('.code-block').forEach(function (block) {
        var code = block.querySelector('code');
        if (!code) { return; }
        var raw = code.textContent;
        colour(code);

        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'copy-btn';
        button.textContent = 'Copy';
        button.addEventListener('click', function () {
            navigator.clipboard.writeText(raw).then(function () {
                button.textContent = 'Copied!';
                setTimeout(function () { button.textContent = 'Copy'; }, 1500);
            });
        });
        block.appendChild(button);
    });

    // Highlight the current section in "On this page"
    var links = Array.prototype.slice.call(document.querySelectorAll('.toc a'));
    if (links.length && 'IntersectionObserver' in window) {
        var byId = {};
        links.forEach(function (a) { byId[decodeURIComponent(a.hash.slice(1))] = a; });
        var observer = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting && byId[entry.target.id]) {
                    links.forEach(function (a) { a.classList.remove('current'); });
                    byId[entry.target.id].classList.add('current');
                }
            });
        }, { rootMargin: '-70px 0px -70% 0px' });
        document.querySelectorAll('.prose h2[id], .prose h3[id]').forEach(function (h) { observer.observe(h); });
    }
})();
