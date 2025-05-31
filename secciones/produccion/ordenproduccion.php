<?php
include "../../verificar_sesion.php";
include "../../conexionBD.php";
requerirLogin();
requerirPermisoStockApp();

// Crear tabla de stock si no existe (NUEVA ESTRUCTURA)
$createTableSQL = "
CREATE TABLE IF NOT EXISTS public.sist_prod_stock (
    id SERIAL PRIMARY KEY,
    numero_etiqueta INTEGER NOT NULL,
    peso_bruto NUMERIC(10,2),
    peso_liquido NUMERIC(10,2),
    fecha_hora_producida TIMESTAMP NOT NULL,
    estado VARCHAR(50) DEFAULT 'en stock',
    numero_item INTEGER NOT NULL,
    nombre_producto VARCHAR(255),
    tipo_producto VARCHAR(50),
    id_orden_produccion INTEGER,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)";

try {
    $conexion->exec($createTableSQL);
} catch (PDOException $e) {
    // Tabla ya existe o error, continuar
}

// Variables iniciales
$ordenEncontrada = null;
$productosOrden = [];
$mensaje = '';
$error = '';
$auto_print_url = null;

// Variables de paginación
$pagina_actual = isset($_GET['pagina']) ? max(1, intval($_GET['pagina'])) : 1;
$items_por_pagina = 5;
$offset = ($pagina_actual - 1) * $items_por_pagina;

// ⭐ NUEVA LÓGICA: Detectar orden desde GET o POST ⭐
$numeroOrdenActual = null;

// 1. Verificar si viene orden por GET (desde paginación)
if (isset($_GET['orden']) && !empty($_GET['orden'])) {
    $numeroOrdenActual = intval($_GET['orden']);
}
// 2. O si viene por POST (desde búsqueda)
elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['buscar_orden'])) {
    $numeroOrdenActual = intval(trim($_POST['numero_orden']));
}

// ⭐ FUNCIÓN UNIFICADA PARA CARGAR ORDEN ⭐
function cargarOrden($conexion, $numeroOrden)
{
    try {
        // Buscar la orden de producción
        $sql = "SELECT op.*, 
                v.cliente, 
                v.moneda,
                v.monto_total,
                u.nombre as nombre_vendedor,
                TO_CHAR(op.fecha_orden, 'DD/MM/YYYY HH24:MI') as fecha_orden_formateada
                FROM public.sist_ventas_orden_produccion op
                JOIN public.sist_ventas_presupuesto v ON op.id_venta = v.id
                LEFT JOIN public.sist_ventas_usuario u ON v.id_usuario = u.id
                WHERE op.id = :numero_orden";

        $stmt = $conexion->prepare($sql);
        $stmt->bindParam(':numero_orden', $numeroOrden, PDO::PARAM_INT);
        $stmt->execute();
        $ordenEncontrada = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($ordenEncontrada) {
            // Buscar productos en tabla de toallitas
            $sqlToallitas = "SELECT DISTINCT pp.id, pp.descripcion, 'Toallitas' as tipo
                            FROM public.sist_ventas_op_toallitas ot
                            JOIN public.sist_ventas_pres_product pp ON ot.id_producto = pp.id
                            WHERE ot.id_orden_produccion = :numero_orden";

            $stmtToallitas = $conexion->prepare($sqlToallitas);
            $stmtToallitas->bindParam(':numero_orden', $numeroOrden, PDO::PARAM_INT);
            $stmtToallitas->execute();
            $productosToallitas = $stmtToallitas->fetchAll(PDO::FETCH_ASSOC);

            // Buscar productos en tabla de TNT
            $sqlTNT = "SELECT DISTINCT pp.id, pp.descripcion, 'TNT' as tipo
                      FROM public.sist_ventas_op_tnt ot
                      JOIN public.sist_ventas_pres_product pp ON ot.id_producto = pp.id
                      WHERE ot.id_orden_produccion = :numero_orden";

            $stmtTNT = $conexion->prepare($sqlTNT);
            $stmtTNT->bindParam(':numero_orden', $numeroOrden, PDO::PARAM_INT);
            $stmtTNT->execute();
            $productosTNT = $stmtTNT->fetchAll(PDO::FETCH_ASSOC);

            // Combinar resultados
            $productosOrden = array_merge($productosToallitas, $productosTNT);

            if (empty($productosOrden)) {
                return [
                    'error' => "La orden #$numeroOrden no tiene productos asociados",
                    'orden' => null,
                    'productos' => []
                ];
            }

            return [
                'error' => null,
                'orden' => $ordenEncontrada,
                'productos' => $productosOrden
            ];
        } else {
            return [
                'error' => "No se encontró la orden de producción #$numeroOrden",
                'orden' => null,
                'productos' => []
            ];
        }
    } catch (PDOException $e) {
        return [
            'error' => "Error al buscar la orden: " . $e->getMessage(),
            'orden' => null,
            'productos' => []
        ];
    }
}

// ⭐ CARGAR ORDEN SI HAY NÚMERO DISPONIBLE ⭐
if ($numeroOrdenActual) {
    $resultado = cargarOrden($conexion, $numeroOrdenActual);
    $error = $resultado['error'];
    $ordenEncontrada = $resultado['orden'];
    $productosOrden = $resultado['productos'];
}

// Manejar solicitudes AJAX para buscar productos por orden
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_buscar_productos'])) {
    $numeroOrden = intval($_POST['numero_orden']);
    $productos = [];

    try {
        // Buscar productos en tabla de toallitas
        $sqlToallitas = "SELECT DISTINCT pp.id, pp.descripcion, 'Toallitas' as tipo
                        FROM public.sist_ventas_op_toallitas ot
                        JOIN public.sist_ventas_pres_product pp ON ot.id_producto = pp.id
                        WHERE ot.id_orden_produccion = :numero_orden";

        $stmtToallitas = $conexion->prepare($sqlToallitas);
        $stmtToallitas->bindParam(':numero_orden', $numeroOrden, PDO::PARAM_INT);
        $stmtToallitas->execute();
        $productosToallitas = $stmtToallitas->fetchAll(PDO::FETCH_ASSOC);

        // Buscar productos en tabla de TNT
        $sqlTNT = "SELECT DISTINCT pp.id, pp.descripcion, 'TNT' as tipo
                  FROM public.sist_ventas_op_tnt ot
                  JOIN public.sist_ventas_pres_product pp ON ot.id_producto = pp.id
                  WHERE ot.id_orden_produccion = :numero_orden";

        $stmtTNT = $conexion->prepare($sqlTNT);
        $stmtTNT->bindParam(':numero_orden', $numeroOrden, PDO::PARAM_INT);
        $stmtTNT->execute();
        $productosTNT = $stmtTNT->fetchAll(PDO::FETCH_ASSOC);

        // Combinar resultados
        $productos = array_merge($productosToallitas, $productosTNT);

        if (!empty($productos)) {
            header('Content-Type: application/json');
            echo json_encode([
                'success' => true,
                'productos' => $productos,
                'message' => 'Productos encontrados'
            ]);
            exit();
        } else {
            // Verificar si la orden existe
            $sqlOrden = "SELECT id FROM public.sist_ventas_orden_produccion WHERE id = :numero_orden";
            $stmtOrden = $conexion->prepare($sqlOrden);
            $stmtOrden->bindParam(':numero_orden', $numeroOrden, PDO::PARAM_INT);
            $stmtOrden->execute();
            $ordenExiste = $stmtOrden->fetch(PDO::FETCH_ASSOC);

            if ($ordenExiste) {
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => true,
                    'productos' => [],
                    'message' => 'Orden existe pero no tiene productos asociados'
                ]);
            } else {
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => false,
                    'message' => 'Orden de producción no encontrada'
                ]);
            }
            exit();
        }
    } catch (PDOException $e) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => 'Error al buscar productos: ' . $e->getMessage()
        ]);
        exit();
    }
}

