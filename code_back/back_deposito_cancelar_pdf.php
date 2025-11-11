<?php
// back_deposito_cancelar_pdf.php - SOLO borra archivos físicos (orden_pdf y resolucion_pdf) si estado es 2 o 7

declare(strict_types=1);
ini_set('display_errors', '0');
error_reporting(0);
header('Content-Type: application/json; charset=utf-8');
ob_start();

try {
    include __DIR__ . '/conexion.php';  
    if (!isset($cn) || !($cn instanceof mysqli)) {
        throw new Exception('Error de conexión a la base de datos.');
    }

    if (empty($_POST['id_deposito'])) {
        throw new Exception('Parámetro id_deposito no recibido.');
    }
    $idDeposito = (int) $_POST['id_deposito'];

    // 1) Traer rutas y estado
    $stmt = $cn->prepare("SELECT id_estado, orden_pdf, resolucion_pdf FROM deposito_judicial WHERE id_deposito = ?");
    if (!$stmt) {
        throw new Exception('Error en consulta: ' . $cn->error);
    }
    $stmt->bind_param('i', $idDeposito);
    $stmt->execute();
    $stmt->bind_result($estado, $rutaOrden, $rutaResol);
    if (!$stmt->fetch()) {
        $stmt->close();
        throw new Exception('Depósito no encontrado.');
    }
    $stmt->close();

    // Solo si estado es 2 o 7
    if (!in_array((int)$estado, [2, 7], true)) {
        throw new Exception('Solo se pueden borrar PDFs si el estado es 2 o 7.');
    }

    if (empty($rutaOrden) && empty($rutaResol)) {
        throw new Exception('No hay PDFs asociados a este depósito.');
    }

    // 2) Resolver ruta real
    $projectRoot = realpath(__DIR__ . '/..');
    $resolvePath = function(?string $rutaRel) use ($projectRoot) {
        if (empty($rutaRel)) return null;
        if (strpos($rutaRel, '/') === 0 || preg_match('#^[A-Za-z]:\\\\#', $rutaRel)) {
            return $rutaRel;
        }
        if (basename($rutaRel) === $rutaRel) {
            return __DIR__ . '/uploads/ordenes/' . $rutaRel;
        }
        return $projectRoot . '/' . ltrim($rutaRel, '/');
    };

    // 3) Intentar borrar
    $archivos = [
        'orden_pdf'      => $rutaOrden,
        'resolucion_pdf' => $rutaResol
    ];
    $deleted = [];
    $errors  = [];

    foreach ($archivos as $col => $ruta) {
        if (empty($ruta)) continue;
        $filePath = $resolvePath($ruta);
        if (!$filePath) continue;
        if (!file_exists($filePath)) {
            $errors[] = "No existe ($col): $filePath";
            continue;
        }
        if (!@unlink($filePath)) {
            $errors[] = "No se pudo eliminar ($col): $filePath";
        } else {
            $deleted[] = ['columna' => $col, 'ruta' => $filePath];
        }
    }

    if (empty($deleted) && !empty($errors)) {
        throw new Exception('No se eliminaron archivos. Errores: ' . implode(' | ', $errors));
    }

    $response = [
        'success' => true,
        'msg'     => 'Archivos eliminados en físico.',
        'deleted' => $deleted,
        'errors'  => $errors
    ];
}
catch (Exception $e) {
    $response = [
        'success' => false,
        'error'   => $e->getMessage()
    ];
}

if (ob_get_length()) ob_clean();
echo json_encode($response, JSON_UNESCAPED_UNICODE);
exit;
