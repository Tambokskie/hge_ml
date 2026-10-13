document.addEventListener('DOMContentLoaded', function () {
    var toggle = document.getElementById('sidebar-toggle');
    var sidebar = document.getElementById('sidebar');
    var overlay = document.getElementById('sidebar-overlay');

    // The menu scrolls on its own when the window (or the browser zoom) leaves it too
    // little height; bring the page being viewed into sight so it is never hidden below.
    var activeLink = sidebar ? sidebar.querySelector('nav a.bg-white') : null;
    if (activeLink && typeof activeLink.scrollIntoView === 'function') {
        activeLink.scrollIntoView({ block: 'nearest' });
    }

    if (!toggle || !sidebar || !overlay) {
        return;
    }

    function openSidebar() {
        sidebar.classList.remove('-translate-x-full');
        overlay.classList.remove('hidden');
        toggle.setAttribute('aria-expanded', 'true');
    }

    function closeSidebar() {
        sidebar.classList.add('-translate-x-full');
        overlay.classList.add('hidden');
        toggle.setAttribute('aria-expanded', 'false');
    }

    toggle.addEventListener('click', function () {
        var isOpen = toggle.getAttribute('aria-expanded') === 'true';
        if (isOpen) {
            closeSidebar();
        } else {
            openSidebar();
        }
    });

    overlay.addEventListener('click', closeSidebar);

    var prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    sidebar.querySelectorAll('details').forEach(function (details) {
        var summary = details.querySelector('summary');
        var content = details.querySelector(':scope > div');
        if (!summary || !content || prefersReducedMotion) {
            return;
        }

        var items = content.querySelectorAll(':scope > a');
        var collapseTransition = 'height 200ms ease, opacity 200ms ease';

        content.style.overflow = 'hidden';
        content.style.transition = collapseTransition;
        if (!details.open) {
            content.style.height = '0px';
            content.style.opacity = '0';
        }

        summary.addEventListener('click', function (event) {
            event.preventDefault();

            if (details.open) {
                content.style.transition = collapseTransition;
                content.style.height = content.scrollHeight + 'px';
                requestAnimationFrame(function () {
                    content.style.height = '0px';
                    content.style.opacity = '0';
                });
                content.addEventListener('transitionend', function collapse() {
                    details.open = false;
                    content.removeEventListener('transitionend', collapse);
                }, { once: true });
            } else {
                details.open = true;
                // The panel itself just snaps open (no height/opacity slide);
                // each link instead drops into position one after another via
                // the same "drop-in" keyframe used for action-button icons
                // elsewhere. Remove-reflow-readd restarts the animation on
                // every open, not just the first time.
                content.style.transition = 'none';
                content.style.height = 'auto';
                content.style.opacity = '1';

                items.forEach(function (item, index) {
                    item.classList.remove('motion-safe:animate-drop-in');
                    void item.offsetWidth;
                    item.style.animationDelay = (index * 70) + 'ms';
                    item.classList.add('motion-safe:animate-drop-in');
                });
            }
        });
    });
});
