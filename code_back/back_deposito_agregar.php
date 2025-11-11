<?php
// back_deposito_agregar.php (versión adaptada para id_deposito + n_deposito NULL)
include("conexion.php");
date_default_timezone_set("America/Lima");

session_start();
header("Content-Type: application/json; charset=utf-8");

function guardar_foto_documento($file, $dni = null, &$errMsg = null) {
    $errMsg = null;
    if (!isset($file) || $file['error'] === UPLOAD_ERR_NO_FILE) return null;

    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errMsg = "Error al subir el archivo (código " . $file['error'] . ").";
        return false;
    }

    $maxSize = 5 * 1024 * 1024; // 5 MB
    if ($file['size'] > $maxSize) {
        $errMsg = "El archivo excede el tamaño máximo (5 MB).";
        return false;
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);
    $allowed = ['image/jpeg' => '.jpg', 'image/png' => '.png'];
    if (!isset($allowed[$mime])) {
        $errMsg = "Tipo de archivo no permitido. Solo PNG/JPG.";
        return false;
    }

    $dirRel = "code_back/documentos";
    $baseDir = __DIR__ . "/documentos";
    if (!is_dir($baseDir)) {
        if (!mkdir($baseDir, 0755, true)) {
            $errMsg = "No se pudo crear la carpeta para guardar las imágenes.";
            return false;
        }
    }

    $prefijo = "";
    if ($dni !== null) {
        $dniClean = preg_replace('/\D+/', '', (string)$dni);
        if ($dniClean !== '') $prefijo = $dniClean . '_';
    }

    try {
        $ext = $allowed[$mime];
        $tries = 0;
        do {
            $random = bin2hex(random_bytes(6));
            $name = $prefijo . $random . $ext;
            $target = $baseDir . '/' . $name;
            $tries++;
        } while (file_exists($target) && $tries < 5);

        if (file_exists($target)) {
            $name = $prefijo . uniqid('', true) . $ext;
            $target = $baseDir . '/' . $name;
        }
    } catch (Exception $e) {
        $name = $prefijo . uniqid('foto_', true) . $allowed[$mime];
        $target = $baseDir . '/' . $name;
    }

    if (!move_uploaded_file($file['tmp_name'], $target)) {
        $errMsg = "No se pudo mover el archivo subido.";
        return false;
    }

    return $dirRel . '/' . $name;
}

function dbpath_to_abspath($dbpath) {
    if (!$dbpath || !is_string($dbpath)) return null;
    $p = ltrim($dbpath, '/');

    if (strpos($p, 'code_back/') === 0) {
        $rel = substr($p, strlen('code_back/'));
        $candidate = __DIR__ . '/' . $rel;
        if (file_exists($candidate)) return $candidate;
        $candidate2 = __DIR__ . '/../' . $p;
        if (file_exists($candidate2)) return $candidate2;
    }

    $candidate = __DIR__ . '/' . $p;
    if (file_exists($candidate)) return $candidate;

    $candidate2 = __DIR__ . '/../' . $p;
    if (file_exists($candidate2)) return $candidate2;

    return __DIR__ . '/documentos/' . basename($p);
}

function table_has_column($cn, $table, $column) {
    $table_esc = mysqli_real_escape_string($cn, $table);
    $column_esc = mysqli_real_escape_string($cn, $column);
    $q = "SHOW COLUMNS FROM `$table_esc` LIKE '$column_esc'";
    $res = mysqli_query($cn, $q);
    if (!$res) return false;
    $has = mysqli_num_rows($res) > 0;
    mysqli_free_result($res);
    return $has;
}

