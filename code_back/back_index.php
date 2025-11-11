<?php
session_start();
include("conexion.php");

$usu  = trim($_POST["usuario"]);
$pass = trim($_POST["password"]);

if (empty($usu) || empty($pass)) {
    header('Location: ../index.php?error=RELLENE_LOS_CAMPOS');
    exit();
}

// Buscar usuario por su código
$sql = "SELECT * FROM usuario WHERE codigo_usu = '$usu'";
$result = mysqli_query($cn, $sql);

if (!$result) {
    header('Location: ../index.php?error=ERROR_AL_BUSCAR_INGRESE_DE_NUEVO');
    exit();
}

$r = mysqli_fetch_assoc($result);

if (!$r || !password_verify($pass, $r["password_usu"])) {
    header('Location: ../index.php?error=USUARIO_O_CONTRA_INCORRECTA');
    exit();
}

// Usuario válido, guardar datos en sesión
$_SESSION["usuario"]  = $r["codigo_usu"];
$_SESSION["documento"] = $r["codigo_usu"]; // <--- Agregado
$_SESSION["auth"]     = 1;
$_SESSION["rol"]      = (int)$r["id_rol"];

header('Location: ../code_front/menu_admin.php?vista=listado_depositos');

?>
