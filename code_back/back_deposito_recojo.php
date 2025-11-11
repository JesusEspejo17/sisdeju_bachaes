<?php
// back_deposito_recojo.php (versión: guarda NOMBRE de usuario en historial en vez del documento)
header('Content-Type: application/json; charset=utf-8');

if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/conexion.php';

if (!isset($_SESSION['documento'], $_SESSION['rol'])) {
  http_response_code(401);
  echo json_encode(['ok' => false, 'msg' => 'Sesión no iniciada']);
  exit;
}

// Solo secretarios (rol 3) por defecto - ajusta si tu rol es distinto
if ((int)$_SESSION['rol'] !== 3) {
  http_response_code(403);
  echo json_encode(['ok' => false, 'msg' => 'No autorizado']);
  exit;
}

$usuario = $_SESSION['documento'];
$id_deposito = isset($_POST['id_deposito']) ? (int) $_POST['id_deposito'] : 0;
$fecha_recojo = isset($_POST['fecha_recojo']) ? trim($_POST['fecha_recojo']) : '';

if ($id_deposito <= 0 || $fecha_recojo === '') {
  http_response_code(400);
  echo json_encode(['ok' => false, 'msg' => 'Datos incompletos (id_deposito y fecha_recojo son requeridos)']);
  exit;
}

// Interpretar la fecha en zona America/Lima
$tz = new DateTimeZone('America/Lima');
$fecha_dt = DateTime::createFromFormat('Y-m-d\TH:i', $fecha_recojo, $tz);
if (!$fecha_dt) {
  try {
    $fecha_dt = new DateTime($fecha_recojo, $tz);
  } catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'msg' => 'Formato de fecha inválido']);
    exit;
  }
}
$now = new DateTime('now', $tz);
if ($fecha_dt < $now) {
  http_response_code(400);
  echo json_encode(['ok' => false, 'msg' => 'La fecha de recojo no puede ser anterior a la fecha actual']);
  exit;
}

$fecha_db = $fecha_dt->format('Y-m-d H:i:s');

// aceptar estado_nuevo opcional desde el cliente (ej: 8 para reprogramación)
$estado_nuevo = isset($_POST['estado_nuevo']) ? (int) $_POST['estado_nuevo'] : 5;
// validar valores permitidos (ajusta la lista según tu catálogo de estados)
$allowed = [5,6,8,9]; // ejemplo; al menos permitir 5 y 8
if (!in_array($estado_nuevo, $allowed, true)) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'msg'=>'Valor de estado_nuevo no permitido']);
    exit;
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$inTx = false;

