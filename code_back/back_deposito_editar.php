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

// Datos mínimos
if (!isset($_POST['id_deposito']) || !isset($_POST['nuevo_expediente']) || !isset($_POST['beneficiario'])) {
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
  if (!preg_match('/^\d{13}$/', $nuevoDep)) {
    echo json_encode(['success' => false, 'msg' => 'Número de depósito inválido. Debe tener 13 dígitos.']);
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

  // 2) Determinar cambios
  $cambios = [];
  $cambioDep = false;
  $cambioExp = false;
  $cambioSec = false;
  $cambioFechaRecojo = false;
  $cambioBeneficiario = false;
  $cambioObservacion = false;

  // Si cambió beneficiario
  if ($beneficiarioAnterior !== $beneficiarioDoc) {
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
    $cambioExp = true;
    $cambios[] = "N° Expediente: {$expAnterior} → {$nuevoExp}";
  }

  // Si cambió secretario
  if ($docSecretario && $secAnterior !== $docSecretario) {
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
        $cambioObservacion = true;
        $cambios[] = "Observación actualizada";
      }
    } elseif ($nuevaObservacion === '' && $observacionAnterior !== null && $observacionAnterior !== '') {
      // Si se vació la observación
      $cambioObservacion = true;
      $cambios[] = "Observación eliminada";
    }
  }

  if (!$cambioDep && !$cambioExp && !$cambioSec && !$cambioFechaRecojo && !$cambioBeneficiario && !$cambioObservacion) {
    $cn->rollback();
    echo json_encode(['success' => false, 'msg' => 'No se han realizado cambios.']);
    exit;
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

  // Commit final
  $cn->commit();
  echo json_encode(['success' => true, 'msg' => 'Depósito actualizado correctamente.']);
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