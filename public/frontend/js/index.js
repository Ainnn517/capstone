
const passwordInput = document.getElementById("password");
const registerPasswordInput = document.getElementById("registerPassword");
const confirmPasswordInput = document.getElementById("confirmPassword");
const loginForm = document.getElementById("loginForm");
const formMessage = document.getElementById("formMessage");
const registerForm = document.getElementById("registerForm");
const registerMessage = document.getElementById("registerMessage");
const formSwitch = document.getElementById("formSwitch");
const formTitle = document.getElementById("formTitle");
const forgotPasswordForm = document.getElementById("forgotPasswordForm");
const forgotPasswordButton = document.getElementById("forgotPasswordButton");
const returnToLoginButton = document.getElementById("returnToLoginButton");
// API routes used by the login and registration forms.
const API_ENDPOINTS = {
    login: "/api/login",
    register: "/api/register",
    verifyLogin: "/api/login/verify"
};

// Send an authentication request and return its response.
async function apiRequest(action, payload) {
    const response = await window.csrfFetch(API_ENDPOINTS[action], {
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
        throw new Error("The server could not complete that request. Please try again in a moment.");
    }

    let data;
    try {
        data = await response.json();
    } catch {
        throw new Error("The server returned an unexpected response. Please try again; contact the system administrator if it continues.");
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
    window.location.href = user.role === "admin" ? "/admin" : "/resident";
}


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
        if (result.twoFactorRequired) {
            showTwoFactorStep(result);
            return;
        }
        enterPortal(result.user);
    } catch (error) {
        formMessage.textContent = error.message;
    }
});

// Administrators confirm each sign-in with a code emailed to them.
const twoFactorForm = document.getElementById("twoFactorForm");
let twoFactorChallenge = null;

function showTwoFactorStep(result) {
    twoFactorChallenge = result.challenge;
    loginForm.hidden = true;
    forgotPasswordButton.hidden = true;
    formSwitch.hidden = true;
    twoFactorForm.hidden = false;
    formTitle.textContent = "Check your email";
    document.getElementById("twoFactorIntro").textContent = result.message;
    document.getElementById("twoFactorMessage").textContent = "";
    document.getElementById("loginCode").value = "";
    document.getElementById("loginCode").focus();
}

function hideTwoFactorStep() {
    twoFactorChallenge = null;
    twoFactorForm.hidden = true;
    loginForm.hidden = false;
    forgotPasswordButton.hidden = false;
    formSwitch.hidden = false;
    formTitle.textContent = "Log in";
    formMessage.textContent = "";
}

twoFactorForm.addEventListener("submit", async (event) => {
    event.preventDefault();
    const message = document.getElementById("twoFactorMessage");
    message.textContent = "Checking the code...";
    try {
        const result = await apiRequest("verifyLogin", {
            challenge: twoFactorChallenge,
            code: document.getElementById("loginCode").value.trim()
        });
        enterPortal(result.user);
    } catch (error) {
        message.textContent = error.message;
        if (/sign in again/i.test(error.message || "")) hideTwoFactorStep();
    }
});

document.getElementById("twoFactorBack").addEventListener("click", hideTwoFactorStep);

// Switch between the login and registration forms.
formSwitch.addEventListener("click", () => {
    twoFactorForm.hidden = true;
    forgotPasswordForm.hidden = true;
    forgotPasswordButton.hidden = false;
    const showingRegistration = registerForm.hidden;
    loginForm.hidden = showingRegistration;
    registerForm.hidden = !showingRegistration;
    formTitle.textContent = showingRegistration ? "Create an account" : "Log in";
    formSwitch.textContent = showingRegistration ? "Back to log in" : "Create an account";
    forgotPasswordButton.hidden = showingRegistration;
    formSwitch.setAttribute("aria-controls", showingRegistration ? "loginForm" : "registerForm");
    formMessage.textContent = "";
    registerMessage.textContent = "";
    showRegisterStep(1);
    if (showingRegistration) loadAddressOptions();
});

// ---- Create account, step 2: home address ----
const registerStep1 = document.getElementById("registerStep1");
const registerStep2 = document.getElementById("registerStep2");
const registerStepLabel = document.getElementById("registerStepLabel");
const registerArea = document.getElementById("registerArea");
const registerStreet = document.getElementById("registerStreet");
let addressAreas = null;

function showRegisterStep(step) {
    registerStep1.hidden = step !== 1;
    registerStep2.hidden = step !== 2;
    registerStepLabel.textContent = step === 1 ? "Step 1 of 2 · Account details" : "Step 2 of 2 · Your address";
}

// Villages / phases and their streets, loaded once from the server.
async function loadAddressOptions() {
    if (addressAreas) return;
    try {
        const response = await fetch("/api/locations", { headers: { Accept: "application/json" } });
        const data = await response.json();
        addressAreas = {};
        (data.locations || []).forEach(location => {
            (addressAreas[location.area_name] ||= []).push(location);
        });
        Object.keys(addressAreas).forEach(area => registerArea.add(new Option(area, area)));
    } catch {
        addressAreas = null;
        registerMessage.textContent = "Could not load the list of streets. Please refresh the page.";
    }
}