// ⭐ MEJORAR MANEJO DE REIMPRESIÓN CON ID ESPECÍFICO ⭐
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reimprimir_etiqueta'])) {
    $numeroOrden = intval($_POST['numero_orden_reimprimir']);
    $tipoProducto = $_POST['tipo_producto_reimprimir'];
    $idStock = isset($_POST['id_stock_reimprimir']) ? intval($_POST['id_stock_reimprimir']) : null;

    if ($tipoProducto === 'Toallitas') {
        try {
            // ⭐ INCLUIR ID DE STOCK EN LA URL SI ESTÁ DISPONIBLE
            if ($idStock && $idStock > 0) {
                $pdf_url = "toallitapdf.php?id_orden=" . $numeroOrden . "&id_stock=" . $idStock;
                $mensaje = "🔄 Reimprimiendo etiqueta específica (Item #$idStock) para Orden #{$numeroOrden}...";
            } else {
                $pdf_url = "toallitapdf.php?id_orden=" . $numeroOrden;
                $mensaje = "🔄 Reimprimiendo última etiqueta para Orden #{$numeroOrden}...";
            }

            $auto_print_url = $pdf_url;

            // ⭐ RECARGAR LA ORDEN DESPUÉS DE REIMPRIMIR ⭐
            $resultado = cargarOrden($conexion, $numeroOrden);
            if (!$resultado['error']) {
                $ordenEncontrada = $resultado['orden'];
                $productosOrden = $resultado['productos'];
            }
        } catch (Exception $e) {
            $error = "❌ Error al reimprimir: " . $e->getMessage();
        }
    } else {
        $error = "❌ Solo se pueden reimprimir etiquetas de toallitas";
    }
}

