/**
 * Prédictions d'Éclipses Solaires
 * Module de calcul des circonstances locales (éléments de Bessel)
 * 
 * Référence : Jean Meeus, "Astronomical Algorithms", 2nd ed.
 *             Explanatory Supplement to the Astronomical Almanac, 3rd ed.
 *             NASA/Espenak — Besselian elements
 * 
 * Licence : GPL v3
 */

const Besselian = (function () {
    'use strict';

    const DEG2RAD = Math.PI / 180;
    const RAD2DEG = 180 / Math.PI;

    // Aplatissement terrestre (WGS 84)
    const FLATTENING = 1 / 298.257223563;
    const E2 = 2 * FLATTENING - FLATTENING * FLATTENING;

    /**
     * Évalue un polynôme d'ordre 3 : a0 + a1*t + a2*t² + a3*t³
     */
    function poly(a0, a1, a2, a3, t) {
        return a0 + t * (a1 + t * (a2 + t * a3));
    }

    /**
     * Évalue tous les éléments de Bessel pour un instant t (heures depuis t0).
     * `be` est l'objet des éléments de Bessel depuis la base de données.
     */
    function evalElements(be, t) {
        return {
            x:   poly(be.x0,   be.x1,   be.x2,   be.x3,   t),
            y:   poly(be.y0,   be.y1,   be.y2,   be.y3,   t),
            d:   poly(be.d0,   be.d1,   be.d2,   be.d3,   t) * DEG2RAD, // en radians
            l1:  poly(be.l1_0, be.l1_1, be.l1_2, be.l1_3, t),
            l2:  poly(be.l2_0, be.l2_1, be.l2_2, be.l2_3, t),
            mu:  poly(be.mu0,  be.mu1,  be.mu2,  be.mu3,  t) * DEG2RAD, // en radians
        };
    }

    /**
     * Dérivées des éléments de Bessel par rapport à t.
     */
    function evalDerivatives(be, t) {
        return {
            dx:  be.x1 + t * (2 * be.x2 + 3 * be.x3 * t),
            dy:  be.y1 + t * (2 * be.y2 + 3 * be.y3 * t),
            dd:  (be.d1 + t * (2 * be.d2 + 3 * be.d3 * t)) * DEG2RAD,
            dmu: (be.mu1 + t * (2 * be.mu2 + 3 * be.mu3 * t)) * DEG2RAD,
        };
    }

    /**
     * Convertit les coordonnées géodésiques (lat, lon en degrés)
     * en coordonnées géocentriques (rho sin phi', rho cos phi').
     * 
     * L'aplatissement terrestre fait que la latitude géocentrique
     * diffère de la latitude géodésique.
     */
    function geocentricCoords(latDeg) {
        const lat = latDeg * DEG2RAD;
        const sinLat = Math.sin(lat);
        const cosLat = Math.cos(lat);

        // Rayon géocentrique et latitude géocentrique
        const u = Math.atan((1 - FLATTENING) * sinLat / cosLat);
        const rhoSinPhiPrime = (1 - FLATTENING) * Math.sin(u);
        const rhoCosPhiPrime = Math.cos(u);

        return { rhoSinPhiPrime, rhoCosPhiPrime };
    }

    /**
     * Calcule la position de l'observateur dans le plan fondamental de Bessel.
     * 
     * Retourne { xi, eta, zeta } — coordonnées de l'observateur
     * dans le plan fondamental.
     * 
     * @param {number} latDeg - Latitude géodésique en degrés
     * @param {number} lonDeg - Longitude en degrés (est positif)
     * @param {object} elem  - Éléments de Bessel évalués pour l'instant t
     */
    function observerCoords(latDeg, lonDeg, elem) {
        const { rhoSinPhiPrime, rhoCosPhiPrime } = geocentricCoords(latDeg);
        const lon = lonDeg * DEG2RAD;

        // Angle horaire local
        // Convention Bessel : μ est le GAST, longitude OUEST positive
        // Notre convention : longitude EST positive
        // Donc θ = μ + lon (et non μ - lon)
        const theta = elem.mu + lon;

        const sinD = Math.sin(elem.d);
        const cosD = Math.cos(elem.d);
        const sinTheta = Math.sin(theta);
        const cosTheta = Math.cos(theta);

        const xi = rhoCosPhiPrime * sinTheta;
        const eta = rhoSinPhiPrime * cosD - rhoCosPhiPrime * cosTheta * sinD;
        const zeta = rhoSinPhiPrime * sinD + rhoCosPhiPrime * cosTheta * cosD;

        return { xi, eta, zeta };
    }

    /**
     * Dérivées de xi et eta par rapport à t.
     */
    function observerDerivatives(latDeg, lonDeg, elem, deriv) {
        const { rhoSinPhiPrime, rhoCosPhiPrime } = geocentricCoords(latDeg);
        const lon = lonDeg * DEG2RAD;
        const theta = elem.mu + lon;

        const sinD = Math.sin(elem.d);
        const cosD = Math.cos(elem.d);
        const sinTheta = Math.sin(theta);
        const cosTheta = Math.cos(theta);

        const dxi = deriv.dmu * rhoCosPhiPrime * cosTheta;
        const deta = deriv.dd * (- rhoSinPhiPrime * sinD - rhoCosPhiPrime * cosTheta * cosD)
                   + deriv.dmu * rhoCosPhiPrime * sinTheta * sinD;

        return { dxi, deta };
    }

    /**
     * Calcule les circonstances locales d'une éclipse pour un observateur donné.
     * 
     * @param {object} be     - Éléments de Bessel (de la DB)
     * @param {number} latDeg - Latitude en degrés
     * @param {number} lonDeg - Longitude en degrés
     * @returns {object|null} - Circonstances locales ou null si pas d'éclipse visible
     */
    function computeCircumstances(be, latDeg, lonDeg) {
        const t0 = be.t0;
        const tanF1 = be.tan_f1;
        const tanF2 = be.tan_f2;

        // --- Recherche du maximum de l'éclipse ---
        // On cherche l'instant t où la distance Δ entre l'axe de l'ombre
        // et l'observateur est minimale.

        let tMax = 0; // première estimation : t = t0

        // Itération de Newton-Raphson pour trouver le minimum de Δ²
        for (let iter = 0; iter < 20; iter++) {
            const elem = evalElements(be, tMax);
            const deriv = evalDerivatives(be, tMax);
            const obs = observerCoords(latDeg, lonDeg, elem);
            const dobs = observerDerivatives(latDeg, lonDeg, elem, deriv);

            const u = elem.x - obs.xi;
            const v = elem.y - obs.eta;
            const du = deriv.dx - dobs.dxi;
            const dv = deriv.dy - dobs.deta;

            // Minimiser Δ² = u² + v²
            // d(Δ²)/dt = 2(u·du + v·dv) = 0
            // Newton : t_new = t - f/f', avec f = u·du + v·dv
            const f = u * du + v * dv;
            const fp = du * du + dv * dv + u * (2 * be.x2 + 6 * be.x3 * tMax)
                     + v * (2 * be.y2 + 6 * be.y3 * tMax);

            if (Math.abs(fp) < 1e-15) break;

            const dt = f / fp;
            tMax -= dt;

            if (Math.abs(dt) < 1e-8) break;
        }

        // --- Évaluer au maximum ---
        const elemMax = evalElements(be, tMax);
        const obsMax = observerCoords(latDeg, lonDeg, elemMax);

        const uMax = elemMax.x - obsMax.xi;
        const vMax = elemMax.y - obsMax.eta;
        const deltaMax = Math.sqrt(uMax * uMax + vMax * vMax);

        // Correction de l1 et l2 pour l'altitude de l'observateur
        const l1Max = elemMax.l1 - obsMax.zeta * tanF1;
        const l2Max = elemMax.l2 - obsMax.zeta * tanF2;

        // L'observateur est-il dans la pénombre ?
        if (deltaMax >= l1Max) {
            return null; // Pas d'éclipse visible
        }

        // --- Magnitude et obscuration ---
        const magnitude = (l1Max - deltaMax) / (l1Max + l2Max);

        // Type local
        let localType;
        if (deltaMax < Math.abs(l2Max)) {
            localType = l2Max < 0 ? 'T' : 'A'; // l2 < 0 → totalité, l2 > 0 → annularité
        } else {
            localType = 'P';
        }

        // Obscuration (fraction de surface)
        let obscuration;
        if (localType === 'T') {
            obscuration = 1.0;
        } else {
            const p = (l1Max - deltaMax) / (l1Max - l2Max);
            // Formule approchée pour l'obscuration de surface
            if (p <= 0) {
                obscuration = 0;
            } else if (p >= 1) {
                obscuration = 1;
            } else {
                // Relation magnitude → obscuration pour deux disques
                const m = magnitude;
                if (m <= 0) {
                    obscuration = 0;
                } else if (m >= 1) {
                    obscuration = 1;
                } else {
                    // Formule exacte pour l'intersection de deux disques de même taille
                    // A = 2r² * arccos(d/2r) - (d/2)*sqrt(4r²-d²)
                    // Simplifié pour des disques de tailles proches :
                    obscuration = 1 - Math.sqrt(1 - m) * (1 - m);
                    // Meilleure approximation polynomiale :
                    const m2 = m * m;
                    obscuration = m2 * (3 - 2 * m);
                }
            }
        }

        // --- Recherche des contacts ---
        // C1 : entrée dans la pénombre (Δ = l1, Δ décroit)
        // C2 : entrée dans l'ombre (Δ = |l2|, Δ décroit) — si T ou A
        // C3 : sortie de l'ombre (Δ = |l2|, Δ croit) — si T ou A
        // C4 : sortie de la pénombre (Δ = l1, Δ croit)

        const contacts = {};

        // C1 et C4 : contact avec la pénombre
        const c1c4 = findContacts(be, latDeg, lonDeg, tMax, tanF1, true);
        if (c1c4) {
            contacts.C1 = c1c4.first;
            contacts.C4 = c1c4.second;
        }

        // C2 et C3 : contact avec l'ombre (seulement si T ou A)
        if (localType === 'T' || localType === 'A') {
            const c2c3 = findContacts(be, latDeg, lonDeg, tMax, tanF2, false);
            if (c2c3) {
                contacts.C2 = c2c3.first;
                contacts.C3 = c2c3.second;
            }
        }

        // --- Durée de totalité/annularité ---
        let duration = null;
        if (contacts.C2 !== undefined && contacts.C3 !== undefined) {
            duration = (contacts.C3 - contacts.C2) * 3600; // en secondes
        }

        // --- Altitude et azimut du Soleil au maximum ---
        const sunPos = solarPosition(latDeg, lonDeg, elemMax);

        // --- Conversion des temps en UT ---
        // Les temps sont en heures TDT depuis t0
        // UT = TDT - ΔT (ΔT est en secondes dans la DB)
        const deltaT = (be.delta_t || 0) / 3600; // ΔT en heures — sera ajouté au niveau de l'affichage

        return {
            tMax: tMax,
            t0: t0,
            magnitude: magnitude,
            obscuration: obscuration,
            localType: localType,
            contacts: contacts,
            duration: duration,
            sunAltitude: sunPos.altitude,
            sunAzimuth: sunPos.azimuth,
        };
    }

    /**
     * Recherche les instants de contact (entrée et sortie) pour un rayon donné.
     * 
     * @param {boolean} isPenumbra - true pour l1 (pénombre), false pour l2 (ombre)
     */
    function findContacts(be, latDeg, lonDeg, tMax, tanF, isPenumbra) {
        // Estimation initiale : on utilise la vitesse relative au maximum
        // pour estimer quand Δ = L (rayon du cône)

        const elemMax = evalElements(be, tMax);
        const derivMax = evalDerivatives(be, tMax);
        const obsMax = observerCoords(latDeg, lonDeg, elemMax);
        const dobsMax = observerDerivatives(latDeg, lonDeg, elemMax, derivMax);

        const uMax = elemMax.x - obsMax.xi;
        const vMax = elemMax.y - obsMax.eta;
        const deltaMax = Math.sqrt(uMax * uMax + vMax * vMax);

        const L = isPenumbra
            ? elemMax.l1 - obsMax.zeta * tanF
            : Math.abs(elemMax.l2 - obsMax.zeta * tanF);

        if (deltaMax > L) return null; // pas de contact

        // Vitesse relative
        const du = derivMax.dx - dobsMax.dxi;
        const dv = derivMax.dy - dobsMax.deta;
        const n2 = du * du + dv * dv;
        if (n2 < 1e-15) return null;

        // Estimation du demi-intervalle
        const tau = Math.sqrt((L * L - deltaMax * deltaMax) / n2);

        let t1 = tMax - tau; // premier contact
        let t2 = tMax + tau; // dernier contact

        // Affiner par Newton-Raphson
        t1 = refineContact(be, latDeg, lonDeg, t1, tanF, isPenumbra);
        t2 = refineContact(be, latDeg, lonDeg, t2, tanF, isPenumbra);

        if (t1 === null || t2 === null) return null;
        if (t1 > t2) { const tmp = t1; t1 = t2; t2 = tmp; }

        return { first: t1, second: t2 };
    }

    /**
     * Affine un instant de contact par Newton-Raphson.
     * On cherche t tel que Δ(t) = L(t).
     */
    function refineContact(be, latDeg, lonDeg, t0est, tanF, isPenumbra) {
        let t = t0est;

        for (let iter = 0; iter < 20; iter++) {
            const elem = evalElements(be, t);
            const deriv = evalDerivatives(be, t);
            const obs = observerCoords(latDeg, lonDeg, elem);
            const dobs = observerDerivatives(latDeg, lonDeg, elem, deriv);

            const u = elem.x - obs.xi;
            const v = elem.y - obs.eta;
            const delta2 = u * u + v * v;
            const delta = Math.sqrt(delta2);

            const L = isPenumbra
                ? elem.l1 - obs.zeta * tanF
                : elem.l2 - obs.zeta * tanF;

            const Labs = isPenumbra ? L : Math.abs(L);

            // f(t) = Δ² - L² = 0
            const f = delta2 - Labs * Labs;

            const du = deriv.dx - dobs.dxi;
            const dv = deriv.dy - dobs.deta;
            const fp = 2 * (u * du + v * dv);

            if (Math.abs(fp) < 1e-15) break;

            const dt = f / fp;
            t -= dt;

            if (Math.abs(dt) < 1e-8) break;
        }

        return t;
    }

    /**
     * Calcule l'altitude et l'azimut du Soleil vus depuis l'observateur.
     */
    function solarPosition(latDeg, lonDeg, elem) {
        const lat = latDeg * DEG2RAD;
        const lon = lonDeg * DEG2RAD;

        // Angle horaire local du Soleil
        const H = elem.mu + lon;

        const sinD = Math.sin(elem.d);
        const cosD = Math.cos(elem.d);
        const sinLat = Math.sin(lat);
        const cosLat = Math.cos(lat);
        const sinH = Math.sin(H);
        const cosH = Math.cos(H);

        // Altitude
        const sinAlt = sinLat * sinD + cosLat * cosD * cosH;
        const altitude = Math.asin(sinAlt) * RAD2DEG;

        // Azimut (mesuré depuis le nord, vers l'est)
        const cosAlt = Math.cos(Math.asin(sinAlt));
        let azimuth;
        if (Math.abs(cosAlt) < 1e-10) {
            azimuth = 0;
        } else {
            const sinAz = -cosD * sinH / cosAlt;
            const cosAz = (sinD - sinLat * sinAlt) / (cosLat * cosAlt);
            azimuth = Math.atan2(sinAz, cosAz) * RAD2DEG;
            if (azimuth < 0) azimuth += 360;
        }

        return { altitude, azimuth };
    }

    /**
     * Convertit un temps t (heures depuis t0) en chaîne HH:MM:SS UT.
     * 
     * @param {number} t   - temps en heures depuis t0
     * @param {number} t0  - temps de référence TDT en heures décimales
     * @param {number} deltaT - ΔT en secondes
     */
    function tToUT(t, t0, deltaT) {
        const tdt = t0 + t; // heures TDT
        const ut = tdt - (deltaT || 0) / 3600; // heures UT
        
        let hours = ut;
        // Normaliser à [0, 24)
        while (hours < 0) hours += 24;
        while (hours >= 24) hours -= 24;

        const h = Math.floor(hours);
        const m = Math.floor((hours - h) * 60);
        const s = Math.round(((hours - h) * 60 - m) * 60);

        return `${String(h).padStart(2, '0')}:${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}`;
    }

    /**
     * Formate une durée en secondes en "Xm YYs".
     */
    function formatDuration(seconds) {
        if (seconds === null || seconds === undefined) return '—';
        const m = Math.floor(Math.abs(seconds) / 60);
        const s = Math.abs(seconds) % 60;
        return `${m}m${s.toFixed(1)}s`;
    }

    // --- API publique ---
    return {
        computeCircumstances,
        tToUT,
        formatDuration,
        evalElements,
    };

})();
