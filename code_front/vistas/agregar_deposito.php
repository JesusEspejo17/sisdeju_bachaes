<?php
// registrar_deposito_completo_con_expedientes.php
if (session_status() === PHP_SESSION_NONE) session_start();
include("../code_back/conexion.php");

// Control de acceso: solo rol 1 (admin) y 2 (MAU) pueden ver esta página
if (!isset($_SESSION['rol']) || ($_SESSION['rol'] != 1 && $_SESSION['rol'] != 2)) {
    if (!headers_sent()) {
        header("Location: ../code_front/menu_admin.php");
        exit;
    } else {
        echo "<script>alert('Acceso denegado. Solo administrador o MAU pueden acceder a esta vista.'); window.location='../menu_admin.php';</script>";
        exit;
    }
}

// --- Consultas ---
$sql_juzgados = "SELECT id_juzgado, nombre_juzgado, tipo_juzgado FROM juzgado";
$r_juzgados = mysqli_query($cn, $sql_juzgados);
$juzgados = mysqli_fetch_all($r_juzgados, MYSQLI_ASSOC);

// Cargar secretarios desde la nueva tabla usuario_juzgado (FILTRAR POR ROL Y JUZGADO)
// Esta consulta carga TODOS los secretarios (id_rol=3) asociados a juzgados para el filtrado JS
// El backend validará que el secretario seleccionado pertenezca realmente al juzgado
$sql_secretarios = "SELECT uj.codigo_usu, uj.id_juzgado, CONCAT(p.nombre_persona, ' ', p.apellido_persona) AS nombre_completo
                    FROM usuario_juzgado uj
                    JOIN persona p ON uj.codigo_usu = p.documento
                    JOIN usuario u ON uj.codigo_usu = u.codigo_usu
                    WHERE u.id_rol = 3
                    ORDER BY uj.id_juzgado, p.nombre_persona";
$r_secretarios = mysqli_query($cn, $sql_secretarios);
if (!$r_secretarios) {
    die("Error en consulta de secretarios: " . mysqli_error($cn));
}
$secretarios = mysqli_fetch_all($r_secretarios, MYSQLI_ASSOC);

$sql_beneficiarios = "SELECT b.id_documento, b.documento, CONCAT(p.nombre_persona, ' ', p.apellido_persona) AS nombre_completo,
                      COALESCE(p.telefono_persona,'') AS telefono, COALESCE(p.correo_persona,'') AS correo,
                      COALESCE(p.foto_documento,'') AS foto_documento
                      FROM beneficiario b
                      JOIN persona p ON b.documento = p.documento";
$r_beneficiarios = mysqli_query($cn, $sql_beneficiarios);
$beneficiarios = mysqli_fetch_all($r_beneficiarios, MYSQLI_ASSOC);

// Cargar expedientes con su juzgado (necesario para preselección)
// Ahora usamos la tabla intermedia expediente_beneficiario
$sql_expedientes = "SELECT DISTINCT eb.n_expediente, eb.documento_beneficiario, e.id_juzgado 
                    FROM expediente_beneficiario eb
                    JOIN expediente e ON eb.n_expediente = e.n_expediente";
