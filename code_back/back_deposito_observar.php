<?php
/**
 * back_deposito_observar.php
 * 
 * Permite al Secretario (rol=3) marcar un depósito como OBSERVADO.
 * 
 * - Valida sesión y rol
 * - Actualiza estado_observacion=11, motivo_observacion, fecha_observacion=NOW()
 * - Inserta registro en historial_deposito
 * - NO modifica id_estado (permanece el estado del proceso)
 * 
 * Recibe POST:
 *  - id_deposito (int)
 *  - motivo_observacion (text)
 */

if (session_status() === PHP_SESSION_NONE) session_start();
require_once 'conexion.php';

header('Content-Type: application/json; charset=utf-8');

// ============================
// 1. VALIDACIÓN DE SESIÓN Y ROL
// ============================
if (!isset($_SESSION['documento']) || !isset($_SESSION['rol'])) {
  echo json_encode([
    'success' => false,
    'message' => 'Sesión no válida. Inicie sesión nuevamente.'
  ]);
  exit;
}

$documentoUsuario = $_SESSION['documento'];
$idRol = (int)$_SESSION['rol'];

// Solo el Secretario (rol 3) puede marcar como OBSERVADO
if ($idRol !== 3) {
  echo json_encode([
    'success' => false,
    'message' => 'Acción no permitida. Solo el Secretario puede marcar depósitos como OBSERVADO.'
  ]);
  exit;
}

// ============================
// 2. RECIBIR Y VALIDAR DATOS
// ============================
$idDeposito = isset($_POST['id_deposito']) ? (int)$_POST['id_deposito'] : 0;
$motivoObservacion = isset($_POST['motivo_observacion']) ? trim($_POST['motivo_observacion']) : '';

if ($idDeposito <= 0) {
  echo json_encode([
    'success' => false,
    'message' => 'ID de depósito inválido.'
  ]);
  exit;
}

if (empty($motivoObservacion)) {
  echo json_encode([
    'success' => false,
    'message' => 'Debe ingresar el motivo de la observación.'
  ]);
  exit;
}

// ============================
// 3. VERIFICAR DEPÓSITO EXISTE
// ============================
$stmtVerificar = $cn->prepare("
  SELECT 
    id_deposito,
    id_estado,
    estado_observacion,
    n_deposito,
    n_expediente
  FROM deposito_judicial
  WHERE id_deposito = ?
");
$stmtVerificar->bind_param('i', $idDeposito);
$stmtVerificar->execute();
$resultVerificar = $stmtVerificar->get_result();

if ($resultVerificar->num_rows === 0) {
  echo json_encode([
    'success' => false,
    'message' => 'Depósito no encontrado.'
  ]);
  exit;
}

$deposito = $resultVerificar->fetch_assoc();
$idEstado = (int)$deposito['id_estado'];
$estadoObservacionActual = $deposito['estado_observacion'] ? (int)$deposito['estado_observacion'] : null;
$nDeposito = $deposito['n_deposito'];
$nExpediente = $deposito['n_expediente'];

// No permitir observar depósitos ENTREGADOS (1) o ANULADOS (10)
if (in_array($idEstado, [1, 10])) {
  echo json_encode([
    'success' => false,
    'message' => 'No se puede observar un depósito ENTREGADO o ANULADO.'
  ]);
  exit;
}

// ============================
// 4. ACTUALIZAR DEPÓSITO
// ============================
mysqli_begin_transaction($cn);

try {
  // Actualizar estado_observacion = 11 (OBSERVADO)
  $stmtUpdate = $cn->prepare("
    UPDATE deposito_judicial
    SET 
      estado_observacion = 11,
      motivo_observacion = ?,
      fecha_observacion = NOW()
    WHERE id_deposito = ?
  ");
  $stmtUpdate->bind_param('si', $motivoObservacion, $idDeposito);
  
  if (!$stmtUpdate->execute()) {
    throw new Exception('Error al actualizar el depósito: ' . $stmtUpdate->error);
  }

  // ============================
  // 5. INSERTAR EN HISTORIAL
  // ============================
  $accion = "OBSERVADO";
  $detalleHistorial = "Depósito marcado como OBSERVADO por el Secretario. Motivo: " . $motivoObservacion;

  $stmtHistorial = $cn->prepare("
    INSERT INTO historial_deposito (
      id_deposito,
      documento_usuario,
      comentario_deposito,
      fecha_historial_deposito,
      tipo_evento
    ) VALUES (?, ?, ?, NOW(), ?)
  ");
  $stmtHistorial->bind_param('isss', $idDeposito, $documentoUsuario, $detalleHistorial, $accion);
  
  if (!$stmtHistorial->execute()) {
    throw new Exception('Error al insertar en historial: ' . $stmtHistorial->error);
  }

  // Confirmar transacción
  mysqli_commit($cn);

  echo json_encode([
    'success' => true,
    'message' => "Depósito {$nDeposito} del expediente {$nExpediente} marcado como OBSERVADO correctamente."
  ]);

} catch (Exception $e) {
  mysqli_rollback($cn);
  echo json_encode([
    'success' => false,
    'message' => 'Error: ' . $e->getMessage()
  ]);
}

mysqli_close($cn);
?>
