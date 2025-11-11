<?php
session_start();
include("../code_back/conexion.php");

if (!$cn) {
    $_SESSION['swal'] = [
        'title' => 'Error de conexión',
        'text' => 'No se pudo conectar con la base de datos.',
        'icon' => 'error'
    ];
    header("Location: ../code_front/menu_admin.php?vista=listado_beneficiarios");
    exit;
}

if (!isset($_GET["documento"])) {
    $_SESSION['swal'] = [
        'title' => 'Error',
        'text' => 'Documento no proporcionado.',
        'icon' => 'error'
    ];
    header("Location: ../code_front/menu_admin.php?vista=listado_beneficiarios");
    exit;
}

$documento = mysqli_real_escape_string($cn, $_GET["documento"]);
$sql_check = "SELECT documento FROM beneficiario WHERE documento = '$documento'";
$res = mysqli_query($cn, $sql_check);

if (!$res || mysqli_num_rows($res) == 0) {
    $_SESSION['swal'] = [
        'title' => 'No encontrado',
        'text' => 'El beneficiario no existe.',
        'icon' => 'warning'
    ];
    header("Location: ../code_front/menu_admin.php?vista=listado_beneficiarios");
    exit;
}

// Eliminación
mysqli_query($cn, "DELETE FROM beneficiario WHERE documento = '$documento'");
mysqli_query($cn, "DELETE FROM persona WHERE documento = '$documento'");

$_SESSION['swal'] = [
    'title' => 'Eliminado',
    'text' => 'El beneficiario fue eliminado correctamente.',
    'icon' => 'success'
];

header("Location: ../code_front/menu_admin.php?vista=listado_beneficiarios");
exit;
?>
