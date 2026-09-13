document.addEventListener('DOMContentLoaded', function () {
    var config = window.HGE_VIEW_UPLOAD;
    var btn = document.getElementById('extract-records-btn');
    if (!config || !btn) {
        return;
    }

    btn.addEventListener('click', function () {
        var formData = new FormData();
        formData.set('csrf_token', config.csrfToken);
        formData.set('upload_id', btn.dataset.uploadId);

        fetch(config.apiBase + '/api/extract_pdf.php', {
            method: 'POST',
            body: formData,
        })
            .then(function (response) {
                return response.json();
            })
            .then(function (data) {
                if (data.status === 'success') {
                    window.location.href = data.redirect_url;
                } else {
                    showToast('error', data.message);
                }
            })
            .catch(function () {
                showToast('error', 'EXTRACTION COULD NOT BE STARTED. PLEASE TRY AGAIN.');
            });
    });
});
