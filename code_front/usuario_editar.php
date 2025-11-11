<?php
// usuario_editar.php (DROPDOWNS para juzgados) - CORREGIDO COMPLETAMENTE
// Requiere: ../code_back/conexion.php

if (session_status() === PHP_SESSION_NONE) session_start();
include("../code_back/conexion.php");

if (!isset($_GET["documento"])) {
    echo "DNI no proporcionado.";
    exit;
}

$dni = $_GET["documento"];

function table_has_column($cn, $table, $column) {
    $table_esc = mysqli_real_escape_string($cn, $table);
    $col_esc = mysqli_real_escape_string($cn, $column);
    $q = "SHOW COLUMNS FROM `{$table_esc}` LIKE '{$col_esc}'";
    $res = mysqli_query($cn, $q);
    if (!$res) return false;
    $has = mysqli_num_rows($res) > 0;
    mysqli_free_result($res);
    return $has;
}

$has_id_juzgado = table_has_column($cn, 'usuario', 'id_juzgado');

$select_cols = "p.documento, p.nombre_persona, p.apellido_persona, p.correo_persona, p.telefono_persona, u.id_rol";
if ($has_id_juzgado) $select_cols .= ", u.id_juzgado";

$sql = "SELECT {$select_cols} 
        FROM persona p
        JOIN usuario u ON p.documento = u.codigo_usu
        WHERE p.documento = ?";
$stmt = mysqli_prepare($cn, $sql);
if (!$stmt) {
    echo "Error en la consulta (prepare): " . htmlspecialchars(mysqli_error($cn));
    exit;
}
mysqli_stmt_bind_param($stmt, "s", $dni);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);
$usuario = mysqli_fetch_assoc($res);
mysqli_stmt_close($stmt);

if (!$usuario) {
    echo "Usuario no encontrado.";
    exit;
}

// roles
$sql_roles = "SELECT id_rol, nombre_rol FROM rol ORDER BY nombre_rol";
$r_roles = mysqli_query($cn, $sql_roles);

// juzgados completos (INCLUIMOS tipo_juzgado)
$sql_juzgados = "SELECT id_juzgado, nombre_juzgado, tipo_juzgado FROM juzgado ORDER BY nombre_juzgado";
$r_juzgados = mysqli_query($cn, $sql_juzgados);

// asociaciones actuales
$assoc = [];
if ($stmt2 = mysqli_prepare($cn, "SELECT id_juzgado FROM usuario_juzgado WHERE codigo_usu = ?")) {
    mysqli_stmt_bind_param($stmt2, "s", $dni);
    mysqli_stmt_execute($stmt2);
    $res2 = mysqli_stmt_get_result($stmt2);
    while ($r = mysqli_fetch_assoc($res2)) {
        $assoc[] = (int)$r['id_juzgado'];
    }
    mysqli_stmt_close($stmt2);
}

// --- CLASIFICACIÓN DE JUZGADOS PARA LOS DROPDOWNS ---
$juzgados_clasificados = [
    'pazLetrado' => [],
    'especializado' => [],
];

