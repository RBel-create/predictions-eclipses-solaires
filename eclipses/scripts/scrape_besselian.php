<?php
/**
 * Prédictions d'Éclipses Solaires
 * Script de scraping - Passe 2 : Éléments de Bessel + métadonnées
 * 
 * Source : NASA/Espenak - Solar Eclipse Search Engine
 * https://eclipse.gsfc.nasa.gov/SEsearch/SEdata.php
 * 
 * Usage : commenter "Deny from all" dans .htaccess, puis accéder via navigateur
 * 
 * Pour chaque éclipse centrale (T, A, H) en base, ce script :
 * 1. Récupère la page des éléments de Bessel
 * 2. Parse les coefficients polynomiaux
 * 3. Parse les métadonnées (gamma, lat/lon, ΔT, largeur, etc.)
 * 4. Insère dans besselian_elements
 * 5. Met à jour les colonnes manquantes de eclipses
 * 
 * Licence : GPL v3
 * Crédit données : "Eclipse Predictions by Fred Espenak, NASA/GSFC Emeritus"
 */

header('Content-Type: text/plain; charset=utf-8');
set_time_limit(600); // 10 minutes — ~117 requêtes × 2s

// --- Configuration ---
require_once __DIR__ . '/../config.php';

$sleep_delay = 2;

// --- Fonctions ---

function fetch_url(string $url): string|false {
    echo "  Fetch : $url\n";
    
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => "User-Agent: EclipseMap-Scraper/1.0 (educational project)\r\n",
            'timeout' => 30,
        ]
    ]);
    
    $html = @file_get_contents($url, false, $context);
    
    if ($html === false) {
        echo "  ERREUR : impossible de récupérer $url\n";
        return false;
    }
    
    echo "  OK (" . strlen($html) . " octets)\n";
    return $html;
}

/**
 * Parse la page des éléments de Bessel d'une éclipse.
 * Retourne un array avec les éléments et les métadonnées, ou null en cas d'échec.
 */
