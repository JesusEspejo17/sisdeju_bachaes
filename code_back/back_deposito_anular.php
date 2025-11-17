<?php
// back_deposito_anular.php
// Anulación lógica de un depósito (cambiar estado a 10 - ANULADO)

error_reporting(0);
ini_set('display_errors', 0);

session_start();
include("conexion.php");

header('Content-Type: application/json; charset=utf-8');

// Validar sesión y permisos
if (!isset($_SESSION['documento']) || !isset($_SESSION['rol'])) {
  echo json_encode(['success' => false, 'msg' => 'Sesión no iniciada.']);
  exit;
}

$usuario = $_SESSION['documento'];
$rolUsuario = (int)$_SESSION['rol'];

// Solo admin (1), MAU (2) y secretarios (3) pueden anular depósitos
if (!in_array($rolUsuario, [1, 2, 3])) {
  echo json_encode(['success' => false, 'msg' => 'No tiene permisos para anular depósitos.']);
  exit;
}

// Validar que se envió el ID del depósito
if (!isset($_POST['id_deposito'])) {
  echo json_encode(['success' => false, 'msg' => 'Falta el ID del depósito.']);
  exit;
}

$idDeposito = (int)$_POST['id_deposito'];
$motivo = isset($_POST['motivo']) ? trim($_POST['motivo']) : 'Anulación sin motivo especificado';

if ($idDeposito <= 0) {
  echo json_encode(['success' => false, 'msg' => 'ID de depósito inválido.']);
  exit;
}

if (!($cn instanceof mysqli)) {
  echo json_encode(['success' => false, 'msg' => 'Error de conexión a la base de datos.']);
  exit;
}

try {
  $cn->begin_transaction();

  // 1) Verificar que el depósito existe y obtener estado actual
  $stmt = $cn->prepare("SELECT id_deposito, id_estado, n_deposito, n_expediente 
                        FROM deposito_judicial 
                        WHERE id_deposito = ? 
                        LIMIT 1");
  $stmt->bind_param("i", $idDeposito);
  
  if (!$stmt->execute()) {
    throw new Exception('Error al buscar el depósito.');
  }
  
  $result = $stmt->get_result();
  if (!$result || $result->num_rows === 0) {
    $cn->rollback();
    echo json_encode(['success' => false, 'msg' => 'Depósito no encontrado.']);
    exit;
  }
  
  $deposito = $result->fetch_assoc();
  $estadoAnterior = (int)$deposito['id_estado'];
  $nDeposito = $deposito['n_deposito'];
  $nExpediente = $deposito['n_expediente'];
  $stmt->close();

  // 2) Validar que el depósito no esté ya anulado
  if ($estadoAnterior === 10) {
    $cn->rollback();
    echo json_encode(['success' => false, 'msg' => 'El depósito ya está anulado.']);
    exit;
  }

  // 3) Validar que el depósito no esté entregado (estado 1)
  if ($estadoAnterior === 1) {
    $cn->rollback();
    echo json_encode(['success' => false, 'msg' => 'No se puede anular un depósito que ya fue entregado.']);
    exit;
  }

  // 4) Actualizar estado a ANULADO (10)
  $stmt = $cn->prepare("UPDATE deposito_judicial SET id_estado = 10 WHERE id_deposito = ?");
  $stmt->bind_param("i", $idDeposito);
  
  if (!$stmt->execute()) {
    $cn->rollback();
    throw new Exception('Error al actualizar el estado del depósito.');
  }
  $stmt->close();

  // ========== RESETEAR OBSERVACIÓN ATENDIDA (estado_observacion=12) ==========
  $checkObs = $cn->prepare("SELECT estado_observacion, motivo_observacion FROM deposito_judicial WHERE id_deposito = ?");
  $checkObs->bind_param("i", $idDeposito);
  $checkObs->execute();
  $resObs = $checkObs->get_result();
  $obsData = $resObs->fetch_assoc();
  $checkObs->close();
  
  if ($obsData && $obsData['estado_observacion'] == 12) {
    // Resetear observación
    $resetObs = $cn->prepare("UPDATE deposito_judicial SET estado_observacion = NULL, motivo_observacion = NULL WHERE id_deposito = ?");
    $resetObs->bind_param("i", $idDeposito);
    $resetObs->execute();
    $resetObs->close();
    
    // Registrar en historial
    $motivoOriginal = $obsData['motivo_observacion'] ? mysqli_real_escape_string($cn, $obsData['motivo_observacion']) : 'Sin motivo registrado';
    $comentarioObsResuelto = "Observación cerrada automáticamente al anular depósito. Motivo original: {$motivoOriginal}";
    
    $stmtObsHist = $cn->prepare("
      INSERT INTO historial_deposito (id_deposito, documento_usuario, fecha_historial_deposito, tipo_evento, comentario_deposito)
      VALUES (?, ?, NOW(), 'OBSERVACION_RESUELTA', ?)
    ");
    $stmtObsHist->bind_param("iss", $idDeposito, $usuario, $comentarioObsResuelto);
    $stmtObsHist->execute();
    $stmtObsHist->close();
  }
  // ========== FIN RESETEO OBSERVACIÓN ==========

  // 5) Registrar en el historial
  $comentario = "Depósito ANULADO.\nEstado anterior: {$estadoAnterior}\nMotivo: {$motivo}";
  
  $stmt = $cn->prepare("
    INSERT INTO historial_deposito 
      (id_deposito, documento_usuario, comentario_deposito, fecha_historial_deposito, tipo_evento, estado_anterior, estado_nuevo) 
    VALUES (?, ?, ?, NOW(), 'CAMBIO_ESTADO', ?, 10)
  ");
  
  $stmt->bind_param("issi", $idDeposito, $usuario, $comentario, $estadoAnterior);
  
  if (!$stmt->execute()) {
    // No hacemos rollback completo si falla el historial
    $cn->commit();
    echo json_encode(['success' => true, 'msg' => 'Depósito anulado (historial no guardado).']);
    exit;
  }
  $stmt->close();

  // 6) Commit final
  $cn->commit();
  
  echo json_encode([
    'success' => true, 
    'msg' => 'Depósito anulado correctamente.',
    'deposito' => [
      'n_deposito' => $nDeposito,
      'n_expediente' => $nExpediente
    ]
  ]);
  exit;

} catch (Exception $ex) {
  if (isset($cn) && $cn instanceof mysqli) {
    $cn->rollback();
  }
  echo json_encode(['success' => false, 'msg' => 'Error del servidor: ' . $ex->getMessage()]);
  exit;
} catch (Error $err) {
  if (isset($cn) && $cn instanceof mysqli) {
    $cn->rollback();
  }
  echo json_encode(['success' => false, 'msg' => 'Error crítico: ' . $err->getMessage()]);
  exit;
}
?>
