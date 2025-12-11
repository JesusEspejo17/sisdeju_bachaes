<?php
include("conexion.php");
session_start();
header("Content-Type: application/json");

// Verificar autenticación
if (!isset($_SESSION['documento']) || !isset($_SESSION['rol'])) {
  echo json_encode(["success" => false, "msg" => "No autorizado. Sesión no iniciada."]);
  exit;
}

$usuario = $_SESSION['documento'];
$rol = $_SESSION['rol'];

// Verificar que sea rol MAU (2) o AOP (4)
if (!in_array($rol, [2, 4])) {
  echo json_encode(["success" => false, "msg" => "No tiene permisos para revertir depósitos. Solo roles MAU y AOP."]);
  exit;
}

// Obtener datos del POST
$id_dep = $_POST["id_deposito"] ?? '';

// Validar datos requeridos
if (!$id_dep) {
  echo json_encode(["success" => false, "msg" => "Datos incompletos. Se requiere ID depósito."]);
  exit;
}

// Siempre revertir al estado 2 (Por Entregar) - estado inmediatamente anterior a Entregado
$estadoAnteriorInt = 2;
$motivoReversion = "Reversión de estado ENTREGADO a estado anterior";

try {
  mysqli_begin_transaction($cn);

  // Verificar que el depósito existe y está en estado ENTREGADO (id_estado = 1)
  $checkSql = "SELECT id_deposito, n_deposito, id_estado 
               FROM deposito_judicial 
               WHERE id_deposito = '$id_dep'
               FOR UPDATE";
  $checkResult = mysqli_query($cn, $checkSql);
  
  if (!$checkResult || mysqli_num_rows($checkResult) === 0) {
    throw new Exception("El depósito no existe.");
  }
  
  $deposito = mysqli_fetch_assoc($checkResult);
  
  if ((int)$deposito['id_estado'] !== 1) {
    throw new Exception("El depósito no está en estado ENTREGADO. Solo se pueden revertir depósitos entregados.");
  }

  // Obtener nombre completo del usuario que está revirtiendo
  $qUser = mysqli_query($cn, "SELECT CONCAT(nombre_persona,' ',apellido_persona) AS nombre 
                              FROM persona 
                              WHERE documento = '$usuario' 
                              LIMIT 1");
  $rowUser = mysqli_fetch_assoc($qUser);
  $nombreUsuario = $rowUser['nombre'] ?? $usuario;

  // Obtener nombre del estado anterior
  $qEstado = mysqli_query($cn, "SELECT nombre_estado FROM estado WHERE id_estado = $estadoAnteriorInt LIMIT 1");
  $rowEstado = mysqli_fetch_assoc($qEstado);
  $nombreEstadoAnterior = $rowEstado['nombre_estado'] ?? "Estado $estadoAnteriorInt";

  // Actualizar el estado del depósito (solo cambiar el estado, sin tocar fecha_finalizacion)
  $updateSql = "UPDATE deposito_judicial 
                SET id_estado = $estadoAnteriorInt
                WHERE id_deposito = '$id_dep'";
  
  if (!mysqli_query($cn, $updateSql)) {
    throw new Exception("Error al actualizar el estado del depósito.");
  }
  
  // Registrar la reversión en el historial
  $comentario = "REVERSION: De ENTREGADO a $nombreEstadoAnterior por $nombreUsuario";
  $comentarioEscapado = mysqli_real_escape_string($cn, $comentario);
  
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
    'REVERSION_ESTADO',
    1,
    $estadoAnteriorInt,
    '$comentarioEscapado'
  )";
  
  if (!mysqli_query($cn, $ins_hist)) {
    throw new Exception("Error al registrar en historial.");
  }

  mysqli_commit($cn);
  
  echo json_encode([
    "success" => true, 
    "msg" => "✅ Depósito revertido exitosamente a '$nombreEstadoAnterior'.",
    "nuevo_estado" => $estadoAnteriorInt
  ]);

} catch (Exception $e) {
  mysqli_rollback($cn);
  echo json_encode(["success" => false, "msg" => "❌ " . $e->getMessage()]);
}
?>
