<?php
session_start();
header('Content-Type: application/json');

include("../code_back/conexion.php");

$documento = $_SESSION['documento'] ?? null;

if (!$documento) {
    echo json_encode(['error' => 'Sin sesión']);
    exit;
}

// Revisa depósitos nuevos para el secretario
$sqlDepositos = "SELECT COUNT(*) AS total FROM deposito_judicial dj 
JOIN expediente ex ON ex.n_expediente = dj.n_expediente
WHERE dj.documento_secretario = '$documento' AND dj.id_estado IN (2, 3)";
$resDep = mysqli_query($cn, $sqlDepositos);
$nuevosDepositos = mysqli_fetch_assoc($resDep)['total'] ?? 0;

// Revisa mensajes nuevos no leídos
$sqlMensajes = "SELECT COUNT(*) AS total FROM chat WHERE receptor = '$documento' AND leido = 0";
$resMensajes = mysqli_query($cn, $sqlMensajes);
$nuevosMensajes = mysqli_fetch_assoc($resMensajes)['total'] ?? 0;

echo json_encode([
    'nuevosDepositos' => $nuevosDepositos,
    'nuevosMensajes' => $nuevosMensajes
]);
