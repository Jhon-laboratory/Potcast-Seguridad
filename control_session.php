<?php
session_start();

// Check if the session variable is set
$perfilesPermitidos = [1, 22,23,16,14,24];
//1 admin, 16 contable

// Obtener el perfil de sesión y convertirlo en un array
$perfilActual = isset($_SESSION["gb_perfil"]) ? $_SESSION["gb_perfil"] : null;

// Si hay un perfil, dividirlo en un array
if ($perfilActual !== null) {
    $perfilesArray = array_map('intval', explode(',', $perfilActual)); // Convertir a enteros
} else {
    $perfilesArray = [];
}

// Verificar si al menos uno de los perfiles permitidos está en el array de perfiles de sesión
if (empty($perfilesArray) || !array_intersect($perfilesArray, $perfilesPermitidos)) {
    session_destroy();
    
    echo "<script> 
        window.location.href = '../../index.php'; 
    </script>";
    
    exit(); // Siempre usa exit después de la redirección
}


/*
if (!$_SESSION["logueado"] == TRUE) {
	header("Location: ../index.php");
} 
*/
?>