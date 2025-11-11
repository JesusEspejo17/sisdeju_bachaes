<?php
// back_editar_usuario.php
session_start();
include("conexion.php");

// Helper: comprobar si columna existe
function table_has_column($cn, $table, $column) {
    $table_esc = mysqli_real_escape_string($cn, $table);
    $col_esc = mysqli_real_escape_string($cn, $column);
    $q = "SHOW COLUMNS FROM `{$table_esc}` LIKE '{$col_esc}'";
    $res = mysqli_query($cn, $q);
    if (!$res) return false;
    $has = mysqli_num_rows($res) > 0;
    mysqli_free_result($res);
    return $has;
}

try {
    if (!isset($_POST["documento"])) throw new Exception("Falta documento del usuario.");

    // Recibir y normalizar datos
    $dni        = trim($_POST["documento"]);
    $nombre     = isset($_POST["txt_nombre"]) ? ucwords(strtolower(trim($_POST["txt_nombre"]))) : '';
    $apellidos  = isset($_POST["txt_apellidos"]) ? ucwords(strtolower(trim($_POST["txt_apellidos"]))) : '';
    $email      = isset($_POST["txt_email"]) ? strtolower(trim($_POST["txt_email"])) : null;
    $telefono   = isset($_POST["txt_telefono"]) ? trim($_POST["txt_telefono"]) : null;
    $rol        = isset($_POST["cbo_rol"]) ? (int)$_POST["cbo_rol"] : null;
    $new_pass   = isset($_POST["txt_password"]) ? trim($_POST["txt_password"]) : '';

    if ($nombre === '' || $apellidos === '' || !$rol) {
        throw new Exception("Complete los campos obligatorios (nombre, apellidos, rol).");
    }

    // Array de juzgados (viene desde checkboxes o multi-select como cbo_juzgados[])
    $selected_juzgados = [];
    if (isset($_POST['cbo_juzgados']) && is_array($_POST['cbo_juzgados'])) {
        foreach ($_POST['cbo_juzgados'] as $jid) {
            $jid_int = intval($jid);
            if ($jid_int > 0) $selected_juzgados[] = $jid_int;
        }
    }

    // Empezar transacción
    if (!mysqli_begin_transaction($cn)) {
        throw new Exception("No se pudo iniciar la transacción.");
    }

    // 1) Actualizar persona
    $sql_persona = "UPDATE persona SET nombre_persona = ?, apellido_persona = ?, correo_persona = ?, telefono_persona = ? WHERE documento = ?";
    $stmt = mysqli_prepare($cn, $sql_persona);
    if (!$stmt) throw new Exception("Error preparando query persona: " . mysqli_error($cn));
    mysqli_stmt_bind_param($stmt, "sssss", $nombre, $apellidos, $email, $telefono, $dni);
    if (!mysqli_stmt_execute($stmt)) {
        $err = mysqli_stmt_error($stmt);
        mysqli_stmt_close($stmt);
        throw new Exception("Error actualizando persona: $err");
    }
    mysqli_stmt_close($stmt);

    // 2) Actualizar usuario: rol (+ password si viene)
    if ($new_pass !== '') {
        $hashed = password_hash($new_pass, PASSWORD_DEFAULT);
        $sql_user = "UPDATE usuario SET id_rol = ?, password_usu = ? WHERE codigo_usu = ?";
        $stmt = mysqli_prepare($cn, $sql_user);
        if (!$stmt) throw new Exception("Error preparando query usuario (pass): " . mysqli_error($cn));
        mysqli_stmt_bind_param($stmt, "iss", $rol, $hashed, $dni);
    } else {
        $sql_user = "UPDATE usuario SET id_rol = ? WHERE codigo_usu = ?";
        $stmt = mysqli_prepare($cn, $sql_user);
        if (!$stmt) throw new Exception("Error preparando query usuario: " . mysqli_error($cn));
        mysqli_stmt_bind_param($stmt, "is", $rol, $dni);
    }
    if (!mysqli_stmt_execute($stmt)) {
        $err = mysqli_stmt_error($stmt);
        mysqli_stmt_close($stmt);
        throw new Exception("Error actualizando usuario: $err");
    }
    mysqli_stmt_close($stmt);

    // 3) Actualizar asociaciones en usuario_juzgado
    // Borrar las asociaciones antiguas
    $del = mysqli_prepare($cn, "DELETE FROM usuario_juzgado WHERE codigo_usu = ?");
    if (!$del) throw new Exception("Error preparando DELETE usuario_juzgado: " . mysqli_error($cn));
    mysqli_stmt_bind_param($del, "s", $dni);
    if (!mysqli_stmt_execute($del)) {
        $err = mysqli_stmt_error($del);
        mysqli_stmt_close($del);
        throw new Exception("Error borrando asociaciones antiguas: $err");
    }
    mysqli_stmt_close($del);

    // Insertar las asociaciones nuevas (si hay)
    if (!empty($selected_juzgados)) {
        $ins = mysqli_prepare($cn, "INSERT INTO usuario_juzgado (codigo_usu, id_juzgado) VALUES (?, ?)");
        if (!$ins) throw new Exception("Error preparando INSERT usuario_juzgado: " . mysqli_error($cn));
        foreach ($selected_juzgados as $jid) {
            mysqli_stmt_bind_param($ins, "si", $dni, $jid);
            if (!mysqli_stmt_execute($ins)) {
                $err = mysqli_stmt_error($ins);
                mysqli_stmt_close($ins);
                throw new Exception("Error insertando asociación usuario_juzgado: $err");
            }
        }
        mysqli_stmt_close($ins);
    }

    // 4) Actualizar columna usuario.id_juzgado para compatibilidad (si la columna existe)
    if (table_has_column($cn, 'usuario', 'id_juzgado')) {
        if (!empty($selected_juzgados)) {
            $first = intval($selected_juzgados[0]);
            $upd = mysqli_prepare($cn, "UPDATE usuario SET id_juzgado = ? WHERE codigo_usu = ?");
            if (!$upd) throw new Exception("Error preparando UPDATE id_juzgado usuario: " . mysqli_error($cn));
            mysqli_stmt_bind_param($upd, "is", $first, $dni);
        } else {
            // quitar valor
            $upd = mysqli_prepare($cn, "UPDATE usuario SET id_juzgado = NULL WHERE codigo_usu = ?");
            if (!$upd) throw new Exception("Error preparando UPDATE id_juzgado NULL: " . mysqli_error($cn));
            mysqli_stmt_bind_param($upd, "s", $dni);
        }
        if (!mysqli_stmt_execute($upd)) {
            $err = mysqli_stmt_error($upd);
            mysqli_stmt_close($upd);
            throw new Exception("Error actualizando id_juzgado en usuario: $err");
        }
        mysqli_stmt_close($upd);
    }

    // Commit
    if (!mysqli_commit($cn)) {
        throw new Exception("No se pudo confirmar la transacción.");
    }

    // Mensaje para UI
    $_SESSION["swal"] = [
        "title" => "✅ Actualizado",
        "text" => "Usuario actualizado correctamente.",
        "icon" => "success"
    ];

    // Redirigir al listado de usuarios
    header("Location: ../code_front/menu_admin.php?vista=listado_usuarios");
    exit;

} catch (Exception $e) {
    // Rollback en caso de error
    if (isset($cn)) mysqli_rollback($cn);

    // Mensaje de error simple en sesión
    $_SESSION["swal"] = [
        "title" => "❌ Error",
        "text"  => $e->getMessage(),
        "icon"  => "error"
    ];

    // Opcional: volver atrás
    header("Location: ../code_front/menu_admin.php?vista=listado_usuarios");
    exit;
}
?>
