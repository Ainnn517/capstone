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
