// Account section shared by the resident and admin pages: profile details and password change.

// Called by each page once it knows who is signed in.
window.fillAccountDetails = user => {
  const set = (id, value) => { document.getElementById(id).textContent = value || '—'; };
  set('accountFullName', user.fullName);
  set('accountUsername', user.username);
  set('accountEmail', user.email);
  set('accountRole', user.role === 'admin' ? 'Administrator' : 'Resident');
};

// Password change happens in two steps:
//   1. current + new password  -> the server emails a 6-digit code (password not changed yet)
//   2. code                    -> the server applies the new password
const changePasswordForm = document.getElementById('changePasswordForm');
const confirmPasswordForm = document.getElementById('confirmPasswordForm');
const passwordMessage = document.getElementById('changePasswordMessage');
const passwordInputs = ['currentPassword', 'newPassword', 'newPasswordConfirmation'].map(id => document.getElementById(id));

function showPasswordMessage(text, kind = '') {
  passwordMessage.className = `account-message${kind ? ` is-${kind}` : ''}`;
  passwordMessage.textContent = text;
}

async function postJson(url, body) {
  const response = await fetch(url, {
    method: 'POST',
    credentials: 'same-origin',
    headers: {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
    },
    body: JSON.stringify(body)
  });
  window.handleExpiredSession(response);
  const result = await response.json();
  if (!response.ok || !result.success) throw new Error(result.message || 'The request could not be completed.');
  return result;
}

// Lock the password fields while waiting for the code, so what gets confirmed is what was sent.
function setWaitingForCode(waiting) {
  passwordInputs.forEach(input => { input.disabled = waiting; });
  document.getElementById('sendPasswordCode').hidden = waiting;
  confirmPasswordForm.hidden = !waiting;
  if (!waiting) confirmPasswordForm.reset();
}

async function requestPasswordCode() {
  showPasswordMessage('Sending a verification code to your email…');
  try {
    const result = await postJson('/api/password/change', {
      currentPassword: passwordInputs[0].value,
      password: passwordInputs[1].value,
      password_confirmation: passwordInputs[2].value
    });
    setWaitingForCode(true);
    showPasswordMessage(result.message, 'success');
    document.getElementById('passwordCode').focus();
  } catch (error) {
    showPasswordMessage(error.message || 'The verification code could not be sent.', 'error');
  }
}

changePasswordForm.addEventListener('submit', event => {
  event.preventDefault();
  requestPasswordCode();
});

confirmPasswordForm.addEventListener('submit', async event => {
  event.preventDefault();
  showPasswordMessage('Confirming…');
  try {
    const result = await postJson('/api/password/change/confirm', {
      code: document.getElementById('passwordCode').value.trim()
    });
    setWaitingForCode(false);
    changePasswordForm.reset();
    showPasswordMessage(result.message, 'success');
  } catch (error) {
    showPasswordMessage(error.message || 'The code could not be confirmed.', 'error');
    // After too many wrong codes or expiry the server cancels the change: start over.
    if (/start the password change again/i.test(error.message || '')) setWaitingForCode(false);
  }
});

document.getElementById('resendPasswordCode').addEventListener('click', requestPasswordCode);
document.getElementById('cancelPasswordChange').addEventListener('click', () => {
  setWaitingForCode(false);
  showPasswordMessage('Password change cancelled. Your password was not changed.');
});
