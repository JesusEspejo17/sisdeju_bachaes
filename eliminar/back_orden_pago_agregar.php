<?php
include("conexion.php");

// Datos del formulario
$n_deposito  = $_POST["n_deposito"];
$id_juzgado  = $_POST["juzgado"];
$secretario  = $_POST["secretario"];

// Verificamos si se agregó un nuevo beneficiario o se eligió uno existente
if (isset($_POST["doc_beneficiario"])) {
    $dni_benef = $_POST["doc_beneficiario"];
    $nombre_benef = $_POST["nombre_beneficiario"];
    $apellido_benef = $_POST["apellido_beneficiario"];

    // Insertar en persona (si no existe)
    $sql_check_persona = "SELECT documento FROM persona WHERE documento = '$dni_benef'";
    $r_check = mysqli_query($cn, $sql_check_persona);

    if (mysqli_num_rows($r_check) == 0) {
        $sql_insert_persona = "INSERT INTO persona (documento, nombre_persona, apellido_persona) 
                               VALUES ('$dni_benef', '$nombre_benef', '$apellido_benef')";
        mysqli_query($cn, $sql_insert_persona);
    }

    // Insertar en beneficiario (si no existe)
    $sql_check_benef = "SELECT documento FROM beneficiario WHERE documento = '$dni_benef'";
    $r_check_benef = mysqli_query($cn, $sql_check_benef);

    if (mysqli_num_rows($r_check_benef) == 0) {
        $sql_insert_benef = "INSERT INTO beneficiario (documento) VALUES ('$dni_benef')";
        mysqli_query($cn, $sql_insert_benef);
    }

} else {
    $dni_benef = $_POST["beneficiario"];
}

// Validación de campos obligatorios
if ($n_deposito === '' || $id_juzgado === '' || $secretario === '' || $dni_benef === '') {
    echo "<script>
        alert('Complete todos los campos obligatorios');
        history.back();
    </script>";
    exit;
}

// Obtener datos del depósito
$sql_dep = "SELECT n_expediente, cantidad_pago_deposito FROM deposito_judicial WHERE n_deposito = $n_deposito LIMIT 1";
$r_dep = mysqli_query($cn, $sql_dep);
if (!$r_dep || mysqli_num_rows($r_dep) == 0) {
    echo "<script>
        alert('Depósito no válido.');
        history.back();
    </script>";
    exit;
}

$dep_data = mysqli_fetch_assoc($r_dep);
$n_expediente = $dep_data["n_expediente"];
$cantidad_pagos = $dep_data["cantidad_pago_deposito"];

// Generar número de orden de pago
$n_orden_pago = $n_deposito . '-' . $cantidad_pagos;

// Verificar si ya existe esa orden de pago
$sql_check_op = "SELECT 1 FROM orden_pago WHERE n_orden_pago = '$n_orden_pago' LIMIT 1";
$r_check_op = mysqli_query($cn, $sql_check_op);

if (mysqli_num_rows($r_check_op) > 0) {
    echo "<script>
        alert('Este depósito ya tiene registrada una orden de pago.');
        history.back();
    </script>";
    exit;
}

// Insertar orden de pago
$sql_insert = "INSERT INTO orden_pago (
    n_orden_pago,
    fecha_orden_pago,
    n_deposito,
    id_juzgado,
    secretario,
    documento_beneficario,
    id_estado
) VALUES (
    '$n_orden_pago',
    NULL,
    $n_deposito,
    $id_juzgado,
    '$secretario',
    '$dni_benef',
    3
)";
$res_insert = mysqli_query($cn, $sql_insert);

if ($res_insert) {
    // Asociar beneficiario al expediente (si aún no está asociado)
    $sql_assoc = "INSERT IGNORE INTO expediente_beneficiario (n_expediente, documento_beneficiario) VALUES ('$n_expediente', '$dni_benef')";
    mysqli_query($cn, $sql_assoc);

    echo "<script>
        alert('Orden registrada con éxito.');
        window.location = '../code_front/menu_admin.php?vista=listado_op';
    </script>";
} else {
    echo "<script>
        alert('Error al registrar la orden.');
        history.back();
    </script>";
}
?>
