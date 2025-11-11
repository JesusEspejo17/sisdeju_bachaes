<?php
session_start();
include("conexion.php");

// Configuración subida
$uploadDirRel = 'uploads/beneficiarios/'; // RELATIVO (guardado en DB). Ajustar si tu carpeta es otra.
$maxSize = 5 * 1024 * 1024; // 5 MB
$allowedMime = ['image/jpeg', 'image/png'];
$allowedExt = ['jpg', 'jpeg', 'png'];


// Obtener y sanear inputs
$documento_original = isset($_POST["documento_original"]) ? trim($_POST["documento_original"]) : '';
$documento_nuevo    = isset($_POST["txt_dni"]) ? trim($_POST["txt_dni"]) : '';
$nombre             = isset($_POST["txt_nombre"]) ? strtoupper(trim($_POST["txt_nombre"])) : '';
$apellidos          = isset($_POST["txt_apellidos"]) ? strtoupper(trim($_POST["txt_apellidos"])) : '';
$email              = isset($_POST["txt_email"]) ? strtolower(trim($_POST["txt_email"])) : '';
$telefono           = isset($_POST["txt_telefono"]) ? preg_replace('/\s+/', '', trim($_POST["txt_telefono"])) : '';
$id_documento       = isset($_POST["cbo_documento"]) ? (int)$_POST["cbo_documento"] : 0;
$direccion          = isset($_POST["txt_direccion"]) ? strtoupper(trim($_POST["txt_direccion"])) : '';

$remove_foto = isset($_POST['remove_foto']) && $_POST['remove_foto'] === '1';
$foto_existente = isset($_POST['foto_existente_path']) ? trim($_POST['foto_existente_path']) : '';

// 1) Validar duplicado documento si cambió
if ($documento_nuevo !== $documento_original) {
    $doc_check = mysqli_real_escape_string($cn, $documento_nuevo);
    $sql_check = "SELECT documento FROM persona WHERE documento = '$doc_check'";
    $res_check = mysqli_query($cn, $sql_check);
    if ($res_check && mysqli_num_rows($res_check) > 0) {
        $_SESSION['swal'] = [
            'title' => 'Documento duplicado',
            'text'  => 'El DNI ingresado ya está registrado con otro beneficiario.',
            'icon'  => 'warning'
        ];
        header("Location: ../code_front/menu_admin.php?vista=listado_beneficiarios");
        exit;
    }
}

// Preparamos carpeta física
$uploadDir = __DIR__ . '/../' . rtrim($uploadDirRel, '/') . '/';
if (!is_dir($uploadDir)) {
    @mkdir($uploadDir, 0755, true);
}

// 2) Manejo de archivo subido
$hasFile = isset($_FILES['foto_documento']) && isset($_FILES['foto_documento']['error']) && $_FILES['foto_documento']['error'] === UPLOAD_ERR_OK;
$new_rel_path = ''; // ruta relativa a guardar en DB (si hay nueva)
$errors = [];

if ($hasFile) {
    $file = $_FILES['foto_documento'];
    // validaciones
    if ($file['size'] > $maxSize) {
        $errors[] = "La imagen no puede exceder 5 MB.";
    }

    // comprobación real de imagen
    $tmpPath = $file['tmp_name'];
    $finfoType = @mime_content_type($tmpPath);
    if ($finfoType === false) $finfoType = $file['type'] ?? '';

    if (!in_array($finfoType, $allowedMime, true)) {
        $errors[] = "Tipo de archivo inválido. Solo PNG o JPG.";
    }

    // extensión
    $origName = $file['name'];
    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    if (!in_array($ext, $allowedExt, true)) {
        $errors[] = "Extensión inválida.";
    }

    if (empty($errors)) {
        // generar nombre único
        try {
            $uniq = time() . '_' . bin2hex(random_bytes(6));
        } catch (Exception $e) {
            $uniq = time() . '_' . substr(md5(uniqid('', true)), 0, 12);
        }
        $newFilename = $uniq . '.' . $ext;
        $targetFull = $uploadDir . $newFilename;
        $new_rel_path = rtrim($uploadDirRel, '/') . '/' . $newFilename;

        // mover archivo
        if (!move_uploaded_file($tmpPath, $targetFull)) {
            $errors[] = "No se pudo mover el archivo al destino.";
            $new_rel_path = '';
        } else {
            // establecer permisos (opcional)
            @chmod($targetFull, 0644);
        }
    }
}

