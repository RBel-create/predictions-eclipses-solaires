<?php
/**
 * Prédictions d'Éclipses Solaires — API
 * Point d'entrée unique
 * 
 * Endpoints :
 *   api/index.php?action=list                    → liste des éclipses
 *   api/index.php?action=list&type=T             → filtrer par type
 *   api/index.php?action=list&from=2025&to=2040  → filtrer par période
 *   api/index.php?action=path&id=12              → tracé GeoJSON d'une éclipse
 *   api/index.php?action=besselian&id=12         → éléments de Bessel
 *   api/index.php?action=detail&id=12            → détail complet
 * 
 * Licence : GPL v3
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../../config.php';

try {
    $pdo = getDB();
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed']);
    exit;
}

$action = $_GET['action'] ?? '';

switch ($action) {
    case 'list':
        handleList($pdo);
        break;
    case 'path':
        handlePath($pdo);
        break;
    case 'besselian':
        handleBesselian($pdo);
        break;
    case 'detail':
        handleDetail($pdo);
        break;
    case 'countries':
        handleCountries($pdo);
        break;
    default:
        http_response_code(400);
        echo json_encode(['error' => 'Unknown action. Use: list, path, besselian, detail, countries']);
}

// --- Handlers ---

function handleList(PDO $pdo): void {
    $where = [];
    $params = [];
    
    // Filtre par type
    $type = $_GET['type'] ?? '';
    if ($type !== '') {
        $types = explode(',', strtoupper($type));
        $placeholders = [];
        foreach ($types as $i => $t) {
            $key = ":type$i";
            $placeholders[] = $key;
            $params[$key] = $t;
        }
        $where[] = 'eclipse_type IN (' . implode(',', $placeholders) . ')';
    }
    
    // Filtre par période
    $from = $_GET['from'] ?? '';
    $to = $_GET['to'] ?? '';
    if ($from !== '') {
        $where[] = 'YEAR(eclipse_date) >= :from_year';
        $params[':from_year'] = (int)$from;
    }
    if ($to !== '') {
        $where[] = 'YEAR(eclipse_date) <= :to_year';
        $params[':to_year'] = (int)$to;
    }
    
    // Filtre par pays
    $country = $_GET['country'] ?? '';
    if ($country !== '') {
        $where[] = "e.id IN (SELECT eclipse_id FROM eclipse_countries WHERE country_code = :country)";
        $params[':country'] = strtoupper($country);
    }
    
    // Filtre centrales uniquement (par défaut)
    $central = $_GET['central'] ?? '1';
    if ($central === '1') {
        $where[] = "e.eclipse_type IN ('T', 'A', 'H')";
        // Exclure les éclipses non-centrales déguisées (gamma > 1, pas de bande au sol)
        $where[] = "(e.path_width_km IS NULL OR e.path_width_km > 0)";
    }
    
    $sql = "SELECT e.id, e.eclipse_date, e.td_greatest_eclipse, e.eclipse_type,
                   e.saros_number, e.magnitude, e.gamma,
                   e.latitude, e.longitude, e.sun_altitude, e.sun_azimuth,
                   e.path_width_km, e.central_duration, e.central_duration_seconds
            FROM eclipses e";
    
    if (!empty($where)) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    
    $sql .= ' ORDER BY e.eclipse_date';
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $eclipses = $stmt->fetchAll();
    
    // Récupérer les pays pour toutes les éclipses d'un coup
    $eclipseIds = array_column($eclipses, 'id');
    $countriesByEclipse = [];
    
    if (!empty($eclipseIds)) {
        $inPlaceholders = implode(',', array_fill(0, count($eclipseIds), '?'));
        $cStmt = $pdo->prepare(
            "SELECT eclipse_id, country_code, country_name 
             FROM eclipse_countries 
             WHERE eclipse_id IN ($inPlaceholders)
             ORDER BY eclipse_id, sort_order"
        );
        $cStmt->execute($eclipseIds);
        
        foreach ($cStmt->fetchAll() as $row) {
            $eid = (int)$row['eclipse_id'];
            if (!isset($countriesByEclipse[$eid])) {
                $countriesByEclipse[$eid] = [];
            }
            $countriesByEclipse[$eid][] = [
                'code' => $row['country_code'],
                'name' => $row['country_name'],
            ];
        }
    }
    
    // Convertir les types numériques et ajouter les pays
    foreach ($eclipses as &$e) {
        $e['id'] = (int)$e['id'];
        $e['saros_number'] = $e['saros_number'] !== null ? (int)$e['saros_number'] : null;
        $e['magnitude'] = $e['magnitude'] !== null ? (float)$e['magnitude'] : null;
        $e['gamma'] = $e['gamma'] !== null ? (float)$e['gamma'] : null;
        $e['latitude'] = $e['latitude'] !== null ? (float)$e['latitude'] : null;
        $e['longitude'] = $e['longitude'] !== null ? (float)$e['longitude'] : null;
        $e['sun_altitude'] = $e['sun_altitude'] !== null ? (float)$e['sun_altitude'] : null;
        $e['sun_azimuth'] = $e['sun_azimuth'] !== null ? (float)$e['sun_azimuth'] : null;
        $e['path_width_km'] = $e['path_width_km'] !== null ? (float)$e['path_width_km'] : null;
        $e['central_duration_seconds'] = $e['central_duration_seconds'] !== null ? (int)$e['central_duration_seconds'] : null;
        $e['countries'] = $countriesByEclipse[(int)$e['id']] ?? [];
    }
    
    echo json_encode($eclipses, JSON_UNESCAPED_UNICODE);
}

function handleCountries(PDO $pdo): void {
    $sql = "SELECT c.country_code, c.country_name, COUNT(DISTINCT c.eclipse_id) as eclipse_count
            FROM eclipse_countries c
            INNER JOIN eclipses e ON c.eclipse_id = e.id
            WHERE (e.path_width_km IS NULL OR e.path_width_km > 0)
            GROUP BY c.country_code, c.country_name
            ORDER BY c.country_name, eclipse_count DESC";
    
    $rows = $pdo->query($sql)->fetchAll();
    
    foreach ($rows as &$r) {
        $r['eclipse_count'] = (int)$r['eclipse_count'];
    }
    
    echo json_encode($rows, JSON_UNESCAPED_UNICODE);
}

function handlePath(PDO $pdo): void {
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing or invalid id parameter']);
        return;
    }
    
    // Récupérer le type d'éclipse
    $stmt = $pdo->prepare("SELECT eclipse_type FROM eclipses WHERE id = :id");
    $stmt->execute([':id' => $id]);
    $eclipse = $stmt->fetch();
    
    if (!$eclipse) {
        http_response_code(404);
        echo json_encode(['error' => 'Eclipse not found']);
        return;
    }
    
    // Récupérer les coordonnées
    $stmt = $pdo->prepare(
        "SELECT north_lat, north_lon, south_lat, south_lon,
                central_lat, central_lon, path_width_km, central_duration,
                diameter_ratio, sun_altitude, sun_azimuth, time_ut
         FROM path_coordinates 
         WHERE eclipse_id = :id 
         ORDER BY sort_order"
    );
    $stmt->execute([':id' => $id]);
    $points = $stmt->fetchAll();
    
    if (empty($points)) {
        http_response_code(404);
        echo json_encode(['error' => 'No path data for this eclipse']);
        return;
    }
    
    // Construire le GeoJSON
    $centralCoords = [];
    $northCoords = [];
    $southCoords = [];
    
    foreach ($points as $p) {
        if ($p['central_lat'] !== null && $p['central_lon'] !== null) {
            $centralCoords[] = [(float)$p['central_lon'], (float)$p['central_lat']];
        }
        if ($p['north_lat'] !== null && $p['north_lon'] !== null) {
            $northCoords[] = [(float)$p['north_lon'], (float)$p['north_lat']];
        }
        if ($p['south_lat'] !== null && $p['south_lon'] !== null) {
            $southCoords[] = [(float)$p['south_lon'], (float)$p['south_lat']];
        }
    }
    
    $features = [];
    
    // Ligne centrale
    if (!empty($centralCoords)) {
        $features[] = [
            'type' => 'Feature',
            'properties' => [
                'role' => 'central_line',
                'eclipse_type' => $eclipse['eclipse_type'],
            ],
            'geometry' => [
                'type' => 'LineString',
                'coordinates' => $centralCoords,
            ],
        ];
    }
    
    // Limite nord
    if (!empty($northCoords)) {
        $features[] = [
            'type' => 'Feature',
            'properties' => ['role' => 'north_limit'],
            'geometry' => [
                'type' => 'LineString',
                'coordinates' => $northCoords,
            ],
        ];
    }
    
    // Limite sud
    if (!empty($southCoords)) {
        $features[] = [
            'type' => 'Feature',
            'properties' => ['role' => 'south_limit'],
            'geometry' => [
                'type' => 'LineString',
                'coordinates' => $southCoords,
            ],
        ];
    }
    
    // Polygone de la bande (nord + sud inversé)
    if (!empty($northCoords) && !empty($southCoords)) {
        $polygonCoords = array_merge($northCoords, array_reverse($southCoords));
        $polygonCoords[] = $northCoords[0]; // fermer le polygone
        
        $features[] = [
            'type' => 'Feature',
            'properties' => [
                'role' => 'umbral_path',
                'eclipse_type' => $eclipse['eclipse_type'],
            ],
            'geometry' => [
                'type' => 'Polygon',
                'coordinates' => [$polygonCoords],
            ],
        ];
    }
    
    $geojson = [
        'type' => 'FeatureCollection',
        'features' => $features,
    ];
    
    echo json_encode($geojson, JSON_UNESCAPED_UNICODE);
}

function handleBesselian(PDO $pdo): void {
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing or invalid id parameter']);
        return;
    }
    
    $stmt = $pdo->prepare(
        "SELECT * FROM besselian_elements WHERE eclipse_id = :id"
    );
    $stmt->execute([':id' => $id]);
    $data = $stmt->fetch();
    
    if (!$data) {
        http_response_code(404);
        echo json_encode(['error' => 'No Besselian elements for this eclipse']);
        return;
    }
    
    // Convertir en floats
    foreach ($data as $key => &$val) {
        if ($key === 'id' || $key === 'eclipse_id') {
            $val = (int)$val;
        } elseif ($val !== null) {
            $val = (float)$val;
        }
    }
    
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
}

function handleDetail(PDO $pdo): void {
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing or invalid id parameter']);
        return;
    }
    
    $stmt = $pdo->prepare("SELECT * FROM eclipses WHERE id = :id");
    $stmt->execute([':id' => $id]);
    $eclipse = $stmt->fetch();
    
    if (!$eclipse) {
        http_response_code(404);
        echo json_encode(['error' => 'Eclipse not found']);
        return;
    }
    
    echo json_encode($eclipse, JSON_UNESCAPED_UNICODE);
}
