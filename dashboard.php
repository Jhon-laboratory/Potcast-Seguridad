<?php
session_start();
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Verificar si el usuario está logueado
if (!isset($_SESSION['usuario']) || !isset($_SESSION['usuario_id'])) {
    header("Location: login.php");
    exit;
}

// ===== CONFIGURACIÓN SQL SERVER =====
$host = 'Jorgeserver.database.windows.net';
$dbname = 'DPL';
$username = 'Jmmc';
$password = 'ChaosSoldier01';
// ===================================

// Conectar a SQL Server
function connectSQLServer() {
    global $host, $dbname, $username, $password;
    
    try {
        $connectionInfo = array(
            "Database" => $dbname,
            "UID" => $username,
            "PWD" => $password,
            "CharacterSet" => "UTF-8"
        );
        
        $conn = sqlsrv_connect($host, $connectionInfo);
        
        if ($conn === false) {
            $errors = sqlsrv_errors();
            error_log('Error de conexión SQL Server: ' . print_r($errors, true));
            return ['success' => false, 'error' => 'Error SQL Server: ' . $errors[0]['message']];
        }
        
        return ['success' => true, 'conn' => $conn];
    } catch (Exception $e) {
        error_log('Excepción SQL Server: ' . $e->getMessage());
        return ['success' => false, 'error' => 'Excepción SQL Server: ' . $e->getMessage()];
    }
}

