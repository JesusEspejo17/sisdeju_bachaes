<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
include(__DIR__ . '/../../code_back/conexion.php'); // ✅ NUNCA falla

if (!isset($_SESSION['rol']) || !in_array($_SESSION['rol'], [1, 2])) {
    if (!headers_sent()) {
        header("Location: ../menu_admin.php");
        exit;
    } else {
        echo "<script>alert('Acceso denegado. Esta vista está restringida para su rol.'); window.location='../menu_admin.php';</script>";
        exit;
    }
}

// Obtener tipos de documento
$sql_documento = "SELECT * FROM documento";
$arreglo_documento = mysqli_query($cn, $sql_documento);
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Agregar Beneficiario</title>
  <link rel="stylesheet" href="../css/crear_usuario.css" />
  <script src="js/jquery.min.js"></script>
  <script src="../js/sweetalert2.all.min.js"></script>
</head>
<body>

<div class="main-container">
  <h1>Agregar a un Beneficiario</h1>

  <form action="../code_back/back_beneficiario_agregar.php" method="post" enctype="multipart/form-data"  autocomplete="off" >
  
    <div class="form-row">
      <select name="cbo_documento" id="cbo_documento" required >
        <option value="" disabled selected>Seleccione el tipo de documento</option>
        <?php while ($r = mysqli_fetch_assoc($arreglo_documento)) { ?>
          <option value="<?php echo $r["id_documento"]; ?>" required autocomplete="off" ><?php echo $r["tipo_documento"]; ?> </option>
        <?php } ?>
      </select>
    </div>

    <!-- DNI y Nombre -->
    <div class="form-row" >
      <input type="text" name="txt_dni" id="dni_ruc" placeholder="DNI o RUC" required autocomplete="off"/>
      <input type="text" name="txt_nombre" placeholder="Nombre" required autocomplete="off"/>
    </div>

    <!-- Apellidos -->
    <div class="form-row">
      <input type="text" name="txt_apellidos" placeholder="Apellidos" required autocomplete="off"/>
    </div>

    <!-- Correo y Teléfono -->
    <div class="form-row">
      <input type="email" name="txt_email" placeholder="Correo Electrónico - Opcional" autocomplete="off"/>
      <input type="tel" name="txt_telefono" placeholder="Teléfono - Opcional" autocomplete="off"/>
    </div>

    <!-- Dirección -->
    <div class="form-row">
      <input type="text" name="txt_direccion" placeholder="Dirección del beneficiario - Opcional" autocomplete="off"/>
    </div>

    <!-- Botón -->
    <div class="form-row">
      <input type="submit" value="Agregar Beneficiario" />
    </div>

  </form>
</div>

<?php if (isset($_SESSION['swal'])): ?>
<script>
  Swal.fire({
    title: '<?= $_SESSION['swal']['title'] ?>',
    text: '<?= $_SESSION['swal']['text'] ?>',
    icon: '<?= $_SESSION['swal']['icon'] ?>',
    confirmButtonText: 'Aceptar'
  });
</script>
<?php unset($_SESSION['swal']); ?>
<?php endif; ?>

<script src="../js/agregar_beneficiario.js" defer></script>
</body>
</html>
