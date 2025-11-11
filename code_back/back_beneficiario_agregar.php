<?php
session_start();
include("conexion.php"); // CORRECTO: estás en /code_back

// Validar datos
$dni       = trim($_POST["txt_dni"]);
$nombre    = ucwords(strtolower(trim($_POST["txt_nombre"])));
$apellidos = ucwords(strtolower(trim($_POST["txt_apellidos"])));
$tele      = trim($_POST["txt_telefono"]);
$email     = strtolower(trim($_POST["txt_email"]));
$documento = (int)$_POST["cbo_documento"];
$direccion = strtolower(trim($_POST["txt_direccion"]));

// Verificar si el beneficiario ya existe
$sql_check = "SELECT documento FROM persona WHERE documento = '$dni'";
$result_check = mysqli_query($cn, $sql_check);

if (mysqli_num_rows($result_check) > 0) {
    $_SESSION['swal'] = [
        'title' => 'Ya existe',
        'text'  => 'El beneficiario ya está registrado.',
        'icon'  => 'warning'
    ];
    header("Location: ../code_front/menu_admin.php?vista=agregar_beneficiario");
    exit;
}

// Insertar en 'persona'
$sql_insert_persona = "INSERT INTO persona (documento, nombre_persona, apellido_persona, correo_persona, telefono_persona, id_documento, direccion_persona)
VALUES ('$dni', '$nombre', '$apellidos', '$email', '$tele', $documento, '$direccion')";
mysqli_query($cn, $sql_insert_persona);

// Insertar en 'beneficiario'
$sql_insert_beneficiario = "INSERT INTO beneficiario (id_documento, documento)
VALUES ($documento, '$dni')";
mysqli_query($cn, $sql_insert_beneficiario);

// Mensaje de éxito
$_SESSION['swal'] = [
    'title' => '✅ Éxito',
    'text'  => 'Beneficiario registrado correctamente.',
    'icon'  => 'success'
];

// Redirigir a la vista del formulario
header("Location: ../code_front/menu_admin.php?vista=agregar_beneficiario");
exit;
?>
