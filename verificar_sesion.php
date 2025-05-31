<?php
// Este archivo debe ser incluido en todas las páginas que requieran autenticación

// Incluir la configuración para tener acceso a $url_base
require_once "conexionBD.php";

// Iniciar sesión si no está iniciada
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Función para verificar si el usuario ha iniciado sesión
function estaLogueado()
{
    return isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true;
}

// Función para verificar el rol del usuario
function tieneRol($rol_requerido)
{
    // Si el usuario no está logueado, retornar falso
    if (!estaLogueado()) {
        return false;
    }

    // Si el rol requerido es un array, verificar si el usuario tiene alguno de esos roles
    if (is_array($rol_requerido)) {
        return in_array($_SESSION['rol'], $rol_requerido);
    }

    // Si el rol requerido es un string, verificar si el usuario tiene ese rol
    return $_SESSION['rol'] == $rol_requerido;
}

// Nueva función para verificar si el usuario tiene permiso para la aplicación de stock
function tienePermisoStockApp()
{
    // Si el usuario no está logueado, retornar falso
    if (!estaLogueado()) {
        return false;
    }

    // Verificar si la variable de sesión stockapp existe y es verdadera
    return isset($_SESSION['stockapp']) && $_SESSION['stockapp'] === true;
}

// Función para redirigir a usuarios no autenticados
function requerirLogin()
{
    global $url_base; // Hacer la variable disponible dentro de la función
    if (!estaLogueado()) {
        header("Location: " . $url_base . "login.php");
        exit();
    }
}

// Función para redirigir a usuarios sin el rol adecuado
function requerirRol($rol_requerido)
{
    global $url_base; // Hacer la variable disponible dentro de la función
    requerirLogin();

    if (!tieneRol($rol_requerido)) {
        header("Location: " . $url_base . "acceso_denegado.php");
        exit();
    }
}

// Nueva función para redirigir a usuarios sin permiso para la aplicación de stock
function requerirPermisoStockApp()
{
    global $url_base; // Hacer la variable disponible dentro de la función
    requerirLogin();

    if (!tienePermisoStockApp()) {
        header("Location: " . $url_base . "acceso_denegado.php?error=stockapp");
        exit();
    }
}

// Función para cerrar sesión
function cerrarSesion()
{
    global $url_base; // Hacer la variable disponible dentro de la función
    // Eliminar todas las variables de sesión
    $_SESSION = array();

    // Destruir la sesión
    session_destroy();

    // Redirigir al login
    header("Location: " . $url_base . "login.php");
    exit();
}
