<?php
// back_deposito_registrar.php (correcciones: bind dinámico por referencia + logging reforzado)
date_default_timezone_set('America/Lima');
session_start();
include("conexion.php");
header("Content-Type: application/json; charset=utf-8");

// Habilitar reporting para detectar errores SQL en dev (comentar en prod si querés)
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// Entradas
$n_dep   = isset($_POST['n_deposito']) ? trim($_POST['n_deposito']) : '';
$id_dep  = isset($_POST['id_deposito']) ? trim($_POST['id_deposito']) : '';
$usuario = $_SESSION['documento'] ?? '';

error_log("back_deposito_registrar: inicio - n_deposito={$n_dep} id_deposito={$id_dep} usuario=" . ($usuario ?: 'NULL'));

// Validaciones básicas
if (!$n_dep && !$id_dep) {
  echo json_encode(["success" => false, "msg" => "Falta n_deposito o id_deposito."]);
  exit;
}
if (!$usuario) {
  echo json_encode(["success" => false, "msg" => "Usuario no autenticado."]);
  exit;
}
if ($n_dep !== '' && !preg_match('/^\d{13}$/', $n_dep) && !preg_match('/^\d{13}-\d{3}$/', $n_dep)) {
  echo json_encode(["success" => false, "msg" => "n_deposito inválido (debe ser formato XXXXXXXXXXXXX o XXXXXXXXXXXXX-XXX)."]);
  exit;
}

// Helper: obtener fila de un stmt con fallback si mysqli_stmt_get_result no está disponible
function fetch_assoc_from_stmt($stmt) {
  if (function_exists('mysqli_stmt_get_result')) {
    $res = mysqli_stmt_get_result($stmt);
    if ($res !== false && $res !== null) {
      return mysqli_fetch_assoc($res);
    }
  }
  mysqli_stmt_store_result($stmt);
  $meta = mysqli_stmt_result_metadata($stmt);
  if (!$meta) return null;
  $fields = [];
  $row = [];
  while ($field = mysqli_fetch_field($meta)) {
    $fields[] = &$row[$field->name];
  }
  mysqli_free_result($meta);
  if (empty($fields)) return null;
  call_user_func_array('mysqli_stmt_bind_result', array_merge([$stmt], $fields));
  if (mysqli_stmt_fetch($stmt)) {
    $assoc = [];
    foreach ($row as $k => $v) $assoc[$k] = $v;
    return $assoc;
  }
  return null;
}

// Helper seguro para bind dinámico por referencia (compatible con varias versiones PHP)
// IMPORTANTE: $params se pasa POR REFERENCIA para que las referencias ligadas sobrevivan hasta el execute
function stmt_bind_params(&$stmt, $types, array &$params) {
    if ($types === '' || empty($params)) {
        return true;
    }
    // construir array de referencias hacia elementos de $params
    $refs = [];
    $refs[] = $types;
    for ($i = 0; $i < count($params); $i++) {
        // cada elemento debe pasarse por referencia
        $refs[] = &$params[$i];
    }
    return call_user_func_array('mysqli_stmt_bind_param', array_merge([$stmt], $refs));
}

// Helper para comprobar existencia de columna
function column_exists($cn, $table, $column) {
  $q = mysqli_prepare($cn, "SHOW COLUMNS FROM `$table` LIKE ?");
  if (!$q) return false;
  mysqli_stmt_bind_param($q, "s", $column);
  mysqli_stmt_execute($q);
  $res = mysqli_stmt_get_result($q);
  $exists = ($res && mysqli_num_rows($res) > 0);
  if ($res) mysqli_free_result($res);
  mysqli_stmt_close($q);
  return $exists;
}

