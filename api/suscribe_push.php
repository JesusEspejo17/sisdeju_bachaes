<?php
// Recibe el objeto JSON de la suscripción y lo almacena (p. ej. en BD)
$data = json_decode(file_get_contents('php://input'), true);

// Aquí guardas $data['endpoint'], $data['keys']['p256dh'] y $data['keys']['auth'] en tu tabla push_subscriptions.
// Asegúrate de relacionarla al usuario correcto.
?>
