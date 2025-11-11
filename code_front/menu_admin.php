<?php 
include("../code_back/conexion.php");
include("../code_back/auth.php");

$documento = $_SESSION['documento'] ?? null;
$idRol = $_SESSION['rol'] ?? 0;

if ($documento && empty($_SESSION['nombre_usuario'])) {
    $sql = "SELECT nombre_persona, apellido_persona FROM persona WHERE documento = '$documento' LIMIT 1";
    $res = mysqli_query($cn, $sql);
    if ($row = mysqli_fetch_assoc($res)) {
        $_SESSION['nombre_usuario'] = $row['nombre_persona'] . ' ' . $row['apellido_persona'];
    } else {
        $_SESSION['nombre_usuario'] = 'Usuario';
    }
}

/**
 * ---------------------------
 * CONFIG: permisos por vista
 * ---------------------------
 * Aquí defines qué roles pueden ver cada 'vista'.
 * Usa arrays con números de rol, por ejemplo:
 *   1 = admin, 2 = operador, 3 = asistente (según tu app)
 *
 * Ejemplos:
 *   'listado_depositos' => [1,2,3]  // accesible por roles 1,2,3
 *   'agregar_deposito'  => [1,2]    // solo roles 1 y 2
 *
 * Si una vista NO aparece en este array, por defecto será accesible
 * para TODOS (comportamiento por compatibilidad). Si preferís negar
 * por defecto, cambia la función canView abajo.
 */
$viewRoles = [
  'listado_usuarios'      => [1,6],
  'listado_depositos'     => [1,2,3,4,5,6],
  'agregar_deposito'      => [1,2],
  'listado_beneficiarios' => [1,2,6],
  'agregar_beneficiario'  => [1,2],
  'crear_usuario'         => [1],
  'crear_juzgado'         => [1],
  'editar_usuario'        => [1,6],
  'reporte_deposito'      => [1,2,3,4,5,6],
  'cambiar_clave'         => [1,2,3,4,5,6]
];

// Rutas de vistas (archivo físico)
$rutas = [
  'listado_usuarios' => 'vistas/listado_usuario.php',
  'listado_depositos' => 'vistas/listado_depositos.php',
  'agregar_deposito' => 'vistas/agregar_deposito.php',
  'listado_beneficiarios' => 'vistas/listado_beneficiarios.php',
  'agregar_beneficiario' => 'vistas/agregar_beneficiario.php',
  'crear_usuario' => 'vistas/crear_usuario.php',
  'crear_juzgado' => 'vistas/crear_juzgado.php',
  'editar_usuario' => 'usuario_editar.php',
  'reporte_deposito' => 'vistas/reporte_deposito.php',
  'cambiar_clave' => 'vistas/cambiar_clave.php'
];

