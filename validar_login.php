<?php
session_start();
include "conexionBD.php"; // Incluimos la conexión a la base de datos

// Verificar si se recibieron los datos del formulario
if (isset($_POST['usuario']) && isset($_POST['password'])) {

    // Limpiar los datos de entrada
    $usuario = trim($_POST['usuario']);
    $password = trim($_POST['password']);

    // Verificar que los campos no estén vacíos
    if (empty($usuario) || empty($password)) {
        header("Location: " . $url_base . "login.php?error=empty");
        exit();
    }

    try {
        // Preparar consulta para buscar al usuario por campo usuario
        // Añadimos el campo stockapp a la consulta
        $query = "SELECT id, nombre, usuario, rol, contrasenia, stockapp FROM public.sist_ventas_usuario WHERE usuario = :usuario";
        $stmt = $conexion->prepare($query);
        $stmt->bindParam(':usuario', $usuario, PDO::PARAM_STR);

        // Ejecutar la consulta
        $stmt->execute();

        // Verificar si existe el usuario
        if ($stmt->rowCount() == 1) {
            $usuario_data = $stmt->fetch(PDO::FETCH_ASSOC);

            // Verificar la contraseña
            if ($password == $usuario_data['contrasenia']) { // En producción usar password_verify()

                // Verificar si stockapp es verdadero
                if (isset($usuario_data['stockapp']) && $usuario_data['stockapp'] == true) {
                    // Iniciar sesión
                    $_SESSION['id'] = $usuario_data['id'];
                    $_SESSION['nombre'] = $usuario_data['nombre'];
                    $_SESSION['usuario'] = $usuario_data['usuario'];
                    $_SESSION['rol'] = $usuario_data['rol'];
                    $_SESSION['stockapp'] = $usuario_data['stockapp']; // Guardamos el valor en la sesión
                    $_SESSION['loggedin'] = true;

                    // Redirigir según el rol
                    header("Location: " . $url_base . "index.php");
                    exit();
                } else {
                    // Usuario no tiene permiso para la aplicación de stock
                    header("Location: " . $url_base . "login.php?error=nopermiso");
                    exit();
                }
            } else {
                // Contraseña incorrecta
                header("Location: " . $url_base . "login.php?error=invalid");
                exit();
            }
        } else {
            // Usuario no encontrado
            header("Location: " . $url_base . "login.php?error=invalid");
            exit();
        }
    } catch (PDOException $e) {
        // Error en la consulta
        header("Location: " . $url_base . "login.php?error=database");
        exit();
    }
} else {
    // Acceso directo al script sin enviar el formulario
    header("Location: " . $url_base . "login.php");
    exit();
}
