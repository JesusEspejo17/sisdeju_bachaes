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
