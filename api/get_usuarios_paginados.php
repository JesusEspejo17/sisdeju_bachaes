<?php
// get_usuarios_paginados.php
if (session_status() === PHP_SESSION_NONE) session_start();
include("../code_back/conexion.php");
header('Content-Type: application/json');

// Verificar permisos
if (!isset($_SESSION['rol']) || !in_array($_SESSION['rol'], [1,6])) {
    echo json_encode(['success'=>false,'message'=>'Acceso denegado']); exit;
}

try {
    $page = isset($_GET['page']) ? max(1,intval($_GET['page'])) : 1;
    $limit = isset($_GET['limit']) ? max(1,intval($_GET['limit'])) : 20;
    $offset = ($page - 1) * $limit;

    $filtroTipo = $_GET['filtroTipo'] ?? 'nombre';
    $filtroTexto = trim($_GET['filtroTexto'] ?? '');

    $sql_base = "FROM usuario u
                 INNER JOIN persona p ON u.codigo_usu = p.documento
                 INNER JOIN rol r ON u.id_rol = r.id_rol
                 LEFT JOIN usuario_juzgado uj ON uj.codigo_usu = u.codigo_usu
                 LEFT JOIN juzgado j ON uj.id_juzgado = j.id_juzgado";

    $where = "";
    $params = [];
    $types = "";

    if ($filtroTexto !== "") {
        if ($filtroTipo === "dni") {
            $where = "WHERE p.documento LIKE ?";
            $params[] = "%$filtroTexto%";
            $types .= "s";
        } else {
            $where = "WHERE CONCAT(p.nombre_persona, ' ', p.apellido_persona) LIKE ?";
            $params[] = "%$filtroTexto%";
            $types .= "s";
        }
    }

    // Contar total
    $sql_count = "SELECT COUNT(DISTINCT u.codigo_usu) AS total $sql_base $where";
    $stmt = mysqli_prepare($cn, $sql_count);
    if (!empty($params)) mysqli_stmt_bind_param($stmt, $types, ...$params);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $total = mysqli_fetch_assoc($result)['total'];

    $total_pages = ceil($total / $limit);

    // Datos paginados
    $sql_data = "SELECT 
                    p.documento, p.nombre_persona, p.apellido_persona, 
                    p.telefono_persona, r.nombre_rol,
                    COALESCE(GROUP_CONCAT(j.nombre_juzgado SEPARATOR ' - '), 'Sin juzgado') AS juzgados_list
                 $sql_base
                 $where
                 GROUP BY p.documento, p.nombre_persona, p.apellido_persona, p.telefono_persona, r.nombre_rol
                 ORDER BY p.apellido_persona, p.nombre_persona
                 LIMIT ? OFFSET ?";
    $params2 = $params;
    $params2[] = $limit;
    $params2[] = $offset;
    $types2 = $types . "ii";
    $stmt2 = mysqli_prepare($cn, $sql_data);
    mysqli_stmt_bind_param($stmt2, $types2, ...$params2);
    mysqli_stmt_execute($stmt2);
    $res2 = mysqli_stmt_get_result($stmt2);
    $usuarios = [];
    while ($r = mysqli_fetch_assoc($res2)) $usuarios[] = $r;

    echo json_encode([
        'success'=>true,
        'data'=>$usuarios,
        'pagination'=>[
            'current_page'=>$page,
            'total_pages'=>$total_pages,
            'total_records'=>$total,
            'limit'=>$limit,
            'has_prev'=>$page>1,
            'has_next'=>$page<$total_pages
        ]
    ]);
} catch (Exception $e) {
    echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
}
?>