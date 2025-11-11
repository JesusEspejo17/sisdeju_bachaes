<?php
header('Content-Type: application/json');

// Ruta al JSON
$ruta_json = __DIR__ . '/../bot_ws/mensajes_pendientes.json';

$input = file_get_contents('php://input');
$datos = json_decode($input, true);

if (!$datos || !is_array($datos)) {
    echo json_encode(['ok' => false, 'error' => 'Datos inválidos']);
    exit;
}

// Guardar JSON
if (file_put_contents($ruta_json, json_encode($datos, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))) {

    // Ruta a Python y al bot
    $ruta_python = 'C:\Users\PJUDICIAL2\AppData\Local\Programs\Python\Python313\python.exe';
    $ruta_py = __DIR__ . '/../bot_ws/bot_ws.py';

    // Comando para abrir CMD visible y ejecutar
    $comando = 'start cmd.exe /k "cd /d C:\xampp\htdocs\Sistemas\SISDEJU\bot_ws && ' . $ruta_python . ' ' . $ruta_py . '"';

    // Log para debug
    //$hora = date('Y-m-d H:i:s');
    //file_put_contents(__DIR__ . "/log_guardar.txt", "$hora => Ejecutando: $comando\n", FILE_APPEND);

    // Ejecutar comando
    pclose(popen($comando, "r"));

    echo json_encode(['ok' => true, 'msg' => '📤 Mensajes enviados al bot. Se abrió la consola de Windows.']);
} else {
    echo json_encode(['ok' => false, 'error' => 'No se pudo guardar el archivo']);
}
