<?php
//error_reporting(E_ALL);
//ini_set('display_errors', 1);

require_once 'includes/conexion.php';
require_once 'includes/auth.php';
require_once 'includes/funciones.php';
require_once 'includes/permissions.php';

verificarAutenticacion(); // Redirige automáticamente si no hay sesión activa

if (!isset($_SESSION['usuario_id'])) {
    header("Location: login.php");
    exit();
}

$cod_operario_sesion = $_SESSION['usuario_id'];
$usuario             = obtenerUsuarioActual();
$esAdmin             = isset($_SESSION['usuario_rol']) && $_SESSION['usuario_rol'] === 'admin';
$cargoUsuario        = obtenerCargoPrincipalUsuario($_SESSION['usuario_id']);

// --- Permiso cambiar_fecha ---
$codCargo         = $usuario['CodNivelesCargos'] ?? null;
$puedeChangeFecha = tienePermiso('boletas_planilla', 'cambiar_fecha', $codCargo);

// --- Parámetros GET (solo si tiene permiso) ---
$filtro_operario = null; // CodOperario a buscar
$filtro_fecha    = null; // fecha_planilla a filtrar (YYYY-MM-DD)
$nombre_buscado  = '';   // texto que digitó en el buscador

if ($puedeChangeFecha) {
    if (!empty($_GET['operario']) && is_numeric($_GET['operario'])) {
        $filtro_operario = (int) $_GET['operario'];
    }
    if (!empty($_GET['fecha'])) {
        // Validar formato YYYY-MM-DD
        $d = DateTime::createFromFormat('Y-m-d', $_GET['fecha']);
        if ($d && $d->format('Y-m-d') === $_GET['fecha']) {
            $filtro_fecha = $_GET['fecha'];
        }
    }
    $nombre_buscado = trim($_GET['nombre_buscado'] ?? '');

    // Fallback: Si el usuario escribió un nombre pero por algún motivo no se seleccionó el ID oculto
    if (empty($filtro_operario) && !empty($nombre_buscado)) {
        // Intentar coincidencia exacta primero
        $stmt_match = ejecutarConsulta(
            "SELECT o.CodOperario 
             FROM Operarios o
             WHERE CONCAT_WS(' ',
                 NULLIF(TRIM(o.Nombre),   ''),
                 NULLIF(TRIM(o.Nombre2),  ''),
                 NULLIF(TRIM(o.Apellido), ''),
                 NULLIF(TRIM(o.Apellido2),'')
             ) = ? LIMIT 1",
            [$nombre_buscado]
        );
        if ($stmt_match && $stmt_match->rowCount() > 0) {
            $filtro_operario = (int)$stmt_match->fetch()['CodOperario'];
        } else {
            // Intentar coincidencia parcial
            $stmt_match_like = ejecutarConsulta(
                "SELECT o.CodOperario 
                 FROM Operarios o
                 WHERE CONCAT_WS(' ',
                     NULLIF(TRIM(o.Nombre),   ''),
                     NULLIF(TRIM(o.Nombre2),  ''),
                     NULLIF(TRIM(o.Apellido), ''),
                     NULLIF(TRIM(o.Apellido2),'')
                 ) LIKE ? LIMIT 1",
                ["%" . $nombre_buscado . "%"]
            );
            if ($stmt_match_like && $stmt_match_like->rowCount() > 0) {
                $filtro_operario = (int)$stmt_match_like->fetch()['CodOperario'];
            }
        }
    }
}

// --- Determinar qué operario se consulta ---
$cod_operario_consulta = $filtro_operario ?? $cod_operario_sesion;

