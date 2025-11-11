<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Iniciar sesión</title>
  <link rel="stylesheet" href="css/login.css">
</head>
<body>

  <div class="body"></div>
  <div class="grad"></div>

  <div class="contenedor-login">

    <div class="titulo">
      <b>Sistema de <span>Depósitos Judiciales</span></b>
    </div>

    <div class="contenido">
      <div class="header">
        <img src="img/pj2.png" alt="Logo Poder Judicial">
        <!-- Texto agregado -->
        <div class="credit" aria-hidden="false">Hecho por MSNL - Voluntariado 2025_1</div>
      </div>

      <form id="loginForm" action="code_back/back_index.php" method="post" novalidate>
        <div class="login">
          
          <input
            id="usuario"
            type="text"
            name="usuario"
            placeholder="Usuario"
            autocomplete="off"
            maxlength="8"
            minlength="8"
            pattern="\d{8}"
            inputmode="numeric"
            required
            autofocus
          ><br>

          
          <input
            id="password"
            type="password"
            name="password"
            placeholder="Contraseña"
            autocomplete="current-password"
            required
          ><br>

          <input type="submit" value="Iniciar sesión">
        </div>
      </form>
    </div>

  </div>

<script>
document.addEventListener("DOMContentLoaded", function () {
  const form = document.getElementById('loginForm');

  // Tomamos solo inputs tipo texto y password (excluimos submit)
  const inputs = Array.from(form.querySelectorAll('input')).filter(i => i.type === 'text' || i.type === 'password');

  inputs.forEach((input, index) => {
    input.addEventListener('keydown', function (event) {
      if (event.key === 'Enter') {
        event.preventDefault(); // evitamos comportamiento por defecto
        if (index < inputs.length - 1) {
          // pasa al siguiente input (ej: del usuario a contraseña)
          inputs[index + 1].focus();
        } else {
          // si es el último (contraseña), intentamos enviar el form
          submitFormIfValid();
        }
      }
    });
  });

  // Antes de enviar: trim y comprobación simple
  function submitFormIfValid() {
    // limpiar espacios
    const usuario = document.getElementById('usuario');
    const password = document.getElementById('password');
    usuario.value = usuario.value.trim();
    password.value = password.value.trim();

    // validación HTML5: required, pattern, minlength...
    if (!form.checkValidity()) {
      // forzar mostrar mensajes nativos de validación
      form.reportValidity();
      return;
    }

    form.submit();
  }

  // Interceptamos submit para hacer trim y validación final
  form.addEventListener('submit', function (e) {
    e.preventDefault();
    submitFormIfValid();
  });
});
</script>
</body>
</html>