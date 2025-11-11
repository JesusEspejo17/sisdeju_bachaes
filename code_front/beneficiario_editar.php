<?php
include("../code_back/conexion.php");

if (!isset($_GET["documento"])) {
    echo "Documento no proporcionado.";
    exit;
}

$documento = $_GET["documento"];

// Obtener datos del beneficiario (traemos foto_documento)
$sql = "SELECT p.*, d.tipo_documento, COALESCE(p.foto_documento, '') AS foto_documento
        FROM persona p
        INNER JOIN documento d ON p.id_documento = d.id_documento
        WHERE p.documento = '" . mysqli_real_escape_string($cn, $documento) . "'";
$res = mysqli_query($cn, $sql);
$beneficiario = mysqli_fetch_assoc($res);

if (!$beneficiario) {
    echo "Beneficiario no encontrado.";
    exit;
}

// Obtener tipos de documento
$sql_documentos = "SELECT * FROM documento";
$documentos = mysqli_query($cn, $sql_documentos);
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Editar Beneficiario</title>
  <link rel="stylesheet" href="../css/crear_usuario.css">
  <style>
    

    /* Foto: caja fija para que no rompa layout */
    .foto-box { display:flex; flex-direction:column; align-items:flex-start; gap:8px; }
    .foto-viewport { width:160px; height:160px; border:1px solid #ddd; background:#fff; display:flex; align-items:center; justify-content:center; overflow:hidden; border-radius:4px; }
    #foto-actual-img, #preview-img { width:100%; height:100%; object-fit:contain; display:block; }

    .small-muted { font-size:0.9em; color:#666; }
    .btn { padding:7px 10px; border:1px solid #ccc; background:#f6f6f6; cursor:pointer; border-radius:4px; }
    .btn-primary { background:#e7f4ff; border-color:#bfe1ff; }
    label strong { display:block; margin-bottom:4px; }

    /* preview-wrap inicialmente oculto y con caja fija */
    #preview-wrap { margin-top:6px; display:none; }
    /* evitar que el preview haga la fila más alta de la cuenta */
    .foto-controls { display:flex; gap:8px; align-items:center; margin-top:6px; }
  </style>
</head>
<body>

<div class="main-container">
  <h1>Editar Beneficiario</h1>

  <form action="../code_back/back_beneficiario_editar.php" method="post" enctype="multipart/form-data" id="form-editar-beneficiario">
    <input type="hidden" name="documento_original" value="<?php echo htmlspecialchars($beneficiario['documento'], ENT_QUOTES, 'UTF-8'); ?>">

    <!-- Tipo de documento -->
    <div class="form-row">
      <input type="text" 
            value="<?php echo htmlspecialchars($beneficiario['tipo_documento'], ENT_QUOTES, 'UTF-8'); ?>" 
            readonly>
      <input type="hidden" 
            name="cbo_documento" 
            value="<?php echo (int)$beneficiario['id_documento']; ?>">
    </div>

    <!-- Documento -->
      <div class="form-row">
        <input type="text" 
              name="txt_dni" 
              id="dni_ruc" 
              value="<?php echo htmlspecialchars($beneficiario['documento'], ENT_QUOTES, 'UTF-8'); ?>" 
              placeholder="Documento" 
              required 
              readonly>
      </div>


    <!-- Nombre y Apellido -->
    <div class="form-row">
      <input type="text" name="txt_nombre" 
            value="<?php echo strtoupper(htmlspecialchars($beneficiario['nombre_persona'], ENT_QUOTES, 'UTF-8')); ?>" required autocomplete="off">
      <input type="text" name="txt_apellidos" 
            value="<?php echo strtoupper(htmlspecialchars($beneficiario['apellido_persona'], ENT_QUOTES, 'UTF-8')); ?>" required autocomplete="off">
    </div>


    <!-- Correo y Teléfono -->
    <div class="form-row">
      <input type="email" name="txt_email" value="<?php echo htmlspecialchars($beneficiario['correo_persona'], ENT_QUOTES, 'UTF-8'); ?>" placeholder="Correo" autocomplete="off">
      <input type="tel" name="txt_telefono" value="<?php echo htmlspecialchars($beneficiario['telefono_persona'], ENT_QUOTES, 'UTF-8'); ?>" placeholder="Teléfono" minlength="9" maxlength="9" autocomplete="off">
    </div>

    <!-- Dirección -->
    <div class="form-row">
      <input type="text" name="txt_direccion" value="<?php echo htmlspecialchars($beneficiario['direccion_persona'], ENT_QUOTES, 'UTF-8'); ?>" placeholder="Dirección" autocomplete="off">
    </div>

    <!-- FOTO: mostrar existente y permitir reemplazar (preview fijo) -->
    <div class="form-row" style="align-items:flex-start;">
      <div class="foto-box">
        <label><strong>Foto actual</strong></label>
        <div class="foto-viewport" aria-hidden="true">
          <?php if (!empty($beneficiario['foto_documento'])):
              $ruta_foto = "../" . ltrim($beneficiario['foto_documento'], "/");
          ?>
            <img id="foto-actual-img" src="<?php echo htmlspecialchars($ruta_foto, ENT_QUOTES, 'UTF-8'); ?>" alt="Foto actual">
          <?php else: ?>
            <div id="foto-actual-placeholder" style="color:#999; font-size:0.9em;">Sin foto</div>
          <?php endif; ?>
        </div>
        <small class="small-muted" id="foto-actual-nombre"><?php echo !empty($beneficiario['foto_documento']) ? htmlspecialchars(basename($beneficiario['foto_documento']), ENT_QUOTES, 'UTF-8') : ''; ?></small>
      </div>

      <div style="width:22px;"></div>

      <div class="foto-box" style="flex:1;">
        <label><strong>Subir nueva foto (previsualización automática)</strong></label>
        <input type="file" name="foto_documento" id="foto_documento" accept="image/png, image/jpeg" class="">
        <small class="small-muted">Máx. 5 MB. PNG/JPG</small>

        <div id="preview-wrap" class="foto-viewport" aria-hidden="true">
          <img id="preview-img" src="" alt="Preview">
        </div>


      </div>
    </div>

    <!-- Hidden: ruta existente (para backend) -->
    <input type="hidden" name="foto_existente_path" value="<?php echo htmlspecialchars($beneficiario['foto_documento'], ENT_QUOTES, 'UTF-8'); ?>">

    <!-- Botón (sin link de volver) -->
    <div class="form-row">
      <input type="submit" value="Guardar cambios" class="btn btn-primary">
    </div>
  </form>
</div>

<!-- Script: preview con createObjectURL + evita que el layout se rompa -->
<script>
(function(){
  // refs iniciales (nota: el input puede ser reemplazado al cancelar)
  let fotoInput = document.getElementById('foto_documento');
  const previewWrap = document.getElementById('preview-wrap');
  let previewImg = document.getElementById('preview-img');
  const btnCancelar = document.getElementById('btn-cancelar-nueva-foto');
  const fotoActualImg = document.getElementById('foto-actual-img');
  const fotoActualPlaceholder = document.getElementById('foto-actual-placeholder');
  const previewInfo = document.getElementById('preview-info');

  let currentObjectURL = null;

  // Asegurar que previewImg exista (por si accidentalmente no está)
  if (!previewImg && previewWrap) {
    previewImg = document.createElement('img');
    previewImg.id = 'preview-img';
    previewWrap.appendChild(previewImg);
  }

  function limpiarPreview() {
    if (currentObjectURL) {
      try { URL.revokeObjectURL(currentObjectURL); } catch(e){/* ignore */ }
      currentObjectURL = null;
    }
    if (previewImg) { previewImg.src = ''; previewImg.style.display = 'none'; }
    if (previewWrap) previewWrap.style.display = 'none';
    if (previewInfo) { previewInfo.style.display = 'none'; previewInfo.textContent = ''; }
    if (fotoActualImg) fotoActualImg.style.opacity = '';
    if (fotoActualPlaceholder) fotoActualPlaceholder.style.opacity = '';
    // asegurar limpiar valor del input (si aún existe)
    if (fotoInput) {
      try {
        fotoInput.value = '';
      } catch(e) {
        // algunos navegadores no permiten asignar, en ese caso clonamos
        const clone = fotoInput.cloneNode(true);
        fotoInput.parentNode.replaceChild(clone, fotoInput);
        fotoInput = clone;
        initFotoInputListener(); // volver a añadir listener al nuevo input
      }
    }
  }

  function showPreviewFromFile(file) {
    const allowed = ['image/png','image/jpeg'];
    const maxSize = 5 * 1024 * 1024; // 5MB
    if (!allowed.includes(file.type)) {
      alert('Solo se permiten imágenes PNG o JPG.');
      limpiarPreview();
      return;
    }
    if (file.size > maxSize) {
      alert('La imagen no puede superar 5 MB.');
      limpiarPreview();
      return;
    }

    // revocar previo
    if (currentObjectURL) {
      try { URL.revokeObjectURL(currentObjectURL); } catch(e){/* ignore */ }
      currentObjectURL = null;
    }

    // intentar createObjectURL; si falla, fallback a FileReader
    try {
      currentObjectURL = URL.createObjectURL(file);
      previewImg.src = currentObjectURL;
      previewImg.style.display = 'block';
      // Mostrar caja de preview con el mismo display que .foto-viewport (flex)
      if (previewWrap) previewWrap.style.display = 'flex';
      if (previewInfo) { previewInfo.style.display = 'inline'; previewInfo.textContent = file.name + ' (' + Math.round(file.size/1024) + ' KB)'; }
      if (fotoActualImg) fotoActualImg.style.opacity = '0.28';
      if (fotoActualPlaceholder) fotoActualPlaceholder.style.opacity = '0.28';
    } catch (err) {
      // fallback FileReader
      try {
        const reader = new FileReader();
        reader.onload = function(e) {
          previewImg.src = e.target.result;
          previewImg.style.display = 'block';
          if (previewWrap) previewWrap.style.display = 'flex';
          if (previewInfo) { previewInfo.style.display = 'inline'; previewInfo.textContent = file.name + ' (' + Math.round(file.size/1024) + ' KB)'; }
          if (fotoActualImg) fotoActualImg.style.opacity = '0.28';
          if (fotoActualPlaceholder) fotoActualPlaceholder.style.opacity = '0.28';
        };
        reader.readAsDataURL(file);
      } catch(e2) {
        console.error('Imposible previsualizar archivo:', e2);
        alert('No se pudo previsualizar la imagen.');
        limpiarPreview();
      }
    }
  }

  function onInputChange(e) {
    const file = (e.target.files && e.target.files[0]) ? e.target.files[0] : null;
    if (!file) {
      limpiarPreview();
      return;
    }
    showPreviewFromFile(file);
  }

  function initFotoInputListener() {
    if (!fotoInput) return;
    fotoInput.removeEventListener('change', onInputChange);
    fotoInput.addEventListener('change', onInputChange);
  }

  // Inicializar listener (por si ya existía o se reemplaza)
  initFotoInputListener();

  // Cancelar selección: reconstruir input para garantizar que se borre el FileList
  if (btnCancelar) {
    btnCancelar.addEventListener('click', function() {
      limpiarPreview();
      // Si el navegador no permite setear value='', forzamos el replace del input
      if (fotoInput) {
        try {
          fotoInput.value = '';
        } catch(err) {
          const clone = fotoInput.cloneNode(true);
          fotoInput.parentNode.replaceChild(clone, fotoInput);
          fotoInput = clone;
        }
      }
      // volver a re-attach listener al input actual
      fotoInput = document.getElementById('foto_documento');
      initFotoInputListener();
    });
  }

  // doble-check al enviar
  const form = document.getElementById('form-editar-beneficiario');
  if (form) {
    form.addEventListener('submit', function(e){
      const f = fotoInput && fotoInput.files && fotoInput.files[0];
      if (f) {
        const allowed = ['image/png','image/jpeg'];
        const maxSize = 5 * 1024 * 1024;
        if (!allowed.includes(f.type)) { e.preventDefault(); alert('Tipo de archivo no permitido.'); return false; }
        if (f.size > maxSize) { e.preventDefault(); alert('Archivo muy grande (max 5MB).'); return false; }
      }
    });
  }

  // liberar objectURL al salir
  window.addEventListener('beforeunload', function(){
    if (currentObjectURL) {
      try { URL.revokeObjectURL(currentObjectURL); } catch(e){/* ignore */ }
      currentObjectURL = null;
    }
  });

})();
</script>

</body>
</html>
