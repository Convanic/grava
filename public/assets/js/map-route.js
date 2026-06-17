/*
 * map-route.js — zeichnet eine einzelne Routen-Geometrie.
 *
 * Liest `#map[data-geojson-url]`, fetcht die GeoJSON-FeatureCollection
 * (same-origin, durch connect-src 'self' gedeckt) und zeichnet die
 * Linie. Wird von Route-Detail, Profil-Route und Share-Seite genutzt.
 *
 * Surface-Score-Einfärbung: hat ein Feature `properties.score` (0..5),
 * wird das Segment entsprechend grün→rot eingefärbt; sonst eine
 * einfarbige Linie.
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
  var url = el.getAttribute('data-geojson-url');
  if (!url) {
    return;
  }

  var BASE_COLOR = '#4a7c2a';

  // Farbskala für Surface-Scores: niedrig = grün, hoch = rot.
  // (Umgekehrt zur ersten Version — glatte Straße grün, Gravel rot.)
  var SCORE_COLORS = {
    0: '#15803d',
    1: '#84cc16',
    2: '#eab308',
    3: '#f97316',
    4: '#e11d48',
    5: '#b91c1c'
  };

  function colorForScore(score) {
    var s = Math.max(0, Math.min(5, Math.round(score)));
    return SCORE_COLORS[s] || BASE_COLOR;
  }

  function styleFeature(feature) {
    var props = (feature && feature.properties) || {};
    if (typeof props.score === 'number') {
      return { color: colorForScore(props.score), weight: 5, opacity: 0.9 };
    }
    return { color: BASE_COLOR, weight: 4, opacity: 0.85 };
  }

  var SCORE_LABELS = {
    0: 'sehr schlecht',
    1: 'schlecht',
    2: 'mäßig',
    3: 'ok',
    4: 'gut',
    5: 'sehr gut'
  };

  function renderLegend(geojson) {
    var legend = document.getElementById('map-legend');
    if (!legend) {
      return;
    }
    var present = {};
    geojson.features.forEach(function (f) {
      if (f.properties && typeof f.properties.score === 'number') {
        present[Math.max(0, Math.min(5, Math.round(f.properties.score)))] = true;
      }
    });
    var scores = Object.keys(present)
      .map(Number)
      .sort(function (a, b) {
        return a - b;
      });
    if (!scores.length) {
      return;
    }
    while (legend.firstChild) {
      legend.removeChild(legend.firstChild);
    }
    var title = document.createElement('span');
    title.textContent = 'Untergrund:';
    legend.appendChild(title);
    scores.forEach(function (s) {
      var item = document.createElement('span');
      var sw = document.createElement('span');
      sw.className = 'swatch';
      sw.style.background = colorForScore(s);
      item.appendChild(sw);
      item.appendChild(document.createTextNode(s + ' – ' + (SCORE_LABELS[s] || '')));
      legend.appendChild(item);
    });
    legend.hidden = false;
  }

  function hasGeometry(geojson) {
    if (!geojson || !geojson.features || !geojson.features.length) {
      return false;
    }
    return geojson.features.some(function (f) {
      var c = f.geometry && f.geometry.coordinates;
      return Array.isArray(c) && c.length > 1;
    });
  }

  fetch(url, {
    headers: { Accept: 'application/geo+json' },
    credentials: 'same-origin'
  })
    .then(function (res) {
      if (!res.ok) {
        throw new Error('HTTP ' + res.status);
      }
      return res.json();
    })
    .then(function (geojson) {
      if (!hasGeometry(geojson)) {
        GE.map.showEmpty(el, 'Für diese Route liegt keine Geometrie vor.');
        return;
      }
      var map = GE.map.createBaseMap(el);
      var layer = L.geoJSON(geojson, { style: styleFeature }).addTo(map);
      try {
        map.fitBounds(layer.getBounds(), { padding: [20, 20] });
      } catch (e) {
        /* leere Bounds — Default-View bleibt */
      }
      renderLegend(geojson);
    })
    .catch(function () {
      GE.map.showEmpty(el, 'Karte konnte nicht geladen werden.');
    });
})();
