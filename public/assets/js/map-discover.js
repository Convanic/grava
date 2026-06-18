/*
 * map-discover.js — Übersichtskarte der Discover-Treffer.
 *
 * Liest `#map[data-routes]` (JSON-Array mit `lat`/`lon`/`title`/`url` je
 * Route, serverseitig aus den Centroiden gebaut). Funktionen:
 *
 *   - Marker-Clustering via Leaflet.markercluster (DivIcons → CSP-clean,
 *     kein zusätzlicher img-src nötig).
 *   - Linien-Vorschau beim Hover: lädt `<url>/geojson` per same-origin-Fetch
 *     und zeichnet die Strecke kurz ein.
 *   - BBox per Maus ziehen: ein Control schaltet den Zeichenmodus; das
 *     aufgezogene Rechteck füllt das vorhandene `bbox`-Filterfeld
 *     (minLat,minLon,maxLat,maxLon) und sendet das Filterformular ab.
 */
(function () {
  'use strict';

  if (!window.GE || !window.GE.map) {
    return;
  }
  var el = document.getElementById('map');
  if (!el) {
    return;
  }
  var raw = el.getAttribute('data-routes');
  if (!raw) {
    return;
  }

  var routes;
  try {
    routes = JSON.parse(raw);
  } catch (e) {
    return;
  }
  if (!Array.isArray(routes) || !routes.length) {
    GE.map.showEmpty(el, 'Keine Routen mit Geodaten gefunden.');
    return;
  }

  var map = GE.map.createBaseMap(el);
  var bounds = [];

  // --- Marker (optional geclustert) ------------------------------------
  var useCluster = typeof L.markerClusterGroup === 'function';
  var layer = useCluster
    ? L.markerClusterGroup({ showCoverageOnHover: false, maxClusterRadius: 50 })
    : L.layerGroup();

  // Vorschau-Layer für die gehoverte Strecke.
  var previewLayer = null;
  var previewToken = 0;

  function clearPreview() {
    previewToken++;
    if (previewLayer) {
      map.removeLayer(previewLayer);
      previewLayer = null;
    }
  }

  function showPreview(geojsonUrl) {
    var token = ++previewToken;
    fetch(geojsonUrl, { headers: { Accept: 'application/geo+json' } })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (data) {
        if (!data || token !== previewToken) {
          return; // veraltet oder Fehler — verwerfen
        }
        if (previewLayer) {
          map.removeLayer(previewLayer);
        }
        previewLayer = L.geoJSON(data, {
          style: { color: '#1d4ed8', weight: 4, opacity: 0.9 }
        }).addTo(map);
      })
      .catch(function () { /* still degradieren */ });
  }

  routes.forEach(function (r) {
    if (typeof r.lat !== 'number' || typeof r.lon !== 'number') {
      return;
    }
    var marker = L.circleMarker([r.lat, r.lon], {
      radius: 7,
      color: '#3c6622',
      weight: 2,
      fillColor: '#4a7c2a',
      fillOpacity: 0.8
    });

    var label = GE.map.escapeHtml(r.title || 'Route');
    if (r.url) {
      marker.bindPopup('<a href="' + GE.map.escapeHtml(r.url) + '">' + label + '</a>');
      marker.on('mouseover', function () { showPreview(r.url + '/geojson'); });
      marker.on('mouseout', clearPreview);
    } else {
      marker.bindPopup(label);
    }

    layer.addLayer(marker);
    bounds.push([r.lat, r.lon]);
  });
  map.addLayer(layer);

  if (bounds.length) {
    try {
      map.fitBounds(bounds, { padding: [30, 30], maxZoom: 12 });
    } catch (e) {
      /* Default-View bleibt */
    }
  }

  // --- BBox per Maus ziehen --------------------------------------------
  setupBboxDraw(map);

  function setupBboxDraw(map) {
    var input = document.querySelector('input[name="bbox"]');
    var form = input ? input.form : null;
    if (!input || !form) {
      return; // kein Filterformular → kein Zeichenmodus
    }

    var drawing = false;
    var active = false;
    var startLatLng = null;
    var rect = null;

    var Control = L.Control.extend({
      options: { position: 'topright' },
      onAdd: function () {
        var btn = L.DomUtil.create('button', 'ge-bbox-btn');
        btn.type = 'button';
        btn.title = 'Bereich auf der Karte aufziehen, um zu filtern';
        btn.textContent = '▭ Bereich';
        L.DomEvent.disableClickPropagation(btn);
        L.DomEvent.on(btn, 'click', function (e) {
          L.DomEvent.stop(e);
          active = !active;
          btn.classList.toggle('is-active', active);
          el.classList.toggle('ge-bbox-mode', active);
          if (active) {
            map.dragging.disable();
          } else {
            map.dragging.enable();
            cancelRect();
          }
        });
        return btn;
      }
    });
    map.addControl(new Control());

    function cancelRect() {
      drawing = false;
      if (rect) { map.removeLayer(rect); rect = null; }
    }

    map.on('mousedown', function (e) {
      if (!active) { return; }
      drawing = true;
      startLatLng = e.latlng;
      if (rect) { map.removeLayer(rect); }
      rect = L.rectangle([startLatLng, startLatLng], {
        color: '#1d4ed8', weight: 2, dashArray: '4', fillOpacity: 0.08
      }).addTo(map);
    });

    map.on('mousemove', function (e) {
      if (!active || !drawing || !startLatLng) { return; }
      rect.setBounds(L.latLngBounds(startLatLng, e.latlng));
    });

    map.on('mouseup', function (e) {
      if (!active || !drawing || !startLatLng) { return; }
      drawing = false;
      var b = L.latLngBounds(startLatLng, e.latlng);
      // Winzige Klicks (kein echtes Rechteck) ignorieren.
      if (b.getNorth() - b.getSouth() < 1e-4 && b.getEast() - b.getWest() < 1e-4) {
        cancelRect();
        return;
      }
      var v = [
        b.getSouth().toFixed(5),
        b.getWest().toFixed(5),
        b.getNorth().toFixed(5),
        b.getEast().toFixed(5)
      ].join(',');
      input.value = v;
      map.dragging.enable();
      form.submit();
    });
  }
})();
