<?php
/**
 * back_deposito_marcar_atendido.php
 * 
 * Permite al MAU (rol=2) marcar un depósito OBSERVADO como OBSERVACIÓN ATENDIDA.
 * 
 * - Valida sesión y rol
 * - Valida que estado_observacion sea 11 (OBSERVADO)
 * - Actualiza estado_observacion=12, fecha_atencion_observacion=NOW()
 * - Inserta registro en historial_deposito
 * - NO modifica id_estado (permanece el estado del proceso)
 * 
 * Recibe POST:
 *  - id_deposito (int)
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

// Solo el MAU (rol 2) puede marcar como ATENDIDO
if ($idRol !== 2) {
  echo json_encode([
    'success' => false,
    'message' => 'Acción no permitida. Solo el MAU puede marcar observaciones como ATENDIDAS.'
  ]);
  exit;
}

// ============================
// 2. RECIBIR Y VALIDAR DATOS
// ============================
$idDeposito = isset($_POST['id_deposito']) ? (int)$_POST['id_deposito'] : 0;

if ($idDeposito <= 0) {
  echo json_encode([
    'success' => false,
    'message' => 'ID de depósito inválido.'
  ]);
  exit;
}

// ============================
// 3. VERIFICAR DEPÓSITO Y ESTADO
// ============================
$stmtVerificar = $cn->prepare("
  SELECT 
    id_deposito,
    id_estado,
    estado_observacion,
    motivo_observacion,
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
$estadoObservacion = $deposito['estado_observacion'] ? (int)$deposito['estado_observacion'] : null;
$motivoObservacion = $deposito['motivo_observacion'];
$nDeposito = $deposito['n_deposito'];
$nExpediente = $deposito['n_expediente'];

// Validar que esté en estado OBSERVADO (11)
if ($estadoObservacion !== 11) {
  echo json_encode([
    'success' => false,
    'message' => 'Solo se pueden marcar como ATENDIDAS las observaciones en estado OBSERVADO.'
  ]);
  exit;
}

// ============================
// 4. ACTUALIZAR DEPÓSITO
// ============================
mysqli_begin_transaction($cn);

try {
  // Actualizar estado_observacion = 12 (OBSERVACIÓN ATENDIDA)
  $stmtUpdate = $cn->prepare("
    UPDATE deposito_judicial
    SET 
      estado_observacion = 12,
      fecha_atencion_observacion = NOW()
    WHERE id_deposito = ?
  ");
  $stmtUpdate->bind_param('i', $idDeposito);
  
  if (!$stmtUpdate->execute()) {
    throw new Exception('Error al actualizar el depósito: ' . $stmtUpdate->error);
  }

  // ============================
  // 5. INSERTAR EN HISTORIAL
  // ============================
  $accion = "OBSERVACIÓN ATENDIDA";
  $detalleHistorial = "Observación atendida por el MAU. Motivo original: " . $motivoObservacion;

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
    'message' => "Observación del depósito {$nDeposito} del expediente {$nExpediente} marcada como ATENDIDA correctamente."
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
