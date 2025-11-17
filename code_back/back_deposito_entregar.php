<?php
include("conexion.php");
session_start();
header("Content-Type: application/json");

$id_dep = $_POST["id_deposito"] ?? '';
$usuario = $_SESSION["documento"] ?? '';

if (!$id_dep || !$usuario) {
  echo json_encode(["success" => false, "msg" => "Datos incompletos."]);
  exit;
}

try {
  mysqli_begin_transaction($cn);

  // buscar nombre completo del usuario
  $qUser = mysqli_query($cn, "SELECT CONCAT(nombre_persona,' ',apellido_persona) AS nombre 
                              FROM persona 
                              WHERE documento = '$usuario' 
                              LIMIT 1");
  $rowUser = mysqli_fetch_assoc($qUser);
  $nombreUsuario = $rowUser['nombre'] ?? $usuario;

  $comentario = "Orden entregada al usuario por $nombreUsuario";

  $sql = "UPDATE deposito_judicial SET id_estado = 1 WHERE id_deposito = '$id_dep'";
  if (!mysqli_query($cn, $sql)) throw new Exception("Error al cambiar estado");

  // ========== RESETEAR OBSERVACIÓN ATENDIDA (estado_observacion=12) ==========
  $checkObs = mysqli_query($cn, "SELECT estado_observacion, motivo_observacion FROM deposito_judicial WHERE id_deposito = '$id_dep'");
  $obsData = mysqli_fetch_assoc($checkObs);
  
  if ($obsData && $obsData['estado_observacion'] == 12) {
    // Resetear observación
    mysqli_query($cn, "UPDATE deposito_judicial SET estado_observacion = NULL, motivo_observacion = NULL WHERE id_deposito = '$id_dep'");
    
    // Registrar en historial
    $motivoOriginal = $obsData['motivo_observacion'] ? mysqli_real_escape_string($cn, $obsData['motivo_observacion']) : 'Sin motivo registrado';
    $comentarioObsResuelto = "Observación cerrada automáticamente al cambiar estado. Motivo original: {$motivoOriginal}";
    
    mysqli_query($cn, "
      INSERT INTO historial_deposito (id_deposito, documento_usuario, fecha_historial_deposito, tipo_evento, comentario_deposito)
      VALUES ('$id_dep', '$usuario', NOW(), 'OBSERVACION_RESUELTA', '$comentarioObsResuelto')
    ");
  }
  // ========== FIN RESETEO OBSERVACIÓN ==========

  $ins_hist = "INSERT INTO historial_deposito (
    id_deposito,
    documento_usuario,
    fecha_historial_deposito,
    tipo_evento,
    estado_anterior,
    estado_nuevo,
    comentario_deposito
  ) VALUES (
    '$id_dep',
    '$usuario',
    NOW(),
    'CAMBIO_ESTADO',
    2,
    1,
    '$comentario'
  )";
  if (!mysqli_query($cn, $ins_hist)) throw new Exception("Error al guardar historial");

  mysqli_commit($cn);
  echo json_encode(["success" => true, "msg" => "Orden entregada correctamente."]);
} catch (Exception $e) {
  mysqli_rollback($cn);
  echo json_encode(["success" => false, "msg" => "❌ " . $e->getMessage()]);
}
