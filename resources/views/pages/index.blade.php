<!-- Login and registration page. -->
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Barangay Issue Reporting System</title>
    <link rel="stylesheet" href="/frontend/css/style.css">
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

            <form id="registerForm" hidden>
                <label for="registerName">Full name:</label>
                <div class="input-wrap">
                    <input type="text" id="registerName" autocomplete="name" required>
                </div>

                <label for="registerEmail">Email:</label>
                <div class="input-wrap">
                    <input type="email" id="registerEmail" autocomplete="email" required>
                </div>

                <label for="registerUsername">Username:</label>
                <div class="input-wrap">
                    <input type="text" id="registerUsername" autocomplete="username" required>
                </div>

                <label for="registerPassword">Password:</label>
                <div class="input-wrap">
                    <input type="password" id="registerPassword" autocomplete="new-password" minlength="8" required>
                    <button type="button" class="toggle-password" id="toggleRegisterPassword" aria-label="Show password">
                        <svg viewBox="0 0 24 24" aria-hidden="true">
                            <path d="M2.5 12s3.3-6 9.5-6 9.5 6 9.5 6-3.3 6-9.5 6-9.5-6-9.5-6z"></path>
                            <circle cx="12" cy="12" r="2.7"></circle>
                        </svg>
                    </button>
                </div>

                <label for="confirmPassword">Confirm password:</label>
                <div class="input-wrap">
                    <input type="password" id="confirmPassword" autocomplete="new-password" minlength="8" required>
                    <button type="button" class="toggle-password" id="toggleConfirmPassword" aria-label="Show password">
                        <svg viewBox="0 0 24 24" aria-hidden="true">
                            <path d="M2.5 12s3.3-6 9.5-6 9.5 6 9.5 6-3.3 6-9.5 6-9.5-6-9.5-6z"></path>
                            <circle cx="12" cy="12" r="2.7"></circle>
                        </svg>
                    </button>
                </div>

                <button type="submit" class="login-button">Create account</button>
                <p id="registerMessage" class="form-message" aria-live="polite"></p>
            </form>

            <button type="button" class="form-switch" id="formSwitch" aria-controls="registerForm">Create an account</button>

            <p class="card-footer">
                Your data, your community.<br>
                A safer, stronger barangay.<br>
                <a href="{{ route('privacy', [], false) }}">Privacy notice</a>
            </p>
        </section>

    </main>

    <script src="/frontend/js/index.js"></script>
</body>
</html>
