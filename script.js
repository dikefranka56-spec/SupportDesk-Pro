document.addEventListener('DOMContentLoaded', () => {
    // Confirm before submitting forms that explicitly request confirmation.
    document.querySelectorAll('[data-confirm]').forEach((element) => {
        element.addEventListener('click', (event) => {
            if (!confirm(element.dataset.confirm)) {
                event.preventDefault();
            }
        });
    });

    // Prevent accidental double submissions.
    document.querySelectorAll('form').forEach((form) => {
        form.addEventListener('submit', () => {
            const button = form.querySelector('button[type="submit"], button:not([type])');
            if (button && !button.dataset.noDisable) {
                button.disabled = true;
                button.dataset.originalText = button.textContent;
                button.textContent = 'Please wait...';
            }
        });
    });

    // Automatically focus the first field on login and registration pages.
    const firstInput = document.querySelector('.auth-card input:not([type="hidden"])');
    if (firstInput) firstInput.focus();
});