try {
    if (!isset($_SESSION["documento"])) {
        throw new Exception("Usuario no autenticado.");
    }
    $documento_mau = $_SESSION["documento"];

    if (!mysqli_begin_transaction($cn)) {
        throw new Exception("No se pudo iniciar la transacción.");
    }

    // Observación opcional desde el modal (puede venir vacía)
    $observacion_post = isset($_POST['observacion']) ? trim($_POST['observacion']) : null;

    $old_fotos_to_delete = [];

    // --- campos basicos ---
    $exp1 = isset($_POST["expediente_1"]) ? trim($_POST["expediente_1"]) : '';
    $exp2 = isset($_POST["expediente_2"]) ? trim($_POST["expediente_2"]) : '';
    $exp3 = isset($_POST["expediente_3"]) ? trim($_POST["expediente_3"]) : '';
    $n_expediente = "$exp1-$exp2-$exp3";

    // --- Obtener id_juzgado (POST normal o hidden _juzgado_hidden) ---
    // Prioridad: $_POST['juzgado'] (si enviada), luego _juzgado_hidden (hidden que agrega JS)
    $id_juzgado = 0;
    if (isset($_POST["juzgado"]) && trim($_POST["juzgado"]) !== '') {
        $id_juzgado = intval($_POST["juzgado"]);
    } elseif (isset($_POST["_juzgado_hidden"]) && trim($_POST["_juzgado_hidden"]) !== '') {
        $id_juzgado = intval($_POST["_juzgado_hidden"]);
    }

    // FALLBACK: si todavía no tenemos id_juzgado, intentar obtenerlo desde el expediente
    if ($id_juzgado <= 0 && !empty($n_expediente)) {
        $s = mysqli_prepare($cn, "SELECT id_juzgado FROM expediente WHERE n_expediente = ? LIMIT 1");
        if ($s) {
            mysqli_stmt_bind_param($s, "s", $n_expediente);
            mysqli_stmt_execute($s);
            mysqli_stmt_bind_result($s, $id_juzgado_from_db);
            if (mysqli_stmt_fetch($s)) {
                $id_juzgado = intval($id_juzgado_from_db);
            }
            mysqli_stmt_close($s);
        }
    }

    $secretario = isset($_POST["secretario"]) ? trim($_POST["secretario"]) : '';
    $estado = 3; // notificado
    $fechaActual = date('Y-m-d H:i:s');

    if ($exp1 === '' || $exp2 === '' || $exp3 === '' || $secretario === '' || $id_juzgado <= 0) {
        throw new Exception("Complete todos los campos obligatorios.");
    }

    // === VALIDACIÓN CRÍTICA: Verificar que el secretario pertenece al juzgado seleccionado y tiene id_rol 3 ===
    $stmt_val = mysqli_prepare($cn, "SELECT 1 FROM usuario_juzgado uj 
                                      JOIN usuario u ON uj.codigo_usu = u.codigo_usu
                                      WHERE uj.codigo_usu = ? AND uj.id_juzgado = ? AND u.id_rol = 3
                                      LIMIT 1");
    if (!$stmt_val) {
        throw new Exception("Error al preparar validación de secretario: " . mysqli_error($cn));
    }
    mysqli_stmt_bind_param($stmt_val, "si", $secretario, $id_juzgado);
    mysqli_stmt_execute($stmt_val);
    mysqli_stmt_store_result($stmt_val);
    $secretario_valido = mysqli_stmt_num_rows($stmt_val) > 0;
    mysqli_stmt_close($stmt_val);

    if (!$secretario_valido) {
        throw new Exception("El secretario seleccionado no pertenece al juzgado especificado o no tiene permisos suficientes.");
    }

    if (!isset($_POST["txt_nro_deposito"]) || !is_array($_POST["txt_nro_deposito"]) || count($_POST["txt_nro_deposito"]) === 0) {
        throw new Exception("Debe enviar al menos un número de depósito (puede estar vacío si se registra luego).");
    }

    // --- Normalizar beneficiario ---
    $beneficiario_post = null;
    if (!empty($_POST['beneficiario'])) {
        $beneficiario_post = trim($_POST['beneficiario']);
    } elseif (!empty($_POST['input-beneficiario'])) {
        $beneficiario_post = trim($_POST['input-beneficiario']);
    } elseif (!empty($_POST['hid_beneficiario'])) {
        $beneficiario_post = trim($_POST['hid_beneficiario']);
    }

    $doc_beneficiario_post = !empty($_POST['doc_beneficiario']) ? trim($_POST['doc_beneficiario']) : null;

    $dni_benef = '';
    $rutaFotoSubida = null;
    $archivoSubido = false;

    if ($doc_beneficiario_post !== null && $doc_beneficiario_post !== '') {
        // crear nuevo beneficiario
        $dni_benef  = $doc_beneficiario_post;
        $nombre_b   = trim($_POST["nombre_beneficiario"] ?? '');
        $apellido_b = trim($_POST["apellido_beneficiario"] ?? '');
        $id_doc     = isset($_POST["cbo_documento"]) ? intval($_POST["cbo_documento"]) : (isset($_POST["tipo_documento"]) ? intval($_POST["tipo_documento"]) : 1);
        $telefono_b = trim($_POST["telefono_beneficiario"] ?? '');
        $correo_b   = trim($_POST["correo_beneficiario"] ?? '');

        if (!$dni_benef || !$nombre_b || !$apellido_b) {
            throw new Exception("Debe completar todos los campos del nuevo beneficiario.");
        }
        if ($id_doc === 1 && strlen(preg_replace('/\D/','',$dni_benef)) !== 8) throw new Exception("DNI debe tener 8 dígitos.");
        if ($id_doc === 2 && strlen(preg_replace('/\D/','',$dni_benef)) !== 11) throw new Exception("RUC debe tener 11 dígitos.");

        if (isset($_FILES['foto_documento'])) {
            $errUpload = null;
            $ruta = guardar_foto_documento($_FILES['foto_documento'], $dni_benef, $errUpload);
            if ($ruta === false) throw new Exception($errUpload ?? "Error al procesar la foto del documento.");
            if ($ruta !== null) { $rutaFotoSubida = $ruta; $archivoSubido = true; }
        }

        $stmt = mysqli_prepare($cn, "SELECT 1 FROM persona WHERE documento = ?");
        mysqli_stmt_bind_param($stmt, "s", $dni_benef);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_store_result($stmt);
        $exists_persona = mysqli_stmt_num_rows($stmt) > 0;
        mysqli_stmt_close($stmt);

        if (!$exists_persona) {
            if ($rutaFotoSubida !== null) {
                $sqlInsertPersona = "INSERT INTO persona (documento, nombre_persona, apellido_persona, id_documento, telefono_persona, correo_persona, foto_documento) VALUES (?, ?, ?, ?, ?, ?, ?)";
                $stmt = mysqli_prepare($cn, $sqlInsertPersona);
                mysqli_stmt_bind_param($stmt, "sssisss", $dni_benef, $nombre_b, $apellido_b, $id_doc, $telefono_b, $correo_b, $rutaFotoSubida);
            } else {
                $sqlInsertPersona = "INSERT INTO persona (documento, nombre_persona, apellido_persona, id_documento, telefono_persona, correo_persona) VALUES (?, ?, ?, ?, ?, ?)";
                $stmt = mysqli_prepare($cn, $sqlInsertPersona);
                mysqli_stmt_bind_param($stmt, "sssiss", $dni_benef, $nombre_b, $apellido_b, $id_doc, $telefono_b, $correo_b);
            }
            if (!$stmt) throw new Exception("Error al preparar inserción de persona: " . mysqli_error($cn));
            if (!mysqli_stmt_execute($stmt)) {
                $err = mysqli_stmt_error($stmt);
                mysqli_stmt_close($stmt);
                throw new Exception("Error al insertar persona: $err");
            }
            mysqli_stmt_close($stmt);
        } else {
            if ($rutaFotoSubida !== null) {
                $oldFotoDb = '';
                $s2 = mysqli_prepare($cn, "SELECT foto_documento FROM persona WHERE documento = ?");
                if ($s2) {
                    mysqli_stmt_bind_param($s2, "s", $dni_benef);
                    mysqli_stmt_execute($s2);
                    mysqli_stmt_bind_result($s2, $oldFotoDb);
                    mysqli_stmt_fetch($s2);
                    mysqli_stmt_close($s2);
                }
                $stmt = mysqli_prepare($cn, "UPDATE persona SET foto_documento = ? WHERE documento = ?");
                mysqli_stmt_bind_param($stmt, "ss", $rutaFotoSubida, $dni_benef);
                if (!$stmt) throw new Exception("Error al preparar update de foto: " . mysqli_error($cn));
                if (!mysqli_stmt_execute($stmt)) { $err = mysqli_stmt_error($stmt); mysqli_stmt_close($stmt); throw new Exception("Error al actualizar foto de persona: $err"); }
                mysqli_stmt_close($stmt);

                if (!empty($oldFotoDb) && $oldFotoDb !== $rutaFotoSubida) {
                    $old_fotos_to_delete[] = $oldFotoDb;
                }
            }
        }

        $stmt = mysqli_prepare($cn, "SELECT 1 FROM beneficiario WHERE documento = ?");
        mysqli_stmt_bind_param($stmt, "s", $dni_benef);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_store_result($stmt);
        $exists_bene = mysqli_stmt_num_rows($stmt) > 0;
        mysqli_stmt_close($stmt);

        if (!$exists_bene) {
            $stmt = mysqli_prepare($cn, "INSERT INTO beneficiario (id_documento, documento) VALUES (?, ?)");
            mysqli_stmt_bind_param($stmt, "is", $id_doc, $dni_benef);
            if (!mysqli_stmt_execute($stmt)) { $err = mysqli_stmt_error($stmt); mysqli_stmt_close($stmt); throw new Exception("Error al insertar beneficiario: $err"); }
            mysqli_stmt_close($stmt);
        }

    } elseif ($beneficiario_post !== null && $beneficiario_post !== '') {
        // beneficiario existente
        $dni_benef = $beneficiario_post;

        if (isset($_FILES['foto_documento'])) {
            $errUpload = null;
            $ruta = guardar_foto_documento($_FILES['foto_documento'], $dni_benef, $errUpload);
            if ($ruta === false) throw new Exception($errUpload ?? "Error al procesar la foto del documento.");
            if ($ruta !== null) {
                $oldFotoDb = '';
                $s2 = mysqli_prepare($cn, "SELECT foto_documento FROM persona WHERE documento = ?");
                if ($s2) {
                    mysqli_stmt_bind_param($s2, "s", $dni_benef);
                    mysqli_stmt_execute($s2);
                    mysqli_stmt_bind_result($s2, $oldFotoDb);
                    mysqli_stmt_fetch($s2);
                    mysqli_stmt_close($s2);
                }

                $stmt = mysqli_prepare($cn, "UPDATE persona SET foto_documento = ? WHERE documento = ?");
                mysqli_stmt_bind_param($stmt, "ss", $ruta, $dni_benef);
                if (!$stmt) throw new Exception("Error al preparar update de foto: " . mysqli_error($cn));
                if (!mysqli_stmt_execute($stmt)) { $err = mysqli_stmt_error($stmt); mysqli_stmt_close($stmt); throw new Exception("Error al actualizar foto de persona: $err"); }
                mysqli_stmt_close($stmt);

                if (!empty($oldFotoDb) && $oldFotoDb !== $ruta) {
                    $old_fotos_to_delete[] = $oldFotoDb;
                }

                $rutaFotoSubida = $ruta;
                $archivoSubido = true;
            }
        }

    } else {
        $keys = array_keys($_POST);
        $files = array_keys($_FILES);
        echo json_encode(["success" => false, "message" => "No se recibió beneficiario (existing o nuevo).", "post_keys" => $keys, "file_keys" => $files]);
        exit;
    }

    // --- Insertar expediente si no existe (SIN documento_beneficiario) ---
    $stmt = mysqli_prepare($cn, "SELECT 1 FROM expediente WHERE n_expediente = ?");
    mysqli_stmt_bind_param($stmt, "s", $n_expediente);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_store_result($stmt);
    $exists_ex = mysqli_stmt_num_rows($stmt) > 0;
    mysqli_stmt_close($stmt);

    if (!$exists_ex) {
        $stmt = mysqli_prepare($cn, "INSERT INTO expediente (n_expediente, id_juzgado) VALUES (?, ?)");
        mysqli_stmt_bind_param($stmt, "si", $n_expediente, $id_juzgado);
        if (!mysqli_stmt_execute($stmt)) { $err = mysqli_stmt_error($stmt); mysqli_stmt_close($stmt); throw new Exception("Error al registrar expediente: $err"); }
        mysqli_stmt_close($stmt);
    }

    // --- Asociar beneficiario al expediente en tabla intermedia ---
    $stmt = mysqli_prepare($cn, "INSERT IGNORE INTO expediente_beneficiario (n_expediente, documento_beneficiario) VALUES (?, ?)");
    mysqli_stmt_bind_param($stmt, "ss", $n_expediente, $dni_benef);
    if (!mysqli_stmt_execute($stmt)) { 
        $err = mysqli_stmt_error($stmt); 
        mysqli_stmt_close($stmt); 
        throw new Exception("Error al asociar beneficiario al expediente: $err"); 
    }
    mysqli_stmt_close($stmt);

    // --- Insertar depósitos e historial ---
    $notifications_to_add = [];

    // Detectar si historial_deposito tiene id_deposito
    $hist_has_id_dep = table_has_column($cn, 'historial_deposito', 'id_deposito');

    foreach ($_POST["txt_nro_deposito"] as $n_deposito_raw) {
        $n_deposito = trim($n_deposito_raw);

        // Si viene vacío, lo tratamos como NULL y no validamos longitud ni unicidad.
        $n_deposito_var = ($n_deposito === '') ? null : $n_deposito;

        if ($n_deposito_var !== null) {
            if (strlen($n_deposito_var) < 8 || strlen($n_deposito_var) > 13) {
                throw new Exception("Todos los depósitos (cuando se proveen) deben tener entre 8 y 13 caracteres. Depósito inválido: $n_deposito_var");
            }

            // verificar existencia solo si se pasó un número concreto
            $stmt = mysqli_prepare($cn, "SELECT 1 FROM deposito_judicial WHERE n_deposito = ?");
            mysqli_stmt_bind_param($stmt, "s", $n_deposito_var);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_store_result($stmt);
            $exists_dep = mysqli_stmt_num_rows($stmt) > 0;
            mysqli_stmt_close($stmt);

            if ($exists_dep) throw new Exception("El número de depósito ya existe en el sistema: $n_deposito_var");
        } else {
            // si es NULL, no chequeamos unicidad
            $exists_dep = false;
        }

        // insertar deposito; n_deposito puede ser NULL
        $estado_str = (string)$estado;
        $fecha_bind = $fechaActual;

        // Preparar observación: si viene vacía la guardamos como NULL en la BD
        $has_obs = isset($observacion_post) && $observacion_post !== '';
        $bind_obs = $observacion_post;

        if ($n_deposito_var !== null) {
            if ($has_obs) {
                $stmt = mysqli_prepare($cn, "INSERT INTO deposito_judicial (n_deposito, n_expediente, documento_beneficiario, documento_secretario, documento_mau, id_estado, fecha_notificacion_deposito, observacion) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                if (!$stmt) throw new Exception("Error al preparar inserción de depósito: " . mysqli_error($cn));
                $bind_n = $n_deposito_var;
                $bind_exp = $n_expediente;
                $bind_benef = $dni_benef;
                $bind_sec = $secretario;
                $bind_mau = $documento_mau;
                $bind_estado = $estado_str;
                $bind_fecha = $fecha_bind;
                mysqli_stmt_bind_param($stmt, "ssssssss", $bind_n, $bind_exp, $bind_benef, $bind_sec, $bind_mau, $bind_estado, $bind_fecha, $bind_obs);
            } else {
                $stmt = mysqli_prepare($cn, "INSERT INTO deposito_judicial (n_deposito, n_expediente, documento_beneficiario, documento_secretario, documento_mau, id_estado, fecha_notificacion_deposito, observacion) VALUES (?, ?, ?, ?, ?, ?, ?, NULL)");
                if (!$stmt) throw new Exception("Error al preparar inserción de depósito (sin observación): " . mysqli_error($cn));
                $bind_n = $n_deposito_var;
                $bind_exp = $n_expediente;
                $bind_benef = $dni_benef;
                $bind_sec = $secretario;
                $bind_mau = $documento_mau;
                $bind_estado = $estado_str;
                $bind_fecha = $fecha_bind;
                mysqli_stmt_bind_param($stmt, "sssssss", $bind_n, $bind_exp, $bind_benef, $bind_sec, $bind_mau, $bind_estado, $bind_fecha);
            }
        } else {
            // n_deposito NULL literal
            if ($has_obs) {
                $stmt = mysqli_prepare($cn, "INSERT INTO deposito_judicial (n_deposito, n_expediente, documento_beneficiario, documento_secretario, documento_mau, id_estado, fecha_notificacion_deposito, observacion) VALUES (NULL, ?, ?, ?, ?, ?, ?, ?)");
                if (!$stmt) throw new Exception("Error al preparar inserción de depósito (NULL): " . mysqli_error($cn));
                $bind_exp = $n_expediente;
                $bind_benef = $dni_benef;
                $bind_sec = $secretario;
                $bind_mau = $documento_mau;
                $bind_estado = $estado_str;
                $bind_fecha = $fecha_bind;
                mysqli_stmt_bind_param($stmt, "sssssss", $bind_exp, $bind_benef, $bind_sec, $bind_mau, $bind_estado, $bind_fecha, $bind_obs);
            } else {
                $stmt = mysqli_prepare($cn, "INSERT INTO deposito_judicial (n_deposito, n_expediente, documento_beneficiario, documento_secretario, documento_mau, id_estado, fecha_notificacion_deposito, observacion) VALUES (NULL, ?, ?, ?, ?, ?, ?, NULL)");
                if (!$stmt) throw new Exception("Error al preparar inserción de depósito (NULL sin observación): " . mysqli_error($cn));
                $bind_exp = $n_expediente;
                $bind_benef = $dni_benef;
                $bind_sec = $secretario;
                $bind_mau = $documento_mau;
                $bind_estado = $estado_str;
                $bind_fecha = $fecha_bind;
                mysqli_stmt_bind_param($stmt, "ssssss", $bind_exp, $bind_benef, $bind_sec, $bind_mau, $bind_estado, $bind_fecha);
            }
        }

        if (!mysqli_stmt_execute($stmt)) {
            $err = mysqli_stmt_error($stmt);
            mysqli_stmt_close($stmt);
            throw new Exception("Error al registrar el depósito " . ($n_deposito_var ?? '(sin número)') . ": $err");
        }
        mysqli_stmt_close($stmt);

        // obtener id_deposito (auto-increment)
        $id_deposito = mysqli_insert_id($cn);

        // insertar historial: si existe columna id_deposito usamos esa, sino usamos n_deposito como antes
        $estado_anterior = 4;
        $estado_nuevo = 3;
        $tipo_evento = 'CAMBIO_ESTADO';
        $comentario = 'Depósito notificado automáticamente al registrar';
        // NO concatenar la observación al comentario - se manejará como campo separado
        $fecha_hist = $fechaActual;

        // usar tipos correctos para bind
        $bind_ea = (int)$estado_anterior;
        $bind_en = (int)$estado_nuevo;

        if ($hist_has_id_dep) {
            // Insert usando id_deposito
            $stmt = mysqli_prepare($cn, "INSERT INTO historial_deposito (id_deposito, documento_usuario, comentario_deposito, fecha_historial_deposito, tipo_evento, estado_anterior, estado_nuevo) VALUES (?, ?, ?, ?, ?, ?, ?)");
            if (!$stmt) {
                $err = mysqli_error($cn);
                throw new Exception("Error al preparar inserción de historial (id_deposito): $err");
            }
            $bind_iddep = (int)$id_deposito;
            $bind_doc = $documento_mau;
            $bind_com = $comentario;
            $bind_fec = $fecha_hist;
            $bind_tip = $tipo_evento;

            // tipos: i (id_deposito), s (doc), s (comentario), s (fecha), s (tipo_evento), i (estado_anterior), i (estado_nuevo)
            mysqli_stmt_bind_param($stmt, "issssii", $bind_iddep, $bind_doc, $bind_com, $bind_fec, $bind_tip, $bind_ea, $bind_en);
            if (!mysqli_stmt_execute($stmt)) {
                $err = mysqli_stmt_error($stmt);
                mysqli_stmt_close($stmt);
                throw new Exception("Error al registrar historial (id_deposito) para depósito $id_deposito: $err");
            }
            mysqli_stmt_close($stmt);
        } else {
            // Compatibilidad: usar n_deposito en historial (si no existe, pasar NULL)
            if ($n_deposito_var !== null) {
                $stmt = mysqli_prepare($cn, "INSERT INTO historial_deposito (n_deposito, documento_usuario, comentario_deposito, fecha_historial_deposito, tipo_evento, estado_anterior, estado_nuevo) VALUES (?, ?, ?, ?, ?, ?, ?)");
                if (!$stmt) {
                    $err = mysqli_error($cn);
                    throw new Exception("Error al preparar inserción de historial (n_deposito): $err");
                }
                $bind_nhist = $n_deposito_var;
                $bind_doc = $documento_mau;
                $bind_com = $comentario;
                $bind_fec = $fecha_hist;
                $bind_tip = $tipo_evento;

                // tipos: s (n_deposito), s (doc), s (comentario), s (fecha), s (tipo), i (estado_anterior), i (estado_nuevo)
                mysqli_stmt_bind_param($stmt, "sssssii", $bind_nhist, $bind_doc, $bind_com, $bind_fec, $bind_tip, $bind_ea, $bind_en);
            } else {
                // n_deposito NULL -> insertar con NULL literal (6 placeholders)
                $stmt = mysqli_prepare($cn, "INSERT INTO historial_deposito (n_deposito, documento_usuario, comentario_deposito, fecha_historial_deposito, tipo_evento, estado_anterior, estado_nuevo) VALUES (NULL, ?, ?, ?, ?, ?, ?)");
                if (!$stmt) {
                    $err = mysqli_error($cn);
                    throw new Exception("Error al preparar inserción de historial (n_deposito NULL): $err");
                }
                $bind_doc = $documento_mau;
                $bind_com = $comentario;
                $bind_fec = $fecha_hist;
                $bind_tip = $tipo_evento;

                // tipos: s (doc), s (comentario), s (fecha), s (tipo), i (estado_anterior), i (estado_nuevo)
                mysqli_stmt_bind_param($stmt, "ssssii", $bind_doc, $bind_com, $bind_fec, $bind_tip, $bind_ea, $bind_en);
            }

            if (!mysqli_stmt_execute($stmt)) {
                $err = mysqli_stmt_error($stmt);
                mysqli_stmt_close($stmt);
                throw new Exception("Error al registrar historial (n_deposito) para depósito " . ($n_deposito_var ?? '(sin número)') . ": $err");
            }
            mysqli_stmt_close($stmt);
        }

        $notifications_to_add[] = [
            'destinatario' => 'secretario',
            'id_usuario'   => $secretario,
            'titulo'       => 'Nuevo Depósito',
            'mensaje'      => "Depósito N° " . ($n_deposito_var ?? '(por asignar)') . " - Expediente: $n_expediente",
            'n_deposito'   => $n_deposito_var,
            'id_deposito'  => $id_deposito,
            'n_expediente' => $n_expediente,
            'fecha'        => $fechaActual,
            'timestamp'    => time()
        ];
    }

    // persistir notificaciones
    $file_json = __DIR__ . "/../api/notis_estado.json";
    $data = [];
    if (file_exists($file_json)) {
        $raw = file_get_contents($file_json);
        $data = json_decode($raw, true);
        if (!is_array($data)) $data = ['last_read' => 0, 'notifications' => []];
    } else {
        $data = ['last_read' => 0, 'notifications' => []];
    }
    foreach ($notifications_to_add as $n) $data['notifications'][] = $n;
    if (file_put_contents($file_json, json_encode($data, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE), LOCK_EX) === false) {
        throw new Exception("Error al escribir archivo de notificaciones.");
    }

    if (!mysqli_commit($cn)) throw new Exception("No se pudo confirmar la transacción.");

    // --- borrar fotos antiguas ahora que todo fue commitado (si corresponde) ---
    if (!empty($old_fotos_to_delete)) {
        foreach ($old_fotos_to_delete as $oldRel) {
            $abs = dbpath_to_abspath($oldRel);
            if ($abs && file_exists($abs)) {
                @unlink($abs);
            }
        }
    }

    echo json_encode(["success" => true, "message" => "Depósito(s) registrado(s) y notificado(s) correctamente."]);
    exit();

} catch (Exception $e) {
    if (isset($cn)) @mysqli_rollback($cn);

    if (!empty($rutaFotoSubida) && isset($archivoSubido) && $archivoSubido) {
        $possible = __DIR__ . '/documentos/' . basename($rutaFotoSubida);
        if (file_exists($possible)) @unlink($possible);
        $possible2 = __DIR__ . '/../' . ltrim($rutaFotoSubida, '/');
        if (file_exists($possible2)) @unlink($possible2);
    }

    $msg = $e->getMessage();
    echo json_encode(["success" => false, "message" => $msg]);
    exit();
}