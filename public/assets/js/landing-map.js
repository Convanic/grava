/**
 * Landing Page Map Initialization
 * Shows game edges (conquered territories) around Waldkraiburg
 */

(function() {
    'use strict';

    function initMap() {
        // Wait for Leaflet to be ready
        if (typeof L === 'undefined') {
            console.error('Leaflet not loaded');
            return;
        }

        const mapContainer = document.getElementById('landing-map');
        if (!mapContainer) {
            return; // Map not on this page
        }

        // Get configuration from data attributes
        const centerLat = parseFloat(mapContainer.dataset.centerLat) || 48.21;
        const centerLon = parseFloat(mapContainer.dataset.centerLon) || 12.40;
        const zoom = parseInt(mapContainer.dataset.zoom) || 11;
        const radiusKm = parseFloat(mapContainer.dataset.radiusKm) || 50;

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

        // Calculate bounding box for API request (radius around center)
        // Rough approximation: 1 degree lat ≈ 111km, 1 degree lon ≈ 111km * cos(lat)
        const latDelta = radiusKm / 111;
        const lonDelta = radiusKm / (111 * Math.cos(centerLat * Math.PI / 180));
        const bbox = [
            centerLon - lonDelta, // minLon
            centerLat - latDelta, // minLat
            centerLon + lonDelta, // maxLon
            centerLat + latDelta  // maxLat
        ].join(',');

        // Load and display game edges
        const edgesUrl = '/api/v1/game/edges?bbox=' + bbox + '&limit=5000';

        fetch(edgesUrl)
            .then(response => {
                if (!response.ok) {
                    throw new Error('Failed to load game edges');
                }
                return response.json();
            })
            .then(data => {
                const edges = data.edges || [];

                // Convert edges to GeoJSON and add to map
                edges.forEach(edge => {
                    if (!edge.geom || !edge.geom.coordinates) {
                        return;
                    }

                    // Determine color based on ownership
                    let color = '#2f5233'; // Default GRAVA green
                    let ownerHandle = null;

                    if (edge.owner && edge.owner.handle) {
                        ownerHandle = edge.owner.handle;
                        // Could vary color by crew/faction here if needed
                        color = edge.owner.crew_color || '#2f5233';
                    }

                    // Create Leaflet polyline
                    const latLngs = edge.geom.coordinates.map(coord => [coord[1], coord[0]]);
                    const polyline = L.polyline(latLngs, {
                        color: color,
                        weight: 4,
                        opacity: 0.8,
                        lineCap: 'round',
                        lineJoin: 'round'
                    }).addTo(map);

                    // Add popup with owner info
                    if (ownerHandle) {
                        polyline.bindPopup('<strong>Erobert von:</strong> @' + ownerHandle);
                    }
                });
            })
            .catch(error => {
                console.error('Error loading game edges:', error);
            });

        // Enable scroll zoom on click (better mobile UX)
        map.on('click', function() {
            map.scrollWheelZoom.enable();
        });

        // Disable scroll zoom when mouse leaves map
        map.on('mouseout', function() {
            map.scrollWheelZoom.disable();
        });
    }

    // Wait for DOM to be ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initMap);
    } else {
        initMap();
    }

})();
