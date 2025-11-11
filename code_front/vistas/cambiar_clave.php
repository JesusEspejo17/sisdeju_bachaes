<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
include("../code_back/conexion.php");

$documento = $_SESSION['documento'] ?? null;
if (!$documento) {
    header("Location: ../login.php");
    exit;
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Cambiar Contraseña</title>
  <link rel="stylesheet" href="../css/crear_usuario.css">
  <script src="../js/sweetalert2.all.min.js"></script>
</head>
<body>
  <div class="main-container">
    <h1>Cambiar Contraseña</h1>
    <form id="form-cambiar-password" method="post">
      <div class="form-row">
        <input type="password" name="contrasena_actual" placeholder="Contraseña Actual" required>
      </div>
      <div class="form-row">
        <input type="password" name="nueva_contrasena" placeholder="Nueva Contraseña" required minlength="6">
      </div>
      <div class="form-row">
        <input type="password" name="confirmar_contrasena" placeholder="Confirmar Nueva Contraseña" required>
      </div>
      <div class="form-row">
        <input type="submit" value="Cambiar Contraseña">
      </div>
    </form>
  </div>

<script>
document.getElementById("form-cambiar-password").addEventListener("submit", function (e) {
  e.preventDefault();
  const data = new FormData(this);
  const nueva = data.get("nueva_contrasena");
  const confirmar = data.get("confirmar_contrasena");

  if (nueva !== confirmar) {
    return Swal.fire("❌ Error", "La nueva contraseña y su confirmación no coinciden", "error");
  }
  if (nueva.length < 6) {
    return Swal.fire("❌ Error", "La nueva contraseña debe tener al menos 6 caracteres", "error");
  }

  fetch("../code_back/back_cambiar_contrasena.php", {
    method: "POST",
    body: data
  })
  .then(res => res.json())
  .then(d => {
    if (d.success) {
      Swal.fire("✅ Éxito", d.message, "success");
      this.reset();
    } else {
      Swal.fire("❌ Error", d.message, "error");
    }
  })
  .catch(() => {
    Swal.fire("❌ Error inesperado", "Ocurrió un error en el servidor", "error");
  });
});
</script>
</body>
</html>
