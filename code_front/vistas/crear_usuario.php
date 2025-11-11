<?php
// usuario_crear.php
if (session_status() === PHP_SESSION_NONE) session_start();
include("../code_back/conexion.php");

// Solo administrador (rol = 1)
if (!isset($_SESSION['rol']) || $_SESSION['rol'] != 1) {
    // MODIFICACION: Sustituimos la redirección por un mensaje simple para entornos donde no se puede verificar la ruta (como Canvas).
    // En tu servidor de producción, estas redirecciones son correctas, pero aquí causan 404.
    if (!headers_sent()) {
        // En un entorno de producción, esto redirige: header("Location: ../code_front/menu_admin.php");
        echo "<h2 style='color: red; text-align: center; margin-top: 50px;'>ACCESO DENEGADO (Rol no asignado o inválido)</h2>";
        exit;
    } else {
        echo "<script>alert('Acceso denegado. Solo el administrador puede acceder a esta vista.');</script>";
        exit;
    }
}

// Obtener roles
$sql_rol = "SELECT id_rol, nombre_rol FROM rol ORDER BY nombre_rol";
$arreglo_rol = mysqli_query($cn, $sql_rol);

// Obtener juzgados
$sql_juzgado = "SELECT id_juzgado, nombre_juzgado, tipo_juzgado FROM juzgado ORDER BY nombre_juzgado";
$arreglo_juzgado = mysqli_query($cn, $sql_juzgado);

// --- 1. CLASIFICACIÓN DE JUZGADOS PARA LOS DROPDOWNS ---
$juzgados_clasificados = [
    'especializado' => [],
    'pazLetrado' => [],
];

if ($arreglo_juzgado) {
    // Clasificamos directamente usando el valor de 'tipo_juzgado' de la BD.
    while ($j = mysqli_fetch_assoc($arreglo_juzgado)) {
        $tipo_db = strtolower(trim($j['tipo_juzgado']));

        // Mapeo simple de tipos de BD a las categorías de la interfaz
        if (strpos($tipo_db, 'especializado') !== false) {
            $tipo_clasificado = 'especializado';
        } elseif (strpos($tipo_db, 'paz letrado') !== false) {
            $tipo_clasificado = 'pazLetrado';
        }
        if (isset($juzgados_clasificados[$tipo_clasificado])) {
            $juzgados_clasificados[$tipo_clasificado][] = $j;
        } else {
            $juzgados_clasificados['otros'][] = $j;
        }
    }
    // Reiniciar el puntero del resultado de la consulta si se necesita más tarde
    mysqli_data_seek($arreglo_juzgado, 0); 
}
// -----------------------------------------------------------------
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Crear Usuario</title>
  <link rel="stylesheet" href="../css/crear_usuario.css">
  <style>
    /* Estilos del archivo original */
    .form-row { display:flex; gap:8px; align-items:center; margin-bottom:10px; }
    .form-row.column { flex-direction:column; align-items:flex-start; }
    .muted { color:#666; font-size:0.9em; }

    /* --- Estilos para los Dropdowns de Selección Múltiple --- */
    .juzgados-container { 
        border-top: 1px solid #ccc; 
        padding-top: 15px;
        margin-top: 15px;
    }
    .spinner-group {
        display: flex;
        gap: 20px;
        margin-top: 10px;
        flex-wrap: wrap; /* Para responsividad */
        width: 100%;
    }
    .spinner-item {
        flex: 1; /* Distribución equitativa */
        min-width: 250px; /* Ancho mínimo */
        position: relative; /* Para el dropdown */
    }
    .spinner-item label {
        display: block;
        font-weight: bold;
        margin-bottom: 5px;
    }
    
    /* Estilo del botón/input que simula el spinner */
    .dropdown-button {
        width: 100%;
        padding: 8px 10px;
        border: 1px solid #ccc;
        border-radius: 4px;
        background-color: #fff;
        cursor: pointer;
        text-align: left;
        display: flex;
        justify-content: space-between;
        align-items: center;
        transition: border-color 0.2s;
    }
    .dropdown-button:hover {
        border-color: #007bff;
    }
    .dropdown-button span {
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        flex-grow: 1;
        color: #333;
    }
    .dropdown-button::after {
        content: '▼';
        font-size: 0.8em;
        margin-left: 10px;
        color: #666;
    }

    /* Estilo del contenedor del menú desplegable */
    .dropdown-menu {
        position: absolute;
        z-index: 10;
        top: 100%;
        left: 0;
        width: 100%;
        max-height: 200px;
        overflow-y: auto;
        border: 1px solid #aaa;
        border-radius: 4px;
        background-color: #fff;
        box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        display: none; /* Oculto por defecto */
        padding: 5px 0;
    }

    /* Estilo de las opciones con checkbox */
    .dropdown-option {
        padding: 5px 10px;
        cursor: pointer;
        display: flex;
        align-items: center;
    }
    .dropdown-option:hover {
        background-color: #f0f0f0;
    }
    .dropdown-option input[type="checkbox"] {
        margin-right: 8px;
    }
    
    /* Contenedor de chips (para las selecciones) */
    #chipsList {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        margin-top: 15px;
        min-height: 40px;
        border: 1px dashed #ddd;
        padding: 8px;
        border-radius: 4px;
        align-items: center;
        background-color: #f8f8f8;
    }
    .chip {
        display: flex;
        align-items: center;
        background-color: #840000; /* Color principal (azul) */
        color: white;
        padding: 4px 8px;
        border-radius: 12px;
        font-size: 0.85em;
    }
    .chip-remove {
        cursor: pointer;
        margin-left: 5px;
        font-weight: bold;
        color: white;
        background: none;
        border: none;
        padding: 0;
        line-height: 1;
        opacity: 0.8;
        transition: opacity 0.2s;
    }
    .chip-remove:hover {
        opacity: 1;
    }
  </style>
