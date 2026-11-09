
const passwordInput = document.getElementById("password");
const togglePassword = document.getElementById("togglePassword");
const registerPasswordInput = document.getElementById("registerPassword");
const toggleRegisterPassword = document.getElementById("toggleRegisterPassword");
const confirmPasswordInput = document.getElementById("confirmPassword");
const toggleConfirmPassword = document.getElementById("toggleConfirmPassword");
const loginForm = document.getElementById("loginForm");
const formMessage = document.getElementById("formMessage");
const registerForm = document.getElementById("registerForm");
const registerMessage = document.getElementById("registerMessage");
const formSwitch = document.getElementById("formSwitch");
const formTitle = document.getElementById("formTitle");
// API routes used by the login and registration forms.
const API_ENDPOINTS = {
    login: "/api/login",
    register: "/api/register"
};

// Send an authentication request and return its response.
async function apiRequest(action, payload) {
    const response = await fetch(API_ENDPOINTS[action], {
        method: "POST",
        credentials: "same-origin",
        headers: {
            "Accept": "application/json",
            "Content-Type": "application/json",
            "X-CSRF-TOKEN": document.querySelector('meta[name="csrf-token"]').content
        },
        body: JSON.stringify(payload)
    });
    if (!(response.headers.get("content-type") || "").includes("application/json")) {
        throw new Error("The Laravel API did not respond. Start the app with Artisan and open the Laravel server URL.");
    }

    let data;
    try {
        data = await response.json();
    } catch {
        throw new Error("The Laravel API returned an empty or invalid response. Check the Laravel server output and database configuration.");
    }
    if (!response.ok || !data.success) {
        const error = new Error(data.message || "Request failed.");
        error.emailStatus = data.emailStatus;
        throw error;
    }
    return data;
}

function emailDeliveryNotice(status) {
    if (status === "sent") return " An update was sent to your email.";
    if (status === "not_configured") return " Email delivery is not configured, so no email was sent.";
    if (status === "failed") return " We could not deliver the email; please contact the barangay office.";
    return "";
}

// Open the correct portal after a successful login or registration.
function enterPortal(user) {
    localStorage.setItem("birResidentName", user.fullName);
    window.location.href = user.role === "admin" ? "admin-dashboard.html" : "resident-portal.html";
}

// Toggle password visibility and keep each control accessible.
function bindPasswordToggle(input, button) {
    button.addEventListener("click", () => {
        const isPasswordHidden = input.type === "password";
        input.type = isPasswordHidden ? "text" : "password";
        button.setAttribute("aria-label", isPasswordHidden ? "Hide password" : "Show password");
    });
}

bindPasswordToggle(passwordInput, togglePassword);
bindPasswordToggle(registerPasswordInput, toggleRegisterPassword);
bindPasswordToggle(confirmPasswordInput, toggleConfirmPassword);

// Handle the login form
loginForm.addEventListener("submit", async (event) => {
    event.preventDefault();

    const username = document.getElementById("username").value.trim();
    const password = passwordInput.value;

    if (!username || !password) {
        formMessage.textContent =
            "Please enter your username and password.";
        return;
    }

    formMessage.textContent = "Signing in...";
    try {
        const result = await apiRequest("login", { username, password });
        enterPortal(result.user);
    } catch (error) {
        formMessage.textContent = error.message;
    }
});

// Switch between the login and registration forms.
formSwitch.addEventListener("click", () => {
    const showingRegistration = registerForm.hidden;
    loginForm.hidden = showingRegistration;
    registerForm.hidden = !showingRegistration;
    formTitle.textContent = showingRegistration ? "Create an account" : "Log in";
    formSwitch.textContent = showingRegistration ? "Back to log in" : "Create an account";
    formSwitch.setAttribute("aria-controls", showingRegistration ? "loginForm" : "registerForm");
    formMessage.textContent = "";
    registerMessage.textContent = "";
});

// Check the registration form and create the new account.
registerForm.addEventListener("submit", async (event) => {
    event.preventDefault();

    const password = document.getElementById("registerPassword").value;
    const confirmPassword = document.getElementById("confirmPassword").value;

    if (password !== confirmPassword) {
        registerMessage.textContent = "Passwords do not match.";
        return;
    }

    registerMessage.textContent = "Creating your account...";
    try {
        const username = document.getElementById("registerUsername").value.trim();
        const result = await apiRequest("register", {
            fullName: document.getElementById("registerName").value.trim(),
            email: document.getElementById("registerEmail").value.trim(),
            username,
            password
        });
        registerForm.reset();
        loginForm.hidden = false;
        registerForm.hidden = true;
        formTitle.textContent = "Log in";
        formSwitch.textContent = "Create an account";
        formSwitch.setAttribute("aria-controls", "registerForm");
        document.getElementById("username").value = username;
        formMessage.textContent = result.message + emailDeliveryNotice(result.emailStatus);
    } catch (error) {
        registerMessage.textContent = error.message + emailDeliveryNotice(error.emailStatus);
    }
});
