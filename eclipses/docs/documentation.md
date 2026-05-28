# Prédictions d'Éclipses Solaires — Documentation technique
# Solar Eclipse Predictions — Technical Documentation

Version 1.01 — Mai 2026

---

## Architecture / Architecture

```
/astro/eclipses/
├── config.php                  # Database credentials (not in repo)
├── .htaccess                   # Protects config.php, disables directory listing
├── scripts/
│   ├── .htaccess               # Deny from all
│   ├── scrape_catalog.php      # Pass 1: eclipse catalog from NASA decade pages
│   ├── scrape_besselian.php    # Pass 2: Besselian elements + metadata
│   ├── scrape_paths.php        # Pass 3: path coordinates (central line, limits)
│   ├── scrape_countries.php    # Pass 4: country geocoding via Nominatim (limited)
│   └── schema.sql              # Database schema
├── public/
│   ├── .htaccess               # URL rewriting (cache-busting, eclipse URLs)
│   ├── index.php               # Main page (Leaflet map)
│   ├── about.html              # About page
│   ├── api/
│   │   └── index.php           # REST API (list, path, besselian, detail, countries)
│   ├── css/
│   │   └── style.css
│   ├── js/
│   │   ├── app.js              # Map logic, UI, visibility contour
│   │   ├── besselian.js        # Besselian elements computation, local circumstances
│   │   └── circumstances.js    # Click handler, popup display
│   └── img/
│       └── favicon.svg
└── docs/
    └── documentation.md        # This file
```

## Base de données / Database

### Table `eclipses`
Catalogue principal. 180 éclipses (2021-2100).
Main catalog. 180 eclipses (2021-2100).

Colonnes / Columns: `id`, `catalog_number`, `eclipse_date`, `td_greatest_eclipse`, `delta_t`, `lunation_number`, `saros_number`, `eclipse_type` (T/A/H/P), `gamma`, `magnitude`, `latitude`, `longitude`, `sun_altitude`, `sun_azimuth`, `path_width_km`, `central_duration`, `central_duration_seconds`

### Table `besselian_elements`
Éléments de Bessel polynomiaux pour chaque éclipse. 180 entrées.
Polynomial Besselian elements for each eclipse. 180 entries.

Colonnes / Columns: `eclipse_id`, `t0`, `x0-x3`, `y0-y3`, `d0-d3`, `l1_0-l1_3`, `l2_0-l2_3`, `mu0-mu3`, `tan_f1`, `tan_f2`, `k1`, `k2`

Évaluation / Evaluation: `a(t) = a0 + a1·t + a2·t² + a3·t³` where t = hours since t0 (TDT)

### Table `path_coordinates`
Coordonnées des tracés de bande pour les éclipses centrales. ~14500 points.
Path coordinates for central eclipses. ~14500 points.

Colonnes / Columns: `eclipse_id`, `time_ut`, `north_lat/lon`, `south_lat/lon`, `central_lat/lon`, `diameter_ratio`, `sun_altitude/azimuth`, `path_width_km`, `central_duration`, `sort_order`

### Table `eclipse_countries`
Pays traversés par la ligne centrale. ~498 entrées.
Countries crossed by the central line. ~498 entries.

Colonnes / Columns: `eclipse_id`, `country_code` (ISO 3166-1 alpha-2), `country_name`, `sort_order`

## Scraping des données / Data scraping

### Ordre des passes / Pass order

Les scripts doivent être exécutés dans l'ordre suivant.
Scripts must be run in the following order.

#### Passe 1 — Catalogue / Pass 1 — Catalog
`scrape_catalog.php`

Source: `https://eclipse.gsfc.nasa.gov/SEdecade/SEdecade{YYYY}.html`

Récupère les pages par décennie de la NASA. Configurable via le tableau `$decades`.
Fetches NASA decade pages. Configurable via the `$decades` array.

Pour étendre au-delà de 2100, ajouter les décennies au tableau:
To extend beyond 2100, add decades to the array:
```php
$decades = [2021, 2031, ..., 2091, 2101, 2111, ...];
```

#### Passe 2 — Éléments de Bessel / Pass 2 — Besselian elements
`scrape_besselian.php`

Source: `https://eclipse.gsfc.nasa.gov/SEsearch/SEdata.php?Ecl=+YYYYMMDD`

Idempotent: ne traite que les éclipses sans éléments de Bessel en base.
Idempotent: only processes eclipses without Besselian elements in the database.