</head>
<body>
<div class="main-container" style="max-width:900px; margin:30px auto;">
  <h1>Crear nuevo usuario</h1>

  <form action="../code_back/back_crear_usuario.php" method="post" autocomplete="off" id="createUserForm">
    <!-- DNI y Nombre -->
    <div class="form-row">
      <input type="text" name="documento" placeholder="DNI" maxlength="12" required style="width:160px;">
      <input type="text" name="txt_nombre" placeholder="Nombres" required style="flex:1;">
    </div>

    <!-- Apellidos -->
    <div class="form-row">
      <input type="text" name="txt_apellidos" placeholder="Apellidos" required style="flex:1;">
    </div>

    <!-- Correo y Teléfono -->
    <div class="form-row">
      <input type="email" name="txt_email" placeholder="Correo electrónico" style="flex:1;">
      <input type="tel" name="txt_telefono" placeholder="Teléfono" style="width:180px;">
    </div>

    <!-- Rol -->
    <div class="form-row">
      <select name="cbo_rol" required style="width:260px;">
        <option value="" disabled selected>Seleccione un rol</option>
        <?php mysqli_data_seek($arreglo_rol, 0); while ($r = mysqli_fetch_assoc($arreglo_rol)) { ?>
          <option value="<?php echo htmlspecialchars($r['id_rol'], ENT_QUOTES); ?>"><?php echo htmlspecialchars($r['nombre_rol'], ENT_QUOTES); ?></option>
        <?php } ?>
      </select>
    </div>

    <!-- Juzgados (DROPDOWNS para multi-asignación) -->
    <div class="form-row column juzgados-container">
      <label><strong>Asignar juzgados (opcional)</strong></label>
      <small class="muted" style="margin-bottom: 10px;">Seleccione uno o varios juzgados por cada tipo.</small>

      <!-- Grupo de Spinners Horizontales -->
      <div class="spinner-group">
        <?php
        $spinner_configs = [
            'especializado' => 'Juzgados Especializados',
            'pazLetrado' => 'Paz Letrado',
        ];

        foreach ($spinner_configs as $tipo => $titulo) {
            echo '<div class="spinner-item" data-dropdown-id="dropdown-' . $tipo . '">';
            echo '<label>' . $titulo . '</label>';
            
            // Botón que actúa como spinner
            echo '<div class="dropdown-button" id="btn-dropdown-' . $tipo . '" tabindex="0">';
            echo '<span>Seleccionar...</span>';
            echo '</div>';

            // Menú desplegable real
            echo '<div class="dropdown-menu" id="dropdown-' . $tipo . '" data-tipo="' . $tipo . '">';
            
            // Llenar el menú con opciones (checkboxes)
            foreach ($juzgados_clasificados[$tipo] as $j) {
                $jid = (int)$j['id_juzgado'];
                $label = htmlspecialchars($j['nombre_juzgado'], ENT_QUOTES);
                echo '<label class="dropdown-option">';
                echo '<input type="checkbox" data-id="' . $jid . '" data-name="' . $label . '" data-tipo="' . $tipo . '">';
                echo '<span>' . $label . '</span>';
                echo '</label>';
            }
            
            echo '</div>';
            echo '</div>';
        }
        ?>
      </div>
      
      <!-- Contenedor para mostrar las selecciones (Chips) -->
      <div style="width: 100%;">
          <label style="margin-top: 15px; display: block;">Juzgados Seleccionados:</label>
          <div id="chipsList">
              <small class="muted" id="noSelectionsMessage">Ningún juzgado seleccionado.</small>
          </div>
      </div>
    </div>

    <!-- Contraseña -->
    <div class="form-row">
      <input type="password" name="txt_password" placeholder="Contraseña (si no se ingresa, será el DNI)" style="flex:1;">
    </div>

    <!-- Campo oculto placeholder (los inputs finales se crean dinámicamente) -->
    <input type="hidden" name="cbo_juzgados_final_placeholder" id="hiddenJuzgadosInput">

    <!-- Botón -->
    <div class="form-row">
      <input type="submit" value="Crear Usuario">
    </div>
  </form>
