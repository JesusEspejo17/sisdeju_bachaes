<?php
// lista_beneficiarios_paginado.php
// Lista de beneficiarios con paginación y filtro en tiempo real
// Requiere: ../code_back/conexion.php, SweetAlert2 en ../js/sweetalert2.all.min.js

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Permisos: solo roles 1,2,6 pueden ver
if (!isset($_SESSION['rol']) || !in_array($_SESSION['rol'], [1,2,6])) {
    if (!headers_sent()) {
        header("Location: ../code_front/menu_admin.php");
    } else {
        echo "<script>
            alert('Acceso denegado. Solo administradores pueden ver esta página.');
            window.location='../code_front/menu_admin.php';
        </script>";
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Lista de Beneficiarios</title>
  <link rel="stylesheet" href="../css/crear_usuario.css">
  <script src="../js/sweetalert2.all.min.js"></script>
  <style>
    /* Paleta centralizada (ajústala aquí si querés cambiar colores) */
    :root{
      --primary: #337ab7;    /* azul */
      --success: #25D366;    /* verde (whatsapp) */
      --danger:  #d9534f;    /* rojo */
      --secondary: #6c757d;  /* gris */
      --accent: #840000;     /* rojo oscuro */
      --alt-row: #f8e1e1;    /* fila alterna clara */
      --header-bg: #840000;  /* header fondo */
      --text: #000000ff;
      --text2:rgba(255, 255, 255, 1)f;
      --muted: #555;
      --table-border: #e6e6e6;
    }

    .main-container { max-width: 1300px; margin: 18px auto; padding: 10px; color:var(--text); }
    h1 { margin-top: 0; color:var(--accent); }

    /* filtros */
    .filters { display:flex; gap:8px; align-items:center; margin-bottom:12px; flex-wrap:wrap; }
    .filters select, .filters input {
      padding:6px 8px; border-radius:6px; border:1px solid var(--table-border);
      background: #fff; color:var(--text);
    }
    .filters button {
      padding:6px 10px; border-radius:6px; color:#fff; border:none; cursor:pointer;
      box-shadow: 0 1px 3px rgba(0,0,0,0.08);
    }
    .filters button#limpiarBtn { background: var(--secondary); }
    .filters button#exportCsvBtn { background: var(--primary); margin-left:6px; }

    /* tabla */
    table { width:100%; border-collapse:collapse; margin-top:6px; }
    th, td { padding:8px; border:1px solid var(--table-border); text-align:center; vertical-align:middle; }
    thead th { background:var(--header-bg); color:var(--text2); font-weight:600; }
    tbody tr:nth-child(even) td { background: #fff; }
    tbody tr:nth-child(odd) td  { background: #fff; } /* keep white by default */
    tbody tr.highlight td { background: var(--alt-row); }

    /* icons */
    img.icon { width:24px; height:24px; vertical-align:middle; filter: none; }

    /* modal */
    .modal {
      position: fixed;
      z-index: 9999;
      left: 0; top: 0;
      width: 100%; height: 100%;
      background-color: rgba(0,0,0,0.45);
      display: none; justify-content: center; align-items: center;
    }
    .modal-contenido {
      background-color: #fff;
      padding: 20px;
      border-radius: 8px;
      width: 80%;
      max-height: 85%;
      overflow-y: auto;
      position: relative;
      box-shadow: 0 6px 24px rgba(0,0,0,0.15);
      border-top: 5px solid var(--primary);
    }
    .cerrar {
      position: absolute;
      top: 10px; right: 20px;
      font-size: 28px; font-weight: bold; cursor: pointer; color:var(--muted);
    }

    /* responsive */
    @media (max-width:800px) {
      .modal-contenido { width: 95%; }
      .filters { flex-direction: column; align-items:flex-start; gap:6px; }
    }

    /* estilos accesibles de botones pequeños dentro de la tabla */
    a.icon-btn { display:inline-flex; align-items:center; justify-content:center; width:36px; height:36px; border-radius:6px; text-decoration:none; }
    a.icon-btn:hover { background: rgba(51,122,183,0.06); }

    /* Paginación - Estilo igual a listado_depositos */
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
    
    /* Loading spinner */
    .loading {
      display: none;
      text-align: center;
      padding: 20px;
      color: var(--muted);
    }
    
    .loading.show {
      display: block;
    }
    
    .spinner {
      border: 3px solid #f3f3f3;
      border-top: 3px solid var(--primary);
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
  </style>
</head>
<body>

<div class="main-container">

  <h1>Beneficiarios Registrados</h1>

  <!-- FILTROS (live) -->
  <form id="form-filtros" class="filters" onsubmit="return false;" aria-label="Filtros de beneficiarios">
    <label><strong>Buscar por:</strong></label>
    <select name="filtroTipo" id="filtroTipo" aria-label="Tipo de filtro">
      <option value="dni">DNI</option>
      <option value="nombre">Nombre</option>
    </select>

    <input
      type="text"
      name="filtroTexto"
      id="filtroTexto"
      placeholder="Ingresa DNI o parte del nombre..."
      autocomplete="off"
      style="min-width:260px;"
      aria-label="Texto de búsqueda"
    >

    <button type="button" id="limpiarBtn">Limpiar</button>
    <!--
    <button type="button" id="exportCsvBtn" title="Exportar visibles a CSV">Exportar CSV</button>
    -->
  </form>

  <!-- Loading spinner -->
  <div id="loading" class="loading">
    <div class="spinner"></div>
    <div>Cargando beneficiarios...</div>
  </div>

  <!-- BARRA DE PAGINACIÓN SUPERIOR -->
  <div style="display:flex; align-items:center; gap:10px; margin-bottom:6px;">
    <div id="pageInfoTop" class="page-info" style="display:none;">
      Mostrando <strong id="showFromTop">0</strong> – <strong id="showToTop">0</strong> de <strong id="totalRowsTop">0</strong>
    </div>
    <div id="paginationTop" class="pagination" aria-label="Paginación" style="display:none;"></div>
  </div>

  <table id="tabla-beneficiarios" border="1" cellpadding="10" cellspacing="0" style="text-align: center;">
    <thead>
      <tr>
        <th>Tipo Doc</th>
        <th>Documento</th>
        <th>Nombres</th>
        <th>Apellidos</th>
        <th>Teléfono</th>
        <th colspan="3">Opciones</th>
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

<!-- Modal -->
<div id="miModal" class="modal" role="dialog" aria-modal="true" aria-hidden="true">
  <div class="modal-contenido" role="document">
    <span class="cerrar" onclick="cerrarModal()" aria-label="Cerrar">&times;</span>
    <div id="contenidoModal"></div>
  </div>
</div>

<script>
/* ================== Variables globales ================== */
let currentPage = 1;
let totalPages = 1;
let totalRecords = 0;
let isLoading = false;

const filtroTipoEl = document.getElementById('filtroTipo');
const filtroTextoEl = document.getElementById('filtroTexto');
const limpiarBtn = document.getElementById('limpiarBtn');
const tabla = document.getElementById('tabla-beneficiarios');
const tbody = document.getElementById('tabla-body');
const loadingEl = document.getElementById('loading');
const paginationTop = document.getElementById('paginationTop');
const paginationBottom = document.getElementById('paginationBottom');
const pageInfoTop = document.getElementById('pageInfoTop');
const pageInfoBottom = document.getElementById('pageInfoBottom');

/* ================== Funciones básicas (abrir modal, eliminar) ================== */
function confirmarEliminacion(documento) {
  Swal.fire({
    title: '¿Estás seguro?',
    text: "Esta acción eliminará al beneficiario.",
    icon: 'warning',
    showCancelButton: true,
    confirmButtonText: 'Sí, eliminar',
    cancelButtonText: 'Cancelar'
  }).then((result) => {
    if (result.isConfirmed) {
      // redirigir al backend que elimina
      window.location.href = `../code_back/back_beneficiario_eliminar.php?documento=${encodeURIComponent(documento)}`;
    }
  });
}

function abrirModal(ruta) {
  fetch(ruta, { credentials: 'same-origin' })
    .then(res => {
      if (!res.ok) throw new Error('Error cargando contenido');
      return res.text();
    })
    .then(html => {
      document.getElementById('contenidoModal').innerHTML = html;
      const modal = document.getElementById('miModal');
      modal.style.display = 'flex';
      modal.setAttribute('aria-hidden', 'false');
      // focus management
      const focusable = modal.querySelector('button, [href], input, select, textarea') || modal.querySelector('.cerrar');
      if (focusable) focusable.focus();
    })
    .catch(err => {
      console.error(err);
      Swal.fire('Error', 'No se pudo cargar el contenido.', 'error');
    });
}

function cerrarModal() {
  const modal = document.getElementById('miModal');
  modal.style.display = 'none';
  modal.setAttribute('aria-hidden', 'true');
  document.getElementById('contenidoModal').innerHTML = '';
}

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

function renderTable(data) {
  tbody.innerHTML = '';
  
  if (data.length === 0) {
    const tr = document.createElement('tr');
    const td = document.createElement('td');
    td.colSpan = 6;
    td.style.textAlign = 'center';
    td.style.fontStyle = 'italic';
    td.textContent = 'No se encontraron beneficiarios con esos criterios.';
    tr.appendChild(td);
    tbody.appendChild(tr);
    return;
  }

  data.forEach(beneficiario => {
    const tr = document.createElement('tr');
    tr.innerHTML = `
      <td>${beneficiario.tipo_documento}</td>
      <td>${beneficiario.documento}</td>
      <td>${beneficiario.nombre_persona}</td>
      <td>${beneficiario.apellido_persona}</td>
      <td>${beneficiario.telefono_persona}</td>
      <td>
        <a href="#" class="icon-btn" title="Editar" onclick="abrirModal('beneficiario_editar.php?documento=${encodeURIComponent(beneficiario.documento)}')">
          <img src="../img/editar1.png" alt="Editar" class="icon">
        </a>
      </td>
      ${<?= isset($_SESSION['rol']) && $_SESSION['rol'] == 1 ? 'true' : 'false' ?> ? `
        <td>
          <a href="#" class="icon-btn" title="Eliminar" onclick="confirmarEliminacion('${beneficiario.documento.replace(/'/g, "\\'")}')">
            <img src="../img/delete1.png" alt="Eliminar" class="icon">
          </a>
        </td>
      ` : '<td></td>'}
      <td>
        <a href="#" class="icon-btn" title="Ver más" onclick="abrirModal('beneficiario_ver.php?documento=${encodeURIComponent(beneficiario.documento)}')">
          <img src="../img/eye1.png" alt="Ver más" class="icon">
        </a>
      </td>
    `;
    tbody.appendChild(tr);
  });
}

function renderPagination(pagination) {
  if (pagination.total_pages <= 1) {
    paginationTop.style.display = 'none';
    paginationBottom.style.display = 'none';
    pageInfoTop.style.display = 'none';
    pageInfoBottom.style.display = 'none';
    return;
  }

  // Mostrar elementos de paginación
  paginationTop.style.display = 'flex';
  paginationBottom.style.display = 'flex';
  pageInfoTop.style.display = 'block';
  pageInfoBottom.style.display = 'block';
  
  // Información de paginación
  const start = ((pagination.current_page - 1) * pagination.limit) + 1;
  const end = Math.min(pagination.current_page * pagination.limit, pagination.total_records);
  
  // Actualizar información en ambas barras
  document.getElementById('showFromTop').textContent = start;
  document.getElementById('showToTop').textContent = end;
  document.getElementById('totalRowsTop').textContent = pagination.total_records;
  document.getElementById('showFromBottom').textContent = start;
  document.getElementById('showToBottom').textContent = end;
  document.getElementById('totalRowsBottom').textContent = pagination.total_records;

  // Función para crear enlaces de paginación (igual que en listado_depositos)
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
  loadBeneficiarios();
}

/* ================== Carga de datos ================== */
async function loadBeneficiarios() {
  if (isLoading) return;
  
  isLoading = true;
  showLoading();

  const params = new URLSearchParams({
    page: currentPage,
    limit: 20,
    filtroTipo: filtroTipoEl.value,
    filtroTexto: filtroTextoEl.value
  });

  try {
    const response = await fetch(`../api/get_beneficiarios_paginados.php?${params}`, {
      credentials: 'same-origin'
    });

    if (!response.ok) {
      throw new Error('Error en la respuesta del servidor');
    }

    const data = await response.json();

    if (!data.success) {
      throw new Error(data.message || 'Error desconocido');
    }

    renderTable(data.data);
    renderPagination(data.pagination);
    
    totalPages = data.pagination.total_pages;
    totalRecords = data.pagination.total_records;

  } catch (error) {
    console.error('Error cargando beneficiarios:', error);
    Swal.fire('Error', 'No se pudieron cargar los beneficiarios. Intenta de nuevo.', 'error');
    tbody.innerHTML = '<tr><td colspan="6" style="text-align: center; color: red;">Error cargando datos</td></tr>';
  } finally {
    isLoading = false;
    hideLoading();
  }
}

/* ================== Filtros en tiempo real ================== */
// debounce helper
function debounce(fn, ms = 300) {
  let t;
  return (...args) => {
    clearTimeout(t);
    t = setTimeout(() => fn.apply(this, args), ms);
  };
}

function aplicarFiltros() {
  currentPage = 1; // Reset a la primera página
  loadBeneficiarios();
}

// Event listeners
const debouncedAplicar = debounce(aplicarFiltros, 300);
filtroTextoEl.addEventListener('input', debouncedAplicar);
filtroTipoEl.addEventListener('change', aplicarFiltros);

limpiarBtn.addEventListener('click', () => {
  filtroTextoEl.value = '';
  filtroTipoEl.value = 'dni';
  currentPage = 1;
  loadBeneficiarios();
  filtroTextoEl.focus();
});

filtroTextoEl.addEventListener('keydown', function(e) {
  if (e.key === 'Enter') {
    e.preventDefault();
    aplicarFiltros();
  }
});

// Cargar datos iniciales
document.addEventListener('DOMContentLoaded', loadBeneficiarios);
</script>

<!-- Mostrar alert de sesión si aplica -->
<?php if (isset($_SESSION['swal'])): ?>
<script>
  Swal.fire({
    title: <?= json_encode($_SESSION['swal']['title']) ?>,
    text: <?= json_encode($_SESSION['swal']['text']) ?>,
    icon: <?= json_encode($_SESSION['swal']['icon']) ?>,
    confirmButtonText: 'OK'
  });
</script>
<?php unset($_SESSION['swal']); endif; ?>

</body>
</html>