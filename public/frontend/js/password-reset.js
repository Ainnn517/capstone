const resetForm = document.getElementById('passwordResetForm');
const resetMessage = document.getElementById('passwordResetMessage');

resetForm.addEventListener('submit', async event => {
  event.preventDefault();
  resetMessage.textContent = 'Saving your new password…';

  try {
    const response = await fetch('/api/password/reset', {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
      },
      body: JSON.stringify({
        token: resetForm.dataset.token,
        email: document.getElementById('resetEmail').value.trim(),
        password: document.getElementById('resetPassword').value,
        password_confirmation: document.getElementById('resetPasswordConfirmation').value
      })
    });
    const result = await response.json();
    if (!response.ok || !result.success) throw new Error(result.message || 'The reset link could not be used.');
    resetForm.hidden = true;
    resetMessage.textContent = `${result.message} You can now sign in.`;
  } catch (error) {
    resetMessage.textContent = error.message || 'The reset could not be completed.';
  }
});
