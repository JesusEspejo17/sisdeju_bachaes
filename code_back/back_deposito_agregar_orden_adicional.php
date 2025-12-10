<?php
// back_deposito_agregar_orden_adicional.php
// Permite agregar una orden de pago y resolución adicional a un depósito existente
// Crea un nuevo registro duplicando la información del depósito original

declare(strict_types=1);
date_default_timezone_set('America/Lima');
session_start();
include("conexion.php");
header('Content-Type: application/json; charset=utf-8');

$usuario = $_SESSION['documento'] ?? null;

// DEBUG
@error_log("back_deposito_agregar_orden_adicional: inicio - POST: " . json_encode($_POST) . " FILES: " . json_encode(array_map(function($f){ return ['name'=>$f['name'] ?? '', 'size'=>$f['size'] ?? 0]; }, $_FILES)));

// --- Funciones helper ---
function jsonErr($msg, $code = 400) {
    http_response_code($code);
    echo json_encode(['success' => false, 'msg' => $msg]);
    exit;
}

function extract_deposit_from_filename($filename) {
    if (!$filename) return null;
    $basename = (strpos($filename, '.') !== false) ? substr($filename, 0, strrpos($filename, '.')) : $filename;
    $s = $basename;
    $len = strlen($s);
    for ($i = 0; $i <= $len - 13; $i++) {
        if (preg_match('/\d/', $s[$i])) {
            $candidate = substr($s, $i, 13);
            if (preg_match('/^\d{13}$/', $candidate)) {
                // Buscar patrón _X después del número base de 13 dígitos
                $afterPos = $i + 13;
                if ($afterPos < $len && $s[$afterPos] === '_' && $afterPos + 1 < $len && preg_match('/\d/', $s[$afterPos + 1])) {
                    $suffixDigit = $s[$afterPos + 1];
                    // Formatear como XXXXXXXXXXXXX-00X
                    return $candidate . '-' . str_pad($suffixDigit, 3, '0', STR_PAD_LEFT);
                }
                // Si no hay sufijo _X, devolver solo el número base de 13 dígitos
                $nextChar = $s[$i + 13] ?? '';
                if ($nextChar === '' || $nextChar === '_' || !preg_match('/\d/', $nextChar)) {
                    return $candidate;
                }
            }
        }
    }
    // Fallback: buscar cualquier secuencia de 13 dígitos (formato base)
    if (preg_match_all('/\d{13}/', $s, $m)) {
        $arr = $m[0];
        $baseNumber = end($arr);
        // Buscar si hay sufijo _X después de este número
        $pos = strrpos($s, $baseNumber);
        if ($pos !== false) {
            $afterPos = $pos + 13;
            if ($afterPos < $len && $s[$afterPos] === '_' && $afterPos + 1 < $len && preg_match('/\d/', $s[$afterPos + 1])) {
                $suffixDigit = $s[$afterPos + 1];
                return $baseNumber . '-' . str_pad($suffixDigit, 3, '0', STR_PAD_LEFT);
            }
        }
        return $baseNumber;
    }
    return null;
}

function extract_deposit_robust($filename) {
    if (!$filename) return null;
    
    // Quitar extensión y sufijos como "(1)"
    $basename = pathinfo($filename, PATHINFO_FILENAME);
    $basename = preg_replace('/\s*\(.+\)\s*$/', '', $basename);
    
    // Si comienza con FIRMA_ -> extraer de la segunda porción
    if (preg_match('/^FIRMA_/i', $basename)) {
        $parts = preg_split('/_+/', $basename);
        $candidate = null;
        
        if (isset($parts[1])) {
            // Buscar 13 dígitos base al final de esa porción
            if (preg_match('/(\d{13})$/', $parts[1], $m)) {
                $candidate = $m[1];
                // Buscar si hay sufijo _X después en las siguientes partes
                if (isset($parts[2]) && preg_match('/^(\d)/', $parts[2], $suffixMatch)) {
                    $candidate = $candidate . '-' . str_pad($suffixMatch[1], 3, '0', STR_PAD_LEFT);
                }
            } else if (preg_match('/(\d{13})/', $parts[1], $m2)) {
                $candidate = $m2[1];
                // Buscar si hay sufijo _X después en las siguientes partes
                if (isset($parts[2]) && preg_match('/^(\d)/', $parts[2], $suffixMatch)) {
                    $candidate = $candidate . '-' . str_pad($suffixMatch[1], 3, '0', STR_PAD_LEFT);
                }
            }
        }
        
        // Fallback: buscar en todo el basename
        if (!$candidate && preg_match('/(\d{13})/', $basename, $m3)) {
            $candidate = $m3[1];
            // Buscar patrón _X después del match
            $pos = strpos($basename, $candidate);
            if ($pos !== false) {
                $afterPos = $pos + 13;
                if ($afterPos < strlen($basename) && $basename[$afterPos] === '_' && $afterPos + 1 < strlen($basename) && preg_match('/\d/', $basename[$afterPos + 1])) {
                    $suffixDigit = $basename[$afterPos + 1];
                    $candidate = $candidate . '-' . str_pad($suffixDigit, 3, '0', STR_PAD_LEFT);
                }
            }
        }
        
        if ($candidate && (preg_match('/^\d{13}$/', $candidate) || preg_match('/^\d{13}-\d{3}$/', $candidate))) {
            return $candidate;
        }
    }
    
    // Si no es FIRMA_ o no se encontró, usar extractor genérico
    return extract_deposit_from_filename($filename);
}

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

    // normalizar n_deposito (preservar formato con guión si existe)
    $n = $n_deposito ? str_replace('-', '_', $n_deposito) : 'no-dep';
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

