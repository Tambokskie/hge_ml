// HELP page: one chapter at a time, contents with collapsible groups, search across
// the whole guide, previous/next navigation, progress and a back-to-top button.
// Without JavaScript every chapter is shown and the contents links are plain anchors.
document.addEventListener('DOMContentLoaded', function () {
    var chapters = Array.prototype.slice.call(document.querySelectorAll('[data-help-chapter]'));
    if (chapters.length === 0) {
        return;
    }

    var links = Array.prototype.slice.call(document.querySelectorAll('[data-help-link]'));
    var chaptersBox = document.getElementById('help-chapters');
    var resultsBox = document.getElementById('help-results');
    var resultsList = document.getElementById('help-results-list');
    var resultsEmpty = document.getElementById('help-results-empty');
    var resultsTitle = document.getElementById('help-results-title');
    var searchInput = document.getElementById('help-search');
    var searchClear = document.getElementById('help-search-clear');
    var searchHint = document.getElementById('help-search-hint');
    var statusEl = document.getElementById('help-status');
    var contentsToggle = document.getElementById('help-contents-toggle');
    var contentsPanel = document.getElementById('help-contents-panel');
    var contentsCurrent = document.getElementById('help-contents-current');
    var topButton = document.getElementById('help-top');
    var seenText = document.getElementById('help-seen-text');
    var seenBar = document.getElementById('help-seen-bar');
    var seenFill = document.getElementById('help-seen-fill');

    var ACTIVE_LINK = ['bg-csc-navy-600/10', 'dark:bg-white/10', 'text-csc-navy-600', 'dark:text-white', 'font-semibold'];
    var ACTIVE_ICON = ['bg-csc-navy-600', 'text-white', 'dark:bg-csc-navy-500', 'dark:text-white'];
    var INACTIVE_ICON = ['bg-gray-100', 'text-gray-500', 'dark:bg-white/5', 'dark:text-gray-400', 'group-hover:text-csc-navy-600', 'dark:group-hover:text-white'];
    var STORAGE_KEY = 'hge_help_seen';

    var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var currentId = null;
    var searching = false;

    // ---- viewed chapters (per browser; the page works without storage) ----
    var seen = {};
    try {
        JSON.parse(window.localStorage.getItem(STORAGE_KEY) || '[]').forEach(function (id) { seen[id] = true; });
    } catch (e) { seen = {}; }

    function saveSeen() {
        try {
            window.localStorage.setItem(STORAGE_KEY, JSON.stringify(Object.keys(seen)));
        } catch (e) { /* storage unavailable: progress simply is not remembered */ }
    }

    function renderSeen() {
        var count = 0;
        links.forEach(function (link) {
            var on = !!seen[link.getAttribute('data-help-link')];
            if (on) { count++; }
            var mark = link.querySelector('[data-help-seen]');
            var text = link.querySelector('[data-help-seen-text]');
            if (mark) { mark.hidden = !on; }
            if (text) { text.hidden = !on; }
        });
        if (seenText) { seenText.textContent = count + ' of ' + chapters.length; }
        if (seenBar) { seenBar.setAttribute('aria-valuenow', String(count)); }
        if (seenFill) { seenFill.style.width = (chapters.length ? Math.round(count / chapters.length * 100) : 0) + '%'; }
    }

    function announce(message) {
        if (statusEl) { statusEl.textContent = message; }
    }

    function scrollToEl(el) {
        el.scrollIntoView({ behavior: reduceMotion ? 'auto' : 'smooth', block: 'start' });
    }

    function flash(el) {
        el.classList.add('ring-2', 'ring-csc-navy-500/60', 'rounded-lg');
        window.setTimeout(function () {
            el.classList.remove('ring-2', 'ring-csc-navy-500/60', 'rounded-lg');
        }, 1800);
    }

    // ---- chapters ----
    function showChapter(id, userInitiated) {
        var chapter = document.getElementById(id);
        if (!chapter || chapters.indexOf(chapter) === -1) {
            return null;
        }
        var changed = currentId !== id;
        currentId = id;

        chapters.forEach(function (c) { c.hidden = c !== chapter; });

        links.forEach(function (link) {
            var on = link.getAttribute('data-help-link') === id;
            ACTIVE_LINK.forEach(function (cls) { link.classList.toggle(cls, on); });
            var icon = link.querySelector('[data-help-icon]');
            if (icon) {
                ACTIVE_ICON.forEach(function (cls) { icon.classList.toggle(cls, on); });
                INACTIVE_ICON.forEach(function (cls) { icon.classList.toggle(cls, !on); });
            }
            if (on) {
                link.setAttribute('aria-current', 'page');
                // The chapter being read must not sit inside a collapsed group.
                var group = link.closest('[data-help-group]');
                if (group) { setGroupOpen(group, true); }
            } else {
                link.removeAttribute('aria-current');
            }
        });

        var title = chapter.getAttribute('data-help-title') || '';
        var number = chapter.getAttribute('data-help-number');
        if (contentsCurrent) { contentsCurrent.textContent = 'Chapter ' + number; }
        if (changed) {
            announce('Chapter ' + number + ' of ' + chapters.length + ': ' + title);
        }

        seen[id] = true;
        saveSeen();
        renderSeen();

        if (userInitiated) {
            var heading = document.getElementById(id + '-title');
            if (heading) { heading.focus({ preventScroll: true }); }
        }
        return chapter;
    }

    function chapterOf(el) {
        return el ? el.closest('[data-help-chapter]') : null;
    }

    // Opens whatever the address points at: a chapter, or a step / note inside one.
    function route(userInitiated) {
        var id = '';
        try { id = decodeURIComponent((window.location.hash || '').replace(/^#/, '')); } catch (e) { id = ''; }
        var target = id ? document.getElementById(id) : null;
        var chapter = chapterOf(target);

        if (!chapter) {
            chapter = chapters[0];
            target = null;
        }
        showChapter(chapter.id, userInitiated);

        if (target && target !== chapter) {
            scrollToEl(target);
            flash(target);
        } else if (userInitiated) {
            window.scrollTo({ top: 0, behavior: reduceMotion ? 'auto' : 'smooth' });
        }
    }

    // ---- contents: collapsible groups and the small-screen toggle ----
    function setGroupOpen(group, open) {
        var button = group.querySelector('[data-help-group-toggle]');
        var list = group.querySelector('ul');
        var chevron = button ? button.querySelector('[data-help-chevron]') : null;
        if (!button || !list) { return; }
        button.setAttribute('aria-expanded', open ? 'true' : 'false');
        list.hidden = !open;
        if (chevron) { chevron.style.transform = open ? '' : 'rotate(-90deg)'; }
    }

    Array.prototype.forEach.call(document.querySelectorAll('[data-help-group]'), function (group) {
        var button = group.querySelector('[data-help-group-toggle]');
        if (button) {
            button.addEventListener('click', function () {
                setGroupOpen(group, button.getAttribute('aria-expanded') !== 'true');
            });
        }
    });

    function setContentsOpen(open) {
        if (!contentsToggle || !contentsPanel) { return; }
        contentsToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        contentsPanel.classList.toggle('hidden', !open);
        var chevron = contentsToggle.querySelector('[data-help-chevron]');
        if (chevron) { chevron.style.transform = open ? 'rotate(180deg)' : ''; }
    }

    if (contentsToggle) {
        contentsToggle.addEventListener('click', function () {
            setContentsOpen(contentsToggle.getAttribute('aria-expanded') !== 'true');
        });
    }

    // ---- search ----
    // Text as a reader sees it: no screen-reader-only prefixes or decorative numbers,
    // and a space between the heading and the description of a step.
    function plainText(el) {
        var copy = el.cloneNode(true);
        Array.prototype.forEach.call(copy.querySelectorAll('.sr-only, [aria-hidden="true"]'), function (n) { n.remove(); });
        Array.prototype.forEach.call(copy.querySelectorAll('p, h3'), function (n) { n.appendChild(document.createTextNode(' ')); });
        return copy.textContent.replace(/\s+/g, ' ').trim();
    }

    var index = [];
    Array.prototype.forEach.call(document.querySelectorAll('[data-help-searchable]'), function (el) {
        var chapter = chapterOf(el);
        if (!chapter) { return; }
        var part = el.closest('section[id]');
        index.push({
            el: el,
            chapter: chapter,
            part: part && part.querySelector('h3') ? part.querySelector('h3').textContent.trim() : '',
            text: plainText(el)
        });
    });

    function escapeRegExp(s) {
        return s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    }

    function highlighted(text, words) {
        var frag = document.createDocumentFragment();
        var re = new RegExp('(' + words.map(escapeRegExp).join('|') + ')', 'ig');
        var last = 0;
        var m;
        while ((m = re.exec(text)) !== null) {
            if (m.index > last) { frag.appendChild(document.createTextNode(text.slice(last, m.index))); }
            var mark = document.createElement('mark');
            mark.className = 'rounded bg-yellow-200 px-0.5 text-gray-900 dark:bg-yellow-400/30 dark:text-yellow-100';
            mark.textContent = m[0];
            frag.appendChild(mark);
            last = m.index + m[0].length;
            if (m[0].length === 0) { re.lastIndex++; }
        }
        if (last < text.length) { frag.appendChild(document.createTextNode(text.slice(last))); }
        return frag;
    }

    function snippet(text, words) {
        var lower = text.toLowerCase();
        var at = -1;
        words.forEach(function (w) {
            var i = lower.indexOf(w);
            if (i !== -1 && (at === -1 || i < at)) { at = i; }
        });
        if (text.length <= 180 || at === -1) { return text.length > 180 ? text.slice(0, 177) + '…' : text; }
        var start = Math.max(0, at - 60);
        var piece = text.slice(start, start + 180);
        return (start > 0 ? '…' : '') + piece + (start + 180 < text.length ? '…' : '');
    }

    function runSearch(query) {
        var words = query.toLowerCase().split(/\s+/).filter(Boolean);
        resultsList.textContent = '';

        var matches = index.filter(function (item) {
            var hay = item.text.toLowerCase();
            return words.every(function (w) { return hay.indexOf(w) !== -1; });
        });

        matches.slice(0, 40).forEach(function (item) {
            var li = document.createElement('li');
            var a = document.createElement('a');
            a.href = '#' + (item.el.id || item.chapter.id);
            a.className = 'block rounded-lg px-2 py-3 transition-colors hover:bg-csc-navy-600/5 focus:outline-none focus-visible:ring-2 focus-visible:ring-csc-navy-500/50 dark:hover:bg-white/5';

            var where = document.createElement('span');
            where.className = 'mb-1 block text-xs font-medium text-csc-navy-600 dark:text-csc-navy-300';
            where.textContent = item.chapter.getAttribute('data-help-title') + (item.part && item.part !== item.text ? ' › ' + item.part : '');
            a.appendChild(where);

            var body = document.createElement('span');
            body.className = 'block text-sm leading-relaxed text-gray-700 dark:text-gray-300';
            body.appendChild(highlighted(snippet(item.text, words), words));
            a.appendChild(body);

            li.appendChild(a);
            resultsList.appendChild(li);
        });

        resultsEmpty.hidden = matches.length !== 0;
        var shown = Math.min(matches.length, 40);
        resultsTitle.textContent = matches.length === 0
            ? 'No results'
            : (matches.length > 40 ? 'Showing the first 40 of ' + matches.length + ' results' : matches.length + (matches.length === 1 ? ' result' : ' results'));
        announce(matches.length === 0 ? 'No results for ' + query : shown + ' results for ' + query);
    }

    function setSearching(on) {
        searching = on;
        resultsBox.hidden = !on;
        chaptersBox.hidden = on;
    }

    function clearSearch(focusInput) {
        searchInput.value = '';
        searchClear.hidden = true;
        if (searchHint) { searchHint.classList.add('sm:block'); }
        if (searching) { setSearching(false); }
        if (focusInput) { searchInput.focus(); }
    }

    var searchTimer = null;
    searchInput.addEventListener('input', function () {
        window.clearTimeout(searchTimer);
        searchTimer = window.setTimeout(function () {
            var query = searchInput.value.trim();
            searchClear.hidden = searchInput.value === '';
            if (searchHint) { searchHint.classList.toggle('sm:block', searchInput.value === ''); }
            if (query === '') {
                if (searching) { setSearching(false); }
                return;
            }
            setSearching(true);
            runSearch(query);
        }, 120);
    });

    searchInput.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            clearSearch(false);
        }
    });
    searchClear.addEventListener('click', function () { clearSearch(true); });
    document.getElementById('help-results-close').addEventListener('click', function () { clearSearch(false); });

    // A result (or any contents link) leaves search and opens that place.
    resultsList.addEventListener('click', function (event) {
        var link = event.target.closest('a');
        if (link) {
            // The same place as the address already holds: no hashchange will follow.
            var same = link.getAttribute('href') === window.location.hash;
            window.setTimeout(function () {
                clearSearch(false);
                if (same) { route(true); }
            }, 0);
        }
    });

    document.addEventListener('keydown', function (event) {
        var tag = (event.target && event.target.tagName || '').toLowerCase();
        if (event.key === '/' && !event.ctrlKey && !event.metaKey && !event.altKey && tag !== 'input' && tag !== 'textarea' && tag !== 'select' && !(event.target && event.target.isContentEditable)) {
            event.preventDefault();
            searchInput.focus();
            searchInput.select();
        }
    });

    // ---- navigation: every in-page link goes through the address bar ----
    window.addEventListener('hashchange', function () {
        if (searching) { clearSearch(false); }
        route(true);
        setContentsOpen(false);
    });

    // Clicking the link of the chapter already open changes nothing in the address,
    // so bring the reader back to the top of it by hand.
    links.concat(Array.prototype.slice.call(document.querySelectorAll('[data-help-nav]'))).forEach(function (link) {
        link.addEventListener('click', function () {
            var target = link.getAttribute('href').replace(/^#/, '');
            if (searching) { clearSearch(false); }
            if (target === currentId) {
                route(true);
                setContentsOpen(false);
            }
        });
    });

    // ---- back to top ----
    function updateTop() {
        var hide = (window.pageYOffset || document.documentElement.scrollTop) < 400;
        if (topButton.hidden !== hide) { topButton.hidden = hide; }
    }
    window.addEventListener('scroll', updateTop, { passive: true });
    topButton.addEventListener('click', function () {
        window.scrollTo({ top: 0, behavior: reduceMotion ? 'auto' : 'smooth' });
        var heading = currentId ? document.getElementById(currentId + '-title') : null;
        if (heading) { heading.focus({ preventScroll: true }); }
    });

    // ---- screenshots: click to enlarge ----
    var lightbox = document.getElementById('help-lightbox');
    var lightboxImg = document.getElementById('help-lightbox-img');
    var lightboxCaption = document.getElementById('help-lightbox-caption');
    if (lightbox && typeof lightbox.showModal === 'function') {
        Array.prototype.forEach.call(document.querySelectorAll('[data-help-zoom]'), function (button) {
            button.addEventListener('click', function () {
                // Two versions sit in the button (light and dark); the visible one is the match for the theme.
                var shown = Array.prototype.filter.call(button.querySelectorAll('img'), function (img) { return img.offsetParent !== null; })[0] || button.querySelector('img');
                var figure = button.closest('figure');
                var caption = figure ? figure.querySelector('figcaption') : null;
                lightboxImg.src = shown.currentSrc || shown.src;
                lightboxImg.alt = shown.alt;
                lightboxCaption.textContent = caption ? caption.textContent : shown.alt;
                lightbox.showModal();
                lightbox.scrollTop = 0;
            });
        });
        document.getElementById('help-lightbox-close').addEventListener('click', function () { lightbox.close(); });
        // A click on the dimmed area around the picture closes it too.
        lightbox.addEventListener('click', function (event) {
            if (event.target === lightbox) { lightbox.close(); }
        });
    } else {
        // No <dialog> support: the screenshot opens in its own tab instead.
        Array.prototype.forEach.call(document.querySelectorAll('[data-help-zoom]'), function (button) {
            button.addEventListener('click', function () {
                var shown = Array.prototype.filter.call(button.querySelectorAll('img'), function (img) { return img.offsetParent !== null; })[0] || button.querySelector('img');
                window.open(shown.currentSrc || shown.src, '_blank', 'noopener');
            });
        });
    }
    renderSeen();
    route(false);
    updateTop();
});