try {
  // Verificar existencia del depósito y obtener estado/n_deposito actuales
  $q = $cn->prepare("SELECT id_estado, COALESCE(n_deposito, '') AS n_deposito FROM deposito_judicial WHERE id_deposito = ?");
  if (!$q) throw new Exception("Error preparando verificación: " . $cn->error);
  $q->bind_param("i", $id_deposito);
  $q->execute();
  $resQ = $q->get_result();
  if ($resQ->num_rows === 0) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'msg' => 'No se encontró el depósito indicado']);
    exit;
  }
  $rowDeposit = $resQ->fetch_assoc();
  $estado_anterior_actual = isset($rowDeposit['id_estado']) ? (int)$rowDeposit['id_estado'] : null;
  $n_deposito_actual = isset($rowDeposit['n_deposito']) && $rowDeposit['n_deposito'] !== '' ? $rowDeposit['n_deposito'] : null;
  $q->close();

  // --- Obtener nombre completo del usuario (persona) para guardar en historial en lugar del documento ---
  $usuarioNombre = null;
  $pstmt = $cn->prepare("SELECT nombre_persona, apellido_persona FROM persona WHERE documento = ? LIMIT 1");
  if ($pstmt) {
    $pstmt->bind_param("s", $usuario);
    $pstmt->execute();
    $resPersona = $pstmt->get_result();
    if ($resPersona && $resPersona->num_rows > 0) {
      $rowPersona = $resPersona->fetch_assoc();
      $nombreParte = trim((string)($rowPersona['nombre_persona'] ?? ''));
      $apellidoParte = trim((string)($rowPersona['apellido_persona'] ?? ''));
      $full = trim($nombreParte . ' ' . $apellidoParte);
      if ($full !== '') $usuarioNombre = $full;
    }
    $pstmt->close();
  }
  // si no encontramos nombre, fallback al documento (para no dejar vacío)
  if (!$usuarioNombre) $usuarioNombre = $usuario;

  // comenzar transacción
  $cn->begin_transaction();
  $inTx = true;

  // detectar columnas existentes en historial_deposito
  $schemaSql = "
    SELECT COLUMN_NAME
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'historial_deposito'
  ";
  $schemaStmt = $cn->prepare($schemaSql);
  if (!$schemaStmt) throw new Exception("Error consultando esquema: " . $cn->error);
  $schemaStmt->execute();
  $resCols = $schemaStmt->get_result();
  $cols = [];
  while ($r = $resCols->fetch_assoc()) {
    $cols[] = $r['COLUMN_NAME'];
  }
  $schemaStmt->close();

  // Mapear variantes de nombres de columna que puedas tener
  $colComentario = in_array('comentario_deposito', $cols, true) ? 'comentario_deposito' : (in_array('comentario', $cols, true) ? 'comentario' : null);
  $colUsuario    = in_array('documento_usuario', $cols, true) ? 'documento_usuario' : (in_array('usuario', $cols, true) ? 'usuario' : null);
  $colNDeposito  = in_array('n_deposito', $cols, true) ? 'n_deposito' : null;
  $colEstadoAnt  = in_array('estado_anterior', $cols, true) ? 'estado_anterior' : null;
  $colEstadoNew  = in_array('estado_nuevo', $cols, true) ? 'estado_nuevo' : null;
  $colTipoEvento = in_array('tipo_evento', $cols, true) ? 'tipo_evento' : null;
  $colFechaHist  = in_array('fecha_historial_deposito', $cols, true) ? 'fecha_historial_deposito' : null;

  // Preparar INSERT dinámico
  $insertCols = [];
  $insertPlaceholders = [];
  $bindTypes = '';
  $bindParams = [];

  // id_deposito siempre
  $insertCols[] = 'id_deposito';
  $insertPlaceholders[] = '?';
  $bindTypes .= 'i';
  $bindParams[] = $id_deposito;

  // fecha_historial_deposito -> si existe incluimos NOW()
  if ($colFechaHist) {
    $insertCols[] = $colFechaHist;
    $insertPlaceholders[] = 'NOW()';
    // no bind param para NOW()
  }

  // tipo_evento
  if ($colTipoEvento) {
    $insertCols[] = $colTipoEvento;
    $insertPlaceholders[] = '?';
    $bindTypes .= 's';
    $bindParams[] = 'CAMBIO_ESTADO';
  }

  // n_deposito (si existe en historial)
  if ($colNDeposito) {
    $insertCols[] = $colNDeposito;
    $insertPlaceholders[] = '?';
    $bindTypes .= 's';
    $bindParams[] = $n_deposito_actual !== null ? (string)$n_deposito_actual : '';
  }

  // estado_anterior (si existe)
  if ($colEstadoAnt) {
    $insertCols[] = $colEstadoAnt;
    $insertPlaceholders[] = '?';
    $bindTypes .= 'i';
    $bindParams[] = $estado_anterior_actual !== null ? (int)$estado_anterior_actual : null;
  }

  // estado_nuevo (si existe)
  if ($colEstadoNew) {
    $insertCols[] = $colEstadoNew;
    $insertPlaceholders[] = '?';
    $bindTypes .= 'i';
    $bindParams[] = (int)$estado_nuevo;
  }

  // comentario (si existe) -> ahora incluyendo NOMBRE en vez de solo documento
  if ($colComentario) {
    $insertCols[] = $colComentario;
    $insertPlaceholders[] = '?';
    $bindTypes .= 's';
    // Uso el NOMBRE del usuario en el comentario (ya obtenido arriba)
    $comentario = "FECHA RECOJO programada por secretario: {$usuarioNombre} (fecha: {$fecha_db})";
    $bindParams[] = $comentario;
  }

  // usuario/documento_usuario (si existe) -> guardamos el NOMBRE (según tu solicitud)
  if ($colUsuario) {
    $insertCols[] = $colUsuario;
    $insertPlaceholders[] = '?';
    $bindTypes .= 's';
    // Aquí guardamos NOMBRE en vez del documento. Si prefieres guardar documento, reemplaza $usuarioNombre por $usuario.
    $bindParams[] = (string)$usuarioNombre;
  }

  // Construir la consulta INSERT final:
  if (count($insertCols) === 0) {
    throw new Exception("No se detectaron columnas válidas para insertar en historial_deposito");
  }

  $sqlInsert = "INSERT INTO historial_deposito (" . implode(',', $insertCols) . ") VALUES (" . implode(',', $insertPlaceholders) . ")";
  $stmt = $cn->prepare($sqlInsert);
  if ($stmt === false) throw new Exception("Error preparando INSERT historial: " . $cn->error);

  // bind_param dinámico (solo si hay parámetros)
  if (strlen($bindTypes) > 0) {
    // preparar array de referencias
    $refs = [];
    $refs[] = & $bindTypes;
    for ($i = 0; $i < count($bindParams); $i++) {
      $refs[] = & $bindParams[$i];
    }
    call_user_func_array([$stmt, 'bind_param'], $refs);
  }

  $stmt->execute();
  $id_historial_inserted = $cn->insert_id;
  $stmt->close();

  // actualizar deposito_judicial con nuevo estado y fecha_recojo
  $stmt2 = $cn->prepare("UPDATE deposito_judicial SET id_estado = ?, fecha_recojo_deposito = ? WHERE id_deposito = ?");
  if ($stmt2 === false) throw new Exception("Error preparando UPDATE deposito: " . $cn->error);
  $stmt2->bind_param("isi", $estado_nuevo, $fecha_db, $id_deposito);
  $stmt2->execute();
  $stmt2->close();

  // confirmar transacción
  $cn->commit();
  $inTx = false;

  // Responder OK con datos útiles (incluyendo id_historial insertado) y nombre del usuario
  echo json_encode([
    'ok' => true,
    'msg' => 'Fecha de recojo programada',
    'id_deposito' => $id_deposito,
    'fecha_recojo' => $fecha_db,
    'estado_nuevo' => $estado_nuevo,
    'estado_anterior' => $estado_anterior_actual,
    'n_deposito' => $n_deposito_actual,
    'id_historial' => $id_historial_inserted,
    'usuario_nombre' => $usuarioNombre
  ]);
  exit;

} catch (Exception $e) {
  if ($inTx) {
    $cn->rollback();
    $inTx = false;
  }
  error_log("back_deposito_recojo error: " . $e->getMessage());
  http_response_code(500);
  echo json_encode(['ok' => false, 'msg' => 'Error del servidor: ' . $e->getMessage()]);
  exit;
}