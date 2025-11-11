<?php
// api/add_historial_deposito.php (parcheado)
// Inserta mensaje y marca como visto para el emisor. Devuelve el registro insertado con aliases.
header('Content-Type: application/json; charset=utf-8');
if (session_status() === PHP_SESSION_NONE) session_start();
include("../code_back/conexion.php");

if (!isset($_SESSION['documento'])) {
  http_response_code(401);
  echo json_encode(['ok'=>false,'msg'=>'Sesión no iniciada']);
  exit;
}

$usuario = $_SESSION['documento'];
$id_deposito = isset($_POST['id_deposito']) ? (int)$_POST['id_deposito'] : 0;
$comentario = isset($_POST['comentario']) ? trim($_POST['comentario']) : '';
$tipo_evento = isset($_POST['tipo_evento']) ? trim($_POST['tipo_evento']) : 'CHAT';
$tipo_evento = strtoupper($tipo_evento);

if ($id_deposito <= 0) {
  echo json_encode(['ok'=>false,'msg'=>'id_deposito requerido']);
  exit;
}
if ($comentario === '') {
  echo json_encode(['ok'=>false,'msg'=>'comentario vacío']);
  exit;
}
if (mb_strlen($comentario) > 250) $comentario = mb_substr($comentario,0,250);

try {
  // intentar obtener n_deposito actual (opcional)
  $nDep = null;
  $qN = $cn->prepare("SELECT n_deposito FROM deposito_judicial WHERE id_deposito = ?");
  $qN->bind_param('i', $id_deposito);
  $qN->execute();
  $rN = $qN->get_result()->fetch_assoc();
  $qN->close();
  $nDep = $rN['n_deposito'] ?? null;

  $sql = "INSERT INTO historial_deposito (id_deposito, n_deposito, documento_usuario, comentario_deposito, fecha_historial_deposito, tipo_evento)
          VALUES (?, ?, ?, ?, NOW(), ?)";
  $stmt = $cn->prepare($sql);
  if (!$stmt) throw new Exception("Prepare fallo: " . $cn->error);
  $stmt->bind_param('issss', $id_deposito, $nDep, $usuario, $comentario, $tipo_evento);
  if (!$stmt->execute()) throw new Exception("Execute fallo: " . $stmt->error);
  $inserted_id = $cn->insert_id;
  $stmt->close();

  // MARCAR VISTO para el emisor en historial_deposito_visto (por evento)
  $sqlHv = "INSERT INTO historial_deposito_visto (id_historial_deposito, documento_usuario, fecha_visto)
            VALUES (?, ?, NOW())
            ON DUPLICATE KEY UPDATE fecha_visto = VALUES(fecha_visto)";
  $sv = $cn->prepare($sqlHv);
  if ($sv) {
    $sv->bind_param('is', $inserted_id, $usuario);
    $sv->execute();
    $sv->close();
  }

  // recuperar el registro insertado
  $q = $cn->prepare("SELECT id_historial_deposito, id_deposito, n_deposito, documento_usuario, comentario_deposito, fecha_historial_deposito, tipo_evento FROM historial_deposito WHERE id_historial_deposito = ?");
  if (!$q) throw new Exception("Prepare recuperar fallo: " . $cn->error);
  $q->bind_param('i', $inserted_id);
  $q->execute();
  $inserted = $q->get_result()->fetch_assoc();
  $q->close();

  // normalizar y preparar respuesta
  $fecha_iso = $inserted['fecha_historial_deposito'] ?? null;
  $fecha_legible = $fecha_iso ? date("d/m/Y H:i", strtotime($fecha_iso)) : null;

  $out = [
    'id_historial_deposito' => (int)$inserted['id_historial_deposito'],
    'id_historial' => (int)$inserted['id_historial_deposito'],
    'id_deposito' => (int)$inserted['id_deposito'],
    'n_deposito' => $inserted['n_deposito'] ?? null,
    'documento_usuario' => $inserted['documento_usuario'] ?? null,
    'comentario_deposito' => $inserted['comentario_deposito'] ?? null,
    'comentario' => $inserted['comentario_deposito'] ?? null,
    'fecha_historial_deposito' => $fecha_iso,
    'fecha_iso' => $fecha_iso,
    'fecha' => $fecha_legible,
    'tipo_evento' => isset($inserted['tipo_evento']) ? strtoupper($inserted['tipo_evento']) : 'CHAT'
  ];

  echo json_encode(['ok'=>true,'data'=>$out]);
  exit;

} catch (Exception $e) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'msg'=>$e->getMessage()]);
  exit;
}