Filtre configurable: `WHERE e.eclipse_type IN ('T', 'A', 'H', 'P')`

#### Passe 3 — Tracés de bande / Pass 3 — Path coordinates
`scrape_paths.php`

Source: `https://eclipse.gsfc.nasa.gov/SEpath/SEpath{FOLDER}/SE{YYYY}{Mon}{DD}{Type}path.html`

Dossiers NASA / NASA folders:
- 2001-2050: `SEpath2001/`
- 2051-2100: `SEpath2051/`
- 2101-2150: probablement `SEpath2101/` (à vérifier / to verify)

Idempotent. Les éclipses partielles n'ont pas de tracé (normal).
Idempotent. Partial eclipses have no path data (expected).

Attention: les données post-2050 ont une résolution double (points toutes les 60s au lieu de 120s).
Note: post-2050 data has double resolution (points every 60s instead of 120s).

#### Passe 4 — Pays traversés / Pass 4 — Countries crossed
Deux méthodes / Two methods:

**Méthode A — Nominatim (lente, limitée)**
`scrape_countries.php` — reverse geocoding via l'API Nominatim (OpenStreetMap).
Limité par le rate limiting (1 req/s) et les timeouts hébergeur.
Limited by rate limiting (1 req/s) and host timeouts.

**Méthode B — Script Python local (recommandée)**
Exporter les coordonnées des lignes centrales en CSV depuis phpMyAdmin:
Export central line coordinates as CSV from phpMyAdmin:
```sql
SELECT e.id, e.eclipse_date, e.eclipse_type, 
       GROUP_CONCAT(CONCAT(p.central_lat, ',', p.central_lon) 
       ORDER BY p.sort_order SEPARATOR ';') as points
FROM eclipses e 
JOIN path_coordinates p ON e.id = p.eclipse_id
WHERE e.eclipse_type IN ('T','A','H') 
AND e.id NOT IN (SELECT DISTINCT eclipse_id FROM eclipse_countries)
AND p.central_lat IS NOT NULL
GROUP BY e.id
ORDER BY e.eclipse_date;
```

Fournir le CSV à Claude pour générer les INSERT SQL via le script Python de géocodage par boites englobantes. Les pays sont déterminés à partir de la ligne centrale uniquement.
Provide the CSV to Claude to generate INSERT SQL via the bounding-box geocoding Python script. Countries are determined from the central line only.

### Exécution des scripts / Running scripts

Les scripts sont protégés par un `.htaccess` (`Deny from all`).
Scripts are protected by `.htaccess` (`Deny from all`).

Pour exécuter / To run:
1. Commenter `Deny from all` dans `scripts/.htaccess`
2. Accéder via le navigateur: `https://domain/astro/eclipses/scripts/scrape_xxx.php`
3. Décommenter `Deny from all` après exécution

Pas d'accès SSH requis. Les scripts affichent leur progression (bufferisée par l'hébergeur).
No SSH access required. Scripts display progress (buffered by host).

## API

Base URL: `/astro/eclipses/api/index.php`

### `?action=list`
Liste des éclipses avec filtres.

| Paramètre | Description | Défaut |
|-----------|-------------|--------|
| `central` | `1` = centrales uniquement, `0` = toutes | `1` |
| `type` | `T`, `A`, `H`, `P` ou combinaison `T,A` | tous |
| `from` | Année minimum | - |
| `to` | Année maximum | - |
| `country` | Code ISO pays (ex: `FR`) | - |

Retourne un JSON array avec les métadonnées et les pays traversés.

### `?action=path&id={id}`
Tracé GeoJSON d'une éclipse (lignes nord, sud, centrale + polygone).

### `?action=besselian&id={id}`
Éléments de Bessel d'une éclipse (coefficients polynomiaux).

### `?action=detail&id={id}`
Détail complet d'une éclipse.

### `?action=countries`
Liste de tous les pays avec le nombre d'éclipses, triés par nom.

## Algorithmes / Algorithms

### Calcul des circonstances locales / Local circumstances computation
Fichier / File: `besselian.js`

À partir des éléments de Bessel et des coordonnées (lat, lon), le calcul détermine:
From Besselian elements and coordinates (lat, lon), the computation determines:

1. Position de l'observateur dans le plan fondamental (ξ, η, ζ)
2. Recherche du maximum par Newton-Raphson (minimisation de Δ²)
3. Magnitude et obscuration au maximum
4. Recherche des contacts C1-C4 par bisection (Δ = l1 pour pénombre, Δ = |l2| pour ombre)
5. Altitude et azimut du Soleil