function parse_besselian_page(string $html): ?array {
    $data = [];
    
    // --- Métadonnées de l'éclipse ---
    
    // Gamma
    if (preg_match('/Gamma\s*=\s*([-\d.]+)/', $html, $m)) {
        $data['gamma'] = (float)$m[1];
    }
    
    // ΔT — plusieurs encodages possibles du delta
    if (preg_match('/[ΔΔ&Delta;]T\s*=\s*([\d.]+)\s*s/', $html, $m)) {
        $data['delta_t'] = (float)$m[1];
    } elseif (preg_match('/DeltaT|ΔT|&#x0394;T|&#916;T/', $html) && 
              preg_match('/=\s*([\d.]+)\s*s/', $html, $m)) {
        // fallback
    }
    
    // Lunation No.
    if (preg_match('/Lunation\s+No\.\s*=\s*(\d+)/', $html, $m)) {
        $data['lunation_number'] = (int)$m[1];
    }
    
    // Circumstances at Greatest Eclipse
    // Latitude:   65.2° N      Sun's Altitude:    25.8°          Path Width = 293.9 km
    // Longitude:  25.2° W      Sun's Azimuth:    248.4°    Central Duration = 02m18s
    
    // Latitude
    if (preg_match('/Latitude:\s*([\d.]+)°?\s*([NS])/', $html, $m)) {
        $lat = (float)$m[1];
        if ($m[2] === 'S') $lat = -$lat;
        $data['latitude'] = $lat;
    }
    
    // Longitude
    if (preg_match('/Longitude:\s*([\d.]+)°?\s*([EW])/', $html, $m)) {
        $lon = (float)$m[1];
        if ($m[2] === 'W') $lon = -$lon;
        $data['longitude'] = $lon;
    }
    
    // Sun's Altitude
    if (preg_match("/Sun's\s+Altitude:\s*([\d.]+)/", $html, $m)) {
        $data['sun_altitude'] = (float)$m[1];
    }
    
    // Sun's Azimuth
    if (preg_match("/Sun's\s+Azimuth:\s*([\d.]+)/", $html, $m)) {
        $data['sun_azimuth'] = (float)$m[1];
    }
    
    // Path Width
    if (preg_match('/Path\s+Width\s*=\s*([\d.]+)\s*km/', $html, $m)) {
        $data['path_width_km'] = (float)$m[1];
    }
    
    // --- Éléments de Bessel ---
    
    // t0 : "2026 Aug 12   18.000 TDT  (=t0)"
    if (preg_match('/(\d+\.\d+)\s+TDT\s+\(=t0\)/', $html, $m)) {
        $data['t0'] = (float)$m[1];
    } else {
        echo "  ATTENTION : t0 non trouvé\n";
        return null;
    }
    
    // tan f1 et tan f2
    if (preg_match('/tan\s+f1\s*=\s*([\d.]+)/', $html, $m)) {
        $data['tan_f1'] = (float)$m[1];
    }
    if (preg_match('/tan\s+f2\s*=\s*([\d.]+)/', $html, $m)) {
        $data['tan_f2'] = (float)$m[1];
    }
    
    // Coefficients polynomiaux
    // Format (4 lignes, n=0 à 3) :
    //   0   0.4755140  0.7711830 14.7966700  0.5379550 -0.0081420  88.747787
    //   1   0.5189249 -0.2301680 -0.0120650  0.0000939  0.0000935  15.003090
    //   2  -0.0000773 -0.0001246 -0.0000030 -0.0000121 -0.0000121   0.000000
    //   3  -0.0000080  0.0000038  ... (ligne 3 tronquée à cause du bug NASA)
    
    // Stratégie : chercher les lignes qui commencent par "  0 ", "  1 ", "  2 ", "  3 "
    // après la ligne contenant "n        x"
    
    $lines = explode("\n", $html);
    $inTable = false;
    $coefficients = [];
    
    foreach ($lines as $line) {
        // Détecter l'en-tête du tableau
        if (preg_match('/^\s*n\s+x\s+y\s+d\s+l1\s+l2\s+/', $line)) {
            $inTable = true;
            continue;
        }
        
        if ($inTable) {
            // Chercher les lignes de coefficients (n = 0, 1, 2, 3)
            $stripped = strip_tags($line);
            if (preg_match('/^\s*([0-3])\s+([-\d.]+)\s+([-\d.]+)\s+([-\d.]+)\s+([-\d.]+)\s+([-\d.]+)\s+([-\d.]+)/', $stripped, $m)) {
                $n = (int)$m[1];
                $coefficients[$n] = [
                    'x'  => (float)$m[2],
                    'y'  => (float)$m[3],
                    'd'  => (float)$m[4],
                    'l1' => (float)$m[5],
                    'l2' => (float)$m[6],
                    'mu' => (float)$m[7],
                ];
            } elseif (preg_match('/^\s*3\s+([-\d.]+)\s+([-\d.]+)/', $stripped, $m)) {
                // Ligne 3 tronquée (bug NASA : d3 manquant)
                $coefficients[3] = [
                    'x'  => (float)$m[1],
                    'y'  => (float)$m[2],
                    'd'  => 0.0,
                    'l1' => 0.0,
                    'l2' => 0.0,
                    'mu' => 0.0,
                ];
            }
            
            // Arrêter après la ligne 3 ou si on rencontre "tan f1"
            if (isset($coefficients[3]) || strpos($stripped, 'tan f1') !== false) {
                $inTable = false;
            }
        }
    }
    
    // Vérifier qu'on a au moins les ordres 0, 1 et 2
    if (!isset($coefficients[0]) || !isset($coefficients[1]) || !isset($coefficients[2])) {
        echo "  ATTENTION : coefficients incomplets\n";
        echo "  Ordres trouvés : " . implode(', ', array_keys($coefficients)) . "\n";
        return null;
    }
    
    // Si l'ordre 3 manque complètement, le mettre à zéro
    if (!isset($coefficients[3])) {
        $coefficients[3] = ['x' => 0, 'y' => 0, 'd' => 0, 'l1' => 0, 'l2' => 0, 'mu' => 0];
    }
    
    $data['coefficients'] = $coefficients;
    
    return $data;
}

/**
 * Insère les éléments de Bessel en base
 */
function insert_besselian(PDO $pdo, int $eclipseId, array $data): bool {
    $c = $data['coefficients'];
    
    $sql = "INSERT INTO besselian_elements 
            (eclipse_id, t0,
             x0, x1, x2, x3, y0, y1, y2, y3,
             d0, d1, d2, d3, l1_0, l1_1, l1_2, l1_3,
             l2_0, l2_1, l2_2, l2_3, mu0, mu1, mu2, mu3,
             tan_f1, tan_f2)
            VALUES 
            (:eclipse_id, :t0,
             :x0, :x1, :x2, :x3, :y0, :y1, :y2, :y3,
             :d0, :d1, :d2, :d3, :l1_0, :l1_1, :l1_2, :l1_3,
             :l2_0, :l2_1, :l2_2, :l2_3, :mu0, :mu1, :mu2, :mu3,
             :tan_f1, :tan_f2)";
    
    $stmt = $pdo->prepare($sql);
    return $stmt->execute([
        ':eclipse_id' => $eclipseId,
        ':t0' => $data['t0'],
        ':x0'  => $c[0]['x'],  ':x1'  => $c[1]['x'],  ':x2'  => $c[2]['x'],  ':x3'  => $c[3]['x'],
        ':y0'  => $c[0]['y'],  ':y1'  => $c[1]['y'],  ':y2'  => $c[2]['y'],  ':y3'  => $c[3]['y'],
        ':d0'  => $c[0]['d'],  ':d1'  => $c[1]['d'],  ':d2'  => $c[2]['d'],  ':d3'  => $c[3]['d'],
        ':l1_0' => $c[0]['l1'], ':l1_1' => $c[1]['l1'], ':l1_2' => $c[2]['l1'], ':l1_3' => $c[3]['l1'],
        ':l2_0' => $c[0]['l2'], ':l2_1' => $c[1]['l2'], ':l2_2' => $c[2]['l2'], ':l2_3' => $c[3]['l2'],
        ':mu0' => $c[0]['mu'], ':mu1' => $c[1]['mu'], ':mu2' => $c[2]['mu'], ':mu3' => $c[3]['mu'],
        ':tan_f1' => $data['tan_f1'] ?? null,
        ':tan_f2' => $data['tan_f2'] ?? null,
    ]);
}

