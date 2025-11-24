<?php
// reporte_deposito.php
// Versión con paginación: carga datos dinámicamente vía AJAX
// Mantiene todas las funcionalidades de filtros y exportación a PDF
// Soporte para reportes especializados: juzgado y secretario

if (session_status() === PHP_SESSION_NONE) session_start();

// Validar sesión básica
if (!isset($_SESSION['documento']) || !isset($_SESSION['rol'])) {
    die('Sesión no iniciada.');
}

$idRol = intval($_SESSION['rol']); // asegurar tipo entero

// Determinar tipo de reporte
$tipoReporte = $_GET['tipo'] ?? 'general';
$esReporteJuzgado = ($tipoReporte === 'juzgado');
$esReporteSecretario = ($tipoReporte === 'secretario');
$esReporteUsuario = ($tipoReporte === 'usuario');

// Obtener datos para los filtros especializados
include("../code_back/conexion.php");

if ($esReporteJuzgado) {
    // Cargar tipos de juzgado y juzgados
    $sql_tipos_juzgado = "SELECT DISTINCT tipo_juzgado FROM juzgado ORDER BY tipo_juzgado";
    $result_tipos = mysqli_query($cn, $sql_tipos_juzgado);
    $tipos_juzgado = [];
    while ($row = mysqli_fetch_assoc($result_tipos)) {
        $tipos_juzgado[] = $row['tipo_juzgado'];
    }
    
    $sql_juzgados = "SELECT id_juzgado, nombre_juzgado, tipo_juzgado FROM juzgado ORDER BY tipo_juzgado, nombre_juzgado";
    $result_juzgados = mysqli_query($cn, $sql_juzgados);
    $juzgados = [];
    while ($row = mysqli_fetch_assoc($result_juzgados)) {
        $juzgados[] = $row;
    }
} elseif ($esReporteSecretario) {
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
} elseif ($esReporteUsuario) {
    // Cargar usuarios (rol 4) que realmente han entregado depósitos
    $sql_usuarios = "SELECT DISTINCT hd.documento_usuario as documento, 
                            CONCAT(p.nombre_persona, ' ', p.apellido_persona) AS nombre_completo
                     FROM historial_deposito hd
                     JOIN persona p ON hd.documento_usuario = p.documento 
                     JOIN usuario u ON p.documento = u.codigo_usu 
                     WHERE u.id_rol = 4 
                     AND hd.tipo_evento = 'CAMBIO_ESTADO' 
                     AND hd.estado_nuevo = 1
                     AND hd.documento_usuario IS NOT NULL
                     ORDER BY p.nombre_persona, p.apellido_persona";
    $result_usuarios = mysqli_query($cn, $sql_usuarios);
    $usuarios = [];
    while ($row = mysqli_fetch_assoc($result_usuarios)) {
        $usuarios[] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Reporte de los certificados de Depósitos Judiciales</title>
  <link rel="stylesheet" href="../css/crear_usuario.css">
  <link rel="stylesheet" href="../css/deposito_ventana.css">
  <link rel="stylesheet" href="../css/menu_admin.css">
  <link rel="stylesheet" href="../css/css_admin/all.min.css">
  <script src="../js/sweetalert2.all.min.js"></script>
  <style>
    .filtro-group { margin-bottom: 15px; display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
    
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
    <h1>
      <?php if ($esReporteJuzgado): ?>
        Reporte de los certificados de Depósitos Judiciales (Por juzgado)
      <?php elseif ($esReporteSecretario): ?>
        Reporte de los certificados de Depósitos Judiciales (Por secretario)
      <?php elseif ($esReporteUsuario): ?>
        Reporte de los certificados de Depósitos Judiciales (Por usuario)
      <?php else: ?>
        Reporte de los certificados de Depósitos Judiciales
      <?php endif; ?>
    </h1>
    
    <!-- Filtros para reporte general -->
    <?php if (!$esReporteJuzgado && !$esReporteSecretario && !$esReporteUsuario): ?>
    <div class="filtro-group">
      <label for="filtroEstado"><strong>Estado:</strong></label>
      <select id="filtroEstado">
        <option value="entregados">Entregados</option>
        <option value="porentregar">Por entregar</option>
      </select>

      <?php if (!in_array($idRol, [1,2])): ?>
        <label for="filtroTipo"><strong>Ver:</strong></label>
        <select id="filtroVerReporte">
          <option value="Reporte">Reporte de depósitos judiciales</option>
          <option value="Usuario">Distribución de entregas por usuario</option>
          <option value="Secretario">Distribución de entregas por secretario</option>
        </select>
        <input type="button" value="Exportar PDF" onclick="exportarPDF()" style="height: 38px; margin-top: 0; padding: 10px 25px;">
      <?php endif; ?>

      <?php if (in_array($idRol, [1, 2])): ?>
        <label for="filtroTipo"><strong>Filtrar por:</strong></label>
        <select id="filtroTipo">
          <option value="juzgado">Juzgado</option>
          <option value="secretario">Secretario</option>
        </select>
        <input type="text" id="filtroTexto" placeholder="Escribe para filtrar..." autocomplete="off">
      <?php endif; ?>
    </div>

    <div class="filtro-group">
      <?php if (in_array($idRol, [1, 2])): ?>
      <label for="filtroTipo"><strong>Ver:</strong></label>
      <select id="filtroVerReporte">
        <option value="Reporte">Reporte de depósitos judiciales</option>
        <option value="Usuario">Distribución de entregas por usuario</option>
        <option value="Secretario">Distribución de entregas por secretario</option>
      </select>
      <input type="button" value="Exportar PDF" onclick="exportarPDF()" style="height: 38px; margin-top: 0; padding: 10px 25px;">
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Filtros para reporte por juzgado -->
    <?php if ($esReporteJuzgado): ?>
    <div class="filtro-group">
      <label for="filtroEstadoJuzgado"><strong>Estado:</strong></label>
      <select id="filtroEstadoJuzgado">
        <option value="entregados">Entregados</option>
        <option value="porentregar">Por entregar</option>
      </select>

      <label for="filtroVerReporte"><strong>Ver:</strong></label>
      <select id="filtroVerReporte">
        <option value="Reporte">Reporte de depósitos judiciales</option>
        <option value="Usuario">Distribución de entregas por usuario</option>
        <option value="Secretario">Distribución de entregas por secretario</option>
      </select>
      
      <input type="button" value="Exportar PDF" onclick="exportarPDF()" style="height: 38px; margin-top: 0; padding: 10px 25px;">
    </div>

    <div class="filtro-group">
      <label for="filtroTipoJuzgado"><strong>Tipo de Juzgado:</strong></label>
      <select id="filtroTipoJuzgado" style="max-width: 20%;">
        <option value="" disabled selected>Seleccione tipo de juzgado</option>
        <?php foreach ($tipos_juzgado as $tipo): ?>
          <option value="<?= htmlspecialchars($tipo) ?>"><?= htmlspecialchars($tipo) ?></option>
        <?php endforeach; ?>
      </select>
      <label for="filtroJuzgadoEspecifico"><strong>Juzgado:</strong></label>
      <select id="filtroJuzgadoEspecifico" style="max-width: 80%;" disabled>
        <option value="">Seleccione un tipo primero</option>
      </select>
    </div>
    <?php endif; ?>

    <!-- Filtros para reporte por secretario -->
    <?php if ($esReporteSecretario): ?>
    <div class="filtro-group">
      <label for="filtroEstadoSecretario"><strong>Estado:</strong></label>
      <select id="filtroEstadoSecretario">
        <option value="entregados">Entregados</option>
        <option value="porentregar">Por entregar</option>
      </select>
      
      <label for="filtroSecretarioEspecifico"><strong>Secretario:</strong></label>
      <select id="filtroSecretarioEspecifico">
        <option value="" disabled selected>Seleccione un secretario</option>
        <?php foreach ($secretarios as $secretario): ?>
          <option value="<?= htmlspecialchars($secretario['documento']) ?>"><?= htmlspecialchars($secretario['nombre_completo']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="filtro-group">
      <label for="filtroVerReporte"><strong>Ver:</strong></label>
      <select id="filtroVerReporte">
        <option value="Reporte">Reporte de depósitos judiciales</option>
        <option value="Usuario">Distribución de entregas por usuario</option>
      </select>
      
      <input type="button" value="Exportar PDF" onclick="exportarPDF()" style="height: 38px; margin-top: 0; padding: 10px 25px;">
    </div>
    <?php endif; ?>

    <!-- Filtros para reporte por usuario -->
    <?php if ($esReporteUsuario): ?>
    <div class="filtro-group">
      <label for="filtroUsuarioEspecifico"><strong>Usuario:</strong></label>
      <select id="filtroUsuarioEspecifico">
        <option value="" disabled selected>Seleccione un usuario</option>
        <?php foreach ($usuarios as $usuario): ?>
          <option value="<?= htmlspecialchars($usuario['documento']) ?>"><?= htmlspecialchars($usuario['nombre_completo']) ?></option>
        <?php endforeach; ?>
      </select>
      <label for="filtroVerReporte"><strong>Ver:</strong></label>
      <select id="filtroVerReporte">
        <option value="Reporte">Reporte de depósitos judiciales</option>
        <option value="Secretario">Distribución de entregas por secretario</option>
      </select>
      
      <input type="button" value="Exportar PDF" onclick="exportarPDF()" style="height: 38px; margin-top: 0; padding: 10px 25px;">
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

    <!-- Contenedor para gráficos (inicialmente oculto) -->
    <div id="graficos-container" class="oculto" style="margin-bottom:20px;">
      <canvas id="chartCanvas" style="max-width:100%; height:auto;"></canvas>
    </div>
    <table id="tabla-depositos" border="1" cellpadding="10" cellspacing="0" style="width:100%; text-align:center;">
      <thead>
        <tr>
          <th>Expediente</th>
          <th>Depósito</th>
          <th>Juzgado</th>
          <?php if (in_array($idRol, [1, 2])): ?>
            <th>Secretario</th>
          <?php endif; ?>
          <th>Beneficiario</th>
          <th>Envío</th>
          <th>Atención</th>
          <th>Estado</th>
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
const tipoReporte = <?= json_encode($tipoReporte) ?>;
const esReporteJuzgado = <?= json_encode($esReporteJuzgado) ?>;
const esReporteSecretario = <?= json_encode($esReporteSecretario) ?>;
const esReporteUsuario = <?= json_encode($esReporteUsuario) ?>;

// Datos para filtros especializados
<?php if ($esReporteJuzgado): ?>
const juzgadosData = <?= json_encode($juzgados) ?>;
<?php endif; ?>

<?php if ($esReporteSecretario): ?>
const secretariosData = <?= json_encode($secretarios) ?>;
<?php endif; ?>

<?php if ($esReporteUsuario): ?>
const usuariosData = <?= json_encode($usuarios) ?>;
<?php endif; ?>

// Referencias a elementos DOM
const filtroEstadoEl = document.getElementById('filtroEstado');
const filtroTipoEl = document.getElementById('filtroTipo');
const filtroTextoEl = document.getElementById('filtroTexto');

// Nuevos elementos para reportes especializados
const filtroTipoJuzgadoEl = document.getElementById('filtroTipoJuzgado');
const filtroJuzgadoEspecificoEl = document.getElementById('filtroJuzgadoEspecifico');
const filtroSecretarioEspecificoEl = document.getElementById('filtroSecretarioEspecifico');
const filtroUsuarioEspecificoEl = document.getElementById('filtroUsuarioEspecifico');
const filtroEstadoJuzgadoEl = document.getElementById('filtroEstadoJuzgado');
const filtroEstadoSecretarioEl = document.getElementById('filtroEstadoSecretario');

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

function renderTable(data) {
  tbody.innerHTML = '';
  
  if (data.length === 0) {
    const tr = document.createElement('tr');
    const td = document.createElement('td');
    td.colSpan = userRole === 1 || userRole === 2 ? 9 : 8;
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
    
    // Preparar estado con nombre de entregador si aplica
    let estadoTexto = d.nombre_estado;
    if (est === 1) {
      const entregadorNombre = (d.usuario_entrega || '').trim();
      const entregadorDoc = (d.documento_entrega || '').trim();
      if (entregadorNombre) {
        estadoTexto = `ENTREGADO - ${entregadorNombre}`;
      } else if (entregadorDoc) {
        estadoTexto = `ENTREGADO - ${entregadorDoc}`;
      }
    }
    
    let html = `
      <td>${d.n_expediente || ''}</td>
      <td>${d.n_deposito || ''}</td>
      <td>${d.nombre_juzgado || ''}</td>
    `;
    
    if (userRole === 1 || userRole === 2) {
      html += `<td>${d.nombre_secretario || ''}</td>`;
    }
    
    html += `
      <td>${d.dni_beneficiario ? `${d.dni_beneficiario} – ${d.nombre_beneficiario}` : '<i>Sin beneficiario</i>'}</td>
      <td>${formatDate(d.fecha_notificacion_deposito)}</td>
      <td>${formatDate(d.fecha_atencion)}</td>
      <td>${estadoTexto}</td>
      <td>${est === 1 && d.fecha_finalizacion ? formatDate(d.fecha_finalizacion) : '--'}</td>
    `;
    
    tr.innerHTML = html;
    tbody.appendChild(tr);
  });
  
  // Para reportes especializados, aplicar filtros secundarios en frontend
  // Para reporte general, todos los filtros se manejan en backend
  if (esReporteJuzgado || esReporteSecretario) {
    aplicarFiltrosSecundariosFrontend();
  }
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

/* ================== Funciones para filtros especializados ================== */
function cargarJuzgadosPorTipo() {
  if (!filtroTipoJuzgadoEl || !filtroJuzgadoEspecificoEl) return;
  
  const tipoSeleccionado = filtroTipoJuzgadoEl.value;
  filtroJuzgadoEspecificoEl.innerHTML = '<option value="" disabled selected>Seleccione un juzgado</option>';
  
  if (!tipoSeleccionado) {
    filtroJuzgadoEspecificoEl.disabled = true;
    return;
  }
  
  const juzgadosFiltrados = juzgadosData.filter(j => j.tipo_juzgado === tipoSeleccionado);
  
  juzgadosFiltrados.forEach(juzgado => {
    const option = document.createElement('option');
    option.value = juzgado.id_juzgado;
    option.textContent = juzgado.nombre_juzgado;
    filtroJuzgadoEspecificoEl.appendChild(option);
  });
  
  filtroJuzgadoEspecificoEl.disabled = false;
}

/* ================== Carga de datos ================== */
async function loadDepositos() {
  if (isLoading) return;
  
  isLoading = true;
  showLoading();

  const params = new URLSearchParams({
    page: currentPage,
    limit: registrosPorPaginaEl.value,
    tipo_reporte: tipoReporte
  });

  // Agregar filtros según el tipo de reporte
  if (esReporteJuzgado) {
    // Para reportes por juzgado, usar filtro de estado específico
    params.append('filtroEstado', filtroEstadoJuzgadoEl?.value || 'entregados');
    if (filtroJuzgadoEspecificoEl?.value) {
      params.append('filtro_juzgado_id', filtroJuzgadoEspecificoEl.value);
    }
  } else if (esReporteSecretario) {
    // Para reportes por secretario, usar filtro de estado específico
    params.append('filtroEstado', filtroEstadoSecretarioEl?.value || 'entregados');
    if (filtroSecretarioEspecificoEl?.value) {
      params.append('filtro_secretario_doc', filtroSecretarioEspecificoEl.value);
    }
  } else if (esReporteUsuario) {
    // Para reportes por usuario, solo mostrar depósitos entregados (estado 1)
    params.append('filtroEstado', 'entregados');
    if (filtroUsuarioEspecificoEl?.value) {
      params.append('filtro_usuario_doc', filtroUsuarioEspecificoEl.value);
    }
  } else {
    // Filtros del reporte general - todos al backend
    params.append('filtroEstado', filtroEstadoEl?.value || 'entregados');
    params.append('filtroTipo', filtroTipoEl?.value || '');
    params.append('filtroTexto', filtroTextoEl?.value || '');
  }

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

    // No aplicar filtros secundarios en frontend para reporte general
    // ya que todos los filtros se manejan en backend
    
    // Actualizar gráficos si es necesario
    const filtroVerReporteEl = document.getElementById('filtroVerReporte');
    if (filtroVerReporteEl && filtroVerReporteEl.value !== 'Reporte') {
      mostrarVisualizacion(filtroVerReporteEl.value);
    }

  } catch (error) {
    console.error('Error cargando depósitos:', error);
    Swal.fire('Error', 'No se pudieron cargar los depósitos. Intenta de nuevo.', 'error');
    tbody.innerHTML = '<tr><td colspan="9" style="text-align: center; color: red;">Error cargando datos</td></tr>';
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

// Filtros secundarios - Solo para reportes especializados (no para el general)
function aplicarFiltrosSecundariosFrontend() {
  // El reporte general ahora maneja todos los filtros en backend
  // Solo los reportes especializados usan filtros secundarios en frontend
  if (!esReporteJuzgado && !esReporteSecretario && !esReporteUsuario) {
    return;
  }
  
  const tipo = filtroTipoEl?.value;
  const texto = filtroTextoEl?.value.toLowerCase() || '';

  let hasFilters = false;
  if (texto) {
    hasFilters = true;
  }

  // Si no hay filtros secundarios, mostrar todo
  if (!hasFilters) {
    document.querySelectorAll('#tabla-depositos tbody tr').forEach(tr => {
      tr.style.display = '';
    });
    updateVisibleRecordsCount();
    
    // Actualizar gráficos si es necesario (incluso cuando se borran filtros)
    const filtroVerReporteEl = document.getElementById('filtroVerReporte');
    if (filtroVerReporteEl && filtroVerReporteEl.value !== 'Reporte') {
      mostrarVisualizacion(filtroVerReporteEl.value);
    }
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

    tr.style.display = visible ? '' : 'none';
  });
  
  updateVisibleRecordsCount();
  
  // Actualizar gráficos si es necesario
  const filtroVerReporteEl = document.getElementById('filtroVerReporte');
  if (filtroVerReporteEl && filtroVerReporteEl.value !== 'Reporte') {
    mostrarVisualizacion(filtroVerReporteEl.value);
  }
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

// Event listeners para reporte general
if (filtroEstadoEl) {
  filtroEstadoEl.addEventListener('change', aplicarFiltros);
}

// Para reporte general: todos los filtros al backend
const debouncedAplicarFiltros = debounce(aplicarFiltros, 300);
if (filtroTipoEl) filtroTipoEl.addEventListener('change', aplicarFiltros);
if (filtroTextoEl) filtroTextoEl.addEventListener('input', debouncedAplicarFiltros);

// Event listeners para reporte por juzgado
if (filtroEstadoJuzgadoEl) {
  filtroEstadoJuzgadoEl.addEventListener('change', aplicarFiltros);
}

if (filtroTipoJuzgadoEl) {
  filtroTipoJuzgadoEl.addEventListener('change', () => {
    cargarJuzgadosPorTipo();
    if (filtroJuzgadoEspecificoEl.value) {
      aplicarFiltros();
    }
  });
}

if (filtroJuzgadoEspecificoEl) {
  filtroJuzgadoEspecificoEl.addEventListener('change', aplicarFiltros);
}

// Event listeners para reporte por secretario
if (filtroEstadoSecretarioEl) {
  filtroEstadoSecretarioEl.addEventListener('change', aplicarFiltros);
}

if (filtroSecretarioEspecificoEl) {
  filtroSecretarioEspecificoEl.addEventListener('change', aplicarFiltros);
}

// Event listeners para reporte por usuario
if (filtroUsuarioEspecificoEl) {
  filtroUsuarioEspecificoEl.addEventListener('change', aplicarFiltros);
}

// Cambio de tipo de visualización (Reporte / Usuario / Secretario)
const filtroVerReporteEl = document.getElementById('filtroVerReporte');
if (filtroVerReporteEl) {
  filtroVerReporteEl.addEventListener('change', (e) => {
    mostrarVisualizacion(e.target.value);
  });
}

// Cambio de registros por página: recargar desde servidor
registrosPorPaginaEl.addEventListener('change', aplicarFiltros);

// Inicialización según el tipo de reporte
document.addEventListener('DOMContentLoaded', () => {
  if (esReporteJuzgado) {
    // Configurar el estado por defecto para mostrar todos los estados
    // No cargar datos hasta que se seleccione un juzgado
    console.log('Reporte por juzgado cargado. Seleccione un juzgado para ver datos.');
  } else if (esReporteSecretario) {
    // No cargar datos hasta que se seleccione un secretario
    console.log('Reporte por secretario cargado. Seleccione un secretario para ver datos.');
  } else if (esReporteUsuario) {
    // No cargar datos hasta que se seleccione un usuario
    console.log('Reporte por usuario cargado. Seleccione un usuario para ver datos.');
  } else {
    // Cargar datos normalmente para reporte general
    loadDepositos();
  }
});

function exportarPDF() {
  const { jsPDF } = window.jspdf;
  const filtroVerReporteEl = document.getElementById('filtroVerReporte');
  const tipoReporte = filtroVerReporteEl ? filtroVerReporteEl.value : 'Reporte';

  // Delegamos a funciones específicas según el tipo
  if (tipoReporte === 'Usuario' && !esReporteUsuario) {
    exportarPDFGrafico(tipoReporte);
  } else if (tipoReporte === 'Secretario') {
    exportarPDFGrafico(tipoReporte);
  } else {
    // Reporte: tabla completa con gráficos y resumen (comportamiento original)
    exportarPDFReporteCompleto();
  }
}

// Función para obtener todos los datos filtrados para exportación (sin paginación)
async function obtenerDatosParaExportacion() {
  const params = new URLSearchParams({
    page: 1,
    limit: -1, // Obtener todos los registros
    tipo_reporte: tipoReporte
  });

  // Agregar filtros según el tipo de reporte
  if (esReporteJuzgado) {
    // Para reportes por juzgado, usar filtro de estado específico
    params.append('filtroEstado', filtroEstadoJuzgadoEl?.value || 'entregados');
    if (filtroJuzgadoEspecificoEl?.value) {
      params.append('filtro_juzgado_id', filtroJuzgadoEspecificoEl.value);
    }
  } else if (esReporteSecretario) {
    // Para reportes por secretario, usar filtro de estado específico
    params.append('filtroEstado', filtroEstadoSecretarioEl?.value || 'entregados');
    if (filtroSecretarioEspecificoEl?.value) {
      params.append('filtro_secretario_doc', filtroSecretarioEspecificoEl.value);
    }
  } else if (esReporteUsuario) {
    // Para reportes por usuario, solo mostrar depósitos entregados (estado 1)
    params.append('filtroEstado', 'entregados');
    if (filtroUsuarioEspecificoEl?.value) {
      params.append('filtro_usuario_doc', filtroUsuarioEspecificoEl.value);
    }
  } else {
    // Filtros del reporte general - todos al backend
    params.append('filtroEstado', filtroEstadoEl?.value || 'entregados');
    params.append('filtroTipo', filtroTipoEl?.value || '');
    params.append('filtroTexto', filtroTextoEl?.value || '');
  }

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

  // Todos los filtros ya se aplican en backend, sin filtros adicionales en frontend
  return data.data;
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

// Función para exportar PDF con gráfico y resumen (Usuario o Secretario)
async function exportarPDFGrafico(tipo) {
  const { jsPDF } = window.jspdf;
  
  // Mostrar loading
  Swal.fire({
    title: 'Preparando exportación...',
    text: 'Obteniendo datos para el gráfico',
    allowOutsideClick: false,
    didOpen: () => {
      Swal.showLoading();
    }
  });

  try {
    // Obtener todos los datos filtrados para exportación
    const exportData = await obtenerDatosParaExportacion();
    
    Swal.close();
    
    if (!exportData || exportData.length === 0) {
      Swal.fire('Información', 'No hay datos para exportar con los filtros aplicados.', 'info');
      return;
    }

    const doc = new jsPDF("p", "mm", "a4");

    // Obtener datos para el gráfico según el tipo desde los datos de exportación
    let datosGrafico = {};
    let titulo = '';

    if (tipo === 'Usuario') {
      datosGrafico = getEntregasPorUsuarioFromData(exportData);
      titulo = "Distribución de entregas por usuario";
    } else if (tipo === 'Secretario') {
      datosGrafico = getEntregasPorSecretarioFromData(exportData);
      titulo = "Distribución de entregas por secretario";
    }

  // Función para dibujar el gráfico en canvas (reutilizado de exportarPDFReporteCompleto)
  function wrapText(ctx, text, maxWidth) {
    const words = text.split(' ');
    const lines = [];
    let cur = '';
    for (let i = 0; i < words.length; i++) {
      const test = cur ? (cur + ' ' + words[i]) : words[i];
      const w = ctx.measureText(test).width;
      if (w > maxWidth && cur) {
        lines.push(cur);
        cur = words[i];
      } else {
        cur = test;
      }
    }
    if (cur) lines.push(cur);
    return lines;
  }

  function drawPieChartOnCanvas(countsObj, title) {
    const cw = 900, ch = 600;
    const canvas = document.createElement('canvas');
    canvas.width = cw; canvas.height = ch;
    const ctx = canvas.getContext('2d');

    // fondo + título
    ctx.fillStyle = "#ffffff";
    ctx.fillRect(0,0,cw,ch);
    ctx.fillStyle = "#840000";
    ctx.font = "22px Arial";
    ctx.textAlign = "center";
    ctx.fillText(title, cw/2, 36);

    const entries = Object.entries(countsObj).sort((a,b)=> b[1]-a[1]);
    if (entries.length === 0) {
      ctx.fillStyle = "#333";
      ctx.font = "16px Arial";
      ctx.textAlign = "center";
      ctx.fillText("No hay datos para graficar.", cw/2, ch/2);
      return canvas;
    }

    const total = entries.reduce((s,[,v]) => s + v, 0);
    let startAngle = -0.5 * Math.PI;
    const palette = ["#337ab7","#25D366","#ff8c00","#9b59b6","#e74c3c","#2ecc71","#f1c40f","#1abc9c","#34495e","#d35400"];

    const cx = cw * 0.28;
    const cy = ch * 0.48;
    const radius = Math.min(cw, ch) * 0.24;

    const legendStartX = cx + radius + 18;
    const legendMaxWidth = cw - legendStartX - 20;
    const legendTopY = 80;
    const legendLineHeight = 18;
    const availableLegendHeight = ch - legendTopY - 40;

    ctx.font = "14px Arial";
    const legendEntries = entries.map(([name,value], i) => {
      const pct = ((value/total)*100).toFixed(1);
      const text = `${name} — ${value} (${pct}%)`;
      const lines = wrapText(ctx, text, legendMaxWidth);
      return { name, value, pct, lines, color: palette[i % palette.length] };
    });

    const totalLines = legendEntries.reduce((s, e) => s + e.lines.length, 0);
    const totalLegendHeight = totalLines * legendLineHeight;

    let columns = totalLegendHeight > availableLegendHeight ? 2 : 1;
    if (legendMaxWidth < 140) columns = 0;

    // dibujar pastel
    entries.forEach(([name, value], i) => {
      const slice = (value/total) * Math.PI * 2;
      const color = palette[i % palette.length];
      const endAngle = startAngle + slice;
      ctx.beginPath();
      ctx.moveTo(cx, cy);
      ctx.arc(cx, cy, radius, startAngle, endAngle);
      ctx.closePath();
      ctx.fillStyle = color;
      ctx.fill();
      ctx.strokeStyle = "#ffffff";
      ctx.lineWidth = 1;
      ctx.stroke();
      startAngle = endAngle;
    });

    // dibujar leyenda
    ctx.font = "14px Arial";
    ctx.textAlign = "left";
    ctx.fillStyle = "#222";

    if (columns === 1) {
      let y = legendTopY;
      legendEntries.forEach(entry => {
        ctx.fillStyle = entry.color;
        ctx.fillRect(legendStartX, y - 12, 16, 12);
        ctx.fillStyle = "#222";
        entry.lines.forEach((ln, idx) => {
          ctx.fillText(ln, legendStartX + 22, y + (idx * legendLineHeight));
        });
        y += entry.lines.length * legendLineHeight;
        y += 6;
      });
    } else if (columns === 2) {
      const colGap = 20;
      const colWidth = Math.floor((legendMaxWidth - colGap) / 2);
      const colX = [legendStartX, legendStartX + colWidth + colGap];
      const colBlocks = [[], []];
      const colHeights = [0,0];
      legendEntries.forEach(entry => {
        const target = colHeights[0] <= colHeights[1] ? 0 : 1;
        colBlocks[target].push(entry);
        colHeights[target] += entry.lines.length * legendLineHeight + 6;
      });
      for (let c = 0; c < 2; c++) {
        let y = legendTopY;
        ctx.textAlign = "left";
        colBlocks[c].forEach(entry => {
          ctx.fillStyle = entry.color;
          ctx.fillRect(colX[c], y - 12, 16, 12);
          ctx.fillStyle = "#222";
          entry.lines.forEach((ln, idx) => {
            ctx.fillText(ln, colX[c] + 22, y + (idx * legendLineHeight));
          });
          y += entry.lines.length * legendLineHeight;
          y += 6;
        });
      }
    } else {
      const legendBelowX = 40;
      let y = cy + radius + 30;
      ctx.textAlign = "left";
      legendEntries.forEach(entry => {
        ctx.fillStyle = entry.color;
        ctx.fillRect(legendBelowX, y - 12, 16, 12);
        ctx.fillStyle = "#222";
        entry.lines.forEach((ln, idx) => {
          ctx.fillText(ln, legendBelowX + 22, y + (idx * legendLineHeight));
        });
        y += entry.lines.length * legendLineHeight;
        y += 6;
      });
    }

    ctx.fillStyle = "#555";
    ctx.font = "12px Arial";
    ctx.textAlign = "center";
    ctx.fillText(`Total entregas: ${total}`, cw/2, ch - 16);

    return canvas;
  }

  // Página 1: Gráfico
  doc.setFont("helvetica", "bold");
  doc.setFontSize(14);
  doc.setTextColor(132, 0, 0);
  doc.text(titulo, 14, 15);

  const canvas = drawPieChartOnCanvas(datosGrafico, titulo);
  try {
    const imgData = canvas.toDataURL("image/png");
    const imgWidthMM = 165;
    const imgHeightMM = (canvas.height / canvas.width) * imgWidthMM;
    doc.addImage(imgData, 'PNG', 8, 25, imgWidthMM, imgHeightMM);
  } catch (err) {
    doc.setFontSize(12);
    doc.setTextColor(0);
    doc.text("No se pudo generar el gráfico (error al renderizar canvas).", 14, 50);
  }

    // Guardar PDF
    const nombreArchivo = tipo === 'Usuario' ? 'reporte_entregas_por_usuario.pdf' : 'reporte_entregas_por_secretario.pdf';
    doc.save(nombreArchivo);
    
  } catch (error) {
    console.error('Error en exportación de gráfico:', error);
    Swal.close();
    Swal.fire('Error', 'Hubo un problema al generar el PDF. Intenta de nuevo.', 'error');
  }
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

    // --- 1) construir headers y body desde los datos obtenidos ---
    const tabla = document.getElementById("tabla-depositos");
    const headers = [];
    const body = [];

    tabla.querySelectorAll("thead th").forEach((th) => {
      headers.push(th.innerText.trim());
    });

    // Construir filas desde los datos de exportación
    exportData.forEach((deposito) => {
      const row = [];
      
      // Expediente
      row.push(deposito.n_expediente || '--');
      
      // Depósito
      row.push(deposito.n_deposito || '--');
      
      // Juzgado
      row.push(deposito.nombre_juzgado || '--');
      
      // Secretario (solo si está en headers)
      if (headers.some(h => h.toLowerCase().includes('secretario'))) {
        row.push(deposito.nombre_secretario || '--');
      }
      
      // Beneficiario
      const beneficiario = deposito.nombre_beneficiario || 'Sin beneficiario';
      row.push(beneficiario);
      
      // Envío (fecha_notificacion_deposito)
      const fechaEnvio = deposito.fecha_notificacion_deposito ? formatDate(deposito.fecha_notificacion_deposito) : '--';
      row.push(fechaEnvio);
      
      // Atención
      const fechaAtencion = deposito.fecha_atencion ? formatDate(deposito.fecha_atencion) : '--';
      row.push(fechaAtencion);
      
      // Estado
      let estadoTexto = deposito.nombre_estado || '--';
      if (deposito.id_estado == 1 && deposito.usuario_entrega) {
        estadoTexto = `ENTREGADO - ${deposito.usuario_entrega}`;
      }
      row.push(estadoTexto);
      
      // Finalización
      const fechaFinalizacion = deposito.fecha_finalizacion ? formatDate(deposito.fecha_finalizacion) : '--';
      row.push(fechaFinalizacion);
      
      body.push(row);
    });

  // Página 1: tabla
  doc.setFont("helvetica", "bold");
  doc.setFontSize(14);
  doc.setTextColor(132, 0, 0);
  doc.text("Reporte de Depósitos Judiciales", 14, 15);

  doc.autoTable({
    head: [headers],
    body: body,
    startY: 20,
    styles: {
      fontSize: 8,
      font: "helvetica",
      textColor: [0, 0, 0],
    },
    headStyles: {
      fillColor: [132, 0, 0],
      textColor: 255,
      fontSize: 9,
      fontStyle: "bold",
      halign: "center",
    },
    bodyStyles: {
      fillColor: [255, 255, 255],
      textColor: [33, 33, 33],
      halign: "left",
    },
    alternateRowStyles: {
      fillColor: [248, 225, 225],
    },
    tableLineColor: [92, 0, 0],
    tableLineWidth: 0.1,
  });

    // --- 2) calcular conteo de entregas por usuario y por secretario desde los datos de exportación ---
    const entregasCount = {};
    const entregasBySecretary = {};
    const headerTexts = headers.map(h => h.toLowerCase());
    // Verificar si los datos exportados contienen información de secretarios
    const tieneSecretario = exportData.length > 0 && exportData[0].hasOwnProperty('nombre_secretario');

    exportData.forEach(deposito => {
      // Contar entregas por usuario
      if (deposito.id_estado == 1 && deposito.usuario_entrega) {
        const nombreEntregador = deposito.usuario_entrega.trim();
        if (nombreEntregador.length) {
          entregasCount[nombreEntregador] = (entregasCount[nombreEntregador] || 0) + 1;
        }
      }
      
      // Contar entregas por secretario (solo si la columna existe)
      if (tieneSecretario && deposito.id_estado == 1) {
        const secretario = deposito.nombre_secretario || 'Sin secretario';
        entregasBySecretary[secretario] = (entregasBySecretary[secretario] || 0) + 1;
      }
    });

  // --- helper: envoltura de texto para canvas (retorna array de líneas) ---
  function wrapText(ctx, text, maxWidth) {
    const words = text.split(' ');
    const lines = [];
    let cur = '';
    for (let i = 0; i < words.length; i++) {
      const test = cur ? (cur + ' ' + words[i]) : words[i];
      const w = ctx.measureText(test).width;
      if (w > maxWidth && cur) {
        lines.push(cur);
        cur = words[i];
      } else {
        cur = test;
      }
    }
    if (cur) lines.push(cur);
    return lines;
  }

  // --- función de dibujo con leyenda que no se corta ---
  function drawPieChartOnCanvas(countsObj, title) {
    const cw = 900, ch = 600;
    const canvas = document.createElement('canvas');
    canvas.width = cw; canvas.height = ch;
    const ctx = canvas.getContext('2d');

    // fondo + título
    ctx.fillStyle = "#ffffff";
    ctx.fillRect(0,0,cw,ch);
    ctx.fillStyle = "#840000";
    ctx.font = "22px Arial";
    ctx.textAlign = "center";
    ctx.fillText(title, cw/2, 36);

    const entries = Object.entries(countsObj).sort((a,b)=> b[1]-a[1]);
    if (entries.length === 0) {
      ctx.fillStyle = "#333";
      ctx.font = "16px Arial";
      ctx.textAlign = "center";
      ctx.fillText("No hay datos para graficar.", cw/2, ch/2);
      return canvas;
    }

    const total = entries.reduce((s,[,v]) => s + v, 0);
    let startAngle = -0.5 * Math.PI;
    const palette = ["#337ab7","#25D366","#ff8c00","#9b59b6","#e74c3c","#2ecc71","#f1c40f","#1abc9c","#34495e","#d35400"];

    // parámetros del pastel (ajustables)
    const cx = cw * 0.28;
    const cy = ch * 0.48;
    const radius = Math.min(cw, ch) * 0.24;

    // área de leyenda
    const legendStartX = cx + radius + 18;
    const legendMaxWidth = cw - legendStartX - 20;
    const legendTopY = 80;
    const legendLineHeight = 18;
    const availableLegendHeight = ch - legendTopY - 40;

    ctx.font = "14px Arial";
    const legendEntries = entries.map(([name,value], i) => {
      const pct = ((value/total)*100).toFixed(1);
      const text = `${name} — ${value} (${pct}%)`;
      const lines = wrapText(ctx, text, legendMaxWidth);
      return { name, value, pct, lines, color: palette[i % palette.length] };
    });

    const totalLines = legendEntries.reduce((s, e) => s + e.lines.length, 0);
    const totalLegendHeight = totalLines * legendLineHeight;

    let columns = totalLegendHeight > availableLegendHeight ? 2 : 1;
    if (legendMaxWidth < 140) columns = 0;

    // dibujar pastel
    entries.forEach(([name, value], i) => {
      const slice = (value/total) * Math.PI * 2;
      const color = palette[i % palette.length];
      const endAngle = startAngle + slice;
      ctx.beginPath();
      ctx.moveTo(cx, cy);
      ctx.arc(cx, cy, radius, startAngle, endAngle);
      ctx.closePath();
      ctx.fillStyle = color;
      ctx.fill();
      ctx.strokeStyle = "#ffffff";
      ctx.lineWidth = 1;
      ctx.stroke();
      startAngle = endAngle;
    });

    // dibujar leyenda (1, 2 columnas o debajo)
    ctx.font = "14px Arial";
    ctx.textAlign = "left";
    ctx.fillStyle = "#222";

    if (columns === 1) {
      let y = legendTopY;
      legendEntries.forEach(entry => {
        ctx.fillStyle = entry.color;
        ctx.fillRect(legendStartX, y - 12, 16, 12);
        ctx.fillStyle = "#222";
        entry.lines.forEach((ln, idx) => {
          ctx.fillText(ln, legendStartX + 22, y + (idx * legendLineHeight));
        });
        y += entry.lines.length * legendLineHeight;
        y += 6;
      });
    } else if (columns === 2) {
      const colGap = 20;
      const colWidth = Math.floor((legendMaxWidth - colGap) / 2);
      const colX = [legendStartX, legendStartX + colWidth + colGap];
      const colBlocks = [[], []];
      const colHeights = [0,0];
      legendEntries.forEach(entry => {
        const target = colHeights[0] <= colHeights[1] ? 0 : 1;
        colBlocks[target].push(entry);
        colHeights[target] += entry.lines.length * legendLineHeight + 6;
      });
      for (let c = 0; c < 2; c++) {
        let y = legendTopY;
        ctx.textAlign = "left";
        colBlocks[c].forEach(entry => {
          ctx.fillStyle = entry.color;
          ctx.fillRect(colX[c], y - 12, 16, 12);
          ctx.fillStyle = "#222";
          entry.lines.forEach((ln, idx) => {
            ctx.fillText(ln, colX[c] + 22, y + (idx * legendLineHeight));
          });
          y += entry.lines.length * legendLineHeight;
          y += 6;
        });
      }
    } else {
      const legendBelowX = 40;
      let y = cy + radius + 30;
      ctx.textAlign = "left";
      legendEntries.forEach(entry => {
        ctx.fillStyle = entry.color;
        ctx.fillRect(legendBelowX, y - 12, 16, 12);
        ctx.fillStyle = "#222";
        entry.lines.forEach((ln, idx) => {
          ctx.fillText(ln, legendBelowX + 22, y + (idx * legendLineHeight));
        });
        y += entry.lines.length * legendLineHeight;
        y += 6;
      });
    }

    ctx.fillStyle = "#555";
    ctx.font = "12px Arial";
    ctx.textAlign = "center";
    ctx.fillText(`Total entregas: ${total}`, cw/2, ch - 16);

    return canvas;
  }

  // --- 3) página con gráfico de entregas por usuario ---
  doc.addPage();
  const canvas1 = drawPieChartOnCanvas(entregasCount, "Distribución de entregas por usuario");
  try {
    const imgData = canvas1.toDataURL("image/png");
    const imgWidthMM = 165;
    const imgHeightMM = (canvas1.height / canvas1.width) * imgWidthMM;
    doc.addImage(imgData, 'PNG', 8, 18, imgWidthMM, imgHeightMM);
  } catch (err) {
    doc.setFontSize(12);
    doc.setTextColor(0);
    doc.text("No se pudo generar el gráfico (error al renderizar canvas).", 14, 30);
  }

    // --- 4) página con gráfico de entregas por secretario ---
    doc.addPage();
    if (!tieneSecretario) {
      doc.setFontSize(12);
      doc.setTextColor(0);
      doc.text("No existe columna 'Secretario' en este reporte. No se puede generar gráfico por secretario.", 14, 20);
  } else {
    const canvas2 = drawPieChartOnCanvas(entregasBySecretary, "Distribución de entregas por secretario");
    try {
      const imgData2 = canvas2.toDataURL("image/png");
      const imgWidthMM2 = 165;
      const imgHeightMM2 = (canvas2.height / canvas2.width) * imgWidthMM2;
      doc.addImage(imgData2, 'PNG', 8, 18, imgWidthMM2, imgHeightMM2);
    } catch (err) {
      doc.setFontSize(12);
      doc.setTextColor(0);
      doc.text("No se pudo generar el gráfico por secretario (error al renderizar canvas).", 14, 30);
    }
  }

    // --- 5) página resumen: conteo por ESTADO desde los datos de exportación ---
    const estadoCounts = {};
    
    exportData.forEach(deposito => {
      let estadoText = deposito.nombre_estado || 'SIN ESTADO';
      
      // Si es ENTREGADO, normalizar solo a "ENTREGADO"
      if (deposito.id_estado == 1) {
        estadoText = 'ENTREGADO';
      }
      
      // Normalizar a mayúsculas para el conteo
      estadoText = estadoText.toUpperCase();
      estadoCounts[estadoText] = (estadoCounts[estadoText] || 0) + 1;
    });

    // Convertir a array ordenada
    const estadoRows = Object.entries(estadoCounts).sort((a,b) => b[1]-a[1]).map(([k,v]) => [k, String(v)]);
    // Total filas contadas
    const totalFilas = estadoRows.reduce((s,r) => s + parseInt(r[1],10), 0);

    // Añadir página con tabla resumen
    doc.addPage();
    doc.setFont("helvetica", "bold");
    doc.setFontSize(13);
    doc.text("Resumen: Cantidad por Estado (datos exportados)", 14, 18);
    doc.setFont("helvetica", "normal");
    doc.setFontSize(11);
    doc.text(`Total registros: ${totalFilas}`, 14, 26);

    // Si no hay datos, mostrar mensaje
    if (estadoRows.length === 0) {
      doc.setFont("helvetica", "normal");
      doc.setFontSize(12);
      doc.setTextColor(0);
      doc.text("No hay estados para mostrar en el resumen.", 14, 40);
    } else {
      // Tabla con estado | cantidad
      doc.autoTable({
        head: [['Estado','Cantidad']],
        body: estadoRows,
        startY: 34,
        styles: { fontSize: 10, halign: 'left' },
        headStyles: { fillColor: [132,0,0], textColor: 255 }
      });
    }

    // Guardar PDF
    doc.save("reporte_depositos_judiciales.pdf");
    
  } catch (error) {
    console.error('Error en exportación:', error);
    Swal.close();
    Swal.fire('Error', 'Hubo un problema al generar el PDF. Intenta de nuevo.', 'error');
  }
}

// ================= FUNCIONES PARA GRÁFICOS INTERACTIVOS =================

// Helper para envolver texto en canvas
function wrapTextOnCanvas(ctx, text, maxWidth) {
  const words = text.split(' ');
  const lines = [];
  let cur = '';
  for (let i = 0; i < words.length; i++) {
    const test = cur ? (cur + ' ' + words[i]) : words[i];
    const w = ctx.measureText(test).width;
    if (w > maxWidth && cur) {
      lines.push(cur);
      cur = words[i];
    } else {
      cur = test;
    }
  }
  if (cur) lines.push(cur);
  return lines;
}

// Función para dibujar gráfico de pastel con leyenda
function drawPieChartOnCanvasInteractive(countsObj, title) {
  const cw = 1200, ch = 700;
  const canvas = document.createElement('canvas');
  canvas.width = cw;
  canvas.height = ch;
  const ctx = canvas.getContext('2d');

  // Fondo blanco
  ctx.fillStyle = "#ffffff";
  ctx.fillRect(0, 0, cw, ch);

  // Título
  ctx.fillStyle = "#840000";
  ctx.font = "24px Arial";
  ctx.textAlign = "center";
  ctx.fillText(title, cw / 2, 40);

  const entries = Object.entries(countsObj).sort((a, b) => b[1] - a[1]);
  
  if (entries.length === 0) {
    ctx.fillStyle = "#333";
    ctx.font = "18px Arial";
    ctx.textAlign = "center";
    ctx.fillText("No hay datos para graficar.", cw / 2, ch / 2);
    return canvas;
  }

  const total = entries.reduce((s, [, v]) => s + v, 0);
  let startAngle = -0.5 * Math.PI;
  const palette = ["#337ab7", "#25D366", "#ff8c00", "#9b59b6", "#e74c3c", "#2ecc71", "#f1c40f", "#1abc9c", "#34495e", "#d35400", "#c0392b", "#16a085"];

  // Parámetros del pastel
  const cx = cw * 0.28;
  const cy = ch * 0.48;
  const radius = Math.min(cw, ch) * 0.22;

  // Área de leyenda
  const legendStartX = cx + radius + 40;
  const legendMaxWidth = cw - legendStartX - 40;
  const legendTopY = 80;
  const legendLineHeight = 18;
  const availableLegendHeight = ch - legendTopY - 40;

  // Preparar entradas de leyenda
  ctx.font = "13px Arial";
  const legendEntries = entries.map(([name, value], i) => {
    const pct = ((value / total) * 100).toFixed(1);
    const text = `${name} — ${value} (${pct}%)`;
    const lines = wrapTextOnCanvas(ctx, text, legendMaxWidth);
    return { name, value, pct, lines, color: palette[i % palette.length] };
  });

  const totalLines = legendEntries.reduce((s, e) => s + e.lines.length, 0);
  const totalLegendHeight = totalLines * legendLineHeight;
  let columns = totalLegendHeight > availableLegendHeight ? 2 : 1;
  if (legendMaxWidth < 140) columns = 0;

  // Dibujar pastel
  entries.forEach(([name, value], i) => {
    const slice = (value / total) * Math.PI * 2;
    const color = palette[i % palette.length];
    const endAngle = startAngle + slice;
    ctx.beginPath();
    ctx.moveTo(cx, cy);
    ctx.arc(cx, cy, radius, startAngle, endAngle);
    ctx.closePath();
    ctx.fillStyle = color;
    ctx.fill();
    ctx.strokeStyle = "#ffffff";
    ctx.lineWidth = 2;
    ctx.stroke();
    startAngle = endAngle;
  });

  // Dibujar leyenda
  ctx.font = "13px Arial";
  ctx.textAlign = "left";
  ctx.fillStyle = "#222";

  if (columns === 1) {
    let y = legendTopY;
    legendEntries.forEach(entry => {
      ctx.fillStyle = entry.color;
      ctx.fillRect(legendStartX, y - 12, 14, 14);
      ctx.fillStyle = "#222";
      entry.lines.forEach((ln, idx) => {
        ctx.fillText(ln, legendStartX + 24, y + (idx * legendLineHeight));
      });
      y += entry.lines.length * legendLineHeight;
      y += 4;
    });
  } else if (columns === 2) {
    const colGap = 30;
    const colWidth = Math.floor((legendMaxWidth - colGap) / 2);
    const colX = [legendStartX, legendStartX + colWidth + colGap];
    const colBlocks = [[], []];
    const colHeights = [0, 0];
    legendEntries.forEach(entry => {
      const target = colHeights[0] <= colHeights[1] ? 0 : 1;
      colBlocks[target].push(entry);
      colHeights[target] += entry.lines.length * legendLineHeight + 4;
    });
    for (let c = 0; c < 2; c++) {
      let y = legendTopY;
      ctx.textAlign = "left";
      colBlocks[c].forEach(entry => {
        ctx.fillStyle = entry.color;
        ctx.fillRect(colX[c], y - 12, 14, 14);
        ctx.fillStyle = "#222";
        entry.lines.forEach((ln, idx) => {
          ctx.fillText(ln, colX[c] + 24, y + (idx * legendLineHeight));
        });
        y += entry.lines.length * legendLineHeight;
        y += 4;
      });
    }
  } else {
    // Leyenda debajo
    const legendBelowX = 40;
    let y = cy + radius + 40;
    ctx.textAlign = "left";
    legendEntries.forEach(entry => {
      ctx.fillStyle = entry.color;
      ctx.fillRect(legendBelowX, y - 12, 14, 14);
      ctx.fillStyle = "#222";
      entry.lines.forEach((ln, idx) => {
        ctx.fillText(ln, legendBelowX + 24, y + (idx * legendLineHeight));
      });
      y += entry.lines.length * legendLineHeight;
      y += 4;
    });
  }

  // Total al pie
  ctx.fillStyle = "#555";
  ctx.font = "13px Arial";
  ctx.textAlign = "center";
  ctx.fillText(`Total: ${total}`, cw / 2, ch - 20);

  return canvas;
}

// Función para obtener datos de entregas por usuario desde la tabla visible
function getEntregasPorUsuario() {
  const tabla = document.getElementById("tabla-depositos");
  const headerTexts = Array.from(tabla.querySelectorAll("thead th")).map(h => h.innerText.trim().toLowerCase());
  const idxEstado = headerTexts.indexOf("estado");
  const entregasCount = {};

  if (idxEstado === -1) return entregasCount;

  tabla.querySelectorAll("tbody tr").forEach(tr => {
    if (tr.style.display === "none") return;
    const tds = tr.querySelectorAll("td");
    if (!tds || tds.length <= idxEstado) return;

    const estadoText = tds[idxEstado].innerText.trim();
    const m = estadoText.match(/^ENTREGADO\s*-\s*(.+)$/i);
    if (m && m[1]) {
      const nombreEntregador = m[1].trim();
      if (nombreEntregador.length) {
        entregasCount[nombreEntregador] = (entregasCount[nombreEntregador] || 0) + 1;
      }
    }
  });

  return entregasCount;
}

// Función para obtener datos de entregas por secretario desde la tabla visible
function getEntregasPorSecretario() {
  const tabla = document.getElementById("tabla-depositos");
  const headerTexts = Array.from(tabla.querySelectorAll("thead th")).map(h => h.innerText.trim().toLowerCase());
  const idxEstado = headerTexts.indexOf("estado");
  const idxSecretario = headerTexts.indexOf("secretario");
  const entregasBySecretary = {};

  if (idxEstado === -1 || idxSecretario === -1) return entregasBySecretary;

  tabla.querySelectorAll("tbody tr").forEach(tr => {
    if (tr.style.display === "none") return;
    const tds = tr.querySelectorAll("td");
    if (!tds || tds.length <= idxEstado || tds.length <= idxSecretario) return;

    const estadoText = tds[idxEstado].innerText.trim();
    const m = estadoText.match(/^ENTREGADO\s*-\s*(.+)$/i);
    if (m && m[1]) {
      let sec = tds[idxSecretario].innerText.trim();
      if (!sec) sec = 'Sin secretario';
      entregasBySecretary[sec] = (entregasBySecretary[sec] || 0) + 1;
    }
  });

  return entregasBySecretary;
}

// Función para mostrar/ocultar tabla y gráficos
function mostrarVisualizacion(tipo) {
  const tabla = document.getElementById("tabla-depositos");
  const pageInfoTop = document.getElementById("pageInfoTop");
  const pageInfoBottom = document.getElementById("pageInfoBottom");
  const paginationTop = document.getElementById("paginationTop");
  const paginationBottom = document.getElementById("paginationBottom");
  const graficosContainer = document.getElementById("graficos-container");
  const registrosContainer = document.querySelector('div > label:has(+ #registrosPorPagina)');
  const registrosPaginaParent = document.getElementById('registrosPorPagina')?.parentElement;

  if (tipo === "Reporte") {
    // Mostrar tabla
    tabla.classList.remove('oculto');
    if (pageInfoTop) pageInfoTop.classList.remove('oculto');
    if (pageInfoBottom) pageInfoBottom.classList.remove('oculto');
    if (paginationTop) paginationTop.classList.remove('oculto');
    if (paginationBottom) paginationBottom.classList.remove('oculto');
    if (registrosPaginaParent) registrosPaginaParent.classList.remove('oculto');
    graficosContainer.classList.add('oculto');
  } else if (tipo === "Usuario" && !esReporteUsuario) {
    // Mostrar gráfico de usuarios (solo para reportes que no sean por usuario)
    tabla.classList.add('oculto');
    // Ocultar elementos de paginación para distribuciones
    if (pageInfoTop) pageInfoTop.classList.add('oculto');
    if (pageInfoBottom) pageInfoBottom.classList.add('oculto');
    if (paginationTop) paginationTop.classList.add('oculto');
    if (paginationBottom) paginationBottom.classList.add('oculto');
    if (registrosPaginaParent) registrosPaginaParent.classList.add('oculto');
    graficosContainer.classList.remove('oculto');

    // Obtener todos los datos para la distribución
    mostrarDistribucionUsuario();
  } else if (tipo === "Secretario") {
    // Mostrar gráfico de secretarios
    tabla.classList.add('oculto');
    // Ocultar elementos de paginación para distribuciones
    if (pageInfoTop) pageInfoTop.classList.add('oculto');
    if (pageInfoBottom) pageInfoBottom.classList.add('oculto');
    if (paginationTop) paginationTop.classList.add('oculto');
    if (paginationBottom) paginationBottom.classList.add('oculto');
    if (registrosPaginaParent) registrosPaginaParent.classList.add('oculto');
    graficosContainer.classList.remove('oculto');

    // Obtener todos los datos para la distribución
    mostrarDistribucionSecretario();
  }
}

// Funciones para mostrar distribuciones con todos los datos
async function mostrarDistribucionUsuario() {
  try {
    // Obtener todos los datos sin limitación de paginación
    const todosLosDatos = await obtenerDatosParaExportacion();
    
    if (!todosLosDatos || todosLosDatos.length === 0) {
      const chartCanvas = document.getElementById("chartCanvas");
      const ctx = chartCanvas.getContext("2d");
      ctx.clearRect(0, 0, chartCanvas.width, chartCanvas.height);
      ctx.font = "16px Arial";
      ctx.fillStyle = "#666";
      ctx.textAlign = "center";
      ctx.fillText("No hay datos para mostrar", chartCanvas.width / 2, chartCanvas.height / 2);
      return;
    }
    
    // Obtener distribución por usuario desde todos los datos
    const datosUsuario = getEntregasPorUsuarioFromData(todosLosDatos);
    const canvas = drawPieChartOnCanvasInteractive(datosUsuario, "Distribución de entregas por usuario");
    const chartCanvas = document.getElementById("chartCanvas");
    
    // Limpiar el canvas antes de dibujar
    const ctx = chartCanvas.getContext("2d");
    ctx.clearRect(0, 0, chartCanvas.width, chartCanvas.height);
    
    // Actualizar dimensiones y dibujar
    chartCanvas.width = canvas.width;
    chartCanvas.height = canvas.height;
    const ctxNew = chartCanvas.getContext("2d");
    ctxNew.drawImage(canvas, 0, 0);
    
  } catch (error) {
    console.error('Error obteniendo datos para distribución por usuario:', error);
    Swal.fire('Error', 'No se pudieron obtener los datos para la distribución por usuario.', 'error');
  }
}

async function mostrarDistribucionSecretario() {
  try {
    // Obtener todos los datos sin limitación de paginación
    const todosLosDatos = await obtenerDatosParaExportacion();
    
    if (!todosLosDatos || todosLosDatos.length === 0) {
      const chartCanvas = document.getElementById("chartCanvas");
      const ctx = chartCanvas.getContext("2d");
      ctx.clearRect(0, 0, chartCanvas.width, chartCanvas.height);
      ctx.font = "16px Arial";
      ctx.fillStyle = "#666";
      ctx.textAlign = "center";
      ctx.fillText("No hay datos para mostrar", chartCanvas.width / 2, chartCanvas.height / 2);
      return;
    }
    
    // Obtener distribución por secretario desde todos los datos
    const datosSecretario = getEntregasPorSecretarioFromData(todosLosDatos);
    const canvas = drawPieChartOnCanvasInteractive(datosSecretario, "Distribución de entregas por secretario");
    const chartCanvas = document.getElementById("chartCanvas");
    
    // Limpiar el canvas antes de dibujar
    const ctx = chartCanvas.getContext("2d");
    ctx.clearRect(0, 0, chartCanvas.width, chartCanvas.height);
    
    // Actualizar dimensiones y dibujar
    chartCanvas.width = canvas.width;
    chartCanvas.height = canvas.height;
    const ctxNew = chartCanvas.getContext("2d");
    ctxNew.drawImage(canvas, 0, 0);
    
  } catch (error) {
    console.error('Error obteniendo datos para distribución por secretario:', error);
    Swal.fire('Error', 'No se pudieron obtener los datos para la distribución por secretario.', 'error');
  }
}

</script>

</body>
</html>