if ($r_juzgados) {
    mysqli_data_seek($r_juzgados, 0); 
    while ($j = mysqli_fetch_assoc($r_juzgados)) {
        $tipo_db = strtolower(trim($j['tipo_juzgado']));
        $tipo_clasificado = 'otros';

        if (strpos($tipo_db, 'especializado') !== false) {
            $tipo_clasificado = 'especializado';
        } elseif (strpos($tipo_db, 'paz letrado') !== false) {
            $tipo_clasificado = 'pazLetrado';
        }
        
        $juzgados_clasificados[$tipo_clasificado][] = $j;
    }
    mysqli_data_seek($r_juzgados, 0); 
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Editar Usuario</title>
  <link rel="stylesheet" href="../css/crear_usuario.css">
  <style>
    .form-row { display:flex; gap:8px; align-items:center; margin-bottom:10px; }
    .form-row.column { flex-direction:column; align-items:flex-start; }
    label.small { font-size:0.9em; color:#666; margin-bottom:6px; display:block; }
    .muted { color:#666; font-size:0.9em; }
    .juzgados-grid { display:grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap:8px; width:100%; }
    .juzgado-item { display:flex; align-items:center; gap:8px; border:1px solid #eee; padding:6px 8px; border-radius:6px; background:#fff; }
    .juzgado-item input[type="checkbox"] { transform:scale(1.05); }

    .juzgados-container { 
        border-top: 1px solid #ccc; 
        padding-top: 15px;
        margin-top: 15px;
    }
    .spinner-group {
        display: flex;
        gap: 20px;
        margin-top: 10px;
        flex-wrap: wrap; 
        width: 100%;
    }
    .spinner-item {
        flex: 1; 
        min-width: 250px; 
        position: relative; 
    }
    .spinner-item label {
        display: block;
        font-weight: bold;
        margin-bottom: 5px;
    }
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
        display: none; 
        padding: 5px 0;
    }
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
        background-color: #840000; 
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
  <h1>Editar usuario</h1>

  <form action="../code_back/back_editar_usuario.php" method="post" id="editUserForm">
    <input type="hidden" name="documento" value="<?php echo htmlspecialchars($usuario['documento'], ENT_QUOTES); ?>">

    <div class="form-row">
      <input type="text" value="<?php echo htmlspecialchars($usuario['documento'], ENT_QUOTES); ?>" disabled style="width:180px;">
      <input type="text" name="txt_nombre" value="<?php echo htmlspecialchars($usuario['nombre_persona'], ENT_QUOTES); ?>" placeholder="Nombres" required style="flex:1;">
    </div>

    <div class="form-row">
      <input type="text" name="txt_apellidos" value="<?php echo htmlspecialchars($usuario['apellido_persona'], ENT_QUOTES); ?>" placeholder="Apellidos" required style="flex:1;">
    </div>

    <div class="form-row">
      <input type="email" name="txt_email" value="<?php echo htmlspecialchars($usuario['correo_persona'] ?? '', ENT_QUOTES); ?>" placeholder="Correo" style="flex:1;">
      <input type="tel" name="txt_telefono" value="<?php echo htmlspecialchars($usuario['telefono_persona'] ?? '', ENT_QUOTES); ?>" placeholder="Teléfono" style="width:180px;">
    </div>

    <div class="form-row">
      <select name="cbo_rol" required style="width:260px;">
        <option value="" disabled>Seleccione un rol</option>
        <?php
        if ($r_roles) {
            mysqli_data_seek($r_roles, 0);
            while ($rol = mysqli_fetch_assoc($r_roles)) {
                $sel = ((int)$rol["id_rol"] === (int)$usuario["id_rol"]) ? 'selected' : '';
                echo '<option value="'.htmlspecialchars($rol["id_rol"], ENT_QUOTES).'" '.$sel.'>'.htmlspecialchars($rol["nombre_rol"], ENT_QUOTES).'</option>';
            }
        }
        ?>
      </select>

      <?php if ($has_id_juzgado): ?>
        <div style="margin-left:12px;">
          <small class="muted">id_juzgado (columna usuario): <strong><?php echo isset($usuario['id_juzgado']) ? intval($usuario['id_juzgado']) : 'NULL'; ?></strong></small>
        </div>
      <?php endif; ?>
    </div>

    <!-- SECCIÓN DE DROPDOWNS DE JUZGADOS -->
    <div class="form-row column juzgados-container">
      <label><strong>Asignar juzgados (opcional)</strong></label>
      <small class="muted" style="margin-bottom: 10px;">Seleccione uno o varios juzgados por cada tipo.</small>

      <div class="spinner-group">
        <?php
        $spinner_configs = [
            'especializado' => 'Juzgados Especializados',
            'pazLetrado' => 'Paz Letrado',
        ];

        foreach ($spinner_configs as $tipo_clasificado => $titulo) {
            echo '<div class="spinner-item" data-dropdown-id="dropdown-' . $tipo_clasificado . '">';
            echo '<label>' . $titulo . '</label>';
            
            echo '<div class="dropdown-button" id="btn-dropdown-' . $tipo_clasificado . '" tabindex="0">';
            echo '<span>Seleccionar...</span>';
            echo '</div>';

            echo '<div class="dropdown-menu" id="dropdown-' . $tipo_clasificado . '" data-tipo="' . $tipo_clasificado . '">';
            
            foreach ($juzgados_clasificados[$tipo_clasificado] as $j) {
                $jid = (int)$j['id_juzgado'];
                $label = htmlspecialchars($j['nombre_juzgado'], ENT_QUOTES);
                $checked = in_array($jid, $assoc) ? 'checked' : ''; 
                echo '<label class="dropdown-option">';
                echo '<input type="checkbox" data-id="' . $jid . '" data-name="' . $label . '" data-tipo="' . $tipo_clasificado . '" ' . $checked . '>';
                echo '<span>' . $label . '</span>';
                echo '</label>';
            }
            
            echo '</div>';
            echo '</div>';
        }
        ?>
      </div>
      
      <div style="width: 100%;">
          <label style="margin-top: 15px; display: block;">Juzgados Seleccionados:</label>
          <div id="chipsList">
              <small class="muted" id="noSelectionsMessage">Ningún juzgado seleccionado.</small>
          </div>
      </div>
    </div>

    <div class="form-row">
      <input type="password" name="txt_password" placeholder="Nueva contraseña (dejar en blanco si no se desea cambiar)" style="flex:1;">
    </div>

    <div class="form-row" style="gap: 12px;">
      <input type="submit" value="Guardar cambios" style="flex: 1;">
      <button type="button" class="btn-cancelar" onclick="window.location.href='menu_admin.php?vista=listado_usuarios'" style="flex: 1">Cancelar</button>
    </div>
  </form>
</div>

<script>
    // Variables globales del script
    const selectedJuzgadoMap = new Map();
    const juzgadoMap = new Map();
    const initialAssocIds = [<?php echo implode(', ', $assoc); ?>];
    
    console.log('✅ Script de edición cargado');
    console.log('📋 Juzgados iniciales:', initialAssocIds);
    
    <?php
    mysqli_data_seek($r_juzgados, 0); 
    while ($j = mysqli_fetch_assoc($r_juzgados)) {
        $tipo_db = strtolower(trim($j['tipo_juzgado']));
        $tipo_js = 'otros';
        if (strpos($tipo_db, 'especializado') !== false) {
            $tipo_js = 'especializado';
        } elseif (strpos($tipo_db, 'paz letrado') !== false) {
            $tipo_js = 'pazLetrado';
        }
        echo "juzgadoMap.set('" . (int)$j['id_juzgado'] . "', {name: '" . addslashes(htmlspecialchars($j['nombre_juzgado'], ENT_QUOTES)) . "', tipo: '" . $tipo_js . "'});\n";
    }
    ?>
    
    console.log('� Juzgados cargados:', juzgadoMap.size);

    function render() {
        console.log('🎨 Renderizando... Seleccionados:', selectedJuzgadoMap.size);
        
        const chipsListElement = document.getElementById('chipsList');
        const noSelectionsMessageElement = document.getElementById('noSelectionsMessage');
        
        if (!chipsListElement) {
            console.error('❌ No se encontró chipsList');
            return;
        }
        
        // Limpiar el contenedor de chips
        chipsListElement.innerHTML = '';
        
        if (selectedJuzgadoMap.size === 0) {
            // No hay selecciones, mostrar mensaje
            const message = document.createElement('small');
            message.className = 'muted';
            message.id = 'noSelectionsMessage';
            message.textContent = 'Ningún juzgado seleccionado.';
            chipsListElement.appendChild(message);
            console.log('   Sin selecciones');
        } else {
            // Hay selecciones, mostrar chips
            console.log('   Creando', selectedJuzgadoMap.size, 'chips');
            
            selectedJuzgadoMap.forEach((data, id) => {
                const chip = document.createElement('div');
                chip.className = 'chip';
                chip.setAttribute('data-id', id);
                chip.innerHTML = `
                    <span>${data.name}</span>
                    <button type="button" class="chip-remove" data-id="${id}">&times;</button>
                `;
                chipsListElement.appendChild(chip);
                console.log('   ✅ Chip creado:', data.name);
            });
            
            // Agregar event listeners a los botones de remover
            chipsListElement.querySelectorAll('.chip-remove').forEach(button => {
                button.addEventListener('click', function() {
                    handleRemoveChip(this.getAttribute('data-id'));
                });
            });
        }
        
        document.querySelectorAll('.spinner-item').forEach(item => {
            const dropdownId = item.getAttribute('data-dropdown-id');
            const button = document.getElementById('btn-' + dropdownId);
            const checkboxes = item.querySelectorAll('.dropdown-menu input[type="checkbox"]');
            
            const selectedCount = Array.from(checkboxes).filter(cb => cb.checked).length;
            
            let buttonText = 'Seleccionar...';
            if (selectedCount > 0) {
                buttonText = selectedCount === 1 ? '1 seleccionado' : `${selectedCount} seleccionados`;
                
                if (selectedCount === 1) {
                    const firstSelected = Array.from(checkboxes).find(cb => cb.checked);
                    buttonText = firstSelected ? firstSelected.getAttribute('data-name') : '1 seleccionado';
                }
            }
            
            if (button) {
                button.querySelector('span').textContent = buttonText;
            }
        });

        updateHiddenInput();
    }

    function toggleDropdown(button, menu) {
        document.querySelectorAll('.dropdown-menu').forEach(m => {
            if (m !== menu) {
                m.style.display = 'none';
            }
        });
        
        const isVisible = menu.style.display === 'block';
        menu.style.display = isVisible ? 'none' : 'block';
    }

    function handleCheckboxChange(event) {
        const checkbox = event.target;
        const id = checkbox.getAttribute('data-id');
        const name = checkbox.getAttribute('data-name');
        const tipo = checkbox.getAttribute('data-tipo');

        console.log('📝 Checkbox cambió:', name, checkbox.checked ? 'SELECCIONADO' : 'DESELECCIONADO');

        if (checkbox.checked) {
            selectedJuzgadoMap.set(id, { name, tipo });
        } else {
            selectedJuzgadoMap.delete(id);
        }
        
        console.log('   Total seleccionados:', selectedJuzgadoMap.size);
        render();
    }
    
    function handleRemoveChip(id) {
        console.log('🗑️ Removiendo chip:', id);
        
        selectedJuzgadoMap.delete(id);
        
        const checkbox = document.querySelector(`.dropdown-menu input[data-id="${id}"]`);
        if (checkbox) {
            checkbox.checked = false;
            console.log('   ✅ Checkbox desmarcado');
        } else {
            console.log('   ⚠️ Checkbox no encontrado');
        }

        render();
    }
    
    function updateHiddenInput() {
        document.querySelectorAll('input[name="cbo_juzgados[]"]').forEach(el => el.remove());
        
        const formElement = document.getElementById('editUserForm');
        if (!formElement) return;

        selectedJuzgadoMap.forEach((data, id) => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'cbo_juzgados[]';
            input.value = id;
            formElement.appendChild(input);
        });
    }
    
    function initializeSelections() {
        console.log('🔄 Cargando juzgados asociados...');
        
        // CAMBIO CRÍTICO: Leer TODOS los checkboxes que ya están marcados en el HTML
        document.querySelectorAll('.dropdown-menu input[type="checkbox"]:checked').forEach(checkbox => {
            const id = checkbox.getAttribute('data-id');
            const name = checkbox.getAttribute('data-name');
            const tipo = checkbox.getAttribute('data-tipo');
            
            if (id && name && tipo) {
                selectedJuzgadoMap.set(id, { name, tipo });
                console.log('  ✓ Cargado:', name);
            }
        });
        
        console.log('✅ Total cargados:', selectedJuzgadoMap.size, 'juzgados');
    }

    // Inicializar el formulario
    function inicializarFormulario() {
        console.log('🚀 Inicializando formulario...');
        
        // PASO 1: Cargar los juzgados que ya están seleccionados
        console.log('📍 PASO 1: Cargando selecciones iniciales');
        initializeSelections();
        
        // PASO 2: Configurar event listeners
        console.log('📍 PASO 2: Configurando event listeners');
        let totalCheckboxes = 0;
        document.querySelectorAll('.dropdown-button').forEach(button => {
            const menuId = button.id.replace('btn-', '');
            const menu = document.getElementById(menuId);
            
            button.addEventListener('click', (e) => {
                e.stopPropagation();
                toggleDropdown(button, menu);
            });
            
            if (menu) {
                const checkboxes = menu.querySelectorAll('input[type="checkbox"]');
                totalCheckboxes += checkboxes.length;
                
                checkboxes.forEach((checkbox, index) => {
                    // Agregar listener para el evento 'change'
                    checkbox.addEventListener('change', handleCheckboxChange);
                });
                
                menu.addEventListener('click', (e) => {
                    e.stopPropagation();
                });
            }
        });
        
        console.log('   ✅ Listeners agregados a', totalCheckboxes, 'checkboxes');

        // PASO 3: Configurar cierre automático de dropdowns
        document.addEventListener('click', function(e) {
            if (!e.target.closest('.spinner-item')) {
                document.querySelectorAll('.dropdown-menu').forEach(menu => {
                    if (menu.style.display === 'block') {
                        menu.style.display = 'none';
                    }
                });
            }
        });

        // PASO 4: Configurar submit del formulario
        const formElement = document.getElementById('editUserForm');
        if (formElement) {
            formElement.addEventListener('submit', function(e) {
                updateHiddenInput();
            });
        }

        // PASO 5: Renderizar la interfaz
        console.log('📍 PASO 5: Renderizando interfaz inicial');
        render(); 
        
        console.log('✅ ✅ ✅ Inicialización completa ✅ ✅ ✅');
    }

    // Ejecutar cuando el DOM esté listo
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', inicializarFormulario);
    } else {
        inicializarFormulario();
    }
</script>
</body>
</html>