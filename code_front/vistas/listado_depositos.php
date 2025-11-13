<?php
// Sesión y conexión
if (session_status() === PHP_SESSION_NONE) session_start();
include("../code_back/conexion.php");

if (!isset($_SESSION['documento'], $_SESSION['rol'])) {
  echo "<script>alert('Sesión no iniciada.'); window.location='../login.php';</script>";
  exit;
}

$usuarioActual = $_SESSION['documento'];
$idRol         = $_SESSION['rol'];

// ---------------- Leer filtros desde GET ----------------
$filtroEstado = isset($_GET['filtroEstado']) ? $_GET['filtroEstado'] : 'todos'; // todos|pendientes|porentregar|entregados|recojo
$filtroTipo   = isset($_GET['filtroTipo'])   ? $_GET['filtroTipo']   : 'expediente'; // expediente|deposito|dni|nombre
$filtroTexto  = isset($_GET['filtroTexto'])  ? trim($_GET['filtroTexto'])  : '';
$filtroFechaEnvio = isset($_GET['filtroFechaEnvio']) ? trim($_GET['filtroFechaEnvio']) : ''; // YYYY-MM-DD or ''

// perPage: 20,50,100,all
$perPageRaw = isset($_GET['perPage']) ? $_GET['perPage'] : '20';
$perPage = ($perPageRaw === 'all') ? 0 : intval($perPageRaw);
if ($perPage < 0) $perPage = 20;

// ---------------- Construir WHERE (server-side) ----------------
$whereClauses = [];

// Excluir depósitos anulados (estado 10) por defecto, EXCEPTO si se filtra específicamente por anulados
$mostrarAnulados = ($filtroEstado === 'anulados');
if (!$mostrarAnulados) {
  $whereClauses[] = "dj.id_estado != 10";
}

// Filtro especial para MAU: si NO hay filtro de estado específico y es rol MAU (2),
// mostrar primero los observados (estado 11), luego el resto
$filtroObservadosMAU = false;
if ($idRol === 2 && $filtroEstado === 'todos') {
  // Verificar si hay depósitos OBSERVADOS (solo estado 11, no 12)
  $checkObs = mysqli_query($cn, "SELECT COUNT(*) AS total FROM deposito_judicial WHERE estado_observacion = 11 AND id_estado != 10");
  $rowObs = mysqli_fetch_assoc($checkObs);
  if ($rowObs && (int)$rowObs['total'] > 0) {
    // Hay observados pendientes: filtrar solo observados (estado 11)
    $whereClauses[] = "dj.estado_observacion = 11";
    $filtroObservadosMAU = true;
  }
  // Si no hay observados pendientes (11), mostrar todos (comportamiento normal)
}

// Rol específico (mantener tu condicion original)
if ($idRol === 3) {
  $whereClauses[] = "dj.documento_secretario = '" . mysqli_real_escape_string($cn, $usuarioActual) . "' AND dj.id_estado != 4";
}

// filtro por estado
if ($filtroEstado !== 'todos') {
  switch ($filtroEstado) {
    case 'pendientes':   $whereClauses[] = "dj.id_estado IN (3,5,6,8,9)"; break;
    case 'porentregar':  $whereClauses[] = "dj.id_estado IN (2,7)"; break; // ahora incluye 2 y 7
    case 'entregados':   $whereClauses[] = "dj.id_estado = 1"; break;
    case 'recojo':       $whereClauses[] = "dj.id_estado = 6"; break;
    case 'notientrega':  $whereClauses[] = "dj.id_estado = 7"; break;
    case 'notirecojo':   $whereClauses[] = "dj.id_estado = 5"; break;
    case 'notirepro':    $whereClauses[] = "dj.id_estado = 8"; break;
    case 'repro':        $whereClauses[] = "dj.id_estado = 9"; break;
    case 'anulados':     $whereClauses[] = "dj.id_estado = 10"; break;
    case 'observados':   $whereClauses[] = "dj.estado_observacion = 11"; break;
    case 'observados_atendidos': $whereClauses[] = "dj.estado_observacion = 12"; break;
  }
}

// filtro por fecha de envio (comparar solo la fecha)
if ($filtroFechaEnvio !== '') {
  // validez básica YYYY-MM-DD
  if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $filtroFechaEnvio)) {
    $whereClauses[] = "DATE(dj.fecha_notificacion_deposito) = '" . mysqli_real_escape_string($cn, $filtroFechaEnvio) . "'";
  }
}

// filtro por tipo + texto
if ($filtroTexto !== '') {
  $t = mysqli_real_escape_string($cn, $filtroTexto);
  switch ($filtroTipo) {
    case 'expediente':
      $whereClauses[] = "(dj.n_expediente LIKE '%{$t}%')";
      break;
    case 'deposito':
      $whereClauses[] = "(dj.n_deposito LIKE '%{$t}%')";
      break;
    case 'dni':
      $whereClauses[] = "(bene.documento LIKE '%{$t}%')";
      break;
    case 'secretario':
      $whereClauses[] = "(
        sec.nombre_persona LIKE '%{$t}%' 
        OR sec.apellido_persona LIKE '%{$t}%'
        OR CONCAT(sec.nombre_persona, ' ', sec.apellido_persona) LIKE '%{$t}%'
      )";
      break;

    case 'nombre':
    default:
      // buscar por nombre concatenado de beneficiario
      $whereClauses[] = "(CONCAT(bene.nombre_persona, ' ', bene.apellido_persona) LIKE '%{$t}%')";
      break;
  }
}

// armar WHERE final
$where = '';
if (count($whereClauses) > 0) {
  $where = 'WHERE ' . implode(' AND ', $whereClauses);
}

