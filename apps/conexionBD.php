<?php 

$url_base = "http://localhost:8001/"; 

$servidor = "localhost";  
$puerto = "5433";         
$basededatos = "AMERICATNT2.0";
$usuario = "postgres";
$contrasenia = "6770";

//$url_base = "http://192.168.1.185/App_StockV/";


//$servidor = "192.168.1.185";  
//$puerto = "5432";         
//$basededatos = "AMERICATNT2.0";
//$usuario = "postgres";
//$contrasenia = "159angel";

// Definir si estamos en modo desarrollo o producción
$modo_desarrollo = true; 

try {
    $dsn = "pgsql:host=$servidor;port=$puerto;dbname=$basededatos";
    $opciones = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];
    
    $conexion = new PDO($dsn, $usuario, $contrasenia, $opciones);
    
} catch (PDOException $e) {
    if ($modo_desarrollo) {
        // En desarrollo, mostrar información detallada
        echo "<h3>Error de conexión a la base de datos</h3>";
        echo "<p><strong>Mensaje:</strong> " . $e->getMessage() . "</p>";
        echo "<p><strong>Código:</strong> " . $e->getCode() . "</p>";
        echo "<p><strong>Archivo:</strong> " . $e->getFile() . "</p>";
        echo "<p><strong>Línea:</strong> " . $e->getLine() . "</p>";
    } else {
        // En producción, mostrar mensaje genérico
        echo "Lo sentimos, no se pudo establecer conexión con la base de datos. Por favor, inténtelo más tarde.";
        
        // Registrar el error completo en el log
        error_log("Error de conexión PDO: " . $e->getMessage() . " en " . $e->getFile() . ":" . $e->getLine());
    }
    
    // Terminar la ejecución del script
    exit();
}
?>