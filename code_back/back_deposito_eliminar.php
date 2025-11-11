<?php
// code_back/back_deposito_eliminar.php
header('Content-Type: application/json; charset=utf-8');
session_start();
require_once __DIR__ . '/conexion.php';

// Validar sesión y rol …
$raw = file_get_contents('php://input');
// Graba un log para depurar:
//file_put_contents(__DIR__.'/debug_eliminar.log', date('c')." RAW_PAYLOAD: ".$raw."\n", FILE_APPEND);

// Intenta decodificar JSON
$data = json_decode($raw, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    // Si no era JSON, interpreta como URL‑encoded
    parse_str($raw, $data);
}

// Toma n_deposito desde JSON o desde $_POST
$n_deposito = '';
if (!empty($data['n_deposito'])) {
    $n_deposito = $data['n_deposito'];
} elseif (!empty($_POST['n_deposito'])) {
    $n_deposito = $_POST['n_deposito'];
}

if (!$n_deposito) {
    http_response_code(400);
    echo json_encode(["success" => false, "msg" => "Falta n_deposito"]);
    exit;
}

$n_deposito = mysqli_real_escape_string($cn, $n_deposito);

// 3) Transacción: borra historial y depósito
mysqli_begin_transaction($cn);
try {
    $q1 = "DELETE FROM historial_deposito WHERE n_deposito = '$n_deposito'";
    if (!mysqli_query($cn, $q1)) throw new Exception(mysqli_error($cn));

    $q2 = "DELETE FROM deposito_judicial WHERE n_deposito = '$n_deposito'";
    if (!mysqli_query($cn, $q2)) throw new Exception(mysqli_error($cn));

    mysqli_commit($cn);
    echo json_encode(["success" => true]);
} catch (Exception $e) {
    mysqli_rollback($cn);
    http_response_code(500);
    echo json_encode(["success" => false, "msg" => "Error al eliminar: " . $e->getMessage()]);
}
