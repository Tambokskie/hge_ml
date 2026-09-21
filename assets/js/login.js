document.addEventListener('DOMContentLoaded', function () {
    var toggleBtn = document.getElementById('toggle-password');
    var passwordInput = document.getElementById('password');
    var eyeOpenIcon = document.getElementById('eye-open-icon');
    var eyeClosedIcon = document.getElementById('eye-closed-icon');

    if (toggleBtn && passwordInput) {
        toggleBtn.addEventListener('click', function () {
            var willShow = passwordInput.type === 'password';
            passwordInput.type = willShow ? 'text' : 'password';
            toggleBtn.setAttribute('aria-pressed', willShow ? 'true' : 'false');
            toggleBtn.setAttribute('aria-label', willShow ? 'Hide password' : 'Show password');
            if (eyeOpenIcon && eyeClosedIcon) {
                eyeOpenIcon.classList.toggle('hidden', willShow);
                eyeClosedIcon.classList.toggle('hidden', !willShow);
            }
        });
    }

    var forgotBtn = document.getElementById('forgot-password-btn');
    if (forgotBtn) {
        forgotBtn.addEventListener('click', function () {
            showToast('info', 'PLEASE CONTACT YOUR SYSTEM ADMINISTRATOR TO RESET YOUR PASSWORD.');
        });
    }

    var form = document.querySelector('form');
    var submitBtn = form ? form.querySelector('button[type="submit"]') : null;
    if (form && submitBtn) {
        form.addEventListener('submit', function () {
            submitBtn.disabled = true;
            submitBtn.textContent = 'SIGNING IN...';
        });
    }
});
