<?php
/**
 * Prédictions d'Éclipses Solaires
 * Script de scraping - Passe 3 : Coordonnées des tracés de bande
 * 
 * Source : NASA/Espenak - Path Coordinate Tables
 * https://eclipse.gsfc.nasa.gov/SEpath/
 * 
 * Usage : commenter "Deny from all" dans .htaccess, puis accéder via navigateur
 * 
 * Pour chaque éclipse centrale (T, A, H) en base, ce script :
 * 1. Récupère la page des coordonnées de tracé
 * 2. Parse le tableau de coordonnées (nord, sud, centre)
 * 3. Insère dans path_coordinates
 * 
 * Licence : GPL v3
 * Crédit données : "Eclipse Predictions by Fred Espenak, NASA/GSFC Emeritus"
 */

header('Content-Type: text/plain; charset=utf-8');
set_time_limit(600);

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
 * Convertit une coordonnée en degrés-minutes (ex: "43 36.8S") en décimal.
 * Retourne null si le champ est vide ou non parsable.
 */
function dm_to_decimal(string $degrees, string $minutes, string $direction): ?float {
    $deg = (int)$degrees;
    $min = (float)$minutes;
    $decimal = $deg + $min / 60.0;
    
    if ($direction === 'S' || $direction === 'W') {
        $decimal = -$decimal;
    }
    
    return $decimal;
}

/**
 * Construit l'URL de la page de tracé pour une éclipse donnée.
 * 
 * Pattern : SE{YYYY}{Mon}{DD}{Type}path.html
 * Dossier : SEpath2001/ pour 2001-2100
 */
function build_path_url(string $eclipseDate, string $eclipseType): string {
    $months = [
        1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr',
        5 => 'May', 6 => 'Jun', 7 => 'Jul', 8 => 'Aug',
        9 => 'Sep', 10 => 'Oct', 11 => 'Nov', 12 => 'Dec',
    ];
    
    $parts = explode('-', $eclipseDate);
    $year = $parts[0];
    $month = $months[(int)$parts[1]];
    $day = $parts[2];
    
    // La NASA organise les tracés par demi-siècle :
    // 2001-2050 → SEpath2001/
    // 2051-2100 → SEpath2051/
    $folder = ((int)$year <= 2050) ? 'SEpath2001' : 'SEpath2051';
    $base = "https://eclipse.gsfc.nasa.gov/SEpath/{$folder}/";
    return $base . "SE{$year}{$month}{$day}{$eclipseType}path.html";
}

/**
 * Parse le tableau de coordonnées de tracé.
 * 
 * Format des lignes de données :
 *  HH:MM   DD MM.MS DD MM.ME  DD MM.MS DD MM.ME  DD MM.MS DD MM.ME  R.RRR  AA BBB  WWW  DDmSS.Ss
 * 
 * Les coordonnées sont en degrés et minutes avec direction (N/S, E/W).
 * Les lignes "Limits" marquent le début/fin (lever/coucher du Soleil).
 */
function parse_path_page(string $html): array {
    $coordinates = [];
    
    // Extraire le bloc <pre> qui contient le tableau
    // Le format est du texte préformaté dans un bloc <pre> ou <code>
    
    $lines = explode("\n", $html);
    $sortOrder = 0;
    
    foreach ($lines as $line) {
        // Nettoyer les tags HTML
        $stripped = strip_tags($line);
        
        // Chercher les lignes de données
        // Pattern pour les lignes temporelles : " HH:MM  " au début
        // Pattern pour les lignes "Limits" : " Limits " au début
        
        // Ligne temporelle : commence par un horaire HH:MM
        if (preg_match('/^\s*(\d{2}):(\d{2})\s+/', $stripped, $timeMatch)) {
            $timeUt = $timeMatch[1] . ':' . $timeMatch[2] . ':00';
            
            $parsed = parse_coordinate_line($stripped, $timeUt, $sortOrder);
            if ($parsed !== null) {
                $coordinates[] = $parsed;
                $sortOrder++;
            }
        }
        // Ligne "Limits"
        elseif (preg_match('/^\s*Limits\s+/', $stripped)) {
            $parsed = parse_coordinate_line($stripped, null, $sortOrder);
            if ($parsed !== null) {
                // Pour les lignes Limits, on utilise 00:00:00 comme placeholder
                // et on les marque différemment
                $coordinates[] = $parsed;
                $sortOrder++;
            }
        }
    }
    
    return $coordinates;
}

