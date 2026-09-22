<script src="<?= e(asset_url('assets/js/utils.js')) ?>"></script>
<script src="<?= e(asset_url('assets/js/sidebar.js')) ?>"></script>
<script src="<?= e(asset_url('assets/js/toast.js')) ?>"></script>
<script src="<?= e(asset_url('assets/js/modal.js')) ?>"></script>
<script src="<?= e(asset_url('assets/js/pdf_drawer.js')) ?>"></script>
<script src="<?= e(asset_url('assets/js/ocr_tools.js')) ?>"></script>
<script src="<?= e(asset_url('assets/js/theme.js')) ?>"></script>
<script>
window.HGE_IDLE_TIMEOUT = {
    timeoutMs: <?= json_encode(SESSION_IDLE_TIMEOUT * 1000) ?>,
    keepAliveUrl: <?= json_encode(APP_BASE_URL . '/api/keep_alive.php') ?>,
    logoutUrl: <?= json_encode(APP_BASE_URL . '/logout.php?reason=idle') ?>,
    csrfToken: <?= json_encode(csrf_token()) ?>
};
</script>
<script src="<?= e(asset_url('assets/js/idle_timeout.js')) ?>"></script>
</body>
</html>
