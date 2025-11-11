document.addEventListener("DOMContentLoaded", function () {
  const cboDocumento = document.getElementById("cbo_documento");
  const dniInput = document.getElementById("dni_ruc");

  const longitudes = {
    'DNI': 8,
    'RUC': 11
  };

  function actualizarLongitud() {
    const selectedOption = cboDocumento.options[cboDocumento.selectedIndex];
    const tipo = selectedOption.text;

    if (longitudes[tipo]) {
      dniInput.maxLength = longitudes[tipo];
      dniInput.placeholder = tipo + " (" + longitudes[tipo] + " dígitos)";
    } else {
      dniInput.removeAttribute("maxlength");
      dniInput.placeholder = "Documento";
    }
  }

  cboDocumento.addEventListener("change", actualizarLongitud);

  actualizarLongitud(); // Ejecutar al inicio por si hay uno preseleccionado
});
