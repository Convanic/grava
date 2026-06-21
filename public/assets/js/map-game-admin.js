/*
 * map-game-admin.js — Regions-Übersichtskarte des Game-Admin-Dashboards.
 *
 * Lädt Kanten als GeoJSON BBox-getrieben (`data-edges-url?bbox=…`) und färbt
 * sie nach Wert / Frische / Owner (Fahrer) / Crew / Fraktion ein. Klick auf eine Kante öffnet den
 * Inspector (`data-edge-base` + id). CSP-konform: externes 'self'-Script,
 * same-origin-Fetch, keine Inline-Handler.
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

  var edgesUrl = el.getAttribute('data-edges-url') || '/admin/game/edges.geojson';
  var edgeBase = el.getAttribute('data-edge-base') || '/admin/game/edge/';

  // Sequentielle Wert-Skala (hell -> kräftig) und Frische-Skala (rot -> grün).
  var VALUE_COLORS = ['#dcfce7', '#86efac', '#4ade80', '#22c55e', '#15803d'];
  var FRESH_COLORS = ['#b91c1c', '#f97316', '#eab308', '#84cc16', '#15803d'];
  var FRESH_LABELS = ['sehr alt', 'alt', 'mittel', 'frisch', 'sehr frisch'];
  // Kategoriale Palette (10 gut unterscheidbare Töne) für Owner/Crew.
  var CATEGORY_COLORS = [
    '#2563eb', '#dc2626', '#16a34a', '#d97706', '#7c3aed',
    '#db2777', '#0891b2', '#65a30d', '#ea580c', '#4f46e5'
  ];
  var NO_OWNER = '#94a3b8';

  var state = { mode: 'value', maxValue: 1, layer: null };

  function clampIdx(i) { return Math.max(0, Math.min(4, i)); }

  function colorForValue(v) {
    var ratio = state.maxValue > 0 ? (v || 0) / state.maxValue : 0;
    return VALUE_COLORS[clampIdx(Math.round(ratio * 4))];
  }
  function colorForFreshness(f) {
    return FRESH_COLORS[clampIdx(Math.round((f || 0) * 4))];
  }
  function colorForCategory(id) {
    if (id === null || typeof id === 'undefined') {
      return NO_OWNER;
    }
    return CATEGORY_COLORS[Math.abs(id) % CATEGORY_COLORS.length];
  }
  function colorForFaction(hex) {
    return hex ? hex : NO_OWNER;
  }

  function colorFor(p) {
    if (state.mode === 'freshness') { return colorForFreshness(p.freshness); }
    if (state.mode === 'owner') { return colorForCategory(p.rider_id); }
    if (state.mode === 'crew') { return colorForCategory(p.crew_id); }
    if (state.mode === 'faction') { return colorForFaction(p.faction_color); }
    return colorForValue(p.value);
  }

  function styleFn(feature) {
    var p = (feature && feature.properties) || {};
    return {
      color: colorFor(p),
      weight: 4,
      opacity: 0.9,
      lineCap: 'round',
      lineJoin: 'round'
    };
  }

  function tooltipHtml(p) {
    var esc = GE.map.escapeHtml;
    var lines = [];
    lines.push('Kante <strong>#' + esc(p.id) + '</strong>');
    lines.push('Owner: ' + (p.owner_handle ? '@' + esc(p.owner_handle) : '—'));
    lines.push('Erstfahrer: ' + (p.rider_handle ? '@' + esc(p.rider_handle) : '—'));
    lines.push('Crew: ' + (p.crew_name ? esc(p.crew_name) : '—'));
    lines.push('Fraktion: ' + (p.faction_key ? esc(p.faction_key) : '—'));
    lines.push('Wert: <strong>' + esc((p.value || 0).toFixed ? p.value.toFixed(1) : p.value) + '</strong>');
    lines.push('Frische: ' + esc((typeof p.freshness === 'number' ? p.freshness.toFixed(2) : p.freshness)));
    lines.push('Fahrer: ' + esc(p.riders || 0) + ' · ' + esc(p.length_m || 0) + ' m');
    if (p.surface) { lines.push('Belag: ' + esc(p.surface)); }
    return lines.join('<br>');
  }

  var map = GE.map.createBaseMap(el);

  function onEachFeature(feature, layer) {
    var p = (feature && feature.properties) || {};
    layer.bindTooltip(tooltipHtml(p), { sticky: true });
    layer.on('click', function () {
      if (p.id) { window.location.href = edgeBase + encodeURIComponent(p.id); }
    });
  }

  function render(fc) {
    if (state.layer) {
      map.removeLayer(state.layer);
      state.layer = null;
    }
    state.maxValue = (fc && fc.meta && fc.meta.max_value) || 1;
    state.layer = L.geoJSON(fc, { style: styleFn, onEachFeature: onEachFeature });
    state.layer.addTo(map);
    updateLegend(fc && fc.meta ? fc.meta.count : 0);
  }

  function restyle() {
    if (state.layer) {
      state.layer.setStyle(styleFn);
    }
    updateLegend(state.layer ? state.layer.getLayers().length : 0);
  }

  // ---- Daten laden (BBox-getrieben, entprellt) --------------------------
  var lastBboxKey = '';
  var debounceTimer = null;
  var firstLoad = true;

  function bboxParam() {
    var b = map.getBounds();
    return [b.getWest(), b.getSouth(), b.getEast(), b.getNorth()]
      .map(function (n) { return n.toFixed(5); })
      .join(',');
  }

  function load(url) {
    fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
      .then(function (res) { if (!res.ok) { throw new Error('HTTP ' + res.status); } return res.json(); })
      .then(function (fc) {
        render(fc);
        if (firstLoad) {
          firstLoad = false;
          var bb = fc && fc.meta && fc.meta.bbox;
          if (bb && bb.length === 4) {
            try {
              map.fitBounds([[bb[1], bb[0]], [bb[3], bb[2]]], { padding: [30, 30] });
            } catch (e) { /* default view bleibt */ }
          }
        }
      })
      .catch(function () { /* still degradieren */ });
  }

  function loadViewport() {
    var key = bboxParam();
    if (key === lastBboxKey) { return; }
    lastBboxKey = key;
    load(edgesUrl + '?bbox=' + encodeURIComponent(key));
  }

  function scheduleLoad() {
    if (debounceTimer) { window.clearTimeout(debounceTimer); }
    debounceTimer = window.setTimeout(loadViewport, 350);
  }

  // ---- Legende ----------------------------------------------------------
  function legendItem(color, label) {
    var item = document.createElement('span');
    var sw = document.createElement('span');
    sw.className = 'swatch';
    sw.style.background = color;
    item.appendChild(sw);
    item.appendChild(document.createTextNode(label));
    return item;
  }

  function updateLegend(count) {
    var legend = document.getElementById('map-legend');
    if (!legend) { return; }
    while (legend.firstChild) { legend.removeChild(legend.firstChild); }

    var title = document.createElement('span');
    if (state.mode === 'freshness') {
      title.textContent = 'Frische:';
      legend.appendChild(title);
      FRESH_COLORS.forEach(function (c, i) { legend.appendChild(legendItem(c, FRESH_LABELS[i])); });
    } else if (state.mode === 'owner') {
      title.textContent = 'Owner (Fahrer):';
      legend.appendChild(title);
      legend.appendChild(legendItem(CATEGORY_COLORS[0], 'Farbe je Fahrer'));
      legend.appendChild(legendItem(NO_OWNER, 'niemand'));
    } else if (state.mode === 'crew') {
      title.textContent = 'Crew:';
      legend.appendChild(title);
      legend.appendChild(legendItem(CATEGORY_COLORS[0], 'Farbe je Crew'));
      legend.appendChild(legendItem(NO_OWNER, 'solo / keine Crew'));
    } else if (state.mode === 'faction') {
      title.textContent = 'Fraktion:';
      legend.appendChild(title);
      legend.appendChild(legendItem('#2EA043', 'Grün'));
      legend.appendChild(legendItem('#1F6FEB', 'Blau'));
      legend.appendChild(legendItem(NO_OWNER, 'keine Fraktion'));
    } else {
      title.textContent = 'Wert (rel. zum Ausschnitt):';
      legend.appendChild(title);
      legend.appendChild(legendItem(VALUE_COLORS[0], 'niedrig'));
      legend.appendChild(legendItem(VALUE_COLORS[4], 'hoch'));
    }
    var hint = document.createElement('span');
    hint.className = 'muted';
    hint.textContent = (count || 0) + ' Kanten · Klick → Inspector';
    legend.appendChild(hint);
    legend.hidden = false;
  }

  // ---- Farbmodus-Umschalter ---------------------------------------------
  var select = document.getElementById('game-map-color');
  if (select) {
    select.addEventListener('change', function () {
      state.mode = select.value || 'value';
      restyle();
    });
  }

  map.on('moveend', scheduleLoad);

  // Erstes Laden ohne BBox: alle (gedeckelten) Kanten -> fitBounds.
  load(edgesUrl);
})();
