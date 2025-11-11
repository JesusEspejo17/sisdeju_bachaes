<?php
include("conexion.php");

// Recibir datos del formulario y normalizar
$dni        = trim($_POST["documento"]);
$nombre     = ucwords(strtolower(trim($_POST["txt_nombre"])));
$apellidos  = ucwords(strtolower(trim($_POST["txt_apellidos"])));
$telefono   = trim($_POST["txt_telefono"]);
$email      = strtolower(trim($_POST["txt_email"]));
$rol        = (int)$_POST["cbo_rol"];
$passwordRaw = trim($_POST["txt_password"]);
$juzgados   = isset($_POST["cbo_juzgados_final"]) && is_array($_POST["cbo_juzgados_final"]) ? $_POST["cbo_juzgados_final"] : [];

// Si no se ingresó contraseña, usar el DNI
$password = empty($passwordRaw) ? $dni : $passwordRaw;
$hashed_password = password_hash($password, PASSWORD_DEFAULT);

// Verificar si el usuario ya existe
$sql_check = "SELECT documento FROM persona WHERE documento = '$dni'";
$result_check = mysqli_query($cn, $sql_check);

if (mysqli_num_rows($result_check) > 0) {
    echo "<script>
        alert('El usuario ya existe. No se puede registrar nuevamente.');
        window.location='../code_front/menu_admin.php?vista=crear_usuario';
    </script>";
    exit;
}

// Insertar en persona (id_documento = 1 → DNI por defecto)
$sql_insert_persona = "INSERT INTO persona (
    documento, nombre_persona, apellido_persona, correo_persona, telefono_persona, id_documento
) VALUES (
    '$dni', '$nombre', '$apellidos', '$email', '$telefono', 1
)";
mysqli_query($cn, $sql_insert_persona);

// Insertar en usuario (sin juzgado directo)
$sql_insert_usuario = "INSERT INTO usuario (
    codigo_usu, password_usu, id_rol
) VALUES (
    '$dni', '$hashed_password', $rol
)";
mysqli_query($cn, $sql_insert_usuario);

// Insertar múltiples juzgados en tabla pivote
if (!empty($juzgados)) {
    foreach ($juzgados as $id_juzgado) {
        $id_juzgado = (int)$id_juzgado;
        $sql_insert_pivote = "INSERT INTO usuario_juzgado (codigo_usu, id_juzgado)
                              VALUES ('$dni', $id_juzgado)";
        mysqli_query($cn, $sql_insert_pivote);
    }
}

// Confirmación y redirección
echo "<script>
    alert('Usuario creado con éxito.');
    window.location='../code_front/menu_admin.php?vista=listado_usuarios';
</script>";
exit;
?>