**Convention de longitude**: θ = μ + lon (longitude est positive, convention opposée à certains manuels).
**Longitude convention**: θ = μ + lon (east positive, opposite to some textbooks).

**Filtre altitude solaire**: retiré de `computeCircumstances` pour permettre le calcul du contour de visibilité en zone de nuit. Le filtre est appliqué dans `isVisibleAt()` (app.js) et dans le popup (circumstances.js).
**Solar altitude filter**: removed from `computeCircumstances` to allow visibility contour computation in nighttime zones. Filter is applied in `isVisibleAt()` (app.js) and in the popup (circumstances.js).

### Contour de visibilité / Visibility contour
Fichier / File: `app.js` — `computeVisibilityContour()`

Double scan:
- **Vertical**: pour chaque longitude (pas de 2°), balayer en latitude (pas de 1°) → détecte les frontières nord/sud (contour de pénombre)
- **Horizontal**: pour chaque latitude (pas de 2°), balayer en longitude (pas de 2°) → détecte les terminateurs (frontière lever/coucher du Soleil)

Points de frontière trouvés par bisection (10 itérations → précision ~0.001°).
Border points found by bisection (10 iterations → ~0.001° precision).

Connexion des points par plus proche voisin avec distance sphérique pondérée: `dlon × cos(lat)`.
Points connected by nearest neighbor with spherical distance weighting: `dlon × cos(lat)`.

### Gestion de l'antéméridien / Antimeridian handling

Toutes les lignes et polygones sont tracés sur 5 copies du monde (-720°, -360°, 0°, +360°, +720°) avec découpe à l'antéméridien. Le déplacement de la carte est limité à ±720° en longitude.

All lines and polygons are drawn on 5 world copies (-720°, -360°, 0°, +360°, +720°) with antimeridian splitting. Map panning is limited to ±720° longitude.

Le polygone de la bande utilise une normalisation [0°, 360°] quand il traverse l'antéméridien, pour éviter l'inversion nord/sud.
The path polygon uses [0°, 360°] normalization when crossing the antimeridian, to avoid north/south inversion.

### Zoom
Les bounds de zoom sont calculées avec `unwrapLongitudes` sur la ligne centrale uniquement, indépendamment des copies ×5.
Zoom bounds are computed with `unwrapLongitudes` on the central line only, independently from the ×5 copies.

## URLs

### Réécriture / Rewriting
```
/public/eclipse-2026-08-12/                → index.php?date=2026-08-12
/public/eclipse-2026-08-12/48.85,2.35,5    → index.php?date=2026-08-12&view=48.85,2.35,5
```

L'URL est mise à jour dynamiquement via `history.pushState` (sélection d'éclipse) et `history.replaceState` (déplacement/zoom de la carte).
The URL is dynamically updated via `history.pushState` (eclipse selection) and `history.replaceState` (map pan/zoom).

## Cache-busting

Les fichiers CSS et JS sont référencés avec un timestamp `filemtime()`:
CSS and JS files are referenced with a `filemtime()` timestamp:
```
style.1748293200.css → style.css (via RewriteRule)
```
Le cache du navigateur est invalidé automatiquement à chaque modification de fichier.
Browser cache is automatically invalidated on each file modification.

## Limitations connues / Known limitations

- Précision des heures de contact: ±quelques secondes (pas de correction du profil du limbe lunaire LRO/Kaguya)
- Contour de visibilité: artefacts possibles aux hautes latitudes (zones polaires)
- Pays traversés: déterminés par boites englobantes sur la ligne centrale, pas les limites nord/sud de la bande
- 2 éclipses "fantômes" (2043-04-09 et 2043-10-03): gamma > 1, exclues de l'affichage (path_width = 0)
- Éclipses partielles: pas de tracé de bande (normal), contour de visibilité uniquement

## Crédits / Credits

- Eclipse Predictions by Fred Espenak, NASA/GSFC Emeritus
- Five Millennium Canon of Solar Eclipses: -1999 to +3000, NASA TP-2006-214141
- VSOP87 (Bretagnon & Francou, 1988) — ELP-2000/82 (Chapront-Touzé & Chapront, 1983)
- Inspiré par Xavier Jubier (UAI, Working Group on Solar Eclipses)
- Développé par Claude — Opus 4.6 (Anthropic) sous la supervision de R. Bel
- Licence GPL v3
