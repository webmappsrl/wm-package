<!DOCTYPE html>
<html lang="it">

<head>
    <meta charset="utf-8">
    <base href="/" />
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Feature Collection Widget Map</title>
    <link rel="stylesheet" href="https://cdn.statically.io/gh/webmappsrl/feature-collection-widget-map/refs/heads/master/dist_20_08_2025/styles.css">
    <style>
        body {
            margin: 0;
            padding: 0;
            font-family: Arial, sans-serif;
        }

        .container {
            width: 100%;
            height: 100vh;
            position: relative;
        }

        feature-collection-widget-map {
            width: 100%;
            height: 100%;
            display: block;
        }

        /* Popup DEM styles */
        .dem-popup-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 9999;
            justify-content: center;
            align-items: center;
        }

        .dem-popup-overlay.active {
            display: flex;
        }

        .dem-popup {
            background: white;
            border-radius: 8px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.3);
            max-width: 500px;
            max-height: 80vh;
            overflow-y: auto;
            width: 90%;
        }

        .dem-popup-header {
            background: #2563eb;
            color: white;
            padding: 16px 20px;
            border-radius: 8px 8px 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .dem-popup-header h3 {
            margin: 0;
            font-size: 18px;
            font-weight: 600;
        }

        .dem-popup-close {
            background: none;
            border: none;
            color: white;
            font-size: 24px;
            cursor: pointer;
            padding: 0;
            line-height: 1;
        }

        .dem-popup-close:hover {
            opacity: 0.8;
        }

        .dem-popup-content {
            padding: 20px;
        }

        .dem-info-section {
            margin-bottom: 16px;
        }

        .dem-info-section:last-child {
            margin-bottom: 0;
        }

        .dem-info-section h4 {
            margin: 0 0 8px 0;
            font-size: 14px;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .dem-info-value {
            font-size: 24px;
            font-weight: 700;
            color: #1f2937;
        }

        .dem-info-unit {
            font-size: 14px;
            color: #6b7280;
            margin-left: 4px;
        }

        .dem-matrix-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }

        .dem-matrix-table th,
        .dem-matrix-table td {
            padding: 8px 10px;
            text-align: left;
            border-bottom: 1px solid #e5e7eb;
        }

        .dem-matrix-table th {
            background: #f9fafb;
            font-weight: 600;
            color: #374151;
        }

        .dem-matrix-table tr:hover {
            background: #f3f4f6;
        }

        .dem-no-data {
            color: #9ca3af;
            font-style: italic;
            text-align: center;
            padding: 20px;
        }
    </style>
</head>

<body>
    <div class="container">
        <feature-collection-widget-map
            geojsonurl="{{ $geojsonUrl }}"
            strokeColor="rgba(0, 0, 255, 1)"
            strokeWidth="3"
            pointStrokeColor="rgba(0, 0, 255, 1)"
            pointStrokeWidth="3"
            fillColor="rgba(0, 0, 255, 0.3)"
            showControlZoom="true"
            mouseWheelZoom="true"
            dragPan="true"
            padding="50">
        </feature-collection-widget-map>
    </div>

    <!-- DEM Popup -->
    <div class="dem-popup-overlay" id="demPopupOverlay">
        <div class="dem-popup">
            <div class="dem-popup-header">
                <h3 id="demPopupTitle">Informazioni Punto</h3>
                <button class="dem-popup-close" onclick="closeDemPopup()">&times;</button>
            </div>
            <div class="dem-popup-content" id="demPopupContent">
                <!-- Content will be injected by JavaScript -->
            </div>
        </div>
    </div>

    <!-- Intercept navigation BEFORE widget loads -->
    <script>
        // Store for popup features - populated after GeoJSON loads
        window.__popupFeatures = new Map();
        window.__geojsonData = null;

        // Function to check if we should intercept navigation to a popup feature
        window.__shouldInterceptNavigation = function(url) {
            if (!url || !window.__popupFeatures || window.__popupFeatures.size === 0) {
                return null;
            }

            const urlStr = String(url);
            // Check if URL matches a popup feature
            const matches = urlStr.match(/\/(\d+)\/?$/);
            if (matches) {
                const id = matches[1];
                if (window.__popupFeatures.has(id)) {
                    return window.__popupFeatures.get(id);
                }
            }
            return null;
        };

        // Intercept clicks on links before they navigate
        document.addEventListener('click', function(e) {
            const link = e.target.closest('a[href]');
            if (link) {
                const href = link.getAttribute('href') || link.href;
                const feature = window.__shouldInterceptNavigation(href);
                if (feature && typeof window.showDemPopup === 'function') {
                    e.preventDefault();
                    e.stopPropagation();
                    e.stopImmediatePropagation();
                    window.showDemPopup(feature);
                    return false;
                }
            }
        }, true); // Use capture phase to intercept before widget

        // Also intercept on parent window if we're in an iframe
        if (window.parent && window.parent !== window) {
            try {
                window.parent.document.addEventListener('click', function(e) {
                    const link = e.target.closest('a[href]');
                    if (link) {
                        const href = link.getAttribute('href') || link.href;
                        const feature = window.__shouldInterceptNavigation(href);
                        if (feature && typeof window.showDemPopup === 'function') {
                            e.preventDefault();
                            e.stopPropagation();
                            window.showDemPopup(feature);
                            return false;
                        }
                    }
                }, true);
            } catch (err) {
                // Cross-origin restriction, ignore
            }
        }
    </script>

    <!-- Script Angular -->
    <script src="https://cdn.statically.io/gh/webmappsrl/feature-collection-widget-map/refs/heads/master/dist_20_08_2025/runtime.js"></script>
    <script src="https://cdn.statically.io/gh/webmappsrl/feature-collection-widget-map/refs/heads/master/dist_20_08_2025/polyfills.js"></script>
    <script src="https://cdn.statically.io/gh/webmappsrl/feature-collection-widget-map/refs/heads/master/dist_20_08_2025/main.js"></script>

    <script>
        // Store GeoJSON data for popup access
        let geojsonData = null;
        let popupFeaturesMap = window.__popupFeatures; // Use global map for navigation interception

        // Fetch and store GeoJSON data
        fetch('{{ $geojsonUrl }}')
            .then(response => response.json())
            .then(data => {
                geojsonData = data;
                window.__geojsonData = data;
                buildPopupFeaturesMap();
                setupClickHandlers();
                console.log('GeoJSON loaded, popup features:', popupFeaturesMap.size);
            })
            .catch(err => console.error('Error loading GeoJSON:', err));

        function buildPopupFeaturesMap() {
            if (!geojsonData || !geojsonData.features) return;
            popupFeaturesMap.clear();
            geojsonData.features.forEach(feature => {
                if (feature.properties && feature.properties.clickAction === 'popup') {
                    popupFeaturesMap.set(String(feature.properties.id), feature);
                }
            });
        }

        function setupClickHandlers() {
            // Use MutationObserver to watch for tooltip/popup elements created by the widget
            const observer = new MutationObserver(function(mutations) {
                mutations.forEach(function(mutation) {
                    mutation.addedNodes.forEach(function(node) {
                        if (node.nodeType === 1) {
                            checkAndBindPopupToElement(node);
                        }
                    });
                });
            });
            observer.observe(document.body, {
                childList: true,
                subtree: true
            });

            // Intercept click events on the entire document (capture phase)
            document.addEventListener('click', function(e) {
                const target = e.target;

                // Check for link clicks
                if (target.tagName === 'A' || target.closest('a')) {
                    const link = target.tagName === 'A' ? target : target.closest('a');
                    const href = link.getAttribute('href');
                    if (href) {
                        const feature = findFeatureByLink(href);
                        if (feature && feature.properties.clickAction === 'popup') {
                            e.preventDefault();
                            e.stopPropagation();
                            showDemPopup(feature);
                            return false;
                        }
                    }
                }

                // Check data attributes
                const featureId = target.dataset?.featureId || target.closest('[data-feature-id]')?.dataset?.featureId;
                if (featureId && popupFeaturesMap.has(String(featureId))) {
                    e.preventDefault();
                    e.stopPropagation();
                    showDemPopup(popupFeaturesMap.get(String(featureId)));
                    return false;
                }
            }, true);

            // Override window.open
            const originalWindowOpen = window.open;
            window.open = function(url, target, features) {
                const feature = findFeatureByLink(url);
                if (feature && feature.properties.clickAction === 'popup') {
                    showDemPopup(feature);
                    return null;
                }
                return originalWindowOpen.call(window, url, target, features);
            };

            // Listen for custom events
            ['featureClick', 'wm-feature-click', 'feature-click'].forEach(eventName => {
                document.addEventListener(eventName, function(e) {
                    handleFeatureClick(e.detail);
                });
            });

            // Hook into widget component
            const widget = document.querySelector('feature-collection-widget-map');
            if (widget) {
                widget.addEventListener('featureClick', (e) => handleFeatureClick(e.detail));

                // Try to access the map click handler after widget initializes
                setTimeout(() => {
                    tryHookMapClick();
                }, 2000);
            }
        }

        // Try to hook into OpenLayers map click
        function tryHookMapClick() {
            // Look for OpenLayers map canvas
            const canvas = document.querySelector('canvas');
            if (!canvas) {
                console.log('No canvas found for map');
                return;
            }

            console.log('Found map canvas, setting up click handler');

            // Add click handler to canvas
            canvas.addEventListener('click', function(e) {
                // Get click coordinates relative to canvas
                const rect = canvas.getBoundingClientRect();
                const x = e.clientX - rect.left;
                const y = e.clientY - rect.top;

                console.log('Canvas click at:', x, y);

                // Try to find feature at click location
                // This requires access to the OpenLayers map instance
                // For now, we'll use a workaround: check if a tooltip appeared
                setTimeout(() => {
                    const tooltip = document.querySelector('.ol-overlay-container, .ol-popup, [class*="tooltip"], [class*="popup"]');
                    if (tooltip) {
                        console.log('Tooltip/popup found after click:', tooltip);
                        // Extract feature info from tooltip if possible
                        const tooltipText = tooltip.textContent;
                        // Try to match with our popup features
                        for (const [id, feature] of popupFeaturesMap) {
                            if (feature.properties.tooltip && tooltipText.includes(feature.properties.tooltip)) {
                                showDemPopup(feature);
                                break;
                            }
                            if (feature.properties.name && tooltipText.includes(feature.properties.name)) {
                                showDemPopup(feature);
                                break;
                            }
                        }
                    }
                }, 100);
            });
        }

        function checkAndBindPopupToElement(element) {
            // Check if this element contains a link to a popup feature
            const links = element.querySelectorAll ? element.querySelectorAll('a[href]') : [];
            links.forEach(link => {
                const feature = findFeatureByLink(link.href);
                if (feature && feature.properties.clickAction === 'popup') {
                    link.addEventListener('click', function(e) {
                        e.preventDefault();
                        e.stopPropagation();
                        showDemPopup(feature);
                    });
                }
            });
        }

        function findFeatureById(id) {
            if (!geojsonData || !geojsonData.features) return null;
            return geojsonData.features.find(f => f.properties && String(f.properties.id) === String(id));
        }

        function findFeatureByLink(url) {
            if (!geojsonData || !geojsonData.features || !url) return null;

            // Extract potential IDs from the URL
            // Common patterns: /resources/poles/123, /poles/123, etc.
            const matches = url.match(/\/(\d+)\/?$/);
            if (matches) {
                const id = matches[1];
                const feature = popupFeaturesMap.get(id);
                if (feature) return feature;
            }

            // Also check by full link match
            return geojsonData.features.find(f =>
                f.properties &&
                f.properties.clickAction === 'popup' &&
                url.includes(String(f.properties.id))
            );
        }

        function handleFeatureClick(feature) {
            if (!feature || !feature.properties) return;

            if (feature.properties.clickAction === 'popup') {
                showDemPopup(feature);
            } else if (feature.properties.link) {
                window.location.href = feature.properties.link;
            }
        }

        function showDemPopup(feature) {
            console.log('Opening popup for feature:', feature);
            const props = feature.properties;
            const dem = props.dem || {};

            // Set title
            document.getElementById('demPopupTitle').textContent = props.name || props.tooltip || 'Punto ' + props.id;

            // Build content
            let content = '';

            // Elevation section
            if (dem.elevation !== undefined) {
                content += `
                    <div class="dem-info-section">
                        <h4>Elevazione</h4>
                        <div class="dem-info-value">${dem.elevation}<span class="dem-info-unit">m s.l.m.</span></div>
                    </div>
                `;
            }

            // Matrix data section
            if (dem.matrix_row && Object.keys(dem.matrix_row).length > 0) {
                content += `
                    <div class="dem-info-section">
                        <h4>Distanze e Tempi verso altri punti</h4>
                        <table class="dem-matrix-table">
                            <thead>
                                <tr>
                                    <th>Destinazione</th>
                                    <th>Distanza</th>
                                    <th>Tempo (hiking)</th>
                                    <th>Dislivello</th>
                                </tr>
                            </thead>
                            <tbody>
                `;

                for (const [targetId, data] of Object.entries(dem.matrix_row)) {
                    const targetFeature = findFeatureById(targetId);
                    const targetName = targetFeature ? (targetFeature.properties.name || targetFeature.properties.tooltip || 'Punto ' + targetId) : 'Punto ' + targetId;

                    const distance = data.distance ? (data.distance / 1000).toFixed(2) + ' km' : '-';
                    const timeHiking = data.time_hiking ? formatTime(data.time_hiking) : '-';
                    const ascent = data.ascent || 0;
                    const descent = data.descent || 0;
                    const elevation = ascent > 0 ? `+${ascent}m` : (descent > 0 ? `-${descent}m` : '0m');

                    content += `
                        <tr>
                            <td>${targetName}</td>
                            <td>${distance}</td>
                            <td>${timeHiking}</td>
                            <td>${elevation}</td>
                        </tr>
                    `;
                }

                content += `
                            </tbody>
                        </table>
                    </div>
                `;
            }

            if (!content) {
                content = '<div class="dem-no-data">Nessun dato DEM disponibile per questo punto.</div>';
            }

            document.getElementById('demPopupContent').innerHTML = content;
            document.getElementById('demPopupOverlay').classList.add('active');
        }

        function closeDemPopup() {
            document.getElementById('demPopupOverlay').classList.remove('active');
        }

        function formatTime(seconds) {
            const hours = Math.floor(seconds / 3600);
            const minutes = Math.floor((seconds % 3600) / 60);
            if (hours > 0) {
                return `${hours}h ${minutes}min`;
            }
            return `${minutes} min`;
        }

        // Close popup on overlay click
        document.getElementById('demPopupOverlay').addEventListener('click', function(e) {
            if (e.target === this) {
                closeDemPopup();
            }
        });

        // Close popup on Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeDemPopup();
            }
        });

        // Expose functions globally for widget integration and navigation interception
        window.showDemPopup = showDemPopup;
        window.showDemPopupForFeature = function(featureId) {
            const feature = findFeatureById(featureId);
            if (feature) {
                showDemPopup(feature);
            }
        };

        // Test function - can be called from console: testPopup()
        window.testPopup = function() {
            if (popupFeaturesMap.size > 0) {
                const firstFeature = popupFeaturesMap.values().next().value;
                showDemPopup(firstFeature);
            } else {
                console.log('No popup features found. Check if GeoJSON has features with clickAction: popup');
            }
        };
    </script>
</body>

</html>