<?php
include "verificar_sesion.php";
include "conexionBD.php";
requerirLogin();
requerirPermisoStockApp();
?>

<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sistema de Producción - Gestión de Órdenes</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Estilos personalizados -->
    <link rel="stylesheet" href="<?php echo $url_base; ?>style.css">
    <style>
        .hero-section {
            min-height: 70vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            margin: 0 -15px;
            border-radius: 15px;
        }

        .hero-content {
            text-align: center;
            color: white;
        }

        .btn-main-action {
            background: linear-gradient(45deg, #28a745, #20c997);
            border: none;
            color: white;
            padding: 20px 40px;
            font-size: 1.5rem;
            font-weight: 600;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(40, 167, 69, 0.3);
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-block;
        }

        .btn-main-action:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 40px rgba(40, 167, 69, 0.4);
            color: white;
        }

        .btn-main-action:active {
            transform: translateY(-2px);
        }

        .hero-title {
            font-size: 3rem;
            font-weight: 700;
            margin-bottom: 1rem;
            text-shadow: 2px 2px 4px rgba(0,0,0,0.3);
        }

        .hero-subtitle {
            font-size: 1.2rem;
            margin-bottom: 2rem;
            opacity: 0.9;
        }

        .icon-production {
            font-size: 4rem;
            margin-bottom: 1rem;
            opacity: 0.8;
        }
    </style>
</head>

<body>
    <!-- Barra de navegación -->
    <nav class="navbar navbar-expand-lg navbar-dark">
        <div class="container">
            <a class="navbar-brand" href="<?php echo $url_base; ?>index.php">
                <i class="fas fa-industry me-2"></i>Sistema de Producción
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
                            <i class="fas fa-clipboard-list me-1"></i>Órdenes de Producción
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

    <!-- Contenido principal -->
    <div class="container mt-4">
        <div class="hero-section">
            <div class="hero-content">
                <div class="icon-production">
                    <i class="fas fa-industry"></i>
                </div>
                <h1 class="hero-title">Sistema de Producción</h1>
                <p class="hero-subtitle">Gestiona tus órdenes de producción de manera eficiente</p>
                <a href="<?php echo $url_base; ?>secciones/produccion/ordenproduccion.php" class="btn-main-action">
                    <i class="fas fa-clipboard-list me-3"></i>Orden de Producción
                </a>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS y dependencias -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>