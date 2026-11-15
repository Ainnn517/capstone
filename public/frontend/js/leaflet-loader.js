// Loads the Leaflet map library (served from this site) the first time a map is needed.
// Map tiles come from OpenStreetMap, which is free and needs no API key.
(function () {
  let loading = null;

  window.MAP_TILE_URL = 'https://tile.openstreetmap.org/{z}/{x}/{y}.png';
  window.MAP_ATTRIBUTION = '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors';
  // Default view: the middle of the barangay (see public/frontend/data/barangay-boundary.json).
  window.MAP_DEFAULT_CENTER = [14.3297, 121.0153];

  // Show only the barangay: draw its outline, dim everything outside it, and lock the map so
  // it cannot be dragged or zoomed away. Resolves with { name, place, bounds, acceptsPin }.
  // If the outline cannot be loaded the map still works, centered on the barangay.
  let boundaryData = null;

  function ringContains(ring, lat, lng) {
    let inside = false;
    for (let i = 0, j = ring.length - 1; i < ring.length; j = i++) {
      const [x1, y1] = ring[i];
      const [x2, y2] = ring[j];
      if ((y1 > lat) !== (y2 > lat) && lng < (x2 - x1) * (lat - y1) / (y2 - y1) + x1) inside = !inside;
    }
    return inside;
  }

  window.addBarangayBoundary = async function (map) {
    const L = window.L;
    try {
      if (!boundaryData) {
        const response = await fetch('/frontend/data/barangay-boundary.json', { credentials: 'same-origin' });
        if (!response.ok) throw new Error('boundary unavailable');
        boundaryData = await response.json();
      }
      const ring = boundaryData.geometry.coordinates[0];
      const pinRing = (boundaryData.properties.pinArea || [ring])[0];
      const hole = ring.map(([lng, lat]) => [lat, lng]);

      // Everything outside the barangay is faded: a sheet over the whole world with a
      // barangay-shaped hole in it.
      L.polygon([[[-85, -180], [-85, 180], [85, 180], [85, -180]], hole], {
        stroke: false,
        fillColor: '#e7ebe8',
        fillOpacity: 0.78,
        interactive: false
      }).addTo(map);
      const outline = L.polygon(hole, {
        interactive: false,
        color: '#1f6f4a',
        weight: 2.5,
        dashArray: '6 5',
        fill: false
      }).addTo(map);

      const bounds = outline.getBounds();
      // Finer zoom steps, so the barangay fills the frame instead of stopping a whole step short.
      map.options.zoomSnap = 0.25;
      map.options.zoomDelta = 0.5;
      map.fitBounds(bounds, { padding: [12, 12] });
      map.setMinZoom(map.getZoom());
      map.setMaxBounds(bounds.pad(0.15));
      map.options.maxBoundsViscosity = 1;
      return {
        name: boundaryData.properties.name,
        place: boundaryData.properties.place,
        bounds,
        acceptsPin: (lat, lng) => ringContains(pinRing, lat, lng)
      };
    } catch {
      map.setView(window.MAP_DEFAULT_CENTER, 15);
      return { name: 'the barangay', place: '', bounds: null, acceptsPin: () => true };
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
