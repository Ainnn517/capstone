// Privacy Notice acknowledgement: once ticked, "Continue" opens the Create account form
// with its consent box already ticked. The form still requires consent on submit.
const privacyAck = document.getElementById('privacyAck');
const privacyContinue = document.getElementById('privacyContinue');

privacyAck.addEventListener('change', () => {
  privacyContinue.disabled = !privacyAck.checked;
});

privacyContinue.addEventListener('click', () => {
  try {
    sessionStorage.setItem('privacyNoticeAccepted', '1');
  } catch {
    // Storage can be unavailable (private mode); the resident just ticks the box on the form.
  }
  window.location.href = '/frontend/pages/index.html?register=1';
});
