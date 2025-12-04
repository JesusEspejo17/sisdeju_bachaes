<?php
// reporte_deposito.php
// Versión con paginación: carga datos dinámicamente vía AJAX
// Mantiene todas las funcionalidades de filtros y exportación a PDF

if (session_status() === PHP_SESSION_NONE) session_start();

// Validar sesión básica
if (!isset($_SESSION['documento']) || !isset($_SESSION['rol'])) {
    die('Sesión no iniciada.');
}

$idRol = intval($_SESSION['rol']); // asegurar tipo entero

// Obtener datos para el filtro de secretarios
include("../code_back/conexion.php");

// Cargar secretarios (rol 3) que realmente tienen depósitos asignados
$sql_secretarios = "SELECT DISTINCT dj.documento_secretario as documento, 
                           CONCAT(p.nombre_persona, ' ', p.apellido_persona) AS nombre_completo
                    FROM deposito_judicial dj
                    JOIN persona p ON dj.documento_secretario = p.documento 
                    JOIN usuario u ON p.documento = u.codigo_usu 
                    WHERE u.id_rol = 3 AND dj.documento_secretario IS NOT NULL
                    ORDER BY p.nombre_persona, p.apellido_persona";
$result_secretarios = mysqli_query($cn, $sql_secretarios);
$secretarios = [];
while ($row = mysqli_fetch_assoc($result_secretarios)) {
    $secretarios[] = $row;
}

// Cargar usuarios AOP (rol 4) que realmente tienen depósitos entregados
$sql_usuarios_aop = "SELECT DISTINCT hd.documento_usuario as documento, 
                            CONCAT(p.nombre_persona, ' ', p.apellido_persona) AS nombre_completo
                     FROM historial_deposito hd
                     JOIN persona p ON hd.documento_usuario = p.documento 
                     JOIN usuario u ON p.documento = u.codigo_usu 
                     WHERE u.id_rol = 4 AND hd.documento_usuario IS NOT NULL
                       AND hd.tipo_evento = 'CAMBIO_ESTADO' AND hd.estado_nuevo = 1
                     ORDER BY p.nombre_persona, p.apellido_persona";
$result_usuarios_aop = mysqli_query($cn, $sql_usuarios_aop);
$usuarios_aop = [];
while ($row = mysqli_fetch_assoc($result_usuarios_aop)) {
    $usuarios_aop[] = $row;
}

// Obtener datos del usuario actual de la sesión
$usuarioActual = $_SESSION['documento'];
$sql_usuario_actual = "SELECT CONCAT(p.nombre_persona, ' ', p.apellido_persona) AS nombre_completo,
                              p.documento
                       FROM persona p 
                       WHERE p.documento = '" . mysqli_real_escape_string($cn, $usuarioActual) . "'";
