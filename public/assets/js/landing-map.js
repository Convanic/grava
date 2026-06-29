/**
 * Landing Page Map Initialization
 * Shows game edges (conquered territories) around Waldkraiburg
 * Dynamically loads edges when user zooms or pans the map
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

        // Layer group to hold all edge polylines (for easy removal)
        let edgesLayer = L.layerGroup().addTo(map);
        let loadTimeout = null;

        // Function to load edges for current map bounds
        function loadEdges() {
            // Get current map bounds
            const bounds = map.getBounds();
            const bbox = [
                bounds.getWest(),  // minLon
                bounds.getSouth(), // minLat
                bounds.getEast(),  // maxLon
                bounds.getNorth()  // maxLat
            ].join(',');

            // Build API URL with bbox
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

                    // Clear existing edges
                    edgesLayer.clearLayers();

                    // Add new edges to map
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
                        });

                        // Add popup with owner info
                        if (ownerHandle) {
                            polyline.bindPopup('<strong>Erobert von:</strong> @' + ownerHandle);
                        }

                        // Add to layer group
                        edgesLayer.addLayer(polyline);
                    });
                })
                .catch(error => {
                    console.error('Error loading game edges:', error);
                });
        }

        // Load edges on map move/zoom with debouncing (wait 500ms after user stops moving)
        map.on('moveend', function() {
            // Clear previous timeout
            if (loadTimeout) {
                clearTimeout(loadTimeout);
            }

            // Set new timeout to load after 500ms
            loadTimeout = setTimeout(function() {
                loadEdges();
            }, 500);
        });

        // Initial load
        loadEdges();

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
