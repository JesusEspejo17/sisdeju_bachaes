<?php
// back_deposito_subir_pdf.php (actualizado: soporte para múltiples resoluciones indexadas)
// Cambios: permite duplicar registros con el mismo n_deposito (se quitó el check de existencia)
// --------------------------------------------------------

date_default_timezone_set('America/Lima');
session_start();
include("conexion.php");
header('Content-Type: application/json; charset=utf-8');

$usuario = $_SESSION['documento'] ?? null;

// DEBUG minimal
@error_log("back_deposito_subir_pdf: inicio - POST: " . json_encode($_POST) . " FILES: " . json_encode(array_map(function($f){ return ['name'=>$f['name'] ?? '', 'size'=>$f['size'] ?? 0]; }, $_FILES)));

// --- helpers ---
function extract_deposit_from_filename($filename) {
    if (!$filename) return null;
    $basename = (strpos($filename, '.') !== false) ? substr($filename, 0, strrpos($filename, '.')) : $filename;
    $s = $basename;
    $len = strlen($s);
    for ($i = 0; $i <= $len - 13; $i++) {
        if (preg_match('/\d/', $s[$i])) {
            $candidate = substr($s, $i, 13);
            if (preg_match('/^\d{13}$/', $candidate)) {
                $nextChar = $s[$i + 13] ?? '';
                if ($nextChar === '' || $nextChar === '_' || !preg_match('/\d/', $nextChar)) {
                    return $candidate;
                }
            }
        }
    }
    if (preg_match_all('/\d{13}/', $s, $m)) {
        $arr = $m[0];
        return end($arr);
    }
    return null;
}

function jsonErr($msg, $code = 400) {
  http_response_code($code);
  echo json_encode(['success' => false, 'msg' => $msg]);
  exit;
}

function safeFilename($name) {
  return preg_replace('/[^A-Za-z0-9_\-\.]/', '_', basename($name));
}

/**
 * Guarda un PDF con el patrón:
 *   <tipo>_<n_deposito>_<YYYYMMDD>_<HHMMSS>.pdf
 * Si ya existe, añade sufijo incremental _1, _2, ...
 * Devuelve array con keys: fullpath, basename
 */
