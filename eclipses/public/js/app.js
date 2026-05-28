/**
 * Prédictions d'Éclipses Solaires
 * Application principale
 * 
 * Licence : GPL v3
 */

(function () {
    'use strict';

    // --- Configuration ---
    const API_BASE = '../api/index.php';
    
    const COLORS = {
        T: { fill: 'rgba(220, 38, 38, 0.20)', stroke: '#dc2626', central: '#1a1f2e' },
        A: { fill: 'rgba(217, 119, 6, 0.15)', stroke: '#d97706', central: '#1a1f2e' },
        H: { fill: 'rgba(124, 58, 237, 0.15)', stroke: '#7c3aed', central: '#1a1f2e' },
    };

    const TYPE_LABELS = {
        T: 'Totale',
        A: 'Annulaire',
        H: 'Hybride',
        P: 'Partielle',
    };

    const MONTHS_FR = [
        'janvier', 'février', 'mars', 'avril', 'mai', 'juin',
        'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'
    ];

    // --- État global ---
    let allEclipses = [];
    let allCountries = [];  // liste des pays avec nombre d'éclipses
    let currentFilter = { country: '', includePartial: false };
    let activeEclipseId = null;
    let pathLayers = {};  // id → Leaflet layer group

    // --- Initialisation carte ---
    const map = L.map('map', {
        center: [20, 0],
        zoom: 2,
        minZoom: 2,
        maxZoom: 12,
        worldCopyJump: false,
        maxBounds: [[-90, -720], [90, 720]],
        maxBoundsViscosity: 1.0,
        attributionControl: false,
    });
    
    // Attribution sans le drapeau
    L.control.attribution({ prefix: '<a href="https://leafletjs.com">Leaflet</a>' }).addTo(map);

    // Tuiles OpenStreetMap standard (style Google Maps)
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
        maxZoom: 19,
    }).addTo(map);

    // --- Fonctions utilitaires ---

    function formatDate(dateStr) {
        const parts = dateStr.split('-');
        const day = parseInt(parts[2], 10);
        const month = MONTHS_FR[parseInt(parts[1], 10) - 1];
        const year = parts[0];
        return `${day} ${month} ${year}`;
    }

    function formatDuration(str) {
        if (!str) return '—';
        return str;
    }

    // --- Chargement des données ---

    async function loadEclipses() {
        try {
            const central = currentFilter.includePartial ? '0' : '1';
            const [eclResp, countResp] = await Promise.all([
                fetch(`${API_BASE}?action=list&central=${central}`),
                fetch(`${API_BASE}?action=countries`),
            ]);
            allEclipses = await eclResp.json();
            allCountries = await countResp.json();
            renderCountrySelects();
            renderList();
        } catch (err) {
            console.error('Erreur chargement éclipses:', err);
            document.getElementById('eclipse-list').innerHTML =
                '<div class="loading">Erreur de chargement</div>';
        }
    }

    /**
     * Corrige les coordonnées [lon, lat] pour maintenir la continuité
     * à travers l'antéméridien. Au lieu de couper, on décale les longitudes
     * au-delà de ±180° pour que Leaflet dessine la ligne sur la copie
     * adjacente du monde.
     * 
     * L'ombre d'une éclipse ne traverse l'antéméridien qu'une seule fois
     * (trajectoire ouest→est), donc un simple décalage cumulatif suffit.
     */
    function unwrapLongitudes(coords) {
        if (coords.length < 2) return coords.map(c => [...c]);
        
        const result = [[...coords[0]]];
        let offset = 0;
        
        for (let i = 1; i < coords.length; i++) {
            const prevLon = coords[i - 1][0];
            const curLon = coords[i][0];
            const diff = curLon - prevLon;
            
            if (diff > 180) {
                // Saut positif → on est passé de +180 à -180, décaler de -360
                offset -= 360;
            } else if (diff < -180) {
                // Saut négatif → on est passé de -180 à +180, décaler de +360
                offset += 360;
            }
            
            result.push([coords[i][0] + offset, coords[i][1]]);
        }
        
        return result;
    }

    /**
     * Crée une polyline sur 5 copies du monde, coupée à l'antéméridien.
     */
    function addContinuousLine(coords, style, layerGroup) {
        // Découper aux traversées de l'antéméridien
        const segments = [];
        let current = [coords[0]];
        
        for (let i = 1; i < coords.length; i++) {
            if (Math.abs(coords[i][0] - coords[i - 1][0]) > 180) {
                if (current.length >= 2) segments.push(current);
                current = [];
            }
            current.push(coords[i]);
        }
        if (current.length >= 2) segments.push(current);
        
        for (const seg of segments) {
            const latlngs = seg.map(c => [c[1], c[0]]);
            for (const off of [-720, -360, 0, 360, 720]) {
                const shifted = latlngs.map(ll => [ll[0], ll[1] + off]);
                L.polyline(shifted, style).addTo(layerGroup);
            }
        }
    }

    /**
     * Crée un polygone de bande avec continuité à l'antéméridien.
     */
    function addContinuousPolygon(northCoords, southCoords, style, layerGroup) {
        const len = Math.min(northCoords.length, southCoords.length);
        if (len < 2) return;
        
        // Détecter si la bande traverse l'antéméridien
        let crosses = false;
        for (let i = 1; i < len; i++) {
            if (Math.abs(northCoords[i][0] - northCoords[i-1][0]) > 180) {
                crosses = true;
                break;
            }
        }
        if (!crosses) {
            for (let i = 1; i < len; i++) {
                if (Math.abs(southCoords[i][0] - southCoords[i-1][0]) > 180) {
                    crosses = true;
                    break;
                }
            }
        }
        
        const northLL = [];
        const southLL = [];
        
        for (let i = 0; i < len; i++) {
            let nLon = northCoords[i][0];
            let sLon = southCoords[i][0];
            
            // Si traversée antéméridien, mettre tout dans [0, 360]
            if (crosses) {
                if (nLon < 0) nLon += 360;
                if (sLon < 0) sLon += 360;
            }
            
            northLL.push([northCoords[i][1], nLon]);
            southLL.push([southCoords[i][1], sLon]);
        }
        
        const polygon = northLL.concat(southLL.reverse());
        for (const off of [-720, -360, 0, 360, 720]) {
            const shifted = polygon.map(ll => [ll[0], ll[1] + off]);
            L.polygon(shifted, style).addTo(layerGroup);
        }
    }

    async function loadPath(eclipseId) {
        // Si déjà chargé, ne pas recharger
        if (pathLayers[eclipseId]) return;

        try {
            const resp = await fetch(`${API_BASE}?action=path&id=${eclipseId}`);
            const geojson = await resp.json();

            if (geojson.error) {
                console.warn('Pas de tracé pour', eclipseId);
                return;
            }

            const eclipse = allEclipses.find(e => e.id === eclipseId);
            const colors = COLORS[eclipse.eclipse_type] || COLORS.T;

            const layerGroup = L.featureGroup();
            
            // Extraire les coordonnées brutes pour le polygone
            let northCoords = null;
            let southCoords = null;

            // Parcourir les features
            geojson.features.forEach(feature => {
                const role = feature.properties.role;
                const coords = feature.geometry.coordinates;

                if (role === 'umbral_path') {
                    // Traité via nord/sud ci-dessous
                }
                else if (role === 'central_line') {
                    addContinuousLine(coords, {
                        color: colors.central,
                        weight: 1.5,
                        opacity: 0.7,
                        dashArray: '6, 4',
                    }, layerGroup);
                }
                else if (role === 'north_limit') {
                    northCoords = coords;
                    addContinuousLine(coords, {
                        color: colors.stroke,
                        weight: 1,
                        opacity: 0.4,
                    }, layerGroup);
                }
                else if (role === 'south_limit') {
                    southCoords = coords;
                    addContinuousLine(coords, {
                        color: colors.stroke,
                        weight: 1,
                        opacity: 0.4,
                    }, layerGroup);
                }
            });
            
            // Construire le polygone de bande avec continuité antéméridien
            if (northCoords && southCoords) {
                addContinuousPolygon(northCoords, southCoords, {
                    fillColor: colors.fill,
                    fillOpacity: 1,
                    color: colors.stroke,
                    weight: 0.5,
                    opacity: 0.3,
                }, layerGroup);
            }

            pathLayers[eclipseId] = layerGroup;
            layerGroup.addTo(map);

        } catch (err) {
            console.error('Erreur chargement tracé:', err);
        }
    }

    // --- Rendu de la liste ---

    function getFilteredEclipses() {
        return allEclipses.filter(e => {
            if (currentFilter.country !== '') {
                const hasCountry = e.countries && e.countries.some(c => c.code === currentFilter.country);
                if (!hasCountry) return false;
            }
            return true;
        });
    }

    function renderList() {
        const container = document.getElementById('eclipse-list');
        container.scrollTop = 0;
        const filtered = getFilteredEclipses();

        if (filtered.length === 0) {
            container.innerHTML = '<div class="loading">Aucune éclipse trouvée</div>';
            return;
        }

        container.innerHTML = filtered.map(e => {
            const isActive = e.id === activeEclipseId ? ' active' : '';
            const meta = [];
            if (e.central_duration) meta.push(e.central_duration);
            if (e.path_width_km) meta.push(`${e.path_width_km} km`);
            if (e.saros_number) meta.push(`Saros ${e.saros_number}`);
            
            const countriesStr = e.countries && e.countries.length > 0
                ? e.countries.map(c => c.name).join(', ')
                : '';

            return `
                <div class="eclipse-card${isActive}" data-id="${e.id}">
                    <div class="card-date">${formatDate(e.eclipse_date)}</div>
                    <div class="card-meta">
                        <span class="card-type" data-type="${e.eclipse_type}">${TYPE_LABELS[e.eclipse_type]}</span>
                        <span>${meta.join(' · ')}</span>
                    </div>
                    ${countriesStr ? `<div class="card-countries">${countriesStr}</div>` : ''}
                </div>
            `;
        }).join('');

        // Event listeners
        container.querySelectorAll('.eclipse-card').forEach(card => {
            card.addEventListener('click', () => {
                const id = parseInt(card.dataset.id, 10);
                selectEclipse(id);
            });
        });

        // Mettre à jour le select mobile aussi
        renderMobileSelect();
    }

    // --- Sélection d'une éclipse ---

    function selectEclipse(id) {
        // Désactiver l'ancien
        if (activeEclipseId !== null && pathLayers[activeEclipseId]) {
            map.removeLayer(pathLayers[activeEclipseId]);
        }
        
        // Fermer le popup de circonstances locales
        map.closePopup();

        activeEclipseId = id;
        renderList(); // mettre à jour la classe active

        const eclipse = allEclipses.find(e => e.id === id);
        if (!eclipse) return;

        // Charger le tracé de bande (centrales) et le contour de visibilité (toutes)
        loadPathAndVisibility(id, eclipse).then(() => {
            if (pathLayers[id]) {
                pathLayers[id].addTo(map);

                // Zoomer sur la bande (pas le contour de visibilité)
                const zoomTarget = pathLayers[id]._bandGroup && pathLayers[id]._bandGroup.getLayers().length > 0
                    ? pathLayers[id]._bandGroup
                    : pathLayers[id];
                const bounds = zoomTarget.getBounds();
                if (bounds.isValid()) {
                    map.fitBounds(bounds, { padding: [100, 100], maxZoom: 5 });
                }
            }
        });

        // Afficher le panneau d'info
        showInfo(eclipse);
        
        // Mettre à jour le titre de la page
        const typeLabel = TYPE_LABELS[eclipse.eclipse_type] || eclipse.eclipse_type;
        const countriesStr = eclipse.countries && eclipse.countries.length > 0
            ? ' — ' + eclipse.countries.map(c => c.name).join(', ')
            : '';
        document.title = `Éclipse ${typeLabel.toLowerCase()} du ${formatDate(eclipse.eclipse_date)}${countriesStr}`;
        
        // Mettre à jour l'URL sans recharger la page
        const newUrl = `eclipse-${eclipse.eclipse_date}/`;
        history.pushState({ eclipseDate: eclipse.eclipse_date }, document.title, newUrl);
    }
    
    // Gérer le bouton retour du navigateur
    window.addEventListener('popstate', function(e) {
        if (e.state && e.state.eclipseDate) {
            const eclipse = allEclipses.find(ec => ec.eclipse_date === e.state.eclipseDate);
            if (eclipse) {
                selectEclipse(eclipse.id);
            }
        }
    });
    
    // Mettre à jour l'URL avec la vue quand on déplace/zoome la carte
    map.on('moveend', function() {
        if (activeEclipseId === null) return;
        const eclipse = allEclipses.find(e => e.id === activeEclipseId);
        if (!eclipse) return;
        
        const center = map.getCenter();
        const zoom = map.getZoom();
        const viewStr = `${center.lat.toFixed(2)},${center.lng.toFixed(2)},${zoom}`;
        const newUrl = `eclipse-${eclipse.eclipse_date}/${viewStr}`;
        history.replaceState({ eclipseDate: eclipse.eclipse_date }, document.title, newUrl);
    });

    /**
     * Charge le tracé de bande (si central) ET calcule le contour de visibilité.
     */
    async function loadPathAndVisibility(eclipseId, eclipse) {
        if (pathLayers[eclipseId]) return;

        const layerGroup = L.featureGroup(); // tout
        const bandGroup = L.featureGroup();  // bande seulement (pour le zoom)
        const colors = COLORS[eclipse.eclipse_type] || COLORS.T;

        let crossesAntimeridian = false;

        // 1. Tracé de bande (centrales uniquement)
        if (eclipse.eclipse_type !== 'P') {
            try {
                const resp = await fetch(`${API_BASE}?action=path&id=${eclipseId}`);
                const geojson = await resp.json();

                if (!geojson.error && geojson.features) {
                    let northCoords = null;
                    let southCoords = null;

                    geojson.features.forEach(feature => {
                        const role = feature.properties.role;
                        const coords = feature.geometry.coordinates;

                        if (role === 'central_line') {
                            // Détecter si la ligne centrale traverse l'antéméridien
                            for (let i = 1; i < coords.length; i++) {
                                if (Math.abs(coords[i][0] - coords[i-1][0]) > 180) {
                                    crossesAntimeridian = true;
                                    break;
                                }
                            }
                            
                            addContinuousLine(coords, {
                                color: colors.central,
                                weight: 1.5,
                                opacity: 0.7,
                                dashArray: '6, 4',
                            }, bandGroup);
                        }
                        else if (role === 'north_limit') {
                            northCoords = coords;
                            addContinuousLine(coords, {
                                color: colors.stroke,
                                weight: 1,
                                opacity: 0.4,
                            }, bandGroup);
                        }
                        else if (role === 'south_limit') {
                            southCoords = coords;
                            addContinuousLine(coords, {
                                color: colors.stroke,
                                weight: 1,
                                opacity: 0.4,
                            }, bandGroup);
                        }
                    });

                    if (northCoords && southCoords) {
                        addContinuousPolygon(northCoords, southCoords, {
                            fillColor: colors.fill,
                            fillOpacity: 1,
                            color: colors.stroke,
                            weight: 0.5,
                            opacity: 0.3,
                        }, bandGroup);
                    }
                }
            } catch (err) {
                console.error('Erreur chargement tracé:', err);
            }
            
            bandGroup.addTo(layerGroup);
        }

        // 2. Contour de visibilité (toutes les éclipses)
        try {
            const be = await Circumstances.loadBesselian(eclipseId);
            if (be) {
                computeVisibilityContour(be, layerGroup, eclipse.eclipse_type, crossesAntimeridian);
            }
        } catch (err) {
            console.error('Erreur calcul contour visibilité:', err);
        }

        // Stocker le layerGroup complet et le bandGroup pour le zoom
        pathLayers[eclipseId] = layerGroup;
        pathLayers[eclipseId]._bandGroup = bandGroup;
    }

    /**
     * Trace le contour de visibilité (magnitude = 0) sous forme de polylignes.
     * Pour chaque longitude, on trouve par bisection les latitudes nord et sud
     * du bord de visibilité.
     */
    function computeVisibilityContour(be, layerGroup, eclipseType, crossesAntimeridian) {
        const lonStep = 2;
        const scanStep = 1;
        
        const color = eclipseType === 'P' ? '#94a3b8' : (COLORS[eclipseType]?.stroke || '#94a3b8');
        
        const borderPoints = [];
        
        // Scan vertical : pour chaque longitude, balayer en latitude
        for (let lon = -180; lon < 180; lon += lonStep) {
            let prevVisible = false;
            
            for (let lat = -85; lat <= 85; lat += scanStep) {
                const result = Besselian.computeCircumstances(be, lat, lon);
                const visible = result !== null && result.magnitude > 0 && result.sunAltitude >= 0;
                
                if (visible !== prevVisible) {
                    const exactLat = bisectLatitude(be, lon, lat - scanStep, lat);
                    if (exactLat !== null && exactLat > -80 && exactLat < 80) {
                        const adjustedLon = (crossesAntimeridian && lon < 0) ? lon + 360 : lon;
                        borderPoints.push({ lon: adjustedLon, lat: exactLat });
                    }
                }
                prevVisible = visible;
            }
        }
        
        // Scan horizontal : pour chaque latitude, balayer en longitude
        const hScanStep = 2;
        for (let lat = -80; lat <= 80; lat += hScanStep) {
            let prevVisible = false;
            
            for (let lon = -180; lon < 180; lon += lonStep) {
                const result = Besselian.computeCircumstances(be, lat, lon);
                const visible = result !== null && result.magnitude > 0 && result.sunAltitude >= 0;
                
                if (visible !== prevVisible) {
                    const exactLon = bisectLongitude(be, lat, lon - lonStep, lon);
                    if (exactLon !== null) {
                        const adjustedLon = (crossesAntimeridian && exactLon < 0) ? exactLon + 360 : exactLon;
                        borderPoints.push({ lon: adjustedLon, lat: lat });
                    }
                }
                prevVisible = visible;
            }
        }
        
        if (borderPoints.length < 3) return;
        
        // Trier tous les points par plus proche voisin et tracer
        const segments = buildSegmentsByProximity(borderPoints);
        
        const lineStyle = {
            color: color,
            weight: 1.5,
            opacity: 0.6,
            dashArray: '6, 4',
        };
        
        for (const seg of segments) {
            if (seg.length < 2) continue;
            const latlngs = seg.map(p => [p.lat, p.lon]);
            for (const off of [-720, -360, 0, 360, 720]) {
                const shifted = latlngs.map(ll => [ll[0], ll[1] + off]);
                L.polyline(shifted, lineStyle).addTo(layerGroup);
            }
        }
    }
    
    /**
     * Bisection pour trouver la latitude exacte de transition visible/invisible.
     */
    function bisectLatitude(be, lon, latA, latB) {
        const visA = isVisibleAt(be, latA, lon);
        const visB = isVisibleAt(be, latB, lon);
        
        if (visA === visB) return null;
        
        let a = latA, b = latB;
        for (let i = 0; i < 10; i++) {
            const mid = (a + b) / 2;
            if (isVisibleAt(be, mid, lon) === visA) {
                a = mid;
            } else {
                b = mid;
            }
        }
        return (a + b) / 2;
    }
    
    /**
     * Bisection pour trouver la longitude exacte de transition visible/invisible.
     */
    function bisectLongitude(be, lat, lonA, lonB) {
        const visA = isVisibleAt(be, lat, lonA);
        const visB = isVisibleAt(be, lat, lonB);
        
        if (visA === visB) return null;
        
        let a = lonA, b = lonB;
        for (let i = 0; i < 10; i++) {
            const mid = (a + b) / 2;
            if (isVisibleAt(be, lat, mid) === visA) {
                a = mid;
            } else {
                b = mid;
            }
        }
        return (a + b) / 2;
    }
    
    function isVisibleAt(be, lat, lon) {
        const result = Besselian.computeCircumstances(be, lat, lon);
        return result !== null && result.magnitude > 0 && result.sunAltitude >= 0;
    }
    
    /**
     * Regroupe des points en segments connexes par proximité.
     * Deux points consécutifs dans un segment sont à moins de maxDist degrés.
     */
    function buildSegments(points, be) {
        if (points.length < 2) return [];
        
        const sorted = [...points].sort((a, b) => a.lon - b.lon || a.lat - b.lat);
        
        const byLon = {};
        for (const p of sorted) {
            const key = p.lon;
            if (!byLon[key]) byLon[key] = [];
            byLon[key].push(p);
        }
        
        const northLine = [];
        const southLine = [];
        
        const lons = Object.keys(byLon).map(Number).sort((a, b) => a - b);
        
        for (const lon of lons) {
            const pts = byLon[lon].sort((a, b) => a.lat - b.lat);
            if (pts.length >= 2) {
                southLine.push(pts[0]);
                northLine.push(pts[pts.length - 1]);
            } else if (pts.length === 1) {
                const p = pts[0];
                // Utiliser la longitude originale (avant décalage antéméridien)
                const origLon = p.lon > 180 ? p.lon - 360 : p.lon;
                const aboveVisible = isVisibleAt(be, p.lat + 2, origLon);
                if (aboveVisible) {
                    southLine.push(p);
                } else {
                    northLine.push(p);
                }
            }
        }
        
        // Découper chaque ligne en sous-segments quand il y a un saut > 15° en latitude
        // (indique un passage par le pôle ou une discontinuité)
        const segments = [];
        
        function splitByLatJump(line) {
            if (line.length < 2) return;
            let current = [line[0]];
            for (let i = 1; i < line.length; i++) {
                const dLat = Math.abs(line[i].lat - line[i-1].lat);
                const dLon = Math.abs(line[i].lon - line[i-1].lon);
                if (dLat > 40 || dLon > 60) {
                    if (current.length >= 2) segments.push(current);
                    current = [line[i]];
                } else {
                    current.push(line[i]);
                }
            }
            if (current.length >= 2) segments.push(current);
        }
        
        splitByLatJump(northLine);
        splitByLatJump(southLine);
        
        return segments;
    }

    /**
     * Relie les points par plus proche voisin.
     * Coupe quand la distance dépasse un seuil.
     */
    function buildSegmentsByProximity(points) {
        if (points.length < 2) return [];
        
        const used = new Array(points.length).fill(false);
        const segments = [];
        const maxDist2 = 50 * 50;
        
        while (true) {
            let startIdx = -1;
            for (let i = 0; i < points.length; i++) {
                if (!used[i]) { startIdx = i; break; }
            }
            if (startIdx === -1) break;
            
            const seg = [points[startIdx]];
            used[startIdx] = true;
            let currentIdx = startIdx;
            
            while (true) {
                let bestIdx = -1;
                let bestDist = Infinity;
                
                for (let i = 0; i < points.length; i++) {
                    if (used[i]) continue;
                    const dlat = points[i].lat - points[currentIdx].lat;
                    const dlon = (points[i].lon - points[currentIdx].lon) * Math.cos(points[currentIdx].lat * Math.PI / 180);
                    const dist = dlat * dlat + dlon * dlon;
                    if (dist < bestDist) {
                        bestDist = dist;
                        bestIdx = i;
                    }
                }
                
                if (bestIdx === -1 || bestDist > maxDist2) break;
                
                seg.push(points[bestIdx]);
                used[bestIdx] = true;
                currentIdx = bestIdx;
            }
            
            if (seg.length >= 2) segments.push(seg);
        }
        
        return segments;
    }

    function showInfo(eclipse) {
        const panel = document.getElementById('eclipse-info');
        const title = document.getElementById('info-title');
        const content = document.getElementById('info-content');

        title.textContent = `Éclipse ${TYPE_LABELS[eclipse.eclipse_type].toLowerCase()} du ${formatDate(eclipse.eclipse_date)}`;

        const rows = [];
        
        rows.push({ label: 'Type', value: TYPE_LABELS[eclipse.eclipse_type] });
        rows.push({ label: 'Série de Saros', value: eclipse.saros_number || '—' });
        rows.push({ label: 'Magnitude', value: eclipse.magnitude?.toFixed(4) || '—' });
        rows.push({ label: 'Gamma', value: eclipse.gamma?.toFixed(4) || '—' });
        
        if (eclipse.central_duration) {
            rows.push({ label: 'Durée max.', value: eclipse.central_duration });
        }
        if (eclipse.path_width_km) {
            rows.push({ label: 'Largeur bande', value: `${eclipse.path_width_km} km` });
        }
        if (eclipse.latitude !== null && eclipse.longitude !== null) {
            const latStr = Math.abs(eclipse.latitude).toFixed(1) + '°' + (eclipse.latitude >= 0 ? 'N' : 'S');
            const lonStr = Math.abs(eclipse.longitude).toFixed(1) + '°' + (eclipse.longitude >= 0 ? 'E' : 'W');
            rows.push({ label: 'Plus grand éclipse', value: `${latStr}, ${lonStr}` });
        }
        if (eclipse.sun_altitude !== null) {
            rows.push({ label: 'Altitude Soleil', value: `${eclipse.sun_altitude}°` });
        }
        rows.push({ label: 'TDT', value: eclipse.td_greatest_eclipse?.substring(11) || '—' });

        content.innerHTML = rows.map(r =>
            `<div class="info-row">
                <span class="info-label">${r.label}</span>
                <span class="info-value">${r.value}</span>
            </div>`
        ).join('');

        panel.classList.remove('hidden');
    }

    // --- Event listeners ---

    // Fonction pour remplir les selects de pays (desktop + mobile)
    function renderCountrySelects() {
        const desktopSelect = document.getElementById('country-select');
        const mobileSelect = document.getElementById('mobile-country-select');
        
        let options = '<option value="">Tous les pays</option>';
        let mobileOptions = '<option value="">Tous pays</option>';
        
        allCountries.forEach(c => {
            options += `<option value="${c.country_code}">${c.country_name} (${c.eclipse_count})</option>`;
            mobileOptions += `<option value="${c.country_code}">${c.country_name} (${c.eclipse_count})</option>`;
        });
        
        if (desktopSelect) desktopSelect.innerHTML = options;
        if (mobileSelect) mobileSelect.innerHTML = mobileOptions;
    }

    // Filtre pays desktop
    const countrySelect = document.getElementById('country-select');
    if (countrySelect) {
        countrySelect.addEventListener('change', (e) => {
            currentFilter.country = e.target.value;

            if (activeEclipseId !== null && pathLayers[activeEclipseId]) {
                map.removeLayer(pathLayers[activeEclipseId]);
            }
            activeEclipseId = null;
            document.getElementById('eclipse-info').classList.add('hidden');

            renderList();
            
            // Synchroniser le select mobile
            const mobileSelect = document.getElementById('mobile-country-select');
            if (mobileSelect) mobileSelect.value = e.target.value;
        });
    }

    // Filtre pays mobile
    const mobileCountrySelect = document.getElementById('mobile-country-select');
    if (mobileCountrySelect) {
        mobileCountrySelect.addEventListener('change', (e) => {
            currentFilter.country = e.target.value;

            if (activeEclipseId !== null && pathLayers[activeEclipseId]) {
                map.removeLayer(pathLayers[activeEclipseId]);
            }
            activeEclipseId = null;
            document.getElementById('eclipse-info').classList.add('hidden');

            renderList();
            
            // Synchroniser le select desktop
            const desktopSelect = document.getElementById('country-select');
            if (desktopSelect) desktopSelect.value = e.target.value;
        });
    }

    // Case à cocher partielles
    const includePartialCheckbox = document.getElementById('include-partial');
    if (includePartialCheckbox) {
        includePartialCheckbox.addEventListener('change', (e) => {
            currentFilter.includePartial = e.target.checked;

            if (activeEclipseId !== null && pathLayers[activeEclipseId]) {
                map.removeLayer(pathLayers[activeEclipseId]);
            }
            activeEclipseId = null;
            document.getElementById('eclipse-info').classList.add('hidden');

            // Recharger les éclipses avec ou sans partielles
            loadEclipses();
        });
    }

    // Fermer le panneau info
    document.getElementById('info-close').addEventListener('click', () => {
        document.getElementById('eclipse-info').classList.add('hidden');
    });

    // Bouton partager
    document.getElementById('info-share').addEventListener('click', () => {
        const url = window.location.href;
        navigator.clipboard.writeText(url).then(() => {
            const toast = document.getElementById('share-toast');
            // Réinitialiser l'animation en retirant puis remettant l'élément
            toast.classList.add('hidden');
            void toast.offsetWidth; // force reflow
            toast.classList.remove('hidden');
            setTimeout(() => {
                toast.classList.add('hidden');
            }, 2000);
        });
    });

    // Clic sur la carte → circonstances locales (Phase B)
    map.on('click', function (e) {
        if (activeEclipseId === null) return; // pas d'éclipse sélectionnée

        const eclipse = allEclipses.find(ec => ec.id === activeEclipseId);
        if (!eclipse) return;

        Circumstances.showLocalCircumstances(
            activeEclipseId,
            e.latlng.lat,
            e.latlng.lng,
            map,
            eclipse
        );
    });

    // --- Mobile : select d'éclipses ---

    function renderMobileSelect() {
        const select = document.getElementById('mobile-eclipse-select');
        if (!select) return;

        const filtered = getFilteredEclipses();
        
        let html = '<option value="">— Choisir une éclipse —</option>';
        filtered.forEach(e => {
            const typeStr = TYPE_LABELS[e.eclipse_type];
            const selected = e.id === activeEclipseId ? ' selected' : '';
            html += `<option value="${e.id}"${selected}>${formatDate(e.eclipse_date)} — ${typeStr}</option>`;
        });
        
        select.innerHTML = html;
    }

    // Sélection d'éclipse via le select mobile
    const mobileEclipseSelect = document.getElementById('mobile-eclipse-select');
    if (mobileEclipseSelect) {
        mobileEclipseSelect.addEventListener('change', (e) => {
            const id = parseInt(e.target.value, 10);
            if (id > 0) {
                selectEclipse(id);
            }
        });
    }

    // --- Démarrage ---
    loadEclipses().then(() => {
        // Si une éclipse est spécifiée dans l'URL, la sélectionner
        if (window.ECLIPSE_PARAMS && window.ECLIPSE_PARAMS.date) {
            const targetDate = window.ECLIPSE_PARAMS.date;
            const eclipse = allEclipses.find(e => e.eclipse_date === targetDate);
            if (eclipse) {
                selectEclipse(eclipse.id);
                
                // Si une vue est spécifiée, l'appliquer après le chargement
                if (window.ECLIPSE_PARAMS.view) {
                    const parts = window.ECLIPSE_PARAMS.view.split(',');
                    if (parts.length >= 3) {
                        const lat = parseFloat(parts[0]);
                        const lon = parseFloat(parts[1]);
                        const zoom = parseInt(parts[2], 10);
                        if (!isNaN(lat) && !isNaN(lon) && !isNaN(zoom)) {
                            setTimeout(() => map.setView([lat, lon], zoom), 500);
                        }
                    }
                }
            }
        }
    });

})();
