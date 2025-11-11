<?php
include("conexion.php");
header('Content-Type: application/json');

try {
    if (!isset($_GET['deposito'])) {
        throw new Exception("Parámetro 'deposito' no especificado.");
    }

    $id_deposito = trim($_GET['deposito']);

    $sql = "
        SELECT 
            dj.n_expediente       AS expediente,
            dj.id_estado          AS estado,
            dj.fecha_recojo_deposito       AS fecha_recojo,  
            bene.nombre_persona   AS nombre,
            bene.apellido_persona AS apellido,
            bene.telefono_persona AS telefono
        FROM deposito_judicial dj
        JOIN expediente ex ON ex.n_expediente = dj.n_expediente
        LEFT JOIN persona bene ON bene.documento = dj.documento_beneficiario
        WHERE dj.id_deposito = ?
    ";

    $stmt = mysqli_prepare($cn, $sql);
    mysqli_stmt_bind_param($stmt, "s", $id_deposito);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    if (!$result || mysqli_num_rows($result) === 0) {
        echo json_encode(["error" => true, "mensaje" => "No se encontraron datos del beneficiario."]);
        exit;
    }

    $data = mysqli_fetch_assoc($result);

    echo json_encode([
        "error"      => false,
        "expediente" => $data['expediente'],
        "estado"     => $data['estado'],
        "fecha_recojo"      => $data['fecha_recojo'],   // ✅ agregado
        "nombre"     => $data['nombre'],
        "apellido"   => $data['apellido'],
        "telefono"   => $data['telefono']
    ]);
} catch (Exception $e) {
    echo json_encode(["error" => true, "mensaje" => $e->getMessage()]);
}
?>
