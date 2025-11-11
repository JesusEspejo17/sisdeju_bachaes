<?php
// api/get_historial_deposito.php
header('Content-Type: application/json; charset=utf-8');
if (session_status() === PHP_SESSION_NONE) session_start();
include("../code_back/conexion.php");

if (!isset($_SESSION['documento'])) {
  http_response_code(401);
  echo json_encode(['ok'=>false,'msg'=>'Sesión no iniciada']);
  exit;
}

$id_deposito = isset($_REQUEST['id_deposito']) ? (int)$_REQUEST['id_deposito'] : 0;
$last = isset($_REQUEST['last']) ? trim($_REQUEST['last']) : '';

if ($id_deposito <= 0) {
  http_response_code(400);
  echo json_encode(['ok'=>false,'msg'=>'id_deposito requerido']);
  exit;
}

try {
  // Validar formato last opcional (evita inyección)
  if ($last !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}( |T)\d{2}:\d{2}:\d{2}$/', $last)) {
    $last = '';
  }

  if ($last !== '') {
    $sql = "
      SELECT
        hd.id_historial_deposito,
        hd.id_deposito,
        hd.n_deposito,
        hd.documento_usuario,
        hd.comentario_deposito,
        hd.fecha_historial_deposito,
        hd.tipo_evento,
        hd.estado_anterior,
        hd.estado_nuevo,
        p.nombre_persona,
        p.apellido_persona,
        r.nombre_rol
      FROM historial_deposito hd
      LEFT JOIN persona p ON p.documento = hd.documento_usuario
      LEFT JOIN usuario u ON u.codigo_usu = p.documento
      LEFT JOIN rol r ON u.id_rol = r.id_rol
      WHERE hd.id_deposito = ? AND hd.fecha_historial_deposito > ?
      ORDER BY hd.fecha_historial_deposito ASC
    ";
    $st = mysqli_prepare($cn, $sql);
    mysqli_stmt_bind_param($st, "is", $id_deposito, $last);
  } else {
    $sql = "
      SELECT
        hd.id_historial_deposito,
        hd.id_deposito,
        hd.n_deposito,
        hd.documento_usuario,
        hd.comentario_deposito,
        hd.fecha_historial_deposito,
        hd.tipo_evento,
        hd.estado_anterior,
        hd.estado_nuevo,
        p.nombre_persona,
        p.apellido_persona,
        r.nombre_rol
      FROM historial_deposito hd
      LEFT JOIN persona p ON p.documento = hd.documento_usuario
      LEFT JOIN usuario u ON u.codigo_usu = p.documento
      LEFT JOIN rol r ON u.id_rol = r.id_rol
      WHERE hd.id_deposito = ?
      ORDER BY hd.fecha_historial_deposito ASC
      LIMIT 2000
    ";
    $st = mysqli_prepare($cn, $sql);
    mysqli_stmt_bind_param($st, "i", $id_deposito);
  }

  if (!$st) {
    http_response_code(500);
    echo json_encode(['ok'=>false,'msg'=>'Error preparar SQL: '.mysqli_error($cn)]);
    exit;
  }

  mysqli_stmt_execute($st);
  $res = mysqli_stmt_get_result($st);
  $out = [];
  while ($r = mysqli_fetch_assoc($res)) {
    $out[] = [
      'id_historial_deposito' => (int)$r['id_historial_deposito'],
      'id_historial' => (int)$r['id_historial_deposito'],
      'id_deposito' => (int)$r['id_deposito'],
      'n_deposito' => $r['n_deposito'] ?? null,
      'documento_usuario' => $r['documento_usuario'] ?? null,
      'comentario_deposito' => $r['comentario_deposito'] ?? null,
      'fecha_historial_deposito' => $r['fecha_historial_deposito'] ?? null,
      'tipo_evento' => isset($r['tipo_evento']) ? strtoupper($r['tipo_evento']) : null,
      'estado_anterior' => $r['estado_anterior'] ?? null,
      'estado_nuevo' => $r['estado_nuevo'] ?? null,
      // campos nuevos para frontend
      'nombre_persona' => $r['nombre_persona'] ?? null,
      'apellido_persona' => $r['apellido_persona'] ?? null,
      'nombre_rol' => $r['nombre_rol'] ?? null
    ];
  }
  mysqli_stmt_close($st);

  // Obtener la observación del depósito desde la tabla deposito_judicial
  $observacion = null;
  $queryObservacion = "SELECT observacion FROM deposito_judicial WHERE id_deposito = ?";
  $stmtObservacion = mysqli_prepare($cn, $queryObservacion);
  if ($stmtObservacion) {
    mysqli_stmt_bind_param($stmtObservacion, "i", $id_deposito);
    mysqli_stmt_execute($stmtObservacion);
    mysqli_stmt_bind_result($stmtObservacion, $observacion);
    mysqli_stmt_fetch($stmtObservacion);
    mysqli_stmt_close($stmtObservacion);
  }

  echo json_encode(['ok'=>true,'data'=>$out,'observacion'=>$observacion]);
  exit;

} catch (Exception $e) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'msg'=>$e->getMessage()]);
  exit;
}
