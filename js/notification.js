const NOTIF_URL = '../api/ver_notificaciones.php';

// Pide permiso para mostrar notificaciones del navegador
if (Notification.permission !== 'granted') {
  Notification.requestPermission();
}

fetch(NOTIF_URL)
// Mostrar en la lista HTML (si la tienes)
function mostrarNotificaciones(notificaciones) {
  const lista = document.getElementById("lista-notificaciones");
  if (!lista) return;
  
  lista.innerHTML = "";

  const filtradas = notificaciones.filter(n => n.id_usuario === dniUsuarioActual);

  filtradas.forEach(noti => {
    const li = document.createElement("li");
    li.innerHTML = `
      <strong>${noti.titulo}</strong><br>
      <small>${noti.fecha}</small><br>
      ${noti.mensaje}
    `;
    lista.appendChild(li);
  });
}

// Verificar notificaciones y mostrar al usuario correspondiente
function verificarNotificaciones() {
  fetch(`${NOTIF_URL}?dni=${encodeURIComponent(dniUsuarioActual)}`)
    .then(response => response.json())
    .then(data => {
      const notificaciones = data.notifications || [];

      notificaciones.forEach(n => {
        const titulo = n.titulo || "Notificación";
        const cuerpo = `Depósito N° ${n.n_deposito} - Expediente: ${n.n_expediente}`;
        const fecha = n.fecha || "";

        new Notification(titulo, {
          body: `${cuerpo}\n${fecha}`,
          icon: "../img/icono_notificacion.png"
        });
      });

      mostrarNotificaciones(notificaciones);
    })
    .catch(err => console.error("Error al verificar notificaciones", err));
}


// Verifica cada 60 segundos
setInterval(verificarNotificaciones, 60000);
verificarNotificaciones(); // Verifica inmediatamente al cargar
