<!-- Login and registration page. -->
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Barangay Issue Reporting System</title>
    <link rel="stylesheet" href="{{ \App\Support\Asset::url('/frontend/css/style.css') }}">
    <link rel="stylesheet" href="{{ \App\Support\Asset::url('/frontend/css/notifications.css') }}">
</head>
<body>

    <main class="login-page">

        <div class="background-image"></div>
        <div class="background-shade"></div>

        <!-- Left Section -->
        <section class="hero-copy">
            <h1><span class="word report-word">REPORT.</span> <span class="word track-word">TRACK.</span> <span class="word respond-word">RESPOND.</span></h1>
            <p class="intro">
                An intelligent issue reporting and decision-support
                platform for faster, transparent local governance.
            </p>
        </section>

        <!-- Login Card -->
        <section class="login-card">
            <h2 id="formTitle">Log in</h2>
            <form id="loginForm">

                <label for="username">Username:</label>
                <div class="input-wrap">
                    <input type="text" id="username" autocomplete="username" required>
                </div>

                <label for="password">Password:</label>
                <div class="input-wrap">
                    <input type="password" id="password" autocomplete="current-password" required>
                    <button type="button" class="toggle-password" id="togglePassword" aria-label="Show password">
                        <svg viewBox="0 0 24 24" aria-hidden="true">
                            <path d="M2.5 12s3.3-6 9.5-6 9.5 6 9.5 6-3.3 6-9.5 6-9.5-6-9.5-6z"></path>
                            <circle cx="12" cy="12" r="2.7"></circle>
                        </svg>
                    </button>
                </div>

                <button type="submit" class="login-button">
                    <span>Log in</span>
                    <span class="arrow">→</span>
                </button>

                <p id="formMessage" class="form-message"></p>
            </form>

            <form id="twoFactorForm" hidden>
                <p class="two-factor-intro" id="twoFactorIntro"></p>
                <label for="loginCode">Sign-in code:</label>
                <div class="input-wrap">
                    <input type="text" id="loginCode" inputmode="numeric" autocomplete="one-time-code" maxlength="6" pattern="[0-9]{6}" placeholder="000000" required>
                </div>
                <button type="submit" class="login-button">Verify and log in</button>
                <p id="twoFactorMessage" class="form-message" role="status" aria-live="polite"></p>
                <button type="button" class="form-switch" id="twoFactorBack">Back to log in</button>
            </form>

            <form id="registerForm" hidden>
                <div class="register-fields">
                    <div class="field">
                        <label for="registerName">Full name:</label>
                        <div class="input-wrap">
                            <input type="text" id="registerName" autocomplete="name" required>
                        </div>
                    </div>
                    <div class="field">
                        <label for="registerEmail">Email:</label>
                        <div class="input-wrap">
                            <input type="email" id="registerEmail" autocomplete="email" required>
                        </div>
                    </div>
                    <div class="field field-wide">
                        <label for="registerUsername">Username:</label>
                        <div class="input-wrap">
                            <input type="text" id="registerUsername" autocomplete="username" required>
                        </div>
                    </div>
                    <div class="field">
                        <label for="registerPassword">Password:</label>
                        <div class="input-wrap">
                            <input type="password" id="registerPassword" autocomplete="new-password" minlength="12" required>
                            <button type="button" class="toggle-password" id="toggleRegisterPassword" aria-label="Show password">
                                <svg viewBox="0 0 24 24" aria-hidden="true">
                                    <path d="M2.5 12s3.3-6 9.5-6 9.5 6 9.5 6-3.3 6-9.5 6-9.5-6-9.5-6z"></path>
                                    <circle cx="12" cy="12" r="2.7"></circle>
                                </svg>
                            </button>
                        </div>
                    </div>
                    <div class="field">
                        <label for="confirmPassword">Confirm password:</label>
                        <div class="input-wrap">
                            <input type="password" id="confirmPassword" autocomplete="new-password" minlength="12" required>
                            <button type="button" class="toggle-password" id="toggleConfirmPassword" aria-label="Show password">
                                <svg viewBox="0 0 24 24" aria-hidden="true">
                                    <path d="M2.5 12s3.3-6 9.5-6 9.5 6 9.5 6-3.3 6-9.5 6-9.5-6-9.5-6z"></path>
                                    <circle cx="12" cy="12" r="2.7"></circle>
                                </svg>
                            </button>
                        </div>
                    </div>
                </div>

                <label class="privacy-consent">
                    <input type="checkbox" id="privacyConsent" class="check-input" required>
                    <span class="check-box" aria-hidden="true"></span>
                    <span class="check-text">I have read and agree to the privacy notice and consent to the processing of my report and account information.</span>
                </label>

                <button type="submit" class="login-button">Create account</button>
                <p id="registerMessage" class="form-message" aria-live="polite"></p>
            </form>

            <form id="forgotPasswordForm" hidden>
                <label for="forgotEmail">Email address:</label>
                <div class="input-wrap">
                    <input type="email" id="forgotEmail" autocomplete="email" required>
                </div>
                <button type="submit" class="login-button">Send reset link</button>
                <p id="forgotPasswordMessage" class="form-message" aria-live="polite"></p>
                <button type="button" class="form-switch" id="returnToLoginButton">Back to log in</button>
            </form>

            <button type="button" class="form-switch" id="forgotPasswordButton">Forgot password?</button>
            <button type="button" class="form-switch" id="formSwitch" aria-controls="registerForm">Create an account</button>

            <p class="card-footer">
                Your data, your community.<br>
                A safer, stronger barangay.<br>
                <a href="{{ route('privacy', [], false) }}">Privacy notice</a>
            </p>
        </section>

    </main>

    <script src="{{ \App\Support\Asset::url('/frontend/js/notifications.js') }}" nonce="{{ app('csp.nonce') }}"></script>
    <script src="{{ \App\Support\Asset::url('/frontend/js/password-toggle.js') }}" nonce="{{ app('csp.nonce') }}"></script>
    <script src="{{ \App\Support\Asset::url('/frontend/js/index.js') }}" nonce="{{ app('csp.nonce') }}"></script>
</body>
</html>
