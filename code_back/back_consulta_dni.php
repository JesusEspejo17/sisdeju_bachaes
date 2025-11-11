<?php
// back_consulta_dni.php
// Proxy seguro para consultar API de DNI (apis.net.pe en este ejemplo).
// Devuelve JSON con estructura:
// { success: true, source: 'api'|'cache', data: { numero, nombres, apellidoPaterno, apellidoMaterno, apellidos } }
// En errores: { success: false, code: 'INVALID'|'RATE_LIMIT'|'API_ERROR'|'NOT_FOUND', message: '...' }

header('Content-Type: application/json; charset=utf-8');

define('API_URL', 'https://api.apis.net.pe/v1/dni'); // endpoint del proveedor
define('CACHE_FILE', sys_get_temp_dir() . '/dni_cache.json');
define('CACHE_TTL', 60 * 60 * 24); // 24 horas
define('MAX_REQUESTS_PER_MIN', 10); // rate limit simple por sesión

if (session_status() === PHP_SESSION_NONE) session_start();

// (Opcional) exigir usuario autenticado
// if (!isset($_SESSION['rol'])) { http_response_code(403); echo json_encode(['success'=>false,'message'=>'No autorizado']); exit; }

// Leer DNI desde POST
$dni = isset($_POST['dni']) ? trim($_POST['dni']) : '';

// Validación básica
if (!preg_match('/^\d{8}$/', $dni)) {
    echo json_encode(['success' => false, 'code' => 'INVALID', 'message' => 'DNI inválido (debe tener 8 dígitos).']);
    exit;
}

// Rate limiting simple por sesión
if (!isset($_SESSION['dni_requests'])) $_SESSION['dni_requests'] = [];
// limpiar entradas antiguas (>60s)
$_SESSION['dni_requests'] = array_filter($_SESSION['dni_requests'], function($t) { return $t >= time() - 60; });
if (count($_SESSION['dni_requests']) >= MAX_REQUESTS_PER_MIN) {
    echo json_encode(['success' => false, 'code' => 'RATE_LIMIT', 'message' => 'Demasiadas peticiones. Intenta en un minuto.']);
    exit;
}
$_SESSION['dni_requests'][] = time();

// Cargar cache
$cache = [];
if (file_exists(CACHE_FILE)) {
    $raw = @file_get_contents(CACHE_FILE);
    $cache = $raw ? json_decode($raw, true) : [];
    if (!is_array($cache)) $cache = [];
}

// Retornar cache si vigente
if (isset($cache[$dni]) && (time() - intval($cache[$dni]['ts']) < CACHE_TTL)) {
    $data = $cache[$dni]['data'];
    // sanitizar salida (protege contra XSS)
    array_walk_recursive($data, function(&$v){ if (is_string($v)) $v = htmlspecialchars($v, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); });
    echo json_encode(['success' => true, 'source' => 'cache', 'data' => $data]);
    exit;
}

// Llamada al proveedor con cURL
$endpoint = API_URL . '?numero=' . urlencode($dni);
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $endpoint);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 8);
curl_setopt($ch, CURLOPT_FAILONERROR, false);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json']);
$resp = curl_exec($ch);
$curlErr = curl_error($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($curlErr || !$resp || $httpCode >= 400) {
    echo json_encode(['success' => false, 'code' => 'API_ERROR', 'message' => 'No se pudo consultar el servicio externo.']);
    exit;
}

$parsed = json_decode($resp, true);
if ($parsed === null) {
    echo json_encode(['success' => false, 'code' => 'API_INVALID', 'message' => 'Respuesta inválida del proveedor.']);
    exit;
}

// Estructura esperada ejemplo apis.net.pe:
// { "numero":"...","nombres":"JUAN CARLOS","apellidoPaterno":"PEREZ","apellidoMaterno":"LOPEZ" }
$nombre = isset($parsed['nombres']) ? trim($parsed['nombres']) : '';
$apat   = isset($parsed['apellidoPaterno']) ? trim($parsed['apellidoPaterno']) : '';
$amat   = isset($parsed['apellidoMaterno']) ? trim($parsed['apellidoMaterno']) : '';

// Si no hay datos suficientes, devolver NOT_FOUND
if (empty($nombre) && empty($apat) && empty($amat)) {
    echo json_encode(['success' => false, 'code' => 'NOT_FOUND', 'message' => 'No se encontraron datos para ese DNI.']);
    exit;
}

// Construir resultado (incluye campo 'apellidos' unido por conveniencia)
$result = [
    'numero' => $dni,
    'nombres' => $nombre,
    'apellidoPaterno' => $apat,
    'apellidoMaterno' => $amat,
    'apellidos' => trim($apat . ' ' . $amat)
];

// Guardar en cache (silencioso)
try {
    $cache[$dni] = ['ts' => time(), 'data' => $result];
    @file_put_contents(CACHE_FILE, json_encode($cache), LOCK_EX);
} catch (Exception $e) { /* ignore cache errors */ }

// Sanitizar salida
array_walk_recursive($result, function(&$v){ if (is_string($v)) $v = htmlspecialchars($v, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); });

echo json_encode(['success' => true, 'source' => 'api', 'data' => $result]);
exit;