/**
 * Parse une ligne de coordonnées individuelle.
 * 
 * Le format est à colonnes fixes. Les coordonnées sont :
 * - Latitude : DD MM.M puis N ou S (implicite par la valeur et le contexte)
 * - Longitude : DDD MM.M puis E ou W
 * 
 * Mais dans le format NASA, la direction N/S/E/W est implicite dans la position
 * des colonnes et le signe. Il faut parser par position de caractère.
 * 
 * En fait, en regardant de plus près, le format utilise des colonnes fixes
 * avec les degrés et minutes séparés par des espaces.
 */
function parse_coordinate_line(string $line, ?string $timeUt, int $sortOrder): ?array {
    // Le format exact varie légèrement. On utilise une regex plus flexible.
    // 
    // Exemple de ligne :
    //  05:16   19 05.8S 010 13.4E  18 25.3S 005 39.6E  18 52.8S 008 13.7E  1.032   7 110  126  01m42.2s
    //  Limits  15 45.9S 001 54.4E  16 40.2S 001 18.4E  16 13.0S 001 36.5E  1.030   0   -  117  01m30.8s
    
    // Pattern flexible pour extraire les 6 paires de coordonnées (lat/lon × 3 limites)
    $pattern = '/(?:(\d{2}:\d{2})|Limits)\s+' .
        '(\d+)\s+(\d+\.\d+)([NS])\s+(\d+)\s+(\d+\.\d+)([EW])\s+' .  // North limit lat/lon
        '(\d+)\s+(\d+\.\d+)([NS])\s+(\d+)\s+(\d+\.\d+)([EW])\s+' .  // South limit lat/lon
        '(\d+)\s+(\d+\.\d+)([NS])\s+(\d+)\s+(\d+\.\d+)([EW])\s+' .  // Central line lat/lon
        '(\d+\.\d+)\s+' .                                              // Diameter ratio M:S
        '(\d+)\s+' .                                                   // Sun altitude
        '([\d-]+)\s+' .                                                // Sun azimuth (peut être "-")
        '(\d+)\s+' .                                                   // Path width km
        '(\d+m[\d.]+s)' .                                              // Central duration
        '/';
    
    if (!preg_match($pattern, $line, $m)) {
        return null;
    }
    
    // Si c'est une ligne "Limits" et qu'on n'a pas de time
    if ($timeUt === null) {
        $timeUt = '00:00:00';
    }
    
    return [
        'time_ut' => $timeUt,
        'north_lat' => dm_to_decimal($m[2], $m[3], $m[4]),
        'north_lon' => dm_to_decimal($m[5], $m[6], $m[7]),
        'south_lat' => dm_to_decimal($m[8], $m[9], $m[10]),
        'south_lon' => dm_to_decimal($m[11], $m[12], $m[13]),
        'central_lat' => dm_to_decimal($m[14], $m[15], $m[16]),
        'central_lon' => dm_to_decimal($m[17], $m[18], $m[19]),
        'diameter_ratio' => (float)$m[20],
        'sun_altitude' => (float)$m[21],
        'sun_azimuth' => ($m[22] === '-') ? null : (float)$m[22],
        'path_width_km' => (float)$m[23],
        'central_duration' => $m[24],
        'sort_order' => $sortOrder,
    ];
}

/**
 * Insère les coordonnées de tracé en base
 */
