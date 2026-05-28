/**
 * Prédictions d'Éclipses Solaires
 * Module d'interaction carte — circonstances locales au clic
 * 
 * Licence : GPL v3
 */

const Circumstances = (function () {
    'use strict';

    const API_BASE = '../api/index.php';

    // Cache des éléments de Bessel déjà chargés
    const besselianCache = {};

    /**
     * Charge les éléments de Bessel pour une éclipse donnée.
     */
    async function loadBesselian(eclipseId) {
        if (besselianCache[eclipseId]) {
            return besselianCache[eclipseId];
        }

        const resp = await fetch(`${API_BASE}?action=besselian&id=${eclipseId}`);
        const data = await resp.json();

        if (data.error) {
            console.warn('Pas d\'éléments de Bessel pour', eclipseId);
            return null;
        }

        besselianCache[eclipseId] = data;
        return data;
    }

    /**
     * Charge les métadonnées de l'éclipse (pour ΔT notamment).
     */
    async function loadEclipseDetail(eclipseId) {
        const resp = await fetch(`${API_BASE}?action=detail&id=${eclipseId}`);
        return resp.json();
    }

    /**
     * Calcule et affiche les circonstances locales pour un point donné.
     * 
     * @param {number} eclipseId - ID de l'éclipse active
     * @param {number} lat       - Latitude du clic
     * @param {number} lon       - Longitude du clic
     * @param {L.Map}  map       - Instance Leaflet
     * @param {object} eclipseInfo - Métadonnées de l'éclipse (de la liste)
     */
    async function showLocalCircumstances(eclipseId, lat, lon, map, eclipseInfo) {
        // Charger les éléments de Bessel
        const be = await loadBesselian(eclipseId);
        if (!be) return;

        // Charger les détails pour ΔT
        const detail = await loadEclipseDetail(eclipseId);
        const deltaT = parseFloat(detail.delta_t) || 0;

        // Ajouter ΔT à l'objet besselian pour tToUT
        be.delta_t = deltaT;

        // Calculer
        const result = Besselian.computeCircumstances(be, lat, lon);

        // Construire le contenu du popup
        let html = '';

        const latStr = Math.abs(lat).toFixed(4) + '°' + (lat >= 0 ? 'N' : 'S');
        const lonStr = Math.abs(lon).toFixed(4) + '°' + (lon >= 0 ? 'E' : 'W');
        html += `<div class="popup-coords">${latStr}, ${lonStr}</div>`;

        if (!result || result.magnitude <= 0) {
            html += `<div class="popup-no-eclipse">Éclipse non visible depuis ce point</div>`;
        } else if (result.sunAltitude < 0) {
            html += `<div class="popup-no-eclipse">Éclipse non visible depuis ce point</div>`;
        } else {
            const TYPE_LABELS = { T: 'Totale', A: 'Annulaire', H: 'Hybride', P: 'Partielle' };
            const typeLabel = TYPE_LABELS[result.localType] || result.localType;

            html += `<div class="popup-type popup-type-${result.localType}">Éclipse ${typeLabel.toLowerCase()}</div>`;

            html += '<table class="popup-table">';

            // Magnitude
            html += `<tr><td>Magnitude</td><td>${result.magnitude.toFixed(4)}</td></tr>`;

            // Obscuration
            html += `<tr><td>Obscuration</td><td>${(result.obscuration * 100).toFixed(1)}%</td></tr>`;

            // Contacts
            if (result.contacts.C1 !== undefined) {
                html += `<tr><td>C1 (début partielle)</td><td>${Besselian.tToUT(result.contacts.C1, be.t0, deltaT)} UT</td></tr>`;
            }
            if (result.contacts.C2 !== undefined) {
                html += `<tr><td>C2 (début ${result.localType === 'T' ? 'totalité' : 'annularité'})</td><td>${Besselian.tToUT(result.contacts.C2, be.t0, deltaT)} UT</td></tr>`;
            }
            if (result.contacts.C3 !== undefined) {
                html += `<tr><td>C3 (fin ${result.localType === 'T' ? 'totalité' : 'annularité'})</td><td>${Besselian.tToUT(result.contacts.C3, be.t0, deltaT)} UT</td></tr>`;
            }
            if (result.contacts.C4 !== undefined) {
                html += `<tr><td>C4 (fin partielle)</td><td>${Besselian.tToUT(result.contacts.C4, be.t0, deltaT)} UT</td></tr>`;
            }

            // Durée
            if (result.duration !== null) {
                html += `<tr><td>Durée ${result.localType === 'T' ? 'totalité' : 'annularité'}</td><td><strong>${Besselian.formatDuration(result.duration)}</strong></td></tr>`;
            }

            // Position du Soleil
            if (result.sunAltitude !== undefined) {
                html += `<tr><td>Altitude Soleil</td><td>${result.sunAltitude.toFixed(1)}°</td></tr>`;
                html += `<tr><td>Azimut Soleil</td><td>${result.sunAzimuth.toFixed(1)}°</td></tr>`;
            }

            html += '</table>';
        }

        // Afficher le popup Leaflet
        L.popup({
            maxWidth: 320,
            className: 'eclipse-popup',
        })
            .setLatLng([lat, lon])
            .setContent(html)
            .openOn(map);
    }

    return {
        showLocalCircumstances,
        loadBesselian,
    };

})();
