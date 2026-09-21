document.addEventListener('DOMContentLoaded', function () {
    var config = window.HGE_IDLE_TIMEOUT;
    if (!config || !config.timeoutMs || typeof showModal !== 'function') {
        return;
    }

    var warningMs = Math.min(60000, Math.floor(config.timeoutMs / 4));
    var pingIntervalMs = Math.max(30000, Math.floor(config.timeoutMs / 10));
    var activityEvents = ['mousemove', 'mousedown', 'keydown', 'scroll', 'touchstart'];
    var idleTimer = null;
    var countdownInterval = null;
    var closeWarning = null;
    var lastReset = 0;
    var lastPing = 0;

    function goToLogout() {
        window.location.href = config.logoutUrl;
    }

    function clearTimers() {
        clearTimeout(idleTimer);
        clearInterval(countdownInterval);
        countdownInterval = null;
    }

    function keepAlive() {
        var formData = new FormData();
        formData.set('csrf_token', config.csrfToken);

        fetch(config.keepAliveUrl, { method: 'POST', body: formData })
            .then(function (response) {
                if (response.redirected || !response.ok) {
                    goToLogout();
                }
            })
            .catch(function () {
                // Network hiccup: keep the client-side timer running rather
                // than forcing a logout the server may not agree with.
            });
    }

    function showWarning() {
        var secondsLeft = Math.round(warningMs / 1000);
        var countdownId = 'idle-timeout-countdown';

        closeWarning = showModal({
            title: 'SESSION EXPIRING SOON',
            bodyHtml: 'YOU HAVE BEEN INACTIVE FOR A WHILE. YOU WILL BE LOGGED OUT IN <span id="' + countdownId + '">' + secondsLeft + '</span> SECONDS.',
            buttons: [
                {
                    label: 'STAY LOGGED IN',
                    className: 'rounded-lg px-4 py-2 text-sm bg-accent-600 hover:bg-accent-500 text-white',
                    onClick: function (close) {
                        close();
                        resetTimers();
                    },
                },
            ],
        });

        countdownInterval = setInterval(function () {
            secondsLeft -= 1;
            var el = document.getElementById(countdownId);
            if (el) {
                el.textContent = secondsLeft;
            }
            if (secondsLeft <= 0) {
                clearInterval(countdownInterval);
                countdownInterval = null;
                if (closeWarning) {
                    closeWarning();
                }
                goToLogout();
            }
        }, 1000);
    }

    function resetTimers() {
        clearTimers();

        var now = Date.now();
        if (now - lastPing >= pingIntervalMs) {
            lastPing = now;
            keepAlive();
        }

        idleTimer = setTimeout(showWarning, config.timeoutMs - warningMs);
    }

    activityEvents.forEach(function (eventName) {
        document.addEventListener(eventName, function () {
            var now = Date.now();
            if (countdownInterval !== null || now - lastReset < 1000) {
                return;
            }
            lastReset = now;
            resetTimers();
        }, { passive: true });
    });

    resetTimers();
});
