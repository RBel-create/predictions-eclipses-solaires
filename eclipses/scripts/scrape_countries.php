<?php
/**
 * Prédictions d'Éclipses Solaires
 * Script de remplissage des pays traversés
 * 
 * Utilise l'API Nominatim (OpenStreetMap) pour le reverse geocoding.
 * Politique d'usage : max 1 requête/seconde, User-Agent identifié.
 * https://operations.osmfoundation.org/policies/nominatim/
 * 
 * Usage : commenter "Deny from all" dans scripts/.htaccess, puis navigateur
 * 
 * Licence : GPL v3
 */

header('Content-Type: text/plain; charset=utf-8');
set_time_limit(3600); // 1 heure max

require_once __DIR__ . '/../config.php';

$sleep_delay = 1.1; // secondes entre chaque requête Nominatim (>1s obligatoire)

// --- Fonctions ---

/**
 * Reverse geocoding via Nominatim.
 * Retourne ['country_code' => 'FR', 'country' => 'France'] ou null.
 */
function reverseGeocode(float $lat, float $lon): ?array {
    $url = 'https://nominatim.openstreetmap.org/reverse?'
         . 'format=json'
         . '&lat=' . $lat
         . '&lon=' . $lon
         . '&zoom=3'        // niveau pays
         . '&addressdetails=1'
         . '&accept-language=fr';
    
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => "User-Agent: PredictionsEclipsesSolaires/1.0 (educational project; contact: webmaster@ducotedeparici.xyz)\r\n",
            'timeout' => 10,
        ]
    ]);
    
    $response = @file_get_contents($url, false, $context);
    if ($response === false) return null;
    
    $data = json_decode($response, true);
    if (!$data || !isset($data['address']['country_code'])) return null;
    
    return [
        'country_code' => strtoupper($data['address']['country_code']),
        'country' => $data['address']['country'] ?? 'Inconnu',
    ];
}

// --- Exécution principale ---

echo "===========================================\n";
echo "Prédictions d'Éclipses Solaires\n";
echo "Remplissage des pays traversés\n";
echo "===========================================\n\n";

try {
    $pdo = getDB();
    echo "Connexion MySQL : OK\n\n";
} catch (PDOException $e) {
    echo "ERREUR connexion MySQL : " . $e->getMessage() . "\n";
    exit(1);
}

// Récupérer les éclipses centrales qui n'ont pas encore de pays
$sql = "SELECT DISTINCT e.id, e.eclipse_date, e.eclipse_type
        FROM eclipses e
        INNER JOIN path_coordinates p ON e.id = p.eclipse_id
        LEFT JOIN eclipse_countries c ON e.id = c.eclipse_id
        WHERE c.id IS NULL
        AND e.eclipse_type IN ('T', 'A', 'H')
        ORDER BY e.eclipse_date
        LIMIT :batch_limit";

// Nombre d'éclipses à traiter par exécution (paramètre ?limit=N, défaut 5)
$batchLimit = (int)($_GET['limit'] ?? 5);
if ($batchLimit < 1) $batchLimit = 5;
if ($batchLimit > 20) $batchLimit = 20;

$stmt = $pdo->prepare($sql);
$stmt->bindValue(':batch_limit', $batchLimit, PDO::PARAM_INT);
$stmt->execute();
$eclipses = $stmt->fetchAll();
$total = count($eclipses);

echo "Éclipses sans pays : $total\n\n";

if ($total === 0) {
    echo "Rien à faire !\n";
    exit(0);
}

// Préparer l'insertion
$insertStmt = $pdo->prepare(
    "INSERT IGNORE INTO eclipse_countries (eclipse_id, country_code, country_name, sort_order)
     VALUES (:eclipse_id, :country_code, :country_name, :sort_order)"
);

$totalCountries = 0;

foreach ($eclipses as $i => $eclipse) {
    $num = $i + 1;
    $id = $eclipse['id'];
    $date = $eclipse['eclipse_date'];
    $type = $eclipse['eclipse_type'];
    
    echo "[$num/$total] $date ($type) — id=$id\n";
    
    // Récupérer les points de la ligne centrale
    $pathStmt = $pdo->prepare(
        "SELECT central_lat, central_lon FROM path_coordinates
         WHERE eclipse_id = :id AND central_lat IS NOT NULL
         ORDER BY sort_order"
    );
    $pathStmt->execute([':id' => $id]);
    $points = $pathStmt->fetchAll();
    
    if (empty($points)) {
        echo "  Pas de points de tracé\n\n";
        continue;
    }
    
    // Échantillonner : un point tous les 5 pour réduire les requêtes
    $step = max(1, intval(count($points) / 20)); // ~20 points par éclipse
    $sampled = [];
    for ($j = 0; $j < count($points); $j += $step) {
        $sampled[] = $points[$j];
    }
    // Toujours inclure le dernier point
    $lastPoint = end($points);
    if (end($sampled) !== $lastPoint) {
        $sampled[] = $lastPoint;
    }
    
    echo "  Points à géocoder : " . count($sampled) . " / " . count($points) . "\n";
    
    // Géocoder chaque point échantillonné
    $countries = []; // code => ['name' => ..., 'order' => ...]
    $order = 0;
    
    foreach ($sampled as $pt) {
        $lat = (float)$pt['central_lat'];
        $lon = (float)$pt['central_lon'];
        
        $result = reverseGeocode($lat, $lon);
        
        if ($result !== null) {
            $code = $result['country_code'];
            if (!isset($countries[$code])) {
                $countries[$code] = [
                    'name' => $result['country'],
                    'order' => $order,
                ];
                $order++;
                echo "  + {$result['country']} ($code)\n";
            }
        }
        // else : en mer ou erreur, on passe
        
        usleep((int)($sleep_delay * 1000000)); // respecter le rate limit
    }
    
    // Insérer les pays en base
    foreach ($countries as $code => $info) {
        try {
            $insertStmt->execute([
                ':eclipse_id' => $id,
                ':country_code' => $code,
                ':country_name' => $info['name'],
                ':sort_order' => $info['order'],
            ]);
            $totalCountries++;
        } catch (PDOException $ex) {
            echo "  ERREUR insertion $code : {$ex->getMessage()}\n";
        }
    }
    
    echo "  Pays : " . count($countries) . "\n\n";
    
    if (ob_get_level() > 0) ob_flush();
    flush();
}

echo "===========================================\n";
echo "TERMINÉ\n";
echo "Éclipses traitées : $total\n";
echo "Pays insérés : $totalCountries\n";
echo "===========================================\n";
