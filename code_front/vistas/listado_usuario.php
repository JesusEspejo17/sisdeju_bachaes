<?php
// lista_usuarios_paginado.php
// Lista de usuarios con paginación y filtro en tiempo real

if (session_status() === PHP_SESSION_NONE) session_start();
include("../code_back/conexion.php");

// Roles permitidos: Admin (1) y Rol 6
if (!isset($_SESSION['rol']) || !in_array($_SESSION['rol'], [1,6])) {
    if (!headers_sent()) {
        header("Location: ../code_front/menu_admin.php");
    } else {
        echo "<script>alert('Acceso denegado'); window.location='../code_front/menu_admin.php';</script>";
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Usuarios registrados</title>
  <link rel="stylesheet" href="../css/crear_usuario.css">
  <script src="../js/sweetalert2.all.min.js"></script>
  <style>
    :root {
      --primary: #840000;
      --danger: #d9534f;
      --secondary: #6c757d;
      --accent: #840000;
      --text: #000;
      --header-bg: #840000;
      --text2: #fff;
      --table-border: #e6e6e6;
    }
    body { color: var(--text); font-family: Arial, sans-serif; }
    .main-container { max-width: 1300px; margin: 20px auto; padding: 10px; }
    h1 { color: var(--accent); }
    .filters { display:flex; gap:8px; align-items:center; margin-bottom:12px; flex-wrap:wrap; }
    select, input, button {
      padding:6px 8px; border:1px solid var(--table-border); border-radius:6px;
    }
    button { cursor:pointer; }
    button#limpiarBtn { background: var(--secondary); color:white; }
    table { width:100%; border-collapse:collapse; }
    th, td { border:1px solid var(--table-border); padding:8px; text-align:center; }
    th { background:var(--header-bg); color:var(--text2); }
    a.icon-btn img { width:24px; height:24px; }
    .pagination { display:flex; gap:6px; justify-content:flex-end; margin:10px 0; flex-wrap:wrap; }
    .pagination a, .pagination span {
      padding:6px 10px; border-radius:6px; background:#f2f2f2; border:1px solid #e0e0e0; text-decoration:none;
    }
    .pagination .current { background:var(--primary); color:#fff; }
    .loading { text-align:center; display:none; padding:20px; }
    .loading.show { display:block; }
    .spinner { border:3px solid #f3f3f3; border-top:3px solid var(--primary); border-radius:50%; width:30px; height:30px; margin:0 auto 10px; animation:spin 1s linear infinite; }
    @keyframes spin { 0%{transform:rotate(0)} 100%{transform:rotate(360deg)} }
  </style>
</head>
<body>
<div class="main-container">
  <h1>Usuarios registrados</h1>

  <!-- Filtros -->
  <form id="form-filtros" class="filters" onsubmit="return false;">
    <label><strong>Buscar por:</strong></label>
    <select id="filtroTipo">
      <option value="dni">DNI</option>
      <option value="nombre">Nombre</option>
    </select>
    <input type="text" id="filtroTexto" placeholder="Escribe para filtrar..." autocomplete="off" style="min-width:260px;">
    <button type="button" id="limpiarBtn">Limpiar</button>
  </form>

  <!-- Loading -->
  <div id="loading" class="loading">
    <div class="spinner"></div>
    <div>Cargando usuarios...</div>
  </div>

  <!-- Tabla -->
  <table id="tabla-usuarios">
    <thead>
      <tr>
        <th>Documento</th>
        <th>Nombre</th>
        <th>Apellido</th>
        <th>Teléfono</th>
        <th>Rol</th>
        <th>Juzgados</th>
        <th colspan="2">Opciones</th>
      </tr>
    </thead>
    <tbody id="tabla-body"></tbody>
  </table>

  <!-- Paginación -->
  <div style="display:flex; align-items:center; gap:10px; margin-top:10px;">
    <div id="pageInfo" class="page-info" style="display:none;">
      Mostrando <strong id="showFrom">0</strong> – <strong id="showTo">0</strong> de <strong id="totalRows">0</strong>
    </div>
    <div id="pagination" class="pagination" style="display:none;"></div>
  </div>
</div>

<script>
let currentPage = 1;
let totalPages = 1;
let isLoading = false;
const filtroTipoEl = document.getElementById('filtroTipo');
const filtroTextoEl = document.getElementById('filtroTexto');
const limpiarBtn = document.getElementById('limpiarBtn');
const tbody = document.getElementById('tabla-body');
const paginationEl = document.getElementById('pagination');
const pageInfoEl = document.getElementById('pageInfo');
const loadingEl = document.getElementById('loading');

function showLoading() {
  loadingEl.classList.add('show');
  tbody.innerHTML = '';
}
function hideLoading() { loadingEl.classList.remove('show'); }

function renderTable(data) {
  tbody.innerHTML = '';
  if (data.length === 0) {
    tbody.innerHTML = '<tr><td colspan="8" style="font-style:italic;">No se encontraron usuarios.</td></tr>';
    return;
  }
  data.forEach(u => {
    tbody.innerHTML += `
      <tr>
        <td>${u.documento}</td>
        <td>${u.nombre_persona}</td>
        <td>${u.apellido_persona}</td>
        <td>${u.telefono_persona}</td>
        <td>${u.nombre_rol}</td>
        <td>${u.juzgados_list}</td>
        <td><a href="#" class="icon-btn" onclick="abrirEditar('${u.documento}')"><img src="../img/editar1.png" alt="Editar"></a></td>
        <td><a href="#" class="icon-btn" onclick="confirmarEliminacion('${u.documento}')"><img src="../img/delete1.png" alt="Eliminar"></a></td>
      </tr>
    `;
  });
}

function renderPagination(p) {
  if (p.total_pages <= 1) { paginationEl.style.display='none'; pageInfoEl.style.display='none'; return; }
  paginationEl.style.display='flex'; pageInfoEl.style.display='block';
  document.getElementById('showFrom').textContent = (p.current_page - 1) * p.limit + 1;
  document.getElementById('showTo').textContent = Math.min(p.current_page * p.limit, p.total_records);
  document.getElementById('totalRows').textContent = p.total_records;

  let html = '';
  const windowSize = 5;
  let start = Math.max(1, p.current_page - Math.floor(windowSize/2));
  let end = Math.min(p.total_pages, start + windowSize - 1);
  if (end - start + 1 < windowSize) start = Math.max(1, end - windowSize + 1);

  const link = (page, label, disabled=false, current=false) => {
    if (disabled) return `<span class="${current?'current':'disabled'}">${label}</span>`;
    if (current) return `<span class="current">${label}</span>`;
    return `<a href="#" onclick="goToPage(${page});return false;">${label}</a>`;
  };

  html += link(1, '« Primero', p.current_page <= 1);
  html += link(p.current_page-1, '‹ Prev', !p.has_prev);
  for (let i=start; i<=end; i++) html += link(i, i, false, i===p.current_page);
  html += link(p.current_page+1, 'Next ›', !p.has_next);
  html += link(p.total_pages, 'Último »', p.current_page >= p.total_pages);
  paginationEl.innerHTML = html;
}

function goToPage(page) {
  if (page<1 || page>totalPages || isLoading) return;
  currentPage = page;
  loadUsuarios();
}

async function loadUsuarios() {
  isLoading = true;
  showLoading();
  const params = new URLSearchParams({
    page: currentPage,
    limit: 20,
    filtroTipo: filtroTipoEl.value,
    filtroTexto: filtroTextoEl.value
  });
  try {
    const res = await fetch(`../api/get_usuarios_paginados.php?${params}`);
    const data = await res.json();
    if (!data.success) throw new Error(data.message);
    renderTable(data.data);
    renderPagination(data.pagination);
    totalPages = data.pagination.total_pages;
  } catch(e) {
    console.error(e);
    tbody.innerHTML = `<tr><td colspan="8" style="color:red;">Error cargando datos</td></tr>`;
  } finally {
    hideLoading();
    isLoading = false;
  }
}

function confirmarEliminacion(documento) {
  Swal.fire({
    title: '¿Eliminar usuario?',
    icon: 'warning',
    showCancelButton: true,
    confirmButtonText: 'Sí, eliminar'
  }).then(r=>{
    if(r.isConfirmed) window.location.href=`../code_back/back_eliminar_usuario.php?documento=${encodeURIComponent(documento)}`;
  });
}
function abrirEditar(doc) {
  // Redirigir a la vista de edición dentro del menú admin
  window.location.href = `menu_admin.php?vista=editar_usuario&documento=${encodeURIComponent(doc)}`;
}

// Filtros
function debounce(fn, ms=300){let t;return(...a)=>{clearTimeout(t);t=setTimeout(()=>fn.apply(this,a),ms);};}
const debounced = debounce(()=>{currentPage=1;loadUsuarios();},300);
filtroTextoEl.addEventListener('input',debounced);
filtroTipoEl.addEventListener('change',()=>{currentPage=1;loadUsuarios();});
limpiarBtn.addEventListener('click',()=>{filtroTextoEl.value='';filtroTipoEl.value='nombre';loadUsuarios();});
document.addEventListener('DOMContentLoaded',loadUsuarios);
</script>
</body>
</html>