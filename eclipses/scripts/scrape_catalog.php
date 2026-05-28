<?php
header('Content-Type: text/plain; charset=utf-8');
set_time_limit(120);
/**
 * Prédictions d'Éclipses Solaires
 * Script de scraping - Passe 1 : Catalogue des éclipses
 * 
 * Source : NASA/Espenak - Decade Tables of Solar Eclipses
 * https://eclipse.gsfc.nasa.gov/SEdecade/
 * 
 * Usage : php scrape_catalog.php
 * 
 * Ce script récupère les pages "SEdecade" de la NASA pour les décennies
 * 2021-2030 à 2091-2100, parse les tableaux HTML, et insère les éclipses
 * dans la table `eclipses` de la base de données.
 * 
 * Licence : GPL v3
 * Crédit données : "Eclipse Predictions by Fred Espenak, NASA/GSFC Emeritus"
 */

// --- Configuration ---
require_once __DIR__ . '/../config.php';

// Décennies à scraper (début de chaque décennie)
$decades = [2021, 2031, 2041, 2051, 2061, 2071, 2081, 2091];

// Délai entre les requêtes (secondes) — respect du serveur NASA
$sleep_delay = 2;

// --- Fonctions ---

/**
 * Récupère le contenu HTML d'une URL
 */
function fetch_url(string $url): string|false {
    echo "  Récupération : $url\n";
    
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
 * Parse le tableau d'une page SEdecade et retourne un array d'éclipses.
 * 
 * Colonnes du tableau NASA :
 * 0: Calendar Date (ex: "2021 Jun 10")
 * 1: TD of Greatest Eclipse (ex: "10:43:06")
 * 2: Eclipse Type (ex: "Annular", "Total", "Partial", "Hybrid")
 * 3: Saros Series (ex: "147")
 * 4: Eclipse Magnitude (ex: "0.943")
 * 5: Central Duration (ex: "03m51s" ou "-")
 * 6: Geographic Region
 */
function parse_decade_page(string $html): array {
    $eclipses = [];
    
    // Chercher toutes les lignes du tableau principal
    // Les lignes d'éclipses contiennent des dates au format "YYYY Mon DD"
    // On utilise une regex pour extraire les lignes pertinentes
    
    // Stratégie : chercher les <tr> qui contiennent des liens vers les cartes
    // (SEplot/SEplotYYYY/SEYYYYMonDDX.GIF)
    
    // Extraction des lignes de données via regex sur les liens de carte
    $pattern = '/SE(\d{4})([A-Z][a-z]{2})(\d{2})([TAHP])\.GIF/';
    
    // On parse le HTML ligne par ligne avec DOMDocument
    $dom = new DOMDocument();
    @$dom->loadHTML($html, LIBXML_NOERROR);
    
    $tables = $dom->getElementsByTagName('table');
    
    foreach ($tables as $table) {
        $rows = $table->getElementsByTagName('tr');
        
        foreach ($rows as $row) {
            $cells = $row->getElementsByTagName('td');
            if ($cells->length < 7) continue;
            
            // Vérifier que la première cellule contient un lien vers une carte
            $firstCell = $cells->item(0);
            $links = $firstCell->getElementsByTagName('a');
            if ($links->length === 0) continue;
            
            $href = $links->item(0)->getAttribute('href');
            if (strpos($href, 'SEplot') === false) continue;
            
            // C'est une ligne d'éclipse !
            $dateText = trim($firstCell->textContent);    // "2021 Jun 10"
            $tdText = trim($cells->item(1)->textContent); // "10:43:06"
            
            // Type d'éclipse — extraire le texte, ignorer les liens
            $typeCell = $cells->item(2);
            $typeText = trim($typeCell->textContent);
            
            $sarosText = trim($cells->item(3)->textContent);
            $magText = trim($cells->item(4)->textContent);
            $durationText = trim($cells->item(5)->textContent);
            $regionText = trim($cells->item(6)->textContent);
            
            // Parser la date
            $dateParts = preg_split('/\s+/', $dateText);
            if (count($dateParts) < 3) continue;
            
            $year = (int)$dateParts[0];
            $monthStr = $dateParts[1];
            $day = (int)$dateParts[2];
            
            $months = [
                'Jan' => 1, 'Feb' => 2, 'Mar' => 3, 'Apr' => 4,
                'May' => 5, 'Jun' => 6, 'Jul' => 7, 'Aug' => 8,
                'Sep' => 9, 'Oct' => 10, 'Nov' => 11, 'Dec' => 12,
            ];
            
            $month = $months[$monthStr] ?? 0;
            if ($month === 0) continue;
            
            $eclipseDate = sprintf('%04d-%02d-%02d', $year, $month, $day);
            
            // Parser le type
            $typeMap = [
                'Total' => 'T',
                'Annular' => 'A',
                'Hybrid' => 'H',
                'Partial' => 'P',
            ];
            $eclipseType = $typeMap[$typeText] ?? null;
            if ($eclipseType === null) {
                echo "  ATTENTION : type inconnu '$typeText' pour $eclipseDate\n";
                continue;
            }
            
            // Parser la durée centrale
            $centralDuration = null;
            $centralDurationSeconds = null;
            if ($durationText !== '-' && $durationText !== '') {
                $centralDuration = $durationText;
                // Format : "04m28s" ou "03m51s"
                if (preg_match('/(\d+)m(\d+)s/', $durationText, $durMatch)) {
                    $centralDurationSeconds = (int)$durMatch[1] * 60 + (int)$durMatch[2];
                }
            }
            
            // Construire le datetime TDT
            $tdGreatestEclipse = $eclipseDate . ' ' . $tdText;
            
            $eclipses[] = [
                'eclipse_date' => $eclipseDate,
                'td_greatest_eclipse' => $tdGreatestEclipse,
                'eclipse_type' => $eclipseType,
                'saros_number' => (int)$sarosText,
                'magnitude' => (float)$magText,
                'central_duration' => $centralDuration,
                'central_duration_seconds' => $centralDurationSeconds,
                'region' => $regionText,
            ];
        }
    }
    
    return $eclipses;
}

/**
 * Insère les éclipses en base de données
 */
function insert_eclipses(PDO $pdo, array $eclipses): int {
    $sql = "INSERT INTO eclipses 
            (eclipse_date, td_greatest_eclipse, eclipse_type, 
             saros_number, magnitude, central_duration, central_duration_seconds)
            VALUES 
            (:eclipse_date, :td_greatest_eclipse, :eclipse_type,
             :saros_number, :magnitude, :central_duration, :central_duration_seconds)
            ON DUPLICATE KEY UPDATE
             eclipse_type = VALUES(eclipse_type),
             saros_number = VALUES(saros_number),
             magnitude = VALUES(magnitude)";
    
    $stmt = $pdo->prepare($sql);
    $count = 0;
    
    foreach ($eclipses as $e) {
        try {
            $stmt->execute([
                ':eclipse_date' => $e['eclipse_date'],
                ':td_greatest_eclipse' => $e['td_greatest_eclipse'],
                ':eclipse_type' => $e['eclipse_type'],
                ':saros_number' => $e['saros_number'],
                ':magnitude' => $e['magnitude'],
                ':central_duration' => $e['central_duration'],
                ':central_duration_seconds' => $e['central_duration_seconds'],
            ]);
            $count++;
        } catch (PDOException $ex) {
            echo "  ERREUR insertion {$e['eclipse_date']} : {$ex->getMessage()}\n";
        }
    }
    
    return $count;
}

// --- Exécution principale ---

echo "===========================================\n";
echo "Prédictions d'Éclipses Solaires\n";
echo "Scraping du catalogue NASA - Passe 1\n";
echo "===========================================\n\n";

try {
    $pdo = getDB();
    echo "Connexion MySQL : OK\n\n";
} catch (PDOException $e) {
    echo "ERREUR connexion MySQL : " . $e->getMessage() . "\n";
    exit(1);
}

$totalInserted = 0;
$totalEclipses = 0;

foreach ($decades as $startYear) {
    $url = "https://eclipse.gsfc.nasa.gov/SEdecade/SEdecade{$startYear}.html";
    $endYear = $startYear + 9;
    
    echo "--- Décennie {$startYear}-{$endYear} ---\n";
    
    $html = fetch_url($url);
    if ($html === false) {
        echo "  Passage à la décennie suivante.\n\n";
        sleep($sleep_delay);
        continue;
    }
    
    $eclipses = parse_decade_page($html);
    $count = count($eclipses);
    $totalEclipses += $count;
    
    echo "  Éclipses trouvées : $count\n";
    
    if ($count > 0) {
        // Afficher un résumé
        $types = array_count_values(array_column($eclipses, 'eclipse_type'));
        echo "  Types : ";
        foreach ($types as $t => $n) echo "$t=$n ";
        echo "\n";
        
        $inserted = insert_eclipses($pdo, $eclipses);
        $totalInserted += $inserted;
        echo "  Insérées en base : $inserted\n";
    }
    
    echo "\n";
    sleep($sleep_delay);
}

echo "===========================================\n";
echo "TERMINÉ\n";
echo "Éclipses trouvées : $totalEclipses\n";
echo "Éclipses insérées : $totalInserted\n";
echo "===========================================\n";
echo "\nCrédit : Eclipse Predictions by Fred Espenak, NASA/GSFC Emeritus\n";
