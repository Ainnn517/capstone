// Loads the Leaflet map library (served from this site) the first time a map is needed.
// Map tiles come from OpenStreetMap, which is free and needs no API key.
(function () {
  let loading = null;

  window.MAP_TILE_URL = 'https://tile.openstreetmap.org/{z}/{x}/{y}.png';
  window.MAP_ATTRIBUTION = '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors';
  // Default view: San Jose del Monte, Bulacan.
  window.MAP_DEFAULT_CENTER = [14.8136, 121.045];

  window.loadLeaflet = function () {
    if (window.L) return Promise.resolve(window.L);
    if (!loading) {
      const load = (element, parent) => new Promise((resolve, reject) => {
        element.onload = resolve;
        element.onerror = reject;
        parent.appendChild(element);
      });
      const css = document.createElement('link');
      css.rel = 'stylesheet';
      css.href = '/frontend/vendor/leaflet/leaflet.css';
      const script = document.createElement('script');
      script.src = '/frontend/vendor/leaflet/leaflet.js';
      loading = Promise.all([load(css, document.head), load(script, document.head)])
        .then(() => {
          if (!window.L) throw new Error('The map could not start.');
          window.L.Icon.Default.imagePath = '/frontend/vendor/leaflet/images/';
          return window.L;
        })
        .catch(error => {
          loading = null;
          throw error instanceof Error && error.message === 'The map could not start.'
            ? error
            : new Error('The map could not be loaded. Check your internet connection and try again.');
        });
    }
    return loading;
  };
})();
