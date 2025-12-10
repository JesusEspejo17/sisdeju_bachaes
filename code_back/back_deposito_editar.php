<?php
// back_deposito_editar.php (v4 - COMPLETO CON BENEFICIARIO)
// - Acepta editar: beneficiario, expediente, número de depósito, secretario, fecha de recojo
// - Valida y actualiza todos los campos enviados
// - Registra historial detallado de cambios
// - Usa consultas preparadas y transacción

error_reporting(0);
ini_set('display_errors', 0);

session_start();
include("conexion.php");

header('Content-Type: application/json; charset=utf-8');

// ============== LOG: POST Y FILES RECIBIDOS ==============
error_log("=== BACK_DEPOSITO_EDITAR.PHP - INICIO ===");
error_log("POST recibido: " . print_r($_POST, true));
error_log("FILES recibido: " . print_r($_FILES, true));

// Datos mínimos
if (!isset($_POST['id_deposito']) || !isset($_POST['nuevo_expediente']) || !isset($_POST['beneficiario'])) {
  error_log("ERROR: Faltan datos mínimos");
  echo json_encode(['success' => false, 'msg' => 'Faltan datos mínimos (id_deposito, nuevo_expediente, beneficiario).']);
  exit;
}

$idDep = (int)$_POST['id_deposito'];
$nuevoExp = trim($_POST['nuevo_expediente']);
$beneficiarioDoc = trim($_POST['beneficiario']);
$nuevoDep = isset($_POST['nuevo_deposito']) ? trim($_POST['nuevo_deposito']) : null;
$docSecretario = isset($_POST['documento_secretario']) ? trim($_POST['documento_secretario']) : null;
$fechaRecojo = isset($_POST['fecha_recojo']) ? trim($_POST['fecha_recojo']) : null;
// Permitir que el cliente envíe un id_juzgado cuando cree/edite expediente
$nuevoIdJuzgadoSeleccionado = isset($_POST['id_juzgado']) && $_POST['id_juzgado'] !== '' ? (int)$_POST['id_juzgado'] : null;
// Campo de observación (opcional)
$nuevaObservacion = isset($_POST['observacion']) ? trim($_POST['observacion']) : null;

if ($beneficiarioDoc === '') {
  echo json_encode(['success' => false, 'msg' => 'Debe seleccionar un beneficiario.']);
  exit;
}

if ($nuevoExp === '') {
  echo json_encode(['success' => false, 'msg' => 'El número de expediente no puede estar vacío.']);
  exit;
}

// Validar formato nuevo_deposito si se envió
if ($nuevoDep !== null && $nuevoDep !== '') {
  if (!preg_match('/^\d{13}$/', $nuevoDep) && !preg_match('/^\d{13}-\d{3}$/', $nuevoDep)) {
    echo json_encode(['success' => false, 'msg' => 'El número de depósito debe tener formato XXXXXXXXXXXXX (13 caracteres) o XXXXXXXXXXXXX-XXX (17 caracteres con guión).']);
    exit;
  }
}

// Usuario desde sesión
$usuario = $_SESSION['documento'] ?? null;
$rolUsuario = $_SESSION['rol'] ?? null;

if (!$usuario) {
  echo json_encode(['success' => false, 'msg' => 'Sesión no iniciada.']);
  exit;
}

if (!$rolUsuario) {
  echo json_encode(['success' => false, 'msg' => 'No tiene permisos para esta acción.']);
  exit;
}

if (!($cn instanceof mysqli)) {
  echo json_encode(['success' => false, 'msg' => 'Error de conexión a la base de datos.']);
  exit;
}

