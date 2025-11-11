<?php
// back_deposito_cargar_historial.php (parcheado)
header('Content-Type: application/json; charset=utf-8');
session_start();
include("conexion.php");

// Validación de sesión
if (!isset($_SESSION['documento'])) {
    http_response_code(401);
    echo json_encode(["success" => false, "msg" => "Sesión expirada. Vuelva a iniciar sesión."]);
    exit;
}

// Puede venir por POST o GET
$id_deposito = isset($_REQUEST['id_deposito']) ? (int)$_REQUEST['id_deposito'] : 0;
$last = isset($_REQUEST['last']) ? trim($_REQUEST['last']) : ''; // opcional, formato 'YYYY-MM-DD HH:MM:SS' recomendado

if ($id_deposito <= 0) {
    http_response_code(400);
    echo json_encode(["success" => false, "msg" => "Falta id_deposito (obligatorio)."]);
    exit;
}

try {
    // validar formato básico para 'last' (aceptar ISO o 'YYYY-MM-DD HH:MM:SS')
    if ($last !== '') {
        if (!preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}[ T][0-9]{2}:[0-9]{2}:[0-9]{2}$/', $last)) {
            // si no coincide, ignoramos last (evitar error)
            $last = '';
        }
    }

    if ($last !== '') {
        $sql = "
            SELECT
                hd.id_historial_deposito,
                hd.tipo_evento,
                hd.estado_anterior,
                hd.estado_nuevo,
                hd.comentario_deposito AS comentario,
                hd.fecha_historial_deposito AS fecha_iso,
                p.nombre_persona,
                p.apellido_persona,
                r.nombre_rol,
                hd.documento_usuario
            FROM historial_deposito hd
            LEFT JOIN persona p ON p.documento = hd.documento_usuario
            LEFT JOIN usuario u ON u.codigo_usu = p.documento
            LEFT JOIN rol r ON u.id_rol = r.id_rol
            WHERE hd.id_deposito = ? AND hd.fecha_historial_deposito > ?
            ORDER BY hd.fecha_historial_deposito ASC
        ";
        $st = mysqli_prepare($cn, $sql);
        mysqli_stmt_bind_param($st, "is", $id_deposito, $last);
    } else {
        $sql = "
            SELECT
                hd.id_historial_deposito,
                hd.tipo_evento,
                hd.estado_anterior,
                hd.estado_nuevo,
                hd.comentario_deposito AS comentario,
                hd.fecha_historial_deposito AS fecha_iso,
                p.nombre_persona,
                p.apellido_persona,
                r.nombre_rol,
                hd.documento_usuario
            FROM historial_deposito hd
            LEFT JOIN persona p ON p.documento = hd.documento_usuario
            LEFT JOIN usuario u ON u.codigo_usu = p.documento
            LEFT JOIN rol r ON u.id_rol = r.id_rol
            WHERE hd.id_deposito = ?
            ORDER BY hd.fecha_historial_deposito ASC
            LIMIT 2000
        ";
        $st = mysqli_prepare($cn, $sql);
        mysqli_stmt_bind_param($st, "i", $id_deposito);
    }

    if (!$st) {
        http_response_code(500);
        echo json_encode(["success" => false, "msg" => "Error preparar SQL: " . mysqli_error($cn)]);
        exit;
    }

    if (!mysqli_stmt_execute($st)) {
        http_response_code(500);
        $err = mysqli_stmt_error($st);
        mysqli_stmt_close($st);
        echo json_encode(["success" => false, "msg" => "Error ejecutar SQL: " . $err]);
        exit;
    }

    $res = mysqli_stmt_get_result($st);
    $historial = [];
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            // filtrar chats vacíos si lo deseas
            $tipoUp = isset($row['tipo_evento']) ? strtoupper($row['tipo_evento']) : '';
            $esChatVacio = ($tipoUp === 'CHAT') && (is_null($row['comentario']) || trim($row['comentario']) === '');
            $esOtroSinComentario = ($tipoUp !== 'CHAT') && is_null($row['comentario']);
            if ($esChatVacio || $esOtroSinComentario) continue;

            $usuarioNombre = trim(($row['nombre_persona'] ?? '') . ' ' . ($row['apellido_persona'] ?? ''));
            if ($usuarioNombre === '') $usuarioNombre = $row['documento_usuario'] ?? 'Usuario desconocido';

            // formato legible y mantener ISO / DB raw para comparaciones
            $fecha_iso = $row['fecha_iso'];
            $fecha_leyenda = $fecha_iso ? date("d/m/Y H:i", strtotime($fecha_iso)) : '';

            $historial[] = [
                // claves primarias / alias (compatibilidad front)
                "id_historial_deposito" => (int)$row["id_historial_deposito"],
                "id_historial" => (int)$row["id_historial_deposito"],

                "tipo_evento"     => $tipoUp,
                "estado_anterior" => $row["estado_anterior"],
                "estado_nuevo"    => $row["estado_nuevo"],

                // comentario: aliased both ways
                "comentario"      => $row["comentario"],
                "comentario_deposito" => $row["comentario"],

                // fechas: legible y cruda (compatible con parseTsToMillis)
                "fecha"           => $fecha_leyenda,
                "fecha_iso"       => $fecha_iso,
                "fecha_historial_deposito" => $fecha_iso, // alias crudo para JS

                "rol"             => $row["nombre_rol"] ?? "Rol desconocido",
                "usuario"         => $usuarioNombre,
                "documento_usuario"=> $row["documento_usuario"] ?? null
            ];
        }
        mysqli_free_result($res);
    }
    mysqli_stmt_close($st);

    // Obtener la observación del depósito desde la tabla deposito_judicial
    $observacion = null;
    $queryObservacion = "SELECT observacion FROM deposito_judicial WHERE id_deposito = ?";
    $stmtObservacion = mysqli_prepare($cn, $queryObservacion);
    mysqli_stmt_bind_param($stmtObservacion, "i", $id_deposito);
    mysqli_stmt_execute($stmtObservacion);
    mysqli_stmt_bind_result($stmtObservacion, $observacion);
    mysqli_stmt_fetch($stmtObservacion);
    mysqli_stmt_close($stmtObservacion);

    // DEBUG: verificar si se obtiene la observación
    error_log("back_deposito_cargar_historial: id_deposito={$id_deposito}, observacion=" . ($observacion ?: 'NULL'));

    // Incluir la observación en la respuesta JSON como campo separado
    // Convertir null a cadena vacía o mantener null explícitamente
    $response = [
        "ok" => true,
        "data" => $historial, // Historial del depósito sin modificaciones
        "observacion" => $observacion !== null ? $observacion : null // Observación del depósito (campo separado)
    ];

    // Asegurar que JSON_UNESCAPED_UNICODE está habilitado
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "msg" => $e->getMessage()]);
    exit;
}
