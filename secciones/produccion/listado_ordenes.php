<?php
include "../../verificar_sesion.php";
include "../../conexionBD.php";
requerirLogin();
requerirPermisoStockApp();

// Variables de paginación
$items_por_pagina = 10;
$pagina_actual = isset($_GET['pagina']) ? max(1, intval($_GET['pagina'])) : 1;
$offset = ($pagina_actual - 1) * $items_por_pagina;

// Variables de filtrado
$filtro_fecha = isset($_GET['fecha']) ? trim($_GET['fecha']) : date('Y-m-d'); // Por defecto la fecha actual
$filtro_orden = isset($_GET['orden']) ? trim($_GET['orden']) : '';
$filtro_producto = isset($_GET['producto']) ? trim($_GET['producto']) : '';
$filtro_tipo = isset($_GET['tipo']) ? trim($_GET['tipo']) : '';
$ordenar_por = isset($_GET['ordenar_por']) ? trim($_GET['ordenar_por']) : 'hora_desc';

// Consulta base para obtener las cajas agrupadas por orden de producción
$sql_base = "SELECT 
             s.id_orden_produccion,
             COUNT(s.id) as total_cajas,
             SUM(s.peso_bruto) as peso_bruto_total,
             SUM(s.peso_liquido) as peso_liquido_total,
             MIN(s.hora_producida) as primera_hora,
             MAX(s.hora_producida) as ultima_hora,
             s.nombre_producto,
             s.tipo_producto,
             s.fecha_producida,
             v.cliente,
             v.moneda,
             v.monto_total,
             op.estado as estado_orden,
             u.nombre as nombre_vendedor
             FROM public.sist_prod_stock s
             LEFT JOIN public.sist_ventas_orden_produccion op ON s.id_orden_produccion = op.id
             LEFT JOIN public.sist_ventas_presupuesto v ON op.id_venta = v.id
             LEFT JOIN public.sist_ventas_usuario u ON v.id_usuario = u.id";

// Construir condiciones de filtrado
$condiciones = [];
$parametros = [];

// Siempre filtramos por fecha
$condiciones[] = "s.fecha_producida = :fecha";
$parametros[':fecha'] = $filtro_fecha;

if (!empty($filtro_orden)) {
    $condiciones[] = "s.id_orden_produccion = :orden";
    $parametros[':orden'] = $filtro_orden;
}

if (!empty($filtro_producto)) {
    $condiciones[] = "s.nombre_producto ILIKE :producto";
    $parametros[':producto'] = '%' . $filtro_producto . '%';
}

if (!empty($filtro_tipo)) {
    $condiciones[] = "s.tipo_producto = :tipo";
    $parametros[':tipo'] = $filtro_tipo;
}

// Añadir condiciones a la consulta
if (!empty($condiciones)) {
    $sql_base .= " WHERE " . implode(" AND ", $condiciones);
}

// Agrupar por orden de producción
$sql_base .= " GROUP BY s.id_orden_produccion, s.nombre_producto, s.tipo_producto, s.fecha_producida, 
                v.cliente, v.moneda, v.monto_total, op.estado, u.nombre";

// Determinar el orden
switch ($ordenar_por) {
    case 'hora_asc':
        $sql_base .= " ORDER BY primera_hora ASC";
        break;
    case 'peso_desc':
        $sql_base .= " ORDER BY peso_bruto_total DESC";
        break;
    case 'peso_asc':
        $sql_base .= " ORDER BY peso_bruto_total ASC";
        break;
    case 'cajas_desc':
        $sql_base .= " ORDER BY total_cajas DESC";
        break;
    case 'cajas_asc':
        $sql_base .= " ORDER BY total_cajas ASC";
        break;
    case 'orden_asc':
        $sql_base .= " ORDER BY s.id_orden_produccion ASC";
        break;
    case 'orden_desc':
        $sql_base .= " ORDER BY s.id_orden_produccion DESC";
        break;
    case 'hora_desc':
    default:
        $sql_base .= " ORDER BY ultima_hora DESC";
        break;
}

// Consulta para obtener el número total de registros (grupos)
$sql_count = "SELECT COUNT(*) FROM (" . $sql_base . ") AS subquery";