$r_expedientes = mysqli_query($cn, $sql_expedientes);
$exp_rows = mysqli_fetch_all($r_expedientes, MYSQLI_ASSOC);
$expedientes_map = [];
$expedientes_juzgado_map = []; // mapa n_expediente -> id_juzgado
foreach ($exp_rows as $er) {
    $doc = (string)($er['documento_beneficiario'] ?? '');
    if (!isset($expedientes_map[$doc])) $expedientes_map[$doc] = [];
    $expedientes_map[$doc][] = $er['n_expediente'];
    $expedientes_juzgado_map[$er['n_expediente']] = (int)$er['id_juzgado'];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Registrar Depósito</title>
  <link rel="stylesheet" href="../css/crear_usuario.css">
  <script src="../js/sweetalert2.all.min.js"></script>
  <style>
    .form-row { display:flex; gap:8px; align-items:center; margin-bottom:10px; }
    .expediente-row input { width:80px; text-align:center; }
    #lista-coincidencias li:hover { background:#f2f2f2; }
    #lista-coincidencias { box-shadow: 0 2px 6px rgba(0,0,0,0.06); }
    #depositos-container .form-row { margin-bottom:6px; }
    .muted { color:#666; font-size:0.9em; }
    #expedientes-existentes { margin-top:8px; display:none; }
    #nuevo-beneficiario input, #nuevo-beneficiario select { padding:6px; }
    #preview-img { max-width:120px; max-height:120px; border:1px solid #ddd; padding:4px; background:#fff; display:block; }
    #foto-existente-img, #preview-existente-img { max-width:120px; max-height:120px; border:1px solid #ddd; padding:4px; background:#fff; display:block; }
    #foto-preview-pair { display:flex; gap:12px; align-items:flex-start; margin-top:8px; }
    .foto-box { display:flex; flex-direction:column; align-items:center; gap:6px; }
    .foto-box small { color:#666; font-size:0.85em; }
    button { cursor:pointer; }
    .small-muted { font-size:0.9em; color:#666; margin-left:8px; }
    .consulta-estado { margin-left:8px; font-size:0.9em; color:#333; }
  </style>
</head>
<body>
<div class="main-container">
  <h1>Registrar Depósito</h1>

  <form id="form-deposito" method="post" enctype="multipart/form-data">

    <!-- ===== Beneficiario (BUSCADOR arriba) ===== -->
    <div class="form-row" style="flex-direction:column; align-items:flex-start;">
      <label for="input-buscador-beneficiario"><strong>Beneficiario (DNI o nombre)</strong></label>
      <input type="text" id="input-buscador-beneficiario" placeholder="Escriba el DNI o nombre del beneficiario..." autocomplete="off" style="width:100%; max-width:520px;">

      <div id="dropdown-beneficiarios" style="position:relative; width:100%; max-width:520px;">
        <ul id="lista-coincidencias" style="list-style:none; margin:6px 0; padding:0; max-height:200px; overflow:auto; border:1px solid #ddd; display:none; background:#fff; width:100%;"></ul>
      </div>

      <div id="crear-nuevo-wrap" style="margin-top:6px; display:none;">
        <button type="button" id="btn-crear-nuevo">➕ Crear nuevo beneficiario</button>
        <span style="margin-left:8px; color:#666; font-size:0.9em;">No se encontró coincidencia</span>
      </div>

      <!-- Bloque para crear nuevo beneficiario (oculto por defecto) -->
      <div id="nuevo-beneficiario" style="display:none; margin-top:8px; border:1px solid #eee; padding:8px; background:#fff; width:100%; max-width:520px;">
        <label><strong>Crear nuevo beneficiario</strong></label>
        <div style="display:flex; gap:8px; margin-top:6px; flex-wrap:wrap;">
          <select id="tipo_documento" name="tipo_documento" onchange="cambiarLongitudDocumento()" style="width:150px;">
            <option value="1">DNI</option>
            <option value="2">RUC</option>
          </select>

          <!-- doc_beneficiario: NO tiene name por defecto; JS lo pondrá cuando corresponda -->
          <input type="text" id="doc_beneficiario" placeholder="DNI / RUC" maxlength="11" style="flex:1;" autocomplete="off"/>

          <!-- BOTÓN DE CONSULTA al back (se integra con back_consulta_dni.php) -->
          <button type="button" id="btn-consultar-dni-nuevo">🔎 Consultar DNI</button>
          <span id="consulta-estado" class="consulta-estado"></span>

          <input type="text" id="nombre_beneficiario" name="nombre_beneficiario" placeholder="Nombres" style="flex:1;" autocomplete="off" />
          <input type="text" id="apellido_beneficiario" name="apellido_beneficiario" placeholder="Apellidos" style="flex:1;" autocomplete="off"/>
          <!-- quitar required del HTML: validamos en JS -->
          <input type="text" id="telefono_beneficiario" name="telefono_beneficiario" placeholder="Teléfono (9 dígitos)" style="flex:1;" minlength="9" maxlength="9" autocomplete="off"/>
          <input type="email" id="correo_beneficiario" name="correo_beneficiario" placeholder="Correo (opcional)" style="flex:1;" autocomplete="off"/>

          <!-- Campo nuevo: foto del documento (no tiene name por defecto) -->
          <label for="foto_documento" style="width:100%; flex-basis:100%;"><strong>Foto del DNI:</strong></label>
          <input type="file" id="foto_documento" accept="image/png, image/jpeg" style="flex:1;" />
        </div>

        <div style="margin-top:8px;">
          <button type="button" id="btn-confirmar-dni-nuevo" style="display:none;">✅ Aceptar datos</button>
          <button type="button" id="btn-editar-dni-nuevo" style="display:none;">✏️ Editar</button>
        </div>

        <div id="preview-wrap" style="margin-top:6px; display:none; color:black;">
          <small class="muted">Previsualización (nuevo beneficiario):</small><br>
          <img id="preview-img" src="" alt="Preview">
        </div>
        <!-- Hidden para indicar si se confirmó/llenó datos desde consulta -->
        <input type="hidden" id="nuevo_confirmado" name="nuevo_confirmado" value="">
      </div>

      <div id="beneficiario-detalles" style="margin-top:8px; display:none; border:1px solid #eee; padding:8px; background:#fafafa; width:100%; max-width:520px;">
        <strong id="det-nombre"></strong><br>
        DNI: <span id="det-dni"></span><br>
        Teléfono: <span id="det-tel"></span><br>
        Correo: <span id="det-email"></span>

        <!-- foto(s) area: existente + nueva preview (si se elige) -->
        <div id="foto-preview-pair" style="display:none;">
          <div class="foto-box">
            <small>Foto actual</small>
            <img id="foto-existente-img" src="" alt="Foto actual" style="display:none;">
            <small id="foto-existente-empty" style="display:none; color:#999;">Sin foto</small>
          </div>
          <div class="foto-box">
            <small>Nueva (a subir)</small>
            <img id="preview-existente-img" src="" alt="Nueva foto" style="display:none;">
            <small id="preview-existente-empty" style="display:none; color:#999;">No seleccionada</small>
          </div>
        </div>

        <div style="margin-top:8px;">
          <button type="button" id="btn-actualizar-foto" style="display:none;">📷 Actualizar foto del DNI</button>
          <button type="button" id="btn-cancelar-actualizar-foto" style="display:none; margin-left:8px;">✖ Cancelar</button>
          <small id="label-foto-existente" style="display:none; margin-left:8px; color:#333;">Archivo listo</small>
        </div>
      </div>

      <input type="file" id="foto_documento_existente" accept="image/png, image/jpeg" style="display:none;" />
      <input type="hidden" id="input-beneficiario" name="beneficiario" value="">
      <input type="hidden" id="expediente_existente" name="expediente_existente" value="">
    </div>

    <!-- Si el beneficiario tiene expedientes, se muestran aquí -->
    <div id="expedientes-existentes" style="width:100%; max-width:520px;">
      <label for="select-expedientes"><strong>Expedientes del beneficiario</strong></label>
      <select id="select-expedientes" style="width:100%; margin-top:6px;">
      </select>
      <div class="muted" style="margin-top:6px;">Si desea crear un expediente nuevo elija "Crear nuevo expediente" en la lista.</div>
    </div>

    <!-- Nuevo/Inputs Expediente -->
    <div id="expediente-inputs" style="margin-top:8px; width:100%; max-width:520px;">
      <label for="expediente_1"><strong>Nº de Expediente</strong></label>
      <div class="expediente-row" style="margin-top:6px;">
        <input type="text" id="expediente_1" name="expediente_1" maxlength="5" required placeholder="XXXXX" autocomplete="off">
        <span>-</span>
        <input type="text" id="expediente_2" name="expediente_2" maxlength="4" required placeholder="YYYY" autocomplete="off">
        <span>-</span>
        <input type="text" id="expediente_3" name="expediente_3" maxlength="2" required placeholder="ZZ" autocomplete="off">
      </div>
    </div>

    <hr style="width:100%; margin:16px 0;">

    <!-- Depósitos -->
    <div id="depositos-container" style="width:100%; max-width:520px;">
      <label for="expediente_1">Nº de Depósito Judicial <small class="muted">(opcional)</small></label>
      <div class="form-row">
        <input type="text" name="txt_nro_deposito[]" placeholder="Número de Depósito (opcional)" maxlength="13" autocomplete="off" style="flex:1;">
        <button type="button" onclick="agregarCampoDeposito()">➕</button>
      </div>
      <div class="muted" style="margin-top:6px;">Si no registra depósitos, puede enviar el formulario igual. Si ingresa números, cada uno debe tener entre 8 y 13 caracteres.</div>
    </div>

  <hr style="width:100%; margin:16px 0;">

    <!-- Tipo Juzgado -->
    <div class="form-row" style="flex-direction: column; align-items:flex-start;">
      <label>Juzgado a atender</label>
      <select id="tipo_juzgado" onchange="filtrarJuzgados()" required>
        <option value="" disabled selected>Seleccione tipo de Juzgado</option>
        <option value="PAZ LETRADO">Paz Letrado</option>
        <option value="ESPECIALIZADO">Especializado</option>
      </select>
    </div>

    <!-- Juzgado y Secretario -->
    <div class="form-row">
      <select name="juzgado" id="juzgado" required onchange="cargarSecretarios()">
        <option value="" disabled selected>Seleccione un Juzgado</option>
      </select>

      <select name="secretario" id="secretario" required disabled>
        <option value="">Seleccione un Juzgado primero</option>
      </select>
    </div>

    <!-- Botón -->
    <div class="form-row" style="margin-top:12px;">
      <input type="submit" value="Registrar Depósito">
    </div>
  </form>
</div>

<script>

  /* ===== Helpers para enviar el juzgado cuando el <select> está disabled =====
   Usa setHiddenJuzgado(idJ) justo después de hacer: juzgadoSelect.disabled = true;
   Usa removeHiddenJuzgado() cuando vuelvas a habilitar el select.
*/
function setHiddenJuzgado(value) {
  // usaremos un hidden con name _juzgado_hidden para no chocar con el select
  let existing = document.querySelector('input[name="_juzgado_hidden"]');
  if (!existing) {
    existing = document.createElement('input');
    existing.type = 'hidden';
    existing.name = '_juzgado_hidden';
    existing.dataset.hidden = 'true';
    // anexar al formulario
    const form = document.getElementById('form-deposito') || document.forms[0];
    form.appendChild(existing);
  }
  existing.value = String(value);
}

function removeHiddenJuzgado() {
  const existing = document.querySelector('input[name="_juzgado_hidden"]');
  if (existing) existing.remove();
}

/* === EJEMPLOS de uso (integra en tu lógica actual) ===
Cuando preselecciones y bloquees el select:
  juzgadoSelect.value = idJ;
  juzgadoSelect.disabled = true;
  setHiddenJuzgado(idJ);

Cuando permitas que el usuario cambie el juzgado (por ejemplo al elegir 'Crear nuevo expediente'):
  juzgadoSelect.disabled = false;
  removeHiddenJuzgado();
*/

/* Datos desde PHP */
const juzgados = <?php echo json_encode($juzgados, JSON_UNESCAPED_UNICODE); ?>;
const secretarios = <?php echo json_encode($secretarios, JSON_UNESCAPED_UNICODE); ?>;
const beneficiarios = <?php echo json_encode($beneficiarios, JSON_UNESCAPED_UNICODE); ?>;
const expedientesMap = <?php echo json_encode($expedientes_map, JSON_UNESCAPED_UNICODE); ?>;
const expedientesJuzgadoMap = <?php echo json_encode($expedientes_juzgado_map, JSON_UNESCAPED_UNICODE); ?>;

/* DOM refs */
const inputBuscador = document.getElementById("input-buscador-beneficiario");
const listaCoincidencias = document.getElementById("lista-coincidencias");
const wrapCrearNuevo = document.getElementById("crear-nuevo-wrap");
const btnCrearNuevo = document.getElementById("btn-crear-nuevo");
const detalles = document.getElementById("beneficiario-detalles");
const detNombre = document.getElementById("det-nombre");
const detDni = document.getElementById("det-dni");
const detTel = document.getElementById("det-tel");
const detEmail = document.getElementById("det-email");
const inputHiddenBenef = document.getElementById("input-beneficiario");

const nuevoBeneficioCont = document.getElementById("nuevo-beneficiario");
const docNuevo = document.getElementById("doc_beneficiario");
const nombreNuevo = document.getElementById("nombre_beneficiario");
const apellidoNuevo = document.getElementById("apellido_beneficiario");

const btnConsultarDniNuevo = document.getElementById("btn-consultar-dni-nuevo");
const consultaEstado = document.getElementById("consulta-estado");
const btnConfirmarDniNuevo = document.getElementById("btn-confirmar-dni-nuevo");
const btnEditarDniNuevo = document.getElementById("btn-editar-dni-nuevo");
const inputNuevoConfirmado = document.getElementById("nuevo_confirmado");

const fotoInput = document.getElementById("foto_documento");
const previewWrap = document.getElementById("preview-wrap");
const previewImg = document.getElementById("preview-img");

/* nuevos refs para foto EXISTENTE */
const btnActualizarFoto = document.getElementById("btn-actualizar-foto");
const btnCancelarActualizarFoto = document.getElementById("btn-cancelar-actualizar-foto");
const fotoInputExistente = document.getElementById("foto_documento_existente");
const labelFotoExistente = document.getElementById("label-foto-existente");
let fotoExistenteSeleccionada = false;
let objectURL_existente = null;

const fotoPreviewPair = document.getElementById("foto-preview-pair");
const fotoExistenteImg = document.getElementById("foto-existente-img");
const fotoExistenteEmpty = document.getElementById("foto-existente-empty");
const previewExistenteImg = document.getElementById("preview-existente-img");
const previewExistenteEmpty = document.getElementById("preview-existente-empty");

const expedientesExistentesWrap = document.getElementById("expedientes-existentes");
const selectExpedientes = document.getElementById("select-expedientes");
const expedienteInputs = document.getElementById("expediente-inputs");
const exp1 = document.getElementById("expediente_1");
const exp2 = document.getElementById("expediente_2");
const exp3 = document.getElementById("expediente_3");
const expedienteExistenteHidden = document.getElementById("expediente_existente");
const telefonoBenef = document.getElementById("telefono_beneficiario");

/* Inicial: asegurarse que campo teléfono no participe si no está visible */
if (telefonoBenef) {
  telefonoBenef.removeAttribute('required');
  telefonoBenef.disabled = true; // no será validado ni enviado cuando esté disabled
  telefonoBenef.removeAttribute('name');
}

/* Filtrar y mostrar juzgados por tipo */
function filtrarJuzgados() {
  const tipo = document.getElementById("tipo_juzgado").value;
  const juzgadoSelect = document.getElementById("juzgado");
  juzgadoSelect.innerHTML = '<option value="" disabled selected>Seleccione un Juzgado</option>';

  juzgados.forEach(j => {
    if (j.tipo_juzgado === tipo) {
      const option = document.createElement("option");
      option.value = j.id_juzgado;
      option.textContent = j.nombre_juzgado;
      juzgadoSelect.appendChild(option);
    }
  });

  const secretarioSelect = document.getElementById("secretario");
  secretarioSelect.innerHTML = '<option value="">Seleccione un Juzgado primero</option>';
  secretarioSelect.disabled = true;
}

/* Cargar secretarios del juzgado (ahora desde usuario_juzgado) */
function cargarSecretarios() {
  const juzgadoId = document.getElementById("juzgado").value;
  const secretarioSelect = document.getElementById("secretario");

  secretarioSelect.innerHTML = '<option value="" disabled selected>Seleccione un Secretario</option>';
  let hay = false;

  secretarios.forEach(s => {
    if (s.id_juzgado == juzgadoId) {
      const option = document.createElement("option");
      option.value = s.codigo_usu;
      option.textContent = s.nombre_completo;
      secretarioSelect.appendChild(option);
      hay = true;
    }
  });

  secretarioSelect.disabled = !hay;
}

/* Agregar campo depósito dinámico */
function agregarCampoDeposito() {
  const contenedor = document.getElementById("depositos-container");
  const nuevo = document.createElement("div");
  nuevo.className = "form-row";
  nuevo.innerHTML = `
    <input type="text" name="txt_nro_deposito[]" placeholder="Número de Depósito (opcional)" maxlength="13" autocomplete="off" style="flex:1;">
    <button type="button" onclick="this.parentNode.remove()">❌</button>
  `;
  contenedor.appendChild(nuevo);
}

/* Beneficiarios: funciones auxiliares */
function limpiarSeleccionBenef() {
  inputHiddenBenef.value = "";
  detalles.style.display = "none";
  detNombre.textContent = "";
  detDni.textContent = "";
  detTel.textContent = "";
  detEmail.textContent = "";
  expedientesExistentesWrap.style.display = "none";
  selectExpedientes.innerHTML = "";
  exp1.value = '';
  exp2.value = '';
  exp3.value = '';
  expedienteInputs.style.display = "block";
  setExpedienteReadonly(false);
  expedienteExistenteHidden.value = "";
  nuevoBeneficioCont.style.display = "none";
  if (fotoInput) fotoInput.removeAttribute('name');
  if (docNuevo) docNuevo.removeAttribute('name');
  if (telefonoBenef) {
    telefonoBenef.disabled = true;
    telefonoBenef.removeAttribute('name');
    telefonoBenef.value = "";
  }
  if (fotoInputExistente) {
    fotoInputExistente.value = "";
    fotoInputExistente.removeAttribute('name');
  }
  fotoExistenteSeleccionada = false;
  if (objectURL_existente) { URL.revokeObjectURL(objectURL_existente); objectURL_existente = null; }
  if (labelFotoExistente) labelFotoExistente.style.display = "none";
  if (btnActualizarFoto) btnActualizarFoto.style.display = "none";
  if (btnCancelarActualizarFoto) btnCancelarActualizarFoto.style.display = "none";
  if (previewWrap) { previewWrap.style.display = "none"; if (previewImg) previewImg.src = ""; }
  if (fotoPreviewPair) fotoPreviewPair.style.display = "none";
  if (fotoExistenteImg) { fotoExistenteImg.style.display = "none"; fotoExistenteImg.src = ""; }
  if (fotoExistenteEmpty) fotoExistenteEmpty.style.display = "none";
  if (previewExistenteImg) { previewExistenteImg.style.display = "none"; previewExistenteImg.src = ""; }
  if (previewExistenteEmpty) previewExistenteEmpty.style.display = "none";

  // limpiar nuevo beneficiario inputs y estados de consulta
  if (nombreNuevo) { nombreNuevo.value = ''; nombreNuevo.readOnly = false; }
  if (apellidoNuevo) { apellidoNuevo.value = ''; apellidoNuevo.readOnly = false; }
  if (docNuevo) { docNuevo.value = ''; docNuevo.removeAttribute('name'); }
  if (inputNuevoConfirmado) { inputNuevoConfirmado.value = ''; }
  if (consultaEstado) consultaEstado.textContent = '';
  if (btnConfirmarDniNuevo) btnConfirmarDniNuevo.style.display = 'none';
  if (btnEditarDniNuevo) btnEditarDniNuevo.style.display = 'none';

  // restaurar posibilidad de elegir juzgado
  const tipoSel = document.getElementById('tipo_juzgado');
  if (tipoSel) { tipoSel.value = ''; }
  const juzgadoSel = document.getElementById('juzgado');
  if (juzgadoSel) { juzgadoSel.innerHTML = '<option value="" disabled selected>Seleccione un Juzgado</option>'; juzgadoSel.disabled = false; }
  const secretarioSel = document.getElementById('secretario');
  if (secretarioSel) { secretarioSel.innerHTML = '<option value="">Seleccione un Juzgado primero</option>'; secretarioSel.disabled = true; }
}

function setExpedienteReadonly(flag) {
  exp1.readOnly = flag;
  exp2.readOnly = flag;
  exp3.readOnly = flag;
  exp1.style.background = flag ? "#eee" : "";
  exp2.style.background = flag ? "#eee" : "";
  exp3.style.background = flag ? "#eee" : "";
}

function filtrarBeneficiarios(texto) {
  const q = texto.trim().toLowerCase();
  wrapCrearNuevo.style.display = "none";
  listaCoincidencias.innerHTML = "";
  listaCoincidencias.style.display = "none";

  if (!q) {
    limpiarSeleccionBenef();
    return;
  }

  const matches = beneficiarios.filter(b => {
    const dni = (b.documento || "").toString().toLowerCase();
    const name = (b.nombre_completo || "").toLowerCase();
    return dni.includes(q) || name.includes(q);
  });

  if (matches.length === 0) {
    wrapCrearNuevo.style.display = "block";
    limpiarSeleccionBenef();
    return;
  }

  listaCoincidencias.style.display = "block";
  matches.forEach(m => {
    const li = document.createElement("li");
    li.style.padding = "6px 8px";
    li.style.borderBottom = "1px solid #f0f0f0";
    li.style.cursor = "pointer";
    li.textContent = `${m.documento} - ${m.nombre_completo}`;
    li.dataset.doc = m.documento;
    li.dataset.nombre = m.nombre_completo;
    li.dataset.tel = m.telefono || "";
    li.dataset.email = m.correo || "";
    li.addEventListener("click", () => seleccionarBeneficiario(m));
    listaCoincidencias.appendChild(li);
  });
}

function mostrarBotonActualizarFoto() {
  if (btnActualizarFoto) btnActualizarFoto.style.display = "inline-block";
  if (btnCancelarActualizarFoto) btnCancelarActualizarFoto.style.display = "none";
  if (labelFotoExistente) labelFotoExistente.style.display = "none";
}

function cancelarActualizarFoto() {
  if (fotoInputExistente) {
    fotoInputExistente.value = "";
    fotoInputExistente.removeAttribute('name');
  }
  fotoExistenteSeleccionada = false;
  if (objectURL_existente) { URL.revokeObjectURL(objectURL_existente); objectURL_existente = null; }
  if (previewWrap) { previewWrap.style.display = "none"; if (previewImg) previewImg.src = ""; }
  if (labelFotoExistente) labelFotoExistente.style.display = "none";
  if (btnCancelarActualizarFoto) btnCancelarActualizarFoto.style.display = "none";
  if (btnActualizarFoto) btnActualizarFoto.style.display = "inline-block";
  if (previewExistenteImg) { previewExistenteImg.style.display = "none"; previewExistenteImg.src = ""; }
  if (previewExistenteEmpty) previewExistenteEmpty.style.display = "inline";
}

function seleccionarBeneficiario(m) {
  inputBuscador.value = `${m.documento} - ${m.nombre_completo}`;
  inputHiddenBenef.value = m.documento;
  inputHiddenBenef.setAttribute("name", "beneficiario");

  listaCoincidencias.style.display = "none";
  wrapCrearNuevo.style.display = "none";

  detNombre.textContent = m.nombre_completo;
  detDni.textContent = m.documento;
  detTel.textContent = m.telefono || '—';
  detEmail.textContent = m.correo || '—';
  detalles.style.display = "block";
  nuevoBeneficioCont.style.display = "none";

  if (docNuevo) docNuevo.removeAttribute("name");
  if (fotoInput) fotoInput.removeAttribute('name');

  if (telefonoBenef) {
    telefonoBenef.disabled = true;
    telefonoBenef.removeAttribute('name');
  }

  const exps = expedientesMap[m.documento] || [];
  selectExpedientes.innerHTML = '';

  if (exps.length > 0) {
    const optNuevo = document.createElement("option");
    optNuevo.value = "___NUEVO___";
    optNuevo.textContent = "Crear nuevo expediente";
    selectExpedientes.appendChild(optNuevo);

    exps.forEach((ne) => {
      const opt = document.createElement("option");
      opt.value = ne;
      opt.textContent = ne;
      selectExpedientes.appendChild(opt);
    });

    expedientesExistentesWrap.style.display = "block";
    if (selectExpedientes.options.length > 1) {
      selectExpedientes.selectedIndex = 1;
      rellenarExpedienteDesde(selectExpedientes.value);
      setExpedienteReadonly(true);
      expedienteExistenteHidden.value = selectExpedientes.value;

      // --- NUEVO: fijar juzgado preseleccionado según el expediente ---
      const chosenExp = selectExpedientes.value;
      const idJ = expedientesJuzgadoMap[chosenExp];
      if (idJ) {
        // buscar el objeto juzgado para obtener su tipo
        const jObj = juzgados.find(j => String(j.id_juzgado) === String(idJ));
        if (jObj) {
          // setear tipo_juzgado y forzar el select de juzgados a contener ese juzgado
          document.getElementById('tipo_juzgado').value = jObj.tipo_juzgado;
          filtrarJuzgados();

          const juzgadoSelect = document.getElementById('juzgado');
          // si la opción aún no existe en el select (por filtrado), agregarla
          if (![...juzgadoSelect.options].some(o => o.value == idJ)) {
            const opt = document.createElement('option');
            opt.value = jObj.id_juzgado;
            opt.textContent = jObj.nombre_juzgado;
            juzgadoSelect.appendChild(opt);
          }
          juzgadoSelect.value = idJ;
          juzgadoSelect.disabled = true; // no permitir cambiar juzgado cuando expediente ya lo tiene

          // poblar secretarios para ese juzgado y activar select
          cargarSecretarios();
        }
      }

    } else {
      expedienteInputs.style.display = "block";
      setExpedienteReadonly(false);
      expedienteExistenteHidden.value = "";
    }
  } else {
    expedientesExistentesWrap.style.display = "none";
    expedienteInputs.style.display = "block";
    setExpedienteReadonly(false);
    expedienteExistenteHidden.value = "";
  }

  mostrarBotonActualizarFoto();

  if (fotoPreviewPair) fotoPreviewPair.style.display = "flex";
  if (m.foto_documento && m.foto_documento.trim() !== "") {
    const url = "../" + m.foto_documento.replace(/^\/+/, "");
    if (fotoExistenteImg) { fotoExistenteImg.src = url; fotoExistenteImg.style.display = "block"; }
    if (fotoExistenteEmpty) fotoExistenteEmpty.style.display = "none";
  } else {
    if (fotoExistenteImg) { fotoExistenteImg.src = ""; fotoExistenteImg.style.display = "none"; }
    if (fotoExistenteEmpty) fotoExistenteEmpty.style.display = "block";
  }

  if (previewExistenteImg) { previewExistenteImg.src = ""; previewExistenteImg.style.display = "none"; }
  if (previewExistenteEmpty) previewExistenteEmpty.style.display = "inline";
}

/* selectExpedientes change listener */
selectExpedientes.addEventListener("change", function () {
  const val = this.value;
  if (val === "___NUEVO___") {
    exp1.value = '';
    exp2.value = '';
    exp3.value = '';
    expedienteInputs.style.display = "block";
    setExpedienteReadonly(false);
    expedienteExistenteHidden.value = "";

    // permitir al usuario elegir juzgado si crea nuevo expediente
    document.getElementById('juzgado').disabled = false;
    document.getElementById('tipo_juzgado').value = '';
    document.getElementById('juzgado').innerHTML = '<option value="" disabled selected>Seleccione un Juzgado</option>';
    document.getElementById('secretario').innerHTML = '<option value="">Seleccione un Juzgado primero</option>';
    document.getElementById('secretario').disabled = true;

  } else {
    rellenarExpedienteDesde(val);
    expedienteInputs.style.display = "block";
    setExpedienteReadonly(true);
    expedienteExistenteHidden.value = val;

    // fijar juzgado preseleccionado según expediente
    const idJ = expedientesJuzgadoMap[val];
    if (idJ) {
      const jObj = juzgados.find(j => String(j.id_juzgado) === String(idJ));
      if (jObj) {
        document.getElementById('tipo_juzgado').value = jObj.tipo_juzgado;
        filtrarJuzgados();

        const juzgadoSelect = document.getElementById('juzgado');
        if (![...juzgadoSelect.options].some(o => o.value == idJ)) {
          const opt = document.createElement('option');
          opt.value = jObj.id_juzgado;
          opt.textContent = jObj.nombre_juzgado;
          juzgadoSelect.appendChild(opt);
        }
        juzgadoSelect.value = idJ;
        juzgadoSelect.disabled = true;
        cargarSecretarios();
      }
    }
  }
});

function rellenarExpedienteDesde(n_expediente) {
  if (!n_expediente) return;
  const parts = n_expediente.split('-');
  exp1.value = parts[0] || '';
  exp2.value = parts[1] || '';
  exp3.value = parts[2] || '';
}

/* Crear nuevo beneficiario (cuando no hay coincidencias) */
btnCrearNuevo.addEventListener("click", () => {
  nuevoBeneficioCont.style.display = "flex";
  wrapCrearNuevo.style.display = "none";
  listaCoincidencias.style.display = "none";
  detalles.style.display = "none";

  inputHiddenBenef.value = "";

  docNuevo.value = "";
  docNuevo.setAttribute('name', 'doc_beneficiario');

  nombreNuevo.value = "";
  apellidoNuevo.value = "";
  document.getElementById("tipo_documento").value = "1";
  cambiarLongitudDocumento();

  expedientesExistentesWrap.style.display = "none";
  expedienteInputs.style.display = "block";
  setExpedienteReadonly(false);
  expedienteExistenteHidden.value = "";

  if (fotoInput) fotoInput.setAttribute('name', 'foto_documento');

  if (telefonoBenef) {
    telefonoBenef.disabled = false;
    telefonoBenef.setAttribute('name','telefono_beneficiario');
  }

  if (btnActualizarFoto) btnActualizarFoto.style.display = "none";
  if (btnCancelarActualizarFoto) btnCancelarActualizarFoto.style.display = "none";
  if (labelFotoExistente) labelFotoExistente.style.display = "none";

  if (fotoInput) {
    fotoInput.value = "";
    previewWrap.style.display = "none";
    previewImg.src = "";
  }

  if (fotoInputExistente) {
    fotoInputExistente.value = "";
    fotoInputExistente.removeAttribute('name');
  }
  fotoExistenteSeleccionada = false;

  if (fotoPreviewPair) fotoPreviewPair.style.display = "none";
  if (fotoExistenteImg) { fotoExistenteImg.style.display = "none"; fotoExistenteImg.src = ""; }
  if (previewExistenteImg) { previewExistenteImg.style.display = "none"; previewExistenteImg.src = ""; }
  if (fotoExistenteEmpty) fotoExistenteEmpty.style.display = "none";
  if (previewExistenteEmpty) previewExistenteEmpty.style.display = "none";

  // limpiar estado de consulta DNI al abrir crear nuevo
  if (consultaEstado) consultaEstado.textContent = '';
  if (inputNuevoConfirmado) inputNuevoConfirmado.value = '';
  if (btnConfirmarDniNuevo) btnConfirmarDniNuevo.style.display = 'none';
  if (btnEditarDniNuevo) btnEditarDniNuevo.style.display = 'none';

  // permitir elegir juzgado y secretario para nuevo expediente
  document.getElementById('tipo_juzgado').value = '';
  document.getElementById('juzgado').disabled = false;
  document.getElementById('juzgado').innerHTML = '<option value="" disabled selected>Seleccione un Juzgado</option>';
  document.getElementById('secretario').innerHTML = '<option value="">Seleccione un Juzgado primero</option>';
  document.getElementById('secretario').disabled = true;
});

/* Input buscador */
inputBuscador.addEventListener("input", (e) => {
  const val = e.target.value;
  if (!val) {
    limpiarSeleccionBenef();
  }
  filtrarBeneficiarios(val);
});

/* Cambiar longitud segun tipo documento */
function cambiarLongitudDocumento() {
  const tipo = document.getElementById("tipo_documento").value;
  const input = document.getElementById("doc_beneficiario");
  input.maxLength = tipo === "1" ? 8 : 11;
  input.placeholder = tipo === "1" ? "DNI - 8 dígitos" : "RUC - 11 dígitos";
}

/* PREVIEW y validación cliente para foto_documento (NUEVO beneficiario) */
if (fotoInput) {
  fotoInput.addEventListener("change", function () {
    const file = this.files[0];
    if (!file) {
      previewWrap.style.display = "none";
      previewImg.src = "";
      return;
    }
    const maxSize = 5 * 1024 * 1024; // 5 MB
    const allowed = ["image/png", "image/jpeg"];
    if (!allowed.includes(file.type)) {
      Swal.fire("❌ Error", "Solo se permiten imágenes PNG o JPG.", "error");
      this.value = "";
      previewWrap.style.display = "none";
      previewImg.src = "";
      return;
    }
    if (file.size > maxSize) {
      Swal.fire("❌ Error", "La imagen no puede exceder 5 MB.", "error");
      this.value = "";
      previewWrap.style.display = "none";
      previewImg.src = "";
      return;
    }
    const reader = new FileReader();
    reader.onload = (e) => {
      previewImg.src = e.target.result;
      previewWrap.style.display = "block";
    };
    reader.readAsDataURL(file);
  });
}

/* PREVIEW y validación cliente para foto_documento EXISTENTE (actualizar foto) */
if (fotoInputExistente) {
  fotoInputExistente.addEventListener("change", function () {
    const file = this.files[0];
    if (!file) {
      fotoExistenteSeleccionada = false;
      if (labelFotoExistente) labelFotoExistente.style.display = "none";
      if (objectURL_existente) { URL.revokeObjectURL(objectURL_existente); objectURL_existente = null; }
      if (previewWrap) { previewWrap.style.display = "none"; if (previewImg) previewImg.src = ""; }
      this.removeAttribute('name');
      if (previewExistenteImg) { previewExistenteImg.style.display = "none"; previewExistenteImg.src = ""; }
      if (previewExistenteEmpty) previewExistenteEmpty.style.display = "inline";
      return;
    }

    const maxSize = 2 * 1024 * 1024; // 2 MB
    const allowed = ["image/png", "image/jpeg"];
    if (!allowed.includes(file.type)) {
      Swal.fire("❌ Error", "Solo se permiten imágenes PNG o JPG.", "error");
      this.value = "";
      fotoExistenteSeleccionada = false;
      if (labelFotoExistente) labelFotoExistente.style.display = "none";
      if (previewWrap) { previewWrap.style.display = "none"; if (previewImg) previewImg.src = ""; }
      this.removeAttribute('name');
      if (previewExistenteImg) { previewExistenteImg.style.display = "none"; previewExistenteImg.src = ""; }
      if (previewExistenteEmpty) previewExistenteEmpty.style.display = "inline";
      return;
    }
    if (file.size > maxSize) {
      Swal.fire("❌ Error", "La imagen no puede exceder 2 MB.", "error");
      this.value = "";
      fotoExistenteSeleccionada = false;
      if (labelFotoExistente) labelFotoExistente.style.display = "none";
      if (previewWrap) { previewWrap.style.display = "none"; if (previewImg) previewImg.src = ""; }
      this.removeAttribute('name');
      if (previewExistenteImg) { previewExistenteImg.style.display = "none"; previewExistenteImg.src = ""; }
      if (previewExistenteEmpty) previewExistenteEmpty.style.display = "inline";
      return;
    }

    if (objectURL_existente) { URL.revokeObjectURL(objectURL_existente); objectURL_existente = null; }
    objectURL_existente = URL.createObjectURL(file);

    if (previewExistenteImg) {
      previewExistenteImg.src = objectURL_existente;
      previewExistenteImg.style.display = "block";
    }
    if (previewExistenteEmpty) previewExistenteEmpty.style.display = "none";

    fotoInputExistente.setAttribute('name', 'foto_documento');
    fotoExistenteSeleccionada = true;

    if (btnCancelarActualizarFoto) btnCancelarActualizarFoto.style.display = "inline-block";
    if (btnActualizarFoto) btnActualizarFoto.style.display = "none";

    if (fotoPreviewPair) fotoPreviewPair.style.display = "flex";
  });
}

/* listeners para botones actualizar/cancelar (existente) */
if (btnActualizarFoto) {
  btnActualizarFoto.addEventListener("click", function () {
    if (fotoInputExistente) {
      fotoInputExistente.click();
      if (btnCancelarActualizarFoto) btnCancelarActualizarFoto.style.display = "inline-block";
      if (btnActualizarFoto) btnActualizarFoto.style.display = "none";
    }
  });
}
if (btnCancelarActualizarFoto) {
  btnCancelarActualizarFoto.addEventListener("click", function () {
    cancelarActualizarFoto();
  });
}

/* --- NUEVO: lógica para consultar DNI desde el "Crear nuevo beneficiario" --- */
if (btnConsultarDniNuevo) {
  btnConsultarDniNuevo.addEventListener('click', async function () {
    const tipo = document.getElementById("tipo_documento").value;
    const doc = (docNuevo && docNuevo.value) ? docNuevo.value.trim() : '';
    // validaciones básicas
    if (tipo === "1" && !/^\d{8}$/.test(doc)) {
      Swal.fire("❌ Error", "DNI debe tener exactamente 8 dígitos.", "error");
      return;
    }
    if (tipo === "2" && !/^\d{11}$/.test(doc)) {
      Swal_FIRE("❌ Error", "RUC debe tener exactamente 11 dígitos.", "error");
      return;
    }

    // feedback
    if (consultaEstado) { consultaEstado.textContent = 'Consultando...'; }
    // llamada POST al proxy en server (ajusta ruta si tu archivo está en otra carpeta)
    try {
      const form = new FormData();
      // Para DNI usamos el campo 'dni' en el back_consulta_dni.php
      form.set('dni', tipo === "1" ? doc : doc); // si en futuro RUC tiene otro endpoint cambia aquí

      const resp = await fetch('../code_back/back_consulta_dni.php', { method: 'POST', body: form, cache: 'no-store' });
      const json = await resp.json();

      if (!json || !json.success) {
        // manejar códigos y habilitar edición manual
        if (consultaEstado) consultaEstado.textContent = '';
        inputNuevoConfirmado.value = '';
        nombreNuevo.readOnly = false;
        apellidoNuevo.readOnly = false;
        btnConfirmarDniNuevo.style.display = 'none';
        btnEditarDniNuevo.style.display = 'none';
        if (json && json.code === 'NOT_FOUND') {
          Swal.fire('No encontrado', 'No se encontraron datos para ese documento. Completa manualmente.', 'info');
        } else if (json && json.code === 'RATE_LIMIT') {
          Swal.fire('Espera', 'Demasiadas peticiones. Intenta en un minuto.', 'warning');
        } else {
          Swal.fire('Error', (json && json.message) ? json.message : 'Error consultando el servicio', 'error');
        }
        return;
      }

      // OK: rellenar campos (usar campos 'nombres' y 'apellidos' según el proxy)
      const d = json.data || {};
      // Preferir 'apellidos' si existe, sino concatenar
      const apellidos = d.apellidos || ((d.apellidoPaterno || '') + (d.apellidoMaterno ? ' ' + d.apellidoMaterno : '')) || '';
      if (nombreNuevo) {
        nombreNuevo.value = d.nombres || '';
        nombreNuevo.readOnly = true;
      }
      if (apellidoNuevo) {
        apellidoNuevo.value = apellidos.trim();
        apellidoNuevo.readOnly = true;
      }
      // marcar confirmado
      if (inputNuevoConfirmado) inputNuevoConfirmado.value = '1';
      if (consultaEstado) consultaEstado.textContent = 'Datos cargados desde el servicio';
      btnConfirmarDniNuevo.style.display = 'inline-block';
      btnEditarDniNuevo.style.display = 'inline-block';
    } catch (err) {
      console.error('Error consulta DNI:', err);
      if (consultaEstado) consultaEstado.textContent = '';
      inputNuevoConfirmado.value = '';
      nombreNuevo.readOnly = false;
      apellidoNuevo.readOnly = false;
      btnConfirmarDniNuevo.style.display = 'none';
      btnEditarDniNuevo.style.display = 'none';
      Swal.fire('Error', 'Ocurrió un error al consultar el servicio.', 'error');
    }
  });
}

/* Confirmar / Editar (nuevo beneficiario) */
if (btnConfirmarDniNuevo) {
  btnConfirmarDniNuevo.addEventListener('click', function () {
    // si los campos están readonly, se asume confirmación; si el usuario editó manualmente, también permitimos
    const nom = (nombreNuevo && nombreNuevo.value) ? nombreNuevo.value.trim() : '';
    const ape = (apellidoNuevo && apellidoNuevo.value) ? apellidoNuevo.value.trim() : '';
    if (!nom || !ape) {
      Swal.fire('Error', 'El nombre y apellido no pueden quedar vacíos.', 'error');
      return;
    }
    // forzamos valor y marcamos confirmado
    if (inputNuevoConfirmado) inputNuevoConfirmado.value = '1';
    Swal.fire('Listo', 'Datos confirmados. Ahora puedes completar el resto y enviar.', 'success');
    // bloquear edición por defecto (si quieres dejar editable, comentar las siguientes dos)
    if (nombreNuevo) nombreNuevo.readOnly = true;
    if (apellidoNuevo) apellidoNuevo.readOnly = true;
    btnConfirmarDniNuevo.style.display = 'none';
    btnEditarDniNuevo.style.display = 'inline-block';
  });
}
if (btnEditarDniNuevo) {
  btnEditarDniNuevo.addEventListener('click', function () {
    if (nombreNuevo) { nombreNuevo.readOnly = false; nombreNuevo.focus(); }
    if (apellidoNuevo) { apellidoNuevo.readOnly = false; }
    if (inputNuevoConfirmado) inputNuevoConfirmado.value = '';
    btnConfirmarDniNuevo.style.display = 'inline-block';
    btnEditarDniNuevo.style.display = 'none';
    if (consultaEstado) consultaEstado.textContent = 'Editando manualmente';
  });
}

/* REEMPLAZO: handler submit que construye FormData, fuerza campos y valida */
document.getElementById("form-deposito").addEventListener("submit", async function (e) {
  e.preventDefault();
  const form = e.target;

  try {
    if (!form.contains(inputHiddenBenef)) form.appendChild(inputHiddenBenef);

    const creatingNew = nuevoBeneficioCont && nuevoBeneficioCont.style.display !== "none";

    if (creatingNew) {
      if (docNuevo) docNuevo.setAttribute('name', 'doc_beneficiario');
      if (fotoInput) fotoInput.setAttribute('name', 'foto_documento');
      if (telefonoBenef) telefonoBenef.setAttribute('name','telefono_beneficiario');
      if (inputHiddenBenef) inputHiddenBenef.value = "";
    } else {
      if (inputHiddenBenef) {
        inputHiddenBenef.setAttribute('name', 'beneficiario');
        if (!inputHiddenBenef.value || inputHiddenBenef.value.trim() === "") {
          return Swal.fire("❌ Error", "No se detectó beneficiario seleccionado. Haz click en la lista para seleccionar.", "error");
        }
      }
      if (docNuevo) docNuevo.removeAttribute('name');
      if (fotoInput) fotoInput.removeAttribute('name');
      if (telefonoBenef) {
        telefonoBenef.removeAttribute('name');
        telefonoBenef.disabled = true;
      }
    }

    if (!creatingNew && fotoExistenteSeleccionada && fotoInputExistente && fotoInputExistente.files && fotoInputExistente.files.length > 0) {
      fotoInputExistente.setAttribute('name', 'foto_documento');
      if (!form.contains(fotoInputExistente)) form.appendChild(fotoInputExistente);
    }

    const fd = new FormData(form);

    if (!fd.has('beneficiario') && inputHiddenBenef && inputHiddenBenef.value.trim() !== "") {
      fd.set('beneficiario', inputHiddenBenef.value.trim());
      console.warn("[DEBUG] Forzado beneficiario:", inputHiddenBenef.value.trim());
    }
    if (!fd.has('doc_beneficiario') && docNuevo && docNuevo.value.trim() !== "" && creatingNew) {
      fd.set('doc_beneficiario', docNuevo.value.trim());
      console.warn("[DEBUG] Forzado doc_beneficiario:", docNuevo.value.trim());
    }
    if (!fd.has('foto_documento') && fotoInput && fotoInput.files && fotoInput.files.length > 0 && creatingNew) {
      fd.set('foto_documento', fotoInput.files[0]);
      console.warn("[DEBUG] Forzada foto_documento (nuevo):", fotoInput.files[0].name);
    }
    if (!fd.has('foto_documento') && fotoInputExistente && fotoInputExistente.files && fotoInputExistente.files.length > 0 && !creatingNew) {
      fd.set('foto_documento', fotoInputExistente.files[0]);
      console.warn("[DEBUG] Forzada foto_documento (existente):", fotoInputExistente.files[0].name);
    }

    if (!fd.has('expediente_existente') && expedienteExistenteHidden && expedienteExistenteHidden.value) {
      fd.set('expediente_existente', expedienteExistenteHidden.value);
    }

    // DEBUG: listar lo que se va a enviar (mira consola)
    console.groupCollapsed("[DEBUG] FormData a enviar");
    for (const k of fd.keys()) {
      const v = fd.get(k);
      if (v instanceof File) console.log(k + ": File(" + v.name + ", " + v.size + " bytes)");
      else console.log(k + ":", String(v).substr(0,200));
    }
    console.groupEnd();

    // Validaciones cliente: depósitos (ahora opcional)
    const depositos = fd.getAll("txt_nro_deposito[]");
    for (let nro of depositos) {
      if (nro === null || nro === undefined) continue;
      nro = String(nro).trim();
      if (nro === "") continue; // campo vacío -> permitido (opcional)
      if (nro.length < 8 || nro.length > 13) {
        return Swal.fire("❌ Error", "Cada depósito (si se ingresa) debe tener entre 8 y 13 caracteres", "error");
      }
    }

    // Validación beneficiario
    const hasExisting = fd.has('beneficiario') && String(fd.get('beneficiario')).trim() !== "";
    const hasNew = fd.has('doc_beneficiario') && String(fd.get('doc_beneficiario')).trim() !== "";

    if (!hasExisting && !hasNew) {
      return Swal.fire("❌ Error", "Debe seleccionar o crear un beneficiario", "error");
    }

    // Si estamos creando nuevo: validar nombre, apellido y teléfono (9 dígitos)
    if (creatingNew) {
      const tipoDoc = document.getElementById("tipo_documento").value;
      const docVal = (docNuevo && docNuevo.value) ? docNuevo.value.trim() : "";
      const nomVal = (nombreNuevo && nombreNuevo.value) ? nombreNuevo.value.trim() : "";
      const apeVal = (apellidoNuevo && apellidoNuevo.value) ? apellidoNuevo.value.trim() : "";
      const telVal = (telefonoBenef && telefonoBenef.value) ? telefonoBenef.value.trim() : "";

      if (!nomVal) {
        nombreNuevo.focus();
        return Swal.fire("❌ Error", "El nombre del beneficiario no puede quedar vacío.", "error");
      }
      if (!apeVal) {
        apellidoNuevo.focus();
        return Swal.fire("❌ Error", "El apellido del beneficiario no puede quedar vacío.", "error");
      }

      if (tipoDoc === "1" && (!/^\d{8}$/.test(docVal))) {
        docNuevo.focus();
        return Swal.fire("❌ Error", "DNI debe tener exactamente 8 dígitos.", "error");
      }
      if (tipoDoc === "2" && (!/^\d{11}$/.test(docVal))) {
        docNuevo.focus();
        return Swal.fire("❌ Error", "RUC debe tener exactamente 11 dígitos.", "error");
      }

      if (!/^\d{9}$/.test(telVal)) {
        if (telefonoBenef) telefonoBenef.focus();
        return Swal.fire("❌ Error", "Ingrese un teléfono válido de 9 dígitos para el nuevo beneficiario.", "error");
      }

      if (!fd.has('nombre_beneficiario')) fd.set('nombre_beneficiario', nomVal);
      if (!fd.has('apellido_beneficiario')) fd.set('apellido_beneficiario', apeVal);
      if (!fd.has('telefono_beneficiario')) fd.set('telefono_beneficiario', telVal);
    }

    // validar expediente inputs
    const e1 = exp1.value.trim(), e2 = exp2.value.trim(), e3 = exp3.value.trim();
    if (!e1 || !e2 || !e3) {
      return Swal.fire("❌ Error", "Complete el número de expediente (o seleccione uno existente).", "error");
    }

    // En lugar de enviar ahora, mostrar el modal de confirmación para añadir observación opcional
    mostrarConfirmModal(fd, form);
    return;
  } catch (err) {
    console.error(err);
    Swal.fire("❌ Error inesperado", "Revisa la consola para más detalles", "error");
  }
});
</script>
<!-- Modal: Confirmar registro con observación -->
<div id="confirmRegisterModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.45); z-index:9999; align-items:center; justify-content:center;">
  <div style="background:#fff; padding:16px; border-radius:8px; max-width:520px; width:95%; box-shadow:0 8px 30px rgba(0,0,0,0.2);">
    <h3 style="margin-top:0;">Confirmar registro de depósito</h3>
    <div style="margin:8px 0;">
      <div><strong>Expediente:</strong> <span id="modal-expediente">--</span></div>
      <div><strong>Depósito:</strong> <span id="modal-deposito">--</span></div>
    </div>
    <div style="margin-top:8px;">
      <label for="modal-observacion"><strong>Observación (opcional)</strong></label>
      <textarea id="modal-observacion" rows="4" style="width:100%; padding:8px; box-sizing:border-box; margin-top:6px;"></textarea>
    </div>
    <div style="display:flex; gap:8px; justify-content:flex-end; margin-top:12px;">
      <button id="modal-cancel" type="button" style="padding:6px 10px; border-radius:6px;">Cancelar</button>
      <button id="modal-confirm" type="button" style="background:#337ab7;color:#fff;padding:6px 12px;border-radius:6px;">Guardar</button>
    </div>
  </div>
</div>

<script>
// Variables para control de envío pendiente
let pendingFormData = null;
let pendingForm = null;

function construirExpedienteString() {
  const expExist = document.getElementById('expediente_existente') ? document.getElementById('expediente_existente').value : '';
  if (expExist) return expExist;
  const p1 = (document.getElementById('expediente_1') || {}).value || '';
  const p2 = (document.getElementById('expediente_2') || {}).value || '';
  const p3 = (document.getElementById('expediente_3') || {}).value || '';
  if (!p1 && !p2 && !p3) return '--';
  return `${p1}-${p2}-${p3}`;
}

function obtenerResumenDeposito(fd) {
  let depositos = [];
  if (fd && typeof fd.getAll === 'function') {
    try { depositos = fd.getAll('txt_nro_deposito[]').map(s => (s||'').toString().trim()).filter(s=>s!==''); } catch(e){ depositos = []; }
  } else {
    const elems = document.querySelectorAll('input[name="txt_nro_deposito[]"]');
    elems.forEach(i=> { const v = (i.value||'').trim(); if (v) depositos.push(v); });
  }
  return depositos.length ? depositos[0] : 'Sin número';
}

function mostrarConfirmModal(fd, form) {
  pendingFormData = fd;
  pendingForm = form;
  document.getElementById('modal-expediente').textContent = construirExpedienteString();
  document.getElementById('modal-deposito').textContent = obtenerResumenDeposito(fd);
  document.getElementById('modal-observacion').value = '';
  document.getElementById('confirmRegisterModal').style.display = 'flex';
  document.getElementById('modal-observacion').focus();
}

function ocultarConfirmModal() {
  document.getElementById('confirmRegisterModal').style.display = 'none';
  pendingFormData = null; pendingForm = null;
}

document.getElementById('modal-cancel').addEventListener('click', function(){ ocultarConfirmModal(); });

document.getElementById('modal-confirm').addEventListener('click', async function(){
  if (!pendingFormData || !pendingForm) { ocultarConfirmModal(); return; }
  const obs = document.getElementById('modal-observacion').value.trim();
  // añadir observación al FormData
  pendingFormData.set('observacion', obs);

  // Enviar y reutilizar la lógica de respuesta (similar al submit original)
  try {
    const resp = await fetch("../code_back/back_deposito_agregar.php", { method: "POST", body: pendingFormData });
    const text = await resp.text();
    let json;
    try { json = JSON.parse(text); } catch(e) { console.error('Resp no JSON', text); Swal.fire("❌ Error","Respuesta inesperada del servidor. Revisa la consola.","error"); ocultarConfirmModal(); return; }

    if (json.success) {
      Swal.fire("✅ Éxito", json.message || "Registrado", "success");
      // replicar el reset que estaba en el submit
      const form = pendingForm;
      form.reset();
      listaCoincidencias.innerHTML = "";
      listaCoincidencias.style.display = "none";
      wrapCrearNuevo.style.display = "none";
      detalles.style.display = "none";
      nuevoBeneficioCont.style.display = "none";
      expedientesExistentesWrap.style.display = "none";
      expedienteInputs.style.display = "block";
      setExpedienteReadonly(false);
      if (inputHiddenBenef) { inputHiddenBenef.setAttribute("name","beneficiario"); inputHiddenBenef.value = ""; }
      if (expedienteExistenteHidden) expedienteExistenteHidden.value = "";
      document.getElementById("depositos-container").innerHTML = `
        <div class="form-row">
          <input type="text" name="txt_nro_deposito[]" placeholder="Número de Depósito (opcional)" maxlength="13" autocomplete="off" style="flex:1;">
          <button type="button" onclick="agregarCampoDeposito()">➕</button>
        </div>
      `;
      if (fotoInput) { fotoInput.value = ""; fotoInput.removeAttribute('name'); previewWrap.style.display = "none"; previewImg.src = ""; }
      if (fotoInputExistente) { fotoInputExistente.value = ""; fotoInputExistente.removeAttribute('name'); }
      if (labelFotoExistente) labelFotoExistente.style.display = "none";
      if (btnActualizarFoto) btnActualizarFoto.style.display = "none";
      if (btnCancelarActualizarFoto) btnCancelarActualizarFoto.style.display = "none";
      if (objectURL_existente) { URL.revokeObjectURL(objectURL_existente); objectURL_existente = null; }
      if (fotoPreviewPair) fotoPreviewPair.style.display = "none";
      if (fotoExistenteImg) { fotoExistenteImg.style.display = "none"; fotoExistenteImg.src = ""; }
      if (previewExistenteImg) { previewExistenteImg.style.display = "none"; previewExistenteImg.src = ""; }
      if (fotoExistenteEmpty) fotoExistenteEmpty.style.display = "none";
      if (previewExistenteEmpty) previewExistenteEmpty.style.display = "none";
      if (telefonoBenef) { telefonoBenef.disabled = true; telefonoBenef.removeAttribute('name'); }

      ocultarConfirmModal();
    } else {
      const extra = json.post_keys ? (" Keys: " + json.post_keys.join(', ')) : "";
      Swal.fire("❌ Error", (json.message || "Error al registrar.") + extra, "error");
      ocultarConfirmModal();
    }
  } catch (err) {
    console.error(err);
    Swal.fire("❌ Error inesperado", "Revisa la consola para más detalles", "error");
    ocultarConfirmModal();
  }
});
</script>
</body>
</html>