<?php
// back_deposito_enviar_historial.php (parcheado)
// Inserta historial y marca visto para emisor; devuelve data consistente.
date_default_timezone_set('America/Lima');
session_start();
include("conexion.php");
header('Content-Type: application/json; charset=utf-8');

// Autenticación
if (!isset($_SESSION['documento'])) {
    http_response_code(401);
    echo json_encode(["success" => false, "msg" => "Usuario no autenticado."]);
    exit;
}
$usuario_envia = $_SESSION['documento'];

// Entradas
$id_deposito = isset($_POST['id_deposito']) ? (int)$_POST['id_deposito'] : 0;
$comentario_raw = isset($_POST['comentario']) ? trim($_POST['comentario']) : '';
$comentario = $comentario_raw === '' ? null : $comentario_raw;

if ($id_deposito <= 0) {
    http_response_code(400);
    echo json_encode(["success" => false, "msg" => "Falta id_deposito válido."]);
    exit;
}

// validar tamaño comentario
if ($comentario !== null && mb_strlen($comentario) > 250) {
    $comentario = mb_substr($comentario, 0, 250);
}

try {
    // Comprobar que historial_deposito tiene columna id_deposito
    $hasCol = false;
    $resShow = mysqli_query($cn, "SHOW COLUMNS FROM `historial_deposito` LIKE 'id_deposito'");
    if ($resShow && mysqli_num_rows($resShow) > 0) $hasCol = true;
    if ($resShow) mysqli_free_result($resShow);

    if (!$hasCol) {
        http_response_code(500);
        echo json_encode(["success" => false, "msg" => "La tabla historial_deposito no tiene columna id_deposito. Debes migrar la tabla o usar n_deposito."]);
        exit;
    }

    // Insert dinámico (comentario puede ser NULL)
    if ($comentario === null) {
        $sql = "INSERT INTO historial_deposito (id_deposito, documento_usuario, comentario_deposito, fecha_historial_deposito, tipo_evento) VALUES (?, ?, NULL, NOW(), 'CHAT')";
        $st = mysqli_prepare($cn, $sql);
        mysqli_stmt_bind_param($st, "is", $id_deposito, $usuario_envia);
    } else {
        $sql = "INSERT INTO historial_deposito (id_deposito, documento_usuario, comentario_deposito, fecha_historial_deposito, tipo_evento) VALUES (?, ?, ?, NOW(), 'CHAT')";
        $st = mysqli_prepare($cn, $sql);
        mysqli_stmt_bind_param($st, "iss", $id_deposito, $usuario_envia, $comentario);
    }

    if (!$st) {
        throw new Exception("Error preparar INSERT: " . mysqli_error($cn));
    }
    if (!mysqli_stmt_execute($st)) {
        $err = mysqli_stmt_error($st);
        mysqli_stmt_close($st);
        throw new Exception("Error al guardar historial: " . $err);
    }
    $inserted_id = mysqli_insert_id($cn);
    mysqli_stmt_close($st);

    // MARCAR VISTO para el emisor en historial_deposito_visto (por evento)
    $sqlHv = "INSERT INTO historial_deposito_visto (id_historial_deposito, documento_usuario, fecha_visto)
              VALUES (?, ?, NOW())
              ON DUPLICATE KEY UPDATE fecha_visto = VALUES(fecha_visto)";
    $sv = mysqli_prepare($cn, $sqlHv);
    if ($sv) {
        mysqli_stmt_bind_param($sv, "is", $inserted_id, $usuario_envia);
        mysqli_stmt_execute($sv);
        mysqli_stmt_close($sv);
    }

    // Recuperar el registro insertado para devolverlo al frontend
    $q = mysqli_prepare($cn, "SELECT id_historial_deposito, id_deposito, n_deposito, documento_usuario, comentario_deposito, fecha_historial_deposito, tipo_evento FROM historial_deposito WHERE id_historial_deposito = ?");
    if (!$q) throw new Exception("Prepare recuperar fallo: " . mysqli_error($cn));
    mysqli_stmt_bind_param($q, "i", $inserted_id);
    mysqli_stmt_execute($q);
    $res = mysqli_stmt_get_result($q);
    $inserted = mysqli_fetch_assoc($res);
    mysqli_stmt_close($q);

    // Formatear fecha
    $fecha_iso = $inserted['fecha_historial_deposito'] ?? null;
    $fecha_legible = $fecha_iso ? date("d/m/Y H:i", strtotime($fecha_iso)) : null;

    // Normalizar tipo_evento
    $tipo = isset($inserted['tipo_evento']) ? strtoupper($inserted['tipo_evento']) : 'CHAT';

    // Devolver campos compatibles con el frontend
    echo json_encode([
        "success" => true,
        "msg" => "Mensaje guardado.",
        "data" => [
            "id_historial_deposito" => (int)$inserted['id_historial_deposito'],
            "id_historial" => (int)$inserted['id_historial_deposito'],
            "id_deposito" => (int)$inserted['id_deposito'],
            "n_deposito" => $inserted['n_deposito'] ?? null,
            "documento_usuario" => $inserted['documento_usuario'] ?? null,
            "comentario_deposito" => $inserted['comentario_deposito'] ?? null,
            "comentario" => $inserted['comentario_deposito'] ?? null,
            "fecha_historial_deposito" => $fecha_iso,
            "fecha_iso" => $fecha_iso,
            "fecha" => $fecha_legible,
            "tipo_evento" => $tipo
        ]
    ]);
    exit;

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "msg" => $e->getMessage()]);
    exit;
}
