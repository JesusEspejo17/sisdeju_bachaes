<?php
// api/get_last_by_depositos.php
header('Content-Type: application/json; charset=utf-8');
if (session_status() === PHP_SESSION_NONE) session_start();
include("../code_back/conexion.php");

if (!isset($_SESSION['documento'], $_SESSION['rol'])) {
  http_response_code(401);
  echo json_encode(['ok'=>false,'msg'=>'Sesión no iniciada']);
  exit;
}

$usuario = $_SESSION['documento'];
$rol = (int)$_SESSION['rol'];

// tipos accionables que consideramos para notificaciones / conteo
$ACTIONABLE = "('NOTIFICACION','CHAT')";

try {
  // 1) obtener depósitos visibles para este usuario
  // Si rol == 3 (secretario) sólo sus depósitos; sino todos
  if ($rol === 3) {
    $sql = "SELECT id_deposito, n_deposito, n_expediente, id_estado FROM deposito_judicial WHERE documento_secretario = ?";
    $stmt = $cn->prepare($sql);
    $stmt->bind_param('s', $usuario);
  } else {
    $sql = "SELECT id_deposito, n_deposito, n_expediente, id_estado FROM deposito_judicial";
    $stmt = $cn->prepare($sql);
  }
  $stmt->execute();
  $res = $stmt->get_result();
  $depositos = [];
  while ($r = $res->fetch_assoc()) $depositos[] = $r;
  $stmt->close();

  $out = [];

  // Para cada deposito: obtener último mensaje accionable y contar no leídos accionables
  // (usando historial_deposito_visto para saber si un mensaje ya fue visto por el usuario)
  foreach ($depositos as $d) {
    $id = (int)$d['id_deposito'];

    // ultimo mensaje entre los tipos accionables
    $sqlLast = "SELECT id_historial_deposito, documento_usuario, comentario_deposito, fecha_historial_deposito, tipo_evento
                FROM historial_deposito
                WHERE id_deposito = ?
                  AND tipo_evento IN $ACTIONABLE
                ORDER BY fecha_historial_deposito DESC
                LIMIT 1";
    $s1 = $cn->prepare($sqlLast);
    $s1->bind_param('i', $id);
    $s1->execute();
    $r1 = $s1->get_result()->fetch_assoc();
    $s1->close();

    // contar no leidos entre tipos accionables, excluyendo mis propios mensajes,
    // y que NO estén marcados en historial_deposito_visto para este usuario
    $sqlCount = "
      SELECT COUNT(*) AS cnt
      FROM historial_deposito h
      WHERE h.id_deposito = ?
        AND h.tipo_evento IN $ACTIONABLE
        AND (h.documento_usuario IS NULL OR h.documento_usuario <> ?)
        AND NOT EXISTS (
          SELECT 1 FROM historial_deposito_visto hv
           WHERE hv.id_historial_deposito = h.id_historial_deposito
             AND hv.documento_usuario = ?
        )
    ";
    $s3 = $cn->prepare($sqlCount);
    $s3->bind_param('iss', $id, $usuario, $usuario);
    $s3->execute();
    $cntRow = $s3->get_result()->fetch_assoc();
    $cnt = (int)($cntRow['cnt'] ?? 0);
    $s3->close();

    // normalizar tipo_evento en mayúsculas (si existe)
    $tipo_evento = isset($r1['tipo_evento']) ? strtoupper($r1['tipo_evento']) : null;

    $out[] = [
      'id_deposito' => $id,
      'n_deposito' => $d['n_deposito'] ?? null,
      'n_expediente' => $d['n_expediente'] ?? null,
      'id_estado' => $d['id_estado'] ?? null,
      'ultimo_mensaje' => $r1['comentario_deposito'] ?? null,
      'documento_usuario' => $r1['documento_usuario'] ?? null,
      'fecha_historial_deposito' => $r1['fecha_historial_deposito'] ?? null,
      'tipo_evento' => $tipo_evento,
      'no_leidos' => $cnt
    ];
  }

  echo json_encode(['ok'=>true,'data'=>$out]);
  exit;

} catch (Exception $e) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'msg'=>$e->getMessage()]);
  exit;
}