// Preparar y ejecutar la consulta de conteo
$stmt_count = $conexion->prepare($sql_count);
foreach ($parametros as $param => $valor) {
    $stmt_count->bindValue($param, $valor);
}
$stmt_count->execute();
$total_registros = $stmt_count->fetchColumn();
$total_paginas = ceil($total_registros / $items_por_pagina);

// Añadir límite y offset para paginación
$sql_paginado = $sql_base . " LIMIT :limit OFFSET :offset";

// Preparar y ejecutar la consulta principal con paginación
$stmt = $conexion->prepare($sql_paginado);
foreach ($parametros as $param => $valor) {
    $stmt->bindValue($param, $valor);
}
$stmt->bindValue(':limit', $items_por_pagina, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$produccion_por_orden = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Obtener tipos de producto únicos para el filtro
$sql_tipos = "SELECT DISTINCT tipo_producto FROM public.sist_prod_stock ORDER BY tipo_producto";
$stmt_tipos = $conexion->prepare($sql_tipos);
$stmt_tipos->execute();
$tipos_producto = $stmt_tipos->fetchAll(PDO::FETCH_COLUMN);

// Calcular estadísticas globales para la fecha seleccionada
$sql_stats = "SELECT 
              COUNT(DISTINCT id_orden_produccion) as total_ordenes,
              COUNT(*) as total_cajas,
              SUM(peso_bruto) as peso_bruto_total,
              SUM(peso_liquido) as peso_liquido_total,
              COUNT(DISTINCT tipo_producto) as tipos_distintos,
              AVG(peso_bruto) as peso_promedio
              FROM public.sist_prod_stock 
              WHERE fecha_producida = :fecha";

$stmt_stats = $conexion->prepare($sql_stats);
$stmt_stats->bindValue(':fecha', $filtro_fecha);
$stmt_stats->execute();
$estadisticas = $stmt_stats->fetch(PDO::FETCH_ASSOC);

// Función para construir los parámetros de URL para paginación manteniendo los filtros
function construirQueryParams($pagina, $params_actuales = [])
{
    $params = $params_actuales;
    $params['pagina'] = $pagina;
    return http_build_query($params);
}

$params_actuales = [
    'fecha' => $filtro_fecha,
    'orden' => $filtro_orden,
    'producto' => $filtro_producto,
    'tipo' => $filtro_tipo,
    'ordenar_por' => $ordenar_por
];

// Formatear la fecha para mostrar
$fecha_mostrar = date('d/m/Y', strtotime($filtro_fecha));
$es_hoy = (date('Y-m-d') === $filtro_fecha);

// Consulta para obtener el detalle de cajas más recientes
function obtenerCajasRecientes($conexion, $id_orden, $limit = 5)
{
    $sql = "SELECT id, numero_item, peso_bruto, tipo_producto,
            TO_CHAR(fecha_producida, 'DD/MM') as fecha,
            TO_CHAR(hora_producida, 'HH24:MI') as hora
            FROM public.sist_prod_stock 
            WHERE id_orden_produccion = :orden 
            ORDER BY hora_producida DESC
            LIMIT :limit";

    $stmt = $conexion->prepare($sql);
    $stmt->bindValue(':orden', $id_orden, PDO::PARAM_INT);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>

<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Producción Diaria - Sistema Stock</title>
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

        .card-listado {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(15px);
            border-radius: 25px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.2);
            border: 1px solid rgba(255, 255, 255, 0.2);
            width: 100%;
            max-width: 100%;
            overflow: hidden;
            padding: 2.5rem;
        }

        .orden-card {
            background: #ffffff;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.05);
            transition: all 0.3s ease;
            border: 1px solid #e9ecef;
            overflow: hidden;
            margin-bottom: 1.5rem;
        }

        .orden-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 12px 30px rgba(0, 0, 0, 0.1);
        }

        .orden-header {
            padding: 1.25rem;
            background: linear-gradient(to right, #f8f9fa, #e9ecef);
            border-bottom: 1px solid #dee2e6;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .orden-body {
            padding: 1.25rem;
        }

        .orden-footer {
            padding: 1rem 1.25rem;
            background: #f8f9fa;
            border-top: 1px solid #e9ecef;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .badge-estado {
            font-size: 0.85rem;
            padding: 0.5rem 1rem;
            border-radius: 50px;
            font-weight: 600;
        }

        .btn-accion {
            border-radius: 12px;
            font-weight: 600;
            padding: 0.5rem 1.25rem;
            transition: all 0.3s ease;
            text-decoration: none;
        }

        .btn-registrar {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            color: white;
            border: none;
            box-shadow: 0 5px 15px rgba(40, 167, 69, 0.2);
        }

        .btn-registrar:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(40, 167, 69, 0.3);
            color: white;
        }

        .btn-ver {
            background: #6c757d;
            color: white;
            border: none;
        }

        .btn-ver:hover {
            background: #5a6268;
            color: white;
        }

        .filtro-card {
            background: #ffffff;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.05);
            padding: 1.5rem;
            margin-bottom: 2rem;
            border: 1px solid #e9ecef;
        }

        .btn-filtrar {
            background: linear-gradient(135deg, #007bff 0%, #6610f2 100%);
            color: white;
            border: none;
            border-radius: 12px;
            font-weight: 600;
            padding: 0.75rem 1.5rem;
            transition: all 0.3s ease;
        }

        .btn-filtrar:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(0, 123, 255, 0.3);
            color: white;
        }

        .btn-limpiar {
            background: #6c757d;
            color: white;
            border: none;
            border-radius: 12px;
            font-weight: 600;
            padding: 0.75rem 1.5rem;
            transition: all 0.3s ease;
        }

        .btn-limpiar:hover {
            background: #5a6268;
            color: white;
        }

        .stats-card {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            border-radius: 15px;
            padding: 1.5rem;
            margin-bottom: 2rem;
            border: 1px solid #dee2e6;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.05);
        }

        .stat-item {
            text-align: center;
            padding: 1rem;
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.05);
            transition: all 0.3s ease;
        }

        .stat-item:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }

        .stat-value {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 0.25rem;
        }

        .stat-label {
            font-size: 0.85rem;
            color: #6c757d;
            margin-bottom: 0;
        }

        .pagination-custom {
            margin-top: 2rem;
            display: flex;
            justify-content: center;
        }

        .pagination-custom .page-link {
            border-radius: 8px;
            margin: 0 3px;
            color: #007bff;
            font-weight: 500;
        }

        .pagination-custom .page-item.active .page-link {
            background-color: #007bff;
            border-color: #007bff;
        }

        .form-select-custom,
        .form-control-custom {
            border: 2px solid #e9ecef;
            border-radius: 12px;
            padding: 0.75rem 1rem;
            font-size: 0.95rem;
            transition: all 0.3s ease;
        }

        .form-select-custom:focus,
        .form-control-custom:focus {
            border-color: #007bff;
            box-shadow: 0 0 0 0.2rem rgba(0, 123, 255, 0.25);
        }

        .form-label-custom {
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: #495057;
        }

        .orden-meta {
            display: flex;
            align-items: center;
            margin-bottom: 0.5rem;
            color: #6c757d;
        }

        .orden-meta i {
            margin-right: 0.5rem;
            width: 18px;
            text-align: center;
        }

        .datos-cliente {
            font-weight: 600;
            font-size: 1.1rem;
            margin-bottom: 0.5rem;
            color: #343a40;
        }

        .orden-id {
            font-weight: 700;
            font-size: 1.25rem;
            color: #007bff;
        }

        .orden-stats {
            display: flex;
            gap: 1rem;
            margin-top: 1rem;
        }

        .orden-stat {
            background: #f8f9fa;
            padding: 0.75rem;
            border-radius: 8px;
            flex: 1;
            text-align: center;
            border: 1px solid #e9ecef;
        }

        .orden-stat-value {
            font-weight: 700;
            font-size: 1.2rem;
            color: #495057;
            margin-bottom: 0.25rem;
        }

        .orden-stat-label {
            font-size: 0.8rem;
            color: #6c757d;
            margin-bottom: 0;
        }

        .cajas-recientes {
            margin-top: 1rem;
        }

        .cajas-recientes-titulo {
            font-size: 1rem;
            font-weight: 600;
            color: #495057;
            margin-bottom: 0.75rem;
            display: flex;
            align-items: center;
        }

        .cajas-recientes-titulo i {
            margin-right: 0.5rem;
            color: #6c757d;
        }

        .caja-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.5rem;
            border-radius: 8px;
            background: #f8f9fa;
            margin-bottom: 0.5rem;
            border: 1px solid #e9ecef;
        }

        .caja-numero {
            font-weight: 600;
            color: #007bff;
        }

        .caja-info {
            display: flex;
            gap: 1rem;
            align-items: center;
            font-size: 0.9rem;
            color: #6c757d;
        }

        .empty-state {
            text-align: center;
            padding: 3rem 1rem;
        }

        .empty-state i {
            font-size: 4rem;
            color: #dee2e6;
            margin-bottom: 1.5rem;
        }

        .empty-state h4 {
            font-weight: 600;
            color: #6c757d;
            margin-bottom: 1rem;
        }

        .empty-state p {
            color: #adb5bd;
            margin-bottom: 2rem;
            max-width: 500px;
            margin-left: auto;
            margin-right: auto;
        }

        .fecha-selector {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .fecha-actual {
            font-weight: 700;
            font-size: 1.25rem;
            color: #343a40;
            padding: 0.5rem 1rem;
            background: #e9ecef;
            border-radius: 8px;
            display: flex;
            align-items: center;
        }

        .fecha-actual i {
            margin-right: 0.5rem;
            color: #007bff;
        }

        .fecha-navegar {
            display: flex;
            gap: 0.25rem;
        }

        .fecha-navegar .btn {
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 8px;
            background: #e9ecef;
            color: #495057;
            border: none;
            transition: all 0.3s ease;
        }

        .fecha-navegar .btn:hover {
            background: #007bff;
            color: white;
        }

        .etiqueta-hoy {
            font-size: 0.75rem;
            padding: 0.25rem 0.5rem;
            background: #28a745;
            color: white;
            border-radius: 4px;
            margin-left: 0.5rem;
        }

        @media (max-width: 992px) {
            .orden-stats {
                flex-direction: row;
                flex-wrap: wrap;
            }

            .orden-stat {
                flex: 0 0 calc(50% - 0.5rem);
            }
        }

        @media (max-width: 768px) {
            .card-listado {
                padding: 1.5rem;
            }

            .stats-row {
                flex-wrap: wrap;
            }

            .stat-item {
                flex: 0 0 calc(50% - 0.5rem);
                margin-bottom: 1rem;
            }

            .fecha-selector {
                flex-direction: column;
                align-items: flex-start;
            }
        }

        @media (max-width: 576px) {
            .orden-header {
                flex-direction: column;
                align-items: flex-start;
            }

            .orden-header .badge-estado {
                margin-top: 0.5rem;
            }

            .orden-footer {
                flex-direction: column;
                gap: 0.5rem;
            }

            .btn-accion {
                width: 100%;
                text-align: center;
            }

            .stat-item {
                flex: 0 0 100%;
            }
        }

        .expandir-cajas {
            cursor: pointer;
            color: #007bff;
            text-decoration: none;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 0.5rem;
            border-top: 1px solid #e9ecef;
            transition: all 0.3s ease;
        }

        .expandir-cajas:hover {
            background: #f8f9fa;
            color: #0056b3;
        }

        .expandir-cajas i {
            margin-right: 0.5rem;
        }

        .collapse-cajas {
            transition: all 0.3s ease;
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
                        <a class="nav-link" href="index.php">
                            <i class="fas fa-plus me-1"></i>Registrar
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active" href="#">
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
            <div class="card-listado">
                <!-- Título de la página -->
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h1 class="h3 mb-0">
                        <i class="fas fa-clipboard-list me-2 text-primary"></i>Producción Diaria
                    </h1>
                    <a href="index.php" class="btn btn-success btn-lg">
                        <i class="fas fa-plus me-2"></i>Registrar Caja
                    </a>
                </div>

                <!-- Selector de fecha -->
                <div class="filtro-card mb-4">
                    <div class="d-flex justify-content-between align-items-center flex-wrap">
                        <div class="fecha-selector mb-3 mb-md-0">
                            <div class="fecha-actual">
                                <i class="fas fa-calendar-day"></i>
                                <?php echo $fecha_mostrar; ?>
                                <?php if ($es_hoy): ?>
                                    <span class="etiqueta-hoy">HOY</span>
                                <?php endif; ?>
                            </div>
                            <div class="fecha-navegar">
                                <?php
                                $ayer = date('Y-m-d', strtotime($filtro_fecha . ' -1 day'));
                                $manana = date('Y-m-d', strtotime($filtro_fecha . ' +1 day'));
                                $hoy = date('Y-m-d');
                                ?>
                                <a href="?fecha=<?php echo $ayer; ?>" class="btn" title="Día anterior">
                                    <i class="fas fa-chevron-left"></i>
                                </a>
                                <a href="?fecha=<?php echo $hoy; ?>" class="btn" title="Hoy">
                                    <i class="fas fa-calendar-day"></i>
                                </a>
                                <a href="?fecha=<?php echo $manana; ?>" class="btn" title="Día siguiente">
                                    <i class="fas fa-chevron-right"></i>
                                </a>
                            </div>
                        </div>
                        <form method="GET" action="" class="d-flex gap-2 flex-wrap">
                            <input type="date" class="form-control form-control-custom" id="fecha" name="fecha" value="<?php echo htmlspecialchars($filtro_fecha); ?>">
                            <button type="submit" class="btn btn-filtrar">
                                <i class="fas fa-search me-2"></i>Ver Fecha
                            </button>
                        </form>
                    </div>
                </div>

                <!-- Estadísticas generales -->
                <div class="stats-card mb-4">
                    <h6 class="mb-3 text-primary">
                        <i class="fas fa-chart-pie me-2"></i>Resumen de Producción - <?php echo $fecha_mostrar; ?>
                    </h6>
                    <div class="row row-cols-2 row-cols-md-3 row-cols-lg-6 stats-row g-3">
                        <div class="col">
                            <div class="stat-item">
                                <div class="stat-value text-primary"><?php echo number_format($estadisticas['total_ordenes']); ?></div>
                                <p class="stat-label">Órdenes</p>
                            </div>
                        </div>
                        <div class="col">
                            <div class="stat-item">
                                <div class="stat-value text-info"><?php echo number_format($estadisticas['total_cajas']); ?></div>
                                <p class="stat-label">Cajas</p>
                            </div>
                        </div>
                        <div class="col">
                            <div class="stat-item">
                                <div class="stat-value text-success"><?php echo number_format($estadisticas['peso_bruto_total'], 1); ?></div>
                                <p class="stat-label">kg Bruto Total</p>
                            </div>
                        </div>
                        <div class="col">
                            <div class="stat-item">
                                <div class="stat-value text-warning"><?php echo number_format($estadisticas['peso_liquido_total'] ?? 0, 1); ?></div>
                                <p class="stat-label">kg Líquido Total</p>
                            </div>
                        </div>
                        <div class="col">
                            <div class="stat-item">
                                <div class="stat-value text-danger"><?php echo number_format($estadisticas['tipos_distintos']); ?></div>
                                <p class="stat-label">Tipos Producto</p>
                            </div>
                        </div>
                        <div class="col">
                            <div class="stat-item">
                                <div class="stat-value text-primary"><?php echo number_format($estadisticas['peso_promedio'], 2); ?></div>
                                <p class="stat-label">kg Promedio</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Filtros adicionales -->
                <div class="filtro-card">
                    <h6 class="mb-3 text-primary">
                        <i class="fas fa-filter me-2"></i>Filtrar Producción
                    </h6>
                    <form method="GET" action="" class="row g-3">
                        <!-- Mantener la fecha seleccionada -->
                        <input type="hidden" name="fecha" value="<?php echo htmlspecialchars($filtro_fecha); ?>">

                        <div class="col-md-3">
                            <label for="orden" class="form-label form-label-custom">Nº Orden</label>
                            <input type="number" class="form-control form-control-custom" id="orden" name="orden" value="<?php echo htmlspecialchars($filtro_orden); ?>" placeholder="Número de orden...">
                        </div>
                        <div class="col-md-3">
                            <label for="producto" class="form-label form-label-custom">Producto</label>
                            <input type="text" class="form-control form-control-custom" id="producto" name="producto" value="<?php echo htmlspecialchars($filtro_producto); ?>" placeholder="Nombre del producto...">
                        </div>
                        <div class="col-md-3">
                            <label for="tipo" class="form-label form-label-custom">Tipo</label>
                            <select class="form-select form-select-custom" id="tipo" name="tipo">
                                <option value="">Todos los tipos</option>
                                <?php foreach ($tipos_producto as $tipo): ?>
                                    <option value="<?php echo htmlspecialchars($tipo); ?>" <?php echo $filtro_tipo === $tipo ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($tipo); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label for="ordenar_por" class="form-label form-label-custom">Ordenar por</label>
                            <select class="form-select form-select-custom" id="ordenar_por" name="ordenar_por">
                                <option value="hora_desc" <?php echo $ordenar_por === 'hora_desc' ? 'selected' : ''; ?>>Hora (Reciente → Antiguo)</option>
                                <option value="hora_asc" <?php echo $ordenar_por === 'hora_asc' ? 'selected' : ''; ?>>Hora (Antiguo → Reciente)</option>
                                <option value="peso_desc" <?php echo $ordenar_por === 'peso_desc' ? 'selected' : ''; ?>>Peso (Mayor → Menor)</option>
                                <option value="peso_asc" <?php echo $ordenar_por === 'peso_asc' ? 'selected' : ''; ?>>Peso (Menor → Mayor)</option>
                                <option value="cajas_desc" <?php echo $ordenar_por === 'cajas_desc' ? 'selected' : ''; ?>>Cajas (Mayor → Menor)</option>
                                <option value="cajas_asc" <?php echo $ordenar_por === 'cajas_asc' ? 'selected' : ''; ?>>Cajas (Menor → Mayor)</option>
                                <option value="orden_desc" <?php echo $ordenar_por === 'orden_desc' ? 'selected' : ''; ?>>Nº Orden (Mayor → Menor)</option>
                                <option value="orden_asc" <?php echo $ordenar_por === 'orden_asc' ? 'selected' : ''; ?>>Nº Orden (Menor → Mayor)</option>
                            </select>
                        </div>
                        <div class="col-12 d-flex gap-2 mt-4">
                            <button type="submit" class="btn btn-filtrar">
                                <i class="fas fa-search me-2"></i>Aplicar Filtros
                            </button>
                            <a href="?fecha=<?php echo htmlspecialchars($filtro_fecha); ?>" class="btn btn-limpiar">
                                <i class="fas fa-undo me-2"></i>Limpiar Filtros
                            </a>
                        </div>
                    </form>
                </div>

                <!-- Resultados de la búsqueda -->
                <div class="mt-4">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h6 class="text-primary mb-0">
                            <i class="fas fa-box me-2"></i>Producción del Día (<?php echo $total_registros; ?> órdenes con cajas)
                        </h6>
                        <div class="text-muted">
                            Página <?php echo $pagina_actual; ?> de <?php echo max(1, $total_paginas); ?>
                        </div>
                    </div>

                    <?php if (empty($produccion_por_orden)): ?>
                        <!-- Estado vacío -->
                        <div class="empty-state">
                            <i class="fas fa-box-open"></i>
                            <h4>No hay producción registrada</h4>
                            <p>No se encontraron cajas registradas para <?php echo $fecha_mostrar; ?> con los filtros seleccionados.</p>
                            <?php if ($es_hoy): ?>
                                <a href="index.php" class="btn btn-primary btn-lg">
                                    <i class="fas fa-plus me-2"></i>Registrar Nueva Caja
                                </a>
                            <?php else: ?>
                                <a href="?fecha=<?php echo date('Y-m-d'); ?>" class="btn btn-primary btn-lg">
                                    <i class="fas fa-calendar-day me-2"></i>Ver Producción de Hoy
                                </a>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <!-- Listado de órdenes con su producción -->
                        <?php foreach ($produccion_por_orden as $produccion): ?>
                            <?php
                            // Obtener cajas recientes para esta orden
                            $cajas_recientes = obtenerCajasRecientes($conexion, $produccion['id_orden_produccion']);
                            ?>
                            <div class="orden-card">
                                <div class="orden-header">
                                    <div class="orden-id">
                                        Orden #<?php echo $produccion['id_orden_produccion']; ?>
                                    </div>
                                    <?php if (isset($produccion['estado_orden'])): ?>
                                        <span class="badge badge-estado bg-<?php echo $produccion['estado_orden'] === 'Completado' ? 'success' : ($produccion['estado_orden'] === 'En Proceso' ? 'warning' : 'secondary'); ?>">
                                            <?php echo htmlspecialchars($produccion['estado_orden']); ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                                <div class="orden-body">
                                    <?php if (isset($produccion['cliente'])): ?>
                                        <div class="datos-cliente">
                                            <?php echo htmlspecialchars($produccion['cliente'] ?: 'Cliente no especificado'); ?>
                                        </div>
                                    <?php endif; ?>

                                    <div class="orden-meta">
                                        <i class="fas fa-box"></i>
                                        Producto: <?php echo htmlspecialchars($produccion['nombre_producto'] ?: 'No especificado'); ?>
                                    </div>
                                    <div class="orden-meta">
                                        <i class="fas fa-tag"></i>
                                        Tipo: <?php echo htmlspecialchars($produccion['tipo_producto'] ?: 'No especificado'); ?>
                                    </div>
                                    <div class="orden-meta">
                                        <i class="fas fa-clock"></i>
                                        Hora: <?php echo date('H:i', strtotime($produccion['primera_hora'])); ?> - <?php echo date('H:i', strtotime($produccion['ultima_hora'])); ?>
                                    </div>
                                    <?php if (isset($produccion['nombre_vendedor'])): ?>
                                        <div class="orden-meta">
                                            <i class="fas fa-user"></i>
                                            Vendedor: <?php echo htmlspecialchars($produccion['nombre_vendedor'] ?: 'No asignado'); ?>
                                        </div>
                                    <?php endif; ?>

                                    <div class="orden-stats">
                                        <div class="orden-stat">
                                            <div class="orden-stat-value"><?php echo number_format($produccion['total_cajas']); ?></div>
                                            <p class="orden-stat-label">Cajas</p>
                                        </div>
                                        <div class="orden-stat">
                                            <div class="orden-stat-value"><?php echo number_format($produccion['peso_bruto_total'], 1); ?></div>
                                            <p class="orden-stat-label">kg Bruto</p>
                                        </div>
                                        <?php if ($produccion['peso_liquido_total']): ?>
                                            <div class="orden-stat">
                                                <div class="orden-stat-value"><?php echo number_format($produccion['peso_liquido_total'], 1); ?></div>
                                                <p class="orden-stat-label">kg Líquido</p>
                                            </div>
                                        <?php endif; ?>
                                    </div>

                                    <?php if (!empty($cajas_recientes)): ?>
                                        <div class="cajas-recientes">
                                            <div class="cajas-recientes-titulo">
                                                <i class="fas fa-history"></i> Últimas Cajas Registradas
                                            </div>
                                            <div class="collapse show collapse-cajas" id="cajas-<?php echo $produccion['id_orden_produccion']; ?>">
                                                <?php foreach ($cajas_recientes as $caja): ?>
                                                    <div class="caja-item">
                                                        <div class="caja-numero">
                                                            #<?php echo $caja['numero_item']; ?>
                                                        </div>
                                                        <div class="caja-info">
                                                            <span><?php echo number_format($caja['peso_bruto'], 2); ?> kg</span>
                                                            <span><?php echo $caja['hora']; ?></span>
                                                            <span class="badge bg-<?php echo $caja['tipo_producto'] === 'Toallitas' ? 'success' : 'secondary'; ?>">
                                                                <?php echo $caja['tipo_producto']; ?>
                                                            </span>
                                                        </div>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                            <?php if ($produccion['total_cajas'] > count($cajas_recientes)): ?>
                                                <a class="expandir-cajas" data-bs-toggle="collapse" href="#cajas-<?php echo $produccion['id_orden_produccion']; ?>" role="button" aria-expanded="true">
                                                    <i class="fas fa-chevron-down"></i>
                                                    <span class="colapsar-texto">Ocultar cajas</span>
                                                    <span class="expandir-texto" style="display: none;">Ver cajas (<?php echo $produccion['total_cajas']; ?>)</span>
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="orden-footer">
                                    <div>
                                        <small class="text-muted">
                                            <i class="fas fa-calendar-alt me-1"></i><?php echo $fecha_mostrar; ?>
                                        </small>
                                    </div>
                                    <div class="d-flex gap-2">
                                        <a href="index.php?orden=<?php echo $produccion['id_orden_produccion']; ?>" class="btn-accion btn-registrar">
                                            <i class="fas fa-plus me-2"></i>Registrar Caja
                                        </a>
                                        <a href="#" class="btn-accion btn-ver">
                                            <i class="fas fa-print me-2"></i>Imprimir
                                        </a>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>

                        <!-- Paginación -->
                        <?php if ($total_paginas > 1): ?>
                            <nav class="pagination-custom">
                                <ul class="pagination">
                                    <!-- Botón Primera página -->
                                    <li class="page-item <?php echo $pagina_actual <= 1 ? 'disabled' : ''; ?>">
                                        <a class="page-link" href="?<?php echo construirQueryParams(1, $params_actuales); ?>" aria-label="Primera">
                                            <i class="fas fa-angle-double-left"></i>
                                        </a>
                                    </li>
                                    <!-- Botón Anterior -->
                                    <li class="page-item <?php echo $pagina_actual <= 1 ? 'disabled' : ''; ?>">
                                        <a class="page-link" href="?<?php echo construirQueryParams($pagina_actual - 1, $params_actuales); ?>" aria-label="Anterior">
                                            <i class="fas fa-angle-left"></i>
                                        </a>
                                    </li>

                                    <!-- Números de página -->
                                    <?php
                                    $inicio = max(1, $pagina_actual - 2);
                                    $fin = min($total_paginas, $pagina_actual + 2);

                                    for ($i = $inicio; $i <= $fin; $i++):
                                    ?>
                                        <li class="page-item <?php echo $i === $pagina_actual ? 'active' : ''; ?>">
                                            <a class="page-link" href="?<?php echo construirQueryParams($i, $params_actuales); ?>">
                                                <?php echo $i; ?>
                                            </a>
                                        </li>
                                    <?php endfor; ?>

                                    <!-- Botón Siguiente -->
                                    <li class="page-item <?php echo $pagina_actual >= $total_paginas ? 'disabled' : ''; ?>">
                                        <a class="page-link" href="?<?php echo construirQueryParams($pagina_actual + 1, $params_actuales); ?>" aria-label="Siguiente">
                                            <i class="fas fa-angle-right"></i>
                                        </a>
                                    </li>
                                    <!-- Botón Última página -->
                                    <li class="page-item <?php echo $pagina_actual >= $total_paginas ? 'disabled' : ''; ?>">
                                        <a class="page-link" href="?<?php echo construirQueryParams($total_paginas, $params_actuales); ?>" aria-label="Última">
                                            <i class="fas fa-angle-double-right"></i>
                                        </a>
                                    </li>
                                </ul>
                            </nav>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Manejar los botones de expandir/colapsar cajas
            document.querySelectorAll('.expandir-cajas').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    const expandirTexto = this.querySelector('.expandir-texto');
                    const colapsarTexto = this.querySelector('.colapsar-texto');
                    const icono = this.querySelector('i');

                    if (this.getAttribute('aria-expanded') === 'true') {
                        expandirTexto.style.display = 'inline';
                        colapsarTexto.style.display = 'none';
                        icono.classList.remove('fa-chevron-down');
                        icono.classList.add('fa-chevron-right');
                    } else {
                        expandirTexto.style.display = 'none';
                        colapsarTexto.style.display = 'inline';
                        icono.classList.remove('fa-chevron-right');
                        icono.classList.add('fa-chevron-down');
                    }
                });
            });
        });
    </script>

</body>

</html>