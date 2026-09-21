document.addEventListener('DOMContentLoaded', function () {
    var toggle = document.getElementById('sidebar-toggle');
    var sidebar = document.getElementById('sidebar');
    var overlay = document.getElementById('sidebar-overlay');

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

        content.style.overflow = 'hidden';
        content.style.transition = 'height 200ms ease, opacity 200ms ease';
        if (!details.open) {
            content.style.height = '0px';
            content.style.opacity = '0';
        }

        summary.addEventListener('click', function (event) {
            event.preventDefault();

            if (details.open) {
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
                content.style.height = '0px';
                content.style.opacity = '0';
                requestAnimationFrame(function () {
                    content.style.height = content.scrollHeight + 'px';
                    content.style.opacity = '1';
                });
                content.addEventListener('transitionend', function expand() {
                    content.style.height = 'auto';
                    content.removeEventListener('transitionend', expand);
                }, { once: true });
            }
        });
    });
});
