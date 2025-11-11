<?php
include("../code_back/conexion.php");

if (!isset($_GET["documento"])) {
    echo "<script>alert('Documento no proporcionado.'); window.location='../code_front/menu_admin.php?vista=listado_beneficiarios';</script>";
    exit;
}

$documento = $_GET["documento"];

// Consulta
$sql = "SELECT 
            p.documento,
            p.nombre_persona,
            p.apellido_persona,
            p.correo_persona,
            p.telefono_persona,
            p.direccion_persona,
            d.tipo_documento
        FROM persona p
        INNER JOIN documento d ON p.id_documento = d.id_documento
        WHERE p.documento = '$documento'";
$res = mysqli_query($cn, $sql);
$beneficiario = mysqli_fetch_assoc($res);

if (!$beneficiario) {
    echo "<script>alert('Beneficiario no encontrado.'); window.location='../code_front/menu_admin.php?vista=listado_beneficiarios';</script>";
    exit;
}

// Para inputs readonly, si está vacío, se pone como placeholder visual
function valorCampo($valor) {
    $valor = trim($valor);
    return $valor !== '' ? $valor : '';
}

function placeholderCampo($valor, $etiqueta) {
    return trim($valor) === '' ? "placeholder='$etiqueta no registrado'" : "";
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Ver Beneficiario</title>
  <link rel="stylesheet" href="../css/crear_usuario.css">
</head>
<body>

<div class="main-container">
  <h1>Información del Beneficiario</h1>

  <form>

    <!-- Tipo de documento -->
    <div class="form-row">
      <input type="text" value="<?php echo valorCampo($beneficiario['tipo_documento']); ?>" 
             <?php echo placeholderCampo($beneficiario['tipo_documento'], 'Tipo de documento'); ?> readonly>
    </div>

    <!-- Documento -->
    <div class="form-row">
      <input type="text" value="<?php echo valorCampo($beneficiario['documento']); ?>" 
             <?php echo placeholderCampo($beneficiario['documento'], 'Documento'); ?> readonly>
    </div>

    <!-- Nombre y Apellido -->
    <div class="form-row">
      <input type="text" value="<?php echo valorCampo($beneficiario['nombre_persona']); ?>" 
             <?php echo placeholderCampo($beneficiario['nombre_persona'], 'Nombre'); ?> readonly>
      <input type="text" value="<?php echo valorCampo($beneficiario['apellido_persona']); ?>" 
             <?php echo placeholderCampo($beneficiario['apellido_persona'], 'Apellido'); ?> readonly>
    </div>

    <!-- Correo y Teléfono -->
    <div class="form-row">
      <input type="text" value="<?php echo valorCampo($beneficiario['correo_persona']); ?>" 
             <?php echo placeholderCampo($beneficiario['correo_persona'], 'Correo'); ?> readonly>
      <input type="text" value="<?php echo valorCampo($beneficiario['telefono_persona']); ?>" 
             <?php echo placeholderCampo($beneficiario['telefono_persona'], 'Teléfono'); ?> readonly>
    </div>

    <!-- Dirección -->
    <div class="form-row">
      <input type="text" value="<?php echo valorCampo($beneficiario['direccion_persona']); ?>" 
             <?php echo placeholderCampo($beneficiario['direccion_persona'], 'Dirección'); ?> readonly>
    </div>

    <!-- Botón de volver -->
    <div class="form-row">
      <input type="button" class="btn-agregar" value="Volver al listado" onclick="window.location.href='../code_front/menu_admin.php?vista=listado_beneficiarios'">
    </div>

  </form>
</div>

</body>
</html>
