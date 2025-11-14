<?php
include("conexion.php");
header('Content-Type: application/json');

try {
    // Aceptar búsqueda por documento (POST) o por id_deposito (GET)
    if (isset($_POST['documento'])) {
        // Búsqueda por documento del beneficiario
        $documento = trim($_POST['documento']);
        
        $sql = "
            SELECT 
                p.documento,
                p.nombre_persona,
                p.apellido_persona,
                p.telefono_persona,
                p.correo_persona,
                p.direccion_persona,
                p.foto_documento
            FROM persona p
            WHERE p.documento = ?
            LIMIT 1
        ";
        
        $stmt = mysqli_prepare($cn, $sql);
        mysqli_stmt_bind_param($stmt, "s", $documento);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        
        if (!$result || mysqli_num_rows($result) === 0) {
            echo json_encode([
                "success" => false, 
                "msg" => "No se encontró el beneficiario con documento: " . $documento
            ]);
            exit;
        }
        
        $beneficiario = mysqli_fetch_assoc($result);
        
        echo json_encode([
            "success" => true,
            "beneficiario" => $beneficiario
        ]);
        
    } elseif (isset($_GET['deposito'])) {
        // Búsqueda por ID de depósito (funcionalidad original)
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
            "fecha_recojo"      => $data['fecha_recojo'],
            "nombre"     => $data['nombre'],
            "apellido"   => $data['apellido'],
            "telefono"   => $data['telefono']
        ]);
    } else {
        throw new Exception("Debe proporcionar 'documento' (POST) o 'deposito' (GET).");
    }
    
} catch (Exception $e) {
    echo json_encode(["error" => true, "mensaje" => $e->getMessage()]);
}
?>