// ⭐ PROCESAR REGISTRO EN STOCK CON FECHA/HORA LOCAL ⭐
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['registrar_caja'])) {
    $numeroOrden = $_POST['numero_orden'];
    $pesoBruto = $_POST['peso_bruto'];

    try {
        $conexion->beginTransaction();

        // ⭐ RECARGAR LA ORDEN PARA MANTENER EL CONTEXTO ⭐
        $resultado = cargarOrden($conexion, $numeroOrden);
        if ($resultado['error']) {
            throw new Exception($resultado['error']);
        }

        $ordenEncontrada = $resultado['orden'];
        $productosOrden = $resultado['productos'];

        if (!empty($productosOrden)) {
            // Tomar el primer producto (ya que la orden debería tener productos relacionados)
            $producto = $productosOrden[0];

            // Obtener el siguiente número de item para esta orden
            $sqlCount = "SELECT COALESCE(MAX(numero_item), 0) + 1 as siguiente 
                         FROM public.sist_prod_stock 
                         WHERE numero_etiqueta = :numero_etiqueta";
            $stmtCount = $conexion->prepare($sqlCount);
            $stmtCount->bindParam(':numero_etiqueta', $numeroOrden, PDO::PARAM_INT);
            $stmtCount->execute();
            $siguienteItem = $stmtCount->fetch(PDO::FETCH_ASSOC)['siguiente'];

            date_default_timezone_set('America/Asuncion'); // Por ejemplo, Paraguay
            $fechaHoraLocal = date('Y-m-d H:i:s');


            // Insertar en stock con fecha/hora local
            $sqlInsert = "INSERT INTO public.sist_prod_stock 
                          (numero_etiqueta, peso_bruto, estado, numero_item, nombre_producto, 
                           tipo_producto, id_orden_produccion, fecha_hora_producida)
                          VALUES (:numero_etiqueta, :peso_bruto, 'en stock', :numero_item, 
                                  :nombre_producto, :tipo_producto, :id_orden_produccion, :fecha_hora_producida)";

            $stmtInsert = $conexion->prepare($sqlInsert);
            $stmtInsert->bindParam(':numero_etiqueta', $numeroOrden, PDO::PARAM_INT);
            $stmtInsert->bindParam(':peso_bruto', $pesoBruto, PDO::PARAM_STR);
            $stmtInsert->bindParam(':numero_item', $siguienteItem, PDO::PARAM_INT);
            $stmtInsert->bindParam(':nombre_producto', $producto['descripcion'], PDO::PARAM_STR);
            $stmtInsert->bindParam(':tipo_producto', $producto['tipo'], PDO::PARAM_STR);
            $stmtInsert->bindParam(':id_orden_produccion', $numeroOrden, PDO::PARAM_INT);
            $stmtInsert->bindParam(':fecha_hora_producida', $fechaHoraLocal, PDO::PARAM_STR);
            $stmtInsert->execute();

            $conexion->commit();

            // OPCIÓN 2 - Generar automáticamente etiqueta PDF para toallitas (IFRAME OCULTO)
            if ($producto['tipo'] === 'Toallitas') {
                try {
                    // Generar la URL del PDF (sin ID específico para nuevos registros)
                    $pdf_url = "toallitapdf.php?id_orden=" . $numeroOrden;

                    // Configurar para impresión automática con iframe
                    $auto_print_url = $pdf_url;

                    $mensaje = "✅ Caja #$siguienteItem registrada exitosamente! Peso: {$pesoBruto}kg - Orden #{$numeroOrden}";
                    $mensaje .= "<br><small class='text-info'><i class='fas fa-print me-1'></i>Imprimiendo etiqueta automáticamente...</small>";
                } catch (Exception $pdf_error) {
                    $mensaje = "✅ Caja #$siguienteItem registrada exitosamente! Peso: {$pesoBruto}kg - Orden #{$numeroOrden}<br>";
                    $mensaje .= "<small class='text-warning'>Nota: No se pudo generar la etiqueta automáticamente.</small>";
                }
            } else {
                $mensaje = "✅ Caja #$siguienteItem registrada exitosamente! Peso: {$pesoBruto}kg - Orden #{$numeroOrden}";
            }
        } else {
            throw new Exception("No se encontraron productos para esta orden");
        }
    } catch (Exception $e) {
        $conexion->rollBack();
        $error = "❌ Error al registrar la caja: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registro de Producción - Sistema Stock</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Estilos personalizados -->
    <link rel="stylesheet" href="<?php echo $url_base; ?>style.css">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            font-family: 'Inter', sans-serif;
            padding-top: 70px;
        }

        .main-container {
            min-height: 100vh;
            padding: 10px 20px 20px 20px;
        }

        .production-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(15px);
            border-radius: 25px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.2);
            border: 1px solid rgba(255, 255, 255, 0.2);
            width: 100%;
            max-width: 100%;
            overflow: hidden;
        }

        .form-section {
            padding: 2.5rem;
        }

        .form-group-custom {
            margin-bottom: 2rem;
            position: relative;
        }

        .form-label-custom {
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 0.75rem;
            display: flex;
            align-items: center;
            font-size: 1rem;
        }

        .form-label-custom i {
            margin-right: 0.5rem;
            color: #28a745;
        }

        .form-control-custom {
            border: 2px solid #e9ecef;
            border-radius: 15px;
            padding: 1rem 1.25rem;
            font-size: 1.1rem;
            transition: all 0.3s ease;
            background: #f8f9fa;
        }

        .form-control-custom:focus {
            border-color: #28a745;
            box-shadow: 0 0 0 0.2rem rgba(40, 167, 69, 0.25);
            background: white;
        }

        .btn-save {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            border: none;
            border-radius: 15px;
            padding: 1.25rem 2rem;
            font-size: 1.2rem;
            font-weight: 600;
            color: white;
            transition: all 0.3s ease;
            box-shadow: 0 10px 30px rgba(40, 167, 69, 0.3);
        }

        .btn-save:hover {
            transform: translateY(-3px);
            box-shadow: 0 15px 40px rgba(40, 167, 69, 0.4);
            color: white;
        }

        .btn-reprint {
            background: linear-gradient(135deg, #6f42c1 0%, #e83e8c 100%);
            border: none;
            border-radius: 15px;
            padding: 1.25rem 2rem;
            font-size: 1.2rem;
            font-weight: 600;
            color: white;
            transition: all 0.3s ease;
            box-shadow: 0 10px 30px rgba(111, 66, 193, 0.3);
        }

        .btn-reprint:hover {
            transform: translateY(-3px);
            box-shadow: 0 15px 40px rgba(111, 66, 193, 0.4);
            color: white;
        }

        .btn-reprint:disabled {
            background: #6c757d;
            box-shadow: none;
            transform: none;
            cursor: not-allowed;
        }

        .alert-custom {
            border-radius: 15px;
            border: none;
            padding: 1.25rem;
            margin-bottom: 2rem;
            font-weight: 500;
        }

        .orden-info-card {
            background: linear-gradient(135deg, #e8f5e8 0%, #f0f8f0 100%);
            border: 1px solid #28a745;
            border-radius: 15px;
            padding: 1.5rem;
        }

        .resumen-cajas {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            border-radius: 15px;
            padding: 1.5rem;
            border: 1px solid #dee2e6;
        }

        .ultimas-cajas {
            background: white;
            border-radius: 15px;
            padding: 1.5rem;
            border: 1px solid #e9ecef;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
        }

        .table-hover tbody tr {
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .table-hover tbody tr:hover {
            background-color: #f8f9fa;
        }

        .table-hover tbody tr.selected {
            background: linear-gradient(135deg, #e3f2fd 0%, #bbdefb 100%) !important;
            border-left: 4px solid #2196f3;
            box-shadow: 0 2px 8px rgba(33, 150, 243, 0.2);
        }

        .table-hover tbody tr.selected td {
            font-weight: 700;
            color: #1565c0;
        }

        .stats-adicionales .card {
            transition: all 0.3s ease;
            border-width: 2px;
        }

        .stats-adicionales .card:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }

        .table-responsive {
            max-height: 400px;
            overflow-y: auto;
        }

        .pagination-custom {
            margin-top: 1rem;
        }

        .pagination-custom .page-link {
            border-radius: 8px;
            margin: 0 2px;
            border: none;
            color: #6c757d;
            font-weight: 500;
        }

        .pagination-custom .page-link:hover {
            background-color: #e9ecef;
            color: #495057;
        }

        .pagination-custom .page-item.active .page-link {
            background-color: #007bff;
            border-color: #007bff;
        }

        .quick-actions {
            display: flex;
            gap: 1rem;
            margin-top: 2rem;
        }

        .btn-secondary-custom {
            background: #6c757d;
            border: none;
            border-radius: 12px;
            padding: 0.75rem 1.5rem;
            color: white;
            text-decoration: none;
            transition: all 0.3s ease;
            flex: 1;
            text-align: center;
        }

        .btn-secondary-custom:hover {
            background: #5a6268;
            transform: translateY(-2px);
            color: white;
        }

        .input-group-custom .input-group-text {
            background: #28a745;
            color: white;
            border: none;
            border-radius: 15px 0 0 15px;
            font-weight: 600;
        }

        .input-group-custom .form-control {
            border-radius: 0 15px 15px 0;
            border-left: none;
        }

        .btn-group-custom {
            display: flex;
            gap: 0.5rem;
        }

        .btn-reprint-group {
            display: flex;
            gap: 0.25rem;
        }

        .btn-reprint-group .btn {
            border-radius: 15px;
            font-weight: 600;
            transition: all 0.3s ease;
        }

        .btn-reprint-group .btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }

        .btn-outline-info {
            border: 2px solid #17a2b8;
            color: #17a2b8;
        }

        .btn-outline-info:hover:not(:disabled) {
            background: #17a2b8;
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(23, 162, 184, 0.3);
        }

        .btn-outline-secondary {
            border: 2px solid #6c757d;
            color: #6c757d;
        }

        .btn-outline-secondary:hover:not(:disabled) {
            background: #6c757d;
            color: white;
            transform: translateY(-2px);
        }

        #seleccion-info {
            animation: slideDown 0.3s ease-out;
        }

        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @media (max-width: 768px) {
            .main-container {
                padding: 10px;
            }

            .form-section {
                padding: 1.5rem;
            }

            .quick-actions {
                flex-direction: column;
            }

            .btn-group-custom {
                flex-direction: column;
            }
        }
    </style>
</head>

<body>
    <!-- Navbar flotante -->
    <nav class="navbar navbar-expand-lg navbar-dark fixed-top" style="background: rgba(0,0,0,0.2); backdrop-filter: blur(10px); border-bottom: 1px solid rgba(255,255,255,0.1);">
        <div class="container">
            <a class="navbar-brand" href="<?php echo $url_base; ?>index.php">
                <i class="fas fa-industry me-2"></i>Sistema Stock
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav me-auto">
                    <li class="nav-item">
                        <a class="nav-link" href="<?php echo $url_base; ?>index.php">
                            <i class="fas fa-home me-1"></i>Inicio
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active" href="#">
                            <i class="fas fa-plus me-1"></i>Registrar
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="listado_ordenes.php">
                            <i class="fas fa-list me-1"></i>Listado
                        </a>
                    </li>
                </ul>
                <div class="navbar-nav">
                    <div class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" id="navbarDropdown" role="button" data-bs-toggle="dropdown">
                            <i class="fas fa-user-circle me-1"></i><?php echo htmlspecialchars($_SESSION['nombre']); ?>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><a class="dropdown-item" href="<?php echo $url_base; ?>perfil.php">
                                    <i class="fas fa-id-card me-2"></i>Mi Perfil</a></li>
                            <li>
                                <hr class="dropdown-divider">
                            </li>
                            <li><a class="dropdown-item" href="<?php echo $url_base; ?>cerrar_sesion.php">
                                    <i class="fas fa-sign-out-alt me-2"></i>Cerrar Sesión</a></li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </nav>

    <!-- Contenido Principal -->
    <div class="main-container">
        <div class="container-fluid">
            <div class="production-card">
                <!-- Formulario -->
                <div class="form-section">
                    <!-- Mensajes -->
                    <?php if ($error): ?>
                        <div class="alert alert-danger alert-custom">
                            <i class="fas fa-exclamation-triangle me-2"></i><?php echo $error; ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($mensaje): ?>
                        <div class="alert alert-success alert-custom">
                            <i class="fas fa-check-circle me-2"></i><?php echo $mensaje; ?>
                        </div>
                    <?php endif; ?>

                    <!-- Estadísticas rápidas -->
                    <div class="row mb-4">
                        <div class="col-md-6">
                            <!-- Formulario de búsqueda de orden -->
                            <form method="POST" action="" id="formBuscarOrden">
                                <input type="hidden" name="buscar_orden" value="1">
                                <div class="input-group input-group-lg">
                                    <span class="input-group-text bg-primary text-white">
                                        <i class="fas fa-hashtag"></i>
                                    </span>
                                    <input type="number" class="form-control" name="numero_orden"
                                        id="numero_orden"
                                        placeholder="Número de orden de producción..."
                                        value="<?php
                                                // ⭐ NUEVA LÓGICA PARA MANTENER EL VALOR ⭐
                                                if (isset($_POST['numero_orden'])) {
                                                    echo htmlspecialchars($_POST['numero_orden']);
                                                } elseif (isset($_GET['orden'])) {
                                                    echo htmlspecialchars($_GET['orden']);
                                                } elseif ($ordenEncontrada) {
                                                    echo $ordenEncontrada['id'];
                                                }
                                                ?>"
                                        required min="1">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-search me-2"></i>Buscar Orden
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>

                    <?php if ($ordenEncontrada): ?>
                        <!-- Layout principal en dos columnas -->
                        <div class="row">
                            <!-- Columna izquierda: Información y registro -->
                            <div class="col-md-6">
                                <!-- Información de la orden encontrada -->
                                <div class="orden-info-card mb-4">
                                    <div class="row">
                                        <div class="col-8">
                                            <h6 class="text-success mb-1">
                                                <i class="fas fa-check-circle me-2"></i>Orden #{<?php echo $ordenEncontrada['id']; ?>} Encontrada
                                            </h6>
                                            <p class="mb-1"><strong>Cliente:</strong> <?php echo htmlspecialchars($ordenEncontrada['cliente']); ?></p>
                                            <p class="mb-0"><strong>Producto:</strong> <?php echo htmlspecialchars($productosOrden[0]['descripcion']); ?> (<?php echo $productosOrden[0]['tipo']; ?>)</p>
                                        </div>
                                        <div class="col-4 text-end">
                                            <span class="badge bg-<?php echo $ordenEncontrada['estado'] === 'Completado' ? 'success' : 'warning'; ?> fs-6">
                                                <?php echo $ordenEncontrada['estado']; ?>
                                            </span>
                                        </div>
                                    </div>
                                </div>

                                <!-- Formulario para registrar cajas -->
                                <form method="POST" action="" id="formRegistrarCaja">
                                    <input type="hidden" name="registrar_caja" value="1">
                                    <input type="hidden" name="numero_orden" value="<?php echo $ordenEncontrada['id']; ?>">

                                    <!-- Peso Bruto -->
                                    <div class="form-group-custom">
                                        <label class="form-label-custom">
                                            <i class="fas fa-weight"></i>Peso Bruto (kg)
                                        </label>
                                        <div class="input-group input-group-custom">
                                            <input type="number" class="form-control form-control-custom" name="peso_bruto"
                                                id="peso_bruto"
                                                placeholder="0.00" step="0.01" min="0"
                                                required autofocus>
                                            <span class="input-group-text">kg</span>
                                        </div>
                                        <small class="text-muted mt-2 d-block">
                                            <i class="fas fa-info-circle me-1"></i>
                                            <strong>Fecha/Hora:</strong> Se usará la hora actual de la máquina (<?php echo date('d/m/Y H:i:s'); ?>)
                                        </small>
                                    </div>

                                    <!-- Botones de acción -->
                                    <div class="btn-group-custom">
                                        <button type="submit" class="btn-save" style="flex: 0.7;">
                                            <i class="fas fa-plus me-2"></i>Guardar
                                        </button>

                                        <!-- Grupo de botones de reimprimir -->
                                        <div class="btn-reprint-group" style="flex: 2; display: flex; gap: 0.25rem;">
                                            <!-- Botón principal de reimprimir -->
                                            <button type="button" class="btn-reprint" id="btnReimprimir"
                                                onclick="reimprimirSeleccionada()" disabled style="flex: 1;">
                                                <i class="fas fa-print me-2"></i>Reimprimir
                                            </button>

                                            <!-- Botón para abrir PDF -->
                                            <button type="button" class="btn btn-outline-info" id="btnAbrirPDF"
                                                onclick="abrirPDFSeleccionada()" disabled style="flex: 1; border-radius: 15px; font-weight: 600;">
                                                <i class="fas fa-external-link-alt me-2"></i>Abrir PDF
                                            </button>

                                            <!-- Botón para desmarcar -->
                                            <button type="button" class="btn btn-outline-secondary" id="btnDesmarcar"
                                                onclick="desmarcarSeleccion()" disabled style="border-radius: 15px; font-weight: 600; padding: 1.25rem 1rem;">
                                                <i class="fas fa-times"></i>
                                            </button>
                                        </div>
                                    </div>

                                    <small class="text-muted mt-2 d-block" id="reprint-help" style="display: none;">
                                        <i class="fas fa-hand-pointer me-1"></i>
                                        Seleccione una fila de la tabla para reimprimir su etiqueta
                                    </small>

                                    <!-- Información de selección actual -->
                                    <div class="alert alert-info mt-3" id="seleccion-info" style="display: none; border-radius: 15px;">
                                        <div class="d-flex align-items-center justify-content-between">
                                            <div>
                                                <i class="fas fa-info-circle me-2"></i>
                                                <strong>Fila seleccionada:</strong> <span id="info-caja-numero">#</span> -
                                                <span id="info-caja-tipo">Tipo</span> - <span id="info-caja-peso">0kg</span>
                                            </div>
                                            <button type="button" class="btn-close" onclick="desmarcarSeleccion()"></button>
                                        </div>
                                    </div>
                                </form> <!-- Cierre del formRegistrarCaja -->

                                <!-- ⭐ FORMULARIO OCULTO PARA REIMPRIMIR CON ID ESPECÍFICO ⭐ -->
                                <form method="POST" action="" id="formReimprimir" style="display: none;">
                                    <input type="hidden" name="reimprimir_etiqueta" value="1">
                                    <input type="hidden" name="numero_orden_reimprimir" id="orden_reimprimir" value="">
                                    <input type="hidden" name="tipo_producto_reimprimir" id="tipo_reimprimir" value="">
                                    <input type="hidden" name="id_stock_reimprimir" id="stock_reimprimir" value="">
                                </form>

                                <!-- ⭐ RESUMEN CON NUEVA CONSULTA PARA FECHA/HORA ⭐ -->
                                <?php
                                $sqlCajasHoy = "SELECT COUNT(*) as cajas_hoy, SUM(peso_bruto) as peso_hoy_orden
                                           FROM public.sist_prod_stock 
                                           WHERE numero_etiqueta = :numero_orden 
                                           AND DATE(fecha_hora_producida) = CURRENT_DATE";
                                $stmtCajasHoy = $conexion->prepare($sqlCajasHoy);
                                $stmtCajasHoy->bindParam(':numero_orden', $ordenEncontrada['id'], PDO::PARAM_INT);
                                $stmtCajasHoy->execute();
                                $cajasHoy = $stmtCajasHoy->fetch(PDO::FETCH_ASSOC);
                                ?>

                                <div class="resumen-cajas mt-4">
                                    <h6 class="text-primary mb-3">
                                        <i class="fas fa-chart-bar me-2"></i>Resumen de Hoy - Orden #<?php echo $ordenEncontrada['id']; ?>
                                    </h6>
                                    <div class="row text-center">
                                        <div class="col-6">
                                            <div class="bg-light rounded p-3">
                                                <h4 class="text-primary mb-1"><?php echo $cajasHoy['cajas_hoy'] ?: 0; ?></h4>
                                                <small class="text-muted">Cajas Registradas</small>
                                            </div>
                                        </div>
                                        <div class="col-6">
                                            <div class="bg-light rounded p-3">
                                                <h4 class="text-success mb-1"><?php echo number_format($cajasHoy['peso_hoy_orden'] ?: 0, 1); ?></h4>
                                                <small class="text-muted">kg Total</small>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Columna derecha: Historial y estadísticas -->
                            <div class="col-md-6">
                                <!-- ⭐ ÚLTIMAS CAJAS CON NUEVA CONSULTA ⭐ -->
                                <?php
                                // Contar total de cajas para paginación
                                $sqlCountCajas = "SELECT COUNT(*) as total
                                                FROM public.sist_prod_stock 
                                                WHERE numero_etiqueta = :numero_orden";
                                $stmtCountCajas = $conexion->prepare($sqlCountCajas);
                                $stmtCountCajas->bindParam(':numero_orden', $ordenEncontrada['id'], PDO::PARAM_INT);
                                $stmtCountCajas->execute();
                                $totalCajas = $stmtCountCajas->fetch(PDO::FETCH_ASSOC)['total'];
                                $totalPaginas = ceil($totalCajas / $items_por_pagina);

                                // ⭐ OBTENER CAJAS CON NUEVA ESTRUCTURA DE FECHA/HORA ⭐
                                $sqlUltimasCajas = "SELECT id, numero_item, peso_bruto, tipo_producto,
                                                      TO_CHAR(fecha_hora_producida, 'DD/MM') as fecha,
                                                      TO_CHAR(fecha_hora_producida, 'HH24:MI') as hora
                                               FROM public.sist_prod_stock 
                                               WHERE numero_etiqueta = :numero_orden 
                                               ORDER BY id DESC 
                                               LIMIT :limit OFFSET :offset";

                                $stmtUltimasCajas = $conexion->prepare($sqlUltimasCajas);
                                $stmtUltimasCajas->bindParam(':numero_orden', $ordenEncontrada['id'], PDO::PARAM_INT);
                                $stmtUltimasCajas->bindParam(':limit', $items_por_pagina, PDO::PARAM_INT);
                                $stmtUltimasCajas->bindParam(':offset', $offset, PDO::PARAM_INT);
                                $stmtUltimasCajas->execute();
                                $ultimasCajas = $stmtUltimasCajas->fetchAll(PDO::FETCH_ASSOC);
                                ?>

                                <?php if (!empty($ultimasCajas)): ?>
                                    <div class="ultimas-cajas">
                                        <div class="d-flex justify-content-between align-items-center mb-3">
                                            <h6 class="text-secondary mb-0">
                                                <i class="fas fa-history me-2"></i>Ultimos Registros
                                            </h6>
                                            <small class="text-muted">
                                                Total: <?php echo $totalCajas; ?> cajas
                                            </small>
                                        </div>

                                        <div class="table-responsive">
                                            <table class="table table-sm table-hover">
                                                <thead class="table-light">
                                                    <tr>
                                                        <th>Caja #</th>
                                                        <th>Peso</th>
                                                        <th>Fecha</th>
                                                        <th>Hora</th>
                                                        <th>Tipo</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($ultimasCajas as $caja): ?>
                                                        <tr class="caja-row"
                                                            data-id="<?php echo $caja['id']; ?>"
                                                            data-orden="<?php echo $ordenEncontrada['id']; ?>"
                                                            data-tipo="<?php echo htmlspecialchars($caja['tipo_producto']); ?>"
                                                            data-numero="<?php echo $caja['numero_item']; ?>">
                                                            <td><strong class="text-primary">#<?php echo $caja['numero_item']; ?></strong></td>
                                                            <td><?php echo number_format($caja['peso_bruto'], 2); ?> kg</td>
                                                            <td><?php echo $caja['fecha']; ?></td>
                                                            <td><?php echo $caja['hora']; ?></td>
                                                            <td>
                                                                <span class="badge bg-<?php echo $caja['tipo_producto'] === 'Toallitas' ? 'success' : 'secondary'; ?>">
                                                                    <?php echo $caja['tipo_producto']; ?>
                                                                </span>
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>

                                        <!-- ⭐ PAGINACIÓN CORREGIDA CON PARÁMETRO DE ORDEN ⭐ -->
                                        <?php if ($totalPaginas > 1): ?>
                                            <nav aria-label="Paginación de cajas" class="pagination-custom">
                                                <ul class="pagination pagination-sm justify-content-center">
                                                    <!-- Botón Anterior -->
                                                    <?php if ($pagina_actual > 1): ?>
                                                        <li class="page-item">
                                                            <a class="page-link" href="?pagina=<?php echo $pagina_actual - 1; ?>&orden=<?php echo $ordenEncontrada['id']; ?>#ultimas-cajas">
                                                                <i class="fas fa-chevron-left"></i>
                                                            </a>
                                                        </li>
                                                    <?php endif; ?>

                                                    <!-- Números de página -->
                                                    <?php
                                                    $inicio = max(1, $pagina_actual - 2);
                                                    $fin = min($totalPaginas, $pagina_actual + 2);

                                                    for ($i = $inicio; $i <= $fin; $i++):
                                                    ?>
                                                        <li class="page-item <?php echo $i === $pagina_actual ? 'active' : ''; ?>">
                                                            <a class="page-link" href="?pagina=<?php echo $i; ?>&orden=<?php echo $ordenEncontrada['id']; ?>#ultimas-cajas">
                                                                <?php echo $i; ?>
                                                            </a>
                                                        </li>
                                                    <?php endfor; ?>

                                                    <!-- Botón Siguiente -->
                                                    <?php if ($pagina_actual < $totalPaginas): ?>
                                                        <li class="page-item">
                                                            <a class="page-link" href="?pagina=<?php echo $pagina_actual + 1; ?>&orden=<?php echo $ordenEncontrada['id']; ?>#ultimas-cajas">
                                                                <i class="fas fa-chevron-right"></i>
                                                            </a>
                                                        </li>
                                                    <?php endif; ?>
                                                </ul>
                                            </nav>
                                        <?php endif; ?>
                                    </div>
                                <?php else: ?>
                                    <div class="ultimas-cajas" id="ultimas-cajas">
                                        <h6 class="text-secondary mb-3">
                                            <i class="fas fa-history me-2"></i>Historial de Cajas
                                        </h6>
                                        <div class="text-center text-muted py-4">
                                            <i class="fas fa-inbox fa-3x mb-3 d-block"></i>
                                            <p>No hay cajas registradas para esta orden</p>
                                            <small>¡Comience registrando la primera caja!</small>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <!-- ⭐ ESTADÍSTICAS ADICIONALES CON NUEVA CONSULTA ⭐ -->
                                <div class="stats-adicionales mt-4">
                                    <?php
                                    // Estadísticas generales de la orden
                                    $sqlStatsOrden = "SELECT 
                                                 COUNT(*) as total_cajas,
                                                 SUM(peso_bruto) as peso_total_orden,
                                                 AVG(peso_bruto) as peso_promedio,
                                                 MIN(peso_bruto) as peso_minimo,
                                                 MAX(peso_bruto) as peso_maximo
                                                 FROM public.sist_prod_stock 
                                                 WHERE numero_etiqueta = :numero_orden";
                                    $stmtStatsOrden = $conexion->prepare($sqlStatsOrden);
                                    $stmtStatsOrden->bindParam(':numero_orden', $ordenEncontrada['id'], PDO::PARAM_INT);
                                    $stmtStatsOrden->execute();
                                    $statsOrden = $stmtStatsOrden->fetch(PDO::FETCH_ASSOC);
                                    ?>

                                    <div class="row">
                                        <div class="col-6">
                                            <div class="card border-primary">
                                                <div class="card-body text-center p-3">
                                                    <h5 class="text-primary mb-1"><?php echo $statsOrden['total_cajas'] ?: 0; ?></h5>
                                                    <small class="text-muted">Total Cajas</small>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-6">
                                            <div class="card border-success">
                                                <div class="card-body text-center p-3">
                                                    <h5 class="text-success mb-1"><?php echo number_format($statsOrden['peso_total_orden'] ?: 0, 1); ?></h5>
                                                    <small class="text-muted">kg Total Orden</small>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <?php if ($statsOrden['total_cajas'] > 0): ?>
                                        <div class="row mt-3">
                                            <div class="col-4">
                                                <div class="card border-info">
                                                    <div class="card-body text-center p-2">
                                                        <h6 class="text-info mb-1"><?php echo number_format($statsOrden['peso_promedio'], 2); ?></h6>
                                                        <small class="text-muted">Promedio</small>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="col-4">
                                                <div class="card border-warning">
                                                    <div class="card-body text-center p-2">
                                                        <h6 class="text-warning mb-1"><?php echo number_format($statsOrden['peso_minimo'], 2); ?></h6>
                                                        <small class="text-muted">Mínimo</small>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="col-4">
                                                <div class="card border-danger">
                                                    <div class="card-body text-center p-2">
                                                        <h6 class="text-danger mb-1"><?php echo number_format($statsOrden['peso_maximo'], 2); ?></h6>
                                                        <small class="text-muted">Máximo</small>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php else: ?>
                        <!-- Mensaje cuando no hay orden cargada -->
                        <div class="text-center py-5">
                            <i class="fas fa-search fa-4x text-muted mb-4"></i>
                            <h4 class="text-muted">Busque una orden de producción para comenzar</h4>
                            <p class="text-muted">Ingrese el número de orden en el campo superior y haga clic en "Buscar Orden"</p>
                        </div>
                    <?php endif; ?>

                    <!-- Acciones rápidas -->
                    <div class="quick-actions mt-4">
                        <a href="listado_ordenes.php" class="btn-secondary-custom">
                            <i class="fas fa-list me-2"></i>Ver Listado
                        </a>
                        <a href="<?php echo $url_base; ?>index.php" class="btn-secondary-custom">
                            <i class="fas fa-home me-2"></i>Inicio
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- IFRAME OCULTO PARA IMPRESIÓN AUTOMÁTICA -->
    <?php if (isset($auto_print_url) && !empty($auto_print_url)): ?>
        <iframe id="print-frame"
            src="<?php echo htmlspecialchars($auto_print_url); ?>"
            style="display: none; width: 0; height: 0; border: none;"
            onload="autoPrint()">
        </iframe>

        <!-- Indicador visual de impresión -->
        <div id="print-indicator" class="position-fixed top-0 end-0 m-3" style="z-index: 9999; display: none;">
            <div class="alert alert-info alert-dismissible fade show shadow-lg" style="min-width: 300px;">
                <div class="d-flex align-items-center">
                    <div class="spinner-border spinner-border-sm text-info me-3" role="status"></div>
                    <div>
                        <strong><i class="fas fa-print me-2"></i>Imprimiendo etiqueta...</strong>
                        <br><small>Verifique su impresora</small>
                    </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        </div>
    <?php endif; ?>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        // Variables globales para selección de caja
        let cajaSeleccionada = null;

        // Función para impresión automática
        function autoPrint() {
            // Mostrar indicador de impresión
            const indicator = document.getElementById('print-indicator');
            if (indicator) {
                indicator.style.display = 'block';
            }

            setTimeout(function() {
                const printFrame = document.getElementById("print-frame");

                try {
                    // Intentar imprimir usando el iframe
                    if (printFrame && printFrame.contentWindow) {
                        printFrame.contentWindow.print();

                        // Ocultar indicador después de 3 segundos
                        setTimeout(() => {
                            if (indicator) {
                                indicator.style.display = 'none';
                            }
                        }, 3000);
                    }
                } catch (e) {
                    console.log('Error con iframe, usando fallback:', e);

                    // Fallback: abrir en nueva ventana
                    const printWindow = window.open('<?php echo isset($auto_print_url) ? $auto_print_url : ""; ?>', '_blank', 'width=800,height=600');

                    if (printWindow) {
                        printWindow.onload = function() {
                            setTimeout(function() {
                                printWindow.print();

                                // Opcional: cerrar ventana después de imprimir
                                setTimeout(function() {
                                    printWindow.close();
                                }, 2000);
                            }, 500);
                        };
                    }

                    // Ocultar indicador
                    if (indicator) {
                        indicator.style.display = 'none';
                    }
                }
            }, 1000); // Esperar 1 segundo para que cargue el PDF
        }

        // ⭐ NUEVA FUNCIÓN PARA DESMARCAR SELECCIÓN ⭐
        function desmarcarSeleccion() {
            console.log('🔄 Desmarcando selección...');

            // Remover selección visual de todas las filas
            document.querySelectorAll('.caja-row').forEach(row => {
                row.classList.remove('selected');
                row.style.backgroundColor = '';
            });

            // Reset variable global
            cajaSeleccionada = null;

            // Actualizar interfaz
            actualizarInterfazSeleccion();

            console.log('✅ Selección desmarcada');
        }

        // ⭐ FUNCIÓN MEJORADA PARA ABRIR PDF CON ID ESPECÍFICO ⭐
        function abrirPDFSeleccionada() {
            console.log('📄 Abriendo PDF específico...');

            if (!cajaSeleccionada) {
                alert('Por favor, seleccione una caja antes de abrir el PDF.');
                return;
            }

            if (cajaSeleccionada.tipo !== 'Toallitas') {
                alert('Solo se pueden generar PDFs para productos tipo "Toallitas".');
                return;
            }

            try {
                // ⭐ INCLUIR ID ESPECÍFICO EN LA URL ⭐
                const pdfUrl = `toallitapdf.php?id_orden=${cajaSeleccionada.orden}&id_stock=${cajaSeleccionada.id}`;

                // Abrir en nueva pestaña
                window.open(pdfUrl, '_blank', 'width=800,height=600,scrollbars=yes,resizable=yes');

                console.log('✅ PDF abierto para caja específica:', cajaSeleccionada.id);

            } catch (error) {
                console.error('💥 Error al abrir PDF:', error);
                alert('Error al abrir el PDF: ' + error.message);
            }
        }

        // ⭐ FUNCIÓN MEJORADA PARA REIMPRIMIR CON ID ESPECÍFICO ⭐
        function reimprimirSeleccionada() {
            console.log('🔍 Iniciando reimpresión específica...');

            if (!cajaSeleccionada) {
                alert('Por favor, seleccione una caja de la tabla antes de reimprimir.');
                return;
            }

            if (cajaSeleccionada.tipo !== 'Toallitas') {
                alert('Solo se pueden reimprimir etiquetas de productos tipo "Toallitas".');
                return;
            }

            const formReimprimir = document.getElementById('formReimprimir');
            if (!formReimprimir) {
                alert('Error crítico: Formulario de reimpresión no encontrado. Recargue la página.');
                return;
            }

            try {
                // ⭐ INCLUIR TODOS LOS DATOS NECESARIOS, ESPECIALMENTE EL ID ⭐
                document.getElementById('orden_reimprimir').value = cajaSeleccionada.orden;
                document.getElementById('tipo_reimprimir').value = cajaSeleccionada.tipo;
                document.getElementById('stock_reimprimir').value = cajaSeleccionada.id;

                // Mostrar confirmación específica
                if (!confirm(`¿Está seguro de que desea reimprimir la etiqueta específica de la Caja #${cajaSeleccionada.numero} (ID: ${cajaSeleccionada.id})?`)) {
                    return;
                }

                const btnReimprimir = document.getElementById('btnReimprimir');
                if (btnReimprimir) {
                    const originalText = btnReimprimir.innerHTML;
                    btnReimprimir.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Reimprimiendo...';
                    btnReimprimir.disabled = true;

                    setTimeout(() => {
                        btnReimprimir.innerHTML = originalText;
                        btnReimprimir.disabled = false;
                    }, 5000);
                }

                console.log('🚀 Enviando formulario con ID específico:', cajaSeleccionada.id);
                formReimprimir.submit();

            } catch (error) {
                console.error('💥 Error al procesar reimpresión:', error);
                alert('Error inesperado al procesar la reimpresión: ' + error.message);
            }
        }

        // Función para seleccionar una caja
        function seleccionarCaja(elemento) {
            try {
                console.log('🖱️ Seleccionando caja específica:', elemento.dataset);

                // Remover selección previa de todas las filas
                document.querySelectorAll('.caja-row').forEach(row => {
                    row.classList.remove('selected');
                });

                // Agregar selección a la fila actual
                elemento.classList.add('selected');

                // ⭐ GUARDAR DATOS COMPLETOS DE LA CAJA SELECCIONADA ⭐
                cajaSeleccionada = {
                    id: parseInt(elemento.dataset.id), // ID específico del registro
                    orden: parseInt(elemento.dataset.orden),
                    tipo: elemento.dataset.tipo,
                    numero: parseInt(elemento.dataset.numero),
                    peso: elemento.querySelector('td:nth-child(2)').textContent.trim() // Obtener peso de la tabla
                };

                console.log('✅ Caja específica seleccionada:', cajaSeleccionada);

                // Actualizar interfaz
                actualizarInterfazSeleccion();

                // Agregar efecto visual adicional
                elemento.scrollIntoView({
                    behavior: 'smooth',
                    block: 'nearest'
                });

            } catch (error) {
                console.error('💥 Error al seleccionar caja:', error);
                alert('Error al seleccionar la caja: ' + error.message);
            }
        }

        // ⭐ NUEVA FUNCIÓN PARA ACTUALIZAR LA INTERFAZ ⭐
        function actualizarInterfazSeleccion() {
            const btnReimprimir = document.getElementById('btnReimprimir');
            const btnAbrirPDF = document.getElementById('btnAbrirPDF');
            const btnDesmarcar = document.getElementById('btnDesmarcar');
            const helpText = document.getElementById('reprint-help');
            const seleccionInfo = document.getElementById('seleccion-info');

            if (!cajaSeleccionada) {
                // Estado inicial: ninguna caja seleccionada
                if (btnReimprimir) {
                    btnReimprimir.disabled = true;
                    btnReimprimir.innerHTML = '<i class="fas fa-print me-2"></i>Reimprimir';
                }
                if (btnAbrirPDF) {
                    btnAbrirPDF.disabled = true;
                }
                if (btnDesmarcar) {
                    btnDesmarcar.disabled = true;
                }
                if (helpText) {
                    helpText.style.display = 'block';
                }
                if (seleccionInfo) {
                    seleccionInfo.style.display = 'none';
                }
                return;
            }

            // Estado: caja seleccionada
            if (helpText) {
                helpText.style.display = 'none';
            }

            // Mostrar información de selección
            if (seleccionInfo) {
                document.getElementById('info-caja-numero').textContent = `#${cajaSeleccionada.numero}`;
                document.getElementById('info-caja-tipo').textContent = cajaSeleccionada.tipo;
                document.getElementById('info-caja-peso').textContent = cajaSeleccionada.peso;
                seleccionInfo.style.display = 'block';
            }

            // Habilitar botón de desmarcar siempre
            if (btnDesmarcar) {
                btnDesmarcar.disabled = false;
            }

            // Configurar botones según el tipo de producto
            if (cajaSeleccionada.tipo === 'Toallitas') {
                if (btnReimprimir) {
                    btnReimprimir.disabled = false;
                    btnReimprimir.innerHTML = `<i class="fas fa-print me-2"></i>Reimprimir #${cajaSeleccionada.numero}`;
                    btnReimprimir.classList.remove('btn-secondary');
                    btnReimprimir.classList.add('btn-reprint');
                }

                if (btnAbrirPDF) {
                    btnAbrirPDF.disabled = false;
                    btnAbrirPDF.innerHTML = `<i class="fas fa-external-link-alt me-2"></i>Ver PDF #${cajaSeleccionada.numero}`;
                }

                console.log('✅ Botones habilitados para toallitas - ID específico:', cajaSeleccionada.id);
            } else {
                if (btnReimprimir) {
                    btnReimprimir.disabled = true;
                    btnReimprimir.innerHTML = '<i class="fas fa-print me-2"></i>Solo Toallitas';
                    btnReimprimir.classList.remove('btn-reprint');
                    btnReimprimir.classList.add('btn-secondary');
                }

                if (btnAbrirPDF) {
                    btnAbrirPDF.disabled = true;
                    btnAbrirPDF.innerHTML = '<i class="fas fa-external-link-alt me-2"></i>Solo Toallitas';
                }

                console.log('⚠️ Botones deshabilitados - producto tipo:', cajaSeleccionada.tipo);
            }
        }

        // ⭐ INICIALIZACIÓN MEJORADA ⭐
        document.addEventListener('DOMContentLoaded', function() {
            try {
                console.log('🚀 Inicializando sistema mejorado con fecha/hora local...');

                // Auto-focus en el primer campo disponible
                const pesoInput = document.getElementById('peso_bruto');
                const numeroOrdenInput = document.getElementById('numero_orden');

                if (pesoInput && pesoInput.offsetParent !== null) {
                    pesoInput.focus();
                    pesoInput.select();
                } else if (numeroOrdenInput) {
                    numeroOrdenInput.focus();
                }

                // ⭐ AGREGAR EVENT LISTENERS A LAS FILAS DE CAJAS ⭐
                const cajasRows = document.querySelectorAll('.caja-row');
                console.log(`🔍 Encontradas ${cajasRows.length} filas de cajas`);

                cajasRows.forEach((row, index) => {
                    row.addEventListener('click', function() {
                        console.log(`🖱️ Click en fila ${index + 1} - ID: ${this.dataset.id}`);
                        seleccionarCaja(this);
                    });

                    // Efecto hover
                    row.addEventListener('mouseenter', function() {
                        if (!this.classList.contains('selected')) {
                            this.style.backgroundColor = '#f8f9fa';
                        }
                    });

                    row.addEventListener('mouseleave', function() {
                        if (!this.classList.contains('selected')) {
                            this.style.backgroundColor = '';
                        }
                    });
                });

                // Inicializar interfaz en estado inicial
                actualizarInterfazSeleccion();

                // Limpiar selección después de registro exitoso
                const mensajeExito = document.querySelector('.alert-success');
                if (mensajeExito && pesoInput) {
                    setTimeout(() => {
                        pesoInput.value = '';
                        pesoInput.focus();
                        desmarcarSeleccion(); // Usar la nueva función

                        // Ocultar mensaje después de 3 segundos
                        setTimeout(() => {
                            mensajeExito.style.display = 'none';
                        }, 2000);
                    }, 100);
                }

                console.log('✅ Sistema inicializado correctamente con fecha/hora local');

            } catch (error) {
                console.error('💥 Error en la inicialización:', error);
            }
        });
    </script>

</body>

</html>