$result_usuario_actual = mysqli_query($cn, $sql_usuario_actual);
$datos_usuario_actual = mysqli_fetch_assoc($result_usuario_actual);
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Reporte de entrega de Órdenes de Pago</title>
  <link rel="stylesheet" href="../css/crear_usuario.css">
  <link rel="stylesheet" href="../css/deposito_ventana.css">
  <link rel="stylesheet" href="../css/menu_admin.css">
  <link rel="stylesheet" href="../css/css_admin/all.min.css">
  <script src="../js/sweetalert2.all.min.js"></script>
  <style>
    .filtro-group { margin-bottom: 15px; display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
    .rango-fechas { display: flex; }
    
    /* Clases para mostrar/ocultar elementos sin alterar su layout */
    .oculto { display: none !important; }
    .visible { display: inherit !important; }
    
    /* Loading spinner */
    .loading {
      display: none;
      text-align: center;
      padding: 20px;
      color: #555;
    }
    .loading.show {
      display: block;
    }
    .spinner {
      border: 3px solid #f3f3f3;
      border-top: 3px solid #337ab7;
      border-radius: 50%;
      width: 30px;
      height: 30px;
      animation: spin 1s linear infinite;
      margin: 0 auto 10px;
    }
    @keyframes spin {
      0% { transform: rotate(0deg); }
      100% { transform: rotate(360deg); }
    }
    
    /* Paginación */
    .pagination { 
      display:flex; 
      gap:6px; 
      align-items:center; 
      justify-content:flex-end; 
      margin:10px 0; 
      flex-wrap:wrap; 
    }
    .pagination a, .pagination span { 
      padding:6px 10px; 
      border-radius:6px; 
      text-decoration:none; 
      background:#f2f2f2; 
      color:#333; 
      border:1px solid #e0e0e0; 
    }
    .pagination a:hover { 
      background:#e9e9e9; 
    }
    .pagination .current { 
      background:#840000; 
      color:#fff; 
      border-color:#5a0000; 
    }
    .pagination .disabled { 
      opacity:0.5; 
      pointer-events:none; 
    }
    .page-info { 
      font-size:0.95rem; 
      color:#555; 
      margin-right:auto; 
      align-self:center; 
    }
  </style>

<script src="../js/jspdf/jspdf.umd.min.js"></script>
<script src="../js/jspdf/jspdf.plugin.autotable.min.js"></script>

</head>
<body>
  <div class="main-container">
    <h1>Reporte de entrega de Órdenes de Pago</h1>
    <div class="filtro-group" style="display: none;">
      <label for="filtroEstado"><strong>Estado:</strong></label>
      <select id="filtroEstado">
        <option value="entregados" selected>Entregados</option>
      </select>
    </div>

    <div class="filtro-group">
      <label for="filtroSecretario"><strong>Secretario*:</strong></label>
      <select id="filtroSecretario" required>
        <option value="">Todos los secretarios</option>
        <?php foreach ($secretarios as $secretario): ?>
          <option value="<?= htmlspecialchars($secretario['documento']) ?>"><?= htmlspecialchars($secretario['nombre_completo']) ?></option>
        <?php endforeach; ?>
      </select>
      
      <?php if ($idRol != 4): ?>
      <label for="filtroUsuarioAOP"><strong>Usuario*:</strong></label>
      <select id="filtroUsuarioAOP" required>
        <option value="">Todos los usuarios</option>
        <?php foreach ($usuarios_aop as $usuario_aop): ?>
          <option value="<?= htmlspecialchars($usuario_aop['documento']) ?>"><?= htmlspecialchars($usuario_aop['nombre_completo']) ?></option>
        <?php endforeach; ?>
      </select>
      <?php else: ?>
      <label for="filtroFecha"><strong>Fecha de finalización*:</strong></label>
      <input type="date" id="filtroFecha" placeholder="Seleccione una fecha" required>
      <!-- Usuario AOP (rol 4): filtro automático oculto -->
      <input type="hidden" id="filtroUsuarioAOP" value="<?= htmlspecialchars($_SESSION['documento']) ?>">
      <?php endif; ?>
      
      <input type="button" value="Exportar PDF" onclick="exportarPDF()" style="height: 38px; margin-top: 0; padding: 10px 25px;">
    </div>

    <?php if ($idRol != 4): ?>
    <div class="filtro-group">
      <label for="filtroFecha"><strong>Fecha de finalización*:</strong></label>
      <input type="date" id="filtroFecha" placeholder="Seleccione una fecha" required>
    </div>
    <?php endif; ?>

    <!-- Loading spinner -->
    <div id="loading" class="loading">
      <div class="spinner"></div>
      <div>Cargando reportes...</div>
    </div>

    <!-- BARRA DE PAGINACIÓN SUPERIOR -->
    <div style="display:flex; align-items:center; gap:10px; margin-bottom:6px;">
      <div>
        <label for="registrosPorPagina"><strong>Mostrar:</strong></label>
        <select id="registrosPorPagina">
          <option value="20" selected>20</option>
          <option value="50">50</option>
          <option value="-1">Todos</option>
        </select>
      </div>
      <div id="pageInfoTop" class="page-info" style="display:none;">
        Mostrando <strong id="showFromTop">0</strong> – <strong id="showToTop">0</strong> de <strong id="totalRowsTop">0</strong>
      </div>
      <div id="paginationTop" class="pagination" aria-label="Paginación" style="display:none;"></div>
    </div>


    <table id="tabla-depositos" border="1" cellpadding="10" cellspacing="0" style="width:100%; text-align:center;">
      <thead>
        <tr>
          <th>Expediente</th>
          <th>Depósito</th>
          <?php if (in_array($idRol, [1, 2])): ?>
            <th id="th-secretario">Secretario</th>
          <?php endif; ?>
          <th>Beneficiario</th>
          <th>Atención</th>
          <th id="th-estado">Estado</th>
          <th>Finalización</th>
        </tr>
      </thead>
      <tbody id="tabla-body">
        <!-- Los datos se cargarán dinámicamente via AJAX -->
      </tbody>
    </table>

    <!-- BARRA DE PAGINACIÓN INFERIOR -->
    <div style="display:flex; align-items:center; gap:10px; margin-top:10px;">
      <div id="pageInfoBottom" class="page-info" style="display:none;">
        Mostrando <strong id="showFromBottom">0</strong> – <strong id="showToBottom">0</strong> de <strong id="totalRowsBottom">0</strong>
      </div>
      <div id="paginationBottom" class="pagination" aria-label="Paginación inferior" style="display:none;"></div>
    </div>
  </div>

<script>
/* ================== Variables globales ================== */
let currentPage = 1;
let totalPages = 1;
let totalRecords = 0;
let isLoading = false;
let allDataForExport = []; // Para almacenar todos los datos visibles para exportación

const userRole = <?= json_encode($idRol) ?>;
const secretarios = <?= json_encode($secretarios) ?>;
const usuariosAOP = <?= json_encode($usuarios_aop) ?>;
const usuarioActual = <?= json_encode($datos_usuario_actual) ?>;
const filtroEstadoEl = document.getElementById('filtroEstado');
const filtroTipoEl = document.getElementById('filtroTipo');
const filtroTextoEl = document.getElementById('filtroTexto');
const filtroFechaEl = document.getElementById('filtroFecha');
const filtroSecretarioEl = document.getElementById('filtroSecretario');
const filtroUsuarioAOPEl = document.getElementById('filtroUsuarioAOP');
const registrosPorPaginaEl = document.getElementById('registrosPorPagina');
const tbody = document.getElementById('tabla-body');
const loadingEl = document.getElementById('loading');
const paginationTop = document.getElementById('paginationTop');
const paginationBottom = document.getElementById('paginationBottom');
const pageInfoTop = document.getElementById('pageInfoTop');
const pageInfoBottom = document.getElementById('pageInfoBottom');

/* ================== Funciones de paginación ================== */
function showLoading() {
  loadingEl.classList.add('show');
  tbody.innerHTML = '';
  paginationTop.style.display = 'none';
  paginationBottom.style.display = 'none';
  pageInfoTop.style.display = 'none';
  pageInfoBottom.style.display = 'none';
}

function hideLoading() {
  loadingEl.classList.remove('show');
}

function formatDate(dateStr) {
  if (!dateStr) return '--';
  const date = new Date(dateStr.replace(' ', 'T'));
  const day = String(date.getDate()).padStart(2, '0');
  const month = String(date.getMonth() + 1).padStart(2, '0');
  const year = date.getFullYear();
  const hours = String(date.getHours()).padStart(2, '0');
  const minutes = String(date.getMinutes()).padStart(2, '0');
  return `${day}/${month}/${year} ${hours}:${minutes}`;
}

// Función para actualizar la visibilidad de las columnas Secretario y Estado
function actualizarVisibilidadColumnas() {
  const secretarioSeleccionado = filtroSecretarioEl.value;
  const usuarioAOPSeleccionado = filtroUsuarioAOPEl.value;
  const esUsuarioAOP = userRole === 4;
  
  // Manejar columna Secretario
  const thSecretario = document.getElementById('th-secretario');
  const tdsSecretario = document.querySelectorAll('.td-secretario');
  
  if (thSecretario) {
    if (secretarioSeleccionado) {
      // Ocultar columna cuando hay secretario seleccionado
      thSecretario.style.display = 'none';
      tdsSecretario.forEach(td => td.style.display = 'none');
    } else {
      // Mostrar columna cuando no hay secretario seleccionado
      thSecretario.style.display = '';
      tdsSecretario.forEach(td => td.style.display = '');
    }
  }
  
  // Manejar columna Estado
  const thEstado = document.getElementById('th-estado');
  const tdsEstado = document.querySelectorAll('.td-estado');
  
  if (thEstado) {
    // Ocultar si es usuario AOP (rol 4) o si hay filtro de usuario AOP activo
    if (esUsuarioAOP || usuarioAOPSeleccionado) {
      thEstado.style.display = 'none';
      tdsEstado.forEach(td => td.style.display = 'none');
    } else {
      thEstado.style.display = '';
      tdsEstado.forEach(td => td.style.display = '');
    }
  }
}

function renderTable(data) {
  tbody.innerHTML = '';
  
  if (data.length === 0) {
    const tr = document.createElement('tr');
    const td = document.createElement('td');
    // Calcular colspan dinámicamente basado en columnas visibles
    let colSpan = 5; // Expediente, Depósito, Beneficiario, Atención, Finalización
    if (userRole === 1 || userRole === 2) colSpan += 1; // Secretario
    if (userRole !== 4) colSpan += 1; // Estado (solo si no es rol 4)
    td.colSpan = colSpan;
    td.style.textAlign = 'center';
    td.style.fontStyle = 'italic';
    td.textContent = 'No se encontraron depósitos con esos criterios.';
    tr.appendChild(td);
    tbody.appendChild(tr);
    return;
  }

  data.forEach(d => {
    const est = parseInt(d.id_estado, 10);
    const tr = document.createElement('tr');
    
    // Datos para filtros en frontend
    tr.dataset.estado = est;
    tr.dataset.juzgado = (d.nombre_juzgado || '').toLowerCase();
    tr.dataset.secretario = (d.nombre_secretario || '').toLowerCase();
    tr.dataset.dni = d.dni_beneficiario || '';
    tr.dataset.nombre = (d.nombre_beneficiario || '').toLowerCase();
    tr.dataset.fechaFinalizacion = d.fecha_finalizacion || '';
    
    // Verificar si hay filtros específicos activos
    const secretarioSeleccionado = filtroSecretarioEl.value;
    const usuarioAOPSeleccionado = filtroUsuarioAOPEl.value;
    const esUsuarioAOP = userRole === 4;
    
    // Preparar estado con nombre de entregador si aplica
    let estadoTexto = d.nombre_estado;
    if (est === 1) {
      const entregadorNombre = (d.usuario_entrega || '').trim();
      const entregadorDoc = (d.documento_entrega || '').trim();
      
      // Mostrar nombre solo si no hay filtro de usuario AOP activo y el usuario no es rol 4
      const mostrarNombreEntregador = !usuarioAOPSeleccionado && !esUsuarioAOP;
      
      if (mostrarNombreEntregador && entregadorNombre) {
        estadoTexto = `ENTREGADO - ${entregadorNombre}`;
      } else if (mostrarNombreEntregador && entregadorDoc) {
        estadoTexto = `ENTREGADO - ${entregadorDoc}`;
      } else {
        estadoTexto = 'ENTREGADO';
      }
    }
    
    let html = `
      <td>${d.n_expediente || ''}</td>
      <td>${d.n_deposito || ''}</td>
    `;
    
    // Solo mostrar columna secretario si no hay filtro de secretario activo
    const mostrarColumnaSecretario = !secretarioSeleccionado;
    if (userRole === 1 || userRole === 2) {
      if (mostrarColumnaSecretario) {
        html += `<td class="td-secretario">${d.nombre_secretario || ''}</td>`;
      } else {
        html += `<td class="td-secretario" style="display: none;">${d.nombre_secretario || ''}</td>`;
      }
    }
    
    // Determinar si mostrar columna Estado
    const mostrarColumnaEstado = !esUsuarioAOP && !usuarioAOPSeleccionado;
    
    html += `
      <td>${d.dni_beneficiario ? `${d.dni_beneficiario} – ${d.nombre_beneficiario}` : '<i>Sin beneficiario</i>'}</td>
      <td>${formatDate(d.fecha_atencion)}</td>`;
    
    if (mostrarColumnaEstado) {
      html += `<td class="td-estado">${estadoTexto}</td>`;
    } else {
      html += `<td class="td-estado" style="display: none;">${estadoTexto}</td>`;
    }
    
    html += `<td>${est === 1 && d.fecha_finalizacion ? formatDate(d.fecha_finalizacion) : '--'}</td>`;
    
    tr.innerHTML = html;
    tbody.appendChild(tr);
  });
  
  // Solo aplicar filtros de frontend secundarios (juzgado/secretario/fechas)
  // NO filtrar por estado porque ya viene filtrado del backend
  aplicarFiltrosSecundariosFrontend();
  
  // Actualizar visibilidad de columnas según filtros
  actualizarVisibilidadColumnas();
}

function renderPagination(pagination) {
  const limit = pagination.limit;
  
  // Si es "Todos" (-1), no mostrar paginación pero sí el contador
  if (limit === -1 || pagination.total_pages <= 1) {
    paginationTop.style.display = 'none';
    paginationBottom.style.display = 'none';
    
    // Actualizar el contador después de aplicar filtros secundarios
    // Usamos setTimeout para que se ejecute después de aplicarFiltrosSecundariosFrontend
    setTimeout(() => {
      updateVisibleRecordsCount();
    }, 50);
    return;
  }

  // Mostrar elementos de paginación
  paginationTop.style.display = 'flex';
  paginationBottom.style.display = 'flex';
  pageInfoTop.style.display = 'block';
  pageInfoBottom.style.display = 'block';
  
  // Información de paginación
  const start = ((pagination.current_page - 1) * limit) + 1;
  const end = Math.min(pagination.current_page * limit, pagination.total_records);
  
  // Actualizar información en ambas barras
  document.getElementById('showFromTop').textContent = start;
  document.getElementById('showToTop').textContent = end;
  document.getElementById('totalRowsTop').textContent = pagination.total_records;
  document.getElementById('showFromBottom').textContent = start;
  document.getElementById('showToBottom').textContent = end;
  document.getElementById('totalRowsBottom').textContent = pagination.total_records;

  // Función para crear enlaces de paginación
  function createPageLink(page, label, disabled = false, isCurrent = false) {
    if (disabled) {
      return `<span class="${isCurrent ? 'current' : 'disabled'}">${label}</span>`;
    }
    if (isCurrent) {
      return `<span class="current">${label}</span>`;
    }
    return `<a href="#" onclick="goToPage(${page}); return false;">${label}</a>`;
  }

  // Generar HTML de paginación
  let paginationHtml = '';
  
  // Primero / Prev
  paginationHtml += createPageLink(1, '« Primero', pagination.current_page <= 1);
  paginationHtml += createPageLink(pagination.current_page - 1, '‹ Prev', !pagination.has_prev);

  // Mostrar ventana de páginas (5 páginas alrededor)
  const window = 5;
  let startPage = Math.max(1, pagination.current_page - Math.floor(window/2));
  let endPage = Math.min(pagination.total_pages, startPage + window - 1);
  
  if (endPage - startPage + 1 < window) {
    startPage = Math.max(1, endPage - window + 1);
  }
  
  for (let p = startPage; p <= endPage; p++) {
    paginationHtml += createPageLink(p, p, false, p === pagination.current_page);
  }

  // Next / Last
  paginationHtml += createPageLink(pagination.current_page + 1, 'Next ›', !pagination.has_next);
  paginationHtml += createPageLink(pagination.total_pages, 'Último »', pagination.current_page >= pagination.total_pages);

  // Aplicar HTML a ambas barras de paginación
  paginationTop.innerHTML = paginationHtml;
  paginationBottom.innerHTML = paginationHtml;
}

function goToPage(page) {
  if (page < 1 || page > totalPages || isLoading) return;
  currentPage = page;
  loadDepositos();
}

/* ================== Carga de datos ================== */
async function loadDepositos() {
  if (isLoading) return;
  
  isLoading = true;
  showLoading();

  const params = new URLSearchParams({
    page: currentPage,
    limit: registrosPorPaginaEl.value,
    filtroEstado: 'entregados',
    filtroTipo: filtroTipoEl?.value || '',
    filtroTexto: filtroTextoEl?.value || '',
    filtroFecha: filtroFechaEl.value,
    filtroSecretario: filtroSecretarioEl.value,
    filtroUsuarioAOP: filtroUsuarioAOPEl.value
  });

  try {
    const response = await fetch(`../api/get_reportes_depositos_paginados.php?${params}`, {
      credentials: 'same-origin'
    });

    if (!response.ok) {
      throw new Error('Error en la respuesta del servidor');
    }

    const data = await response.json();

    if (!data.success) {
      throw new Error(data.message || 'Error desconocido');
    }

    allDataForExport = data.data; // Guardar para exportación
    renderTable(data.data);
    renderPagination(data.pagination);
    
    totalPages = data.pagination.total_pages;
    totalRecords = data.pagination.total_records;



  } catch (error) {
    console.error('Error cargando depósitos:', error);
    Swal.fire('Error', 'No se pudieron cargar los depósitos. Intenta de nuevo.', 'error');
    tbody.innerHTML = '<tr><td colspan="8" style="text-align: center; color: red;">Error cargando datos</td></tr>';
  } finally {
    isLoading = false;
    hideLoading();
  }
}

/* ================== Filtros en frontend ================== */
// Función para actualizar el contador de registros visibles
function updateVisibleRecordsCount() {
  const visibleRows = Array.from(document.querySelectorAll('#tabla-depositos tbody tr')).filter(tr => tr.style.display !== 'none');
  const count = visibleRows.length;
  
  if (count > 0) {
    pageInfoTop.style.display = 'block';
    pageInfoBottom.style.display = 'block';
    document.getElementById('showFromTop').textContent = 1;
    document.getElementById('showToTop').textContent = count;
    document.getElementById('totalRowsTop').textContent = count;
    document.getElementById('showFromBottom').textContent = 1;
    document.getElementById('showToBottom').textContent = count;
    document.getElementById('totalRowsBottom').textContent = count;
  } else {
    pageInfoTop.style.display = 'none';
    pageInfoBottom.style.display = 'none';
  }
}

// Filtros secundarios (juzgado, secretario, fechas) - NO filtrar por estado aquí
function aplicarFiltrosSecundariosFrontend() {
  const tipo = filtroTipoEl?.value;
  const texto = filtroTextoEl?.value.toLowerCase() || '';
  const fechaSeleccionada = filtroFechaEl.value;
  const usarFecha = fechaSeleccionada; // Filtrar por fecha si está seleccionada

  let hasFilters = false;
  if (texto || usarFecha) {
    hasFilters = true;
  }

  // Si no hay filtros secundarios, mostrar todo
  if (!hasFilters) {
    document.querySelectorAll('#tabla-depositos tbody tr').forEach(tr => {
      tr.style.display = '';
    });
    updateVisibleRecordsCount();
    

    return;
  }

  // Aplicar filtros secundarios
  document.querySelectorAll('#tabla-depositos tbody tr').forEach(tr => {
    let visible = true;

    // Filtro por texto (juzgado o secretario)
    if (visible && texto && tipo) {
      const campo = tipo === 'juzgado' ? 'juzgado' : 'secretario';
      const valor = tr.dataset[campo] || '';
      if (!valor.includes(texto)) visible = false;
    }

    // Filtro por fecha de finalización
    if (visible && usarFecha) {
      const fechaFinalIso = tr.dataset.fechaFinalizacion || '';
      if (!fechaFinalIso) {
        visible = false;
      } else {
        // Extraer solo la fecha (sin hora) para comparación
        const fechaFinal = fechaFinalIso.split(' ')[0]; // Obtener solo YYYY-MM-DD
        if (fechaFinal !== fechaSeleccionada) {
          visible = false;
        }
      }
    }

    tr.style.display = visible ? '' : 'none';
  });
  
  updateVisibleRecordsCount();
  

}

function aplicarFiltros() {
  currentPage = 1; // Reset a la primera página
  loadDepositos();
}

// debounce helper
function debounce(fn, ms = 300) {
  let t;
  return (...args) => {
    clearTimeout(t);
    t = setTimeout(() => fn.apply(this, args), ms);
  };
}

// Event listeners
const debouncedAplicarSecundarios = debounce(aplicarFiltrosSecundariosFrontend, 300);

// Estado fijo en 'entregados' - no necesita event listener

// Cambio de secretario: recargar desde servidor
filtroSecretarioEl.addEventListener('change', aplicarFiltros);

// Cambio de usuario AOP: recargar desde servidor
if (filtroUsuarioAOPEl && filtroUsuarioAOPEl.type !== 'hidden') {
  filtroUsuarioAOPEl.addEventListener('change', aplicarFiltros);
}

// Cambios en filtros secundarios (juzgado/secretario): filtrar en frontend
if (filtroTipoEl) filtroTipoEl.addEventListener('change', aplicarFiltrosSecundariosFrontend);
if (filtroTextoEl) filtroTextoEl.addEventListener('input', debouncedAplicarSecundarios);
// Filtro de fecha: recargar desde API para obtener todos los registros de esa fecha
filtroFechaEl.addEventListener('change', aplicarFiltros);



// Cambio de registros por página: recargar desde servidor
registrosPorPaginaEl.addEventListener('change', aplicarFiltros);

// Cargar datos iniciales
document.addEventListener('DOMContentLoaded', loadDepositos);

function exportarPDF() {
  // Validar que todos los filtros obligatorios estén seleccionados
  const secretario = filtroSecretarioEl.value;
  const fecha = filtroFechaEl.value;
  const usuarioAOP = filtroUsuarioAOPEl.value;
  
  // Verificar filtros obligatorios según el rol
  if (!secretario) {
    Swal.fire('Error', 'Debe seleccionar un secretario específico para exportar el PDF.', 'error');
    return;
  }
  
  if (!fecha) {
    Swal.fire('Error', 'Debe seleccionar una fecha de finalización para exportar el PDF.', 'error');
    return;
  }
  
  // Para usuarios que no son AOP (rol 4), verificar que hayan seleccionado usuario
  <?php if ($idRol != 4): ?>
  if (!usuarioAOP) {
    Swal.fire('Error', 'Debe seleccionar un usuario específico para exportar el PDF.', 'error');
    return;
  }
  <?php endif; ?>
  
  // Si todas las validaciones pasan, exportar el PDF
  exportarPDFReporteCompleto();
}

// Función para obtener todos los datos filtrados para exportación (sin paginación)
async function obtenerDatosParaExportacion() {
  const params = new URLSearchParams({
    page: 1,
    limit: -1, // Obtener todos los registros
    filtroEstado: 'entregados',
    filtroTipo: filtroTipoEl?.value || '',
    filtroTexto: filtroTextoEl?.value || '',
    filtroFecha: filtroFechaEl.value,
    filtroSecretario: filtroSecretarioEl.value,
    filtroUsuarioAOP: filtroUsuarioAOPEl.value
  });

  const response = await fetch(`../api/get_reportes_depositos_paginados.php?${params}`, {
    credentials: 'same-origin'
  });

  if (!response.ok) {
    throw new Error('Error en la respuesta del servidor');
  }

  const data = await response.json();

  if (!data.success) {
    throw new Error(data.message || 'Error desconocido');
  }

  // Ya no necesitamos filtros secundarios en el frontend porque ahora
  // el filtro de fecha se maneja completamente en el backend
  let filteredData = data.data;
  
  const tipo = filtroTipoEl?.value;
  const texto = filtroTextoEl?.value.toLowerCase() || '';

  // Solo aplicar filtros de texto si existen (el filtro de fecha ya se aplicó en backend)
  if (texto) {
    filteredData = data.data.filter(deposito => {
      let coincide = true;
      
      // Filtrar por texto (juzgado o secretario)
      if (texto) {
        if (tipo === 'juzgado') {
          coincide = coincide && (deposito.nombre_juzgado || '').toLowerCase().includes(texto);
        } else if (tipo === 'secretario') {
          coincide = coincide && (deposito.nombre_secretario || '').toLowerCase().includes(texto);
        }
      }
      
      return coincide;
    });
  }

  return filteredData;
}

// Función auxiliar para obtener entregas por usuario desde datos de exportación
function getEntregasPorUsuarioFromData(exportData) {
  const entregas = {};
  
  exportData.forEach(deposito => {
    if (deposito.id_estado == 1 && deposito.usuario_entrega) {
      const nombreEntregador = deposito.usuario_entrega.trim();
      if (nombreEntregador.length) {
        entregas[nombreEntregador] = (entregas[nombreEntregador] || 0) + 1;
      }
    }
  });
  
  return entregas;
}

// Función auxiliar para obtener entregas por secretario desde datos de exportación
function getEntregasPorSecretarioFromData(exportData) {
  const entregas = {};
  
  exportData.forEach(deposito => {
    if (deposito.id_estado == 1) {
      const secretario = deposito.nombre_secretario || 'Sin secretario';
      entregas[secretario] = (entregas[secretario] || 0) + 1;
    }
  });
  
  return entregas;
}



// Función para exportar PDF con reporte completo (tabla + gráficos + resumen)
async function exportarPDFReporteCompleto() {
  const { jsPDF } = window.jspdf;
  
  // Mostrar loading durante la obtención de datos para exportación
  Swal.fire({
    title: 'Preparando exportación...',
    text: 'Obteniendo todos los datos filtrados',
    allowOutsideClick: false,
    didOpen: () => {
      Swal.showLoading();
    }
  });

  try {
    // Obtener TODOS los datos filtrados para exportación (sin paginación)
    const exportData = await obtenerDatosParaExportacion();
    
    Swal.close();
    
    if (!exportData || exportData.length === 0) {
      Swal.fire('Información', 'No hay datos para exportar con los filtros aplicados.', 'info');
      return;
    }

    const doc = new jsPDF("p", "mm", "a4");

    // Función para agregar encabezado en cada página
    function agregarEncabezado(doc, numeroPagina, totalPaginas) {
      // Obtener fecha y hora actual
      const ahora = new Date();
      const fechaHora = ahora.toLocaleString('es-PE', {
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit'
      });

      // Lado izquierdo - Información institucional
      doc.setFont("helvetica", "bold");
      doc.setFontSize(8);
      doc.setTextColor(0, 0, 0);
      doc.text("PODER JUDICIAL DEL PERÚ", 14, 10);
      doc.text("CORTE SUPERIOR DE JUSTICIA", 14, 15);
      doc.text("HUAURA", 14, 20);
      
      doc.setFont("helvetica", "normal");
      doc.setFontSize(9);
      doc.text("Sede Barranca - Jiron Gálvez N° 542 - Barranca", 14, 25);

      // Lado derecho - Fecha, hora y paginación
      doc.setFont("helvetica", "normal");
      doc.setFontSize(8);
      doc.setTextColor(0, 0, 0);
      
      const anchoHoja = doc.internal.pageSize.getWidth();
      const margenDerecho = 14;
      
      // Fecha y hora (alineado a la derecha, parte superior)
      doc.text(fechaHora, anchoHoja - margenDerecho + 1, 10, { align: "right" });
      
      // Número de página (alineado a la derecha, justo debajo)
      doc.text(`Pág ${numeroPagina} de ${totalPaginas}`, anchoHoja - margenDerecho, 15, { align: "right" });
      
    }

    // --- 1) construir headers y body desde los datos obtenidos ---
    const tabla = document.getElementById("tabla-depositos");
    const headers = [];
    const body = [];

    // Verificar filtros activos para determinar columnas visibles
    const filtroSecretarioActivo = filtroSecretarioEl.value;
    const usuarioAOPSeleccionado = filtroUsuarioAOPEl.value;
    const esUsuarioAOP = userRole === 4;

    tabla.querySelectorAll("thead th").forEach((th) => {
      const texto = th.innerText.trim();
      // Omitir columnas según filtros activos
      if (texto.toLowerCase().includes('juzgado')) {
        return; // Omitir columna Juzgado siempre
      }
      if (texto.toLowerCase().includes('secretario') && filtroSecretarioActivo) {
        return; // Omitir columna Secretario si hay filtro activo
      }
      if (texto.toLowerCase().includes('estado') && (esUsuarioAOP || usuarioAOPSeleccionado)) {
        return; // Omitir columna Estado para rol 4 o cuando hay filtro de usuario AOP
      }
      headers.push(texto);
    });
    
    // Agregar columna Observación al final (solo para PDF)
    headers.push('Observación');

    // Construir filas desde los datos de exportación
    exportData.forEach((deposito) => {
      const row = [];
      
      // Expediente
      row.push(deposito.n_expediente || '--');
      
      // Depósito
      row.push(deposito.n_deposito || '--');
      
      // Secretario (solo si está en headers - se omite si hay filtro de secretario)
      if (headers.some(h => h.toLowerCase().includes('secretario'))) {
        row.push(deposito.nombre_secretario || '--');
      }
      
      // Beneficiario
      const beneficiario = deposito.nombre_beneficiario || 'Sin beneficiario';
      row.push(beneficiario);
      
      // Atención
      const fechaAtencion = deposito.fecha_atencion ? formatDate(deposito.fecha_atencion) : '--';
      row.push(fechaAtencion);
      
      // Estado - solo si está en headers (no para rol 4 o con filtro AOP)
      if (headers.some(h => h.toLowerCase().includes('estado'))) {
        let estadoTexto = deposito.nombre_estado || '--';
        if (deposito.id_estado == 1) {
          const mostrarNombreEntregador = !usuarioAOPSeleccionado && !esUsuarioAOP;
          
          if (mostrarNombreEntregador && deposito.usuario_entrega) {
            estadoTexto = `ENTREGADO - ${deposito.usuario_entrega}`;
          } else {
            estadoTexto = 'ENTREGADO';
          }
        }
        row.push(estadoTexto);
      }
      
      // Finalización
      const fechaFinalizacion = deposito.fecha_finalizacion ? formatDate(deposito.fecha_finalizacion) : '--';
      row.push(fechaFinalizacion);
      
      // Observación (columna vacía)
      row.push('');
      
      body.push(row);
    });

  // Calcular total de páginas (estimación inicial - se actualizará después)
  let totalPaginasEstimado = 2; // Estimación inicial

  // Función para agregar pie de página con firmas
  function agregarPiePagina(doc) {
    const altoHoja = doc.internal.pageSize.getHeight();
    const anchoHoja = doc.internal.pageSize.getWidth();
    const margenInferior = 30; // Aumentado para más separación
    const yInicio = altoHoja - margenInferior;
    
    // Líneas para firmas
    const anchuraLinea = 50;
    const xIzquierda = 30;
    const xDerecha = anchoHoja - 30 - anchuraLinea;
    
    doc.setLineWidth(0.3);
    doc.setDrawColor(0, 0, 0);
    doc.line(xIzquierda, yInicio, xIzquierda + anchuraLinea, yInicio);
    doc.line(xDerecha, yInicio, xDerecha + anchuraLinea, yInicio);
    
    // Textos de firma
    doc.setFont("helvetica", "normal");
    doc.setFontSize(8);
    doc.setTextColor(0, 0, 0);
    
    // Obtener datos del secretario seleccionado
    const secretarioSeleccionado = filtroSecretarioEl.value;
    const secretario = secretarios.find(s => s.documento === secretarioSeleccionado);
    const nombreSecretario = secretario ? secretario.nombre_completo : 'Secretario';
    const dniSecretario = secretario ? secretario.documento : '00000000';
    
    // Obtener datos del usuario para firma derecha
    let nombreUsuario = 'Administrador';
    let dniUsuario = '00000000';
    
    if (userRole === 4) {
      // Si el usuario actual es AOP (rol 4), usar sus datos
      nombreUsuario = usuarioActual ? usuarioActual.nombre_completo : 'AOP';
      dniUsuario = usuarioActual ? usuarioActual.documento : '00000000';
    } else {
      // Si el usuario no es AOP, usar el usuario seleccionado en el filtro
      const usuarioAOPSeleccionado = filtroUsuarioAOPEl.value;
      const usuario = usuariosAOP.find(u => u.documento === usuarioAOPSeleccionado);
      nombreUsuario = usuario ? usuario.nombre_completo : 'Administrador';
      dniUsuario = usuario ? usuario.documento : '00000000';
    }
    
    // Firma izquierda (Secretario)
    doc.text(nombreSecretario, xIzquierda + (anchuraLinea / 2), yInicio + 4, { align: "center" });
    doc.text(`DNI: ${dniSecretario}`, xIzquierda + (anchuraLinea / 2), yInicio + 8, { align: "center" });
    
    // Firma derecha (Usuario/Administrador)
    doc.text(nombreUsuario, xDerecha + (anchuraLinea / 2), yInicio + 4, { align: "center" });
    doc.text(`DNI: ${dniUsuario}`, xDerecha + (anchuraLinea / 2), yInicio + 8, { align: "center" });
  }

  // Página 1: tabla
  agregarEncabezado(doc, 1, totalPaginasEstimado);
  
  doc.setFont("helvetica", "bold");
  doc.setFontSize(10);
  doc.setTextColor(132, 0, 0);
  doc.text('"REPORTE DE ENTREGA DE ÓRDENES DE PAGO"', doc.internal.pageSize.getWidth() / 2, 38, { align: "center" });

  doc.setFont("helvetica", "normal");
  doc.setFontSize(10);
  doc.text("LISTA DE DOCUMENTOS ENTREGADOS", doc.internal.pageSize.getWidth() / 2, 43, { align: "center" });

  // Mostrar información de fechas
  let textoFecha = '';
  const fechaSeleccionada = filtroFechaEl.value;
  
  if (fechaSeleccionada) {
    // Si hay fecha seleccionada, mostrarla
    const fechaFormateada = new Date(fechaSeleccionada + 'T00:00:00').toLocaleDateString('es-PE', {
      year: 'numeric',
      month: 'long',
      day: 'numeric'
    });
    textoFecha = `Fecha: ${fechaFormateada}`;
  } else {
    // Si no hay fecha seleccionada, mostrar rango de fechas de los datos
    if (exportData.length > 0) {
      const fechas = exportData
        .map(d => d.fecha_finalizacion)
        .filter(f => f) // Filtrar valores nulos/vacíos
        .map(f => new Date(f.split(' ')[0] + 'T00:00:00')) // Convertir solo la parte de fecha
        .sort((a, b) => a - b); // Ordenar fechas
      
      if (fechas.length > 0) {
        const fechaMasAntigua = fechas[0].toLocaleDateString('es-PE', {
          year: 'numeric',
          month: 'long',
          day: 'numeric'
        });
        const fechaMasReciente = fechas[fechas.length - 1].toLocaleDateString('es-PE', {
          year: 'numeric',
          month: 'long',
          day: 'numeric'
        });
        
        if (fechaMasAntigua === fechaMasReciente) {
          textoFecha = `Fecha: ${fechaMasAntigua}`;
        } else {
          textoFecha = `Desde: ${fechaMasAntigua} - Hasta: ${fechaMasReciente}`;
        }
      } else {
        textoFecha = 'Periodo: Sin fechas de finalización registradas';
      }
    }
  }
  
  doc.setFont("helvetica", "normal");
  doc.setFontSize(9);
  doc.text(textoFecha, 14, 49);

  // Línea separadora
  doc.setLineWidth(0.3);
  doc.setDrawColor(132, 0, 0);
  doc.line(14, 52, doc.internal.pageSize.getWidth() - 14, 52);
  
  // Mostrar nombre del secretario seleccionado
  const secretarioSeleccionadoNombre = filtroSecretarioEl.options[filtroSecretarioEl.selectedIndex].text;
  if (secretarioSeleccionadoNombre && secretarioSeleccionadoNombre !== 'Todos los secretarios') {
    doc.setFont("helvetica", "normal");
    doc.setFontSize(9);
    doc.text(`Secretario: ${secretarioSeleccionadoNombre}`, 14, 57);
  }

  doc.autoTable({
    head: [headers],
    body: body,
    startY: 61,
    margin: { 
      top: 35, // Espacio para el encabezado
      bottom: 50, // Espacio aumentado para el pie de página
      left: 14,
      right: 14
    },
    pageBreak: 'auto',
    rowPageBreak: 'avoid', // Evitar que las filas se corten
    styles: {
      fontSize: 7,
      font: "helvetica",
      textColor: [0, 0, 0],
      cellPadding: 2.5, // Más padding para evitar cortes
      lineWidth: 0.1,
      minCellHeight: 7, // Altura mínima para evitar texto cortado
      overflow: 'linebreak'
    },
    headStyles: {
      fillColor: [132, 0, 0],
      textColor: 255,
      fontSize: 7,
      fontStyle: "bold",
      halign: "center",
      minCellHeight: 9, // Mayor altura para headers
      cellPadding: 3.5
    },
    bodyStyles: {
      fillColor: [255, 255, 255],
      textColor: [33, 33, 33],
      halign: "left",
      valign: "middle"
    },
    alternateRowStyles: {
      fillColor: [248, 225, 225],
    },
    tableLineColor: [92, 0, 0],
    tableLineWidth: 0.1,
    didDrawPage: function (data) {
      // Agregar encabezado a cada página de la tabla
      if (data.pageNumber > 1) {
        agregarEncabezado(doc, data.pageNumber, totalPaginasEstimado);
      }
      // Agregar pie de página en cada página
      agregarPiePagina(doc);
    }
  });

    // Obtener el número total real de páginas
    const totalPaginasReales = doc.internal.getNumberOfPages();
    
    // Actualizar todos los encabezados con el número total correcto de páginas
    for (let i = 1; i <= totalPaginasReales; i++) {
      doc.setPage(i);
      // Limpiar encabezado anterior y agregar el correcto
      doc.setDrawColor(255, 255, 255);
      doc.setFillColor(255, 255, 255);
      doc.rect(0, 0, doc.internal.pageSize.getWidth(), 32, 'F');
      
      agregarEncabezado(doc, i, totalPaginasReales);
      // Agregar pie de página si no se agregó durante la tabla
      if (i === 1) {
        agregarPiePagina(doc);
      }
    }

    // Guardar PDF
    doc.save("reporte_depositos_judiciales.pdf");
    
  } catch (error) {
    console.error('Error en exportación:', error);
    Swal.close();
    Swal.fire('Error', 'Hubo un problema al generar el PDF. Intenta de nuevo.', 'error');
  }
}



</script>

</body>
</html>