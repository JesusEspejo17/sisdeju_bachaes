<?php
// get_reportes_depositos_paginados.php
// API endpoint para obtener reportes de depósitos con paginación y filtros
// Parámetros: page, limit, filtroEstado, filtroTipo, filtroTexto, filtrar_fecha, filtroInicio, filtroFin

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

include("../code_back/conexion.php");
header('Content-Type: application/json');

// Validar sesión básica
if (!isset($_SESSION['documento']) || !isset($_SESSION['rol'])) {
    echo json_encode([
        'success' => false,
        'message' => 'Sesión no iniciada',
        'data' => []
    ]);
    exit;
}

try {
    $usuarioActual = $_SESSION['documento'];
    $idRol = intval($_SESSION['rol']);
    
    // Parámetros de paginación
    $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
    $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 20;
    
    // Si limit es -1, significa "todos"
    $usePagination = ($limit > 0);
    if (!$usePagination) {
        $limit = 999999; // Un número muy grande para obtener todos
    }
    
    $offset = ($page - 1) * $limit;
    
    // Determinar tipo de reporte
    $tipoReporte = isset($_GET['tipo_reporte']) ? trim($_GET['tipo_reporte']) : 'general';
    
    // Parámetros de filtro según el tipo de reporte
    $whereConditions = [];
    
    // Solo aplicar filtro de rol en backend (secretario solo sus depósitos)
    if ($idRol === 3) {
        $whereConditions[] = "dj.documento_secretario='" . mysqli_real_escape_string($cn, $usuarioActual) . "'";
    }
    
    if ($tipoReporte === 'juzgado') {
        // Filtro por juzgado específico
        $filtroJuzgadoId = isset($_GET['filtro_juzgado_id']) ? intval($_GET['filtro_juzgado_id']) : 0;
        if ($filtroJuzgadoId > 0) {
            $whereConditions[] = "j.id_juzgado = $filtroJuzgadoId";
        }
        
        // Aplicar filtro de estado (viene del frontend como 'todos' por defecto)
        $filtroEstado = isset($_GET['filtroEstado']) ? trim($_GET['filtroEstado']) : 'todos';
        if ($filtroEstado === 'pendientes') {
            $whereConditions[] = "dj.id_estado IN (3,5,6,8,9)";
        } elseif ($filtroEstado === 'porentregar') {
            $whereConditions[] = "dj.id_estado IN (2,7)";
        } elseif ($filtroEstado === 'entregados') {
            $whereConditions[] = "dj.id_estado = 1";
        } elseif ($filtroEstado === 'anulados') {
            $whereConditions[] = "dj.id_estado = 10";
        }
        // Si es 'todos', no agregar condición de estado
        
    } elseif ($tipoReporte === 'secretario') {
        // Filtro por secretario específico
        $filtroSecretarioDoc = isset($_GET['filtro_secretario_doc']) ? trim($_GET['filtro_secretario_doc']) : '';
        if ($filtroSecretarioDoc) {
            $whereConditions[] = "dj.documento_secretario='" . mysqli_real_escape_string($cn, $filtroSecretarioDoc) . "'";
        }
        
        // Aplicar filtro de estado (viene del frontend como 'todos' por defecto)
        $filtroEstado = isset($_GET['filtroEstado']) ? trim($_GET['filtroEstado']) : 'todos';
        if ($filtroEstado === 'pendientes') {
            $whereConditions[] = "dj.id_estado IN (3,5,6,8,9)";
        } elseif ($filtroEstado === 'porentregar') {
            $whereConditions[] = "dj.id_estado IN (2,7)";
        } elseif ($filtroEstado === 'entregados') {
            $whereConditions[] = "dj.id_estado = 1";
        } elseif ($filtroEstado === 'anulados') {
            $whereConditions[] = "dj.id_estado = 10";
        }
        // Si es 'todos', no agregar condición de estado
        
    } elseif ($tipoReporte === 'usuario') {
        // Filtro por usuario específico que entregó depósitos
        $filtroUsuarioDoc = isset($_GET['filtro_usuario_doc']) ? trim($_GET['filtro_usuario_doc']) : '';
        if ($filtroUsuarioDoc) {
            // Solo mostrar depósitos que fueron entregados por este usuario
            $whereConditions[] = "dj.id_estado = 1"; // Solo depósitos entregados
            // Verificar que este usuario específico haya sido quien entregó el depósito
            // usando la tabla historial_deposito
            $whereConditions[] = "EXISTS (
                SELECT 1 FROM historial_deposito hd 
                WHERE hd.id_deposito = dj.id_deposito 
                AND hd.documento_usuario = '" . mysqli_real_escape_string($cn, $filtroUsuarioDoc) . "'
                AND hd.tipo_evento = 'CAMBIO_ESTADO' 
                AND hd.estado_nuevo = 1
            )";
        } else {
            // Si no hay usuario seleccionado, no mostrar nada
            $whereConditions[] = "1 = 0";
        }
        
    } else {
        // Filtros para reporte general
        $filtroEstado = isset($_GET['filtroEstado']) ? trim($_GET['filtroEstado']) : 'entregados';
        $filtroTipo = isset($_GET['filtroTipo']) ? trim($_GET['filtroTipo']) : '';
        $filtroTexto = isset($_GET['filtroTexto']) ? trim($_GET['filtroTexto']) : '';
        
        // Aplicar filtro por ESTADO en el backend
        if ($filtroEstado === 'pendientes') {
            $whereConditions[] = "dj.id_estado IN (3,5,6,8,9)";
        } elseif ($filtroEstado === 'porentregar') {
            $whereConditions[] = "dj.id_estado IN (2,7)";
        } elseif ($filtroEstado === 'entregados') {
            $whereConditions[] = "dj.id_estado = 1";
        } elseif ($filtroEstado === 'anulados') {
            $whereConditions[] = "dj.id_estado = 10";
        }
        // Si es 'todos', no agregar condición
        
        // Filtro por tipo/texto (juzgado o secretario)
        if ($filtroTipo && $filtroTexto) {
            $filtroTextoEscaped = mysqli_real_escape_string($cn, $filtroTexto);
            if ($filtroTipo === 'juzgado') {
                $whereConditions[] = "j.nombre_juzgado LIKE '%$filtroTextoEscaped%'";
            } elseif ($filtroTipo === 'secretario') {
                $whereConditions[] = "(CONCAT(sec.nombre_persona,' ',sec.apellido_persona) LIKE '%$filtroTextoEscaped%')";
            }
        }
    }
    
    // Construir la parte FROM de la consulta SQL (común para COUNT y SELECT)
    $sqlFrom = "
      FROM deposito_judicial dj
      JOIN estado e ON dj.id_estado = e.id_estado
      LEFT JOIN persona sec ON sec.documento = dj.documento_secretario
      JOIN expediente ex ON ex.n_expediente = dj.n_expediente
      JOIN juzgado j ON ex.id_juzgado = j.id_juzgado
      LEFT JOIN persona bene ON bene.documento = dj.documento_beneficiario
    ";
    
    $where = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';
    
    // Consulta para contar total de registros
    $sql_count = "SELECT COUNT(*) as total " . $sqlFrom . $where;
    
    $result_count = mysqli_query($cn, $sql_count);
    if (!$result_count) {
        throw new Exception('Error en consulta de conteo: ' . mysqli_error($cn));
    }
    
    $total_records = mysqli_fetch_assoc($result_count)['total'];
    $total_pages = $usePagination ? ceil($total_records / $limit) : 1;
    
    // Construir el ORDER BY
    $orderBy = "
      ORDER BY
        CASE
          WHEN dj.id_estado IN (3,5) THEN 0
          WHEN dj.id_estado = 2 THEN 1
          ELSE 2
        END,
        dj.fecha_ingreso_deposito ASC
    ";
    
    // Consulta completa para obtener los datos paginados
    $sql_data = "
      SELECT dj.n_expediente,
             dj.n_deposito,
             dj.id_deposito,
             dj.orden_pdf,
             dj.fecha_ingreso_deposito,
             dj.fecha_notificacion_deposito,
             dj.id_estado,
             e.nombre_estado,
             dj.estado_observacion,
             dj.motivo_observacion,
             dj.fecha_observacion,
             dj.fecha_atencion_observacion,
             CONCAT(sec.nombre_persona,' ',sec.apellido_persona) AS nombre_secretario,
             j.nombre_juzgado,
             bene.documento AS dni_beneficiario,
             CONCAT(bene.nombre_persona,' ',bene.apellido_persona) AS nombre_beneficiario,
             (
               SELECT hd2.fecha_historial_deposito
               FROM historial_deposito hd2
               WHERE hd2.id_deposito = dj.id_deposito
                 AND hd2.tipo_evento = 'CAMBIO_ESTADO'
                 AND hd2.estado_nuevo IN (2,7)
               ORDER BY hd2.fecha_historial_deposito DESC LIMIT 1
             ) AS fecha_atencion,
             (
               SELECT hd1.fecha_historial_deposito
               FROM historial_deposito hd1
               WHERE hd1.id_deposito = dj.id_deposito
                 AND hd1.tipo_evento = 'CAMBIO_ESTADO'
                 AND hd1.estado_nuevo = 1
               ORDER BY hd1.fecha_historial_deposito ASC LIMIT 1
             ) AS fecha_finalizacion,
             (
               SELECT hd3.documento_usuario
               FROM historial_deposito hd3
               WHERE hd3.id_deposito = dj.id_deposito
                 AND hd3.tipo_evento = 'CAMBIO_ESTADO'
                 AND hd3.estado_nuevo = 1
               ORDER BY hd3.fecha_historial_deposito ASC
               LIMIT 1
             ) AS documento_entrega,
             (
               SELECT CONCAT(p.nombre_persona, ' ', p.apellido_persona)
               FROM historial_deposito hd4
               LEFT JOIN persona p ON p.documento = hd4.documento_usuario
               WHERE hd4.id_deposito = dj.id_deposito
                 AND hd4.tipo_evento = 'CAMBIO_ESTADO'
                 AND hd4.estado_nuevo = 1
               ORDER BY hd4.fecha_historial_deposito ASC
               LIMIT 1
             ) AS usuario_entrega
    " . $sqlFrom . $where . $orderBy;
    
    if ($usePagination) {
        $sql_data .= " LIMIT " . intval($limit) . " OFFSET " . intval($offset);
    }
    
    $resultado = mysqli_query($cn, $sql_data);
    if (!$resultado) {
        throw new Exception('Error en consulta de datos: ' . mysqli_error($cn));
    }
    
    $depositos = [];
    while ($d = mysqli_fetch_assoc($resultado)) {
        // Preparar fecha de finalización en formato ISO
        $fecha_final_iso = $d['fecha_finalizacion'] ? date('Y-m-d H:i:s', strtotime($d['fecha_finalizacion'])) : '';
        
        $depositos[] = [
            'n_expediente' => $d['n_expediente'],
            'n_deposito' => $d['n_deposito'],
            'id_deposito' => $d['id_deposito'],
            'orden_pdf' => $d['orden_pdf'],
            'fecha_ingreso_deposito' => $d['fecha_ingreso_deposito'],
            'fecha_notificacion_deposito' => $d['fecha_notificacion_deposito'],
            'id_estado' => $d['id_estado'],
            'nombre_estado' => $d['nombre_estado'],
            'nombre_secretario' => $d['nombre_secretario'],
            'nombre_juzgado' => $d['nombre_juzgado'],
            'dni_beneficiario' => $d['dni_beneficiario'],
            'nombre_beneficiario' => $d['nombre_beneficiario'],
            'fecha_atencion' => $d['fecha_atencion'],
            'fecha_finalizacion' => $fecha_final_iso,
            'documento_entrega' => $d['documento_entrega'],
            'usuario_entrega' => $d['usuario_entrega']
        ];
    }
    
    // Preparar información de filtros según el tipo de reporte
    $filtersInfo = ['tipo_reporte' => $tipoReporte];
    
    if ($tipoReporte === 'juzgado') {
        $filtersInfo['filtro_juzgado_id'] = $filtroJuzgadoId ?? null;
    } elseif ($tipoReporte === 'secretario') {
        $filtersInfo['filtro_secretario_doc'] = $filtroSecretarioDoc ?? null;
    } else {
        $filtersInfo = array_merge($filtersInfo, [
            'filtroEstado' => $filtroEstado ?? 'entregados',
            'filtroTipo' => $filtroTipo ?? '',
            'filtroTexto' => $filtroTexto ?? ''
        ]);
    }
    
    // Respuesta exitosa
    echo json_encode([
        'success' => true,
        'data' => $depositos,
        'pagination' => [
            'current_page' => $page,
            'total_pages' => $total_pages,
            'total_records' => $total_records,
            'limit' => $usePagination ? $limit : -1,
            'has_prev' => $page > 1,
            'has_next' => $page < $total_pages
        ],
        'filters' => $filtersInfo,
        'userRole' => $idRol
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage(),
        'data' => []
    ]);
}
?>