// ---------------- Base SQL (sin LIMIT por ahora). Mantiene ORDER BY ----------------
$sqlBase = "
  SELECT 
    dj.n_expediente,
    dj.id_deposito,
    dj.n_deposito,
    dj.documento_beneficiario AS documento_beneficiario_deposito,
    dj.orden_pdf,
    dj.resolucion_pdf,
    dj.fecha_ingreso_deposito,
    dj.fecha_recojo_deposito,
    dj.fecha_notificacion_deposito,
    dj.id_estado,
    dj.estado_observacion,
    dj.motivo_observacion,
    dj.fecha_observacion,
    dj.fecha_atencion_observacion,
    e.nombre_estado,
    CONCAT(sec.nombre_persona,' ',sec.apellido_persona) AS nombre_secretario,
    bene.telefono_persona AS telefono_beneficiario,
    bene.foto_documento AS foto_beneficiario,
    j.nombre_juzgado,
    bene.documento AS dni_beneficiario,
    CONCAT(bene.nombre_persona,' ',bene.apellido_persona) AS nombre_beneficiario,
    (
      SELECT hd.fecha_historial_deposito 
      FROM historial_deposito hd 
      WHERE 
        hd.id_deposito = dj.id_deposito 
        AND hd.tipo_evento = 'CAMBIO_ESTADO'
        AND hd.estado_nuevo = 1
      ORDER BY hd.fecha_historial_deposito ASC
      LIMIT 1
    ) AS fecha_finalizacion,
    (
      SELECT hd2.fecha_historial_deposito
      FROM historial_deposito hd2
      WHERE
        hd2.id_deposito = dj.id_deposito
        AND hd2.tipo_evento = 'CAMBIO_ESTADO'
        AND hd2.estado_nuevo IN (2,7)
      ORDER BY hd2.fecha_historial_deposito DESC
      LIMIT 1
    ) AS fecha_atencion,
    (
      -- documento del usuario que realizó la entrega (estado_nuevo = 1)
      SELECT hd3.documento_usuario
      FROM historial_deposito hd3
      WHERE
        hd3.id_deposito = dj.id_deposito
        AND hd3.tipo_evento = 'CAMBIO_ESTADO'
        AND hd3.estado_nuevo = 1
      ORDER BY hd3.fecha_historial_deposito ASC
      LIMIT 1
    ) AS documento_entrega,
    (
      -- intentar resolver el nombre completo del usuario que realizó la entrega
      SELECT CONCAT(p.nombre_persona, ' ', p.apellido_persona)
      FROM historial_deposito hd4
      LEFT JOIN persona p ON p.documento = hd4.documento_usuario
      WHERE
        hd4.id_deposito = dj.id_deposito
        AND hd4.tipo_evento = 'CAMBIO_ESTADO'
        AND hd4.estado_nuevo = 1
      ORDER BY hd4.fecha_historial_deposito ASC
      LIMIT 1
    ) AS usuario_entrega
  FROM deposito_judicial dj
  JOIN estado e           ON dj.id_estado = e.id_estado
  JOIN persona sec        ON sec.documento = dj.documento_secretario
  JOIN expediente ex      ON ex.n_expediente = dj.n_expediente
  JOIN juzgado j          ON ex.id_juzgado = j.id_juzgado
  LEFT JOIN persona bene  ON bene.documento = dj.documento_beneficiario
  $where
  ORDER BY
    CASE 
      WHEN dj.id_estado IN (3,5,6,8,9) THEN 0
      WHEN dj.id_estado IN (2,7) THEN 1
      ELSE 2
    END,
    dj.fecha_ingreso_deposito ASC
";

// ---------------- Pagination setup ----------------
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;

// total count (usamos la misma condición WHERE para coherencia)
$countSql = "SELECT COUNT(*) AS total 
FROM deposito_judicial dj
  JOIN expediente ex ON ex.n_expediente = dj.n_expediente
  JOIN persona sec   ON sec.documento = dj.documento_secretario
  LEFT JOIN persona bene ON bene.documento = dj.documento_beneficiario
  $where";

$countRes = mysqli_query($cn, $countSql);
if (!$countRes) {
  die("Error en count: " . mysqli_error($cn));
}
$countRow = mysqli_fetch_assoc($countRes);
$totalRows = (int)$countRow['total'];

// si perPage == 0 => mostrar todos (sin LIMIT)
if ($perPage === 0) {
  $perPageUsed = $totalRows > 0 ? $totalRows : 1;
  $totalPages = 1;
} else {
  $perPageUsed = $perPage;
  $totalPages = (int)ceil($totalRows / $perPageUsed);
  if ($totalPages < 1) $totalPages = 1;
}

if ($page > $totalPages) $page = $totalPages;
$offset = ($page - 1) * $perPageUsed;

// ----------------- NUEVO: inicializar contador de fila -----------------
$rowNumber = $offset + 1; // esto asegura numeración correcta según la página
// ---------------------------------------------------------------------

// Append LIMIT/OFFSET to main query (si perPage === 0 omitimos LIMIT)
if ($perPage === 0) {
  $sqlPaged = $sqlBase;
} else {
  $sqlPaged = $sqlBase . " LIMIT " . intval($perPageUsed) . " OFFSET " . intval($offset);
}

$resultado = mysqli_query($cn, $sqlPaged) or die(mysqli_error($cn));