// --- Cargar lista de colaboradores para el buscador (solo con permiso) ---
// NOTA: Se incluyen colaboradores activos e inactivos (de baja) y se limpian espacios.
$lista_colaboradores = [];
$error_lista_colaboradores = null;
if ($puedeChangeFecha) {
    try {
        $stmt_col = ejecutarConsulta(
            "SELECT o.CodOperario,
                    CONCAT_WS(' ',
                        NULLIF(TRIM(o.Nombre),   ''),
                        NULLIF(TRIM(o.Nombre2),  ''),
                        NULLIF(TRIM(o.Apellido), ''),
                        NULLIF(TRIM(o.Apellido2),'')
                    ) AS nombre_completo
             FROM Operarios o
             WHERE o.Nombre IS NOT NULL AND o.Nombre != ''
             ORDER BY o.Nombre, o.Apellido"
        );
        if ($stmt_col) {
            $lista_colaboradores = $stmt_col->fetchAll();
        } else {
            global $conn;
            $error_lista_colaboradores = "ejecutarConsulta devolvió false. errorInfo: " . json_encode($conn->errorInfo());
        }
    } catch (Exception $e) {
        $error_lista_colaboradores = "Excepción: " . $e->getMessage();
    }
}

// --- Cargar fechas disponibles para el colaborador seleccionado (solo con permiso) ---
// NOTA: BoletaPago.cod_operario almacena CodContrato (viene del Excel contable),
//       NO CodOperario. Por eso buscamos SOLO por los CodContratos del operario.
$fechas_disponibles = [];
if ($puedeChangeFecha) {
    $stmt_contratos_fechas = ejecutarConsulta(
        "SELECT CodContrato FROM Contratos WHERE cod_operario = ?",
        [$cod_operario_consulta]
    );
    $lista_contratos_fechas = $stmt_contratos_fechas ? $stmt_contratos_fechas->fetchAll(PDO::FETCH_COLUMN) : [];

    if (!empty($lista_contratos_fechas)) {
        $ph_fechas = implode(',', array_fill(0, count($lista_contratos_fechas), '?'));
        $stmt_fechas = ejecutarConsulta(
            "SELECT DISTINCT fecha_planilla
             FROM BoletaPago
             WHERE cod_operario IN ($ph_fechas)
             ORDER BY fecha_planilla DESC",
            $lista_contratos_fechas
        );
        if ($stmt_fechas) {
            $fechas_disponibles = $stmt_fechas->fetchAll();
        }
    }
}

// --- Obtener la boleta ---
// NOTA: BoletaPago.cod_operario almacena CodContrato (viene del Excel contable),
//       NO CodOperario. Buscamos SOLO por los CodContratos del operario.
$boleta = null;

// Obtener CodContratos del operario (también usados para el DEBUG)
$_debug_contratos = [];
$_stmt_debug = ejecutarConsulta(
    "SELECT CodContrato FROM Contratos WHERE cod_operario = ?",
    [$cod_operario_consulta]
);
if ($_stmt_debug) {
    $_debug_contratos = $_stmt_debug->fetchAll(PDO::FETCH_COLUMN);
}

if (!empty($_debug_contratos)) {
    $ph_boleta = implode(',', array_fill(0, count($_debug_contratos), '?'));

    if ($filtro_fecha && $puedeChangeFecha) {
        // Boleta exacta por fecha
        $params_b = array_merge($_debug_contratos, [$filtro_fecha]);
        $stmt = ejecutarConsulta(
            "SELECT * FROM BoletaPago
             WHERE cod_operario IN ($ph_boleta)
               AND fecha_planilla = ?
             ORDER BY id_boleta DESC LIMIT 1",
            $params_b
        );
    } else {
        // Boleta más reciente
        $stmt = ejecutarConsulta(
            "SELECT * FROM BoletaPago
             WHERE cod_operario IN ($ph_boleta)
             ORDER BY fecha_planilla DESC, id_boleta DESC LIMIT 1",
            $_debug_contratos
        );
    }

    if ($stmt && $stmt->rowCount() > 0) {
        $boleta = $stmt->fetch();
    }
}

