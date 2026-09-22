document.addEventListener('DOMContentLoaded', function () {
    var config = window.HGE_VERIFY_IDENTITY;
    var form = document.getElementById('verify-identity-form');
    if (!form || !config) {
        return;
    }

    var submitBtn = document.getElementById('verify-submit-btn');
    var errorEl = document.getElementById('verify-error');

    function showError(message) {
        errorEl.textContent = message;
        errorEl.classList.remove('hidden');
    }

    form.addEventListener('submit', function (event) {
        event.preventDefault();

        errorEl.classList.add('hidden');
        submitBtn.disabled = true;

        var formData = new FormData(form);

        fetch(config.apiBase + '/api/verify_identity.php', {
            method: 'POST',
            body: formData,
        })
            .then(function (response) {
                return response.json();
            })
            .then(function (data) {
                if (data.status === 'success') {
                    window.location.href = data.redirect_url;
                    return;
                }
                submitBtn.disabled = false;
                showError(data.message || 'INVALID ADMINISTRATOR USERNAME OR PASSWORD.');
            })
            .catch(function () {
                submitBtn.disabled = false;
                showError('IDENTITY COULD NOT BE VERIFIED. PLEASE TRY AGAIN.');
            });
    });
});