// Obtener estadísticas generales del dashboard
function getDashboardStats() {
    $connection = connectSQLServer();
    if (!$connection['success']) {
        return ['success' => false, 'error' => $connection['error']];
    }
    
    $conn = $connection['conn'];
    
    try {
        $stats = [];
        
        // 1. ESTADÍSTICAS GENERALES
        $sql = "SELECT 
                    COUNT(*) as total_contenidos,
                    SUM(Vistas) as total_vistas,
                    SUM(Likes) as total_likes,
                    AVG(CAST(Vistas as float)) as promedio_vistas,
                    AVG(CAST(Likes as float)) as promedio_likes
                FROM [DPL].[externos].[Vistas]";
        
        $stmt = sqlsrv_query($conn, $sql);
        if ($stmt === false) {
            $errors = sqlsrv_errors();
            error_log('Error en consulta general: ' . print_r($errors, true));
            return ['success' => false, 'error' => 'Error en consulta general: ' . $errors[0]['message']];
        }
        
        if ($stmt !== false && $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $stats['general'] = [
                'total_contenidos' => $row['total_contenidos'] ?? 0,
                'total_vistas' => $row['total_vistas'] ?? 0,
                'total_likes' => $row['total_likes'] ?? 0,
                'promedio_vistas' => round($row['promedio_vistas'] ?? 0, 1),
                'promedio_likes' => round($row['promedio_likes'] ?? 0, 1)
            ];
        }
        if ($stmt) {
            sqlsrv_free_stmt($stmt);
        }
        
        // 2. CONTENIDO MÁS POPULAR (TOP 10 POR LIKES)
        $sql = "SELECT TOP 10 
                    id, name as titulo, Vistas, Likes,
                    CASE 
                        WHEN embeded LIKE '%spotify.com/embed%' THEN 'Spotify'
                        WHEN embeded IS NULL THEN 'Video/Audio'
                        ELSE 'Otro'
                    END as tipo_contenido,
                    CONVERT(VARCHAR(10), Ultimavista, 103) as ultima_fecha
                FROM [DPL].[externos].[Vistas] 
                ORDER BY Likes DESC, Vistas DESC";
        
        $stmt = sqlsrv_query($conn, $sql);
        $top_likes = [];
        if ($stmt === false) {
            $errors = sqlsrv_errors();
            error_log('Error en consulta top_likes: ' . print_r($errors, true));
        } else {
            while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                // Calcular porcentaje de engagement
                $engagement = $row['Vistas'] > 0 ? round(($row['Likes'] / $row['Vistas']) * 100, 1) : 0;
                
                $top_likes[] = [
                    'id' => $row['id'],
                    'titulo' => $row['titulo'],
                    'vistas' => $row['Vistas'],
                    'likes' => $row['Likes'],
                    'tipo' => $row['tipo_contenido'],
                    'ultima_fecha' => $row['ultima_fecha'],
                    'engagement' => $engagement
                ];
            }
        }
        if ($stmt) {
            sqlsrv_free_stmt($stmt);
        }
        $stats['top_likes'] = $top_likes;
        
        // 3. CONTENIDO MÁS VISTO (TOP 10 POR VISTAS)
        $sql = "SELECT TOP 10 
                    id, name as titulo, Vistas, Likes,
                    CONVERT(VARCHAR(10), Ultimavista, 103) as ultima_fecha
                FROM [DPL].[externos].[Vistas] 
                WHERE Vistas > 0
                ORDER BY Vistas DESC";
        
        $stmt = sqlsrv_query($conn, $sql);
        $top_vistas = [];
        if ($stmt === false) {
            $errors = sqlsrv_errors();
            error_log('Error en consulta top_vistas: ' . print_r($errors, true));
        } else {
            while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                $top_vistas[] = [
                    'id' => $row['id'],
                    'titulo' => $row['titulo'],
                    'vistas' => $row['Vistas'],
                    'likes' => $row['Likes'],
                    'ultima_fecha' => $row['ultima_fecha'],
                    'ratio_likes' => $row['Vistas'] > 0 ? round(($row['Likes'] / $row['Vistas']) * 100, 1) : 0
                ];
            }
        }
        if ($stmt) {
            sqlsrv_free_stmt($stmt);
        }
        $stats['top_vistas'] = $top_vistas;
        
        // 4. DISTRIBUCIÓN POR TIPO DE CONTENIDO
        $sql = "SELECT 
                    CASE 
                        WHEN embeded LIKE '%spotify.com/embed%' THEN 'Spotify'
                        WHEN embeded IS NULL THEN 'Video/Audio'
                        ELSE 'Otro'
                    END as tipo,
                    COUNT(*) as cantidad,
                    SUM(Vistas) as total_vistas,
                    SUM(Likes) as total_likes,
                    AVG(CAST(Vistas as float)) as avg_vistas,
                    AVG(CAST(Likes as float)) as avg_likes
                FROM [DPL].[externos].[Vistas]
                GROUP BY 
                    CASE 
                        WHEN embeded LIKE '%spotify.com/embed%' THEN 'Spotify'
                        WHEN embeded IS NULL THEN 'Video/Audio'
                        ELSE 'Otro'
                    END";
        
        $stmt = sqlsrv_query($conn, $sql);
        $distribucion = [];
        if ($stmt === false) {
            $errors = sqlsrv_errors();
            error_log('Error en consulta distribucion: ' . print_r($errors, true));
        } else {
            while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                $distribucion[] = [
                    'tipo' => $row['tipo'],
                    'cantidad' => $row['cantidad'],
                    'total_vistas' => $row['total_vistas'],
                    'total_likes' => $row['total_likes'],
                    'avg_vistas' => round($row['avg_vistas'], 1),
                    'avg_likes' => round($row['avg_likes'], 1)
                ];
            }
        }
        if ($stmt) {
            sqlsrv_free_stmt($stmt);
        }
        $stats['distribucion'] = $distribucion;
        
        // 5. ACTIVIDAD RECIENTE (ÚLTIMOS 30 DÍAS)
        $sql = "SELECT 
                    CONVERT(VARCHAR(10), Ultimavista, 103) as fecha,
                    COUNT(*) as nuevos_contenidos,
                    SUM(Vistas) as vistas_dia,
                    SUM(Likes) as likes_dia
                FROM [DPL].[externos].[Vistas]
                WHERE Ultimavista >= DATEADD(day, -30, GETDATE())
                GROUP BY CONVERT(VARCHAR(10), Ultimavista, 103)
                ORDER BY CONVERT(VARCHAR(10), Ultimavista, 103) DESC";
        
        $stmt = sqlsrv_query($conn, $sql);
        $actividad = [];
        if ($stmt === false) {
            $errors = sqlsrv_errors();
            error_log('Error en consulta actividad: ' . print_r($errors, true));
        } else {
            while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                $actividad[] = [
                    'fecha' => $row['fecha'],
                    'nuevos_contenidos' => $row['nuevos_contenidos'],
                    'vistas_dia' => $row['vistas_dia'],
                    'likes_dia' => $row['likes_dia']
                ];
            }
        }
        if ($stmt) {
            sqlsrv_free_stmt($stmt);
        }
        $stats['actividad'] = $actividad;
        
        // 6. ANÁLISIS DE ENGAGEMENT
        $sql = "SELECT 
                    CASE 
                        WHEN Vistas = 0 THEN 'Sin vistas'
                        WHEN Vistas BETWEEN 1 AND 10 THEN '1-10 vistas'
                        WHEN Vistas BETWEEN 11 AND 50 THEN '11-50 vistas'
                        WHEN Vistas BETWEEN 51 AND 100 THEN '51-100 vistas'
                        WHEN Vistas > 100 THEN 'Más de 100 vistas'
                    END as rango_vistas,
                    COUNT(*) as cantidad,
                    AVG(CAST(Likes as float)) as avg_likes,
                    AVG(CASE WHEN Vistas > 0 THEN CAST(Likes as float)/CAST(Vistas as float) * 100 ELSE 0 END) as engagement_promedio
                FROM [DPL].[externos].[Vistas]
                GROUP BY 
                    CASE 
                        WHEN Vistas = 0 THEN 'Sin vistas'
                        WHEN Vistas BETWEEN 1 AND 10 THEN '1-10 vistas'
                        WHEN Vistas BETWEEN 11 AND 50 THEN '11-50 vistas'
                        WHEN Vistas BETWEEN 51 AND 100 THEN '51-100 vistas'
                        WHEN Vistas > 100 THEN 'Más de 100 vistas'
                    END
                ORDER BY 
                    CASE 
                        WHEN Vistas = 0 THEN 1
                        WHEN Vistas BETWEEN 1 AND 10 THEN 2
                        WHEN Vistas BETWEEN 11 AND 50 THEN 3
                        WHEN Vistas BETWEEN 51 AND 100 THEN 4
                        WHEN Vistas > 100 THEN 5
                    END";
        
        $stmt = sqlsrv_query($conn, $sql);
        $engagement = [];
        if ($stmt === false) {
            $errors = sqlsrv_errors();
            error_log('Error en consulta engagement: ' . print_r($errors, true));
        } else {
            while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                $engagement[] = [
                    'rango' => $row['rango_vistas'],
                    'cantidad' => $row['cantidad'],
                    'avg_likes' => round($row['avg_likes'], 1),
                    'engagement' => round($row['engagement_promedio'], 1)
                ];
            }
        }
        if ($stmt) {
            sqlsrv_free_stmt($stmt);
        }
        $stats['engagement'] = $engagement;
        
        sqlsrv_close($conn);
        
        return ['success' => true, 'stats' => $stats];
        
    } catch (Exception $e) {
        error_log('Excepción en getDashboardStats: ' . $e->getMessage());
        return ['success' => false, 'error' => 'Error al obtener estadísticas: ' . $e->getMessage()];
    }
}

