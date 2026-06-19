/*
 * map-surface-check.js — zeichnet das Belags-Profil einer hochgeladenen Route.
 *
 * Liest die eingebettete GeoJSON-FeatureCollection (#surface-data, vom Server
 * gerendert), färbt jedes Segment nach projiziertem Crowd-Untergrund
 * (grün glatt -> rot grob; ohne Daten grau) und zoomt auf die Route.
 *
 * Optional: der "Details"-Button holt per same-origin-Fetch das präzise
 * Valhalla-Ergebnis (/surface-check/details) und färbt neu. CSP-konform
 * (externes 'self'-Script, kein Inline, kein eval).
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

  var SCORE_COLORS = {
    0: '#15803d', 1: '#84cc16', 2: '#eab308',
    3: '#f97316', 4: '#e11d48', 5: '#b91c1c'
  };
  var SCORE_LABELS = {
    0: 'sehr glatt', 1: 'glatt', 2: 'überwiegend fest',
    3: 'gemischt', 4: 'ruppig', 5: 'grob / Schotter'
  };
  var NO_DATA_COLOR = '#94a3b8';

  function colorForScore(score) {
    if (typeof score !== 'number') {
      return NO_DATA_COLOR;
    }
    var s = Math.max(0, Math.min(5, Math.round(score)));
    return SCORE_COLORS[s] || NO_DATA_COLOR;
  }

  function parseEmbedded() {
    var node = document.getElementById('surface-data');
    if (!node) {
      return null;
    }
    try {
      return JSON.parse(node.textContent || '{}');
    } catch (e) {
      return null;
    }
  }

  var map = GE.map.createBaseMap(el);
  var routeGroup = L.layerGroup().addTo(map);

  function tooltipHtml(p) {
    if (!p || p.source !== 'crowd') {
      return 'Keine Crowd-Daten';
    }
    var lines = [];
    if (typeof p.score === 'number') {
      lines.push('Untergrund: ' + p.score + ' – ' + (SCORE_LABELS[p.score] || ''));
    }
    if (p.surface) {
      lines.push('OSM: ' + GE.map.escapeHtml(p.surface));
    }
    return lines.length ? lines.join('<br>') : 'Crowd-Daten vorhanden';
  }

  function render(fc) {
    routeGroup.clearLayers();
    var features = (fc && fc.features) || [];
    var bounds = [];
    var hasCrowd = false;

    features.forEach(function (f) {
      var c = f.geometry && f.geometry.coordinates;
      if (!Array.isArray(c) || c.length < 2) {
        return;
      }
      var p = f.properties || {};
      var latlngs = c.map(function (pt) { bounds.push([pt[1], pt[0]]); return [pt[1], pt[0]]; });
      var isCrowd = p.source === 'crowd';
      if (isCrowd) { hasCrowd = true; }
      var line = L.polyline(latlngs, {
        color: isCrowd ? colorForScore(p.score) : NO_DATA_COLOR,
        weight: 5,
        opacity: isCrowd ? 0.9 : 0.55,
        dashArray: isCrowd ? null : '4,6',
        lineCap: 'round',
        lineJoin: 'round'
      });
      line.bindTooltip(tooltipHtml(p), { sticky: true });
      routeGroup.addLayer(line);
    });

    if (bounds.length) {
      try { map.fitBounds(bounds, { padding: [30, 30] }); } catch (e) { /* default view */ }
    }
    updateLegend(hasCrowd);
  }

  function updateLegend(show) {
    var legend = document.getElementById('map-legend');
    if (!legend) {
      return;
    }
    while (legend.firstChild) {
      legend.removeChild(legend.firstChild);
    }
    var title = document.createElement('span');
    title.textContent = 'Untergrund:';
    legend.appendChild(title);
    [0, 1, 2, 3, 4, 5].forEach(function (s) {
      var item = document.createElement('span');
      var sw = document.createElement('span');
      sw.className = 'swatch';
      sw.style.background = SCORE_COLORS[s];
      item.appendChild(sw);
      item.appendChild(document.createTextNode(s + ' – ' + SCORE_LABELS[s]));
      legend.appendChild(item);
    });
    var na = document.createElement('span');
    var naSw = document.createElement('span');
    naSw.className = 'swatch';
    naSw.style.background = NO_DATA_COLOR;
    na.appendChild(naSw);
    na.appendChild(document.createTextNode('keine Daten'));
    legend.appendChild(na);
    legend.hidden = false;
  }

  // ---- Initiales Rendern aus eingebetteten Daten ------------------------
  var initial = parseEmbedded();
  if (initial && initial.geojson) {
    render(initial.geojson);
  } else {
    GE.map.showEmpty(el, 'Keine Geometrie gefunden.');
  }

  // ---- Optional: präziser Valhalla-Pfad (Details) -----------------------
  var btn = document.getElementById('surface-details-btn');
  var statusEl = document.getElementById('surface-details-status');
  var detailsUrl = el.getAttribute('data-details-url') || '';

  function setStatus(msg) {
    if (statusEl) { statusEl.textContent = msg || ''; }
  }

  if (btn && detailsUrl) {
    btn.addEventListener('click', function () {
      btn.disabled = true;
      setStatus('Berechne…');
      fetch(detailsUrl, {
        headers: { Accept: 'application/json' },
        credentials: 'same-origin'
      })
        .then(function (res) {
          if (!res.ok) { throw new Error('HTTP ' + res.status); }
          return res.json();
        })
        .then(function (data) {
          if (data && data.available && data.geojson) {
            render(data.geojson);
            setStatus('Präzises Map-Matching aktiv.');
          } else {
            setStatus('Präzise Analyse derzeit nicht verfügbar.');
            btn.disabled = false;
          }
        })
        .catch(function () {
          setStatus('Präzise Analyse fehlgeschlagen.');
          btn.disabled = false;
        });
    });
  }
})();