// --- Validaciones iniciales ---
if (!isset($_SESSION['rol']) || $_SESSION['rol'] != 3) {
    jsonErr('Acceso denegado. Solo secretarios pueden agregar órdenes adicionales.');
}

$id_dep = isset($_POST['id_deposito']) && $_POST['id_deposito'] !== '' ? (int)$_POST['id_deposito'] : null;

if (!$id_dep) {
    jsonErr('Se requiere id_deposito para agregar orden adicional.');
}

// Validar que se enviaron los archivos requeridos
if (!isset($_FILES['orden_pdf']) || !isset($_FILES['resolucion_pdf'])) {
    jsonErr('Debes enviar tanto orden_pdf como resolucion_pdf.');
}

// Preparar carpeta
$uploadDir = __DIR__ . '/uploads/ordenes/';
if (!is_dir($uploadDir)) {
    if (!mkdir($uploadDir, 0755, true)) jsonErr('No se pudo crear el directorio de subida');
}

$maxBytes = 12 * 1024 * 1024; // 12MB
$savedFiles = [];

try {
    mysqli_begin_transaction($cn);

    // 1) Obtener el registro original
    $sql = "SELECT * FROM deposito_judicial WHERE id_deposito = ? FOR UPDATE";
    $st = mysqli_prepare($cn, $sql);
    if (!$st) throw new Exception("Error preparar SELECT por id");
    mysqli_stmt_bind_param($st, "i", $id_dep);
    mysqli_stmt_execute($st);
    $res = mysqli_stmt_get_result($st);
    $row = mysqli_fetch_assoc($res);
    mysqli_stmt_close($st);

    if (!$row) {
        throw new Exception("No se encontró el depósito con id_deposito = $id_dep");
    }

    // Validar que el registro tenga n_expediente (debe estar ya registrado)
    $n_expediente_original = $row['n_expediente'] ?? null;
    if (!$n_expediente_original || trim($n_expediente_original) === '') {
        throw new Exception("El depósito no tiene número de expediente asignado.");
    }

    // 2) Extraer n_deposito de los archivos PDF subidos
    $orden_file = $_FILES['orden_pdf'];
    $resol_file = $_FILES['resolucion_pdf'];

    // Validar archivo de orden
    if ($orden_file['error'] !== UPLOAD_ERR_OK) throw new Exception('Error en subida de archivo orden');
    if ($orden_file['size'] > $maxBytes) throw new Exception('Archivo orden excede tamaño máximo');
    $ext_orden = strtolower(pathinfo($orden_file['name'], PATHINFO_EXTENSION));
    if ($ext_orden !== 'pdf') throw new Exception('orden_pdf debe ser PDF');

    // Validar archivo de resolución
    if ($resol_file['error'] !== UPLOAD_ERR_OK) throw new Exception('Error en subida de archivo resolución');
    if ($resol_file['size'] > $maxBytes) throw new Exception('Archivo resolución excede tamaño máximo');
    $ext_resol = strtolower(pathinfo($resol_file['name'], PATHINFO_EXTENSION));
    if ($ext_resol !== 'pdf') throw new Exception('resolucion_pdf debe ser PDF');

    // EXTRAER n_deposito del nombre del archivo de orden
    $n_deposito_nuevo = extract_deposit_robust($orden_file['name']);
    
    if (!$n_deposito_nuevo || (!preg_match('/^\d{13}$/', $n_deposito_nuevo) && !preg_match('/^\d{13}-\d{3}$/', $n_deposito_nuevo))) {
        throw new Exception("No se pudo extraer el número de depósito del archivo de orden. Debe ser formato XXXXXXXXXXXXX o XXXXXXXXXXXXX-XXX. Archivo: " . $orden_file['name']);
    }
    
    error_log("=== N_DEPOSITO EXTRAIDO ===");
    error_log("Archivo orden: " . $orden_file['name']);
    error_log("N_deposito extraído: " . $n_deposito_nuevo);

    // Guardar archivos con el n_deposito extraído
    $savedOrden = savePdfWithPattern($orden_file, 'orden', $n_deposito_nuevo, $uploadDir, $maxBytes);
    $savedFiles[] = $savedOrden['fullpath'];
    $rutaOrden = "code_back/uploads/ordenes/" . $savedOrden['basename'];

    $savedResol = savePdfWithPattern($resol_file, 'resolucion', $n_deposito_nuevo, $uploadDir, $maxBytes);
    $savedFiles[] = $savedResol['fullpath'];
    $rutaResol = "code_back/uploads/ordenes/" . $savedResol['basename'];

    // 3) Crear nuevo registro duplicando el original
    // Obtener columnas de la tabla
    $colsRes = mysqli_query($cn, "SHOW COLUMNS FROM deposito_judicial");
    $cols = [];
    while ($c = mysqli_fetch_assoc($colsRes)) {
        $cols[] = $c['Field'];
    }
    
    error_log("=== TODAS LAS COLUMNAS DE LA TABLA ===");
    error_log(print_r($cols, true));

    // Columnas a excluir de la copia automática
    // NO excluimos fecha_atencion_observacion para que se copie del registro original
    $exclude = ['id_deposito', 'orden_pdf', 'resolucion_pdf', 'estado_observacion', 'motivo_observacion', 'fecha_observacion'];
    $copyCols = array_values(array_filter($cols, function($col) use ($exclude) { 
        return !in_array($col, $exclude); 
    }));
    
    error_log("=== COLUMNAS A COPIAR (copyCols) ===");
    error_log(print_r($copyCols, true));

    // Determinar el estado del nuevo registro
    $estadoPrev = isset($row['id_estado']) ? (int)$row['id_estado'] : null;
    $newEstado = ($estadoPrev === 6) ? 2 : 7;

    // Verificar si id_estado está en copyCols (fecha_atencion_observacion ya debe estar incluida)
    $hasIdEstado = in_array('id_estado', $copyCols);
    
    error_log("=== VERIFICACIONES ===");
    error_log("hasIdEstado: " . ($hasIdEstado ? 'SI' : 'NO'));
    error_log("fecha_atencion_observacion en row: " . (isset($row['fecha_atencion_observacion']) ? $row['fecha_atencion_observacion'] : 'NULL'));
    
    $insertCols = $copyCols;
    if (!$hasIdEstado) $insertCols[] = 'id_estado';
    $insertCols[] = 'orden_pdf';
    $insertCols[] = 'resolucion_pdf';
    
    error_log("=== COLUMNAS FINALES PARA INSERT ===");
    error_log(print_r($insertCols, true));

    // Construir valores
    $vals = [];
    foreach ($copyCols as $cc) {
        if (array_key_exists($cc, $row)) {
            $v = $row[$cc];
            
            // IMPORTANTE: Si la columna es n_deposito, usar el nuevo n_deposito extraído del archivo
            if ($cc === 'n_deposito') {
                $vals[] = "'" . mysqli_real_escape_string($cn, $n_deposito_nuevo) . "'";
            } else if (is_null($v) || $v === '') {
                $vals[] = "NULL";
            } else {
                if ($cc === 'id_estado') {
                    $vals[] = (int)$v;
                } else {
                    $vals[] = "'" . mysqli_real_escape_string($cn, $v) . "'";
                }
            }
        } else {
            $vals[] = "NULL";
        }
    }

    // Garantizar que id_estado sea $newEstado
    if ($hasIdEstado) {
        $pos = array_search('id_estado', $copyCols);
        if ($pos !== false) {
            $vals[$pos] = (int)$newEstado;
        }
    } else {
        $vals[] = (int)$newEstado;
    }

    // Agregar rutas de PDFs
    $vals[] = "'" . mysqli_real_escape_string($cn, $rutaOrden) . "'";
    $vals[] = "'" . mysqli_real_escape_string($cn, $rutaResol) . "'";
    
    error_log("=== VALORES FINALES ===");
    error_log("Total columnas: " . count($insertCols));
    error_log("Total valores: " . count($vals));
    error_log(print_r($vals, true));

    // Ejecutar INSERT
    $sqlIns = "INSERT INTO deposito_judicial (" . implode(',', $insertCols) . ") VALUES (" . implode(',', $vals) . ")";
    error_log("=== SQL GENERADO ===");
    error_log($sqlIns);
    
    if (!mysqli_query($cn, $sqlIns)) {
        error_log("=== ERROR EN INSERT ===");
        error_log(mysqli_error($cn));
        throw new Exception("Error insertando nuevo registro: " . mysqli_error($cn));
    }
    $newInsertedId = mysqli_insert_id($cn);

    // 4) Obtener la fecha de atención original (desde historial_deposito)
    // Buscar el registro de historial donde el estado cambió a 2 o 7
    $fechaAtencionOriginal = null;
    $sqlFecha = "SELECT fecha_historial_deposito 
                 FROM historial_deposito 
                 WHERE id_deposito = ? 
                 AND tipo_evento = 'CAMBIO_ESTADO' 
                 AND estado_nuevo IN (2, 7) 
                 ORDER BY fecha_historial_deposito DESC 
                 LIMIT 1";
    $stmtFecha = mysqli_prepare($cn, $sqlFecha);
    if ($stmtFecha) {
        mysqli_stmt_bind_param($stmtFecha, "i", $id_dep);
        mysqli_stmt_execute($stmtFecha);
        mysqli_stmt_bind_result($stmtFecha, $fechaAtencionOriginal);
        mysqli_stmt_fetch($stmtFecha);
        mysqli_stmt_close($stmtFecha);
    }
    
    error_log("=== FECHA ATENCION ORIGINAL ===");
    error_log("Fecha obtenida del historial: " . ($fechaAtencionOriginal ?: 'NULL'));

    // 5) Registrar en historial
    // Detectar si historial tiene id_deposito
    $histHasIdDep = false;
    $resShow = mysqli_query($cn, "SHOW COLUMNS FROM `historial_deposito` LIKE 'id_deposito'");
    if ($resShow && mysqli_num_rows($resShow) > 0) $histHasIdDep = true;
    if ($resShow) mysqli_free_result($resShow);

    $comentario = "Orden adicional agregada por usuario " . ($usuario ?: 'sistema') . " al expediente existente";
    $estadoAnteriorForInsert = $estadoPrev !== null ? $estadoPrev : 3;
    
    // Usar la fecha original si existe, sino NOW()
    $fechaHistorial = $fechaAtencionOriginal ?: date('Y-m-d H:i:s');

    if ($histHasIdDep) {
        $insSql = "INSERT INTO historial_deposito (id_deposito, documento_usuario, fecha_historial_deposito, tipo_evento, estado_anterior, estado_nuevo, comentario_deposito) VALUES (?, ?, ?, 'CAMBIO_ESTADO', ?, ?, ?)";
        $insStmt = mysqli_prepare($cn, $insSql);
        if (!$insStmt) throw new Exception("Error al preparar INSERT historial: " . mysqli_error($cn));
        mysqli_stmt_bind_param($insStmt, "issiis", $newInsertedId, $usuario, $fechaHistorial, $estadoAnteriorForInsert, $newEstado, $comentario);
    } else {
        $insSql = "INSERT INTO historial_deposito (n_deposito, documento_usuario, fecha_historial_deposito, tipo_evento, estado_anterior, estado_nuevo, comentario_deposito) VALUES (?, ?, ?, 'CAMBIO_ESTADO', ?, ?, ?)";
        $insStmt = mysqli_prepare($cn, $insSql);
        if (!$insStmt) throw new Exception("Error al preparar INSERT historial: " . mysqli_error($cn));
        mysqli_stmt_bind_param($insStmt, "sssiis", $n_deposito_nuevo, $usuario, $fechaHistorial, $estadoAnteriorForInsert, $newEstado, $comentario);
    }

    if (!mysqli_stmt_execute($insStmt)) {
        $err = mysqli_error($cn);
        mysqli_stmt_close($insStmt);
        throw new Exception("Error al insertar historial: $err");
    }
    mysqli_stmt_close($insStmt);

    mysqli_commit($cn);

    echo json_encode([
        'success' => true,
        'msg' => 'Orden adicional agregada exitosamente. Se creó un nuevo registro con el mismo expediente.',
        'id_deposito_nuevo' => $newInsertedId,
        'n_deposito' => $n_deposito_nuevo,
        'orden_pdf' => $rutaOrden,
        'resolucion_pdf' => $rutaResol
    ]);
    exit;

} catch (Exception $e) {
    // Limpiar archivos guardados si algo falló
    foreach ($savedFiles as $f) {
        if (file_exists($f)) @unlink($f);
    }
    @mysqli_rollback($cn);
    $msg = '❌ ' . $e->getMessage();
    error_log("back_deposito_agregar_orden_adicional ERROR: " . $e->getMessage());
    echo json_encode(['success' => false, 'msg' => $msg]);
    exit;
}
