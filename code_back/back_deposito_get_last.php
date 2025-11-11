<?php
// code_back/back_deposito_get_last.php (modificado para usar historial_deposito_visto)
header('Content-Type: application/json; charset=utf-8');
if (session_status() === PHP_SESSION_NONE) session_start();
include(__DIR__ . "/conexion.php");

if (!isset($_SESSION['documento'], $_SESSION['rol'])) {
  http_response_code(401);
  echo json_encode(['ok' => false, 'msg' => 'Sesión no iniciada']);
  exit;
}

$usuario = $_SESSION['documento'];
$rol = (int)$_SESSION['rol'];

// tipos accionables para considerar en last/no_leidos
$ACTIONABLE = "('NOTIFICACION','CHAT')";

try {
  // Armamos la consulta principal:
  // - obtenemos último historial accionable por depósito (lh -> hd)
  // - para cada depósito calculamos en subquery COUNT(*) los mensajes accionables
  //   que NO son del propio usuario y que NO están en historial_deposito_visto para este usuario
  $sql = "
    SELECT
      d.id_deposito,
      d.n_deposito,
      d.n_expediente,
      d.id_estado,
      hd.comentario_deposito AS comentario_deposito,
      hd.documento_usuario AS documento_usuario_hist,
      hd.fecha_historial_deposito AS fecha_historial_deposito,
      hd.tipo_evento AS tipo_evento,
      COALESCE((
        SELECT COUNT(*) FROM historial_deposito h2
         WHERE h2.id_deposito = d.id_deposito
           AND h2.tipo_evento IN $ACTIONABLE
           AND (h2.documento_usuario IS NULL OR h2.documento_usuario <> ?)
           AND NOT EXISTS (
             SELECT 1 FROM historial_deposito_visto hv
              WHERE hv.id_historial_deposito = h2.id_historial_deposito
                AND hv.documento_usuario = ?
           )
      ), 0) AS no_leidos
    FROM deposito_judicial d
    LEFT JOIN (
      SELECT id_deposito, MAX(fecha_historial_deposito) AS last_fecha
      FROM historial_deposito
      WHERE tipo_evento IN $ACTIONABLE
      GROUP BY id_deposito
    ) lh ON lh.id_deposito = d.id_deposito
    LEFT JOIN historial_deposito hd ON hd.id_deposito = lh.id_deposito AND hd.fecha_historial_deposito = lh.last_fecha
  ";

  // Si rol == 3 (secretario) limitamos a sus depósitos
  if ($rol === 3) {
    $sql .= " WHERE d.documento_secretario = ?";
    // params: usuario (para exclusion en subquery), usuario (para historial_deposito_visto check), documento_secretario (filtro)
    $types = "sss";
    $params = [$usuario, $usuario, $usuario];
  } else {
    // params: usuario (exclusion), usuario (visto)
    $types = "ss";
    $params = [$usuario, $usuario];
  }

  $stmt = $cn->prepare($sql);
  if (!$stmt) throw new Exception("Prepare failed: " . $cn->error);

  // bind dinámico (mysqli requiere referencias)
  $bind_names = [];
  $bind_names[] = $types;
  for ($i = 0; $i < count($params); $i++) {
    $bind_name = 'param' . $i;
    $$bind_name = $params[$i];
    $bind_names[] = &$$bind_name;
  }
  call_user_func_array([$stmt, 'bind_param'], $bind_names);

  if (!$stmt->execute()) {
    throw new Exception("Execute failed: " . $stmt->error);
  }

  $res = $stmt->get_result();
  $out = [];
  while ($r = $res->fetch_assoc()) {
    $out[] = [
      'id_deposito' => (int)$r['id_deposito'],
      'n_deposito' => $r['n_deposito'] ?? null,
      'n_expediente' => $r['n_expediente'] ?? null,
      'id_estado' => $r['id_estado'] ?? null,
      'comentario_deposito' => $r['comentario_deposito'] ?? null,
      // normalizamos documento_usuario y tipo_evento
      'documento_usuario' => $r['documento_usuario_hist'] ?? null,
      'fecha_historial_deposito' => $r['fecha_historial_deposito'] ?? null,
      'tipo_evento' => isset($r['tipo_evento']) ? strtoupper($r['tipo_evento']) : null,
      'no_leidos' => (int)($r['no_leidos'] ?? 0)
    ];
  }

  $stmt->close();
  echo json_encode(['ok' => true, 'data' => $out]);
  exit;

} catch (Exception $e) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
  exit;
}