</div>

<!-- Scripts para la funcionalidad de chips y sincronización -->
<script>
    // --- Lógica JavaScript para la interfaz de Dropdowns y Chips ---
    
    // Almacenamiento central de los IDs seleccionados
    const selectedJuzgadoMap = new Map(); // Mapa: ID -> {name, tipo}
    const chipsList = document.getElementById('chipsList');
    const noSelectionsMessage = document.getElementById('noSelectionsMessage');
    const form = document.getElementById('createUserForm');

    // Mapeo inverso de ID a Nombre de Juzgado para los chips (desde PHP)
    const juzgadoMap = new Map();
    <?php
    mysqli_data_seek($arreglo_juzgado, 0); 
    while ($j = mysqli_fetch_assoc($arreglo_juzgado)) {
        echo "juzgadoMap.set('" . (int)$j['id_juzgado'] . "', {name: '" . htmlspecialchars($j['nombre_juzgado'], ENT_QUOTES) . "', tipo: '" . strtolower(trim($j['tipo_juzgado'])) . "'});\n";
    }
    ?>

    /**
     * Sincroniza el estado global con los chips visibles y los botones de dropdown.
     */
    function render() {
        // 1. Renderizar Chips
        chipsList.innerHTML = '';
        if (selectedJuzgadoMap.size === 0) {
            noSelectionsMessage.style.display = 'block';
        } else {
            noSelectionsMessage.style.display = 'none';

            selectedJuzgadoMap.forEach((data, id) => {
                const chip = document.createElement('div');
                chip.className = 'chip';
                chip.setAttribute('data-id', id);
                chip.innerHTML = `
                    <span>${data.name}</span>
                    <button type="button" class="chip-remove" data-id="${id}">&times;</button>
                `;
                chipsList.appendChild(chip);
            });
            
            // Asignar manejadores a los botones de remover
            chipsList.querySelectorAll('.chip-remove').forEach(button => {
                button.addEventListener('click', function() {
                    handleRemoveChip(this.getAttribute('data-id'));
                });
            });
        }
        
        // 2. Sincronizar el texto de los botones de Dropdown
        document.querySelectorAll('.spinner-item').forEach(item => {
            const dropdownId = item.getAttribute('data-dropdown-id');
            const button = document.getElementById('btn-' + dropdownId);
            const checkboxes = item.querySelectorAll('.dropdown-menu input[type="checkbox"]');
            
            const selectedCount = Array.from(checkboxes).filter(cb => cb.checked).length;
            
            let buttonText = 'Seleccionar...';
            if (selectedCount > 0) {
                // Opción 1: Mostrar el recuento
                buttonText = selectedCount === 1 ? '1 seleccionado' : `${selectedCount} seleccionados`;
                
                // Opción 2 (Más útil): Mostrar el primer ítem si solo hay uno
                if (selectedCount === 1) {
                    const firstSelected = Array.from(checkboxes).find(cb => cb.checked);
                    buttonText = firstSelected ? firstSelected.getAttribute('data-name') : '1 seleccionado';
                }
            }
            
            if (button) {
                button.querySelector('span').textContent = buttonText;
            }
        });

        // 3. Actualizar el campo oculto para el envío al backend
        updateHiddenInput();
    }

    /**
     * Alterna la visibilidad del menú de selección.
     */
    function toggleDropdown(button, menu) {
        // Cerrar todos los demás menús abiertos
        document.querySelectorAll('.dropdown-menu').forEach(m => {
            if (m !== menu) {
                m.style.display = 'none';
            }
        });
        
        // Alternar el estado del menú actual
        const isVisible = menu.style.display === 'block';
        menu.style.display = isVisible ? 'none' : 'block';
    }

    /**
     * Captura el cambio en un checkbox.
     */
    function handleCheckboxChange(event) {
        const checkbox = event.target;
        const id = checkbox.getAttribute('data-id');
        const name = checkbox.getAttribute('data-name');
        const tipo = checkbox.getAttribute('data-tipo');

        if (checkbox.checked) {
            // Añadir al mapa
            selectedJuzgadoMap.set(id, { name, tipo });
        } else {
            // Eliminar del mapa
            selectedJuzgadoMap.delete(id);
        }
        render();
    }
    
    /**
     * Elimina un juzgado seleccionado desde un chip y sincroniza el checkbox.
     */
    function handleRemoveChip(id) {
        selectedJuzgadoMap.delete(id);
        
        // Sincronizar el checkbox
        document.querySelector(`.dropdown-menu input[data-id="${id}"]`).checked = false;

        render();
    }
    
    /**
     * Actualiza el campo oculto antes de enviar el formulario creando inputs dinámicos.
     */
    function updateHiddenInput() {
        // Eliminar todos los inputs ocultos antiguos
        document.querySelectorAll('input[name="cbo_juzgados_final[]"]').forEach(el => el.remove());
        
        const formElement = document.getElementById('createUserForm');

        // Crear nuevos inputs ocultos por cada ID seleccionado
        selectedJuzgadoMap.forEach((data, id) => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'cbo_juzgados_final[]'; // Nombre esperado por el backend de PHP como array
            input.value = id;
            formElement.appendChild(input);
        });
    }

    // Inicialización: Añadir listeners
    document.addEventListener('DOMContentLoaded', function() {
        // 1. Adjuntar listener a los botones de dropdown
        document.querySelectorAll('.dropdown-button').forEach(button => {
            const menuId = button.id.replace('btn-', '');
            const menu = document.getElementById(menuId);
            
            // El clic es lo único que abre/cierra, no lo abras al recibir foco (tabindex)
            button.addEventListener('click', (e) => {
                e.stopPropagation(); // Evita que el clic en el botón cierre el menú inmediatamente
                toggleDropdown(button, menu);
            });
            // Mantener el evento focus/blur para accesibilidad, pero no para abrir
            // button.addEventListener('focus', () => toggleDropdown(button, menu));
            
            // 2. Adjuntar listener a todos los checkboxes
            if (menu) {
                menu.querySelectorAll('input[type="checkbox"]').forEach(checkbox => {
                    checkbox.addEventListener('change', handleCheckboxChange);
                });
            }
        });

        // 3. Cerrar el menú si se hace clic fuera
        // CORRECCIÓN: Usamos un timeout corto para asegurar que el evento click del botón haya finalizado.
        document.addEventListener('click', function(e) {
            // Si el clic no fue dentro de un elemento spinner-item
            if (!e.target.closest('.spinner-item')) {
                document.querySelectorAll('.dropdown-menu').forEach(menu => {
                    // Solo si está visible, lo cerramos
                    if (menu.style.display === 'block') {
                        menu.style.display = 'none';
                    }
                });
            }
        });

        // 4. Manejar el envío del formulario
        form.addEventListener('submit', function(e) {
            updateHiddenInput(); // Final update
        });

        render(); // Renderizar al cargar la página (estará vacío)
    });
</script>
</body>
</html>