$dashboard_stats = getDashboardStats();
if (!$dashboard_stats['success']) {
    error_log('Error del dashboard: ' . $dashboard_stats['error']);
}
$stats = $dashboard_stats['success'] ? $dashboard_stats['stats'] : [];

$sede_usuario = isset($_SESSION['tienda']) ? $_SESSION['tienda'] : '';
$usuario = $_SESSION['usuario'] ?? 'Usuario';
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dashboard Multimedia - RANSA</title>

    <!-- CSS del template -->
    <link href="vendors/bootstrap/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="vendors/font-awesome/css/font-awesome.min.css" rel="stylesheet">
    <link href="vendors/nprogress/nprogress.css" rel="stylesheet">
    <link href="vendors/iCheck/skins/flat/green.css" rel="stylesheet">
    <link href="vendors/select2/dist/css/select2.min.css" rel="stylesheet">
    <link href="vendors/bootstrap-progressbar/css/bootstrap-progressbar-3.3.4.min.css" rel="stylesheet">
    <link href="vendors/datatables.net-bs/css/dataTables.bootstrap.min.css" rel="stylesheet">
    <link href="build/css/custom.min.css" rel="stylesheet">
    
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    
    <!-- CSS ESPECÍFICO PARA DASHBOARD -->
    <style>
        /* Fondo sólido para el body */
        body.nav-md {
            background: #f5f7fa !important;
            min-height: 100vh;
        }
        
        /* Fondo con imagen para el contenido principal */
        .right_col {
            background: linear-gradient(rgba(255, 255, 255, 0.95), rgba(255, 255, 255, 0.98)), 
                        url('img/fondo.png') center/cover no-repeat;
            border-radius: 10px;
            margin: 15px;
            padding: 25px;
            box-shadow: 0 5px 25px rgba(0, 0, 0, 0.1);
            min-height: calc(100vh - 100px);
        }
        
        /* Panel interno transparente */
        .x_panel {
            background: transparent !important;
            border: none !important;
            box-shadow: none !important;
        }
        
        /* HEADER DEL DASHBOARD */
        .dashboard-header {
            background: linear-gradient(135deg, #009A3F, #00c853);
            color: white;
            padding: 25px;
            border-radius: 10px;
            margin-bottom: 25px;
            box-shadow: 0 4px 15px rgba(0, 154, 63, 0.2);
        }
        
        .dashboard-header h1 {
            font-size: 24px;
            font-weight: 600;
            margin-bottom: 10px;
        }
        
        .dashboard-header p {
            font-size: 14px;
            opacity: 0.9;
            margin-bottom: 0;
        }
        
        /* KPI CARDS */
        .kpi-card {
            background: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 3px 10px rgba(0, 0, 0, 0.08);
            border: 1px solid #e8f5e9;
            transition: all 0.3s ease;
            height: 100%;
        }
        
        .kpi-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 15px rgba(0, 154, 63, 0.1);
            border-color: #009A3F;
        }
        
        .kpi-icon {
            width: 50px;
            height: 50px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
            margin-bottom: 15px;
        }
        
        .kpi-icon.vistas {
            background: linear-gradient(135deg, #2196F3, #21CBF3);
            color: white;
        }
        
        .kpi-icon.likes {
            background: linear-gradient(135deg, #FF5722, #FF8A65);
            color: white;
        }
        
        .kpi-icon.contenidos {
            background: linear-gradient(135deg, #4CAF50, #8BC34A);
            color: white;
        }
        
        .kpi-icon.engagement {
            background: linear-gradient(135deg, #9C27B0, #E040FB);
            color: white;
        }
        
        .kpi-value {
            font-size: 28px;
            font-weight: 700;
            color: #333;
            margin-bottom: 5px;
        }
        
        .kpi-label {
            font-size: 13px;
            color: #666;
            font-weight: 500;
        }
        
        .kpi-trend {
            font-size: 12px;
            margin-top: 8px;
        }
        
        .kpi-trend.positive {
            color: #4CAF50;
        }
        
        .kpi-trend.negative {
            color: #F44336;
        }
        
        /* TABLAS DE RANKING */
        .ranking-card {
            background: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 3px 10px rgba(0, 0, 0, 0.08);
            border: 1px solid #e8f5e9;
            height: 100%;
        }
        
        .ranking-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 2px solid #f0f0f0;
        }
        
        .ranking-header h3 {
            font-size: 16px;
            font-weight: 600;
            color: #333;
            margin: 0;
        }
        
        .ranking-table {
            width: 100%;
        }
        
        .ranking-table th {
            background: #f8f9fa;
            color: #666;
            font-weight: 600;
            font-size: 12px;
            padding: 10px;
            text-transform: uppercase;
            border-bottom: 2px solid #e8f5e9;
        }
        
        .ranking-table td {
            padding: 12px 10px;
            border-bottom: 1px solid #f0f0f0;
            font-size: 13px;
        }
        
        .ranking-table tr:last-child td {
            border-bottom: none;
        }
        
        .ranking-table tr:hover td {
            background: #f8f9fa;
        }
        
        .ranking-position {
            width: 30px;
            text-align: center;
        }
        
        .position-1, .position-2, .position-3 {
            font-weight: 700;
        }
        
        .position-1 {
            color: #FFD700; /* Oro */
        }
        
        .position-2 {
            color: #C0C0C0; /* Plata */
        }
        
        .position-3 {
            color: #CD7F32; /* Bronce */
        }
        
        .ranking-title {
            max-width: 250px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        
        .ranking-stats {
            display: flex;
            gap: 15px;
            font-size: 12px;
            color: #666;
        }
        
        .ranking-badge {
            font-size: 11px;
            padding: 2px 8px;
            border-radius: 10px;
            font-weight: 600;
        }
        
        .badge-spotify {
            background: #1DB954;
            color: white;
        }
        
        .badge-video {
            background: #2196F3;
            color: white;
        }
        
        /* GRÁFICOS */
        .chart-card {
            background: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 3px 10px rgba(0, 0, 0, 0.08);
            border: 1px solid #e8f5e9;
            height: 100%;
        }
        
        .chart-header {
            margin-bottom: 20px;
        }
        
        .chart-header h3 {
            font-size: 16px;
            font-weight: 600;
            color: #333;
            margin: 0;
        }
        
        .chart-container {
            position: relative;
            height: 250px;
            width: 100%;
        }
        
        /* MÉTRICAS DE ENGAGEMENT */
        .engagement-metric {
            display: flex;
            align-items: center;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 8px;
            margin-bottom: 10px;
        }
        
        .engagement-icon {
            width: 40px;
            height: 40px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            margin-right: 15px;
            background: #e8f5e9;
            color: #009A3F;
        }
        
        .engagement-info {
            flex: 1;
        }
        
        .engagement-title {
            font-size: 13px;
            font-weight: 600;
            color: #333;
            margin-bottom: 3px;
        }
        
        .engagement-value {
            font-size: 18px;
            font-weight: 700;
            color: #009A3F;
        }
        
        .engagement-subtitle {
            font-size: 11px;
            color: #666;
        }
        
        /* PROGRESS BARS */
        .progress-container {
            margin: 10px 0;
        }
        
        .progress-label {
            display: flex;
            justify-content: space-between;
            margin-bottom: 5px;
            font-size: 12px;
            color: #666;
        }
        
        .progress {
            height: 8px;
            border-radius: 4px;
            background: #f0f0f0;
            overflow: hidden;
        }
        
        .progress-bar {
            border-radius: 4px;
        }
        
        /* INDICADORES */
        .indicator-card {
            background: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 3px 10px rgba(0, 0, 0, 0.08);
            border: 1px solid #e8f5e9;
        }
        
        .indicator-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
            margin-top: 15px;
        }
        
        .indicator-item {
            text-align: center;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 8px;
        }
        
        .indicator-value {
            font-size: 20px;
            font-weight: 700;
            color: #009A3F;
            margin-bottom: 5px;
        }
        
        .indicator-label {
            font-size: 12px;
            color: #666;
        }
        
        /* RESPONSIVE */
        @media (max-width: 768px) {
            .right_col {
                margin: 10px;
                padding: 20px;
            }
            
            .dashboard-header {
                padding: 20px;
            }
            
            .dashboard-header h1 {
                font-size: 20px;
            }
            
            .kpi-card {
                padding: 15px;
            }
            
            .kpi-value {
                font-size: 24px;
            }
            
            .ranking-table {
                font-size: 12px;
            }
            
            .ranking-title {
                max-width: 150px;
            }
        }
        
        @media (max-width: 480px) {
            .indicator-grid {
                grid-template-columns: 1fr;
            }
            
            .ranking-stats {
                flex-direction: column;
                gap: 5px;
            }
        }
        
        /* MENSAJE DE ERROR */
        .error-alert {
            background: #f8d7da;
            border: 1px solid #f5c6cb;
            color: #721c24;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
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
                        <a href="main.php" class="site_title">
                            <img src="img/logo.png" alt="RANSA Logo" style="height: 32px;">
                            <span style="font-size: 12px; margin-left: 4px;">Dashboard</span>
                        </a>
                    </div>
                    <div class="clearfix"></div>

                    <!-- Información del usuario -->
                    <div class="profile clearfix">
                        <div class="profile_info">
                            <span>Bienvenido,</span>
                            <h2><?php echo htmlspecialchars($usuario); ?></h2>
                            <span><?php echo htmlspecialchars($_SESSION['correo'] ?? ''); ?></span>
                        </div>
                    </div>

                    <br />

                    <!-- MENU -->
                    <div id="sidebar-menu" class="main_menu_side hidden-print main_menu">
                        <div class="menu_section">
                            <h3>Navegación</h3>
                            <ul class="nav side-menu">
                                <li>
                                    <a href="main.php"><i class="fa fa-video-camera"></i> Multimedia</a>
                                </li>
                                <li class="active">
                                    <a href="ransa_main.php"><i class="fa fa-dashboard"></i> Dashboard</a>
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
                            <?php echo htmlspecialchars($usuario); ?>
                            <small style="opacity: 0.8; margin-left: 10px;">
                                <i class="fa fa-map-marker"></i> 
                                <?php echo htmlspecialchars($sede_usuario); ?>
                            </small>
                        </span>
                    </div>
                </div>
            </div>

            <!-- CONTENIDO PRINCIPAL - DASHBOARD -->
            <div class="right_col" role="main">
                <div class="page-title"></div>
                
                <div class="clearfix"></div>
                
                <!-- HEADER DEL DASHBOARD -->
                <div class="dashboard-header">
                    <h1><i class="fa fa-dashboard"></i> Dashboard Multimedia</h1>
                    <p>Análisis y métricas del contenido multimedia - <?php echo date('d/m/Y'); ?></p>
                </div>
                
                <!-- MENSAJE DE ERROR SI HAY PROBLEMA -->
                <?php if (!$dashboard_stats['success']): ?>
                    <div class="error-alert">
                        <h4><i class="fa fa-exclamation-triangle"></i> Error al cargar datos</h4>
                        <p>No se pudieron cargar todas las estadísticas. Por favor, intente actualizar la página.</p>
                        <small>Error: <?php echo htmlspecialchars($dashboard_stats['error'] ?? 'Error desconocido'); ?></small>
                    </div>
                <?php endif; ?>
                
                <!-- KPI PRINCIPALES -->
                <div class="row">
                    <div class="col-md-3 col-sm-6">
                        <div class="kpi-card">
                            <div class="kpi-icon contenidos">
                                <i class="fa fa-film"></i>
                            </div>
                            <div class="kpi-value">
                                <?php echo $stats['general']['total_contenidos'] ?? 0; ?>
                            </div>
                            <div class="kpi-label">Total Contenidos</div>
                            <div class="kpi-trend positive">
                                <i class="fa fa-arrow-up"></i> Todos los medios
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-3 col-sm-6">
                        <div class="kpi-card">
                            <div class="kpi-icon vistas">
                                <i class="fa fa-eye"></i>
                            </div>
                            <div class="kpi-value">
                                <?php echo number_format($stats['general']['total_vistas'] ?? 0); ?>
                            </div>
                            <div class="kpi-label">Vistas Totales</div>
                            <div class="kpi-trend positive">
                                <i class="fa fa-chart-line"></i> 
                                <?php echo $stats['general']['promedio_vistas'] ?? 0; ?> por contenido
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-3 col-sm-6">
                        <div class="kpi-card">
                            <div class="kpi-icon likes">
                                <i class="fa fa-thumbs-up"></i>
                            </div>
                            <div class="kpi-value">
                                <?php echo number_format($stats['general']['total_likes'] ?? 0); ?>
                            </div>
                            <div class="kpi-label">Likes Totales</div>
                            <div class="kpi-trend positive">
                                <i class="fa fa-heart"></i> 
                                <?php echo $stats['general']['promedio_likes'] ?? 0; ?> por contenido
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-3 col-sm-6">
                        <div class="kpi-card">
                            <div class="kpi-icon engagement">
                                <i class="fa fa-chart-pie"></i>
                            </div>
                            <div class="kpi-value">
                                <?php 
                                $total_vistas = $stats['general']['total_vistas'] ?? 1;
                                $total_likes = $stats['general']['total_likes'] ?? 0;
                                $engagement_total = $total_vistas > 0 ? round(($total_likes / $total_vistas) * 100, 1) : 0;
                                echo $engagement_total;
                                ?>%
                            </div>
                            <div class="kpi-label">Engagement Rate</div>
                            <div class="kpi-trend positive">
                                <i class="fa fa-percentage"></i> Likes/Vistas
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- PRIMERA FILA: RANKING Y DISTRIBUCIÓN -->
                <div class="row">
                    <!-- TOP 10 POR LIKES -->
                    <div class="col-md-6">
                        <div class="ranking-card">
                            <div class="ranking-header">
                                <h3><i class="fa fa-trophy text-warning"></i> Top 10 Contenidos por Likes</h3>
                                <span class="badge badge-success">Ranking</span>
                            </div>
                            <div class="table-responsive">
                                <table class="ranking-table">
                                    <thead>
                                        <tr>
                                            <th class="ranking-position">#</th>
                                            <th>Contenido</th>
                                            <th>Likes</th>
                                            <th>Vistas</th>
                                            <th>Engagement</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (!empty($stats['top_likes'])): ?>
                                            <?php foreach ($stats['top_likes'] as $index => $item): ?>
                                                <tr>
                                                    <td class="ranking-position <?php echo 'position-' . ($index + 1); ?>">
                                                        <?php echo $index + 1; ?>
                                                    </td>
                                                    <td>
                                                        <div class="ranking-title" title="<?php echo htmlspecialchars($item['titulo']); ?>">
                                                            <?php echo htmlspecialchars($item['titulo']); ?>
                                                        </div>
                                                        <div class="ranking-stats">
                                                            <span class="ranking-badge <?php echo $item['tipo'] === 'Spotify' ? 'badge-spotify' : 'badge-video'; ?>">
                                                                <?php echo $item['tipo']; ?>
                                                            </span>
                                                            <span><i class="fa fa-calendar"></i> <?php echo $item['ultima_fecha']; ?></span>
                                                        </div>
                                                    </td>
                                                    <td>
                                                        <strong class="text-danger"><?php echo $item['likes']; ?></strong>
                                                    </td>
                                                    <td><?php echo $item['vistas']; ?></td>
                                                    <td>
                                                        <div class="progress-container">
                                                            <div class="progress-label">
                                                                <span><?php echo $item['engagement']; ?>%</span>
                                                            </div>
                                                            <div class="progress">
                                                                <div class="progress-bar bg-success" 
                                                                     style="width: <?php echo min($item['engagement'], 100); ?>%"></div>
                                                            </div>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <tr>
                                                <td colspan="5" class="text-center text-muted">
                                                    <i class="fa fa-info-circle"></i> No hay datos disponibles
                                                </td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    
                    <!-- DISTRIBUCIÓN POR TIPO -->
                    <div class="col-md-6">
                        <div class="chart-card">
                            <div class="chart-header">
                                <h3><i class="fa fa-pie-chart"></i> Distribución por Tipo de Contenido</h3>
                            </div>
                            <div class="chart-container">
                                <canvas id="tipoContenidoChart"></canvas>
                            </div>
                            <div class="mt-3">
                                <?php if (!empty($stats['distribucion'])): ?>
                                    <?php foreach ($stats['distribucion'] as $dist): ?>
                                        <div class="engagement-metric">
                                            <div class="engagement-icon">
                                                <?php if ($dist['tipo'] === 'Spotify'): ?>
                                                    <i class="fa fa-spotify"></i>
                                                <?php else: ?>
                                                    <i class="fa fa-video-camera"></i>
                                                <?php endif; ?>
                                            </div>
                                            <div class="engagement-info">
                                                <div class="engagement-title"><?php echo $dist['tipo']; ?></div>
                                                <div class="engagement-value"><?php echo $dist['cantidad']; ?> contenidos</div>
                                                <div class="engagement-subtitle">
                                                    <?php echo $dist['total_vistas']; ?> vistas • 
                                                    <?php echo $dist['total_likes']; ?> likes • 
                                                    Promedio: <?php echo $dist['avg_vistas']; ?> vistas
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div class="text-center text-muted">
                                        <i class="fa fa-info-circle"></i> No hay datos de distribución disponibles
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- SEGUNDA FILA: MÉTRICAS Y ANÁLISIS -->
                <div class="row">
                    <!-- TOP 10 POR VISTAS -->
                    <div class="col-md-6">
                        <div class="ranking-card">
                            <div class="ranking-header">
                                <h3><i class="fa fa-chart-line text-primary"></i> Top 10 Contenidos por Vistas</h3>
                                <span class="badge badge-info">Popularidad</span>
                            </div>
                            <div class="table-responsive">
                                <table class="ranking-table">
                                    <thead>
                                        <tr>
                                            <th class="ranking-position">#</th>
                                            <th>Contenido</th>
                                            <th>Vistas</th>
                                            <th>Likes</th>
                                            <th>Ratio Likes</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (!empty($stats['top_vistas'])): ?>
                                            <?php foreach ($stats['top_vistas'] as $index => $item): ?>
                                                <tr>
                                                    <td class="ranking-position <?php echo 'position-' . ($index + 1); ?>">
                                                        <?php echo $index + 1; ?>
                                                    </td>
                                                    <td>
                                                        <div class="ranking-title" title="<?php echo htmlspecialchars($item['titulo']); ?>">
                                                            <?php echo htmlspecialchars($item['titulo']); ?>
                                                        </div>
                                                        <div class="ranking-stats">
                                                            <span><i class="fa fa-calendar"></i> <?php echo $item['ultima_fecha']; ?></span>
                                                        </div>
                                                    </td>
                                                    <td>
                                                        <strong class="text-primary"><?php echo number_format($item['vistas']); ?></strong>
                                                    </td>
                                                    <td><?php echo $item['likes']; ?></td>
                                                    <td>
                                                        <div class="progress-container">
                                                            <div class="progress-label">
                                                                <span><?php echo $item['ratio_likes']; ?>%</span>
                                                            </div>
                                                            <div class="progress">
                                                                <div class="progress-bar bg-info" 
                                                                     style="width: <?php echo min($item['ratio_likes'], 100); ?>%"></div>
                                                            </div>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <tr>
                                                <td colspan="5" class="text-center text-muted">
                                                    <i class="fa fa-info-circle"></i> No hay datos disponibles
                                                </td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    
                    <!-- ANÁLISIS DE ENGAGEMENT -->
                    <div class="col-md-6">
                        <div class="indicator-card">
                            <div class="chart-header">
                                <h3><i class="fa fa-bar-chart"></i> Análisis de Engagement por Rango de Vistas</h3>
                            </div>
                            <div class="chart-container">
                                <canvas id="engagementChart"></canvas>
                            </div>
                            <div class="mt-3">
                                <h4>Indicadores Clave</h4>
                                <div class="indicator-grid">
                                    <?php 
                                    $alto_engagement = 0;
                                    $medio_engagement = 0;
                                    $bajo_engagement = 0;
                                    
                                    if (!empty($stats['engagement'])) {
                                        foreach ($stats['engagement'] as $eng) {
                                            if ($eng['engagement'] > 20) $alto_engagement += $eng['cantidad'];
                                            elseif ($eng['engagement'] > 5) $medio_engagement += $eng['cantidad'];
                                            else $bajo_engagement += $eng['cantidad'];
                                        }
                                    }
                                    ?>
                                    <div class="indicator-item">
                                        <div class="indicator-value text-success"><?php echo $alto_engagement; ?></div>
                                        <div class="indicator-label">Alto Engagement<br>(>20%)</div>
                                    </div>
                                    <div class="indicator-item">
                                        <div class="indicator-value text-warning"><?php echo $medio_engagement; ?></div>
                                        <div class="indicator-label">Medio Engagement<br>(5-20%)</div>
                                    </div>
                                    <div class="indicator-item">
                                        <div class="indicator-value text-danger"><?php echo $bajo_engagement; ?></div>
                                        <div class="indicator-label">Bajo Engagement<br>(<5%)</div>
                                    </div>
                                    <div class="indicator-item">
                                        <div class="indicator-value text-primary">
                                            <?php 
                                            $total_contenidos = $stats['general']['total_contenidos'] ?? 0;
                                            $con_vistas = 0;
                                            if (!empty($stats['engagement'])) {
                                                foreach ($stats['engagement'] as $eng) {
                                                    if ($eng['rango'] !== 'Sin vistas') {
                                                        $con_vistas += $eng['cantidad'];
                                                    }
                                                }
                                            }
                                            echo $con_vistas;
                                            ?>
                                        </div>
                                        <div class="indicator-label">Con Vistas<br>Registradas</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- TERCERA FILA: ACTIVIDAD RECIENTE -->
                <div class="row">
                    <div class="col-md-12">
                        <div class="chart-card">
                            <div class="chart-header">
                                <h3><i class="fa fa-calendar"></i> Actividad Reciente (Últimos 30 días)</h3>
                            </div>
                            <div class="chart-container">
                                <canvas id="actividadChart"></canvas>
                            </div>
                            <div class="row mt-3">
                                <?php if (!empty($stats['actividad'])): ?>
                                    <?php 
                                    $ultimos_7 = array_slice($stats['actividad'], 0, 7);
                                    $total_vistas_7 = 0;
                                    $total_likes_7 = 0;
                                    foreach ($ultimos_7 as $act) {
                                        $total_vistas_7 += $act['vistas_dia'];
                                        $total_likes_7 += $act['likes_dia'];
                                    }
                                    ?>
                                    <div class="col-md-3 col-sm-6">
                                        <div class="engagement-metric">
                                            <div class="engagement-icon">
                                                <i class="fa fa-fire"></i>
                                            </div>
                                            <div class="engagement-info">
                                                <div class="engagement-title">Últimos 7 días</div>
                                                <div class="engagement-value"><?php echo count($ultimos_7); ?> días activos</div>
                                                <div class="engagement-subtitle">
                                                    <?php echo $total_vistas_7; ?> vistas • 
                                                    <?php echo $total_likes_7; ?> likes
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-3 col-sm-6">
                                        <div class="engagement-metric">
                                            <div class="engagement-icon">
                                                <i class="fa fa-bolt"></i>
                                            </div>
                                            <div class="engagement-info">
                                                <div class="engagement-title">Promedio Diario</div>
                                                <div class="engagement-value">
                                                    <?php 
                                                    $dias_activos = count($stats['actividad']);
                                                    $promedio_vistas = $dias_activos > 0 ? round($total_vistas_7 / $dias_activos) : 0;
                                                    echo $promedio_vistas;
                                                    ?>
                                                </div>
                                                <div class="engagement-subtitle">vistas por día activo</div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-3 col-sm-6">
                                        <div class="engagement-metric">
                                            <div class="engagement-icon">
                                                <i class="fa fa-rocket"></i>
                                            </div>
                                            <div class="engagement-info">
                                                <div class="engagement-title">Día Más Activo</div>
                                                <div class="engagement-value">
                                                    <?php 
                                                    $max_vistas = 0;
                                                    $mejor_dia = '';
                                                    foreach ($stats['actividad'] as $act) {
                                                        if ($act['vistas_dia'] > $max_vistas) {
                                                            $max_vistas = $act['vistas_dia'];
                                                            $mejor_dia = $act['fecha'];
                                                        }
                                                    }
                                                    echo $max_vistas;
                                                    ?>
                                                </div>
                                                <div class="engagement-subtitle">vistas el <?php echo $mejor_dia; ?></div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-3 col-sm-6">
                                        <div class="engagement-metric">
                                            <div class="engagement-icon">
                                                <i class="fa fa-plus-circle"></i>
                                            </div>
                                            <div class="engagement-info">
                                                <div class="engagement-title">Nuevos Contenidos</div>
                                                <div class="engagement-value">
                                                    <?php 
                                                    $nuevos_total = 0;
                                                    foreach ($stats['actividad'] as $act) {
                                                        $nuevos_total += $act['nuevos_contenidos'];
                                                    }
                                                    echo $nuevos_total;
                                                    ?>
                                                </div>
                                                <div class="engagement-subtitle">en los últimos 30 días</div>
                                            </div>
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <div class="col-12 text-center text-muted">
                                        <i class="fa fa-info-circle"></i> No hay datos de actividad reciente disponibles
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- FOOTER -->
            <footer class="footer-dashboard" style="margin-top: 20px; padding: 15px; background: rgba(0, 154, 63, 0.05); border-radius: 8px; font-size: 12px;">
                <div class="pull-right">
                    <i class="fa fa-copyright"></i>
                    Dashboard Multimedia RANSA <?php echo date('Y'); ?> | 
                    <i class="fa fa-database"></i> Última actualización: <?php echo date('d/m/Y H:i'); ?>
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
    $(document).ready(function() {
        // 1. GRÁFICO DE DISTRIBUCIÓN POR TIPO DE CONTENIDO
        <?php if (!empty($stats['distribucion'])): ?>
        const tipoContenidoCtx = document.getElementById('tipoContenidoChart').getContext('2d');
        const tipoContenidoChart = new Chart(tipoContenidoCtx, {
            type: 'doughnut',
            data: {
                labels: [
                    <?php foreach ($stats['distribucion'] as $dist): ?>
                        '<?php echo $dist['tipo']; ?>',
                    <?php endforeach; ?>
                ],
                datasets: [{
                    data: [
                        <?php foreach ($stats['distribucion'] as $dist): ?>
                            <?php echo $dist['cantidad']; ?>,
                        <?php endforeach; ?>
                    ],
                    backgroundColor: [
                        '#1DB954', // Spotify verde
                        '#2196F3', // Video azul
                        '#FF9800'  // Otro naranja
                    ],
                    borderWidth: 1,
                    borderColor: '#fff'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            padding: 20,
                            usePointStyle: true,
                            font: {
                                size: 11
                            }
                        }
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                const label = context.label || '';
                                const value = context.raw || 0;
                                const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                const percentage = Math.round((value / total) * 100);
                                return `${label}: ${value} (${percentage}%)`;
                            }
                        }
                    }
                }
            }
        });
        <?php endif; ?>

        // 2. GRÁFICO DE ENGAGEMENT POR RANGO DE VISTAS
        <?php if (!empty($stats['engagement'])): ?>
        const engagementCtx = document.getElementById('engagementChart').getContext('2d');
        const engagementChart = new Chart(engagementCtx, {
            type: 'bar',
            data: {
                labels: [
                    <?php foreach ($stats['engagement'] as $eng): ?>
                        '<?php echo $eng['rango']; ?>',
                    <?php endforeach; ?>
                ],
                datasets: [
                    {
                        label: 'Cantidad de Contenidos',
                        data: [
                            <?php foreach ($stats['engagement'] as $eng): ?>
                                <?php echo $eng['cantidad']; ?>,
                            <?php endforeach; ?>
                        ],
                        backgroundColor: '#009A3F',
                        borderColor: '#007a32',
                        borderWidth: 1,
                        yAxisID: 'y'
                    },
                    {
                        label: 'Engagement %',
                        data: [
                            <?php foreach ($stats['engagement'] as $eng): ?>
                                <?php echo $eng['engagement']; ?>,
                            <?php endforeach; ?>
                        ],
                        backgroundColor: '#2196F3',
                        borderColor: '#0d8bf2',
                        borderWidth: 1,
                        type: 'line',
                        yAxisID: 'y1'
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: {
                    mode: 'index',
                    intersect: false,
                },
                scales: {
                    x: {
                        grid: {
                            display: false
                        }
                    },
                    y: {
                        type: 'linear',
                        display: true,
                        position: 'left',
                        title: {
                            display: true,
                            text: 'Cantidad'
                        },
                        grid: {
                            drawBorder: false
                        }
                    },
                    y1: {
                        type: 'linear',
                        display: true,
                        position: 'right',
                        title: {
                            display: true,
                            text: 'Engagement %'
                        },
                        grid: {
                            drawOnChartArea: false,
                        },
                        min: 0,
                        max: 100
                    }
                },
                plugins: {
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                let label = context.dataset.label || '';
                                if (label) {
                                    label += ': ';
                                }
                                if (context.datasetIndex === 0) {
                                    label += context.parsed.y + ' contenidos';
                                } else {
                                    label += context.parsed.y + '%';
                                }
                                return label;
                            }
                        }
                    }
                }
            }
        });
        <?php endif; ?>

        // 3. GRÁFICO DE ACTIVIDAD RECIENTE
        <?php if (!empty($stats['actividad'])): ?>
        const actividadCtx = document.getElementById('actividadChart').getContext('2d');
        
        // Tomar solo los últimos 14 días para mejor visualización
        const actividadData = <?php echo json_encode(array_slice($stats['actividad'], 0, 14)); ?>;
        const fechas = actividadData.map(item => item.fecha).reverse();
        const vistas = actividadData.map(item => item.vistas_dia).reverse();
        const likes = actividadData.map(item => item.likes_dia).reverse();
        
        const actividadChart = new Chart(actividadCtx, {
            type: 'line',
            data: {
                labels: fechas,
                datasets: [
                    {
                        label: 'Vistas',
                        data: vistas,
                        borderColor: '#2196F3',
                        backgroundColor: 'rgba(33, 150, 243, 0.1)',
                        borderWidth: 2,
                        fill: true,
                        tension: 0.4
                    },
                    {
                        label: 'Likes',
                        data: likes,
                        borderColor: '#FF5722',
                        backgroundColor: 'rgba(255, 87, 34, 0.1)',
                        borderWidth: 2,
                        fill: true,
                        tension: 0.4
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'top',
                    },
                    tooltip: {
                        mode: 'index',
                        intersect: false,
                        callbacks: {
                            label: function(context) {
                                let label = context.dataset.label || '';
                                if (label) {
                                    label += ': ';
                                }
                                label += context.parsed.y.toLocaleString();
                                return label;
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        grid: {
                            display: false
                        }
                    },
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) {
                                return value.toLocaleString();
                            }
                        },
                        grid: {
                            drawBorder: false
                        }
                    }
                },
                interaction: {
                    intersect: false,
                    mode: 'nearest'
                }
            }
        });
        <?php endif; ?>

        // 4. ACTUALIZACIÓN AUTOMÁTICA DE DATOS (cada 5 minutos)
        function actualizarDashboard() {
            $.ajax({
                url: window.location.href,
                type: 'GET',
                data: { refresh: true },
                success: function(response) {
                    // Buscar y actualizar los KPIs principales
                    const parser = new DOMParser();
                    const doc = parser.parseFromString(response, 'text/html');
                    
                    // Actualizar valores de KPIs
                    const kpis = [
                        { selector: '.kpi-card:nth-child(1) .kpi-value', value: doc.querySelector('.kpi-card:nth-child(1) .kpi-value')?.textContent },
                        { selector: '.kpi-card:nth-child(2) .kpi-value', value: doc.querySelector('.kpi-card:nth-child(2) .kpi-value')?.textContent },
                        { selector: '.kpi-card:nth-child(3) .kpi-value', value: doc.querySelector('.kpi-card:nth-child(3) .kpi-value')?.textContent },
                        { selector: '.kpi-card:nth-child(4) .kpi-value', value: doc.querySelector('.kpi-card:nth-child(4) .kpi-value')?.textContent }
                    ];
                    
                    kpis.forEach(kpi => {
                        const element = document.querySelector(kpi.selector);
                        if (element && kpi.value) {
                            // Animación suave al cambiar valor
                            element.style.transform = 'scale(1.1)';
                            setTimeout(() => {
                                element.textContent = kpi.value;
                                element.style.transform = 'scale(1)';
                            }, 300);
                        }
                    });
                    
                    console.log('Dashboard actualizado:', new Date().toLocaleTimeString());
                },
                error: function() {
                    console.log('Error al actualizar dashboard');
                }
            });
        }

        // Actualizar cada 5 minutos (300000 ms)
        setInterval(actualizarDashboard, 300000);
    });

    // FUNCIONES DEL SISTEMA BASE
    function cerrarSesion() {
        if (confirm('¿Está seguro de que desea cerrar sesión?')) {
            window.location.href = 'logout.php';
        }
    }

    document.addEventListener('keydown', function(event) {
        if (event.key === 'F1') {
            event.preventDefault();
            window.location.href = 'main.php';
        }
        if (event.key === 'F2' && event.ctrlKey) {
            event.preventDefault();
            window.location.href = 'ransa_main.php';
        }
        if (event.key === 'F5') {
            event.preventDefault();
            location.reload();
        }
    });
    </script>
</body>
</html>