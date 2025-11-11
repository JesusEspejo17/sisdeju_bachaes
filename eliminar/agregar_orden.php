<?php
include("../code_back/conexion.php");

// Juzgados
$sql_juzgados = "SELECT id_juzgado, nombre_juzgado, tipo_juzgado FROM juzgado";
$r_juzgados = mysqli_query($cn, $sql_juzgados);

// Secretarios
$sql_secretarios = "SELECT u.codigo_usu, u.id_juzgado, CONCAT(p.nombre_persona, ' ', p.apellido_persona) AS nombre_completo
                    FROM usuario u
                    JOIN persona p ON u.codigo_usu = p.documento
                    WHERE u.id_rol = 3";
$r_secretarios = mysqli_query($cn, $sql_secretarios);

// Beneficiarios
$sql_beneficiarios = "SELECT b.id_documento, b.documento, CONCAT(p.nombre_persona, ' ', p.apellido_persona) AS nombre_completo
                      FROM beneficiario b
                      JOIN persona p ON b.documento = p.documento";
$r_beneficiarios = mysqli_query($cn, $sql_beneficiarios);

// Depósitos
$sql_depositos = "SELECT n_deposito, monto_deposito, n_expediente FROM deposito_judicial";
$r_depositos = mysqli_query($cn, $sql_depositos);
$depositos_array = [];
while ($dep = mysqli_fetch_assoc($r_depositos)) {
    $depositos_array[] = $dep;
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Registrar Orden de Pago</title>
  <link rel="stylesheet" href="../css/crear_usuario.css">
</head>
<body>

<div class="main-container">
  <h1>Registrar Orden de Pago</h1>

  <form action="../code_back/back_orden_pago_agregar.php" method="post">

    <!-- Expediente dividido -->
    <div class="form-row">
      <input type="text" name="expediente_1" maxlength="5" required placeholder="XXXXX - Nro" oninput="actualizarDepositos()">
      <span>-</span>
      <input type="text" name="expediente_2" maxlength="4" required placeholder="YYYY - Año" oninput="actualizarDepositos()">
      <span>-</span>
      <input type="text" name="expediente_3" maxlength="2" required placeholder="Z o ZZ" oninput="actualizarDepositos()">
    </div>

    <!-- Depósito (filtrado por expediente en JS) -->
    <div class="form-row">
      <select name="n_deposito" id="select_deposito" required disabled>
        <option value="" disabled selected>Complete primero el N° de Expediente</option>
      </select>
    </div>

    <!-- Tipo Juzgado -->
    <div class="form-row">
      <select id="tipo_juzgado" onchange="filtrarJuzgados()" required>
        <option value="" disabled selected>Seleccione tipo de Juzgado</option>
        <option value="PAZ LETRADO">Paz Letrado</option>
        <option value="ESPECIALIZADO">Paz Letrado Especializado</option>
      </select>
    </div>

    <!-- Juzgado y Secretario -->
    <div class="form-row">
      <select name="juzgado" id="juzgado" required onchange="cargarSecretarios()">
        <option value="" disabled selected>Seleccione un Juzgado</option>
      </select>

      <select name="secretario" id="secretario" required disabled>
        <option value="">Seleccione un Juzgado primero</option>
      </select>
    </div>

    <!-- Combo de beneficiarios -->
    <div class="form-row">
      <select name="beneficiario" id="beneficiario" required onchange="mostrarCamposNuevoBeneficiario(this.value)">
        <option value="" disabled selected>Seleccione un Beneficiario</option>
        <option value="nuevo">➕ Agregar nuevo beneficiario</option>
        <?php 
          // Reiniciar el puntero por si acaso
          mysqli_data_seek($r_beneficiarios, 0); 
          while ($b = mysqli_fetch_assoc($r_beneficiarios)) { 
        ?>
          <option value="<?php echo $b['documento']; ?>">
            <?php echo $b['documento'] . " - " . $b['nombre_completo']; ?>
          </option>
        <?php } ?>
      </select>
    </div>


    <!-- Campos para nuevo beneficiario -->
    <div id="nuevo-beneficiario" style="display: none; gap: 8px; flex-direction: column; margin-top: 10px;">
      <div class="form-row">
        <select id="tipo_documento" onchange="cambiarLongitudDocumento()" required>
          <option value="dni" selected>DNI</option>
          <option value="ruc">RUC</option>
        </select>
        <input type="text" id="doc_beneficiario" name="doc_beneficiario" placeholder="DNI - 8 dígitos" maxlength="8" required>
      </div>
      <div class="form-row">
        <input type="text" name="nombre_beneficiario" placeholder="Nombres" required>
        <input type="text" name="apellido_beneficiario" placeholder="Apellidos" required>
      </div>
    </div>

    <!-- Botón -->
    <div class="form-row">
      <input type="submit" value="Registrar Orden de Pago">
    </div>

  </form>
</div>

<script>
const juzgados = <?php
  mysqli_data_seek($r_juzgados, 0);
  echo json_encode(mysqli_fetch_all($r_juzgados, MYSQLI_ASSOC));
?>;

const secretarios = <?php
  mysqli_data_seek($r_secretarios, 0);
  echo json_encode(mysqli_fetch_all($r_secretarios, MYSQLI_ASSOC));
?>;

const depositos = <?php echo json_encode($depositos_array); ?>;

function filtrarJuzgados() {
  const tipo = document.getElementById("tipo_juzgado").value;
  const juzgadoSelect = document.getElementById("juzgado");
  juzgadoSelect.innerHTML = '<option value="" disabled selected>Seleccione un Juzgado</option>';

  juzgados.forEach(j => {
    if (j.tipo_juzgado === tipo) {
      const option = document.createElement("option");
      option.value = j.id_juzgado;
      option.textContent = j.nombre_juzgado;
      juzgadoSelect.appendChild(option);
    }
  });

  const secretarioSelect = document.getElementById("secretario");
  secretarioSelect.innerHTML = '<option value="">Seleccione un Juzgado primero</option>';
  secretarioSelect.disabled = true;
}

function cargarSecretarios() {
  const juzgadoId = document.getElementById("juzgado").value;
  const secretarioSelect = document.getElementById("secretario");

  secretarioSelect.innerHTML = '<option value="" disabled selected>Seleccione un Secretario</option>';
  let hay = false;

  secretarios.forEach(s => {
    if (s.id_juzgado === juzgadoId) {
      const option = document.createElement("option");
      option.value = s.codigo_usu;
      option.textContent = s.nombre_completo;
      secretarioSelect.appendChild(option);
      hay = true;
    }
  });

  secretarioSelect.disabled = !hay;

  if (!hay) {
    const opt = document.createElement("option");
    opt.textContent = "No hay secretarios";
    opt.disabled = true;
    secretarioSelect.appendChild(opt);
  }
}

function actualizarDepositos() {
  const e1 = document.querySelector('input[name="expediente_1"]').value.trim();
  const e2 = document.querySelector('input[name="expediente_2"]').value.trim();
  const e3 = document.querySelector('input[name="expediente_3"]').value.trim();
  const sel = document.getElementById("select_deposito");

  if (e1.length === 5 && e2.length === 4 && e3.length >= 1) {
    const expediente = `${e1}-${e2}-${e3}`;
    const filtrados = depositos.filter(d => d.n_expediente === expediente);

    sel.innerHTML = "";
    if (filtrados.length > 0) {
      sel.disabled = false;
      sel.innerHTML = '<option value="" disabled selected>Seleccione un Depósito</option>';
      filtrados.forEach(dep => {
        const op = document.createElement("option");
        op.value = dep.n_deposito;
        op.textContent = `${dep.n_deposito} - S/ ${parseFloat(dep.monto_deposito).toFixed(2)}`;
        sel.appendChild(op);
      });
    } else {
      sel.disabled = true;
      sel.innerHTML = '<option value="" disabled selected>No hay depósitos para este expediente</option>';
    }
  } else {
    sel.disabled = true;
    sel.innerHTML = '<option value="" disabled selected>Complete primero el N° de Expediente</option>';
  }
}

function mostrarCamposNuevoBeneficiario(valor) {
  const contenedor = document.getElementById("nuevo-beneficiario");

  if (valor === "nuevo") {
    contenedor.style.display = "flex";
    document.getElementById("beneficiario").removeAttribute("name");
    document.getElementById("doc_beneficiario").setAttribute("name", "beneficiario");
  } else {
    contenedor.style.display = "none";
    document.getElementById("beneficiario").setAttribute("name", "beneficiario");
    document.getElementById("doc_beneficiario").removeAttribute("name");
  }
}

function cambiarLongitudDocumento() {
  const tipo = document.getElementById("tipo_documento").value;
  const input = document.getElementById("doc_beneficiario");

  if (tipo === "dni") {
    input.maxLength = 8;
    input.placeholder = "DNI - 8 dígitos";
  } else {
    input.maxLength = 11;
    input.placeholder = "RUC - 11 dígitos";
  }
}
</script>

</body>
</html>
