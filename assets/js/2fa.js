// assets/js/2fa.js - 2FA specific behaviors (can be expanded)

document.addEventListener('DOMContentLoaded', function() {
    const codeInput = document.querySelector('input[name="code"]');
    if (codeInput) {
        // Auto-focus and select on load
        codeInput.focus();
        codeInput.select();

        // Allow only numbers
        codeInput.addEventListener('input', function() {
            this.value = this.value.replace(/[^0-9]/g, '').slice(0, 6);
        });
    }
});