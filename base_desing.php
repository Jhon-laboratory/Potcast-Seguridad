<?php
session_start();
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Verificar si el usuario está logueado
if (!isset($_SESSION['usuario']) || !isset($_SESSION['usuario_id'])) {
    header("Location: login.php");
    exit;
}

// Configuración de conexión SQL Server
$host = 'Jorgeserver.database.windows.net';
$dbname = 'DPL';
$username = 'Jmmc';
$password = 'ChaosSoldier01';

// Variables
$sede_usuario = isset($_SESSION['tienda']) ? $_SESSION['tienda'] : '';

// Mostrar mensaje si viene por parámetro
$mensaje = isset($_GET['msg']) ? urldecode($_GET['msg']) : '';
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dashboard - RANSA</title>

    <!-- CSS del template -->
    <link href="vendors/bootstrap/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="vendors/font-awesome/css/font-awesome.min.css" rel="stylesheet">
    <link href="vendors/nprogress/nprogress.css" rel="stylesheet">
    <link href="vendors/iCheck/skins/flat/green.css" rel="stylesheet">
    <link href="vendors/select2/dist/css/select2.min.css" rel="stylesheet">
    <link href="vendors/bootstrap-progressbar/css/bootstrap-progressbar-3.3.4.min.css" rel="stylesheet">
    <link href="vendors/datatables.net-bs/css/dataTables.bootstrap.min.css" rel="stylesheet">
    <link href="build/css/custom.min.css" rel="stylesheet">

    <!-- Estilos específicos para mantener la responsividad -->
    <style>
        /* Fondo para la página */
        body.nav-md {
            background: linear-gradient(rgba(245, 247, 250, 0.97), rgba(245, 247, 250, 0.97)), 
                        url('img/imglogin.jpg') center/cover no-repeat fixed;
            min-height: 100vh;
        }
        
        /* Responsividad del menú lateral */
        @media (max-width: 768px) {
            .left_col {
                display: block !important;
                position: fixed;
                z-index: 1000;
                height: 100%;
                overflow-y: auto;
            }
            
            .right_col {
                margin-left: 0 !important;
                width: 100% !important;
            }
            
            .top_nav .nav_menu {
                position: fixed;
                width: 100%;
                z-index: 999;
            }
            
            .navbar.nav_title {
                padding: 15px;
            }
            
            .profile_info {
                padding: 10px;
            }
            
            .main_menu {
                padding: 10px 0;
            }
            
            .nav.side-menu li a {
                padding: 12px 15px;
            }
        }
        
        @media (max-width: 480px) {
            .nav_menu .nav.navbar-nav {
                margin-right: 0;
            }
            
            .site_title span {
                font-size: 10px;
            }
        }
    </style>
</head>

<body class="nav-md">
    <div class="container body">
        <div class="main_container">
            <!-- SIDEBAR -->
            <div class="col-md-3 left_col">
                <div class="left_col scroll-view">
                    <div class="navbar nav_title" style="border: 0;">
                        <a href="ransa_main.php" class="site_title">
                            <img src="img/logo.png" alt="RANSA Logo" style="height: 32px;">
                            <span style="font-size: 12px; margin-left: 4px;">Dashboard</span>
                        </a>
                    </div>
                    <div class="clearfix"></div>

                    <!-- Información del usuario -->
                    <div class="profile clearfix">
                        <div class="profile_info">
                            <span>Bienvenido,</span>
                            <h2><?php echo htmlspecialchars($_SESSION['usuario'] ?? 'Usuario'); ?></h2>
                            <span><?php echo htmlspecialchars($_SESSION['correo'] ?? ''); ?></span>
                        </div>
                    </div>

                    <br />

                    <!-- MENU -->
                    <div id="sidebar-menu" class="main_menu_side hidden-print main_menu">
                        <div class="menu_section">
                            <h3>Navegación</h3>
                            <ul class="nav side-menu">
                                <li class="active">
                                    <a href="ransa_main.php"><i class="fa fa-dashboard"></i> Dashboard</a>
                                </li>
                                <li>
                                    <a href="ingreso.php"><i class="fa fa-sign-in"></i> Ingreso</a>
                                </li>
                                <li>
                                    <a href="translado.php"><i class="fa fa-exchange"></i> Traslado</a>
                                </li>
                                <li>
                                    <a href="reportes.php"><i class="fa fa-file-text"></i> Reportes</a>
                                </li>
                            </ul>
                        </div>
                    </div>
                    
                    <!-- FOOTER -->
                    <div class="sidebar-footer hidden-small">
                        <a title="Actualizar" data-toggle="tooltip" data-placement="top" onclick="location.reload()">
                            <span class="glyphicon glyphicon-refresh"></span>
                        </a>
                        <a title="Salir" data-toggle="tooltip" data-placement="top" onclick="cerrarSesion()">
                            <span class="glyphicon glyphicon-off"></span>
                        </a>
                    </div>
                </div>
            </div>

            <!-- NAVBAR SUPERIOR -->
            <div class="top_nav">
                <div class="nav_menu">
                    <div class="nav toggle">
                        <a id="menu_toggle"><i class="fa fa-bars"></i></a>
                    </div>
                    <div class="nav navbar-nav navbar-right">
                        <span style="color: white; padding: 15px; font-weight: 600;">
                            <i class="fa fa-user-circle"></i> 
                            <?php echo htmlspecialchars($_SESSION['usuario'] ?? 'Usuario'); ?>
                            <small style="opacity: 0.8; margin-left: 10px;">
                                <i class="fa fa-map-marker"></i> 
                                <?php echo htmlspecialchars($_SESSION['tienda'] ?? 'N/A'); ?>
                            </small>
                        </span>
                    </div>
                </div>
            </div>

            <!-- CONTENIDO PRINCIPAL VACÍO -->
            <div class="right_col" role="main">
                <div class="page-title"></div>
                <div class="clearfix"></div>
                <!-- Contenido principal eliminado intencionalmente -->
            </div>

            <!-- FOOTER -->
            <footer style="margin-top: 20px; padding: 15px; background: rgba(0, 154, 63, 0.05); border-radius: 8px; font-size: 11px; border-top: 1px solid #e0e0e0;">
                <div class="pull-right">
                    <i class="fa fa-clock-o"></i>
                    Sistema Ransa Archivo - Bolivia 
                </div>
                <div class="clearfix"></div>
            </footer>
        </div>
    </div>

    <!-- SCRIPTS -->
    <script src="vendors/jquery/dist/jquery.min.js"></script>
    <script src="vendors/bootstrap/dist/js/bootstrap.bundle.min.js"></script>
    <script src="vendors/fastclick/lib/fastclick.js"></script>
    <script src="vendors/nprogress/nprogress.js"></script>
    <script src="build/js/custom.min.js"></script>

    <script>
        // Función para cerrar sesión
        function cerrarSesion() {
            if (confirm('¿Está seguro de que desea cerrar sesión?')) {
                window.location.href = 'logout.php';
            }
        }

        // Toggle del menú
        document.getElementById('menu_toggle').addEventListener('click', function() {
            const leftCol = document.querySelector('.left_col');
            leftCol.classList.toggle('menu-open');
        });

        // Optimización para dispositivos móviles
        const isMobile = /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent);
        if (isMobile) {
            // Asegurar que los elementos del menú sean táctiles
            document.querySelectorAll('.nav.side-menu li a').forEach(el => {
                el.style.minHeight = '44px';
                el.style.padding = '12px 15px';
            });
            <
            // Mejorar visualización en móviles
            document.body.classList.add('mobile-device');
        }
    </script>
</body>
</html>