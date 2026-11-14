// Sends same-origin API requests with the page's CSRF token. If the token has gone stale
// (the session expired, or the user logged in or out in another tab), the server answers
// 419; fetch a fresh token once and retry, so the user never sees "CSRF token mismatch".
(function () {
  const meta = () => document.querySelector('meta[name="csrf-token"]');

  async function refreshToken() {
    try {
      const response = await fetch('/api/csrf-token', {
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
        cache: 'no-store'
      });
      const data = await response.json();
      if (!response.ok || !data.token || !meta()) return false;
      meta().setAttribute('content', data.token);
      return true;
    } catch {
      return false;
    }
  }

  window.csrfFetch = async function (url, options = {}) {
    const method = (options.method || 'GET').toUpperCase();
    const send = () => {
      const headers = new Headers(options.headers || {});
      if (method !== 'GET' && meta()) headers.set('X-CSRF-TOKEN', meta().content);
      return fetch(url, { ...options, credentials: 'same-origin', headers });
    };

    const response = await send();
    if (response.status === 419 && method !== 'GET' && await refreshToken()) {
      return send();
    }
    return response;
  };
})();
