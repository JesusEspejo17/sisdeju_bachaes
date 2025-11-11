<?php
if (session_status() === PHP_SESSION_NONE) session_start();
// No debe haber **ningún** echo/print antes de esto:
header('Content-Type: application/json; charset=UTF-8');

include("conexion.php"); // <-- esto carga $cn

try {
    $codigo_usu       = $_SESSION['documento'] ?? null;
    $actual           = $_POST['contrasena_actual']    ?? '';
    $nueva            = $_POST['nueva_contrasena']     ?? '';
    $confirmar        = $_POST['confirmar_contrasena'] ?? '';

    if (!$codigo_usu) {
        throw new Exception('No has iniciado sesión');
    }
    if (!$actual || !$nueva || !$confirmar) {
        throw new Exception('Todos los campos son obligatorios');
    }
    if ($nueva !== $confirmar) {
        throw new Exception('La nueva contraseña no coincide con la confirmación');
    }
    if (strlen($nueva) < 6) {
        throw new Exception('La nueva contraseña debe tener al menos 6 caracteres');
    }

    // 1) Tomar el hash actual
    $stmt = $cn->prepare("SELECT password_usu FROM usuario WHERE codigo_usu = ?");
    if (!$stmt) throw new Exception('Error al preparar SELECT');
    $stmt->bind_param("s", $codigo_usu);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res->num_rows === 0) {
        throw new Exception('Usuario no encontrado');
    }
    $row     = $res->fetch_assoc();
    $hash_bd = $row['password_usu'];
    $stmt->close();

    // 2) Verificar actual
    if (!password_verify($actual, $hash_bd)) {
        throw new Exception('La contraseña actual es incorrecta');
    }
    // 3) Evitar misma clave
    if (password_verify($nueva, $hash_bd)) {
        throw new Exception('La nueva contraseña no puede ser igual a la anterior');
    }
    // 4) Guardar nueva
    $nuevo_hash = password_hash($nueva, PASSWORD_DEFAULT);
    $stmt = $cn->prepare("UPDATE usuario SET password_usu = ? WHERE codigo_usu = ?");
    if (!$stmt) throw new Exception('Error al preparar UPDATE');
    $stmt->bind_param("ss", $nuevo_hash, $codigo_usu);
    if (!$stmt->execute()) {
        throw new Exception('Error al actualizar la contraseña');
    }
    $stmt->close();

    echo json_encode(['success' => true, 'message' => 'Contraseña actualizada correctamente']);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
$cn->close();
