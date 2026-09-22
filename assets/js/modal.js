function showModal(config) {
    var prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    var overlay = document.createElement('div');
    overlay.className = 'fixed inset-0 z-40 flex items-center justify-center bg-black/60 px-4';

    var box = document.createElement('div');
    box.className = 'w-full max-w-md rounded-xl border border-gray-200 dark:border-white/10 bg-white dark:bg-surface-900 p-6';

    var heading = document.createElement('h2');
    heading.className = 'text-base font-semibold text-gray-900 dark:text-white mb-3';
    heading.textContent = config.title;
    box.appendChild(heading);

    var body = document.createElement('div');
    body.className = 'text-sm text-gray-700 dark:text-gray-300 mb-5';
    body.innerHTML = config.bodyHtml;
    box.appendChild(body);

    var buttonRow = document.createElement('div');
    buttonRow.className = 'flex justify-end gap-2';

    function close() {
        document.removeEventListener('keydown', onKeydown);

        if (prefersReducedMotion) {
            overlay.remove();
            return;
        }

        overlay.style.opacity = '0';
        box.style.opacity = '0';
        box.style.transform = 'scale(0.95) translateY(8px)';

        overlay.addEventListener('transitionend', function remove(event) {
            if (event.target === overlay) {
                overlay.remove();
            }
        }, { once: true });
    }

    function onKeydown(event) {
        if (event.key === 'Escape') {
            close();
        }
    }

    (config.buttons || []).forEach(function (btn) {
        var el = document.createElement('button');
        el.type = 'button';
        el.className = btn.className || 'rounded-lg px-4 py-2 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-white/5';
        el.textContent = btn.label;
        el.addEventListener('click', function () {
            if (btn.onClick) {
                btn.onClick(close);
            } else {
                close();
            }
        });
        buttonRow.appendChild(el);
    });

    box.appendChild(buttonRow);
    overlay.appendChild(box);
    document.body.appendChild(overlay);
    document.addEventListener('keydown', onKeydown);

    if (!prefersReducedMotion) {
        overlay.style.transition = 'opacity 180ms ease-out';
        overlay.style.opacity = '0';
        box.style.transition = 'opacity 180ms ease-out, transform 180ms ease-out';
        box.style.opacity = '0';
        box.style.transform = 'scale(0.95) translateY(8px)';

        requestAnimationFrame(function () {
            requestAnimationFrame(function () {
                overlay.style.opacity = '1';
                box.style.opacity = '1';
                box.style.transform = 'scale(1) translateY(0)';
            });
        });
    }

    return close;
}
