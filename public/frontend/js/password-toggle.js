// Show/hide buttons for every password field on the page.
// Fields that already have a .toggle-password button (login and registration) get that
// button wired up; any other password field gets a button added next to it.
(() => {
  const EYE = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2.5 12s3.3-6 9.5-6 9.5 6 9.5 6-3.3 6-9.5 6-9.5-6-9.5-6z"></path><circle cx="12" cy="12" r="2.7"></circle></svg>';
  const EYE_OFF = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2.5 12s3.3-6 9.5-6 9.5 6 9.5 6-3.3 6-9.5 6-9.5-6-9.5-6z"></path><circle cx="12" cy="12" r="2.7"></circle><path d="M4 4l16 16"></path></svg>';

  function setVisible(input, button, visible) {
    input.type = visible ? 'text' : 'password';
    button.innerHTML = visible ? EYE_OFF : EYE;
    button.setAttribute('aria-label', visible ? 'Hide password' : 'Show password');
    button.setAttribute('aria-pressed', String(visible));
  }

  document.querySelectorAll('input[type="password"]').forEach(input => {
    let button = input.parentElement.querySelector('.toggle-password');
    if (!button) {
      const wrapper = document.createElement('span');
      wrapper.className = 'password-field';
      input.parentNode.insertBefore(wrapper, input);
      wrapper.appendChild(input);
      button = document.createElement('button');
      button.type = 'button';
      button.className = 'password-toggle';
      wrapper.appendChild(button);
    }
    setVisible(input, button, false);
    button.addEventListener('click', () => setVisible(input, button, input.type === 'password'));
    // Hide the password again whenever its form is cleared (for example after a successful change).
    input.form?.addEventListener('reset', () => setVisible(input, button, false));
  });
})();
