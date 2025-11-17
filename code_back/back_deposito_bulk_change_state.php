<?php
// code_back/back_deposito_bulk_change_state.php (CORREGIDO: no usa fecha_actualizacion)
header('Content-Type: application/json; charset=utf-8');
if (session_status() === PHP_SESSION_NONE) session_start();
include("conexion.php"); // debe dejar $cn como la conexión mysqli

// debug helper
function dbg($msg) {
    $f = '/tmp/bulk_change_debug.log';
    // intenta escribir, si falla intenta en carpeta del proyecto
    @file_put_contents($f, date('[Y-m-d H:i:s] ').$msg.PHP_EOL, FILE_APPEND);
}

dbg("REQUEST start. Remote: " . ($_SERVER['REMOTE_ADDR'] ?? 'cli') . " URI: " . ($_SERVER['REQUEST_URI'] ?? ''));

// leer raw
$raw = file_get_contents('php://input');
dbg("RAW INPUT: " . (strlen($raw) > 0 ? substr($raw,0,2000) : '(empty)'));

$data = json_decode($raw, true);

if (is_array($data) && count($data)) {
    $ids = isset($data['ids']) ? (array)$data['ids'] : (isset($data['ids[]']) ? (array)$data['ids[]'] : []);
    $estado_nuevo = $data['estado_nuevo'] ?? $data['estado'] ?? null;
} else {
    // fallback a $_POST (form-data)
    $ids = isset($_POST['ids']) ? (array)$_POST['ids'] : (isset($_POST['ids[]']) ? (array)$_POST['ids[]'] : []);
    $estado_nuevo = $_POST['estado_nuevo'] ?? $_POST['estado'] ?? null;
}

if (!is_array($ids)) $ids = [$ids];

// normalizar ints
$idsClean = [];
foreach ($ids as $v) {
    if ($v === '' || $v === null) continue;
    if (is_numeric($v)) $idsClean[] = (int)$v;
}
$idsClean = array_values(array_unique($idsClean));

dbg("Parsed ids: " . json_encode($idsClean) . " estado_nuevo: " . json_encode($estado_nuevo));

if (empty($idsClean) || !$estado_nuevo) {
    http_response_code(400);
    $resp = ['ok' => false, 'msg' => 'ids vacíos o estado faltante', 'received' => ['ids' => $idsClean, 'estado' => $estado_nuevo]];
    dbg("RESPONSE early: " . json_encode($resp));
    echo json_encode($resp);
    exit;
}

$estado_nuevo = (int)$estado_nuevo;

// build IN list (seguros porque son ints)
$inList = implode(',', array_map('intval', $idsClean));

// SQL CORREGIDO: solo actualiza id_estado (sin fecha_actualizacion)
$sql = "UPDATE deposito_judicial SET id_estado = ? WHERE id_deposito IN ($inList)";

dbg("SQL: $sql");

// verificar conexión
if (!isset($cn) || !($cn instanceof mysqli)) {
    dbg("ERROR: DB connection variable \$cn not found or no mysqli instance. Dumping var names.");
    http_response_code(500);
    echo json_encode(['ok' => false, 'msg' => 'DB connection missing or invalid', 'hint' => 'verificar include("conexion.php") y variable $cn (mysqli)']);
    exit;
}

// preparar statement
$stmt = @mysqli_prepare($cn, $sql);
if (!$stmt) {
    $err = mysqli_error($cn);
    dbg("Prepare failed: $err");
    http_response_code(500);
    echo json_encode(['ok' => false, 'msg' => 'Prepare fallo: ' . $err]);
    exit;
}

// bind and execute (solo un parámetro: estado_nuevo)
if (!mysqli_stmt_bind_param($stmt, 'i', $estado_nuevo)) {
    $err = mysqli_stmt_error($stmt);
    dbg("Bind param failed: $err");
    http_response_code(500);
    echo json_encode(['ok' => false, 'msg' => 'Bind fallo: ' . $err]);
    mysqli_stmt_close($stmt);
    exit;
}

if (!mysqli_stmt_execute($stmt)) {
    $err = mysqli_stmt_error($stmt);
    dbg("Execute failed: $err");
    http_response_code(500);
    echo json_encode(['ok' => false, 'msg' => 'Execute fallo: ' . $err]);
    mysqli_stmt_close($stmt);
    exit;
}

$affected = mysqli_stmt_affected_rows($stmt);
mysqli_stmt_close($stmt);

// ========== RESETEAR OBSERVACIÓN ATENDIDA (estado_observacion=12) PARA TODOS LOS DEPÓSITOS ==========
// Verificar qué depósitos tienen estado_observacion=12 y resetearlos
$checkObsSql = "SELECT id_deposito, motivo_observacion FROM deposito_judicial 
                WHERE id_deposito IN ($inList) AND estado_observacion = 12";
$resObs = mysqli_query($cn, $checkObsSql);

if ($resObs && mysqli_num_rows($resObs) > 0) {
    // Obtener usuario actual para historial
    session_start();
    $usuario = $_SESSION['documento'] ?? 'SISTEMA';
    
    while ($obsRow = mysqli_fetch_assoc($resObs)) {
        $depId = $obsRow['id_deposito'];
        $motivoOriginal = $obsRow['motivo_observacion'] ? mysqli_real_escape_string($cn, $obsRow['motivo_observacion']) : 'Sin motivo registrado';
        
        // Resetear observación
        mysqli_query($cn, "UPDATE deposito_judicial SET estado_observacion = NULL, motivo_observacion = NULL WHERE id_deposito = $depId");
        
        // Registrar en historial
        $comentarioObsResuelto = mysqli_real_escape_string($cn, "Observación cerrada automáticamente al cambiar estado masivamente. Motivo original: {$motivoOriginal}");
        mysqli_query($cn, "
            INSERT INTO historial_deposito (id_deposito, documento_usuario, fecha_historial_deposito, tipo_evento, comentario_deposito)
            VALUES ($depId, '$usuario', NOW(), 'OBSERVACION_RESUELTA', '$comentarioObsResuelto')
        ");
    }
}
// ========== FIN RESETEO OBSERVACIÓN ==========

dbg("Updated affected rows: $affected");

echo json_encode(['ok' => true, 'msg' => "Actualizados $affected filas", 'processed' => $idsClean, 'count' => $affected]);
exit;
