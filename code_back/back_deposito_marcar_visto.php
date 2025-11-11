<?php
// code_back/back_deposito_marcar_visto.php
header('Content-Type: application/json; charset=utf-8');
if (session_status() === PHP_SESSION_NONE) session_start();
include(__DIR__ . "/conexion.php");

if (!isset($_SESSION['documento']) || !preg_match('/^\d+$/', $_SESSION['documento'])) {
  http_response_code(401);
  echo json_encode(['ok'=>false,'msg'=>'Sesión no iniciada']);
  exit;
}
$usuario = $_SESSION['documento'];

$id_deposito = isset($_POST['id_deposito']) ? (int)$_POST['id_deposito'] : 0;
if ($id_deposito <= 0) {
  echo json_encode(['ok'=>false,'msg'=>'id_deposito requerido']);
  exit;
}

$ACTIONABLE = "('NOTIFICACION','CHAT')";

try {
  $sql = "
    INSERT INTO historial_deposito_visto (id_historial_deposito, documento_usuario, fecha_visto)
    SELECT h.id_historial_deposito, ?, NOW()
    FROM historial_deposito h
    WHERE h.id_deposito = ? AND h.tipo_evento IN $ACTIONABLE
    ON DUPLICATE KEY UPDATE fecha_visto = VALUES(fecha_visto)
  ";
  $st = mysqli_prepare($cn, $sql);
  if (!$st) throw new Exception("Prepare fallo: " . mysqli_error($cn));
  mysqli_stmt_bind_param($st, "si", $usuario, $id_deposito);
  if (!mysqli_stmt_execute($st)) {
    $err = mysqli_stmt_error($st);
    mysqli_stmt_close($st);
    throw new Exception("Execute fallo: " . $err);
  }
  $affected = mysqli_stmt_affected_rows($st);
  mysqli_stmt_close($st);

  echo json_encode(['ok'=>true,'marked'=>$affected]);
  exit;
} catch (Exception $e) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'msg'=>$e->getMessage()]);
  exit;
}
