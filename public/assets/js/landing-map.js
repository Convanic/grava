/**
 * Landing Page Map Initialization
 * Shows game edges (conquered territories) around Waldkraiburg
 */

(function() {
    'use strict';

    // Wait for DOM and Leaflet to be ready
    if (typeof L === 'undefined') {
        console.error('Leaflet not loaded');
        return;
    }

    const mapContainer = document.getElementById('landing-map');
    if (!mapContainer) {
        return; // Map not on this page
    }

    // Get configuration from data attributes
    const edgesUrl = mapContainer.dataset.edgesUrl || '/api/v1/game/edges.geojson';
    const centerLat = parseFloat(mapContainer.dataset.centerLat) || 48.21;
    const centerLon = parseFloat(mapContainer.dataset.centerLon) || 12.40;
    const zoom = parseInt(mapContainer.dataset.zoom) || 11;

    // Initialize map
    const map = L.map('landing-map', {
        center: [centerLat, centerLon],
        zoom: zoom,
        zoomControl: true,
        scrollWheelZoom: false, // Disable scroll zoom for better UX on landing page
    });

    // Add OpenStreetMap tiles
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
        maxZoom: 19
    }).addTo(map);

    // Load and display game edges
    fetch(edgesUrl)
        .then(response => {
            if (!response.ok) {
                throw new Error('Failed to load game edges');
            }
            return response.json();
        })
        .then(geojson => {
            L.geoJSON(geojson, {
                style: function(feature) {
                    // Color edges based on ownership or default green
                    const props = feature.properties || {};
                    const color = props.color || '#2f5233'; // Default GRAVA green
                    const weight = props.weight || 4;

                    return {
                        color: color,
                        weight: weight,
                        opacity: 0.8,
                        lineCap: 'round',
                        lineJoin: 'round'
                    };
                },
                onEachFeature: function(feature, layer) {
                    // Optional: Add popups with edge information
                    if (feature.properties && feature.properties.owner_handle) {
                        layer.bindPopup(
                            '<strong>Erobert von:</strong> @' + feature.properties.owner_handle
                        );
                    }
                }
            }).addTo(map);
        })
        .catch(error => {
            console.error('Error loading game edges:', error);
            // Optionally show error message to user
        });

    // Enable scroll zoom on click (better mobile UX)
    map.on('click', function() {
        map.scrollWheelZoom.enable();
    });

    // Disable scroll zoom when mouse leaves map
    map.on('mouseout', function() {
        map.scrollWheelZoom.disable();
    });

})();
