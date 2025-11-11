<?php

session_start();
$_SESSION = [];          // Limpia todas las variables de sesión
session_destroy();       // Destruye la sesión
header('Location: ../index.php');
exit();

?>