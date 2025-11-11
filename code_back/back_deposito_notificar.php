<?php
include("conexion.php");
date_default_timezone_set("America/Lima");

if (session_status() === PHP_SESSION_NONE) session_start();

header('Content-Type: application/json');

// Validar sesión
if (!isset($_SESSION['documento'], $_SESSION['rol'])) {
    echo json_encode(["success" => false, "msg" => "Acceso no autorizado."]);
    exit;
}

$usuario = $_SESSION['documento'];

if (!isset($_POST["n_deposito"])) {
    echo json_encode(["success" => false, "msg" => "No se proporcionó el número de depósito."]);
    exit;
}

$n_deposito = trim($_POST["n_deposito"]);

// Obtener estado actual del depósito
$sql_verificar = "SELECT id_estado FROM deposito_judicial WHERE n_deposito = '$n_deposito'";
$res = mysqli_query($cn, $sql_verificar);

if (!$res || mysqli_num_rows($res) === 0) {
    echo json_encode(["success" => false, "msg" => "El depósito no existe."]);
    exit;
}

$row = mysqli_fetch_assoc($res);
$estado_anterior = (int)$row["id_estado"];

if ($estado_anterior !== 4) {
    echo json_encode(["success" => false, "msg" => "Este depósito ya fue notificado o no está listo para ser notificado."]);
    exit;
}

// Cambiar a estado 3 y guardar fecha
$estado_nuevo = 3;
$fecha_actual = date("Y-m-d H:i:s");

$sql_update = "UPDATE deposito_judicial 
               SET id_estado = $estado_nuevo, 
                   fecha_notificacion_deposito = '$fecha_actual'
               WHERE n_deposito = '$n_deposito'";

if (!mysqli_query($cn, $sql_update)) {
    echo json_encode(["success" => false, "msg" => "Error al actualizar el estado del depósito."]);
    exit;
}

// Insertar en historial_deposito
$comentario = "Depósito notificado por MAU";
$sql_historial = "INSERT INTO historial_deposito 
    (n_deposito, documento_usuario, comentario_deposito, fecha_historial_deposito, tipo_evento, estado_anterior, estado_nuevo)
    VALUES 
    ('$n_deposito', '$usuario', '$comentario', '$fecha_actual', 'CAMBIO_ESTADO', $estado_anterior, $estado_nuevo)";

if (!mysqli_query($cn, $sql_historial)) {
    echo json_encode(["success" => false, "msg" => "Error al registrar historial."]);
    exit;
}

echo json_encode(["success" => true, "msg" => "Depósito notificado y registrado en historial."]);
?>
