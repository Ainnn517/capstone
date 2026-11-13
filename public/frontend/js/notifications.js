window.showToast = (message, type = 'info') => {
  let container = document.getElementById('toastContainer');
  if (!container) {
    container = document.createElement('div');
    container.id = 'toastContainer';
    container.className = 'toast-container';
    container.setAttribute('aria-live', 'polite');
    container.setAttribute('aria-relevant', 'additions');
    document.body.appendChild(container);
  }

  const toast = document.createElement('div');
  toast.className = `app-toast app-toast-${type}`;
  toast.setAttribute('role', type === 'error' ? 'alert' : 'status');
  toast.textContent = String(message);
  container.appendChild(toast);
  window.setTimeout(() => toast.remove(), 6000);
};

window.confirmAction = message => new Promise(resolve => {
  const dialog = document.createElement('dialog');
  dialog.className = 'app-confirm-dialog';
  dialog.setAttribute('aria-label', 'Confirm action');
  const text = document.createElement('p');
  text.textContent = String(message);
  const actions = document.createElement('div');
  const cancel = document.createElement('button');
  cancel.type = 'button';
  cancel.textContent = 'Cancel';
  cancel.addEventListener('click', () => dialog.close('cancel'));
  const confirm = document.createElement('button');
  confirm.type = 'button';
  confirm.className = 'app-confirm-button';
  confirm.textContent = 'Confirm';
  confirm.addEventListener('click', () => dialog.close('confirm'));
  actions.append(cancel, confirm);
  dialog.append(text, actions);
  document.body.appendChild(dialog);
  dialog.addEventListener('close', () => {
    const confirmed = dialog.returnValue === 'confirm';
    dialog.remove();
    resolve(confirmed);
  }, { once: true });
  dialog.showModal();
});

// Themed replacement for window.prompt: asks for a short text and resolves with the trimmed
// text, or null when cancelled. With `required`, an empty answer shows an inline message.
window.promptAction = (message, { placeholder = '', maxLength = 255, required = true, confirmLabel = 'OK' } = {}) => new Promise(resolve => {
  const dialog = document.createElement('dialog');
  dialog.className = 'app-confirm-dialog app-prompt-dialog';
  dialog.setAttribute('aria-label', String(message));

  const form = document.createElement('form');
  form.method = 'dialog';
  form.noValidate = true;
  const label = document.createElement('label');
  label.className = 'app-prompt-label';
  label.textContent = String(message);
  const input = document.createElement('textarea');
  input.className = 'app-prompt-input';
  input.rows = 3;
  input.maxLength = maxLength;
  input.placeholder = placeholder;
  label.appendChild(input);
  const error = document.createElement('p');
  error.className = 'app-prompt-error';
  error.setAttribute('role', 'alert');
  error.hidden = true;

  const actions = document.createElement('div');
  const cancel = document.createElement('button');
  cancel.type = 'button';
  cancel.textContent = 'Cancel';
  cancel.addEventListener('click', () => dialog.close('cancel'));
  const confirm = document.createElement('button');
  confirm.type = 'submit';
  confirm.className = 'app-confirm-button';
  confirm.textContent = confirmLabel;
  actions.append(cancel, confirm);
  form.append(label, error, actions);
  dialog.appendChild(form);

  form.addEventListener('submit', event => {
    event.preventDefault();
    if (required && !input.value.trim()) {
      error.textContent = 'Please enter a reason.';
      error.hidden = false;
      input.focus();
      return;
    }
    dialog.close('confirm');
  });
  // Clear the "Please enter a reason." message as soon as the admin starts typing.
  input.addEventListener('input', () => { error.hidden = true; });
  // Enter submits; Shift+Enter adds a new line.
  input.addEventListener('keydown', event => {
    if (event.key === 'Enter' && !event.shiftKey) {
      event.preventDefault();
      form.requestSubmit();
    }
  });

  document.body.appendChild(dialog);
  dialog.addEventListener('close', () => {
    const value = dialog.returnValue === 'confirm' ? input.value.trim() : null;
    dialog.remove();
    resolve(value);
  }, { once: true });
  dialog.showModal();
  input.focus();
});
