<?php
include("conexion.php");

// Recibir datos del formulario y normalizar
$nombre     = ucwords(strtoupper(trim($_POST["txt_nombre"])));
$tipo        = $_POST["cbo_tipo"];

// Verificar si el nombre del juzgado ya existe
$sql_check = "SELECT nombre_juzgado FROM juzgado WHERE nombre_juzgado = '$nombre'";
$result_check = mysqli_query($cn, $sql_check);

if (mysqli_num_rows($result_check) > 0) {
    echo "<script>
        alert('El juzgado ya existe. No se puede registrar nuevamente.');
        window.location='../code_front/menu_admin.php?vista=crear_juzgado';
    </script>";
    exit;
}

// Insertar en juzgado
$sql_insert_juzgado = "INSERT INTO juzgado (
    nombre_juzgado, descripcion_juzgado, tipo_juzgado
) VALUES (
    '$nombre', '', '$tipo'
)";
mysqli_query($cn, $sql_insert_juzgado);

// Confirmación y redirección
echo "<script>
    alert('Juzgado creado con éxito.');
    window.location='../code_front/menu_admin.php?vista=crear_juzgado';
</script>";
exit;
?>
