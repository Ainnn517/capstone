<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Reset Password - Barangay Reporting System</title>
    <link rel="stylesheet" href="/frontend/css/style.css">
</head>
<body>
    <main class="login-page">
        <section class="login-card">
            <h1>Reset your password</h1>
            <form id="passwordResetForm" data-token="{{ request()->route('token') }}">
                <label for="resetEmail">Email address:</label>
                <input type="email" id="resetEmail" value="{{ request('email') }}" required autocomplete="email">
                <label for="resetPassword">New password:</label>
                <input type="password" id="resetPassword" minlength="12" required autocomplete="new-password">
                <label for="resetPasswordConfirmation">Confirm new password:</label>
                <input type="password" id="resetPasswordConfirmation" minlength="12" required autocomplete="new-password">
                <button type="submit" class="login-button">Save new password</button>
                <p id="passwordResetMessage" class="form-message" role="status" aria-live="polite"></p>
            </form>
            <a href="/frontend/pages/index.html">Return to sign in</a>
        </section>
    </main>
    <script src="/frontend/js/password-reset.js" nonce="{{ app('csp.nonce') }}"></script>
</body>
</html>