// Función para formatear valores (mostrar - si es null o 0)
function formatearValor($valor, $esMonto = false)
{
    if ($valor === null || $valor === '' || $valor == 0) {
        return '-';
    }
    if ($esMonto) {
        return number_format($valor, 2, '.', ',');
    }
    return $valor;
}

// Calcular totales
$total_ingresos    = 0;
$total_deducciones = 0;
$total_quincena    = 0;

if ($boleta) {
    $total_ingresos = ($boleta['salario_basico_quincenal_monto'] ?? 0) +
        ($boleta['feriados_laborados_monto'] ?? 0) +
        ($boleta['horas_extras_monto'] ?? 0);

    $total_deducciones = abs($boleta['faltas_septimo_dia_monto'] ?? 0) +
        abs($boleta['inss_empleado_monto'] ?? 0) +
        abs($boleta['Deducciones'] ?? 0);

    $total_quincena = $total_ingresos - $total_deducciones;
}
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Boleta de Pago - Batidos Pitaya</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css">
    <link rel="icon" href="assets/img/icon12.png" type="image/png">
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: 'Calibri', sans-serif;
        }

        body {
            background-color: #F6F6F6;
            color: #333;
        }

        .container {
            max-width: 800px;
            margin: 0 auto;
            background: white;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }

        .header-container {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 0;
            border-bottom: 1px solid #ddd;
            margin-bottom: 30px;
            flex-wrap: wrap;
            gap: 15px;
        }

        .logo {
            height: 50px;
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .user-avatar {
            width: 35px;
            height: 35px;
            border-radius: 50%;
            background-color: #51B8AC;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
        }

        .btn-logout {
            background: #51B8AC;
            color: white;
            border: none;
            padding: 8px 15px;
            border-radius: 4px;
            cursor: pointer;
            transition: background 0.3s;
            text-decoration: none;
            display: inline-block;
        }

        .btn-logout:hover {
            background: #0E544C;
        }

        /* Panel de filtros histórico */
        .panel-historico {
            background: #f0faf9;
            border: 1px solid #51B8AC;
            border-left: 4px solid #0E544C;
            border-radius: 6px;
            padding: 14px 18px;
            margin-bottom: 25px;
        }

        .panel-historico h3 {
            color: #0E544C;
            font-size: 14px;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .filtros-form {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            align-items: flex-end;
        }

        .filtro-grupo {
            display: flex;
            flex-direction: column;
            gap: 4px;
            flex: 1;
            min-width: 180px;
            position: relative;
        }

        .autocomplete-suggestions {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: white;
            border: 1px solid #ccc;
            border-top: none;
            border-radius: 0 0 4px 4px;
            max-height: 200px;
            overflow-y: auto;
            z-index: 1000;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }

        .suggestion-item {
            padding: 8px 12px;
            cursor: pointer;
            font-size: 13px;
            color: #333;
            text-align: left;
            transition: background 0.15s;
        }

        .suggestion-item:hover,
        .suggestion-item.active {
            background: #e8f5e9;
            color: #0E544C;
        }

        .filtro-grupo label {
            font-size: 12px;
            color: #555;
            font-weight: bold;
        }

        .filtro-grupo input,
        .filtro-grupo select {
            padding: 7px 10px;
            border: 1px solid #ccc;
            border-radius: 4px;
            font-size: 13px;
            width: 100%;
        }

        .filtro-grupo input:focus,
        .filtro-grupo select:focus {
            outline: none;
            border-color: #51B8AC;
        }

        .btn-filtrar {
            background: #0E544C;
            color: white;
            border: none;
            padding: 8px 18px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 13px;
            transition: background 0.2s;
            white-space: nowrap;
        }

        .btn-filtrar:hover {
            background: #0a3d37;
        }

        .btn-limpiar {
            background: #6c757d;
            color: white;
            border: none;
            padding: 8px 14px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 13px;
            transition: background 0.2s;
            text-decoration: none;
            display: inline-block;
            white-space: nowrap;
        }

        .btn-limpiar:hover {
            background: #545b62;
        }

        .badge-historico {
            display: inline-block;
            background: #51B8AC;
            color: white;
            font-size: 11px;
            padding: 2px 8px;
            border-radius: 10px;
            margin-bottom: 8px;
        }

        /* Boleta styles */
        .boleta-header {
            text-align: center;
            margin-bottom: 30px;
        }

        .boleta-header h1 {
            color: #0E544C;
            font-size: 24px;
            margin-bottom: 10px;
        }

        .empleado-info {
            font-size: 18px;
            margin-bottom: 5px;
        }

        .fecha-info {
            color: #666;
            margin-bottom: 20px;
        }

        .seccion {
            margin-bottom: 25px;
        }

        .seccion h2 {
            color: #0E544C;
            border-bottom: 2px solid #51B8AC;
            padding-bottom: 5px;
            margin-bottom: 15px;
            font-size: 18px;
        }

        .tabla-boleta {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 10px;
        }

        .tabla-boleta th {
            background-color: #0E544C;
            color: white;
            padding: 10px;
            text-align: left;
            font-weight: normal;
        }

        .tabla-boleta td {
            padding: 10px;
            border-bottom: 1px solid #ddd;
        }

        .tabla-boleta tr:nth-child(even) {
            background-color: #f9f9f9;
        }

        .total-row {
            font-weight: bold;
            background-color: #e8f5e9 !important;
        }

        .total-quincena {
            font-size: 20px;
            color: #0E544C;
            text-align: center;
            padding: 15px;
            background-color: #f1f8e9;
            border-radius: 4px;
            margin: 20px 0;
        }

        .info-adicional {
            display: flex;
            justify-content: space-between;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #ddd;
        }

        .firma {
            text-align: center;
            width: 45%;
        }

        .firma p {
            margin-top: 50px;
            border-top: 1px solid #333;
            padding-top: 5px;
        }

        .sin-boleta {
            text-align: center;
            padding: 50px 20px;
            color: #666;
        }

        .sin-boleta i {
            font-size: 48px;
            margin-bottom: 20px;
            color: #ccc;
        }

        @media print {

            .btn-logout,
            .panel-historico {
                display: none;
            }

            .header-container {
                border-bottom: none;
                margin-bottom: 10px;
            }

            body {
                background-color: white;
            }

            .container {
                box-shadow: none;
                padding: 10px;
            }
        }

        @media (max-width: 768px) {
            .container {
                padding: 10px;
            }

            .header-container {
                flex-direction: column;
                text-align: center;
            }

            .info-adicional {
                flex-direction: column;
                gap: 30px;
            }

            .firma {
                width: 100%;
            }

            .filtros-form {
                flex-direction: column;
            }
        }
    </style>
</head>

<body>
    <div class="container">
        <header>
            <div class="header-container">
                <div class="logo-container">
                    <img src="assets/img/Logo.svg" alt="Batidos Pitaya" class="logo">
                </div>
                <div class="user-info">
                    <div class="user-avatar">
                        <?= $esAdmin ?
                            strtoupper(substr($usuario['nombre'], 0, 1)) :
                            strtoupper(substr($usuario['Nombre'], 0, 1)) ?>
                    </div>
                    <div>
                        <div>
                            <?= $esAdmin ?
                                htmlspecialchars($usuario['nombre']) :
                                htmlspecialchars($usuario['Nombre'] . ' ' . $usuario['Apellido']) ?>
                        </div>
                        <small><?= htmlspecialchars($cargoUsuario) ?></small>
                    </div>
                    <a href="index.php" class="btn-logout">
                        <i class="fas fa-sign-out-alt"></i>
                    </a>
                </div>
            </div>
        </header>

        <?php if ($puedeChangeFecha): ?>
            <!-- Panel de búsqueda histórica -->
            <div class="panel-historico">
                <h3><i class="fas fa-history"></i> Buscar boleta histórica</h3>
                <form class="filtros-form" method="GET" action="boleta_pago.php">
                    <div class="filtro-grupo">
                        <label for="input-colaborador">Colaborador</label>
                        <input
                            type="text"
                            id="input-colaborador"
                            name="nombre_buscado"
                            placeholder="Buscar por nombre..."
                            value="<?= htmlspecialchars($nombre_buscado) ?>"
                            autocomplete="off">
                        <!-- Campo oculto con CodOperario seleccionado -->
                        <input type="hidden" name="operario" id="input-operario-id" value="<?= $filtro_operario ?? '' ?>">
                    </div>
                    <div class="filtro-grupo">
                        <label for="select-fecha">Fecha de planilla</label>
                        <select id="select-fecha" name="fecha">
                            <option value="">— Más reciente —</option>
                            <?php foreach ($fechas_disponibles as $f): ?>
                                <option value="<?= $f['fecha_planilla'] ?>"
                                    <?= ($filtro_fecha === $f['fecha_planilla']) ? 'selected' : '' ?>>
                                    <?= date('d/m/Y', strtotime($f['fecha_planilla'])) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" class="btn-filtrar">
                        <i class="fas fa-search"></i> Ver
                    </button>
                    <?php if ($filtro_operario || $filtro_fecha): ?>
                        <a href="boleta_pago.php" class="btn-limpiar">
                            <i class="fas fa-times"></i> Limpiar
                        </a>
                    <?php endif; ?>
                </form>
                <?php if ($filtro_operario || $filtro_fecha): ?>
                    <div style="margin-top:8px;">
                        <span class="badge-historico">
                            <i class="fas fa-filter"></i> Filtro activo
                            <?= $filtro_fecha ? ' — ' . date('d/m/Y', strtotime($filtro_fecha)) : '' ?>
                            <?php if ($filtro_operario && $boleta): ?>
                                — <?= htmlspecialchars($boleta['empleado_nombre']) ?>
                            <?php endif; ?>
                        </span>
                        <!-- INFO PANEL (solo para usuarios con permiso de filtrar) -->
                        <div style="margin-top:6px; padding:7px 12px; background:#f0f4f8; border:1px solid #ccd6e0; border-left:3px solid #51B8AC; border-radius:4px; font-size:11px; color:#555; display:flex; flex-wrap:wrap; gap:12px;">
                            <span title="Código de operario real en tabla Operarios">
                                <i class="fas fa-id-badge" style="color:#51B8AC;"></i>
                                Cód. operario: <strong><?= $cod_operario_consulta ?></strong>
                            </span>
                            <span title="Código de contrato usado para buscar en BoletaPago">
                                <i class="fas fa-file-contract" style="color:#51B8AC;"></i>
                                Contrato: <strong><?= !empty($_debug_contratos) ? implode(', ', $_debug_contratos) : '—' ?></strong>
                            </span>
                            <?php if ($boleta): ?>
                                <span title="ID interno de la boleta encontrada">
                                    <i class="fas fa-receipt" style="color:#51B8AC;"></i>
                                    ID boleta: <strong><?= $boleta['id_boleta'] ?></strong>
                                </span>
                                <span title="Nombre guardado en la boleta">
                                    <i class="fas fa-user" style="color:#51B8AC;"></i>
                                    Nombre en boleta: <strong><?= htmlspecialchars($boleta['empleado_nombre']) ?></strong>
                                </span>
                            <?php else: ?>
                                <span style="color:#c0392b;">
                                    <i class="fas fa-exclamation-triangle"></i>
                                    No se encontró boleta para este colaborador
                                </span>
                            <?php endif; ?>
                            <span title="Fechas de planilla disponibles para este colaborador">
                                <i class="fas fa-calendar-alt" style="color:#51B8AC;"></i>
                                Fechas disponibles: <strong><?= count($fechas_disponibles) ?></strong>
                            </span>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ($boleta): ?>
            <div class="boleta-header">
                <h1>BOLETA DE PAGO</h1>
                <div class="empleado-info"><?= htmlspecialchars($boleta['empleado_nombre']) ?></div>
                <?php
                    $meses_es = ['Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'];
                    $ts_planilla = strtotime($boleta['fecha_planilla']);
                    $fecha_planilla_es = date('d', $ts_planilla) . '-' . $meses_es[(int)date('n', $ts_planilla) - 1] . '-' . date('y', $ts_planilla);
                ?>
                <div class="fecha-info">Fecha de Planilla: <?= $fecha_planilla_es ?></div>
                <div class="fecha-info">Salario Básico: <?= $boleta['salario_basico'] ?></div>
            </div>

            <!-- Ingresos -->
            <div class="seccion">
                <h2>Ingresos</h2>
                <table class="tabla-boleta">
                    <tr style="display:none;">
                        <th>Concepto</th>
                        <th>Cantidad</th>
                        <th>Monto</th>
                    </tr>
                    <tr>
                        <td>Salario Básico Quincenal</td>
                        <td style="text-align:right;"><?= formatearValor($boleta['salario_basico_quincenal_dias']) ?></td>
                        <td>días</td>
                        <td style="text-align:right;"><?= formatearValor($boleta['salario_basico_quincenal_monto'], true) ?></td>
                        <td>C$</td>
                    </tr>
                    <tr>
                        <td>Feriados Laborados</td>
                        <td style="text-align:right;"><?= formatearValor($boleta['feriados_laborados_horas']) ?></td>
                        <td>hrs</td>
                        <td style="text-align:right;"><?= formatearValor($boleta['feriados_laborados_monto'], true) ?></td>
                        <td>C$</td>
                    </tr>
                    <tr>
                        <td>Horas Extras</td>
                        <td style="text-align:right;"><?= formatearValor($boleta['horas_extras_horas']) ?></td>
                        <td>horas</td>
                        <td style="text-align:right;"><?= formatearValor($boleta['horas_extras_monto'], true) ?></td>
                        <td>C$</td>
                    </tr>
                    <tr class="total-row">
                        <td colspan="3"><strong>Total Ingresos:</strong></td>
                        <td style="text-align:right;"><strong><?= number_format($total_ingresos, 2, '.', ',') ?></strong></td>
                        <td><strong>C$</strong></td>
                    </tr>
                </table>
            </div>

            <!-- Deducciones -->
            <div class="seccion">
                <h2>Deducciones</h2>
                <table class="tabla-boleta">
                    <tr style="display:none;">
                        <th>Concepto</th>
                        <th>Cantidad</th>
                        <th>Monto</th>
                    </tr>
                    <tr>
                        <td>Faltas + Séptimo día</td>
                        <td style="text-align:right;"><?= formatearValor($boleta['faltas_septimo_dia_dias']) ?></td>
                        <td>días</td>
                        <td style="text-align:right;">-<?= formatearValor(abs($boleta['faltas_septimo_dia_monto']), true) ?></td>
                        <td>C$</td>
                    </tr>
                    <tr>
                        <td colspan="3">INSS Empleado (<?= formatearValor($boleta['inss_empleado_porcentaje']) ?> %)</td>
                        <td style="text-align:right;">-<?= formatearValor(abs($boleta['inss_empleado_monto']), true) ?></td>
                        <td>C$</td>
                    </tr>
                    <tr>
                        <td colspan="3"><?= htmlspecialchars($boleta['nombre_deduccion_adicional'] ?? 'Deducciones y Pérdidas') ?></td>
                        <td style="text-align:right;">-<?= formatearValor(abs($boleta['Deducciones'] ?? 0), true) ?></td>
                        <td>C$</td>
                    </tr>
                    <tr class="total-row">
                        <td colspan="3"><strong>Total Deducciones:</strong></td>
                        <td style="text-align:right;"><strong>-<?= number_format(abs($total_deducciones), 2, '.', ',') ?></strong></td>
                        <td><strong>C$</strong></td>
                    </tr>
                </table>
            </div>

            <!-- Total Quincena -->
            <div class="total-quincena">
                <strong>Total Quincena: <?= number_format($total_quincena, 2, '.', ',') ?> C$</strong>
            </div>

            <!-- Información Adicional -->
            <div class="seccion">
                <table class="tabla-boleta">
                    <tr>
                        <td>Vacaciones tomadas del mes:</td>
                        <td style="text-align:right;"><?= formatearValor($boleta['vacaciones_dias']) ?></td>
                        <td>días</td>
                    </tr>
                </table>
            </div>

            <!-- Firmas -->
            <div class="info-adicional">
                <div class="firma">
                    <p style="margin-top:75px;">_________________________</p>
                    <div><strong>Entrega:</strong></div>
                    <div>Batidos Pitaya</div>
                </div>
                <div class="firma">
                    <p style="margin-top:75px;">_________________________</p>
                    <div><strong>Recibe conforme:</strong></div>
                    <div><?= htmlspecialchars($boleta['empleado_nombre']) ?></div>
                </div>
            </div>

            <div style="text-align: center; margin-top: 23px; font-style: italic; color: #666;">
                Gracias por ser parte del Equipo Pitaya.
            </div>
            <div style="text-align: center; margin-top: 5px; font-style: italic; color: #666; margin-bottom:15px;">
                Si tienes dudas, por favor contáctanos.
            </div>

        <?php else: ?>
            <div class="sin-boleta">
                <i class="fas fa-file-invoice"></i>
                <h2>No se encontró boleta de pago</h2>
                <p>
                    <?php if ($filtro_fecha || $filtro_operario): ?>
                        No hay boleta registrada para los filtros seleccionados.
                    <?php else: ?>
                        No hay boletas de pago registradas para su usuario.
                    <?php endif; ?>
                </p>
            </div>
        <?php endif; ?>
    </div>

    <script>
        // Autocompletado reactivo personalizado para buscador de colaboradores
        (function() {
            var inputNombre = document.getElementById('input-colaborador');
            var inputId = document.getElementById('input-operario-id');
            var listaColaboradores = <?= json_encode($lista_colaboradores) ?>;

            if (!inputNombre || !inputId || !listaColaboradores) return;

            // Crear contenedor de sugerencias dinámico
            var suggestionsContainer = document.createElement('div');
            suggestionsContainer.id = 'autocomplete-suggestions';
            suggestionsContainer.className = 'autocomplete-suggestions';
            suggestionsContainer.style.display = 'none';
            inputNombre.parentNode.appendChild(suggestionsContainer);

            var currentFocus = -1;

            inputNombre.addEventListener('input', function() {
                var val = this.value.trim();
                closeAllLists();
                if (!val) {
                    inputId.value = '';
                    return false;
                }
                currentFocus = -1;

                var valLower = val.toLowerCase();
                var matches = [];

                for (var i = 0; i < listaColaboradores.length; i++) {
                    var col = listaColaboradores[i];
                    if (col.nombre_completo.toLowerCase().indexOf(valLower) !== -1) {
                        matches.push(col);
                    }
                }

                if (matches.length === 0) {
                    return;
                }

                suggestionsContainer.style.display = 'block';
                for (var i = 0; i < matches.length; i++) {
                    (function(item) {
                        var div = document.createElement('div');
                        div.className = 'suggestion-item';
                        
                        var text = item.nombre_completo;
                        var idx = text.toLowerCase().indexOf(valLower);
                        if (idx !== -1) {
                            div.innerHTML = text.substring(0, idx) + 
                                            "<strong>" + text.substring(idx, idx + val.length) + "</strong>" + 
                                            text.substring(idx + val.length);
                        } else {
                            div.textContent = text;
                        }

                        div.addEventListener('click', function() {
                            inputNombre.value = item.nombre_completo;
                            inputId.value = item.CodOperario;
                            closeAllLists();
                            // Cargar fechas disponibles vía AJAX para este colaborador
                            cargarFechasDisponibles(item.CodOperario, null);
                        });
                        suggestionsContainer.appendChild(div);
                    })(matches[i]);
                }
            });

            // Mostrar sugerencias al hacer clic/focus si ya tiene texto
            inputNombre.addEventListener('focus', function() {
                if (this.value.trim()) {
                    this.dispatchEvent(new Event('input'));
                }
            });

            // Navegación por teclado
            inputNombre.addEventListener('keydown', function(e) {
                var x = suggestionsContainer.getElementsByClassName('suggestion-item');
                if (!x || x.length === 0) return;

                if (e.keyCode == 40) { // Flecha abajo
                    currentFocus++;
                    addActive(x);
                } else if (e.keyCode == 38) { // Flecha arriba
                    currentFocus--;
                    addActive(x);
                } else if (e.keyCode == 13) { // Enter
                    if (currentFocus > -1) {
                        x[currentFocus].click();
                        e.preventDefault();
                    }
                } else if (e.keyCode == 27) { // Escape
                    closeAllLists();
                }
            });

            function addActive(x) {
                removeActive(x);
                if (currentFocus >= x.length) currentFocus = 0;
                if (currentFocus < 0) currentFocus = (x.length - 1);
                x[currentFocus].classList.add('active');
                x[currentFocus].scrollIntoView({ block: 'nearest' });
            }

            function removeActive(x) {
                for (var i = 0; i < x.length; i++) {
                    x[i].classList.remove('active');
                }
            }

            function closeAllLists() {
                suggestionsContainer.innerHTML = '';
                suggestionsContainer.style.display = 'none';
            }

            // Cerrar sugerencias al hacer clic fuera
            document.addEventListener('click', function(e) {
                if (e.target !== inputNombre && e.target !== suggestionsContainer) {
                    closeAllLists();
                }
            });

            // ── AJAX: cargar fechas del colaborador seleccionado ──────────────
            var selectFecha = document.getElementById('select-fecha');

            function cargarFechasDisponibles(codOperario, preSelectedDate) {
                if (!selectFecha || !codOperario) return;

                selectFecha.innerHTML = '<option value="">— Cargando... —</option>';
                selectFecha.disabled = true;

                fetch('get_fechas_boleta.php?operario=' + encodeURIComponent(codOperario))
                    .then(function(resp) { return resp.json(); })
                    .then(function(fechas) {
                        selectFecha.innerHTML = '<option value="">— Más reciente —</option>';
                        if (fechas && fechas.length > 0) {
                            fechas.forEach(function(f) {
                                var opt = document.createElement('option');
                                opt.value = f.fecha;
                                opt.textContent = f.label;
                                if (preSelectedDate && f.fecha === preSelectedDate) {
                                    opt.selected = true;
                                }
                                selectFecha.appendChild(opt);
                            });
                        }
                        selectFecha.disabled = false;
                    })
                    .catch(function() {
                        selectFecha.innerHTML = '<option value="">— Más reciente —</option>';
                        selectFecha.disabled = false;
                    });
            }

            // Si ya viene un operario en GET (página cargada con filtros activos),
            // repoblar el select con las fechas correctas y reseleccionar la fecha filtrada.
            var operarioPreseleccionado = inputId.value;
            if (operarioPreseleccionado) {
                var fechaFiltradaActual = '<?= $filtro_fecha ?? '' ?>';
                cargarFechasDisponibles(operarioPreseleccionado, fechaFiltradaActual);
            }
        })();


        // Atajo de teclado para imprimir
        document.addEventListener('keydown', function(e) {
            if (e.ctrlKey && e.key === 'p') {
                e.preventDefault();
                window.print();
            }
        });


    </script>
</body>

</html>