try {
  if (!mysqli_begin_transaction($cn)) {
    mysqli_query($cn, "START TRANSACTION");
  }

  // 1) SELECT ... FOR UPDATE (por id_deposito o por n_deposito)
  if ($id_dep !== '') {
    $id_dep_int = (int)$id_dep;
    $selSql = "SELECT id_deposito, n_deposito, id_estado FROM deposito_judicial WHERE id_deposito = ? FOR UPDATE";
    $selStmt = mysqli_prepare($cn, $selSql);
    if (!$selStmt) throw new Exception("Error al preparar SELECT por id_deposito: " . mysqli_error($cn));
    mysqli_stmt_bind_param($selStmt, "i", $id_dep_int);
  } else {
    $selSql = "SELECT id_deposito, n_deposito, id_estado FROM deposito_judicial WHERE n_deposito = ? FOR UPDATE";
    $selStmt = mysqli_prepare($cn, $selSql);
    if (!$selStmt) throw new Exception("Error al preparar SELECT por n_deposito: " . mysqli_error($cn));
    mysqli_stmt_bind_param($selStmt, "s", $n_dep);
  }

  if (!mysqli_stmt_execute($selStmt)) {
    throw new Exception("Error al ejecutar SELECT: " . mysqli_error($cn));
  }

  $row = fetch_assoc_from_stmt($selStmt);
  mysqli_stmt_close($selStmt);

  if (!$row) {
    mysqli_rollback($cn);
    error_log("back_deposito_registrar: fila NO encontrada (id_dep={$id_dep} n_dep={$n_dep}).");
    echo json_encode(["success" => false, "msg" => "Depósito no encontrado (id_deposito: '{$id_dep}' / n_deposito: '{$n_dep}')."]);
    exit;
  }

  $rowId       = (int)$row['id_deposito'];
  $currentNdep = $row['n_deposito'];
  $estadoPrev  = isset($row['id_estado']) ? (int)$row['id_estado'] : null;

  error_log("back_deposito_registrar: fila encontrada id={$rowId} currentNdep=" . ($currentNdep ?: 'NULL') . " estadoPrev=" . ($estadoPrev ?? 'NULL'));

  // 2) Si se recibió n_deposito distinto -> comprobar duplicado
  if ($n_dep && $n_dep !== $currentNdep) {
    $chkSql = "SELECT id_deposito FROM deposito_judicial WHERE n_deposito = ? AND id_deposito <> ? LIMIT 1";
    $chkStmt = mysqli_prepare($cn, $chkSql);
    if (!$chkStmt) throw new Exception("Error al preparar comprobación duplicado: " . mysqli_error($cn));
    mysqli_stmt_bind_param($chkStmt, "si", $n_dep, $rowId);
    if (!mysqli_stmt_execute($chkStmt)) throw new Exception("Error al ejecutar comprobación duplicado: " . mysqli_error($cn));
    $dup = fetch_assoc_from_stmt($chkStmt);
    mysqli_stmt_close($chkStmt);
    if ($dup) {
      throw new Exception("El número de depósito $n_dep ya está asignado al depósito ID " . $dup['id_deposito']);
    }
  }

  // 3) UPDATE: n_deposito (si corresponde) y estado = 2 (si no está)  -- ahora con binding seguro
  $needsUpdate = false;
  $sets = [];
  $params = [];
  $types = "";

  if ($n_dep && $n_dep !== $currentNdep) {
    $sets[] = "n_deposito = ?";
    $params[] = $n_dep; $types .= "s";
    $needsUpdate = true;
  }
  if ($estadoPrev !== 2) {
    $sets[] = "id_estado = 2";
    $needsUpdate = true;
  }

  if ($needsUpdate) {
    $updSql = "UPDATE deposito_judicial SET " . implode(", ", $sets) . " WHERE id_deposito = ?";
    $params[] = $rowId; $types .= "i";
    $updStmt = mysqli_prepare($cn, $updSql);
    if (!$updStmt) throw new Exception("Error al preparar UPDATE: " . mysqli_error($cn));

    // uso de bind dinámico seguro: PASO $params POR REFERENCIA
    if (!stmt_bind_params($updStmt, $types, $params)) {
        throw new Exception("Error bind params UPDATE: " . mysqli_error($cn));
    }
    if (!mysqli_stmt_execute($updStmt)) {
      $err = mysqli_error($cn);
      mysqli_stmt_close($updStmt);
      throw new Exception("Error al actualizar depósito: $err");
    }
    mysqli_stmt_close($updStmt);
    error_log("back_deposito_registrar: UPDATE ejecutado para id={$rowId}");
  } else {
    error_log("back_deposito_registrar: no es necesario UPDATE para id={$rowId}");
  }

  // 4) Insertar historial si el estado anterior no era 2
  $finalNdep = $n_dep ? $n_dep : $currentNdep;
  if ($estadoPrev !== 2) {

    // DETECTAR si la tabla historial_deposito tiene columna id_deposito
    $histHasIdDep = false;
    $resShow = mysqli_query($cn, "SHOW COLUMNS FROM `historial_deposito` LIKE 'id_deposito'");
    if ($resShow && mysqli_num_rows($resShow) > 0) $histHasIdDep = true;
    if ($resShow) mysqli_free_result($resShow);

    $comentario = "Orden registrada por usuario " . ($usuario ?: 'sistema');
    $estadoAnteriorForInsert = $estadoPrev !== null ? $estadoPrev : 3;

    if ($histHasIdDep) {
      // Insert usando id_deposito (columna nueva)
      $insSql = "INSERT INTO historial_deposito (id_deposito, documento_usuario, fecha_historial_deposito, tipo_evento, estado_anterior, estado_nuevo, comentario_deposito) VALUES (?, ?, NOW(), 'CAMBIO_ESTADO', ?, 2, ?)";
      $insStmt = mysqli_prepare($cn, $insSql);
      if (!$insStmt) throw new Exception("Error al preparar INSERT historial (id_deposito): " . mysqli_error($cn));
      $iddepParam = $rowId;
      mysqli_stmt_bind_param($insStmt, "isis", $iddepParam, $usuario, $estadoAnteriorForInsert, $comentario);
    } else {
      // Insert clásico usando n_deposito
      $insSql = "INSERT INTO historial_deposito (n_deposito, documento_usuario, fecha_historial_deposito, tipo_evento, estado_anterior, estado_nuevo, comentario_deposito) VALUES (?, ?, NOW(), 'CAMBIO_ESTADO', ?, 2, ?)";
      $insStmt = mysqli_prepare($cn, $insSql);
      if (!$insStmt) throw new Exception("Error al preparar INSERT historial: " . mysqli_error($cn));
      mysqli_stmt_bind_param($insStmt, "ssis", $finalNdep, $usuario, $estadoAnteriorForInsert, $comentario);
    }

    if (!mysqli_stmt_execute($insStmt)) {
      $err = mysqli_error($cn);
      mysqli_stmt_close($insStmt);
      throw new Exception("Error al insertar historial: $err");
    }
    mysqli_stmt_close($insStmt);
    error_log("back_deposito_registrar: historial insertado para id={$rowId} finalNdep={$finalNdep}");
  } else {
    error_log("back_deposito_registrar: estadoPrev ya era 2, no se inserta historial para id={$rowId}");
  }

  mysqli_commit($cn);

  // Devolver info clara al frontend
  echo json_encode([
    "success" => true,
    "msg" => "Orden registrada y estado actualizado.",
    "updated" => true,
    "registered" => true,
    "id_deposito" => $rowId,
    "n_deposito" => $finalNdep
  ]);
  exit;

} catch (Exception $e) {
  @mysqli_rollback($cn);
  $errm = $e->getMessage();
  error_log("back_deposito_registrar ERROR: " . $errm . " - mysqli_error: " . mysqli_error($cn));
  echo json_encode(["success" => false, "msg" => "❌ " . $errm]);
  exit;
}