// Helper: verifica si el rol actual puede ver la vista
function canView(string $vista, int $idRol, array $viewRoles): bool {
    // Si no definiste la vista en $viewRoles -> permitimos (para compatibilidad)
    if (!isset($viewRoles[$vista])) return true;
    return in_array($idRol, $viewRoles[$vista], true);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <script>
  const dniUsuarioActual  = "<?= $_SESSION['documento'] ?? '' ?>";
  const rolUsuarioActual  = <?= (int)$idRol ?>;
</script>

  <meta charset="UTF-8">
  <title>Menú Admin</title>
  <link rel="stylesheet" href="../css/css_admin/all.min.css">
  <link rel="stylesheet" href="../css/menu_admin.css">

  <link rel="manifest" href="../manifest.json">
  <meta name="theme-color" content="#0066cc">
</head>

<body>
<button id="btnInstall" style="
     display: none;
     position: fixed;
     bottom: 1rem;
     right: 2rem;
     padding: .5rem 1rem;
     background: #0066cc;
     color: #fff;
     border: none;
     border-radius: .25rem;
     cursor: pointer;
     box-shadow: 0 2px 6px rgba(0,0,0,.2);
     z-index: 1000;
">
  📥 Instalar SISDEJU
</button>

<ul class="accordion-menu">
  <div class="menu-header">
    <img src="../img/pj2.png"/>
    <span><center>Sistema de Entrega de Órdenes de Pago</center></span>
  </div>

  <!-- Depósitos judiciales (grupo) -->
  <li id="menu-depositos-judiciales" class="active">
    <div class="dropdownlink">
      <i class="fa fa-paper-plane" aria-hidden="true"></i> Depósitos judiciales
    </div>
    <ul class="submenuItems">
      <?php if (canView('listado_depositos', $idRol, $viewRoles)): ?>
        <li>
          <a href="?vista=listado_depositos">
            <i class="fa fa-list" aria-hidden="true"></i> Ver todos
          </a>
        </li>
      <?php endif; ?>

      <?php if (canView('agregar_deposito', $idRol, $viewRoles)): ?>
        <li>
          <a href="?vista=agregar_deposito">
            <i class="fa fa-plus" aria-hidden="true"></i> Agregar depósito
          </a>
        </li>
      <?php endif; ?>
    </ul>
  </li>

  <!-- Reportes y listados -->
  <li id="menu-depositos-judiciales-1">
    <div  class="dropdownlink">
      <i class="fa fa-quote-left" aria-hidden="true"></i> Reportes
      <i class="fa fa-chevron-down" aria-hidden="true"></i>
    </div>
    <ul class="submenuItems">
      <?php if (canView('reporte_deposito', $idRol, $viewRoles)): ?>
        <li><a href="?vista=reporte_deposito">Reporte de depósitos</a></li>
      <?php endif; ?>
    </ul>
  </li>

  <!-- Opciones generales -->
  <?php if (in_array($idRol, [1,2,3,4,5,6], true)): ?>
  <li>
    <div class="dropdownlink">
      <i class="fa fa-cog" aria-hidden="true"></i> Opciones
      <i class="fa fa-chevron-down" aria-hidden="true"></i>
    </div>
    <ul class="submenuItems">
      <?php if (canView('crear_usuario', $idRol, $viewRoles)): ?>
        <li><a href="?vista=crear_usuario">Crear nuevo usuario</a></li>
      <?php endif; ?>

      <?php if (canView('crear_juzgado', $idRol, $viewRoles)): ?>
        <li><a href="?vista=crear_juzgado">Crear nuevo juzgado</a></li>
      <?php endif; ?>

      <?php if (canView('agregar_beneficiario', $idRol, $viewRoles)): ?>
        <li><a href="?vista=agregar_beneficiario">Agregar a un beneficiario</a></li>
      <?php endif; ?>

      <?php if (canView('listado_usuarios', $idRol, $viewRoles)): ?>
        <li><a href="?vista=listado_usuarios">Lista de usuarios</a></li>
      <?php endif; ?>

      <?php if (canView('listado_beneficiarios', $idRol, $viewRoles)): ?>
        <li><a href="?vista=listado_beneficiarios">Lista de beneficiarios</a></li>
      <?php endif; ?>

      <?php if (canView('cambiar_clave', $idRol, $viewRoles)): ?>
        <li><a href="?vista=cambiar_clave">Cambiar Contraseña</a></li>
      <?php endif; ?>
    </ul>
  </li>
  <?php endif; ?>

  <li class="logout-item">
    <div class="dropdownlink" onclick="location.href='../code_back/back_logout.php'">
      <i class="fa fa-sign-out-alt" aria-hidden="true"></i> Cerrar sesión
    </div>
  </li>
</ul>

<div class="main-content">
  <header class="top-header">
	<div style="color: white;">Presione para cargar las nuevas funciones "CTRL+SHIFT+R" caso no carguen</div>
    <div class="user-info">
      <img src="../img/profile3.png" alt="Foto de perfil" class="profile-icon">
      <span class="username"><?= htmlspecialchars($_SESSION['nombre_usuario'] ?? 'Usuario') ?></span>
      <div class="user-dropdown">
        <?php if (canView('cambiar_clave', $idRol, $viewRoles)): ?>
          <a href="?vista=cambiar_clave">Cambiar contraseña</a>
        <?php endif; ?>
        <a href="../code_back/back_logout.php">Cerrar sesión</a>
      </div>
    </div>
  </header>

  <?php
    $vista = $_GET['vista'] ?? 'listado_depositos';

    // Si la vista existe en $rutas, verificar permiso; si no existe, mostrar error
    if (isset($rutas[$vista])) {
      if (!canView($vista, $idRol, $viewRoles)) {
        // Opcional: puedes redirigir a una página "Sin permisos" o mostrar mensaje
        echo "<h2>Acceso denegado</h2><p>No tienes permisos para ver esta vista.</p>";
      } else {
        include($rutas[$vista]);
      }
    } else {
      echo "<h2>Error</h2><p>La vista solicitada no existe.</p>";
    }
  ?>
</div>

<footer class="footer">
  <p>SISDEJU by vsirk v1.1.5</p>
</footer>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="../js/menu_admin.js"></script>

<?php if (isset($_SESSION["swal"])): ?>
<script src="../js/sweetalert2.all.min.js"></script>
<script>
  document.addEventListener("DOMContentLoaded", () => {
    Swal.fire({
      title: "<?= $_SESSION['swal']['title'] ?>",
      text: "<?= $_SESSION['swal']['text'] ?>",
      icon: "<?= $_SESSION['swal']['icon'] ?>"
    });
  });
</script>
<?php unset($_SESSION["swal"]); ?>
<?php endif; ?>


<!-- SCRIPT: Service Worker -->
<script>
if ('serviceWorker' in navigator) {
  window.addEventListener('load', async () => {
    try {
      const reg = await navigator.serviceWorker.register('../service-worker.js');
      console.log('Service Worker registrado:', reg);
    } catch (e) {
      console.error('Error al registrar SW:', e);
    }
  });
}
</script>

<!-- SCRIPT: Botón instalar PWA -->
<script>
let deferredPrompt;
const installBtn = document.getElementById('btnInstall');

window.addEventListener('beforeinstallprompt', (e) => {
  e.preventDefault();
  deferredPrompt = e;
  installBtn.style.display = 'block';
});

installBtn.addEventListener('click', async () => {
  installBtn.style.display = 'none';
  if (!deferredPrompt) return;
  deferredPrompt.prompt();
  const { outcome } = await deferredPrompt.userChoice;
  console.log('Resultado instalación PWA:', outcome);
  deferredPrompt = null;
});

window.addEventListener('appinstalled', () => {
  console.log('PWA instalada ✅');
});
</script>

</body>
<script src="../js/notification.js"></script>

</html>
