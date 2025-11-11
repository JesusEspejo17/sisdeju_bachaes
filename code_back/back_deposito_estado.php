<?php
include("conexion.php");

$n_deposito = $_POST["n_deposito"] ?? '';
if (!$n_deposito) {
    echo json_encode(["estado" => null]);
    exit;
}

$sql = "SELECT id_estado FROM deposito_judicial WHERE n_deposito = '$n_deposito'";
$res = mysqli_query($cn, $sql);
$row = mysqli_fetch_assoc($res);

echo json_encode(["estado" => $row['id_estado'] ?? null]);
