// Loads the Leaflet map library (served from this site) the first time a map is needed.
// Map tiles come from OpenStreetMap, which is free and needs no API key.
(function () {
  let loading = null;

  window.MAP_TILE_URL = 'https://tile.openstreetmap.org/{z}/{x}/{y}.png';
  window.MAP_ATTRIBUTION = '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors';
  // Default view: the middle of the barangay (see public/frontend/data/barangay-boundary.json).
  window.MAP_DEFAULT_CENTER = [14.3286, 121.0155];

  // Draw the barangay's outline on a map, fit the view to it, and stop the map from being
  // dragged far away from it. Resolves with { name, place, bounds }; if the outline cannot
  // be loaded the map still works, centered on the barangay.
  let boundaryData = null;
  window.addBarangayBoundary = async function (map) {
    const L = window.L;
    try {
      if (!boundaryData) {
        const response = await fetch('/frontend/data/barangay-boundary.json', { credentials: 'same-origin' });
        if (!response.ok) throw new Error('boundary unavailable');
        boundaryData = await response.json();
      }
      const outline = L.geoJSON(boundaryData, {
        interactive: false,
        style: { color: '#1f6f4a', weight: 2.5, dashArray: '6 5', fillColor: '#35b779', fillOpacity: 0.06 }
      }).addTo(map);
      const bounds = outline.getBounds();
      map.fitBounds(bounds, { padding: [20, 20] });
      map.setMaxBounds(bounds.pad(0.6));
      map.setMinZoom(Math.max(0, map.getBoundsZoom(bounds.pad(0.6)) - 1));
      return { name: boundaryData.properties.name, place: boundaryData.properties.place, bounds };
    } catch {
      map.setView(window.MAP_DEFAULT_CENTER, 15);
      return { name: 'the barangay', place: '', bounds: null };
    }
  };

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
