<?php
//ini_set('display_errors', 1);
//ini_set('display_startup_errors', 1);
//error_reporting(E_ALL);
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'includes/conexion.php';
require_once 'includes/auth.php';

// Verificar si el usuario ya está autenticado
if (isset($_SESSION['usuario_id'])) {
    header("Location: index.php");
    exit();
}

// Procesar el formulario de inicio de sesión
$error = '';
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $usuario = $_POST['usuario'] ?? '';
    $password = $_POST['password'] ?? '';

    // Buscar en Operarios
    $stmt = ejecutarConsulta(
        "SELECT CodOperario, usuario, clave, Operativo, Inicio, Fin FROM Operarios WHERE usuario = ?",
        [$usuario]
    );

    if ($stmt && $stmt->rowCount() === 1) {
        $user = $stmt->fetch();

        // Verificación 1: Usuario inactivo
        if ($user['Operativo'] != 1) {
            $error = "Usted no es trabajador activo de Batidos Pitaya. (Motivo: Cuenta desactivada)";
        }
        // Verificación 2: Fecha de liquidación en Contratos
        else {
            // Buscar la fecha de liquidación más reciente del operario
            $stmtContrato = ejecutarConsulta(
                "SELECT fecha_liquidacion FROM Contratos 
                 WHERE CodOperario = ? 
                 ORDER BY fecha_liquidacion DESC 
                 LIMIT 1",
                [$user['CodOperario']]
            );

            if ($stmtContrato && $stmtContrato->rowCount() > 0) {
                $contrato = $stmtContrato->fetch();
                $fechaLiquidacion = $contrato['fecha_liquidacion'];

                // Verificar si la fecha de liquidación es mayor a la fecha actual
                if ($fechaLiquidacion !== null && strtotime($fechaLiquidacion) > time()) {
                    $error = "Usted no es trabajador activo de Batidos Pitaya. (Motivo: Contrato liquidado el " . formatoFechaCorta($fechaLiquidacion) . ")";
                } else {
                    // Verificar contraseña
                    if ($password === $user['clave']) {
                        // Iniciar sesión
                        $_SESSION['usuario_id'] = $user['CodOperario'];
                        $_SESSION['usuario_nombre'] = $user['usuario'];

                        header("Location: index.php");
                        exit();
                    } else {
                        $error = "Credenciales incorrectas. Verifique su usuario y contraseña.";
                    }
                }
            } else {
                // Si no hay registros en Contratos, permitir el acceso (o puedes cambiar esto según tu lógica de negocio)
                if ($password === $user['clave']) {
                    // Iniciar sesión
                    $_SESSION['usuario_id'] = $user['CodOperario'];
                    $_SESSION['usuario_nombre'] = $user['usuario'];

                    header("Location: index.php");
                    exit();
                } else {
                    $error = "Credenciales incorrectas. Verifique su usuario y contraseña.";
                }
            }
        }
    } else {
        $error = "Usuario no encontrado. Verifique sus datos.";
    }
}

// Función auxiliar para formato de fecha
//function formatoFechaCorta($fecha) {
//  if (empty($fecha) || $fecha === null) {
//        return '';
//  }
//    
//  $meses = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
//    
//  try {
//        $fechaObj = new DateTime($fecha);
//        $mes = $meses[(int)$fechaObj->format('m') - 1];
//        return $fechaObj->format('d') . '-' . $mes . '-' . $fechaObj->format('y');
//    } catch (Exception $e) {
//        return '';
//    }
//}
?>

<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Iniciar Sesión - Batidos Pitaya</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css">
    <link rel="icon" href="assets/img/icon12.png" type="image/png">
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Calibri', sans-serif;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            margin: 0;
            background-color: #F6F6F6;
            padding: 20px;
        }

        .logo-container {
            text-align: center;
            margin-bottom: 30px;
            width: 100%;
            max-width: 400px;
        }

        .logo {
            max-width: 150px;
            height: auto;
        }

        .login-container {
            background-color: #fff;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            text-align: center;
            width: 100%;
            max-width: 400px;
        }

        .login-container h2 {
            margin-bottom: 20px;
            color: #51B8AC;
        }

        .login-container input {
            width: 100%;
            padding: 12px;
            margin-bottom: 15px;
            border: 1px solid #ddd;
            border-radius: 4px;
            box-sizing: border-box;
            font-size: 16px;
        }

        .login-container button {
            padding: 12px 20px;
            background-color: #51B8AC;
            color: #fff;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 16px;
            width: 100%;
            transition: background-color 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .login-container button:hover {
            background-color: #0E544C;
        }

        .error {
            transition: opacity 0.5s ease;
            color: red;
            margin-bottom: 20px;
            padding: 10px;
            background-color: #ffeeee;
            border-radius: 4px;
        }

        @media (max-width: 480px) {
            .logo {
                max-width: 120px;
            }

            .login-container {
                padding: 20px;
            }

            .login-container h2 {
                font-size: 1.5rem;
            }

            .login-container input {
                padding: 10px;
                font-size: 14px;
            }

            .login-container button {
                padding: 10px 15px;
                font-size: 14px;
            }
        }
    </style>
</head>

<body>
    <div class="logo-container">
        <img src="assets/img/Logo.svg" alt="Batidos Pitaya" class="logo">
    </div>

    <div class="login-container">
        <h2>Iniciar Sesión</h2>
        <?php if ($error): ?>
            <div class="error"><?php echo $error; ?></div>
        <?php endif; ?>
        <form action="login.php" method="POST" autocomplete="off">
            <input type="text" name="usuario" id="usuario" placeholder="Usuario" required autocomplete="off">
            <input type="password" name="password" id="password" placeholder="Contraseña" required autocomplete="current-password">
            <button type="submit">
                <i class="fas fa-sign-in-alt"></i> Acceder
            </button>
        </form>
    </div>
</body>
<script>
    // Esperar a que el DOM esté completamente cargado
    document.addEventListener('DOMContentLoaded', function() {
        // Seleccionar el elemento de error
        const errorElement = document.querySelector('.error');

        // Si hay un mensaje de error, configurar el temporizador
        if (errorElement) {
            setTimeout(function() {
                // Agregar clase para desvanecer
                errorElement.style.transition = 'opacity 0.5s ease';
                errorElement.style.opacity = '0';

                // Eliminar el elemento después de la animación
                setTimeout(function() {
                    errorElement.remove();
                }, 500);
            }, 5000); // 5000 milisegundos = 5 segundos
        }
    });
</script>

</html>