registerArea.addEventListener("change", () => {
    const streets = (addressAreas && addressAreas[registerArea.value]) || [];
    registerStreet.length = 0;
    if (!registerArea.value) {
        registerStreet.add(new Option("Choose village / phase first", ""));
        registerStreet.disabled = true;
    } else if (streets.length === 1 && streets[0].name === "Area-wide") {
        // Areas without separate streets have a single "Area-wide" entry.
        registerStreet.add(new Option("Whole area", streets[0].location_id));
        registerStreet.disabled = false;
    } else {
        registerStreet.add(new Option("Choose street", ""));
        streets.filter(street => street.name !== "Area-wide")
            .forEach(street => registerStreet.add(new Option(street.name, street.location_id)));
        registerStreet.disabled = false;
    }
});

function goToAddressStep() {
    const fields = registerStep1.querySelectorAll("input");
    for (const field of fields) {
        if (!field.reportValidity()) return;
    }
    if (registerPasswordInput.value !== confirmPasswordInput.value) {
        registerMessage.textContent = "Passwords do not match.";
        confirmPasswordInput.focus();
        return;
    }
    registerMessage.textContent = "";
    showRegisterStep(2);
    loadAddressOptions();
    registerArea.focus();
}
document.getElementById("registerNext").addEventListener("click", goToAddressStep);
// Pressing Enter in step 1 moves to step 2 instead of submitting.
registerStep1.addEventListener("keydown", event => {
    if (event.key === "Enter" && event.target.tagName === "INPUT") {
        event.preventDefault();
        goToAddressStep();
    }
});
document.getElementById("registerBack").addEventListener("click", () => {
    registerMessage.textContent = "";
    showRegisterStep(1);
});

forgotPasswordButton.addEventListener("click", () => {
    loginForm.hidden = true;
    registerForm.hidden = true;
    forgotPasswordForm.hidden = false;
    forgotPasswordButton.hidden = true;
    formSwitch.hidden = true;
    formTitle.textContent = "Reset your password";
});

returnToLoginButton.addEventListener("click", () => {
    forgotPasswordForm.hidden = true;
    loginForm.hidden = false;
    forgotPasswordButton.hidden = false;
    formSwitch.hidden = false;
    formTitle.textContent = "Log in";
});

// Cloudflare Turnstile (only present when the server enables it). Each token works once,
// so the widget is reset after every attempt.
function botCheckToken(form) {
    const field = form.querySelector('[name="cf-turnstile-response"]');
    return field ? field.value : undefined;
}

function resetBotCheck(form) {
    const widget = form.querySelector(".cf-turnstile");
    if (widget && window.turnstile) window.turnstile.reset(widget);
}

forgotPasswordForm.addEventListener("submit", async event => {
    event.preventDefault();
    const message = document.getElementById("forgotPasswordMessage");
    message.textContent = "Sending…";
    try {
        const response = await window.csrfFetch("/api/password/forgot", {
            method: "POST",
            credentials: "same-origin",
            headers: {
                "Accept": "application/json",
                "Content-Type": "application/json",
                "X-CSRF-TOKEN": document.querySelector('meta[name="csrf-token"]').content
            },
            body: JSON.stringify({
                email: document.getElementById("forgotEmail").value.trim(),
                "cf-turnstile-response": botCheckToken(forgotPasswordForm)
            })
        });
        const result = await response.json();
        if (!response.ok || !result.success) throw new Error(result.message || "The request could not be completed.");
        message.textContent = result.message;
    } catch (error) {
        message.textContent = error.message || "The request could not be completed.";
    } finally {
        resetBotCheck(forgotPasswordForm);
    }
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
            password,
            locationId: Number(registerStreet.value) || null,
            addressDetails: document.getElementById("registerAddress").value.trim(),
            mobile: document.getElementById("registerMobile").value.trim(),
            privacyConsent: document.getElementById("privacyConsent").checked,
            "cf-turnstile-response": botCheckToken(registerForm)
        });
        registerForm.reset();
        registerArea.dispatchEvent(new Event("change"));
        showRegisterStep(1);
        loginForm.hidden = false;
        registerForm.hidden = true;
        formTitle.textContent = "Log in";
        formSwitch.textContent = "Create an account";
        formSwitch.setAttribute("aria-controls", "registerForm");
        document.getElementById("username").value = username;
        formMessage.textContent = result.message + emailDeliveryNotice(result.emailStatus);
    } catch (error) {
        registerMessage.textContent = error.message + emailDeliveryNotice(error.emailStatus);
    } finally {
        resetBotCheck(registerForm);
    }
});

// A link to /?register=1 opens the Create account form directly.
if (new URLSearchParams(window.location.search).get("register") === "1" && registerForm.hidden) {
    formSwitch.click();
}

