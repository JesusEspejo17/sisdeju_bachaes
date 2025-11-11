 <?php
// usuario_crear.php
if (session_status() === PHP_SESSION_NONE) session_start();
include("../code_back/conexion.php");

// Solo administrador (rol = 1)
if (!isset($_SESSION['rol']) || $_SESSION['rol'] != 1) {
    if (!headers_sent()) {
        header("Location: ../code_front/menu_admin.php");
        exit;
    } else {
        echo "<script>alert('Acceso denegado. Solo el administrador puede acceder a esta vista.'); window.location='../menu_admin.php';</script>";
        exit;
    }
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Crear Nuevo Juzgado</title>
  <link rel="stylesheet" href="../css/crear_usuario.css">
  <style>
    .form-row { display:flex; gap:8px; align-items:center; margin-bottom:10px; }
    .form-row.column { flex-direction:column; align-items:flex-start; }
    .juzgados-grid { display:grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap:8px; width:100%; margin-top:8px; }
    .juzgado-item { display:flex; align-items:center; gap:8px; border:1px solid #eee; padding:6px 8px; border-radius:6px; background:#fff; }
    .muted { color:#666; font-size:0.9em; }
  </style>
</head>
<body>
<div class="main-container" style="max-width:900px; margin:30px auto;">
  <h1>Crear nuevo juzgado</h1>

  <form action="../code_back/back_crear_juzgado.php" method="post" autocomplete="off">
    <!-- Nombre -->
    <div class="form-row">
      <input type="text" name="txt_nombre" placeholder="Nombre" required style="flex:1;">
    </div>

    <!-- Tipo -->
    <div class="form-row">
      <select name="cbo_tipo" required style="width:260px;">
        <option value="" disabled selected>Seleccione un tipo de juzgado</option>
          <option value="ESPECIALIZADO">ESPECIALIZADO</option>
          <option value="PAZ LETRADO">PAZ LETRADO</option>
      </select>
    </div>

    <!-- Botón -->
    <div class="form-row">
      <input type="submit" value="Crear Juzgado">
    </div>
  </form>
</div>
</body>
</html>