function insert_path_coordinates(PDO $pdo, int $eclipseId, array $coordinates): int {
    $sql = "INSERT INTO path_coordinates 
            (eclipse_id, time_ut, north_lat, north_lon, south_lat, south_lon,
             central_lat, central_lon, diameter_ratio, sun_altitude, sun_azimuth,
             path_width_km, central_duration, sort_order)
            VALUES 
            (:eclipse_id, :time_ut, :north_lat, :north_lon, :south_lat, :south_lon,
             :central_lat, :central_lon, :diameter_ratio, :sun_altitude, :sun_azimuth,
             :path_width_km, :central_duration, :sort_order)";
    
    $stmt = $pdo->prepare($sql);
    $count = 0;
    
    foreach ($coordinates as $c) {
        try {
            $stmt->execute([
                ':eclipse_id' => $eclipseId,
                ':time_ut' => $c['time_ut'],
                ':north_lat' => $c['north_lat'],
                ':north_lon' => $c['north_lon'],
                ':south_lat' => $c['south_lat'],
                ':south_lon' => $c['south_lon'],
                ':central_lat' => $c['central_lat'],
                ':central_lon' => $c['central_lon'],
                ':diameter_ratio' => $c['diameter_ratio'],
                ':sun_altitude' => $c['sun_altitude'],
                ':sun_azimuth' => $c['sun_azimuth'],
                ':path_width_km' => $c['path_width_km'],
                ':central_duration' => $c['central_duration'],
                ':sort_order' => $c['sort_order'],
            ]);
            $count++;
        } catch (PDOException $ex) {
            echo "  ERREUR insertion point {$c['sort_order']} : {$ex->getMessage()}\n";
        }
    }
    
    return $count;
}

// --- Exécution principale ---

echo "===========================================\n";
echo "Prédictions d'Éclipses Solaires\n";
echo "Scraping des tracés de bande - Passe 3\n";
echo "===========================================\n\n";

try {
    $pdo = getDB();
    echo "Connexion MySQL : OK\n\n";
} catch (PDOException $e) {
    echo "ERREUR connexion MySQL : " . $e->getMessage() . "\n";
    exit(1);
}

// Récupérer les éclipses centrales sans coordonnées de tracé
$sql = "SELECT e.id, e.eclipse_date, e.eclipse_type 
        FROM eclipses e 
        LEFT JOIN path_coordinates p ON e.id = p.eclipse_id
        WHERE e.eclipse_type IN ('T', 'A', 'H')
        AND p.id IS NULL
        GROUP BY e.id
        ORDER BY e.eclipse_date";

$eclipses = $pdo->query($sql)->fetchAll();
$total = count($eclipses);

echo "Éclipses centrales sans tracé : $total\n\n";

if ($total === 0) {
    echo "Rien à faire !\n";
    exit(0);
}

$success = 0;
$errors = 0;
$totalPoints = 0;

foreach ($eclipses as $i => $eclipse) {
    $num = $i + 1;
    $date = $eclipse['eclipse_date'];
    $type = $eclipse['eclipse_type'];
    $id = $eclipse['id'];
    
    $url = build_path_url($date, $type);
    
    echo "[$num/$total] $date ($type) — id=$id\n";
    
    $html = fetch_url($url);
    if ($html === false) {
        $errors++;
        echo "  ÉCHEC\n\n";
        sleep($sleep_delay);
        continue;
    }
    
    $coordinates = parse_path_page($html);
    $count = count($coordinates);
    
    if ($count === 0) {
        echo "  ATTENTION : aucune coordonnée trouvée !\n";
        $errors++;
        echo "\n";
        sleep($sleep_delay);
        continue;
    }
    
    // Insérer en base
    try {
        $inserted = insert_path_coordinates($pdo, $id, $coordinates);
        $totalPoints += $inserted;
        echo "  Points insérés : $inserted\n";
        
        // Afficher un résumé
        $first = $coordinates[0];
        $last = end($coordinates);
        echo "  Tracé : ({$first['central_lat']},{$first['central_lon']}) → ({$last['central_lat']},{$last['central_lon']})\n";
        
        $success++;
    } catch (PDOException $ex) {
        echo "  ERREUR : {$ex->getMessage()}\n";
        $errors++;
    }
    
    echo "\n";
    
    if (ob_get_level() > 0) ob_flush();
    flush();
    
    sleep($sleep_delay);
}

echo "===========================================\n";
echo "TERMINÉ\n";
echo "Succès : $success / $total\n";
echo "Erreurs : $errors\n";
echo "Points de tracé insérés : $totalPoints\n";
echo "===========================================\n";
echo "\nCrédit : Eclipse Predictions by Fred Espenak, NASA/GSFC Emeritus\n";
