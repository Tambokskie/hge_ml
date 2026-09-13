function showModal(config) {
    var overlay = document.createElement('div');
    overlay.className = 'fixed inset-0 z-40 flex items-center justify-center bg-black/60 px-4';

    var box = document.createElement('div');
    box.className = 'w-full max-w-md rounded-xl border border-white/10 bg-surface-900 p-6';

    var heading = document.createElement('h2');
    heading.className = 'text-base font-semibold text-white mb-3';
    heading.textContent = config.title;
    box.appendChild(heading);

    var body = document.createElement('div');
    body.className = 'text-sm text-gray-300 mb-5';
    body.innerHTML = config.bodyHtml;
    box.appendChild(body);

    var buttonRow = document.createElement('div');
    buttonRow.className = 'flex justify-end gap-2';

    function close() {
        overlay.remove();
        document.removeEventListener('keydown', onKeydown);
    }

    function onKeydown(event) {
        if (event.key === 'Escape') {
            close();
        }
    }

    (config.buttons || []).forEach(function (btn) {
        var el = document.createElement('button');
        el.type = 'button';
        el.className = btn.className || 'rounded-lg px-4 py-2 text-sm text-gray-300 hover:bg-white/5';
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

    return close;
}
