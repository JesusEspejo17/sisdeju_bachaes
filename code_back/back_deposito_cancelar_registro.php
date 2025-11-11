<?php
// back_deposito_cancelar_registro.php - v3 (acepta estado 2 o 7)

declare(strict_types=1);
ini_set('display_errors', '0');
error_reporting(0);
header('Content-Type: application/json; charset=utf-8');
ob_start();

try {
    session_start();
    if (!isset($_SESSION['rol'], $_SESSION['documento']) || $_SESSION['rol'] != 3) {
        throw new Exception('Acceso denegado.');
    }

    if (empty($_POST['id_deposito'])) {
        throw new Exception('Parámetro id_deposito no recibido.');
    }
    $id_deposito = (int) $_POST['id_deposito'];
    $documento   = $_SESSION['documento'];

    include __DIR__ . '/conexion.php';
    if (!isset($cn) || !($cn instanceof mysqli)) {
        throw new Exception('Error de conexión a la base de datos.');
    }

    // 1) Obtener estado y nombres de PDFs
    $sql  = "SELECT id_estado, orden_pdf, resolucion_pdf, n_deposito 
             FROM deposito_judicial 
             WHERE id_deposito = ?";
    $stmt = $cn->prepare($sql);
    if (!$stmt) {
        throw new Exception('Error al preparar consulta: ' . $cn->error);
    }
    $stmt->bind_param('i', $id_deposito);
    $stmt->execute();
    $stmt->bind_result($estadoAnterior, $archivo_orden, $archivo_resol, $n_deposito);
    if (!$stmt->fetch()) {
        $stmt->close();
        throw new Exception('Depósito no encontrado.');
    }
    $stmt->close();

    // Solo se permite cancelar si estaba en estado 2 o 7
    if (!in_array((int)$estadoAnterior, [2, 7], true)) {
        throw new Exception('Solo se puede cancelar cuando el estado es 2 o 7.');
    }

    // 2) Borrar los archivos físicos (si existen)
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

    foreach ([$archivo_orden, $archivo_resol] as $ruta) {
        if (empty($ruta)) continue;
        $ruta_archivo = $resolvePath($ruta);
        if ($ruta_archivo && file_exists($ruta_archivo)) {
            @unlink($ruta_archivo);
        }
    }

    // 3) Revertir estado y limpiar columnas
    $sqlUpdate = "
      UPDATE deposito_judicial
      SET id_estado = 3,
          orden_pdf = NULL,
          resolucion_pdf = NULL,
          n_deposito = NULL
      WHERE id_deposito = ?
    ";
    $stmt2 = $cn->prepare($sqlUpdate);
    if (!$stmt2) {
        throw new Exception('Error al preparar UPDATE: ' . $cn->error);
    }
    $stmt2->bind_param('i', $id_deposito);
    if (!$stmt2->execute()) {
        $stmt2->close();
        throw new Exception('Error al ejecutar UPDATE: ' . $stmt2->error);
    }
    $stmt2->close();

    // 4) Registrar historial
    $comentario  = "⚠️ El secretario canceló el registro de orden (estado $estadoAnterior → 3); se eliminaron los archivos adjuntos.";
    $tipoEvento  = "CANCELACION_ORDEN";
    $estadoNuevo = 3;
    $sqlHist     = "
      INSERT INTO historial_deposito (
        id_deposito,
        documento_usuario,
        comentario_deposito,
        fecha_historial_deposito,
        tipo_evento,
        estado_anterior,
        estado_nuevo
      ) VALUES (?, ?, ?, NOW(), ?, ?, ?)
    ";
    $stmtH = $cn->prepare($sqlHist);
    if ($stmtH) {
        $stmtH->bind_param(
            'isssii',
            $id_deposito,
            $documento,
            $comentario,
            $tipoEvento,
            $estadoAnterior,
            $estadoNuevo
        );
        $stmtH->execute();
        $stmtH->close();
    }

    $response = [
        'success' => true,
        'msg'     => 'La orden fue cancelada , se eliminaron los archivos y se registró la cancelación.'
    ];
} catch (Exception $e) {
    $response = [
        'success' => false,
        'msg'     => $e->getMessage()
    ];
}

if (ob_get_length()) ob_clean();
echo json_encode($response, JSON_UNESCAPED_UNICODE);
exit;