// rango mostrado
$showFrom = ($totalRows === 0) ? 0 : ($offset + 1);
$showTo = ($perPage === 0) ? $totalRows : min($offset + $perPageUsed, $totalRows);
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Lista de Depósitos</title>
  <link rel="stylesheet" href="../css/crear_usuario.css">
  <link rel="stylesheet" href="../css/deposito_ventana.css">
  <link rel="stylesheet" href="../css/menu_admin.css">
  <script src="../js/sweetalert2.all.min.js"></script>
  <link rel="stylesheet" href="../css/css_admin/all.min.css">
  <style>
    /* pequeño ajuste para que la fila de filtros quepa bien */
    .filters-row { display:flex; gap:10px; align-items:center; flex-wrap:wrap; }
    .filters-row > div { display:flex; flex-direction:column; }
    /* paginación */
    .pagination { display:flex; gap:6px; align-items:center; justify-content:flex-end; margin:10px 0; flex-wrap:wrap; }
    .pagination a, .pagination span { padding:6px 10px; border-radius:6px; text-decoration:none; background:#f2f2f2; color:#333; border:1px solid #e0e0e0; }
    .pagination a:hover { background:#e9e9e9; }
    .pagination .current { background:#840000; color:#fff; border-color:#5a0000; }
    .pagination .disabled { opacity:0.5; pointer-events:none; }
    .page-info { font-size:0.95rem; color:#555; margin-right:auto; align-self:center; }
    /* tabla */
    table#tabla-depositos td, table#tabla-depositos th { padding:8px; vertical-align:middle; }
    .acciones i { margin-right:8px; cursor:pointer; }
    .filters-actions { display:flex; gap:8px; align-items:flex-end; }
    #filtroEstado option.bold-option { font-weight: 700; }
    /* Alineación para la columna de número */
    table#tabla-depositos .col-number { width: 48px; text-align:center; font-weight:600; }
    /* Estilo para filas anuladas */
    table#tabla-depositos tr.deposito-anulado {
      background-color: #f8d7da !important;
      opacity: 0.75;
    }
    table#tabla-depositos tr.deposito-anulado td {
      color: #721c24;
      text-decoration: line-through;
    }
    table#tabla-depositos tr.deposito-anulado .col-number {
      text-decoration: none;
    }
    .whatsapp-icon {
      color: #000000 !important;
    }
    /* Opcional: cambiar hover, tamaño, etc. */
    .whatsapp-icon:hover {
      color: #000000 !important;
    }
    /* Icono de check verde para atender observación */
    .atender-observacion-icon {
      transition: transform 0.2s ease, color 0.2s ease;
    }
    .atender-observacion-icon:hover {
      transform: scale(1.2);
      color: #218838 !important; /* Verde más oscuro al hover */
    }
  </style>
</head>
<body>
  <div class="main-container">
    <h1>Depósitos Judiciales Registrados</h1>

    <!-- filtros + botón masivo (sólo rol 2) -->
    <div style="margin-bottom: 15px;">
      <div class="filters-row">
        <div>
          <label for="filtroEstado"><strong>Filtrar por estado:</strong></label>
          <select id="filtroEstado">
            <option value="todos" class="bold-option" <?= $filtroEstado==='todos' ? 'selected' : '' ?>>Todos</option>
            <option value="pendientes" class="bold-option" <?= $filtroEstado==='pendientes' ? 'selected' : '' ?>>Pendientes de Atención</option>
            <option value="porentregar" class="bold-option" <?= $filtroEstado==='porentregar' ? 'selected' : '' ?>>Por Entregar</option>
            <option value="entregados" class="bold-option" <?= $filtroEstado==='entregados' ? 'selected' : '' ?>>Entregados</option>
            <option value="anulados" class="bold-option" <?= $filtroEstado==='anulados' ? 'selected' : '' ?>>Anulados</option>
            <option value="observados" class="bold-option" <?= $filtroEstado==='observados' ? 'selected' : '' ?>>Observados</option>
            <option value="observados_atendidos" class="bold-option" <?= $filtroEstado==='observados_atendidos' ? 'selected' : '' ?>>Observaciones Atendidas</option>
            <option value="recojo" <?= $filtroEstado==='recojo' ? 'selected' : '' ?>>Recojo Notificado</option>
            <option value="notirecojo" <?= $filtroEstado==='notirecojo' ? 'selected' : '' ?>>Notificar Recojo</option>
            <option value="notientrega" <?= $filtroEstado==='notientrega' ? 'selected' : '' ?>>Notificar Entrega</option>
            <option value="notirepro" <?= $filtroEstado==='notirepro' ? 'selected' : '' ?>>Notificar Reprogramación</option>
            <option value="repro" <?= $filtroEstado==='repro' ? 'selected' : '' ?>>Recojos Reprogramados</option>
          </select>
        </div>

        <div>
          <label for="filtroTipo"><strong>Filtrar por:</strong></label>
          <select id="filtroTipo">
            <option value="expediente" <?= $filtroTipo==='expediente' ? 'selected' : '' ?>>N° de expediente</option>
            <option value="deposito" <?= $filtroTipo==='deposito' ? 'selected' : '' ?>>N° de depósito</option>
            <option value="dni" <?= $filtroTipo==='dni' ? 'selected' : '' ?>>DNI / documento</option>
            <option value="nombre" <?= $filtroTipo==='nombre' ? 'selected' : '' ?>>Nombre de persona</option>
            <?php if (in_array($_SESSION['rol'], [1,2,4,5,6])): ?>
              <option value="secretario" <?= $filtroTipo==='secretario' ? 'selected' : '' ?>>Secretario</option>
            <?php endif; ?>
          </select>
        </div>

        <div>
          <label for="filtroTexto"><strong>Texto:</strong></label>
          <!-- oninput con debounce: filtra mientras escribís (500ms) -->
          <input type="text" id="filtroTexto" placeholder="Escribe para filtrar..." value="<?= htmlspecialchars($filtroTexto) ?>" autocomplete="off">
        </div>

        <!-- NUEVO: filtro por día para "Fecha envío al secretario" -->
        <div>
          <label for="filtroFechaEnvio"><strong>Filtrar por día (fecha envío)</strong></label>
          <div style="display:flex;align-items:center;gap:5px;">
            <input type="date" id="filtroFechaEnvio" value="<?= htmlspecialchars($filtroFechaEnvio) ?>">
            <button type="button" onclick="limpiarFiltroFecha()" style="padding:2px 6px;">✖</button>
          </div>
          <small style="color:gray;">Mostrar filas acorde al día seleccionado.</small>
        </div>

        <div class="filters-actions" style="margin-left:auto;">
          <?php if ($idRol === 2): ?>
          <button
            id="bulkWhatsappBtn"
            style="
              width: 160px;
              padding: 0.6em 1em;
              background-color: #25D366;
              color: #fff;
              border: none;
              border-radius: 8px;
              box-shadow: 0 2px 6px rgba(0, 0, 0, 0.2);
              font-size: 0.95rem;
              cursor: pointer;
              transition: background-color 0.2s, transform 0.2s;
              white-space: normal;
              word-wrap: break-word;
            "
            onmouseover="this.style.backgroundColor='#1ebe57'; this.style.transform='translateY(-2px)';"
            onmouseout="this.style.backgroundColor='#25D366'; this.style.transform='translateY(0)';"
          >
            📲 Enviar WhatsApp Masivo
          </button>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- BARRA DE PAGINACIÓN + INFO -->
    <div style="display:flex; align-items:center; gap:10px; margin-bottom:6px;">
      <div>
          <label for="perPageSelect"><strong>Mostrar:</strong></label>
          <select id="perPageSelect">
            <option value="20" <?= ($perPageRaw==='20') ? 'selected' : '' ?>>20</option>
            <option value="50" <?= ($perPageRaw==='50') ? 'selected' : '' ?>>50</option>
            <option value="100" <?= ($perPageRaw==='100') ? 'selected' : '' ?>>100</option>
            <option value="all" <?= ($perPageRaw==='all') ? 'selected' : '' ?>>Todos</option>
          </select>
        </div>
      <div id="pageInfoTop" class="page-info">
        Mostrando <strong id="showFromTop"><?= $showFrom ?></strong> – <strong id="showToTop"><?= $showTo ?></strong> de <strong id="totalRowsTop"><?= $totalRows ?></strong>
      </div>

      <div id="paginationTop" class="pagination" aria-label="Paginación">
        <?php
          // construir base URL preservando GET params
          function pageLink($p, $label, $disabled=false, $isCurrent=false) {
            $qs = $_GET;
            $qs['page'] = $p;
            $href = htmlspecialchars($_SERVER['PHP_SELF'] . '?' . http_build_query($qs));
            $class = $disabled ? 'disabled' : ($isCurrent ? 'current' : '');
            if ($disabled) return "<span class=\"$class\">$label</span>";
            if ($isCurrent) return "<span class=\"$class\">$label</span>";
            return "<a href=\"$href\">$label</a>";
          }

          // First / Prev
          echo ($page > 1) ? pageLink(1, '« Primero') : pageLink(1, '« Primero', true);
          echo ($page > 1) ? pageLink($page-1, '‹ Prev') : pageLink($page-1, '‹ Prev', true);

          // mostrar ventana de páginas (ej: 5 páginas alrededor)
          $window = 5;
          $start = max(1, $page - intval($window/2));
          $end = min($totalPages, $start + $window - 1);
          if ($end - $start + 1 < $window) {
            $start = max(1, $end - $window + 1);
          }
          for ($p = $start; $p <= $end; $p++) {
            echo ($p == $page) ? pageLink($p, $p, false, true) : pageLink($p, $p);
          }

          // Next / Last
          echo ($page < $totalPages) ? pageLink($page+1, 'Next ›') : pageLink($page+1, 'Next ›', true);
          echo ($page < $totalPages) ? pageLink($totalPages, 'Último »') : pageLink($totalPages, 'Último »', true);
        ?>
      </div>
    </div>

    <table id="tabla-depositos" border="1" cellpadding="5" cellspacing="0" style="width:100%; text-align:center;">
      <thead>
        <tr>
          <?php if ($idRol === 2): ?>
          <th>Todos<input type="checkbox" id="selectAll"></th>
          <?php endif; ?>

          <!-- NUEVO: columna número -->
          <th class="col-number">#</th>

          <th>Expediente</th>
          <th>Depósito</th>
          <th>Juzgado</th>
          <?php if ($idRol !== 3): ?><th>Secretario</th><?php endif; ?>
          <th>Solicitante</th>
          <th>Fecha envío al secretario</th>
          <th>Fecha de Recojo</th>
          <th>Fecha atención secretario</th>
          <th>Estado</th>
          <th>Fecha entrega beneficiario</th>
          <th>Opciones</th>
        </tr>
      </thead>
      <tbody>
      <?php while ($d = mysqli_fetch_assoc($resultado)):
        $est = (int)$d['id_estado'];
        $estObs = $d['estado_observacion'] ? (int)$d['estado_observacion'] : null;
        // preparar valor ISO-DB para dataset (puede ser NULL)
        $fecha_notif_iso = $d['fecha_notificacion_deposito'] ? $d['fecha_notificacion_deposito'] : '';
        $esAnulado = ($est === 10);
      ?>
        <tr
          class="<?= $esAnulado ? 'deposito-anulado' : '' ?>"
          data-estado="<?= $est ?>"
          <?php if ($estObs !== null): ?>
          data-estado-observacion="<?= $estObs ?>"
          <?php endif; ?>
          data-expediente="<?= htmlspecialchars($d['n_expediente']) ?>"
          data-deposito="<?= htmlspecialchars($d['n_deposito']) ?>"
          data-dni="<?= $d['dni_beneficiario'] ?: '' ?>"
          data-nombre="<?= htmlspecialchars($d['nombre_beneficiario']) ?>"
          data-telefono="<?= $d['telefono_beneficiario'] ?? '' ?>"
          data-fecha-notificacion="<?= htmlspecialchars($fecha_notif_iso) ?>"
          data-fecha-recojo="<?= $d['fecha_recojo_deposito'] ? date('Y-m-d H:i:s', strtotime($d['fecha_recojo_deposito'])) : '' ?>"
        >

          <?php if ($idRol === 2): ?>
          <td>
            <?php if (in_array($est, [5,7,8])): ?>
              <input type="checkbox"
                    class="whatsapp-bulk"
                    data-iddep="<?= (int)$d['id_deposito'] ?>"
                    data-dep="<?= htmlspecialchars($d['n_deposito'], ENT_QUOTES) ?>">
            <?php endif; ?>
          </td>
          <?php endif; ?>

          <!-- IMPRESIÓN DEL NÚMERO DE FILA -->
          <td class="col-number"><?= $rowNumber++ ?></td>

          <td><?= htmlspecialchars($d['n_expediente']) ?></td>
          <td>
            <?= $d['n_deposito']
              ? htmlspecialchars($d['n_deposito'])
              : "--" ?>
          </td>
          <td><?= htmlspecialchars($d['nombre_juzgado']) ?></td>
          <?php if ($idRol !== 3): ?><td><?= htmlspecialchars($d['nombre_secretario']) ?></td><?php endif; ?>
          <td>
            <?= $d['dni_beneficiario']
              ? htmlspecialchars($d['dni_beneficiario'] . ' – ' . $d['nombre_beneficiario'])
              : "<i>Sin beneficiario</i>" ?>
          </td>
          <td>
            <?= $d['fecha_notificacion_deposito']
              ? date("d/m/Y H:i", strtotime($d['fecha_notificacion_deposito']))
              : "--" ?>
          </td>
          <td>
            <?= $d['fecha_recojo_deposito']
              ? date("d/m/Y H:i", strtotime($d['fecha_recojo_deposito']))
              : "--" ?>
          </td>
          <td>
            <?= $d['fecha_atencion']
                ? date("d/m/Y H:i", strtotime($d['fecha_atencion']))
                : "--" ?>
          </td>
          <td>
            <?php
              // Nuevo comportamiento: si estado = 1 mostrar "ENTREGADO - NombreUsuario" si existe
              if ($est === 1) {
                $entregadorNombre = trim((string)($d['usuario_entrega'] ?? ''));
                $entregadorDoc = trim((string)($d['documento_entrega'] ?? ''));
                if ($entregadorNombre !== '') {
                  echo "ENTREGADO - " . htmlspecialchars($entregadorNombre);
                } elseif ($entregadorDoc !== '') {
                  // si no hay nombre, mostrar documento
                  echo "ENTREGADO - " . htmlspecialchars($entregadorDoc);
                } else {
                  // fallback al nombre del estado si no hay datos de quien entregó
                  echo htmlspecialchars($d['nombre_estado']);
                }
              } else {
                echo htmlspecialchars($d['nombre_estado']);
              }
            ?>
          </td>
          <td>
            <?= $est === 1 && $d['fecha_finalizacion']
              ? date("d/m/Y H:i", strtotime($d['fecha_finalizacion']))
              : "--" ?>
          </td>

          <td class="acciones">
            <!-- Deshabilitar todas las acciones para depósitos anulados -->
            <?php if ($est === 10): ?>
              <span style="color: #721c24; font-weight: 600; font-style: italic;">ANULADO</span>
            <?php else: ?>
              <!-- Icono de OBSERVAR para Secretario (rol 3) -->
              <?php if ($idRol === 3 && !in_array($est, [1, 10])): ?>
                <i class="fas fa-exclamation-triangle observar-icon" 
                  data-iddep="<?= (int)$d['id_deposito'] ?>" 
                  style="color: #FF6600; font-size: 18px;"
                  title="Marcar como Observado"></i>
              <?php endif; ?>

              <!-- Icono de CHECK VERDE para MAU (rol 2) cuando estado_observacion=11 -->
              <?php 
                $estObs = $d['estado_observacion'] ? (int)$d['estado_observacion'] : null;
                if ($idRol === 2 && $estObs === 11): 
              ?>
                <i class="fas fa-check-circle atender-observacion-icon" 
                  data-iddep="<?= (int)$d['id_deposito'] ?>" 
                  data-deposito="<?= htmlspecialchars($d['n_deposito'] ?? '', ENT_QUOTES) ?>"
                  data-expediente="<?= htmlspecialchars($d['n_expediente'] ?? '', ENT_QUOTES) ?>"
                  style="color: #28a745; font-size: 20px; cursor: pointer;"
                  title="Marcar como Observación Atendida"></i>
              <?php endif; ?>

              <!-- (misma lógica de acciones que tenías) -->
              <?php if ($idRol === 2 && $est === 4): ?>
                <i class="fas fa-bell notificar-icon"
                  data-dep="<?= $d['id_deposito'] ?>"
                  title="Notificar"></i>
              <?php endif; ?>

            <?php if (in_array($est, [1, 2, 3,5,6,7,8,9])): ?>
              <i class="fas fa-comments chat-icon grande" 
                data-iddep="<?= (int)$d['id_deposito'] ?>" 
                data-ndep="<?= htmlspecialchars($d['n_deposito'] ?? '', ENT_QUOTES) ?>" 
                data-dep="<?= (int)$d['id_deposito'] ?>" 
                data-state="<?= $est ?>" 
                title="Atención del depósito"></i>
            <?php else: ?>
              <i class="fas fa-comments"
                style="color:gray;cursor:not-allowed"
                title="Chat cerrado"></i>
            <?php endif; ?>

            <?php
              if ($idRol === 3 && !empty($d['foto_beneficiario'])):
                  $fotoPublica = '/Sistemas/SISDEJU/' . ltrim($d['foto_beneficiario'], '/');
              ?>
                  <a href="<?= htmlspecialchars($fotoPublica, ENT_QUOTES) ?>" target="_blank" title="Ver o descargar foto del beneficiario" style="text-decoration:none;">
                    <i class="fas fa-image" style="font-size:1.4em;color:#444;cursor:pointer;margin-right:6px;"></i>
                  </a>
              <?php
              endif;
            ?>

            <?php if (!empty($d['orden_pdf'])): ?>
              <a href="/Sistemas/SISDEJU/<?= htmlspecialchars($d['orden_pdf'], ENT_QUOTES) ?>" target="_blank" class="fas fa-file-pdf" title="Ver Orden PDF" style="margin-left:8px;font-size:1.4em;color:#d9534f;"></a>
            <?php endif; ?>

            <?php if (!empty($d['resolucion_pdf'])): ?>
              <a href="/Sistemas/SISDEJU/<?= htmlspecialchars($d['resolucion_pdf'], ENT_QUOTES) ?>" target="_blank" class="fas fa-file-alt resolucion-icon" title="Ver Resolución" style="margin-left:6px;font-size:1.4em;color:#337ab7; text-decoration:none;"></a>
            <?php endif; ?>

            <?php if (in_array($idRol, [1, 2]) && in_array($est, [2,5,6,7,8,9])): ?>
              <i class="fab fa-whatsapp whatsapp-icon" data-iddep="<?= (int)$d['id_deposito'] ?>" data-ndep="<?= htmlspecialchars($d['n_deposito'] ?? '', ENT_QUOTES) ?>" data-dep="<?= (int)$d['id_deposito'] ?>" style="margin-left:8px; margin-top:8px; font-size:1.8em; color:#25D366; cursor:pointer;" title="Notificar por WhatsApp"></i>
            <?php endif; ?>

            <?php if (in_array($idRol, [1, 2, 3])): ?>
              <?php if (in_array($est, [1, 2])): ?>
                <i class="fas fa-pen" style="color: gray; cursor: not-allowed;" title="No editable en este estado"></i>
              <?php else: ?>
                <i class="fas fa-pen edit-icon" title="Editar" data-iddep="<?= (int)$d['id_deposito'] ?>" data-ndep="<?= htmlspecialchars($d['n_deposito'] ?? '', ENT_QUOTES) ?>" data-dep="<?= (int)$d['id_deposito'] ?>" data-exp="<?= htmlspecialchars($d['n_expediente']) ?>" onclick="abrirEdicionDeposito(this)"></i>
              <?php endif; ?>
            <?php endif; ?>

            <?php if (in_array($idRol, [1, 2, 3])): ?>
              <?php if ($est === 1): ?>
                <i class="fas fa-trash-alt" style="color: gray; cursor: not-allowed; margin-left:8px;" title="No se puede anular un depósito entregado"></i>
              <?php else: ?>
                <i class="fas fa-trash-alt anular-icon" title="Anular depósito" data-iddep="<?= (int)$d['id_deposito'] ?>" data-ndep="<?= htmlspecialchars($d['n_deposito'] ?? 'Sin número', ENT_QUOTES) ?>" data-exp="<?= htmlspecialchars($d['n_expediente']) ?>" style="color: #d9534f; cursor: pointer; margin-left:8px;"></i>
              <?php endif; ?>
            <?php endif; ?>
            
            <?php endif; // Fin del else de if ($est === 10) ?>
          </td>
        </tr>
      <?php endwhile; ?>
      </tbody>
    </table>

    <!-- Repetir paginación abajo (opcional) -->
    <div style="display:flex; align-items:center; gap:10px; margin-top:10px;">
      <div id="pageInfoBottom" class="page-info">
        Mostrando <strong id="showFromBottom"><?= $showFrom ?></strong> – <strong id="showToBottom"><?= $showTo ?></strong> de <strong id="totalRowsBottom"><?= $totalRows ?></strong>
      </div>
      <div id="paginationBottom" class="pagination" aria-label="Paginación inferior">
        <?php
          // Re-usar el mismo fragmento de páginas (puedes refactorizar si querés extraer en función)
          echo ($page > 1) ? pageLink(1, '« Primero') : pageLink(1, '« Primero', true);
          echo ($page > 1) ? pageLink($page-1, '‹ Prev') : pageLink($page-1, '‹ Prev', true);
          for ($p = $start; $p <= $end; $p++) {
            echo ($p == $page) ? pageLink($p, $p, false, true) : pageLink($p, $p);
          }
          echo ($page < $totalPages) ? pageLink($page+1, 'Next ›') : pageLink($page+1, 'Next ›', true);
          echo ($page < $totalPages) ? pageLink($totalPages, 'Último »') : pageLink($totalPages, 'Último »', true);
        ?>
      </div>
    </div>

  </div>

  <!-- Modales (igual) -->
  <div class="modal" id="modal" style="display:none;">
    <div class="modal-content">
      <span class="modal-close" onclick="cerrarModal()">&times;</span>
      <h3 id="modal-titulo"></h3>
      <div id="modal-body"></div>
      <button onclick="cerrarModal()">Cerrar</button>
    </div>
  </div>

  <div class="modal" id="modal-chat" style="display:none;">
    <div class="modal-content" onclick="event.stopPropagation()">
      <span class="modal-close" onclick="cerrarModalChat()">&times;</span>
      <h3 id="chat-titulo"></h3>
      <div id="chat-historial" style="max-height:300px; overflow:auto;"></div>
      <div id="chat-comentario-wrapper" class="form-row" style="margin-top: 5px;">
        <textarea id="chat-comentario" rows="3" placeholder="Comentario..." style="width:100%; text-align: center;"></textarea>
      </div>
      <div id="chat-botones-superiores"></div>
      <button id="chat-cerrar-btn" onclick="cerrarModalChat()">Cerrar</button>
    </div>
  </div>

  <!-- MODAL OBSERVACIÓN -->
  <div class="modal" id="modal-observacion" style="display:none;">
    <div class="modal-content" onclick="event.stopPropagation()">
      <span class="modal-close" onclick="cerrarModalObservacion()">&times;</span>
      <h3>Marcar Depósito como OBSERVADO</h3>
      <p style="margin: 10px 0; font-size: 0.95rem;">
        <strong>Depósito:</strong> <span id="obs-deposito"></span><br>
        <strong>Expediente:</strong> <span id="obs-expediente"></span>
      </p>
      <div style="margin: 15px 0;">
        <label for="obs-motivo" style="display: block; margin-bottom: 5px; font-weight: 600;">
          Motivo de la Observación: <span style="color: red;">*</span>
        </label>
        <textarea 
          id="obs-motivo" 
          rows="4" 
          placeholder="Ingrese el motivo por el cual se marca como observado..." 
          style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px; resize: vertical;"
        ></textarea>
      </div>
      <div style="display: flex; gap: 10px; justify-content: center;">
        <button 
          id="obs-confirmar-btn" 
          style="background-color: #FF6600; color: white; padding: 10px 20px; border: none; border-radius: 5px; cursor: pointer; font-weight: bold;"
        >
          Confirmar Observación
        </button>
        <button 
          onclick="cerrarModalObservacion()" 
          style="background-color: #6c757d; color: white; padding: 10px 20px; border: none; border-radius: 5px; cursor: pointer;"
        >
          Cancelar
        </button>
      </div>
    </div>
  </div>

  <script>
  const usuarioActual = <?= json_encode($usuarioActual) ?>;
  const rolActual = <?= json_encode($idRol) ?>;

  // ---------- AJAX live filter (no reload) ----------
  (function(){
    const debounce = (fn, ms) => { let t; return (...a) => { clearTimeout(t); t = setTimeout(() => fn.apply(this,a), ms); }; };

    function qsFromControls(page = 1) {
      const params = new URLSearchParams();
      const estado = document.getElementById('filtroEstado') ? document.getElementById('filtroEstado').value : 'todos';
      const tipo   = document.getElementById('filtroTipo')   ? document.getElementById('filtroTipo').value   : 'expediente';
      const texto  = document.getElementById('filtroTexto')  ? document.getElementById('filtroTexto').value.trim() : '';
      const fecha  = document.getElementById('filtroFechaEnvio') ? document.getElementById('filtroFechaEnvio').value : '';
      const perPage = document.getElementById('perPageSelect') ? document.getElementById('perPageSelect').value : '20';

      params.set('filtroEstado', estado);
      params.set('filtroTipo', tipo);
      params.set('filtroTexto', texto);
      params.set('filtroFechaEnvio', fecha);
      params.set('perPage', perPage);
      params.set('page', page);
      return params.toString();
    }

    async function fetchHtmlAndReplace(page = 1) {
      const url = window.location.pathname + '?' + qsFromControls(page);

      // Save caret position if text input focused
      const inputTexto = document.getElementById('filtroTexto');
      let selStart = null, selEnd = null;
      const hadFocus = document.activeElement === inputTexto;
      if (inputTexto && hadFocus) {
        selStart = inputTexto.selectionStart;
        selEnd = inputTexto.selectionEnd;
      }

      showLoader(true);
      try {
        const res = await fetch(url, { credentials: 'same-origin' });
        const text = await res.text();

        // parse html
        const parser = new DOMParser();
        const doc = parser.parseFromString(text, 'text/html');

        // replace tbody
        const newTbody = doc.querySelector('#tabla-depositos tbody');
        const oldTbody = document.querySelector('#tabla-depositos tbody');
        if (newTbody && oldTbody) {
          oldTbody.replaceWith(newTbody.cloneNode(true));
        }

        // replace paginations and page-info (top & bottom)
        const ids = ['paginationTop','paginationBottom','pageInfoTop','pageInfoBottom','showFromTop','showToTop','totalRowsTop','showFromBottom','showToBottom','totalRowsBottom'];
        ids.forEach(id=>{
          const newNode = doc.getElementById(id);
          const oldNode = document.getElementById(id);
          if (newNode && oldNode) oldNode.replaceWith(newNode.cloneNode(true));
        });

        // update URL in address bar (no reload)
        window.history.replaceState({}, '', url);

        // emit event so other scripts can re-bind if needed
        document.dispatchEvent(new CustomEvent('depositos:updated'));

      } catch (err) {
        console.error('Error al traer filtros:', err);
      } finally {
        showLoader(false);
        // restore focus+caret
        if (inputTexto && hadFocus) {
          try {
            inputTexto.focus();
            if (selStart !== null && selEnd !== null) {
              inputTexto.setSelectionRange(selStart, selEnd);
            }
          } catch(e) {}
        }
      }
    }

    // small loader in top-right
    function showLoader(show){
      let loader = document.getElementById('ajax-loader-dep');
      if (!loader) {
        loader = document.createElement('div');
        loader.id = 'ajax-loader-dep';
        loader.style.cssText = 'position:fixed; right:10px; top:65px; background:#fff; padding:6px 10px; border-radius:6px; border:1px solid #ddd; z-index:99999; display:none;';
        loader.textContent = 'Cargando...';
        document.body.appendChild(loader);
      }
      loader.style.display = show ? 'block' : 'none';
    }

    // hookup controls
    const elEstado = document.getElementById('filtroEstado');
    const elTipo   = document.getElementById('filtroTipo');
    const elTexto  = document.getElementById('filtroTexto');
    const elFecha  = document.getElementById('filtroFechaEnvio');
    const elPer    = document.getElementById('perPageSelect');

    // Debounced live input (while typing)
    const debouncedFetch = debounce(()=> fetchHtmlAndReplace(1), 350);

    if (elEstado) elEstado.addEventListener('change', ()=> fetchHtmlAndReplace(1));
    if (elTipo)   elTipo.addEventListener('change', ()=> fetchHtmlAndReplace(1));
    if (elFecha)  elFecha.addEventListener('change', ()=> fetchHtmlAndReplace(1));
    if (elPer)    elPer.addEventListener('change', ()=> fetchHtmlAndReplace(1));

    if (elTexto) {
      elTexto.addEventListener('input', debouncedFetch);
      // Enter aplica inmediatamente
      elTexto.addEventListener('keypress', function(e){ if (e.key === 'Enter') { e.preventDefault(); fetchHtmlAndReplace(1); } });
    }

    // intercept clicks on pagination links (top & bottom)
    document.addEventListener('click', function(e){
      const a = e.target.closest('#paginationTop a, #paginationBottom a, .pagination a');
      if (!a) return;
      // Only intercept links that point to the same script (or have page param)
      try {
        const u = new URL(a.href, window.location.origin);
        if (u.pathname === window.location.pathname) {
          e.preventDefault();
          const pageParam = u.searchParams.get('page') || 1;
          fetchHtmlAndReplace(parseInt(pageParam,10));
        }
      } catch(err){}
    });

    // Expose a function for other inline code (keeps compatibility with earlier aplicarFiltros calls)
    window.aplicarFiltros = function(){ fetchHtmlAndReplace(1); };
    window.limpiarFiltroFecha = function() {
      const inputFecha = document.getElementById('filtroFechaEnvio');
      if (inputFecha) inputFecha.value = '';
      fetchHtmlAndReplace(1);
    };

    // Optional: if other scripts rely on page load to attach handlers to new rows,
    // they can listen depositos:updated event. Example:
    // document.addEventListener('depositos:updated', ()=> { /* rebind stuff */ });

    // initial listeners attached; don't auto-fetch on load to avoid double requests.
  })();
  // ---------- fin AJAX live filter ----------

  // ========================================
  // MODAL OBSERVACIÓN - Funciones
  // ========================================
  let observacionIdDeposito = null;

  function abrirModalObservacion(idDeposito, nDeposito, nExpediente) {
    observacionIdDeposito = idDeposito;
    document.getElementById('obs-deposito').textContent = nDeposito || '--';
    document.getElementById('obs-expediente').textContent = nExpediente || '--';
    document.getElementById('obs-motivo').value = '';
    document.getElementById('modal-observacion').style.display = 'flex';
  }

  function cerrarModalObservacion() {
    observacionIdDeposito = null;
    document.getElementById('obs-motivo').value = '';
    document.getElementById('modal-observacion').style.display = 'none';
  }

  // Cerrar al hacer clic fuera del contenido
  document.getElementById('modal-observacion').addEventListener('click', function(e) {
    if (e.target === this) {
      cerrarModalObservacion();
    }
  });

  // Manejar clic en icono de observar
  document.addEventListener('click', function(e) {
    const icon = e.target.closest('.observar-icon');
    if (!icon) return;

    const idDeposito = parseInt(icon.dataset.iddep, 10);
    const tr = icon.closest('tr');
    const nDeposito = tr.dataset.deposito || '--';
    const nExpediente = tr.dataset.expediente || '--';

    abrirModalObservacion(idDeposito, nDeposito, nExpediente);
  });

  // Confirmar observación
  document.getElementById('obs-confirmar-btn').addEventListener('click', async function() {
    const motivo = document.getElementById('obs-motivo').value.trim();

    if (!motivo) {
      Swal.fire('Atención', 'Debe ingresar el motivo de la observación.', 'warning');
      return;
    }

    if (!observacionIdDeposito) {
      Swal.fire('Error', 'No se ha seleccionado un depósito.', 'error');
      return;
    }

    const formData = new FormData();
    formData.append('id_deposito', observacionIdDeposito);
    formData.append('motivo_observacion', motivo);

    try {
      const response = await fetch('../code_back/back_deposito_observar.php', {
        method: 'POST',
        body: formData
      });

      const result = await response.json();

      if (result.success) {
        cerrarModalObservacion();
        await Swal.fire('✅ Observado', result.message, 'success');
        window.location.reload();
      } else {
        await Swal.fire('❌ Error', result.message, 'error');
      }
    } catch (error) {
      console.error('Error al marcar como observado:', error);
      await Swal.fire('❌ Error de red', 'No se pudo procesar la solicitud.', 'error');
    }
  });

  // ========================================
  // FIN MODAL OBSERVACIÓN
  // ========================================

  // ========================================
  // ICONO CHECK VERDE - MARCAR COMO ATENDIDA (MAU)
  // ========================================
  document.addEventListener('click', async function(e) {
    const icon = e.target.closest('.atender-observacion-icon');
    if (!icon) return;

    const idDeposito = parseInt(icon.dataset.iddep, 10);
    const nDeposito = icon.dataset.deposito || '--';
    const nExpediente = icon.dataset.expediente || '--';

    // Confirmación con SweetAlert
    const confirmResult = await Swal.fire({
      title: '¿Marcar como Observación Atendida?',
      html: `
        <p style="margin: 10px 0;">
          <strong>Depósito:</strong> ${nDeposito}<br>
          <strong>Expediente:</strong> ${nExpediente}
        </p>
        <p style="margin-top: 15px;">Esto indicará que la observación ha sido resuelta.</p>
      `,
      icon: 'question',
      showCancelButton: true,
      confirmButtonText: 'Sí, marcar como atendida',
      cancelButtonText: 'Cancelar',
      confirmButtonColor: '#28a745',
      cancelButtonColor: '#6c757d'
    });

    if (!confirmResult.isConfirmed) return;

    const formData = new FormData();
    formData.append('id_deposito', idDeposito);

    try {
      const response = await fetch('../code_back/back_deposito_marcar_atendido.php', {
        method: 'POST',
        body: formData
      });

      const result = await response.json();

      if (result.success) {
        await Swal.fire('✅ Atendida', result.message, 'success');
        window.location.reload();
      } else {
        await Swal.fire('❌ Error', result.message, 'error');
      }
    } catch (error) {
      console.error('Error al marcar como atendida:', error);
      await Swal.fire('❌ Error de red', 'No se pudo procesar la solicitud.', 'error');
    }
  });
  // ========================================
  // FIN ICONO CHECK VERDE
  // ========================================
  </script>

  <script src="../js/listado_depositos.js" defer></script>
  <script src="../js/enviar_masivo.js" defer></script>

  <!-- Contenedor de toasts -->
  <div id="notification-container" style="
       position: fixed;
       bottom: 20px;
       right: 20px;
       z-index: 9999;
       display: flex;
       flex-direction: column;
       gap: 10px;
  "></div>

</body>
</html>