// Si hubo errores de archivo - redirigir con mensaje
if (!empty($errors)) {
    $_SESSION['swal'] = [
        'title' => 'Error con la imagen',
        'text'  => implode(' ', $errors),
        'icon'  => 'error'
    ];
    header("Location: ../code_front/menu_admin.php?vista=listado_beneficiarios");
    exit;
}

// Función auxiliar: borrar archivo antiguo solo si está dentro de la carpeta permitida
function safe_unlink_old($oldRel, $uploadDirFull) {
    if (!$oldRel) return false;
    // construir ruta absoluta probable
    $candidate = __DIR__ . '/../' . ltrim($oldRel, '/');
    // realpath puede devolver false si no existe
    $realCandidate = @realpath($candidate);
    $realUploadDir = @realpath($uploadDirFull);
    if ($realCandidate && $realUploadDir && strpos($realCandidate, $realUploadDir) === 0 && is_file($realCandidate)) {
        return @unlink($realCandidate);
    }
    return false;
}

// 3) Lógica para decidir ruta final a guardar en DB
$foto_a_guardar = ''; // valor final que pondremos en DB
if ($new_rel_path) {
    // subió nueva foto: borrar antigua (si existe) y usar nueva
    if (!empty($foto_existente)) {
        safe_unlink_old($foto_existente, $uploadDir);
    }
    $foto_a_guardar = $new_rel_path;
} else {
    if ($remove_foto) {
        // marcó eliminar y no subió nueva: borrar antigua (si existe) y dejar vacío
        if (!empty($foto_existente)) {
            safe_unlink_old($foto_existente, $uploadDir);
        }
        $foto_a_guardar = ''; // dejar campo vacío
    } else {
        // no tocó la foto -> conservar la existente (puede ser vacía)
        $foto_a_guardar = $foto_existente;
    }
}

// 4) Actualizar persona (incluyendo foto_documento)
$doc_orig_esc = mysqli_real_escape_string($cn, $documento_original);
$doc_new_esc = mysqli_real_escape_string($cn, $documento_nuevo);
$nombre_esc = mysqli_real_escape_string($cn, $nombre);
$apellidos_esc = mysqli_real_escape_string($cn, $apellidos);
$email_esc = mysqli_real_escape_string($cn, $email);
$telefono_esc = mysqli_real_escape_string($cn, $telefono);
$direccion_esc = mysqli_real_escape_string($cn, $direccion);
$foto_esc = mysqli_real_escape_string($cn, $foto_a_guardar);

$sql_update_persona = "
    UPDATE persona SET
      documento = '$doc_new_esc',
      nombre_persona = '$nombre_esc',
      apellido_persona = '$apellidos_esc',
      correo_persona = '$email_esc',
      telefono_persona = '$telefono_esc',
      id_documento = $id_documento,
      direccion_persona = '$direccion_esc',
      foto_documento = " . ($foto_esc === '' ? "''" : "'$foto_esc'") . "
    WHERE documento = '$doc_orig_esc'
";
$res_up = mysqli_query($cn, $sql_update_persona);

// 5) Si cambió documento, actualizar tabla beneficiario también
if ($documento_nuevo !== $documento_original) {
    $sql_update_beneficiario = "UPDATE beneficiario SET documento = '$doc_new_esc' WHERE documento = '$doc_orig_esc'";
    mysqli_query($cn, $sql_update_beneficiario);
}

// 6) Mensaje final y redirección
if ($res_up) {
    $_SESSION['swal'] = [
        'title' => 'Actualizado',
        'text'  => 'Los datos del beneficiario fueron actualizados correctamente.',
        'icon'  => 'success'
    ];
} else {
    // si falló la actualización, opcionalmente revertir nueva subida (si había)
    if (!empty($new_rel_path)) {
        // borrar archivo subido nuevo para no dejar basura
        @unlink(__DIR__ . '/../' . ltrim($new_rel_path, '/'));
    }
    $_SESSION['swal'] = [
        'title' => 'Error',
        'text'  => 'Ocurrió un error al actualizar la base de datos.',
        'icon'  => 'error'
    ];
}

header("Location: ../code_front/menu_admin.php?vista=listado_beneficiarios");
exit;
?>
