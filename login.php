<?php
// Agregar esta línea al inicio para tener acceso a $url_base
include "conexionBD.php";
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sistema de Ventas - Iniciar Sesión</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --primary-color: #2563eb;
            --primary-hover: #1e40af;
            --secondary-color: #f8fafc;
            --accent-color: #0ea5e9;
            --text-color: #1e293b;
            --light-text: #64748b;
            --border-radius: 10px;
            --box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
            --error-color: #ef4444;
            --success-color: #10b981;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #bae6fd 0%, #eff6ff 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            color: var(--text-color);
        }

        .login-container {
            max-width: 480px;
            width: 100%;
            background-color: #fff;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            overflow: hidden;
            position: relative;
        }

        .login-header {
            background-color: var(--primary-color);
            padding: 25px 40px;
            color: white;
            position: relative;
        }

        .login-header::after {
            content: '';
            position: absolute;
            bottom: -15px;
            left: 0;
            right: 0;
            height: 30px;
            background-color: white;
            border-radius: 50% 50% 0 0;
        }

        .login-header h2 {
            font-weight: 700;
            margin-bottom: 5px;
        }

        .login-header p {
            opacity: 0.9;
            margin-bottom: 0;
        }

        .login-form {
            padding: 30px 40px 40px;
        }

        .form-control {
            height: 55px;
            border-radius: var(--border-radius);
            padding-left: 50px;
            padding-right: 15px;
            border: 1px solid #e2e8f0;
            font-size: 0.95rem;
            transition: all 0.3s ease;
            background-color: #f8fafc;
        }

        .form-control:focus {
            box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.15);
            border-color: var(--primary-color);
            background-color: #fff;
        }

        .form-group {
            position: relative;
            margin-bottom: 20px;
        }

        .form-group i {
            position: absolute;
            left: 18px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--light-text);
            font-size: 1.1rem;
        }

        .password-toggle {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--light-text);
            cursor: pointer;
            padding: 5px;
            z-index: 10;
            background: none;
            border: none;
        }

        .password-toggle:hover {
            color: var(--primary-color);
        }

        .btn-primary {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
            height: 55px;
            border-radius: var(--border-radius);
            font-weight: 600;
            font-size: 1rem;
            letter-spacing: 0.3px;
            transition: all 0.3s ease;
            box-shadow: 0 4px 6px rgba(37, 99, 235, 0.12);
        }

        .btn-primary:hover {
            background-color: var(--primary-hover);
            border-color: var(--primary-hover);
            transform: translateY(-2px);
            box-shadow: 0 6px 10px rgba(37, 99, 235, 0.2);
        }

        .btn-primary:active {
            transform: translateY(0);
        }

        .alert {
            border-radius: var(--border-radius);
            padding: 16px;
            margin-bottom: 25px;
            border: none;
            font-size: 0.95rem;
        }

        .alert-danger {
            background-color: #fee2e2;
            color: #b91c1c;
        }

        .alert-success {
            background-color: #d1fae5;
            color: #065f46;
        }

        .alert i {
            margin-right: 10px;
        }

        .remember-forgot {
            display: flex;
            justify-content: space-between;
            margin-bottom: 25px;
            align-items: center;
        }

        .form-check-input:checked {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
        }

        .form-check-label {
            color: var(--light-text);
            font-size: 0.9rem;
            cursor: pointer;
        }

        .forgot-link {
            color: var(--primary-color);
            text-decoration: none;
            font-size: 0.9rem;
            font-weight: 500;
            transition: all 0.2s;
        }

        .forgot-link:hover {
            color: var(--primary-hover);
            text-decoration: underline;
        }

        .brand-logo {
            text-align: center;
            margin-bottom: 10px;
        }

        .brand-logo img {
            max-height: 30px;
        }

        .company-info {
            text-align: center;
            font-size: 0.85rem;
            color: var(--light-text);
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #e2e8f0;
        }

        .company-info p {
            margin-bottom: 5px;
        }

        @media (max-width: 576px) {
            .login-form {
                padding: 25px 20px 30px;
            }

            .login-header {
                padding: 20px;
            }
        }

        /* Animación de carga para el botón */
        .btn-loading {
            position: relative;
            color: transparent !important;
        }

        .btn-loading:after {
            content: "";
            position: absolute;
            width: 20px;
            height: 20px;
            top: 50%;
            left: 50%;
            margin: -10px 0 0 -10px;
            border-radius: 50%;
            border: 2px solid rgba(255, 255, 255, 0.2);
            border-top-color: #fff;
            animation: spin 0.8s linear infinite;
        }

        @keyframes spin {
            to {
                transform: rotate(360deg);
            }
        }

        /* Efecto hover para los campos */
        .form-group:hover .form-control {
            border-color: #cbd5e1;
        }

        /* Efecto de elevación para el contenedor de login */
        .login-container {
            transition: all 0.3s ease;
        }

        .login-container:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 30px rgba(0, 0, 0, 0.15);
        }
    </style>
