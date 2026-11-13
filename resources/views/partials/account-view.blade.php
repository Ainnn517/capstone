{{-- Account section shared by the resident and admin pages: profile details and password change. --}}
<section id="accountView" class="{{ $viewClass }}" aria-labelledby="accountTitle" hidden>
  <div class="mb-4">
    <h1 id="accountTitle" class="section-title mb-1">Account</h1>
    <p class="text-secondary mb-0">Your sign-in details and password.</p>
  </div>

  <div class="card p-4 mb-4">
    <h2 class="account-heading">Profile</h2>
    <dl class="account-details">
      <dt>Full name</dt><dd id="accountFullName">—</dd>
      <dt>Username</dt><dd id="accountUsername">—</dd>
      <dt>Email</dt><dd id="accountEmail">—</dd>
      <dt>Role</dt><dd id="accountRole">—</dd>
    </dl>
    <p class="text-secondary small mb-0">To change your name or email, contact the barangay office.</p>
  </div>

  <div class="card p-4">
    <h2 class="account-heading">Change password</h2>
    <form id="changePasswordForm" class="account-form">
      <label class="form-label" for="currentPassword">Current password</label>
      <input id="currentPassword" class="form-control" type="password" autocomplete="current-password" required>
      <label class="form-label" for="newPassword">New password</label>
      <input id="newPassword" class="form-control" type="password" minlength="12" autocomplete="new-password" required aria-describedby="passwordRules">
      <small id="passwordRules" class="text-secondary">At least 12 characters, with uppercase and lowercase letters, a number, and a symbol.</small>
      <label class="form-label" for="newPasswordConfirmation">Confirm new password</label>
      <input id="newPasswordConfirmation" class="form-control" type="password" minlength="12" autocomplete="new-password" required>
      <p class="text-secondary small mb-0">For your security, we will email a 6-digit code to confirm the change. Your other signed-in devices will be signed out afterwards.</p>
      <button id="sendPasswordCode" class="btn btn-primary" type="submit">Send verification code</button>
    </form>

    <form id="confirmPasswordForm" class="account-form account-code-step" hidden>
      <label class="form-label" for="passwordCode">Verification code</label>
      <input id="passwordCode" class="form-control code-input" inputmode="numeric" autocomplete="one-time-code" maxlength="6" pattern="[0-9]{6}" placeholder="000000" required>
      <div class="account-code-actions">
        <button class="btn btn-primary" type="submit">Confirm change</button>
        <button id="resendPasswordCode" class="btn btn-light" type="button">Send a new code</button>
        <button id="cancelPasswordChange" class="btn btn-light" type="button">Cancel</button>
      </div>
    </form>
    <p id="changePasswordMessage" class="account-message" role="status" aria-live="polite"></p>
  </div>
</section>
