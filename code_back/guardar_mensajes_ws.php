<?php
header('Content-Type: application/json');

// Rutas
$ruta_json = __DIR__ . '/../bot_ws/mensajes_pendientes.json';
$status_file = __DIR__ . '/../bot_ws/bot_status.txt';

$input = file_get_contents('php://input');
$nuevos_mensajes = json_decode($input, true);

if (!$nuevos_mensajes || !is_array($nuevos_mensajes)) {
    echo json_encode(['ok' => false, 'error' => 'Datos inválidos']);
    exit;
}

// Función para verificar si el bot está ejecutándose
function is_bot_running() {
    global $status_file;
    
    // Si no existe el archivo, definitivamente no está corriendo
    if (!file_exists($status_file)) {
        return false;
    }
    
    $status = trim(file_get_contents($status_file));
    $last_update = filemtime($status_file);
    $time_diff = time() - $last_update;
    
    // Si el status no es RUNNING, definitivamente no está corriendo
    if ($status !== 'RUNNING') {
        return false;
    }
    
    // Si pasaron más de 3 minutos sin actualizar, considerar como detenido
    if ($time_diff > 180) {
        file_put_contents($status_file, 'STOPPED');
        return false;
    }
    
    // Verificación adicional con procesos (solo si el tiempo es reciente)
    if ($time_diff > 60) { // Solo verificar procesos si han pasado más de 1 minuto
        $python_running = false;
        
        // Buscar procesos Python con bot_ws.py
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            $wmic_output = [];
            exec('wmic process where "name=\'python.exe\'" get commandline 2>NUL', $wmic_output, $return_var);
            
            if ($return_var === 0) {
                foreach ($wmic_output as $line) {
                    if (stripos($line, 'bot_ws.py') !== false) {
                        $python_running = true;
                        break;
                    }
                }
            }
        }
        
        // Si no encontramos el proceso, marcar como detenido
        if (!$python_running) {
            file_put_contents($status_file, 'STOPPED');
            return false;
        }
    }
    
    return true;
}

// Función para agregar mensajes a la cola (no reemplazar)
function agregar_mensajes_cola($nuevos) {
    global $ruta_json;
    
    $mensajes_existentes = [];
    if (file_exists($ruta_json)) {
        $contenido = file_get_contents($ruta_json);
        if ($contenido) {
            $todos_mensajes = json_decode($contenido, true) ?: [];
            
            // LIMPIAR: Solo mantener mensajes NO enviados (descartar los ya enviados)
            $mensajes_existentes = array_filter($todos_mensajes, function($m) {
                return !$m['enviado']; // Solo mantener pendientes
            });
            
            // Reindexar el array para evitar índices faltantes
            $mensajes_existentes = array_values($mensajes_existentes);
        }
    }
    
    // Agregar TODOS los mensajes nuevos
    // Esto permite múltiples mensajes al mismo número con contenido diferente
    $mensajes_agregados = count($nuevos);
    foreach ($nuevos as $nuevo_msg) {
        $mensajes_existentes[] = $nuevo_msg;
    }
    
    $total_pendientes = count($mensajes_existentes); // Todos son pendientes después de la limpieza
    
    return [
        'mensajes_totales' => $mensajes_existentes,
        'agregados' => $mensajes_agregados,
        'total_pendientes' => $total_pendientes,
        'limpiados' => count($todos_mensajes ?? []) - count(array_filter($todos_mensajes ?? [], function($m) { return !$m['enviado']; }))
    ];
}

// Función para forzar reset del estado del bot
function force_reset_bot_status() {
    global $status_file;
    if (file_exists($status_file)) {
        file_put_contents($status_file, 'STOPPED');
    }
}

// Agregar mensajes a la cola
$resultado_cola = agregar_mensajes_cola($nuevos_mensajes);

// Guardar la cola actualizada
if (file_put_contents($ruta_json, json_encode($resultado_cola['mensajes_totales'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))) {

    // Verificar parámetro de forzar reset (para debug/manual)
    if (isset($_GET['force_reset']) && $_GET['force_reset'] === '1') {
        force_reset_bot_status();
    }
    
    $bot_running = is_bot_running();
    
    // Verificación de estado sin logging
    
    if ($bot_running) {
        // Bot ya está ejecutándose, solo agregamos mensajes a la cola
        $msg_limpieza = isset($resultado_cola['limpiados']) && $resultado_cola['limpiados'] > 0 
            ? " Se limpiaron {$resultado_cola['limpiados']} mensajes ya enviados." 
            : "";
            
        // Solo agregando a cola - sin logging
            
        echo json_encode([
            'ok' => true, 
            'msg' => "📥 Mensajes agregados a la cola existente. El bot ya está procesando mensajes.{$msg_limpieza}",
            'bot_status' => 'running',
            'agregados' => $resultado_cola['agregados'],
            'total_pendientes' => $resultado_cola['total_pendientes'],
            'limpiados' => $resultado_cola['limpiados'] ?? 0,
            'action' => 'queue_only'
        ]);
    } else {
        // Bot no está ejecutándose, iniciar nuevo proceso
        
        $msg_limpieza = isset($resultado_cola['limpiados']) && $resultado_cola['limpiados'] > 0 
            ? " Se limpiaron {$resultado_cola['limpiados']} mensajes ya enviados." 
            : "";
        
        // Detectar Python automáticamente
        $ruta_python = 'python';
        $ruta_py = 'bot_ws.py';
        
        // Detectar directorio del bot automáticamente
        $bot_directory = __DIR__ . '/../bot_ws';
        
        // Comando portable que funciona en cualquier PC
        $comando = 'start cmd.exe /k "cd /d "' . $bot_directory . '" && ' . $ruta_python . ' ' . $ruta_py . '"';

        // Iniciando bot sin logging

        // Ejecutar comando
        pclose(popen($comando, "r"));
        
        // Nueva ventana CMD abierta - sin logging

        echo json_encode([
            'ok' => true, 
            'msg' => "📤 Bot iniciado - Se abrió nueva ventana CMD. Procesando {$resultado_cola['total_pendientes']} mensajes.{$msg_limpieza}",
            'bot_status' => 'started',
            'agregados' => $resultado_cola['agregados'],
            'total_pendientes' => $resultado_cola['total_pendientes'],
            'limpiados' => $resultado_cola['limpiados'] ?? 0,
            'action' => 'new_bot'
        ]);
    }
} else {
    echo json_encode(['ok' => false, 'error' => 'No se pudo guardar la cola de mensajes']);
}
?>
