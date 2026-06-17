/*
 * map-heatmap.js — Crowd-Heatmap via leaflet.heat.
 *
 * Fetcht die GeoJSON-FeatureCollection von `/api/v1/heatmap`
 * (same-origin) und baut daraus eine Heat-Layer. Intensität wird
 * über `meta.max_weight` auf 0..1 normiert.
 */
(function () {
  'use strict';

  if (!window.GE || !window.GE.map || typeof L.heatLayer !== 'function') {
    return;
  }
  var el = document.getElementById('map');
  if (!el) {
    return;
  }
  var url = el.getAttribute('data-heatmap-url') || '/api/v1/heatmap';

  fetch(url, {
    headers: { Accept: 'application/json' },
    credentials: 'same-origin'
  })
    .then(function (res) {
      if (!res.ok) {
        throw new Error('HTTP ' + res.status);
      }
      return res.json();
    })
    .then(function (fc) {
      var features = (fc && fc.features) || [];
      if (!features.length) {
        GE.map.showEmpty(el, 'Noch keine Aktivitätsdaten für die Heatmap.');
        return;
      }
      var max = fc.meta && fc.meta.max_weight ? fc.meta.max_weight : 1;
      var points = [];
      var bounds = [];
      features.forEach(function (f) {
        var c = f.geometry && f.geometry.coordinates;
        if (!Array.isArray(c) || c.length < 2) {
          return;
        }
        var lon = c[0];
        var lat = c[1];
        var weight = (f.properties && f.properties.weight) || 0;
        var intensity = max > 0 ? weight / max : 0;
        points.push([lat, lon, intensity]);
        bounds.push([lat, lon]);
      });
      if (!points.length) {
        GE.map.showEmpty(el, 'Noch keine Aktivitätsdaten für die Heatmap.');
        return;
      }
      var map = GE.map.createBaseMap(el);
      L.heatLayer(points, { radius: 25, blur: 18, maxZoom: 13 }).addTo(map);
      try {
        map.fitBounds(bounds, { padding: [30, 30] });
      } catch (e) {
        /* Default-View bleibt */
      }
    })
    .catch(function () {
      GE.map.showEmpty(el, 'Heatmap konnte nicht geladen werden.');
    });
})();