/**
 * Met à jour les métadonnées d'une éclipse
 */
function update_eclipse_metadata(PDO $pdo, int $eclipseId, array $data): bool {
    $fields = [];
    $params = [':id' => $eclipseId];
    
    $mapping = [
        'gamma' => 'gamma',
        'delta_t' => 'delta_t',
        'lunation_number' => 'lunation_number',
        'latitude' => 'latitude',
        'longitude' => 'longitude',
        'sun_altitude' => 'sun_altitude',
        'sun_azimuth' => 'sun_azimuth',
        'path_width_km' => 'path_width_km',
    ];
    
    foreach ($mapping as $dataKey => $dbField) {
        if (isset($data[$dataKey])) {
            $fields[] = "$dbField = :$dbField";
            $params[":$dbField"] = $data[$dataKey];
        }
    }
    
    if (empty($fields)) return true;
    
    $sql = "UPDATE eclipses SET " . implode(', ', $fields) . " WHERE id = :id";
    $stmt = $pdo->prepare($sql);
    return $stmt->execute($params);
}

// --- Exécution principale ---

echo "===========================================\n";
echo "Prédictions d'Éclipses Solaires\n";
echo "Scraping des éléments de Bessel - Passe 2\n";
echo "===========================================\n\n";

try {
    $pdo = getDB();
    echo "Connexion MySQL : OK\n\n";
} catch (PDOException $e) {
    echo "ERREUR connexion MySQL : " . $e->getMessage() . "\n";
    exit(1);
}

// Récupérer toutes les éclipses centrales (T, A, H) qui n'ont pas encore d'éléments de Bessel
$sql = "SELECT e.id, e.eclipse_date, e.eclipse_type 
        FROM eclipses e 
        LEFT JOIN besselian_elements b ON e.id = b.eclipse_id
        WHERE e.eclipse_type IN ('T', 'A', 'H', 'P')
        AND b.id IS NULL
        ORDER BY e.eclipse_date";

$eclipses = $pdo->query($sql)->fetchAll();
$total = count($eclipses);

echo "Éclipses centrales sans éléments de Bessel : $total\n\n";

if ($total === 0) {
    echo "Rien à faire !\n";
    exit(0);
}

$success = 0;
$errors = 0;

foreach ($eclipses as $i => $eclipse) {
    $num = $i + 1;
    $date = $eclipse['eclipse_date'];
    $type = $eclipse['eclipse_type'];
    $id = $eclipse['id'];
    
    // Construire l'URL : format +YYYYMMDD
    $dateCompact = str_replace('-', '', $date);
    $url = "https://eclipse.gsfc.nasa.gov/SEsearch/SEdata.php?Ecl=+{$dateCompact}";
    
    echo "[$num/$total] $date ($type) — id=$id\n";
    
    $html = fetch_url($url);
    if ($html === false) {
        $errors++;
        echo "  ÉCHEC\n\n";
        sleep($sleep_delay);
        continue;
    }
    
    $data = parse_besselian_page($html);
    if ($data === null) {
        $errors++;
        echo "  ÉCHEC parsing\n\n";
        sleep($sleep_delay);
        continue;
    }
    
    // Insérer les éléments de Bessel
    try {
        insert_besselian($pdo, $id, $data);
        echo "  Bessel : OK (t0={$data['t0']})\n";
    } catch (PDOException $ex) {
        echo "  ERREUR Bessel : {$ex->getMessage()}\n";
        $errors++;
        sleep($sleep_delay);
        continue;
    }
    
    // Mettre à jour les métadonnées
    try {
        update_eclipse_metadata($pdo, $id, $data);
        $meta = [];
        if (isset($data['gamma'])) $meta[] = "γ={$data['gamma']}";
        if (isset($data['latitude'])) $meta[] = "lat={$data['latitude']}";
        if (isset($data['longitude'])) $meta[] = "lon={$data['longitude']}";
        if (isset($data['path_width_km'])) $meta[] = "w={$data['path_width_km']}km";
        echo "  Métadonnées : " . implode(', ', $meta) . "\n";
    } catch (PDOException $ex) {
        echo "  ERREUR métadonnées : {$ex->getMessage()}\n";
    }
    
    $success++;
    echo "\n";
    
    // Flush pour voir la progression dans le navigateur
    if (ob_get_level() > 0) ob_flush();
    flush();
    
    sleep($sleep_delay);
}

echo "===========================================\n";
echo "TERMINÉ\n";
echo "Succès : $success / $total\n";
echo "Erreurs : $errors\n";
echo "===========================================\n";
echo "\nCrédit : Eclipse Predictions by Fred Espenak, NASA/GSFC Emeritus\n";
