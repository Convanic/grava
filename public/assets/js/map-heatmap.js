/*
 * map-heatmap.js — Crowd-Heatmap mit zwei umschaltbaren Layern.
 *
 *  1) "Dichte" — leaflet.heat-Layer aus `/api/v1/heatmap` (Centroid-Raster).
 *  2) "Strecken" — gematchte Wegstücke aus `/api/v1/heatmap/lines?bbox=…`.
 *     Linien liegen direkt auf der Straße (Valhalla-Map-Matching), Farbe =
 *     Ø Surface-Score (grün glatt → rot grob, identisch zu map-route.js),
 *     Linienbreite = Häufigkeit (wie viele Routen das Stück nutzen).
 *
 * Die Strecken-Layer lädt BBox-getrieben: nur was im Viewport liegt, und beim
 * Verschieben/Zoomen (entprellt) nach. CSP-konform (externes 'self'-Script,
 * same-origin-Fetch).
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

  var heatUrl = el.getAttribute('data-heatmap-url') || '/api/v1/heatmap';
  var linesUrl = el.getAttribute('data-lines-url') || '/api/v1/heatmap/lines';

  // Farbskala wie map-route.js: niedrig = glatt = grün, hoch = grob = rot.
  var SCORE_COLORS = {
    0: '#15803d', 1: '#84cc16', 2: '#eab308',
    3: '#f97316', 4: '#e11d48', 5: '#b91c1c'
  };
  var SCORE_LABELS = {
    0: 'sehr glatt', 1: 'glatt', 2: 'überwiegend fest',
    3: 'gemischt', 4: 'ruppig', 5: 'grob / Schotter'
  };
  var NO_SCORE_COLOR = '#64748b';

  function colorForScore(score) {
    if (typeof score !== 'number') {
      return NO_SCORE_COLOR;
    }
    var s = Math.max(0, Math.min(5, Math.round(score)));
    return SCORE_COLORS[s] || NO_SCORE_COLOR;
  }

  function weightForCount(count, maxCount) {
    var base = 2.5;
    if (!maxCount || maxCount <= 1) {
      return base + 1.5;
    }
    var ratio = Math.min(1, (count || 1) / maxCount);
    return base + ratio * 6; // 2.5 .. 8.5 px
  }

  var map = GE.map.createBaseMap(el);

  // ---- Strecken-Layer (BBox-getrieben) ----------------------------------
  var linesGroup = L.layerGroup();
  var linesActive = false;
  var lastBboxKey = '';
  var debounceTimer = null;

  function bboxParam() {
    var b = map.getBounds();
    // API erwartet minLon,minLat,maxLon,maxLat.
    return [b.getWest(), b.getSouth(), b.getEast(), b.getNorth()]
      .map(function (n) { return n.toFixed(5); })
      .join(',');
  }

  function tooltipHtml(p) {
    var lines = [];
    lines.push('<strong>' + (p.count || 1) + '</strong> Route(n)');
    if (typeof p.avg_score === 'number') {
      var s = Math.max(0, Math.min(5, Math.round(p.avg_score)));
      lines.push('Ø Untergrund: ' + p.avg_score.toFixed(1) + ' – ' + (SCORE_LABELS[s] || ''));
    } else if (p.surface) {
      lines.push('OSM: ' + GE.map.escapeHtml(p.surface));
    }
    if (p.length_m) {
      lines.push(p.length_m + ' m');
    }
    return lines.join('<br>');
  }

  function renderLines(fc) {
    linesGroup.clearLayers();
    var features = (fc && fc.features) || [];
    var maxCount = (fc && fc.meta && fc.meta.max_count) || 1;
    var drawn = 0;
    features.forEach(function (f) {
      var c = f.geometry && f.geometry.coordinates;
      if (!Array.isArray(c) || c.length < 2) {
        return;
      }
      var latlngs = c.map(function (pt) { return [pt[1], pt[0]]; });
      var p = f.properties || {};
      var line = L.polyline(latlngs, {
        color: colorForScore(p.avg_score),
        weight: weightForCount(p.count, maxCount),
        opacity: 0.85,
        lineCap: 'round',
        lineJoin: 'round'
      });
      line.bindTooltip(tooltipHtml(p), { sticky: true });
      linesGroup.addLayer(line);
      drawn++;
    });
    updateLegend(drawn > 0);
  }

  function loadLines() {
    if (!linesActive) {
      return;
    }
    var key = bboxParam();
    if (key === lastBboxKey) {
      return;
    }
    lastBboxKey = key;
    fetch(linesUrl + '?bbox=' + encodeURIComponent(key), {
      headers: { Accept: 'application/json' },
      credentials: 'same-origin'
    })
      .then(function (res) {
        if (!res.ok) { throw new Error('HTTP ' + res.status); }
        return res.json();
      })
      .then(renderLines)
      .catch(function () { /* still degradieren, Karte bleibt nutzbar */ });
  }

  function scheduleLoad() {
    if (debounceTimer) {
      window.clearTimeout(debounceTimer);
    }
    debounceTimer = window.setTimeout(loadLines, 350);
  }

  map.on('overlayadd', function (e) {
    if (e.layer === linesGroup) {
      linesActive = true;
      lastBboxKey = '';
      loadLines();
    }
  });
  map.on('overlayremove', function (e) {
    if (e.layer === linesGroup) {
      linesActive = false;
      linesGroup.clearLayers();
      updateLegend(false);
    }
  });
  map.on('moveend', scheduleLoad);

  // ---- Legende ----------------------------------------------------------
  function updateLegend(showScores) {
    var legend = document.getElementById('map-legend');
    if (!legend) {
      return;
    }
    while (legend.firstChild) {
      legend.removeChild(legend.firstChild);
    }
    if (!showScores) {
      legend.hidden = true;
      return;
    }
    var title = document.createElement('span');
    title.textContent = 'Untergrund (Ø):';
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
    var hint = document.createElement('span');
    hint.className = 'muted';
    hint.textContent = 'Linienbreite = Häufigkeit';
    legend.appendChild(hint);
    legend.hidden = false;
  }

  // ---- Dichte-Layer (leaflet.heat) --------------------------------------
  var heatLayer = null;
  var overlays = {};
  var heatBounds = [];

  function buildOverlayControl() {
    if (heatLayer) {
      overlays['Dichte (Heatmap)'] = heatLayer;
    }
    overlays['Strecken (gematcht)'] = linesGroup;
    L.control.layers(null, overlays, { collapsed: false }).addTo(map);
  }

  function finishSetup() {
    buildOverlayControl();
    if (heatLayer) {
      heatLayer.addTo(map); // Dichte als Default an.
    }
    if (heatBounds.length) {
      try { map.fitBounds(heatBounds, { padding: [30, 30] }); } catch (e) { /* default view */ }
    }
  }

  if (typeof L.heatLayer === 'function') {
    fetch(heatUrl, {
      headers: { Accept: 'application/json' },
      credentials: 'same-origin'
    })
      .then(function (res) {
        if (!res.ok) { throw new Error('HTTP ' + res.status); }
        return res.json();
      })
      .then(function (fc) {
        var features = (fc && fc.features) || [];
        var max = (fc.meta && fc.meta.max_weight) ? fc.meta.max_weight : 1;
        var points = [];
        features.forEach(function (f) {
          var c = f.geometry && f.geometry.coordinates;
          if (!Array.isArray(c) || c.length < 2) {
            return;
          }
          var lon = c[0], lat = c[1];
          var weight = (f.properties && f.properties.weight) || 0;
          points.push([lat, lon, max > 0 ? weight / max : 0]);
          heatBounds.push([lat, lon]);
        });
        if (points.length) {
          heatLayer = L.heatLayer(points, { radius: 25, blur: 18, maxZoom: 13 });
        }
        finishSetup();
      })
      .catch(finishSetup);
  } else {
    finishSetup();
  }
})();
