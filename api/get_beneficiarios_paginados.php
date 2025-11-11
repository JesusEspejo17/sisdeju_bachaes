<?php
// get_beneficiarios_paginados.php
// API endpoint para obtener beneficiarios con paginación y filtros
// Parámetros: page, limit, filtroTipo, filtroTexto

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

include("../code_back/conexion.php");
header('Content-Type: application/json');

// Verificar permisos
if (!isset($_SESSION['rol']) || !in_array($_SESSION['rol'], [1,2,6])) {
    echo json_encode([
        'success' => false,
        'message' => 'Acceso denegado',
        'data' => []
    ]);
    exit;
}

try {
    // Parámetros de paginación
    $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
    $limit = isset($_GET['limit']) ? max(1, intval($_GET['limit'])) : 20;
    $offset = ($page - 1) * $limit;
    
    // Parámetros de filtro
    $filtroTipo = isset($_GET['filtroTipo']) ? trim($_GET['filtroTipo']) : '';
    $filtroTexto = isset($_GET['filtroTexto']) ? trim($_GET['filtroTexto']) : '';
    
    // Construir la consulta base
    $sql_base = "FROM beneficiario b
                 INNER JOIN persona p ON b.documento = p.documento
                 INNER JOIN documento d ON p.id_documento = d.id_documento";
    
    $where_conditions = [];
    $params = [];
    $param_types = "";
    
    // Aplicar filtros
    if (!empty($filtroTexto)) {
        if ($filtroTipo === 'dni') {
            $where_conditions[] = "p.documento LIKE ?";
            $params[] = "%{$filtroTexto}%";
            $param_types .= "s";
        } else if ($filtroTipo === 'nombre') {
            $where_conditions[] = "(CONCAT(p.nombre_persona, ' ', p.apellido_persona) LIKE ? OR p.nombre_persona LIKE ? OR p.apellido_persona LIKE ?)";
            $params[] = "%{$filtroTexto}%";
            $params[] = "%{$filtroTexto}%";
            $params[] = "%{$filtroTexto}%";
            $param_types .= "sss";
        }
    }
    
    // Construir WHERE clause
    $where_clause = "";
    if (!empty($where_conditions)) {
        $where_clause = "WHERE " . implode(" AND ", $where_conditions);
    }
    
    // Consulta para contar total de registros
    $sql_count = "SELECT COUNT(*) as total {$sql_base} {$where_clause}";
    
    if (!empty($params)) {
        $stmt_count = mysqli_prepare($cn, $sql_count);
        mysqli_stmt_bind_param($stmt_count, $param_types, ...$params);
        mysqli_stmt_execute($stmt_count);
        $result_count = mysqli_stmt_get_result($stmt_count);
    } else {
        $result_count = mysqli_query($cn, $sql_count);
    }
    
    $total_records = mysqli_fetch_assoc($result_count)['total'];
    $total_pages = ceil($total_records / $limit);
    
    // Consulta para obtener los datos paginados
    $sql_data = "SELECT 
                    p.documento,
                    p.nombre_persona,
                    p.apellido_persona,
                    p.correo_persona,
                    p.telefono_persona,
                    d.tipo_documento
                 {$sql_base}
                 {$where_clause}
                 ORDER BY p.apellido_persona, p.nombre_persona
                 LIMIT ? OFFSET ?";
    
    $params[] = $limit;
    $params[] = $offset;
    $param_types .= "ii";
    
    $stmt_data = mysqli_prepare($cn, $sql_data);
    mysqli_stmt_bind_param($stmt_data, $param_types, ...$params);
    mysqli_stmt_execute($stmt_data);
    $result_data = mysqli_stmt_get_result($stmt_data);
    
    $beneficiarios = [];
    while ($row = mysqli_fetch_assoc($result_data)) {
        $beneficiarios[] = $row;
    }
    
    // Respuesta exitosa
    echo json_encode([
        'success' => true,
        'data' => $beneficiarios,
        'pagination' => [
            'current_page' => $page,
            'total_pages' => $total_pages,
            'total_records' => $total_records,
            'limit' => $limit,
            'has_prev' => $page > 1,
            'has_next' => $page < $total_pages
        ],
        'filters' => [
            'filtroTipo' => $filtroTipo,
            'filtroTexto' => $filtroTexto
        ]
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage(),
        'data' => []
    ]);
}
?>