</head>

<body>
    <div class="login-container">
        <div class="login-header">
            <h2>Bienvenido</h2>
            <p>Inicia sesión para acceder al sistema</p>
        </div>

        <div class="login-form">
            <div class="brand-logo">
                <!-- Reemplaza con tu logo real -->
                <img src="utils/logoa.png" alt="Logo del Sistema">
            </div>

            <?php if (isset($_GET['error'])): ?>
                <div class="alert alert-danger" role="alert">
                    <i class="fas fa-exclamation-circle"></i>
                    <?php
                    switch ($_GET['error']) {
                        case 'empty':
                            echo "Por favor complete todos los campos.";
                            break;
                        case 'invalid':
                            echo "Usuario o contraseña incorrectos.";
                            break;
                        case 'database':
                            echo "Error de conexión a la base de datos. Intente nuevamente.";
                            break;
                        case 'nopermiso':
                            echo "No tienes permiso para acceder a la aplicación de stock.";
                            break;
                        default:
                            echo "Ha ocurrido un error. Intente nuevamente.";
                    }
                    ?>
                </div>
            <?php endif; ?>

            <?php if (isset($_GET['logout']) && $_GET['logout'] == 'success'): ?>
                <div class="alert alert-success" role="alert">
                    <i class="fas fa-check-circle"></i>
                    Has cerrado sesión correctamente.
                </div>
            <?php endif; ?>

            <form id="loginForm" action="<?php echo $url_base; ?>validar_login.php" method="POST">
                <div class="form-group">
                    <i class="fas fa-user"></i>
                    <input type="text" class="form-control" id="usuario" name="usuario" placeholder="Nombre de usuario" required autofocus>
                </div>
                <div class="form-group">
                    <i class="fas fa-lock"></i>
                    <input type="password" class="form-control" id="password" name="password" placeholder="Contraseña" required>
                    <button type="button" class="password-toggle" id="togglePassword">
                        <i class="fas fa-eye"></i>
                    </button>
                </div>

                <div class="remember-forgot">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="remember" name="remember">
                        <label class="form-check-label" for="remember">
                            Recordarme
                        </label>
                    </div>
                    <a href="#" class="forgot-link">¿Olvidaste tu contraseña?</a>
                </div>

                <div class="d-grid gap-2">
                    <button type="submit" class="btn btn-primary" id="loginButton">
                        Iniciar sesión
                    </button>
                </div>
            </form>

            <div class="company-info">
                <p><strong>Sistema de Gestión de Ventas</strong></p>
                <p>&copy; <?php echo date('Y'); ?> Tu Empresa. Todos los derechos reservados.</p>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <!-- Script personalizado -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Funcionalidad para mostrar/ocultar contraseña
            const togglePassword = document.getElementById('togglePassword');
            const passwordInput = document.getElementById('password');

            togglePassword.addEventListener('click', function() {
                const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
                passwordInput.setAttribute('type', type);

                // Cambiar el ícono
                this.querySelector('i').classList.toggle('fa-eye');
                this.querySelector('i').classList.toggle('fa-eye-slash');
            });

            // Efecto de carga al enviar el formulario
            const loginForm = document.getElementById('loginForm');
            const loginButton = document.getElementById('loginButton');

            loginForm.addEventListener('submit', function() {
                loginButton.classList.add('btn-loading');
                loginButton.disabled = true;
            });

            // Eliminar alertas después de un tiempo
            const alerts = document.querySelectorAll('.alert');
            if (alerts.length > 0) {
                setTimeout(function() {
                    alerts.forEach(function(alert) {
                        alert.style.opacity = '0';
                        alert.style.transition = 'opacity 0.5s ease';
                        setTimeout(function() {
                            alert.remove();
                        }, 500);
                    });
                }, 5000);
            }
        });
    </script>
</body>

</html>