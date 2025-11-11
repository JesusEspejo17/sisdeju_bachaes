<?php
// api/mark_seen.php  -> marca en historial_deposito_visto todos los eventos accionables del depósito
header('Content-Type: application/json; charset=utf-8');
if (session_status() === PHP_SESSION_NONE) session_start();
include("../code_back/conexion.php");

if (!isset($_REQUEST['id_deposito'])) {
  http_response_code(400);
  echo json_encode(['ok'=>false,'msg'=>'id_deposito requerido']);
  exit;
}
$id_deposito = (int)$_REQUEST['id_deposito'];
if ($id_deposito <= 0) {
  echo json_encode(['ok'=>false,'msg'=>'id_deposito inválido']);
  exit;
}

// Preferimos sesión; si querés permitir documento_usuario por POST, podrías aceptarlo.
// Aquí usamos la sesión obligatoria:
if (!isset($_SESSION['documento']) || !preg_match('/^\d+$/', $_SESSION['documento'])) {
  http_response_code(401);
  echo json_encode(['ok'=>false,'msg'=>'Sesión no iniciada']);
  exit;
}
$documento = $_SESSION['documento'];

// tipos accionables
$ACTIONABLE = "('NOTIFICACION','CHAT')";

try {
  // Insert bulk -> historial_deposito_visto
  $sql = "
    INSERT INTO historial_deposito_visto (id_historial_deposito, documento_usuario, fecha_visto)
    SELECT h.id_historial_deposito, ?, NOW()
    FROM historial_deposito h
    WHERE h.id_deposito = ? AND h.tipo_evento IN $ACTIONABLE
    ON DUPLICATE KEY UPDATE fecha_visto = VALUES(fecha_visto)
  ";
  $st = $cn->prepare($sql);
  if (!$st) throw new Exception("Prepare fallo: " . $cn->error);
  $st->bind_param('si', $documento, $id_deposito);
  if (!$st->execute()) throw new Exception("Execute fallo: " . $st->error);
  $affected = $st->affected_rows;
  $st->close();

  echo json_encode(['ok'=>true,'marked'=>(int)$affected,'msg'=>"Marcado en historial_deposito_visto"]);
  exit;
} catch (Exception $e) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'msg'=>$e->getMessage()]);
  exit;
}
