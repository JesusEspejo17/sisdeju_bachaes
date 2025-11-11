<?php
// back_eliminar_usuario.php
session_start();
include("conexion.php");

try {
    if (!isset($_GET['documento']) || trim($_GET['documento']) === '') {
        $_SESSION["swal"] = [
            "title" => "❌ Error",
            "text" => "DNI no proporcionado.",
            "icon" => "error"
        ];
        header("Location: ../code_front/menu_admin.php?vista=listado_usuarios");
        exit;
    }

    $dni = trim($_GET['documento']);

    // Verificar que el usuario exista en la tabla 'usuario'
    $stmt = mysqli_prepare($cn, "SELECT codigo_usu FROM usuario WHERE codigo_usu = ?");
    if (!$stmt) {
        throw new Exception("Error preparando consulta: " . mysqli_error($cn));
    }
    mysqli_stmt_bind_param($stmt, "s", $dni);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_store_result($stmt);
    $exists = mysqli_stmt_num_rows($stmt) > 0;
    mysqli_stmt_close($stmt);

    if (!$exists) {
        $_SESSION["swal"] = [
            "title" => "⚠️ No encontrado",
            "text" => "El usuario no tiene cuenta en el sistema.",
            "icon" => "warning"
        ];
        header("Location: ../code_front/menu_admin.php?vista=listado_usuarios");
        exit;
    }

    // Iniciar transacción
    if (!mysqli_begin_transaction($cn)) {
        throw new Exception("No se pudo iniciar la transacción.");
    }

    // 1) Borrar asociaciones en usuario_juzgado (si la tabla existe)
    $res_check = mysqli_query($cn, "SHOW TABLES LIKE 'usuario_juzgado'");
    if ($res_check && mysqli_num_rows($res_check) > 0) {
        $del_uj = mysqli_prepare($cn, "DELETE FROM usuario_juzgado WHERE codigo_usu = ?");
        if (!$del_uj) throw new Exception("Error preparando DELETE usuario_juzgado: " . mysqli_error($cn));
        mysqli_stmt_bind_param($del_uj, "s", $dni);
        if (!mysqli_stmt_execute($del_uj)) {
            $err = mysqli_stmt_error($del_uj);
            mysqli_stmt_close($del_uj);
            throw new Exception("Error eliminando asociaciones en usuario_juzgado: $err");
        }
        mysqli_stmt_close($del_uj);
    }

    // 2) (Opcional) borrar otras tablas de autenticación si existen (nombres comunes).
    //    No es obligatorio; solo se manejan si existen. No tocaremos persona ni datos operativos.
    $possible_auth_tables = ['usuario_token', 'user_tokens', 'sessions', 'user_session', 'auth_tokens'];
    foreach ($possible_auth_tables as $t) {
        $q = "SHOW TABLES LIKE '" . mysqli_real_escape_string($cn, $t) . "'";
        $r = mysqli_query($cn, $q);
        if ($r && mysqli_num_rows($r) > 0) {
            // intentar borrar filas relacionadas con el usuario si existe la columna 'codigo_usu' o 'user_id'
            // Primero comprobar columnas comunes
            $has_codigo = false;
            $colRes = mysqli_query($cn, "SHOW COLUMNS FROM `{$t}` LIKE 'codigo_usu'");
            if ($colRes && mysqli_num_rows($colRes) > 0) $has_codigo = true;
            $has_userid = false;
            $colRes2 = mysqli_query($cn, "SHOW COLUMNS FROM `{$t}` LIKE 'user_id'");
            if ($colRes2 && mysqli_num_rows($colRes2) > 0) $has_userid = true;

            if ($has_codigo) {
                $del = @mysqli_prepare($cn, "DELETE FROM `{$t}` WHERE codigo_usu = ?");
                if ($del) {
                    mysqli_stmt_bind_param($del, "s", $dni);
                    @mysqli_stmt_execute($del);
                    mysqli_stmt_close($del);
                }
            } elseif ($has_userid) {
                // si user_id guarda el documento (no es estándar, pero por si acaso)
                $del = @mysqli_prepare($cn, "DELETE FROM `{$t}` WHERE user_id = ?");
                if ($del) {
                    mysqli_stmt_bind_param($del, "s", $dni);
                    @mysqli_stmt_execute($del);
                    mysqli_stmt_close($del);
                }
            }
            // si la tabla existe pero no tiene columnas esperadas, la dejamos intacta.
        }
    }

    // 3) Borrar registro en 'usuario' (elimina capacidad de acceso)
    $del_user = mysqli_prepare($cn, "DELETE FROM usuario WHERE codigo_usu = ?");
    if (!$del_user) throw new Exception("Error preparando DELETE usuario: " . mysqli_error($cn));
    mysqli_stmt_bind_param($del_user, "s", $dni);
    if (!mysqli_stmt_execute($del_user)) {
        $err = mysqli_stmt_error($del_user);
        mysqli_stmt_close($del_user);
        throw new Exception("Error eliminando usuario: $err");
    }
    mysqli_stmt_close($del_user);

    // Commit
    if (!mysqli_commit($cn)) throw new Exception("No se pudo confirmar la transacción.");

    $_SESSION["swal"] = [
        "title" => "✅ Eliminado",
        "text" => "Cuenta de acceso eliminada correctamente. Los datos operativos se mantienen.",
        "icon" => "success"
    ];
    header("Location: ../code_front/menu_admin.php?vista=listado_usuarios");
    exit;

} catch (Exception $e) {
    if (isset($cn)) mysqli_rollback($cn);
    $_SESSION["swal"] = [
        "title" => "❌ Error",
        "text" => "No se pudo eliminar la cuenta: " . $e->getMessage(),
        "icon" => "error"
    ];
    header("Location: ../code_front/menu_admin.php?vista=listado_usuarios");
    exit;
}
