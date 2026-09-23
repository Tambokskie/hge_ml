function showModal(config) {
    var prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var hasFormHeader = !!config.subtitle;

    var overlay = document.createElement('div');
    overlay.className = 'fixed inset-0 z-40 flex items-center justify-center bg-black/60 px-4';

    var box = document.createElement('div');
    box.className = 'w-full max-w-md rounded-xl border border-gray-200 dark:border-white/10 bg-white dark:bg-surface-900 overflow-hidden'
        + (hasFormHeader ? '' : (config.icon ? ' text-center p-6' : ' p-6'));

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

    if (hasFormHeader) {
        var header = document.createElement('div');
        header.className = 'flex items-start gap-4 border-b border-gray-100 dark:border-white/10 px-6 pt-6 pb-5';

        if (config.icon) {
            var headerIconWrap = document.createElement('div');
            headerIconWrap.className = 'flex h-14 w-14 shrink-0 items-center justify-center rounded-full '
                + (config.iconClassName || 'bg-csc-navy-600/10 text-csc-navy-600 dark:text-csc-navy-300');
            headerIconWrap.innerHTML = config.icon;
            header.appendChild(headerIconWrap);
        }

        var titleWrap = document.createElement('div');
        titleWrap.className = 'min-w-0 flex-1 pt-1';

        var formHeading = document.createElement('h2');
        formHeading.className = 'text-lg font-bold text-csc-navy-600 dark:text-white';
        formHeading.textContent = config.title;
        titleWrap.appendChild(formHeading);

        var subtitleEl = document.createElement('p');
        subtitleEl.className = 'mt-1 text-sm text-gray-500 dark:text-gray-400';
        subtitleEl.textContent = config.subtitle;
        titleWrap.appendChild(subtitleEl);

        header.appendChild(titleWrap);

        var closeBtn = document.createElement('button');
        closeBtn.type = 'button';
        closeBtn.setAttribute('aria-label', 'Close');
        closeBtn.className = 'shrink-0 -mr-1 -mt-1 rounded-lg p-1.5 text-gray-400 hover:bg-gray-100 hover:text-gray-600 dark:hover:bg-white/5 dark:hover:text-gray-200';
        closeBtn.innerHTML = '<svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>';
        closeBtn.addEventListener('click', function () { close(); });
        header.appendChild(closeBtn);

        box.appendChild(header);
    } else if (config.icon) {
        var iconWrap = document.createElement('div');
        iconWrap.className = 'mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-full '
            + (config.iconClassName || 'bg-csc-navy-600/10 text-csc-navy-600 dark:text-csc-navy-300');
        iconWrap.innerHTML = config.icon;
        box.appendChild(iconWrap);

        var heading = document.createElement('h2');
        heading.className = 'text-base font-semibold text-gray-900 dark:text-white mb-2';
        heading.textContent = config.title;
        box.appendChild(heading);
    } else {
        var plainHeading = document.createElement('h2');
        plainHeading.className = 'text-base font-semibold text-gray-900 dark:text-white mb-3';
        plainHeading.textContent = config.title;
        box.appendChild(plainHeading);
    }

    var body = document.createElement('div');
    body.className = hasFormHeader
        ? 'px-6 py-5 text-sm text-gray-600 dark:text-gray-400'
        : (config.icon ? 'text-sm text-gray-600 dark:text-gray-400 mb-6' : 'text-sm text-gray-700 dark:text-gray-300 mb-5');
    body.innerHTML = config.bodyHtml;
    box.appendChild(body);

    var buttonRow = document.createElement('div');
    buttonRow.className = hasFormHeader
        ? 'flex items-center justify-end gap-3 border-t border-gray-100 dark:border-white/10 bg-gray-50/60 px-6 py-4 dark:bg-white/[0.02]'
        : (config.icon ? 'flex gap-3' : 'flex justify-end gap-2');

    (config.buttons || []).forEach(function (btn) {
        var el = document.createElement('button');
        el.type = 'button';
        el.className = btn.className || 'rounded-lg px-4 py-2 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-white/5';
        if (btn.icon) {
            el.innerHTML = btn.icon + '<span>' + escapeHtml(btn.label) + '</span>';
        } else {
            el.textContent = btn.label;
        }
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
