document.addEventListener('DOMContentLoaded', () => {
    const tabs = document.querySelectorAll('.auth-tab');

    tabs.forEach(tab => {
        tab.addEventListener('click', () => {
            tabs.forEach(t => {
                t.classList.remove('active');
                t.setAttribute('aria-selected', 'false');
            });
            document.querySelectorAll('.auth-form').forEach(form => {
                form.hidden = true;
            });

            tab.classList.add('active');
            tab.setAttribute('aria-selected', 'true');
            const form = document.getElementById(tab.getAttribute('aria-controls'));
            if (form) form.hidden = false;
        });
    });

    document.querySelectorAll('.password-toggle').forEach(button => {
        button.addEventListener('click', () => {
            const input = document.getElementById(button.dataset.passwordToggle);
            if (!input) return;

            const showPassword = input.type === 'password';
            input.type = showPassword ? 'text' : 'password';
            button.textContent = showPassword ? 'Hide' : 'Show';
            button.setAttribute('aria-label', showPassword ? 'Hide password' : 'Show password');
            button.setAttribute('aria-pressed', String(showPassword));
        });
    });
});
