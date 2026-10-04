<?php
//error_reporting(E_ALL);
//ini_set('display_errors', 1);

//ini_set('display_errors', 1);
//ini_set('display_startup_errors', 1);
//error_reporting(E_ALL);

require_once 'includes/conexion.php';
require_once 'includes/auth.php';
require_once 'includes/funciones.php';
require_once 'includes/menu_lateral.php';
require_once 'includes/header_universal.php';

// Verificar autenticación
if (!isset($_SESSION['usuario_id'])) {
    header("Location: login.php");
    exit();
}

$usuario = obtenerUsuarioActual();
$esAdmin = isset($_SESSION['usuario_rol']) && $_SESSION['usuario_rol'] === 'admin';

// Obtener información del usuario
$infoUsuario = obtenerInfoUsuario($_SESSION['usuario_id']);

// Verificar si el usuario está de cumpleaños
$cumpleanosInfo = verificarCumpleanosUsuario($_SESSION['usuario_id']);

/**
 * Obtiene información del usuario para mostrar en el portal
 */
function obtenerInfoUsuario($codOperario)
{
    global $conn;

    $stmt = $conn->prepare("
        SELECT o.CodOperario, o.Nombre, o.Apellido, o.Nombre2, o.Apellido2,
               nc.Nombre as cargo_nombre,
               s.nombre as sucursal_nombre
        FROM Operarios o
        LEFT JOIN AsignacionNivelesCargos anc ON o.CodOperario = anc.CodOperario 
            AND (anc.Fin IS NULL OR anc.Fin >= CURDATE())
        LEFT JOIN NivelesCargos nc ON anc.CodNivelesCargos = nc.CodNivelesCargos
        LEFT JOIN sucursales s ON anc.Sucursal = s.codigo
        WHERE o.CodOperario = ?
        GROUP BY o.CodOperario
        LIMIT 1
    ");
    $stmt->execute([$codOperario]);
    return $stmt->fetch() ?: [];
}
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Portal Colaboradores - Batidos Pitaya</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css">
    <link rel="icon" href="assets/img/icon12.png" type="image/png">
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: 'Calibri', sans-serif;
            font-size: clamp(12px, 2vw, 18px) !important;
        }

        body {
            background-color: #F6F6F6;
            color: #333;
            margin: 0;
            padding: 0;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 10px;
        }

        .modules {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(135px, 135px));
            gap: 20px;
            margin-bottom: 30px;
        }

        .module-card {
            background: white;
            border-radius: 8px;
            padding: 7px;
            width: auto;
            max-width: 135px;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
            transition: transform 0.3s, box-shadow 0.3s;
            text-align: center;
            display: flex;
            flex-direction: column;
            align-items: center !important;
            justify-content: center !important;
            cursor: pointer;
            text-decoration: none;
            color: inherit;
        }

        .module-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }

        .module-icon {
            font-size: 2.5rem;
            color: #51B8AC;
            margin-bottom: 12px;
        }

        .module-title {
            margin: 0;
            font-size: 1.2rem;
            margin-bottom: 10px;
            color: #0E544C;
        }

        .module-desc {
            color: #666;
            font-size: 0.9rem;
        }

        .category-title {
            color: #0E544C;
            font-size: 1.5rem;
            margin: 30px 0 15px 0;
            padding-bottom: 10px;
            border-bottom: 2px solid #51B8AC;
            text-align: center;
        }

        /* Estilos para el indicador de bienvenida/información */
        .info-container {
            max-width: 1200px;
            margin: 0 auto 30px auto;
        }

        .info-card {
            background: linear-gradient(135deg, #51B8AC 0%, #0E544C 100%);
            border-radius: 12px;
            padding: 25px;
            color: white;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
            transition: transform 0.3s ease;
        }

        .info-card:hover {
            transform: translateY(-2px);
        }

        .info-title {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 15px;
            font-size: 1.4rem !important;
        }

        .info-title i {
            font-size: 1.6rem;
        }

        .info-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .info-icon {
            font-size: 4rem !important;
            opacity: 0.8;
            min-width: 100px;
            text-align: center;
        }

        .info-text {
            flex: 1;
            text-align: left;
            padding-left: 20px;
        }

        .info-message {
            margin-bottom: 10px;
            font-size: 1.1rem;
        }

        .info-details {
            opacity: 0.9;
            font-size: 0.9rem;
        }

        .btn-action {
            background: rgba(255, 255, 255, 0.2);
            border: 2px solid white;
            color: white;
            padding: 10px 20px;
            border-radius: 25px;
            cursor: pointer;
            transition: all 0.3s ease;
            font-weight: bold;
            text-decoration: none;
            display: inline-block;
        }

        .btn-action:hover {
            background: white;
            color: #333;
            text-decoration: none;
        }

        /* Estilos para la tarjeta de cumpleaños */
        .cumpleanos-container {
            max-width: 1200px;
            margin: 0 auto 30px auto;
        }

        .cumpleanos-card {
            background: linear-gradient(135deg, #FF6B6B 0%, #FF8E53 50%, #FF6B6B 100%);
            border-radius: 20px;
            padding: 30px;
            color: white;
            box-shadow: 0 10px 30px rgba(255, 107, 107, 0.3);
            position: relative;
            overflow: hidden;
            border: 3px solid rgba(255, 255, 255, 0.2);
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0% {
                transform: scale(1);
            }

            50% {
                transform: scale(1.02);
            }

            100% {
                transform: scale(1);
            }
        }

        .cumpleanos-content {
            display: flex;
            align-items: center;
            gap: 25px;
            position: relative;
            z-index: 2;
        }

        .cumpleanos-icon {
            font-size: 4rem !important;
            animation: bounce 2s infinite;
            min-width: 80px;
            text-align: center;
        }

        @keyframes bounce {

            0%,
            100% {
                transform: translateY(0);
            }

            50% {
                transform: translateY(-10px);
            }
        }

        .cumpleanos-text {
            flex: 1;
        }

        .cumpleanos-title {
            font-size: 2rem !important;
            margin-bottom: 15px;
            text-shadow: 2px 2px 4px rgba(0, 0, 0, 0.3);
        }

        .cumpleanos-message {
            font-size: 1.1rem !important;
            margin-bottom: 15px;
            line-height: 1.6;
            text-shadow: 1px 1px 2px rgba(0, 0, 0, 0.3);
        }

        .cumpleanos-details {
            font-size: 1rem !important;
            opacity: 0.9;
            line-height: 1.5;
        }

        .cumpleanos-confetti {
            position: absolute;
            top: 20px;
            right: 30px;
            font-size: 3rem !important;
            animation: spin 4s linear infinite;
        }

        @keyframes spin {
            0% {
                transform: rotate(0deg);
            }

            100% {
                transform: rotate(360deg);
            }
        }

        /* Responsive */
        @media (max-width: 768px) {
            .modules {
                grid-template-columns: repeat(3, 1fr);
                gap: 10px;
            }

            .module-card {
                padding: 10px 5px;
                max-width: 100%;
                height: 100%;
            }

            .module-icon {
                font-size: 1.8rem !important;
                margin-bottom: 5px;
            }

            .module-title {
                font-size: 0.9rem !important;
                margin-bottom: 5px;
            }

            .info-content {
                flex-direction: column;
                gap: 15px;
                text-align: center;
            }

            .info-text {
                text-align: center;
                padding-left: 0;
            }

            .info-icon {
                font-size: 3rem !important;
            }

            .cumpleanos-content {
                flex-direction: column;
                text-align: center;
                gap: 15px;
            }

            .cumpleanos-title {
                font-size: 1.5rem !important;
            }

            .cumpleanos-message {
                font-size: 1rem !important;
            }

            .cumpleanos-icon {
                font-size: 3rem !important;
            }

            .cumpleanos-confetti {
                position: relative;
                top: auto;
                right: auto;
                margin-top: 10px;
            }
        }
    </style>
</head>

<body>
    <!-- Renderizar menú lateral -->
    <?php echo renderMenuLateral($infoUsuario['cargo_nombre'] ?? 'Colaborador'); ?>

    <div class="main-container">
        <div class="contenedor-principal">
            <?php echo renderHeader($usuario, $esAdmin, ''); ?>

            <!-- Tarjeta de Información/Bienvenida -->
            <div class="info-container" style="display:none;">
                <div class="info-card">
                    <h2 class="info-title">
                        <i class="fas fa-user-check"></i>
                        Portal del Colaborador
                    </h2>
                    <div class="info-content">
                        <div class="info-icon">
                            <i class="fas fa-id-card"></i>
                        </div>
                        <div class="info-text">
                            <div class="info-message">
                                ¡Bienvenido <?= htmlspecialchars($esAdmin ? $usuario['nombre'] : $infoUsuario['Nombre']) ?>!
                            </div>
                            <div class="info-details">
                                <strong>Cargo:</strong> <?= htmlspecialchars($infoUsuario['cargo_nombre'] ?? 'Colaborador') ?><br>
                                <strong>Tienda/Área:</strong> <?= htmlspecialchars($infoUsuario['sucursal_nombre'] ?? 'No asignada') ?><br>
                                <strong style="display:none;">Último acceso:</strong> <? //= date('d/m/Y H:i') 
                                                                                        ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Tarjeta de Feliz Cumpleaños -->
            <?php if ($cumpleanosInfo): ?>
                <div class="cumpleanos-container">
                    <div class="cumpleanos-card">
                        <div class="cumpleanos-content">
                            <div class="cumpleanos-icon">
                                <i class="fas fa-birthday-cake"></i>
                            </div>
                            <div class="cumpleanos-text">
                                <h2 class="cumpleanos-title">
                                    ¡Feliz Cumpleaños <?= htmlspecialchars($cumpleanosInfo['nombre']) ?>! 🎉🎂
                                </h2>
                                <p class="cumpleanos-message">
                                    Hoy celebramos tu día especial 🥳 y queremos agradecerte por ser parte de nuestra familia
                                    en Batidos Pitaya 🍓. <?= $cumpleanosInfo['edad'] ? "¡Felicidades por tus {$cumpleanosInfo['edad']} años! " : "" ?>
                                    Que este nuevo ciclo de vida esté lleno de éxitos, alegrías y momentos inolvidables.
                                </p>
                                <p class="cumpleanos-details">
                                    Te invitamos a pasar por tu batido de cortesía en cualquier sucursal si tienes membresía de Club Pitaya 🥤<br>
                                    <strong>Con nuestros mejores deseos,<br>El equipo de Batidos Pitaya 💜✨</strong>
                                </p>
                            </div>
                            <div class="cumpleanos-confetti">🎊</div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Grupo: Información Personal -->
            <h2 class="category-title" style="display:none;">Mi Información</h2>
            <div class="modules">
                <a href="boleta_pago.php" class="module-card">
                    <div class="module-icon">
                        <i class="fas fa-file-invoice-dollar"></i>
                    </div>
                    <h3 class="module-title">Boleta de Pago</h3>
                </a>

                <a href="historial_marcacion_individual.php" class="module-card">
                    <div class="module-icon">
                        <i class="fas fa-history"></i>
                    </div>
                    <h3 class="module-title">Marcaciones / Deducciones</h3>
                </a>

                <a href="boleta_aguinaldo.php" class="module-card">
                    <div class="module-icon">
                        <i class="fas fa-file-invoice-dollar"></i>
                    </div>
                    <h3 class="module-title">Boleta de Aguinaldo</h3>
                </a>
            </div>
        </div>
    </div>

    <script>
        // Mostrar mensaje de bienvenida
        document.addEventListener('DOMContentLoaded', function() {
            console.log('Portal del Colaborador cargado correctamente');
        });
    </script>
</body>

</html>