function savePdfWithPattern($file, $tipo, $n_deposito, $destDir, $maxBytes) {
    if (!isset($file) || $file['error'] !== UPLOAD_ERR_OK) {
        throw new Exception("Error subiendo archivo: " . ($file['name'] ?? 'sin nombre'));
    }
    if ($file['size'] <= 0) throw new Exception("Archivo vacío: " . ($file['name'] ?? 'sin nombre'));
    if ($file['size'] > $maxBytes) throw new Exception("Archivo excede tamaño máximo: " . ($file['name'] ?? ''));

    // validar MIME
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    if ($mime !== 'application/pdf') throw new Exception("Solo se aceptan PDFs. Archivo: " . ($file['name'] ?? '') . " (mime: $mime)");

    // normalizar n_deposito
    $n = $n_deposito ? preg_replace('/\D+/', '', $n_deposito) : 'no-dep';
    // timestamp
    $ts_date = date('Ymd'); // YYYYMMDD
    $ts_time = date('His'); // HHMMSS

    // construir base
    $base = "{$tipo}_{$n}_{$ts_date}_{$ts_time}.pdf";
    $target = rtrim($destDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $base;

    // si ya existe, añadir sufijo incremental
    $i = 0;
    while (file_exists($target)) {
        $i++;
        $base = "{$tipo}_{$n}_{$ts_date}_{$ts_time}_{$i}.pdf";
        $target = rtrim($destDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $base;
    }

    // intentar mover
    if (!move_uploaded_file($file['tmp_name'], $target)) {
        throw new Exception("No se pudo mover archivo a destino.");
    }

    return ['fullpath' => $target, 'basename' => $base];
}

// --- entradas ---
$id_dep = isset($_POST['id_deposito']) && $_POST['id_deposito'] !== '' ? (int)$_POST['id_deposito'] : null;
$n_dep_post_raw = isset($_POST['n_deposito']) ? trim($_POST['n_deposito']) : null;
$n_dep_post_raw = ($n_dep_post_raw === '') ? null : $n_dep_post_raw;

// Recolectar archivos de orden (puede venir orden_pdf[] o orden_pdf single)
$orden_files = [];
if (isset($_FILES['orden_pdf'])) {
    // caso array
    if (is_array($_FILES['orden_pdf']['name'])) {
        for ($i = 0; $i < count($_FILES['orden_pdf']['name']); $i++) {
            $orden_files[] = [
                'name' => $_FILES['orden_pdf']['name'][$i],
                'type' => $_FILES['orden_pdf']['type'][$i],
                'tmp_name' => $_FILES['orden_pdf']['tmp_name'][$i],
                'error' => $_FILES['orden_pdf']['error'][$i],
                'size' => $_FILES['orden_pdf']['size'][$i],
            ];
        }
    } else {
        $orden_files[] = $_FILES['orden_pdf'];
    }
}
$hasOrden = count($orden_files) > 0;

// Recolectar archivos de resolucion (puede venir resolucion_pdf[] o resolucion_pdf single)
$resol_files = [];
if (isset($_FILES['resolucion_pdf'])) {
    if (is_array($_FILES['resolucion_pdf']['name'])) {
        for ($i = 0; $i < count($_FILES['resolucion_pdf']['name']); $i++) {
            // ignorar entradas vacías (usuario pudo añadir input sin seleccionar archivo)
            if (empty($_FILES['resolucion_pdf']['tmp_name'][$i])) continue;
            $resol_files[] = [
                'name' => $_FILES['resolucion_pdf']['name'][$i],
                'type' => $_FILES['resolucion_pdf']['type'][$i],
                'tmp_name' => $_FILES['resolucion_pdf']['tmp_name'][$i],
                'error' => $_FILES['resolucion_pdf']['error'][$i],
                'size' => $_FILES['resolucion_pdf']['size'][$i],
            ];
        }
    } else {
        if (!empty($_FILES['resolucion_pdf']['tmp_name'])) {
            $resol_files[] = $_FILES['resolucion_pdf'];
        }
    }
}
$hasResol = count($resol_files) > 0;

if (!$hasOrden && !$hasResol) jsonErr('Debes enviar al menos orden_pdf o resolucion_pdf');

// Si hay más de 1 resolución y hay órdenes, su cantidad debe coincidir con la de órdenes
if ($hasResol && count($resol_files) > 1 && $hasOrden && count($resol_files) !== count($orden_files)) {
    jsonErr('Si subes múltiples resoluciones, deben coincidir en cantidad con las órdenes (1:1 por índice).');
}

// --- extracción de n_deposito: SOLO desde ORDENES (por cada orden intentamos extraer su propio n) ---
// --- extracción robusta de n_deposito desde ORDENES (entrada: nombre de archivo) ---
$extracted_n_by_order = []; // index => 13-digit or null

foreach ($orden_files as $idx => $f) {
    $extracted = null;
    $name = isset($f['name']) ? $f['name'] : '';
    if ($name !== '') {
        // basename sin extensión, y quitar sufijos tipo "(1)" si los hay
        $basename = pathinfo($name, PATHINFO_FILENAME);
        $basename = preg_replace('/\s*\(.+\)\s*$/', '', $basename);

        // 1) Si comienza con FIRMA_ -> preferimos extraer el 13 dígitos que van en la 2ª porción
        if (preg_match('/^FIRMA_/i', $basename)) {
            $parts = preg_split('/_+/', $basename);
            $candidate = null;

            if (isset($parts[1])) {
                // primero tratar de sacar 13 dígitos al final de esa porción (caso típico)
                if (preg_match('/(\d{13})$/', $parts[1], $m)) {
                    $candidate = $m[1];
                } else {
                    // si no existe al final, buscar cualquier secuencia de 13 dígitos en esa porción
                    if (preg_match('/(\d{13})/', $parts[1], $m2)) {
                        $candidate = $m2[1];
                    }
                }
            }

            // fallback: buscar cualquier 13-digit en todo el basename (por precaución)
            if (!$candidate && preg_match('/(\d{13})/', $basename, $m3)) {
                $candidate = $m3[1];
            }

            if ($candidate && preg_match('/^\d{13}$/', $candidate)) {
                $extracted = $candidate;
            }
        }

        // 2) Si no logramos con FIRMA_ o no empieza con FIRMA_, usar el extractor genérico previo
        if (!$extracted) {
            $alt = extract_deposit_from_filename($name);
            if ($alt && preg_match('/^\d{13}$/', $alt)) {
                $extracted = $alt;
            } else {
                $extracted = null;
            }
        }
    }

    $extracted_n_by_order[$idx] = $extracted;
}

// Si front mandó un n_deposito general (fallback), lo consideramos para la primera orden solo si no se extrajo
if ($n_dep_post_raw && (!isset($extracted_n_by_order[0]) || !$extracted_n_by_order[0])) {
    $n_dep_post_raw = preg_replace('/\D+/', '', $n_dep_post_raw);
    if (!preg_match('/^\d{13}$/', $n_dep_post_raw)) $n_dep_post_raw = null;
    if ($n_dep_post_raw) $extracted_n_by_order[0] = $n_dep_post_raw;
}

// --- preparar carpeta ---
$uploadDir = __DIR__ . '/uploads/ordenes/';
if (!is_dir($uploadDir)) {
    if (!mkdir($uploadDir, 0755, true)) jsonErr('No se pudo crear el directorio de subida');
}

// para resoluciones (usamos la misma carpeta pero distinto prefijo)
$uploadDirResol = $uploadDir; // si quieres otra carpeta, cámbiala aquí

$ts = date('Ymd_His');
$savedFiles = []; // guardará rutas físicas completas y nombres

// max size 12MB (ajusta si quieres)
$maxBytes = 12 * 1024 * 1024;
try {
    // validar y guardar ordenes usando el patrón de nombre solicitado
    $orden_paths = []; $orden_saved_names = [];
    foreach ($orden_files as $i => $f) {
        if ($f['error'] !== UPLOAD_ERR_OK) throw new Exception('Error en subida de archivo orden: ' . ($f['name'] ?? ''));
        if ($f['size'] > $maxBytes) throw new Exception('Archivo excede tamaño máximo: ' . ($f['name'] ?? ''));
        // extensión check básico
        $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
        if ($ext !== 'pdf') throw new Exception('orden_pdf debe ser PDF: ' . ($f['name'] ?? ''));

        // elegir n para el nombre: si se extrajo de esta orden usarlo, si no usar $n_dep_post_raw o 'no-dep'
        $n_for_name = $extracted_n_by_order[$i] ?? $n_dep_post_raw ?? null;

        // guardar con patrón
        $saved = savePdfWithPattern($f, 'orden', $n_for_name, $uploadDir, $maxBytes);
        $orden_paths[] = $saved['fullpath'];
        $orden_saved_names[] = $saved['basename'];
        $savedFiles[] = $saved['fullpath'];
    }

    // guardar resoluciones si vienen (puede ser 1 para todas o N=ordenes)
    $rutaResolRel = null; // ruta relativa para caso single
    $resol_rutas_rel = []; // array de rutas relativas por índice (si se suben múltiples)
    if ($hasResol) {
        if (count($resol_files) === 1) {
            // una sola resolución -> se aplicará a todas las órdenes
            $rf = $resol_files[0];
            if ($rf['error'] !== UPLOAD_ERR_OK) throw new Exception('Error en subida de resolución.');
            if ($rf['size'] > $maxBytes) throw new Exception('Resolución excede tamaño máximo.');
            $ext2 = strtolower(pathinfo($rf['name'], PATHINFO_EXTENSION));
            if ($ext2 !== 'pdf') throw new Exception('resolucion_pdf debe ser PDF');

            $n_for_resol = $extracted_n_by_order[0] ?? $n_dep_post_raw ?? null;
            $savedR = savePdfWithPattern($rf, 'resolucion', $n_for_resol, $uploadDirResol, $maxBytes);
            $rutaResolRel = "code_back/uploads/ordenes/" . $savedR['basename'];
            $savedFiles[] = $savedR['fullpath'];
        } else {
            // varias resoluciones -> deben ajustarse por índice (ya validamos que count == count(ordenes))
            foreach ($resol_files as $i => $rf) {
                if ($rf['error'] !== UPLOAD_ERR_OK) throw new Exception('Error en subida de resolución index ' . ($i+1));
                if ($rf['size'] > $maxBytes) throw new Exception('Resolución excede tamaño máximo: ' . ($rf['name'] ?? ''));
                $ext2 = strtolower(pathinfo($rf['name'], PATHINFO_EXTENSION));
                if ($ext2 !== 'pdf') throw new Exception('resolucion_pdf debe ser PDF: ' . ($rf['name'] ?? ''));
                // preferir n extraído de la orden correspondiente; si no, fallback al primer n o al n_post
                $n_for_resol = $extracted_n_by_order[$i] ?? $extracted_n_by_order[0] ?? $n_dep_post_raw ?? null;
                $savedR = savePdfWithPattern($rf, 'resolucion', $n_for_resol, $uploadDirResol, $maxBytes);
                $resol_rutas_rel[$i] = "code_back/uploads/ordenes/" . $savedR['basename'];
                $savedFiles[] = $savedR['fullpath'];
            }
        }
    }

    // construir rutas que guardaremos en BD (relativas o como prefieras)
    $rutaOrdenRel = function($fullPath, $savedName) {
        return "code_back/uploads/ordenes/" . $savedName;
    };
    $orden_rutas_rel = [];
    for ($i = 0; $i < count($orden_paths); $i++) {
        $orden_rutas_rel[] = $rutaOrdenRel($orden_paths[$i], $orden_saved_names[$i]);
    }
    // $rutaResolRel ya contiene la ruta relativa en caso de single; $resol_rutas_rel contiene array por índice si mult

    // ----------------- DB: comenzar transacción -----------------
    mysqli_begin_transaction($cn);

    // 1) intentar localizar fila objetivo por id_deposito (FOR UPDATE)
    $row = null;
    if ($id_dep) {
        $sql = "SELECT * FROM deposito_judicial WHERE id_deposito = ? FOR UPDATE";
        $st = mysqli_prepare($cn, $sql);
        if (!$st) throw new Exception("Error preparar SELECT por id");
        mysqli_stmt_bind_param($st, "i", $id_dep);
        mysqli_stmt_execute($st);
        $res = mysqli_stmt_get_result($st);
        $row = mysqli_fetch_assoc($res);
        mysqli_stmt_close($st);
    }

    // 2) si no hay row y tenemos un n extraído para la primera orden, buscar por n_deposito
    if (!$row) {
        $firstN = $extracted_n_by_order[0] ?? null;
        if ($firstN) {
            $sql = "SELECT * FROM deposito_judicial WHERE n_deposito = ? FOR UPDATE";
            $st = mysqli_prepare($cn, $sql);
            if ($st) {
                mysqli_stmt_bind_param($st, "s", $firstN);
                mysqli_stmt_execute($st);
                $res = mysqli_stmt_get_result($st);
                $row = mysqli_fetch_assoc($res);
                mysqli_stmt_close($st);
            }
        }
    }

    if (!$row) {
        throw new Exception("No se encontró fila origen para actualizar/duplicar (id_deposito o n_deposito).");
    }

    // Si llegamos aquí tenemos $row: fila origen para actualizar/duplicar
    $rowId = (int)$row['id_deposito'];
    $currentN = $row['n_deposito'] ?? null;
    $estadoPrev = isset($row['id_estado']) ? (int)$row['id_estado'] : null;

    // definir nuevo estado por regla: si prev = 6 -> nuevo = 2, sino -> 7
    $newEstado = ($estadoPrev === 6) ? 2 : 7; // <<== CAMBIO: aquí definimos el estado objetivo

    // --- Actualizar la fila existente con la PRIMERA orden y asignar su n si procede ---
    $firstOrdenRuta = $orden_rutas_rel[0] ?? null;
    $firstOrdenN    = $extracted_n_by_order[0] ?? null;

    // determinar resolucion para la primera orden (puede ser shared o mult)
    $firstResolRel = null;
    if ($hasResol) {
        if (!empty($resol_rutas_rel)) {
            $firstResolRel = $resol_rutas_rel[0] ?? null;
        } else {
            $firstResolRel = $rutaResolRel ?? null;
        }
    }

    // si la fila tiene n_deposito NULL y tenemos n para la primera orden -> asignar (previo check duplicado)
    if ($firstOrdenN && (is_null($currentN) || trim($currentN) === '')) {
        
        // armar update (ahora también fijar id_estado = $newEstado si hace falta)
        $sets = ["n_deposito = ?"];
        $params = [$firstOrdenN];
        $types = "s";
        if ($firstOrdenRuta !== null) { $sets[] = "orden_pdf = ?"; $params[] = $firstOrdenRuta; $types .= "s"; }
        if ($firstResolRel !== null) { $sets[] = "resolucion_pdf = ?"; $params[] = $firstResolRel; $types .= "s"; }
        if ($estadoPrev !== $newEstado) {
            // usar literal en SET (no parametro) como antes
            $sets[] = "id_estado = {$newEstado}"; // <<== CAMBIO: si prev era 6 pasa a 2, sino 7
        }

        $updSql = "UPDATE deposito_judicial SET " . implode(", ", $sets) . " WHERE id_deposito = ?";
        $params[] = $rowId; $types .= "i";
        $updSt = mysqli_prepare($cn, $updSql);
        if (!$updSt) throw new Exception("Error preparar UPDATE target");
        mysqli_stmt_bind_param($updSt, $types, ...$params);
        if (!mysqli_stmt_execute($updSt)) { $err = mysqli_error($cn); mysqli_stmt_close($updSt); throw new Exception("Error al actualizar depósito: $err"); }
        mysqli_stmt_close($updSt);

        $finalN_first = $firstOrdenN;

        // insertar historial para la fila origen si su estado previo no era equal al nuevoEstado
        if ($estadoPrev !== $newEstado) {
            $finalNdep = $finalN_first;
            // detectar si historial_deposito tiene id_deposito
            $histHasIdDep = false;
            $resShow = mysqli_query($cn, "SHOW COLUMNS FROM `historial_deposito` LIKE 'id_deposito'");
            if ($resShow && mysqli_num_rows($resShow) > 0) $histHasIdDep = true;
            if ($resShow) mysqli_free_result($resShow);

            $comentario = "Orden registrada por usuario " . ($usuario ?: 'sistema');
            $estadoAnteriorForInsert = $estadoPrev !== null ? $estadoPrev : 3;

            if ($histHasIdDep) {
                // ahora usamos placeholder para estado_nuevo y lo bindemos
                $insSql = "INSERT INTO historial_deposito (id_deposito, documento_usuario, fecha_historial_deposito, tipo_evento, estado_anterior, estado_nuevo, comentario_deposito) VALUES (?, ?, NOW(), 'CAMBIO_ESTADO', ?, ?, ?)";
                $insStmt = mysqli_prepare($cn, $insSql);
                if (!$insStmt) throw new Exception("Error al preparar INSERT historial (id_deposito): " . mysqli_error($cn));
                $iddepParam = $rowId;
                // types: id_deposito(i), documento_usuario(s), estado_anterior(i), estado_nuevo(i), comentario(s) => "isiis"
                mysqli_stmt_bind_param($insStmt, "isiis", $iddepParam, $usuario, $estadoAnteriorForInsert, $newEstado, $comentario);
            } else {
                $insSql = "INSERT INTO historial_deposito (n_deposito, documento_usuario, fecha_historial_deposito, tipo_evento, estado_anterior, estado_nuevo, comentario_deposito) VALUES (?, ?, NOW(), 'CAMBIO_ESTADO', ?, ?, ?)";
                $insStmt = mysqli_prepare($cn, $insSql);
                if (!$insStmt) throw new Exception("Error al preparar INSERT historial: " . mysqli_error($cn));
                // types: n_deposito(s), documento_usuario(s), estado_anterior(i), estado_nuevo(i), comentario(s) => "ssiis"
                mysqli_stmt_bind_param($insStmt, "ssiis", $finalNdep, $usuario, $estadoAnteriorForInsert, $newEstado, $comentario);
            }

            if (!mysqli_stmt_execute($insStmt)) {
                $err = mysqli_error($cn);
                mysqli_stmt_close($insStmt);
                throw new Exception("Error al insertar historial: $err");
            }
            mysqli_stmt_close($insStmt);
            error_log("back_deposito_subir_pdf: historial insertado para id={$rowId} finalNdep={$finalNdep} newEstado={$newEstado}");
        }

    } else {
        // solo actualizar rutas si vienen (y actualizar estado a $newEstado si corresponde)
        $sets = []; $params = []; $types = "";
        if ($firstOrdenRuta !== null) { $sets[] = "orden_pdf = ?"; $params[] = $firstOrdenRuta; $types .= "s"; }
        if ($firstResolRel !== null) { $sets[] = "resolucion_pdf = ?"; $params[] = $firstResolRel; $types .= "s"; }
        if ($estadoPrev !== $newEstado) {
            $sets[] = "id_estado = {$newEstado}"; // <<== CAMBIO
            // literal
        }
        if (!empty($sets)) {
            $updSql = "UPDATE deposito_judicial SET " . implode(", ", $sets) . " WHERE id_deposito = ?";
            $params[] = $rowId; $types .= "i";
            $updSt = mysqli_prepare($cn, $updSql);
            if (!$updSt) throw new Exception("Error preparar UPDATE rutas final");
            mysqli_stmt_bind_param($updSt, $types, ...$params);
            if (!mysqli_stmt_execute($updSt)) { $err = mysqli_error($cn); mysqli_stmt_close($updSt); throw new Exception("Error al actualizar rutas: $err"); }
            mysqli_stmt_close($updSt);
        }
        $finalN_first = $currentN ?: $firstOrdenN;

        // insertar historial si el estado previo no era igual al nuevoEstado
        if ($estadoPrev !== $newEstado) {
            $finalNdep = $finalN_first;
            $histHasIdDep = false;
            $resShow = mysqli_query($cn, "SHOW COLUMNS FROM `historial_deposito` LIKE 'id_deposito'");
            if ($resShow && mysqli_num_rows($resShow) > 0) $histHasIdDep = true;
            if ($resShow) mysqli_free_result($resShow);

            $comentario = "Orden registrada por usuario " . ($usuario ?: 'sistema');
            $estadoAnteriorForInsert = $estadoPrev !== null ? $estadoPrev : 3;

            if ($histHasIdDep) {
                $insSql = "INSERT INTO historial_deposito (id_deposito, documento_usuario, fecha_historial_deposito, tipo_evento, estado_anterior, estado_nuevo, comentario_deposito) VALUES (?, ?, NOW(), 'CAMBIO_ESTADO', ?, ?, ?)";
                $insStmt = mysqli_prepare($cn, $insSql);
                if (!$insStmt) throw new Exception("Error al preparar INSERT historial (id_deposito): " . mysqli_error($cn));
                $iddepParam = $rowId;
                mysqli_stmt_bind_param($insStmt, "isiis", $iddepParam, $usuario, $estadoAnteriorForInsert, $newEstado, $comentario);
            } else {
                $insSql = "INSERT INTO historial_deposito (n_deposito, documento_usuario, fecha_historial_deposito, tipo_evento, estado_anterior, estado_nuevo, comentario_deposito) VALUES (?, ?, NOW(), 'CAMBIO_ESTADO', ?, ?, ?)";
                $insStmt = mysqli_prepare($cn, $insSql);
                if (!$insStmt) throw new Exception("Error al preparar INSERT historial: " . mysqli_error($cn));
                mysqli_stmt_bind_param($insStmt, "ssiis", $finalNdep, $usuario, $estadoAnteriorForInsert, $newEstado, $comentario);
            }

            if (!mysqli_stmt_execute($insStmt)) {
                $err = mysqli_error($cn);
                mysqli_stmt_close($insStmt);
                throw new Exception("Error al insertar historial: $err");
            }
            mysqli_stmt_close($insStmt);
            error_log("back_deposito_subir_pdf: historial insertado para id={$rowId} finalNdep={$finalNdep} newEstado={$newEstado}");
        }
    }

    // --- Si hay más ordenes: duplicar la fila origen por cada orden adicional ---
    $inserted_ids = [];
    if (count($orden_rutas_rel) > 1) {
        // Obtener lista de columnas de la tabla para construir COPY (excepto id_deposito)
        $colsRes = mysqli_query($cn, "SHOW COLUMNS FROM deposito_judicial");
        $cols = [];
        while ($c = mysqli_fetch_assoc($colsRes)) {
            $cols[] = $c['Field'];
        }

        // columnas a excluir de la copia automática
        $exclude = ['id_deposito', 'n_deposito', 'orden_pdf', 'resolucion_pdf'];
        // construir lista de columnas que vamos a insertar (todos menos exclusiones)
        $copyCols = array_values(array_filter($cols, function($col) use ($exclude) { return !in_array($col, $exclude); }));

        // detectar si historial usa id_deposito (solo una vez)
        $histHasIdDep = false;
        $resShow = mysqli_query($cn, "SHOW COLUMNS FROM `historial_deposito` LIKE 'id_deposito'");
        if ($resShow && mysqli_num_rows($resShow) > 0) $histHasIdDep = true;
        if ($resShow) mysqli_free_result($resShow);

        $estadoAnteriorForInsert = $estadoPrev !== null ? $estadoPrev : 3;

        // Para cada orden adicional (i>=1) hacemos INSERT copiando valores desde $row y asignando n_deposito y rutas
        for ($i = 1; $i < count($orden_rutas_rel); $i++) {
            $ordenRutaThis = $orden_rutas_rel[$i];
            $nThis = $extracted_n_by_order[$i] ?? null;

            // LIMPIAR y validar formato si viene
            if ($nThis) {
                $nThis = preg_replace('/\D+/', '', $nThis);
                if (!preg_match('/^\d{13}$/', $nThis)) {
                    throw new Exception("Número de depósito extraído inválido para la orden " . ($i+1));
                }
                // <<== Nota: NO HACEMOS CHECK de existencia en BD; permitimos duplicados
            }

            // seleccionar resolución para este índice: si hay array de resoluciones usar la correspondiente,
            // sino si hay ruta única ($rutaResolRel) usarla, si no null
            $resolForThis = null;
            if (!empty($resol_rutas_rel)) {
                $resolForThis = $resol_rutas_rel[$i] ?? null;
            } else {
                $resolForThis = $rutaResolRel ?? null;
            }

            // construir INSERT: columnas = copyCols (+ id_estado si no estaba) + ['n_deposito','orden_pdf','resolucion_pdf']
            $hasIdEstado = in_array('id_estado', $copyCols);
            $insertCols = $copyCols;
            if (!$hasIdEstado) $insertCols[] = 'id_estado';
            $insertCols[] = 'n_deposito';
            $insertCols[] = 'orden_pdf';
            $insertCols[] = 'resolucion_pdf';

            // valores: para cada $copyCols tomamos de $row (escapamos)
            $vals = [];
            foreach ($copyCols as $cc) {
                if (array_key_exists($cc, $row)) {
                    $v = $row[$cc];
                    if (is_null($v) || $v === '') $vals[] = "NULL";
                    else {
                        // si es id_estado lo mantenemos como número
                        if ($cc === 'id_estado') $vals[] = (int)$v;
                        else $vals[] = "'" . mysqli_real_escape_string($cn, $v) . "'";
                    }
                } else {
                    $vals[] = "NULL";
                }
            }

            // garantizar que id_estado sea $newEstado en el duplicado
            if ($hasIdEstado) {
                $pos = array_search('id_estado', $copyCols);
                if ($pos !== false) {
                    $vals[$pos] = (int)$newEstado; // <<== CAMBIO: usar $newEstado
                }
            } else {
                // si no estaba, agregar valor $newEstado ahora
                $vals[] = (int)$newEstado; // <<== CAMBIO
            }

            // n_deposito
            $vals[] = $nThis ? ("'" . mysqli_real_escape_string($cn, $nThis) . "'") : "NULL";
            // orden_pdf
            $vals[] = "'" . mysqli_real_escape_string($cn, $ordenRutaThis) . "'";
            // resolucion_pdf -> usar resolForThis
            $vals[] = $resolForThis ? ("'" . mysqli_real_escape_string($cn, $resolForThis) . "'") : "NULL";

            $sqlIns = "INSERT INTO deposito_judicial (" . implode(',', $insertCols) . ") VALUES (" . implode(',', $vals) . ")";
            if (!mysqli_query($cn, $sqlIns)) {
                throw new Exception("Error insertando duplicado: " . mysqli_error($cn));
            }
            $newInsertedId = mysqli_insert_id($cn);
            $inserted_ids[] = $newInsertedId;

            // insertar historial para este duplicado (tomamos estado anterior del origen)
            $comentarioDup = "Orden registrada (duplicado) por usuario " . ($usuario ?: 'sistema');

            if ($histHasIdDep) {
                $insSqlH = "INSERT INTO historial_deposito (id_deposito, documento_usuario, fecha_historial_deposito, tipo_evento, estado_anterior, estado_nuevo, comentario_deposito) VALUES (?, ?, NOW(), 'CAMBIO_ESTADO', ?, ?, ?)";
                $insH = mysqli_prepare($cn, $insSqlH);
                if (!$insH) throw new Exception("Error preparar INSERT historial duplicado: " . mysqli_error($cn));
                // types: id_deposito(i), documento_usuario(s), estado_anterior(i), estado_nuevo(i), comentario(s) => "isiis"
                mysqli_stmt_bind_param($insH, "isiis", $newInsertedId, $usuario, $estadoAnteriorForInsert, $newEstado, $comentarioDup);
            } else {
                $nForHistDup = $nThis ? $nThis : ($finalN_first ?? '');
                $insSqlH = "INSERT INTO historial_deposito (n_deposito, documento_usuario, fecha_historial_deposito, tipo_evento, estado_anterior, estado_nuevo, comentario_deposito) VALUES (?, ?, NOW(), 'CAMBIO_ESTADO', ?, ?, ?)";
                $insH = mysqli_prepare($cn, $insSqlH);
                if (!$insH) throw new Exception("Error preparar INSERT historial duplicado: " . mysqli_error($cn));
                // types: n_deposito(s), documento_usuario(s), estado_anterior(i), estado_nuevo(i), comentario(s) => "ssiis"
                mysqli_stmt_bind_param($insH, "ssiis", $nForHistDup, $usuario, $estadoAnteriorForInsert, $newEstado, $comentarioDup);
            }

            if (!mysqli_stmt_execute($insH)) {
                $err = mysqli_error($cn);
                mysqli_stmt_close($insH);
                throw new Exception("Error al insertar historial del duplicado: $err");
            }
            mysqli_stmt_close($insH);
        }
    }

    mysqli_commit($cn);

    // respuesta final: incluye id actualizado + ids insertados (si hubo)
    echo json_encode([
        'success' => true,
        'msg' => 'Archivos subidos: fila actualizada y duplicados insertados si aplicó.',
        'id_deposito_updated' => $rowId,
        'n_deposito_updated' => $finalN_first,
        'orden_actualizada' => $orden_rutas_rel[0] ?? null,
        'resolucion_single' => $rutaResolRel ?? null,
        'resoluciones_indexadas' => !empty($resol_rutas_rel) ? $resol_rutas_rel : null,
        'inserted_duplicate_ids' => $inserted_ids,
        'updated' => true,
        'created' => (count($inserted_ids) > 0),
        'registered' => false
    ]);
    exit;

} catch (Exception $e) {
    // limpiar archivos guardados si algo falló
    foreach ($savedFiles as $f) if (file_exists($f)) @unlink($f);
    @mysqli_rollback($cn);
    $msg = '❌ ' . $e->getMessage();
    error_log("back_deposito_subir_pdf ERROR: " . $e->getMessage());
    echo json_encode(['success' => false, 'msg' => $msg]);
    exit;
}