// "Show" beside a password field (Quire's account pages): a button that only this script reveals, so
// without JavaScript the field is a plain password field. It says whether the password shows with
// aria-pressed, and the field is a password field again before its form is sent, so a browser never
// keeps or offers it as plain text.
for (const button of document.querySelectorAll('button[data-quire-password-toggle]')) {
    const field = document.getElementById(button.getAttribute('aria-controls') ?? '');

    if (!(field instanceof HTMLInputElement) || 'password' !== field.type) {
        continue;
    }

    button.hidden = false;
    button.addEventListener('click', () => {
        const showing = 'text' === field.type;
        field.type = showing ? 'password' : 'text';
        button.setAttribute('aria-pressed', showing ? 'false' : 'true');
        field.focus();
    });
    field.form?.addEventListener('submit', () => {
        field.type = 'password';
        button.setAttribute('aria-pressed', 'false');
    });
}
