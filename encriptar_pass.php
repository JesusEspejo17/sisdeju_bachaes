<?php
include("code_back/conexion.php");


// Obtener todos los usuarios
$sql = "SELECT codigo_usu, password_usu FROM usuario";
$result = mysqli_query($cn, $sql);

if (!$result) {
    die("Error al consultar usuarios: " . mysqli_error($cn));
}

$actualizados = 0;
$omitidos = 0;

while ($row = mysqli_fetch_assoc($result)) {
    $codigo = $row["codigo_usu"];
    $pass_plano = $row["password_usu"];

    // Si ya está hasheada (empieza con $2y$), no la tocamos
    if (strpos($pass_plano, '$2y$') === 0) {
        $omitidos++;
        continue;
    }

    // Encriptar y actualizar
    $hashed = password_hash($pass_plano, PASSWORD_DEFAULT);
    $update = "UPDATE usuario SET password_usu = '$hashed' WHERE codigo_usu = '$codigo'";
    
    if (mysqli_query($cn, $update)) {
        $actualizados++;
    } else {
        echo "Error al actualizar $codigo: " . mysqli_error($cn) . "<br>";
    }
}

echo "<h3>Proceso completado</h3>";
echo "Contraseñas actualizadas: $actualizados<br>";
echo "Contraseñas ya encriptadas (omitidas): $omitidos<br>";

mysqli_close($cn);
?>
