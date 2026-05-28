<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <base href="<?php echo rtrim(dirname($_SERVER['SCRIPT_NAME']), '/'); ?>/">
    <title>Cartographie d'Éclipses Solaires</title>
    <link rel="icon" type="image/svg+xml" href="img/favicon.svg">
    
    <!-- Leaflet CSS -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    
    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Source+Sans+3:wght@300;400;600;700&family=Source+Serif+4:wght@400;600&display=swap" rel="stylesheet">
    
    <link rel="stylesheet" href="css/style.<?php echo filemtime(__DIR__ . '/css/style.css'); ?>.css">
</head>
<body>

<!-- Bandeau mobile (visible uniquement sur petit écran) -->
<nav id="mobile-bar">
    <div class="mobile-filters">
        <select id="mobile-country-select">
            <option value="">Tous pays</option>
        </select>
        <select id="mobile-eclipse-select">
            <option value="">— Choisir une éclipse —</option>
        </select>
    </div>
</nav>

<!-- Panneau latéral (masqué sur mobile) -->
<aside id="sidebar">
    <header id="sidebar-header">
        <h1>Éclipses Solaires</h1>
        <p class="subtitle">XXI<sup>e</sup> siècle</p>
    </header>
    
    <div id="filters">
        <div class="filter-row">
            <label>Pays traversé</label>
            <select id="country-select">
                <option value="">Tous les pays</option>
            </select>
        </div>
        <div class="filter-row filter-checkbox">
            <label class="checkbox-label">
                <input type="checkbox" id="include-partial">
                Inclure les partielles
            </label>
        </div>
    </div>
    
    <div id="eclipse-list">
        <div class="loading">Chargement…</div>
    </div>
    
    <footer id="sidebar-footer">
        <p class="credit">Eclipse Predictions by Fred Espenak,<br>NASA/GSFC Emeritus</p>
        <p class="about-link"><a href="about.html">À propos du projet</a></p>
        <p class="license">GPL v3 — <a href="https://github.com/RBel-create/predictions-eclipses-solaires" target="_blank">Code source</a></p>
    </footer>
</aside>

<!-- Carte -->
<main id="map-container">
    <div id="map"></div>
</main>

<!-- Popup d'information éclipse -->
<div id="eclipse-info" class="hidden">
    <button id="info-close" title="Fermer">&times;</button>
    <h2 id="info-title"></h2>
    <div id="info-content"></div>
    <div id="info-share-row">
        <button id="info-share" title="Copier le lien">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 12v8a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-8"/><polyline points="16 6 12 2 8 6"/><line x1="12" y1="2" x2="12" y2="15"/></svg>
            Partager cette vue
        </button>
    </div>
</div>

<!-- Toast notification -->
<div id="share-toast" class="hidden">Lien copié !</div>

<!-- Leaflet JS -->
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

<!-- Paramètres d'URL -->
<script>
    window.ECLIPSE_PARAMS = {
        date: <?php echo json_encode($_GET['date'] ?? null); ?>,
        view: <?php echo json_encode($_GET['view'] ?? null); ?>
    };
</script>

<!-- Application -->
<script src="js/besselian.<?php echo filemtime(__DIR__ . '/js/besselian.js'); ?>.js"></script>
<script src="js/circumstances.<?php echo filemtime(__DIR__ . '/js/circumstances.js'); ?>.js"></script>
<script src="js/app.<?php echo filemtime(__DIR__ . '/js/app.js'); ?>.js"></script>

</body>
</html>
