document.addEventListener("DOMContentLoaded", function () {
  const cboDocumento = document.getElementById("cbo_documento");
  const dniInput = document.getElementById("dni_ruc");

  // Longitudes esperadas según el tipo de documento
  const longitudes = {
    'DNI': 8,
    'RUC': 11
  };

  cboDocumento.addEventListener("change", function () {
    const selectedText = cboDocumento.options[cboDocumento.selectedIndex].text;

    if (longitudes[selectedText]) {
      dniInput.disabled = false;
      dniInput.value = "";
      dniInput.maxLength = longitudes[selectedText];
      dniInput.placeholder = selectedText + " (" + longitudes[selectedText] + " dígitos)";
    } else {
      dniInput.disabled = true;
      dniInput.value = "";
      dniInput.placeholder = "DNI o RUC";
    }
  });
});