try {
  $cn->begin_transaction();

  // 1) Obtener depósito original completo (incluyendo observacion y beneficiario del depósito)
  $stmt = $cn->prepare("SELECT d.id_deposito, d.n_deposito, d.n_expediente, d.documento_beneficiario, d.documento_secretario, d.fecha_recojo_deposito, d.observacion,
                               e.id_juzgado
                        FROM deposito_judicial d
                        LEFT JOIN expediente e ON d.n_expediente = e.n_expediente
                        WHERE d.id_deposito = ? LIMIT 1");
  $stmt->bind_param("i", $idDep);
  
  if (!$stmt->execute()) {
    $cn->rollback();
    echo json_encode(['success' => false, 'msg' => 'Error al buscar depósito original.']);
    exit;
  }
  
  $res = $stmt->get_result();
  if (!$res || $res->num_rows === 0) {
    $cn->rollback();
    echo json_encode(['success' => false, 'msg' => 'Depósito original no encontrado.']);
    exit;
  }
  
  $row = $res->fetch_assoc();
  $originalDepValue = $row['n_deposito'];
  $expAnterior = $row['n_expediente'];
  $secAnterior = $row['documento_secretario'];
  $fechaRecojoAnterior = $row['fecha_recojo_deposito'];
  $beneficiarioAnterior = $row['documento_beneficiario'];
  $idJuzgadoAnterior = $row['id_juzgado'];
  $observacionAnterior = $row['observacion'];
  $stmt->close();

  error_log("=== DATOS ORIGINALES DEL DEPÓSITO ===");
  error_log("Original n_deposito: " . ($originalDepValue ?? 'NULL'));
  error_log("Original expediente: " . ($expAnterior ?? 'NULL'));
  error_log("Original secretario: " . ($secAnterior ?? 'NULL'));
  error_log("Original fecha_recojo: " . ($fechaRecojoAnterior ?? 'NULL'));
  error_log("Original beneficiario: " . ($beneficiarioAnterior ?? 'NULL'));
  error_log("Original id_juzgado: " . ($idJuzgadoAnterior ?? 'NULL'));
  error_log("Original observacion: " . ($observacionAnterior ?? 'NULL'));

  // 2) Determinar cambios
  $cambios = [];
  $cambioDep = false;
  $cambioExp = false;
  $cambioSec = false;
  $cambioFechaRecojo = false;
  $cambioBeneficiario = false;
  $cambioObservacion = false;

  error_log("=== COMPARANDO CAMBIOS EN DEPÓSITO ===");

  // Si cambió beneficiario
  if ($beneficiarioAnterior !== $beneficiarioDoc) {
    error_log("✓ CAMBIO DETECTADO: Beneficiario ($beneficiarioAnterior -> $beneficiarioDoc)");
    // Validar que el nuevo beneficiario existe
    $stmt = $cn->prepare("SELECT documento FROM beneficiario WHERE documento = ? LIMIT 1");
    $stmt->bind_param("s", $beneficiarioDoc);
    $stmt->execute();
    $r = $stmt->get_result();
    if (!$r || $r->num_rows === 0) {
      $cn->rollback();
      echo json_encode(['success' => false, 'msg' => 'El beneficiario seleccionado no existe.']);
      exit;
    }
    $stmt->close();
    $cambioBeneficiario = true;
    $cambios[] = "Beneficiario: " . ($beneficiarioAnterior ?: '(vacío)') . " → {$beneficiarioDoc}";
  }

  // Si se envió nuevo_deposito y es distinto al actual -> validar unicidad
  if ($nuevoDep !== null && $nuevoDep !== '') {
    if ($originalDepValue !== $nuevoDep) {
      // Verificar unicidad
      $stmt = $cn->prepare("SELECT id_deposito FROM deposito_judicial WHERE n_deposito = ? LIMIT 1");
      $stmt->bind_param("s", $nuevoDep);
      $stmt->execute();
      $r = $stmt->get_result();
      if ($r && $r->num_rows > 0) {
        $other = $r->fetch_assoc();
        if ((int)$other['id_deposito'] !== $idDep) {
          $cn->rollback();
          echo json_encode(['success' => false, 'msg' => 'El número de depósito ya existe en otro registro.']);
          exit;
        }
      }
      $stmt->close();
      $cambioDep = true;
      $cambios[] = "N° Depósito: " . ($originalDepValue ?: '(vacío)') . " → {$nuevoDep}";
    }
  }

  // Si nuevo expediente distinto al actual -> marcar cambio
  if ($expAnterior !== $nuevoExp) {
    error_log("✓ CAMBIO DETECTADO: Expediente ($expAnterior -> $nuevoExp)");
    $cambioExp = true;
    $cambios[] = "N° Expediente: {$expAnterior} → {$nuevoExp}";
  }

  // Si cambió secretario
  if ($docSecretario && $secAnterior !== $docSecretario) {
    error_log("✓ CAMBIO DETECTADO: Secretario ($secAnterior -> $docSecretario)");
    // === VALIDACIÓN: Verificar que el secretario existe, tiene id_rol 3 y pertenece a un juzgado válido ===
    $stmt_val_sec = $cn->prepare("SELECT 1 FROM usuario_juzgado uj 
                                   JOIN usuario u ON uj.codigo_usu = u.codigo_usu
                                   WHERE uj.codigo_usu = ? AND u.id_rol = 3
                                   LIMIT 1");
    if (!$stmt_val_sec) {
      $cn->rollback();
      echo json_encode(['success' => false, 'msg' => 'Error al validar secretario: ' . $cn->error]);
      exit;
    }
    
    $stmt_val_sec->bind_param("s", $docSecretario);
    $stmt_val_sec->execute();
    $res_val = $stmt_val_sec->get_result();
    if (!$res_val || $res_val->num_rows === 0) {
      $cn->rollback();
      echo json_encode(['success' => false, 'msg' => 'El secretario seleccionado no existe o no tiene los permisos requeridos.']);
      exit;
    }
    $stmt_val_sec->close();
    
    $cambioSec = true;
    $cambios[] = "Secretario cambiado";
  }

  // Si cambió fecha de recojo (solo secretarios y admin pueden cambiarla)
  $fechaRecojoNormalizada = null;
  if ($fechaRecojo !== null && $fechaRecojo !== '') {
    // Validar que solo Admin (1) y Secretarios (3) puedan editar fecha de recojo
    if ($rolUsuario == 2) {
      // MAU no puede editar fecha de recojo
      $cn->rollback();
      echo json_encode(['success' => false, 'msg' => 'El rol MAU no tiene permisos para editar la fecha de recojo.']);
      exit;
    }
    
    $fechaRecojoNormalizada = date('Y-m-d H:i:s', strtotime($fechaRecojo));
    if ($fechaRecojoAnterior !== $fechaRecojoNormalizada) {
      $cambioFechaRecojo = true;
      $cambios[] = "Fecha de recojo: " . ($fechaRecojoAnterior ?: '(vacío)') . " → " . ($fechaRecojoNormalizada ?: '(vacío)');
    }
  }

  // Si cambió observación (solo MAU rol 2 y Admin rol 1 pueden editarla)
  if ($nuevaObservacion !== null && ($rolUsuario == 1 || $rolUsuario == 2)) {
    if ($nuevaObservacion !== '') {
      if ($observacionAnterior !== $nuevaObservacion) {
        error_log("✓ CAMBIO DETECTADO: Observación actualizada");
        $cambioObservacion = true;
        $cambios[] = "Observación actualizada";
      }
    } elseif ($nuevaObservacion === '' && $observacionAnterior !== null && $observacionAnterior !== '') {
      // Si se vació la observación
      error_log("✓ CAMBIO DETECTADO: Observación eliminada");
      $cambioObservacion = true;
      $cambios[] = "Observación eliminada";
    }
  }

  error_log("=== RESUMEN CAMBIOS DEPÓSITO ===");
  error_log("cambioDep: " . ($cambioDep ? 'TRUE' : 'FALSE'));
  error_log("cambioExp: " . ($cambioExp ? 'TRUE' : 'FALSE'));
  error_log("cambioSec: " . ($cambioSec ? 'TRUE' : 'FALSE'));
  error_log("cambioFechaRecojo: " . ($cambioFechaRecojo ? 'TRUE' : 'FALSE'));
  error_log("cambioBeneficiario: " . ($cambioBeneficiario ? 'TRUE' : 'FALSE'));
  error_log("cambioObservacion: " . ($cambioObservacion ? 'TRUE' : 'FALSE'));

  if (!$cambioDep && !$cambioExp && !$cambioSec && !$cambioFechaRecojo && !$cambioBeneficiario && !$cambioObservacion) {
    error_log("❌ NO SE DETECTARON CAMBIOS EN DEPÓSITO - VERIFICANDO BENEFICIARIO...");
    // NO salir aquí, primero verificar si hay cambios en beneficiario
  } else {
    error_log("✓ SE DETECTARON CAMBIOS EN DEPÓSITO");
  }

  // 3) Si cambió expediente o beneficiario, gestionar expediente y relación
  if ($cambioExp || $cambioBeneficiario) {
    // Verificar si nuevo expediente ya existe
    $stmt = $cn->prepare("SELECT id_juzgado FROM expediente WHERE n_expediente = ? LIMIT 1");
    $stmt->bind_param("s", $nuevoExp);
    $stmt->execute();
    $r3 = $stmt->get_result();
    
    if (!$r3 || $r3->num_rows === 0) {
      // El expediente no existe -> crear nuevo SIN beneficiario
      $stmt->close();
      // Si el cliente envió un id_juzgado, utilízalo; si no, usa el juzgado anterior
      $idJuzgadoParaInsert = $nuevoIdJuzgadoSeleccionado !== null ? $nuevoIdJuzgadoSeleccionado : $idJuzgadoAnterior;
      $stmt = $cn->prepare("INSERT INTO expediente (n_expediente, id_juzgado) VALUES (?, ?)");
      $stmt->bind_param("si", $nuevoExp, $idJuzgadoParaInsert);
      if (!$stmt->execute()) {
        $cn->rollback();
        echo json_encode(['success' => false, 'msg' => 'Error al crear el nuevo expediente.']);
        exit;
      }
      $stmt->close();
    } else {
      $stmt->close();
    }
    
    // Asociar beneficiario al expediente (si aún no está asociado)
    $stmt = $cn->prepare("INSERT IGNORE INTO expediente_beneficiario (n_expediente, documento_beneficiario) VALUES (?, ?)");
    $stmt->bind_param("ss", $nuevoExp, $beneficiarioDoc);
    if (!$stmt->execute()) {
      $cn->rollback();
      echo json_encode(['success' => false, 'msg' => 'Error al asociar beneficiario al expediente.']);
      exit;
    }
    $stmt->close();
  }

  // 4) Actualizar deposito_judicial: construir SET dinámico según cambios
  $sets = [];
  $types = "";
  $values = [];
  
  if ($cambioDep) {
    $sets[] = "n_deposito = ?";
    $types .= "s";
    $values[] = $nuevoDep;
  }
  if ($cambioExp) {
    $sets[] = "n_expediente = ?";
    $types .= "s";
    $values[] = $nuevoExp;
  }
  if ($cambioBeneficiario) {
    $sets[] = "documento_beneficiario = ?";
    $types .= "s";
    $values[] = $beneficiarioDoc;
  }
  if ($cambioSec) {
    $sets[] = "documento_secretario = ?";
    $types .= "s";
    $values[] = $docSecretario;
  }
  if ($cambioFechaRecojo) {
    $sets[] = "fecha_recojo_deposito = ?";
    $types .= "s";
    $values[] = $fechaRecojoNormalizada;
  }
  if ($cambioObservacion) {
    $sets[] = "observacion = ?";
    $types .= "s";
    $values[] = $nuevaObservacion !== '' ? $nuevaObservacion : null;
  }
  
  // Solo ejecutar UPDATE si hay cambios en deposito_judicial
  if (count($sets) > 0) {
    $sql = "UPDATE deposito_judicial SET " . implode(", ", $sets) . " WHERE id_deposito = ?";
    $types .= "i";
    $values[] = $idDep;

    $stmt = $cn->prepare($sql);
    
    if (!$stmt) {
      $cn->rollback();
      echo json_encode(['success' => false, 'msg' => 'Error al preparar actualización del depósito.']);
      exit;
    }
    
    // bind_param dinámico - Método correcto
    $params = [$types];
    foreach ($values as $key => $value) {
      $params[] = &$values[$key];
    }
    
    call_user_func_array([$stmt, 'bind_param'], $params);

    if (!$stmt->execute()) {
      $cn->rollback();
      echo json_encode(['success' => false, 'msg' => 'Error al actualizar el depósito: ' . $stmt->error]);
      exit;
    }
    $stmt->close();
  } // Fin del if (count($sets) > 0)

  // 5) Si el expediente anterior quedó sin depósitos asociados, eliminarlo (si cambió)
  if ($cambioExp && $expAnterior !== null && $expAnterior !== '') {
    $stmt = $cn->prepare("SELECT COUNT(*) AS cantidad FROM deposito_judicial WHERE n_expediente = ?");
    $stmt->bind_param("s", $expAnterior);
    $stmt->execute();
    $r4 = $stmt->get_result();
    $cnt = 0;
    if ($r4 && $r4->num_rows > 0) {
      $cnt = (int)$r4->fetch_assoc()['cantidad'];
    }
    $stmt->close();
    if ($cnt === 0) {
      $stmt = $cn->prepare("DELETE FROM expediente WHERE n_expediente = ?");
      $stmt->bind_param("s", $expAnterior);
      $stmt->execute();
      $stmt->close();
    }
  }

  // 6) Registrar historial con detalle de cambios
  $comentario = "Edición realizada por el usuario.\nCambios:\n" . implode("\n", $cambios);

  $stmt = $cn->prepare("
    INSERT INTO historial_deposito
      (id_deposito, documento_usuario, comentario_deposito, fecha_historial_deposito, tipo_evento)
    VALUES (?, ?, ?, NOW(), 'EDICION')
  ");
  
  $stmt->bind_param("iss", $idDep, $usuario, $comentario);
  
  if (!$stmt->execute()) {
    // no hacemos rollback completo por fallo al insertar historial; hacemos commit y avisamos
    $cn->commit();
    echo json_encode(['success' => true, 'msg' => 'Datos actualizados (pero no se pudo guardar el historial).']);
    exit;
  }
  $stmt->close();

  // ========== 7) ACTUALIZAR DATOS DEL BENEFICIARIO (si se enviaron) ==========
  error_log("=== INICIO PROCESAMIENTO BENEFICIARIO ===");
  error_log("POST recibido: " . print_r($_POST, true));
  error_log("FILES recibido: " . print_r($_FILES, true));
  
  $benefNombre = isset($_POST['benef_nombre']) ? trim($_POST['benef_nombre']) : null;
  $benefApellido = isset($_POST['benef_apellido']) ? trim($_POST['benef_apellido']) : null;
  $benefCorreo = isset($_POST['benef_correo']) ? trim($_POST['benef_correo']) : null;
  $benefTelefono = isset($_POST['benef_telefono']) ? trim($_POST['benef_telefono']) : null;
  $benefDireccion = isset($_POST['benef_direccion']) ? trim($_POST['benef_direccion']) : null;
  $benefFoto = isset($_FILES['benef_foto']) && $_FILES['benef_foto']['error'] === UPLOAD_ERR_OK ? $_FILES['benef_foto'] : null;

  error_log("Valores extraídos:");
  error_log("  benefNombre: " . ($benefNombre ?: 'NULL'));
  error_log("  benefApellido: " . ($benefApellido ?: 'NULL'));
  error_log("  benefCorreo: " . ($benefCorreo ?: 'NULL'));
  error_log("  benefTelefono: " . ($benefTelefono ?: 'NULL'));
  error_log("  benefDireccion: " . ($benefDireccion ?: 'NULL'));
  error_log("  benefFoto: " . ($benefFoto ? 'SI' : 'NO'));

  $cambioBenefDatos = false;
  $cambiosBenef = [];

  // Si se enviaron datos del beneficiario, obtener datos actuales para comparar
  if ($benefNombre || $benefApellido || $benefCorreo || $benefTelefono || $benefDireccion || $benefFoto) {
    error_log("=== SE ENVIARON DATOS DE BENEFICIARIO - OBTENIENDO DATOS ACTUALES ===");
    
    // Obtener datos actuales del beneficiario
    $stmtBenefActual = $cn->prepare("SELECT nombre_persona, apellido_persona, correo_persona, telefono_persona, direccion_persona, foto_documento 
                                       FROM persona WHERE documento = ? LIMIT 1");
    $stmtBenefActual->bind_param("s", $beneficiarioDoc);
    $stmtBenefActual->execute();
    $resBenefActual = $stmtBenefActual->get_result();
    
    if (!$resBenefActual || $resBenefActual->num_rows === 0) {
      error_log("ERROR: No se encontró el beneficiario con documento: " . $beneficiarioDoc);
      $cn->rollback();
      echo json_encode(['success' => false, 'msg' => 'No se encontró el beneficiario.']);
      exit;
    }
    
    $benefActual = $resBenefActual->fetch_assoc();
    $stmtBenefActual->close();
    
    error_log("=== DATOS ACTUALES DEL BENEFICIARIO ===");
    error_log("  nombre_persona: " . ($benefActual['nombre_persona'] ?? 'NULL'));
    error_log("  apellido_persona: " . ($benefActual['apellido_persona'] ?? 'NULL'));
    error_log("  correo_persona: " . ($benefActual['correo_persona'] ?? 'NULL'));
    error_log("  telefono_persona: " . ($benefActual['telefono_persona'] ?? 'NULL'));
    error_log("  direccion_persona: " . ($benefActual['direccion_persona'] ?? 'NULL'));
    error_log("  foto_documento: " . ($benefActual['foto_documento'] ?? 'NULL'));
    
    $setBenef = [];
    $typesBenef = "";
    $valuesBenef = [];

    // Comparar y agregar solo si hay cambios
    if ($benefNombre && $benefNombre !== '' && strtoupper($benefNombre) !== ($benefActual['nombre_persona'] ?? '')) {
      error_log("✓ CAMBIO: Nombre ('" . ($benefActual['nombre_persona'] ?? '') . "' -> '" . strtoupper($benefNombre) . "')");
      $setBenef[] = "nombre_persona = ?";
      $typesBenef .= "s";
      $valuesBenef[] = strtoupper($benefNombre);
      $cambiosBenef[] = "Nombre actualizado a: " . strtoupper($benefNombre);
      $cambioBenefDatos = true;
    }

    if ($benefApellido && $benefApellido !== '' && strtoupper($benefApellido) !== ($benefActual['apellido_persona'] ?? '')) {
      error_log("✓ CAMBIO: Apellido ('" . ($benefActual['apellido_persona'] ?? '') . "' -> '" . strtoupper($benefApellido) . "')");
      $setBenef[] = "apellido_persona = ?";
      $typesBenef .= "s";
      $valuesBenef[] = strtoupper($benefApellido);
      $cambiosBenef[] = "Apellido actualizado a: " . strtoupper($benefApellido);
      $cambioBenefDatos = true;
    }

    if ($benefCorreo !== null && $benefCorreo !== ($benefActual['correo_persona'] ?? '')) {
      error_log("✓ CAMBIO: Correo ('" . ($benefActual['correo_persona'] ?? '') . "' -> '" . $benefCorreo . "')");
      $setBenef[] = "correo_persona = ?";
      $typesBenef .= "s";
      $valuesBenef[] = $benefCorreo !== '' ? $benefCorreo : null;
      $cambiosBenef[] = "Correo actualizado a: " . ($benefCorreo !== '' ? $benefCorreo : 'vacío');
      $cambioBenefDatos = true;
    }

    if ($benefTelefono !== null && $benefTelefono !== ($benefActual['telefono_persona'] ?? '')) {
      error_log("✓ CAMBIO: Teléfono ('" . ($benefActual['telefono_persona'] ?? '') . "' -> '" . $benefTelefono . "')");
      $setBenef[] = "telefono_persona = ?";
      $typesBenef .= "s";
      $valuesBenef[] = $benefTelefono !== '' ? $benefTelefono : null;
      $cambiosBenef[] = "Teléfono actualizado a: " . ($benefTelefono !== '' ? $benefTelefono : 'vacío');
      $cambioBenefDatos = true;
    }

    if ($benefDireccion !== null && strtoupper($benefDireccion) !== strtoupper($benefActual['direccion_persona'] ?? '')) {
      error_log("✓ CAMBIO: Dirección ('" . ($benefActual['direccion_persona'] ?? '') . "' -> '" . strtoupper($benefDireccion) . "')");
      $setBenef[] = "direccion_persona = ?";
      $typesBenef .= "s";
      $valuesBenef[] = $benefDireccion !== '' ? strtoupper($benefDireccion) : null;
      $cambiosBenef[] = "Dirección actualizada a: " . ($benefDireccion !== '' ? strtoupper($benefDireccion) : 'vacío');
      $cambioBenefDatos = true;
    }

    // Procesar subida de foto
    if ($benefFoto) {
      error_log("✓ CAMBIO: Nueva foto subida");
      $fotoNombre = $benefFoto['name'];
      $fotoTmpName = $benefFoto['tmp_name'];
      $fotoSize = $benefFoto['size'];
      $fotoType = $benefFoto['type'];

      // Validar tipo de archivo
      $tiposPermitidos = ['image/png', 'image/jpeg', 'image/jpg'];
      if (!in_array(strtolower($fotoType), $tiposPermitidos)) {
        $cn->rollback();
        echo json_encode(['success' => false, 'msg' => 'Formato de foto no válido. Solo se permiten PNG y JPG.']);
        exit;
      }

      // Validar tamaño (máx 5 MB)
      if ($fotoSize > 5 * 1024 * 1024) {
        $cn->rollback();
        echo json_encode(['success' => false, 'msg' => 'La foto no puede superar los 5 MB.']);
        exit;
      }

      // Generar nombre único para la foto
      $extension = pathinfo($fotoNombre, PATHINFO_EXTENSION);
      $nuevoNombreFoto = $beneficiarioDoc . '_' . time() . '.' . $extension;
      $rutaDestino = '../uploads/beneficiarios/' . $nuevoNombreFoto;

      // Crear directorio si no existe
      if (!is_dir('../uploads/beneficiarios')) {
        mkdir('../uploads/beneficiarios', 0755, true);
      }

      // Mover archivo
      if (!move_uploaded_file($fotoTmpName, $rutaDestino)) {
        $cn->rollback();
        echo json_encode(['success' => false, 'msg' => 'Error al subir la foto del beneficiario.']);
        exit;
      }

      // Eliminar foto anterior si existe
      $stmtFotoAnterior = $cn->prepare("SELECT foto_documento FROM persona WHERE documento = ?");
      $stmtFotoAnterior->bind_param("s", $beneficiarioDoc);
      $stmtFotoAnterior->execute();
      $resFotoAnterior = $stmtFotoAnterior->get_result();
      if ($resFotoAnterior && $resFotoAnterior->num_rows > 0) {
        $rowFoto = $resFotoAnterior->fetch_assoc();
        $fotoAnterior = $rowFoto['foto_documento'];
        if ($fotoAnterior && file_exists('../' . $fotoAnterior)) {
          unlink('../' . $fotoAnterior);
        }
      }
      $stmtFotoAnterior->close();

      // Agregar a UPDATE
      $setBenef[] = "foto_documento = ?";
      $typesBenef .= "s";
      $valuesBenef[] = 'uploads/beneficiarios/' . $nuevoNombreFoto;
      $cambiosBenef[] = "Foto actualizada";
      $cambioBenefDatos = true;
    }

    // Ejecutar UPDATE en persona si hay cambios
    if (count($setBenef) > 0) {
      error_log("=== PREPARANDO UPDATE BENEFICIARIO ===");
      $sqlBenef = "UPDATE persona SET " . implode(", ", $setBenef) . " WHERE documento = ?";
      error_log("SQL: " . $sqlBenef);
      $typesBenef .= "s";
      $valuesBenef[] = $beneficiarioDoc;
      error_log("Types: " . $typesBenef);
      error_log("Values: " . print_r($valuesBenef, true));

      $stmtBenef = $cn->prepare($sqlBenef);
      
      if (!$stmtBenef) {
        error_log("ERROR: No se pudo preparar UPDATE beneficiario: " . $cn->error);
        $cn->rollback();
        echo json_encode(['success' => false, 'msg' => 'Error al preparar actualización del beneficiario.']);
        exit;
      }

      // bind_param dinámico
      $paramsBenef = [$typesBenef];
      foreach ($valuesBenef as $key => $value) {
        $paramsBenef[] = &$valuesBenef[$key];
      }
      
      call_user_func_array([$stmtBenef, 'bind_param'], $paramsBenef);

      error_log("=== EJECUTANDO UPDATE BENEFICIARIO ===");
      if (!$stmtBenef->execute()) {
        error_log("ERROR: No se pudo ejecutar UPDATE beneficiario: " . $stmtBenef->error);
        $cn->rollback();
        echo json_encode(['success' => false, 'msg' => 'Error al actualizar datos del beneficiario: ' . $stmtBenef->error]);
        exit;
      }
      
      error_log("✓ UPDATE beneficiario ejecutado correctamente");
      error_log("Filas afectadas: " . $stmtBenef->affected_rows);
      $stmtBenef->close();

      // Agregar a historial si hubo cambios en beneficiario
      if ($cambioBenefDatos) {
        error_log("=== REGISTRANDO HISTORIAL BENEFICIARIO ===");
        $comentarioBenef = "Datos del beneficiario actualizados:\n" . implode("\n", $cambiosBenef);
        error_log("Comentario: " . $comentarioBenef);
        
        $stmtHistBenef = $cn->prepare("
          INSERT INTO historial_deposito
            (id_deposito, documento_usuario, comentario_deposito, fecha_historial_deposito, tipo_evento)
          VALUES (?, ?, ?, NOW(), 'EDICION_BENEFICIARIO')
        ");
        $stmtHistBenef->bind_param("iss", $idDep, $usuario, $comentarioBenef);
        $stmtHistBenef->execute();
        error_log("✓ Historial beneficiario registrado");
        $stmtHistBenef->close();
      }
    } else {
      error_log("=== NO HAY CAMBIOS PARA ACTUALIZAR EN BENEFICIARIO ===");
    }
  } else {
    error_log("=== NO SE ENVIARON DATOS DE BENEFICIARIO ===");
  }
  // ========== FIN ACTUALIZACIÓN DATOS DEL BENEFICIARIO ==========

  // ========== VALIDACIÓN FINAL: ¿Hubo algún cambio? ==========
  error_log("=== VALIDACIÓN FINAL DE CAMBIOS ===");
  error_log("Cambios en depósito: " . ($cambioDep || $cambioExp || $cambioSec || $cambioFechaRecojo || $cambioBeneficiario || $cambioObservacion ? 'SÍ' : 'NO'));
  error_log("Cambios en beneficiario: " . ($cambioBenefDatos ? 'SÍ' : 'NO'));
  
  if (!$cambioDep && !$cambioExp && !$cambioSec && !$cambioFechaRecojo && !$cambioBeneficiario && !$cambioObservacion && !$cambioBenefDatos) {
    error_log("❌ NO SE REALIZARON CAMBIOS - ROLLBACK");
    $cn->rollback();
    echo json_encode(['success' => false, 'msg' => 'No se han realizado cambios.']);
    exit;
  }
  
  error_log("✓ SE REALIZARON CAMBIOS - PROCEDIENDO A COMMIT");
  // ========== FIN VALIDACIÓN FINAL ==========

  // Commit final
  $cn->commit();
  error_log("✓ COMMIT EXITOSO");
  
  $mensajeFinal = 'Depósito actualizado correctamente.';
  if ($cambioBenefDatos) {
    $mensajeFinal = 'Depósito y datos del beneficiario actualizados correctamente.';
  }
  
  error_log("=== RESPUESTA FINAL: " . $mensajeFinal . " ===");
  echo json_encode(['success' => true, 'msg' => $mensajeFinal]);
  exit;

} catch (Exception $ex) {
  if (isset($cn) && $cn instanceof mysqli) {
    $cn->rollback();
  }
  echo json_encode(['success' => false, 'msg' => 'Error del servidor: ' . $ex->getMessage()]);
  exit;
} catch (Error $err) {
  if (isset($cn) && $cn instanceof mysqli) {
    $cn->rollback();
  }
  echo json_encode(['success' => false, 'msg' => 'Error crítico: ' . $err->getMessage()]);
  exit;
}