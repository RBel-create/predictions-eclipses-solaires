<?php
// ============================================================
// Prédictions d'Éclipses Solaires — Configuration
// Licence : GPL v3
// ============================================================

// --- Connexion MySQL ---
define('DB_HOST', 'localhost');
define('DB_NAME', 'rfewhasgfd_eclipses');
define('DB_USER', '________');  // <-- À remplir
define('DB_PASS', '________');  // <-- À remplir
define('DB_CHARSET', 'utf8mb4');

// --- Connexion PDO ---
function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
    return $pdo;
}

// --- Chemins ---
define('BASE_URL', '/astro/eclipses');
define('BASE_PATH', __DIR__);

// --- Crédit obligatoire ---
define('DATA_CREDIT', 'Eclipse Predictions by Fred Espenak, NASA/GSFC Emeritus');
