<?php
header('Content-Type: application/json');
session_start();

if (!isset($_SESSION['documento'])) {
    echo json_encode(['error' => 'Sesión no iniciada']);
    exit;
}

$dni = $_SESSION['documento'];

// Ruta del archivo único que mencionas
$estadoPath = __DIR__ . '/notis_estado.json';

// Verificar que el archivo exista
if (!file_exists($estadoPath)) {
    echo json_encode(['notifications' => []]);
    exit;
}

// Leer el archivo completo
$data = json_decode(file_get_contents($estadoPath), true);

// Verificamos que haya notificaciones para el usuario
$misNotis = array_values(array_filter($data['notifications'] ?? [], function($n) use ($dni) {
    return $n['id_usuario'] === $dni || $n['destinatario'] === 'todos';
}));

// Leer el último índice leído
$lastRead = intval($data['usuarios'][$dni]['last_read'] ?? 0);

// Calcular nuevas
$nuevas = array_slice($misNotis, $lastRead);

// Actualizar estado si hay nuevas
if (count($nuevas) > 0) {
    $data['usuarios'][$dni]['last_read'] = $lastRead + count($nuevas);
    file_put_contents($estadoPath, json_encode($data, JSON_PRETTY_PRINT));
}

echo json_encode(['notifications' => $nuevas]);
