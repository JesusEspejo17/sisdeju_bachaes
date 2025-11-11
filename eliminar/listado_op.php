<?php
include("../code_back/conexion.php");

$sql = "SELECT 
          o.n_orden_pago,
          d.n_expediente,
          o.n_deposito,
          o.secretario,
          j.nombre_juzgado,
          CONCAT(p_sec.nombre_persona, ' ', p_sec.apellido_persona) AS nombre_secretario,
          CONCAT(p_ben.nombre_persona, ' ', p_ben.apellido_persona) AS nombre_beneficiario,
          o.fecha_orden_pago,
          e.nombre_estado
        FROM orden_pago o
        INNER JOIN deposito_judicial d ON o.n_deposito = d.n_deposito
        INNER JOIN juzgado j ON o.id_juzgado = j.id_juzgado
        LEFT JOIN usuario u_sec ON o.secretario = u_sec.codigo_usu
        LEFT JOIN persona p_sec ON u_sec.codigo_usu = p_sec.documento
        LEFT JOIN expediente exp ON d.n_expediente = exp.n_expediente
        LEFT JOIN persona p_ben ON p_ben.documento = d.documento_beneficiario
        INNER JOIN estado e ON o.id_estado = e.id_estado
        ORDER BY o.fecha_orden_pago DESC";

$res = mysqli_query($cn, $sql);
?>

<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Listado de Órdenes de Pago</title>
  <link rel="stylesheet" href="../css/crear_usuario.css">
</head>
<body>

<div class="main-container">
  <h1 style="margin-top: 40px;">Órdenes de Pago Registradas</h1>

  <table border="1" cellpadding="10" cellspacing="0" style="width:100%; text-align:center;">
    <thead>
      <tr>
        <th>N° Orden</th>
        <th>Expediente</th>
        <th>Nº Depósito</th>
        <th>Juzgado</th>
        <th>Secretario</th>
        <th>Beneficiario</th>
        <th>Estado</th>
        <th>Fecha de Orden de Pago</th>
        <th>Opciones</th>
      </tr>
    </thead>
    <tbody>
      <?php while ($row = mysqli_fetch_assoc($res)) { ?>
        <tr>
          <td><?php echo $row["n_orden_pago"]; ?></td>
          <td><?php echo $row["n_expediente"]; ?></td>
          <td><?php echo $row["n_deposito"]; ?></td>
          <td><?php echo $row["nombre_juzgado"]; ?></td>
          <td><?php echo $row["nombre_secretario"] ?? '---'; ?></td>
          <td><?php echo $row["nombre_beneficiario"] ?? '---'; ?></td>
          <td><?php echo $row["nombre_estado"]; ?></td>
          <td><?php echo $row["fecha_orden_pago"] ? date("d/m/Y H:i", strtotime($row["fecha_orden_pago"])) : '--'; ?></td>
          <td>
            <a href="#" onclick="alert('Función aún no implementada.')">Ver más</a>
          </td>
        </tr>
      <?php } ?>
    </tbody>
  </table>
</div>

</body>
</html>
