/*
 * map-discover.js — Übersichtskarte der Discover-Treffer.
 *
 * Liest `#map[data-routes]` (JSON-Array mit `lat`/`lon`/`title`/`url`
 * je Route, serverseitig aus den Centroiden gebaut) und setzt je
 * Route einen vektorbasierten `L.circleMarker` (keine Icon-Bilder →
 * CSP-clean, kein zusätzlicher img-src nötig).
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
    }).addTo(map);

    var label = GE.map.escapeHtml(r.title || 'Route');
    if (r.url) {
      marker.bindPopup('<a href="' + GE.map.escapeHtml(r.url) + '">' + label + '</a>');
    } else {
      marker.bindPopup(label);
    }
    bounds.push([r.lat, r.lon]);
  });

  if (bounds.length) {
    try {
      map.fitBounds(bounds, { padding: [30, 30], maxZoom: 12 });
    } catch (e) {
      /* Default-View bleibt */
    }
  }
})();
