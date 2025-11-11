<?php
// back_deposito_get_full.php
// Obtiene todos los datos de un depósito para edición completa
session_start();
include("conexion.php");

header('Content-Type: application/json; charset=utf-8');

// Validar método
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'msg' => 'Método no permitido']);
    exit;
}

if (!isset($_POST['id_deposito'])) {
    echo json_encode(['success' => false, 'msg' => 'Falta id_deposito']);
    exit;
}

$idDep = (int)$_POST['id_deposito'];
$rolUsuario = $_SESSION['rol'] ?? null;

if (!$rolUsuario) {
    echo json_encode(['success' => false, 'msg' => 'Sesión no válida']);
    exit;
}

try {
    // Consulta completa con todos los datos necesarios
    $sql = "SELECT 
        dj.id_deposito,
        dj.n_deposito,
        dj.n_expediente,
        dj.fecha_ingreso_deposito,
        dj.fecha_recojo_deposito,
        dj.fecha_notificacion_deposito,
        dj.documento_secretario,
        dj.documento_beneficiario,
        dj.id_estado,
        dj.observacion,
        
        ex.id_juzgado,
        
        j.nombre_juzgado,
        j.tipo_juzgado,
        
        p.nombre_persona AS nombre_beneficiario,
        p.apellido_persona AS apellido_beneficiario,
        p.telefono_persona AS telefono_beneficiario,
        p.correo_persona AS correo_beneficiario,
        
        CONCAT(sec.nombre_persona, ' ', sec.apellido_persona) AS nombre_secretario
        
    FROM deposito_judicial dj
    LEFT JOIN expediente ex ON dj.n_expediente = ex.n_expediente
    LEFT JOIN juzgado j ON ex.id_juzgado = j.id_juzgado
    LEFT JOIN persona p ON dj.documento_beneficiario = p.documento
    LEFT JOIN persona sec ON dj.documento_secretario = sec.documento
    WHERE dj.id_deposito = ?
    LIMIT 1";

    $stmt = mysqli_prepare($cn, $sql);
    
    if (!$stmt) {
        throw new Exception('Error preparando consulta: ' . mysqli_error($cn));
    }
    
    mysqli_stmt_bind_param($stmt, "i", $idDep);

    if (!mysqli_stmt_execute($stmt)) {
        throw new Exception('Error ejecutando consulta: ' . mysqli_stmt_error($stmt));
    }

    $result = mysqli_stmt_get_result($stmt);
    $deposito = mysqli_fetch_assoc($result);

    if (!$deposito) {
        mysqli_stmt_close($stmt);
        echo json_encode(['success' => false, 'msg' => 'Depósito no encontrado']);
        exit;
    }

    mysqli_stmt_close($stmt);

    // Obtener lista de secretarios del mismo juzgado
    // Obtener lista de todos los secretarios (para poblar selects y permitir cambio de juzgado)
    $secretarios = [];
    $sqlAllSec = "SELECT uj.codigo_usu, uj.id_juzgado, CONCAT(p.nombre_persona, ' ', p.apellido_persona) AS nombre_completo
                   FROM usuario_juzgado uj
                   JOIN persona p ON uj.codigo_usu = p.documento
                   ORDER BY uj.id_juzgado, p.nombre_persona";
    $resAllSec = mysqli_query($cn, $sqlAllSec);
    if ($resAllSec) {
        while ($sec = mysqli_fetch_assoc($resAllSec)) {
            $secretarios[] = $sec;
        }
    }

    // Obtener lista de todos los beneficiarios (para el buscador)
    $beneficiarios = [];
    $sqlBenef = "SELECT b.documento, 
                 CONCAT(p.nombre_persona, ' ', p.apellido_persona) AS nombre_completo,
                 p.telefono_persona AS telefono,
                 p.correo_persona AS correo
                 FROM beneficiario b
                 JOIN persona p ON b.documento = p.documento
                 ORDER BY p.nombre_persona";

    $resBenef = mysqli_query($cn, $sqlBenef);
    
    if ($resBenef) {
        while ($benef = mysqli_fetch_assoc($resBenef)) {
            $beneficiarios[] = $benef;
        }
    }

    // Separar el número de expediente en 3 partes (si existe)
    $expediente_parte1 = '';
    $expediente_parte2 = '';
    $expediente_parte3 = '';
    
    if ($deposito['n_expediente']) {
        // Formato esperado: XXXXX-YYYY-ZZ
        $partes = explode('-', $deposito['n_expediente']);
        if (count($partes) === 3) {
            $expediente_parte1 = $partes[0];
            $expediente_parte2 = $partes[1];
            $expediente_parte3 = $partes[2];
        }
    }

    // Devolver todo
    echo json_encode([
        'success' => true,
        'deposito' => [
            'id_deposito' => $deposito['id_deposito'],
            'n_deposito' => $deposito['n_deposito'],
            'n_expediente' => $deposito['n_expediente'],
            'expediente_parte1' => $expediente_parte1,
            'expediente_parte2' => $expediente_parte2,
            'expediente_parte3' => $expediente_parte3,
            'fecha_ingreso_deposito' => $deposito['fecha_ingreso_deposito'],
            'fecha_recojo_deposito' => $deposito['fecha_recojo_deposito'],
            'fecha_notificacion_deposito' => $deposito['fecha_notificacion_deposito'],
            'documento_secretario' => $deposito['documento_secretario'],
            'nombre_secretario' => $deposito['nombre_secretario'],
            'id_estado' => $deposito['id_estado'],
            'id_juzgado' => $deposito['id_juzgado'],
            'nombre_juzgado' => $deposito['nombre_juzgado'],
            'tipo_juzgado' => $deposito['tipo_juzgado'],
            'documento_beneficiario' => $deposito['documento_beneficiario'],
            'nombre_beneficiario' => $deposito['nombre_beneficiario'],
            'apellido_beneficiario' => $deposito['apellido_beneficiario'],
            'telefono_beneficiario' => $deposito['telefono_beneficiario'],
            'correo_beneficiario' => $deposito['correo_beneficiario'],
            'observacion' => $deposito['observacion']
        ],
        'secretarios' => $secretarios,
        // lista de juzgados (id, nombre, tipo) para poblar selects en el cliente
        'juzgados' => (function() use ($cn) {
            $out = [];
            $r = mysqli_query($cn, "SELECT id_juzgado, nombre_juzgado, tipo_juzgado FROM juzgado ORDER BY nombre_juzgado");
            if ($r) {
                while ($row = mysqli_fetch_assoc($r)) $out[] = $row;
            }
            return $out;
        })(),
        'beneficiarios' => $beneficiarios,
        'rol_usuario' => (int)$rolUsuario
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false, 
        'msg' => 'Error del servidor: ' . $e->getMessage()
    ]);
}
?>
