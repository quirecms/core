// "Show" beside a password field (Quire's account pages): a button that only this script reveals, so
// without JavaScript the field is a plain password field. It says what it does next ("Show", "Hide"),
// in its text and its name, and the field is a password field again before its form is sent, so a
// browser never keeps or offers it as plain text.
for (const button of document.querySelectorAll('button[data-quire-password-toggle]')) {
    const field = document.getElementById(button.getAttribute('aria-controls') ?? '');

    if (!(field instanceof HTMLInputElement) || 'password' !== field.type) {
        continue;
    }

    const status = button.parentElement?.querySelector('[data-quire-password-status]');
    const say = (shown) => {
        button.textContent = (shown ? button.dataset.hide : button.dataset.show) ?? '';
        button.setAttribute('aria-label', (shown ? button.dataset.hideLabel : button.dataset.showLabel) ?? '');
        button.toggleAttribute('data-showing', shown);
    };

    button.hidden = false;
    button.addEventListener('click', () => {
        const shown = 'password' === field.type;
        field.type = shown ? 'text' : 'password';
        say(shown);
        if (status) {
            status.textContent = (shown ? status.dataset.shown : status.dataset.hidden) ?? '';
        }
        field.focus();
    });
    field.form?.addEventListener('submit', () => {
        field.type = 'password';
        say(false);
    });
}
