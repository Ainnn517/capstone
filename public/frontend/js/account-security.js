const accountSecurityDialog = document.getElementById('accountSecurityDialog');
const changePasswordForm = document.getElementById('changePasswordForm');

document.getElementById('changePasswordButton').addEventListener('click', () => accountSecurityDialog.showModal());
document.getElementById('closeAccountSecurity').addEventListener('click', () => accountSecurityDialog.close());

changePasswordForm.addEventListener('submit', async event => {
  event.preventDefault();
  const message = document.getElementById('changePasswordMessage');
  message.textContent = 'Updating password…';
  try {
    const response = await fetch('/api/password/change', {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
      },
      body: JSON.stringify({
        currentPassword: document.getElementById('currentPassword').value,
        password: document.getElementById('newPassword').value,
        password_confirmation: document.getElementById('newPasswordConfirmation').value
      })
    });
    window.handleExpiredSession(response);
    const result = await response.json();
    if (!response.ok || !result.success) throw new Error(result.message || 'The password could not be changed.');
    changePasswordForm.reset();
    message.textContent = result.message;
  } catch (error) {
    message.textContent = error.message || 'The password could not be changed.';
  }
});
