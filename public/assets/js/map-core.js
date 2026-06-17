/*
 * map-core.js — gemeinsame Leaflet-Grundlagen für alle Karten.
 *
 * CSP-konform: wird als externes 'self'-Script geladen (kein Inline,
 * kein eval). Konfiguration/Daten kommen über data-*-Attribute oder
 * same-origin-Fetches in den seitenspezifischen Modulen.
 *
 * Tile-Provider: OpenStreetMap-Raster. Tiles sind <img>-Elemente von
 * *.tile.openstreetmap.org — dafür ist img-src in der CSP erweitert.
 * Provider-Wechsel = TILE_URL hier + img-src-Eintrag + Attribution.
 */
(function () {
  'use strict';

  window.GE = window.GE || {};
  if (typeof window.L === 'undefined') {
    return; // Leaflet nicht geladen — Module degradieren still.
  }

  var TILE_URL = 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png';
  var TILE_ATTRIB =
    '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>-Mitwirkende';

  /**
   * Erzeugt eine Basiskarte mit OSM-Tiles im übergebenen Container.
   * Setzt einen sinnvollen Default-View (Mitteleuropa), bis die
   * jeweiligen Daten per fitBounds genauer einzoomen.
   */
  function createBaseMap(el, opts) {
    opts = opts || {};
    el.classList.remove('map-empty');
    var map = L.map(el, {
      zoomControl: true,
      scrollWheelZoom: opts.scrollWheelZoom !== false
    });
    L.tileLayer(TILE_URL, {
      maxZoom: 19,
      attribution: TILE_ATTRIB
    }).addTo(map);
    map.setView(
      opts.center || [51.0, 10.0],
      typeof opts.zoom === 'number' ? opts.zoom : 5
    );
    return map;
  }

  /** Zeigt im Karten-Container eine schlichte Hinweismeldung statt einer Karte. */
  function showEmpty(el, message) {
    el.classList.add('map-empty');
    el.textContent = message || 'Keine Geodaten verfügbar.';
  }

  function escapeHtml(value) {
    return String(value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  GE.map = {
    createBaseMap: createBaseMap,
    showEmpty: showEmpty,
    escapeHtml: escapeHtml,
    TILE_URL: TILE_URL,
    TILE_ATTRIB: TILE_ATTRIB
  };
})();
