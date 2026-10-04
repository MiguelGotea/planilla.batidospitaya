<?php
//error_reporting(E_ALL);
//ini_set('display_errors', 1);

require_once 'includes/conexion.php';
require_once 'includes/auth.php';
require_once 'includes/funciones.php';
require_once 'includes/permissions.php';

verificarAutenticacion();

// Verificar autenticación
if (!isset($_SESSION['usuario_id'])) {
    header("Location: login.php");
    exit();
}

/**
 * Obtiene la boleta de aguinaldo del usuario actual buscando a través de la relación con Contratos
 * MODIFICACIÓN: Ahora usa el cod_operario de Contratos en lugar de BoletaAguinaldo
 */
function obtenerBoletaAguinaldoUsuarioPorContrato($codOperario) {
    global $conn;
    
    // NOTA: BoletaAguinaldo.cod_operario almacena CodContrato (Excel).
    // Buscamos SOLO por los CodContratos reales del operario.
    $stmt_contratos = $conn->prepare("SELECT CodContrato FROM Contratos WHERE cod_operario = ?");
    $stmt_contratos->execute([$codOperario]);
    $lista_contratos = $stmt_contratos->fetchAll(PDO::FETCH_COLUMN);
    
    if (empty($lista_contratos)) return null;
    
    $placeholders = implode(',', array_fill(0, count($lista_contratos), '?'));
    
    $stmt = $conn->prepare("
        SELECT 
            ba.*, 
            c.cod_operario,
            c.salario_inicial,
            c.inicio_contrato,
            c.fin_contrato,
            o.Nombre,
            o.Apellido,
            o.Apellido2
        FROM BoletaAguinaldo ba
        LEFT JOIN Contratos c ON ba.cod_contrato = c.CodContrato
        LEFT JOIN Operarios o ON c.cod_operario = o.CodOperario
        WHERE ba.cod_contrato IN ($placeholders)
           OR ba.cod_operario IN ($placeholders)
        ORDER BY ba.fecha_emision DESC, ba.id_boleta_aguinaldo DESC 
        LIMIT 1
    ");
    
    $params = array_merge($lista_contratos, $lista_contratos);
    $stmt->execute($params);
    
    if ($stmt && $stmt->rowCount() > 0) {
        return $stmt->fetch();
    }
    
    return null;
}

/**
 * Función alternativa para obtener todas las boletas de aguinaldo de un operario
 */
function obtenerBoletasAguinaldoPorOperario($codOperario) {
    global $conn;
    
    $stmt = $conn->prepare("
        SELECT 
            ba.*, 
            c.cod_operario,
            c.salario_inicial,
            c.inicio_contrato,
            c.fin_contrato,
            o.Nombre,
            o.Apellido,
            o.Apellido2
        FROM BoletaAguinaldo ba
        INNER JOIN Contratos c ON ba.cod_contrato = c.CodContrato
        INNER JOIN Operarios o ON c.cod_operario = o.CodOperario
        WHERE c.cod_operario = ? 
        ORDER BY ba.fecha_emision DESC
    ");
    
    $stmt->execute([$codOperario]);
    
    return $stmt->fetchAll();
}

// Obtener el código del operario desde la sesión
$cod_operario = $_SESSION['usuario_id'];

$usuario = obtenerUsuarioActual();
$esAdmin = isset($_SESSION['usuario_rol']) && $_SESSION['usuario_rol'] === 'admin';
$cargoUsuario = obtenerCargoPrincipalUsuario($_SESSION['usuario_id']);

// --- Permiso cambiar_fecha ---
$codCargo = $usuario['CodNivelesCargos'] ?? null;
$puedeChangeFecha = tienePermiso('boletas_planilla', 'cambiar_fecha', $codCargo);

// --- Parámetros GET (solo si tiene permiso) ---
$filtro_operario = null; // CodOperario a buscar
$filtro_fecha = null;    // fecha_emision a filtrar (YYYY-MM-DD)
$nombre_buscado = '';    // texto que digitó en el buscador

if ($puedeChangeFecha) {
    if (!empty($_GET['operario']) && is_numeric($_GET['operario'])) {
        $filtro_operario = (int)$_GET['operario'];
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

// Determinar qué operario se consulta
$cod_operario_consulta = $filtro_operario ?? $cod_operario;

// Cargar lista de colaboradores para el buscador (solo con permiso)
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

// Cargar fechas disponibles de aguinaldo para el colaborador seleccionado (solo con permiso)
// NOTA: BoletaAguinaldo.cod_operario almacena CodContrato (Excel). Buscamos SOLO por CodContratos.
$fechas_disponibles = [];
if ($puedeChangeFecha) {
    $stmt_contratos_fechas = ejecutarConsulta(
        "SELECT CodContrato FROM Contratos WHERE cod_operario = ?",
        [$cod_operario_consulta]
    );
    $lista_contratos_fechas = $stmt_contratos_fechas ? $stmt_contratos_fechas->fetchAll(PDO::FETCH_COLUMN) : [];

    if (!empty($lista_contratos_fechas)) {
        $ph_f = implode(',', array_fill(0, count($lista_contratos_fechas), '?'));
        $params_fechas = array_merge($lista_contratos_fechas, $lista_contratos_fechas);
        $stmt_fechas = ejecutarConsulta(
            "SELECT DISTINCT ba.fecha_emision 
             FROM BoletaAguinaldo ba
             LEFT JOIN Contratos c ON ba.cod_contrato = c.CodContrato
             WHERE ba.cod_contrato IN ($ph_f)
                OR ba.cod_operario IN ($ph_f)
             ORDER BY ba.fecha_emision DESC",
            $params_fechas
        );
        if ($stmt_fechas) {
            $fechas_disponibles = $stmt_fechas->fetchAll();
        }
    }
}

// Obtener la boleta de aguinaldo
// NOTA: BoletaAguinaldo.cod_operario almacena CodContrato (Excel). Buscamos SOLO por CodContratos.
$boleta_aguinaldo = null;

$_debug_contratos_ag = [];
$_stmt_debug_ag = ejecutarConsulta(
    "SELECT CodContrato FROM Contratos WHERE cod_operario = ?",
    [$cod_operario_consulta]
);
if ($_stmt_debug_ag) {
    $_debug_contratos_ag = $_stmt_debug_ag->fetchAll(PDO::FETCH_COLUMN);
}

if (!empty($_debug_contratos_ag)) {
    $ph_ag = implode(',', array_fill(0, count($_debug_contratos_ag), '?'));

    if ($filtro_fecha && $puedeChangeFecha) {
        $params_boleta = array_merge($_debug_contratos_ag, $_debug_contratos_ag, [$filtro_fecha]);
        $stmt = ejecutarConsulta(
            "SELECT 
                ba.*, 
                c.cod_operario,
                c.salario_inicial,
                c.inicio_contrato,
                c.fin_contrato,
                o.Nombre,
                o.Apellido,
                o.Apellido2
             FROM BoletaAguinaldo ba
             LEFT JOIN Contratos c ON ba.cod_contrato = c.CodContrato
             LEFT JOIN Operarios o ON c.cod_operario = o.CodOperario
             WHERE (ba.cod_contrato IN ($ph_ag)
                OR ba.cod_operario IN ($ph_ag))
               AND ba.fecha_emision = ?
             ORDER BY ba.id_boleta_aguinaldo DESC LIMIT 1",
            $params_boleta
        );
        if ($stmt && $stmt->rowCount() > 0) {
            $boleta_aguinaldo = $stmt->fetch();
        }
    } else {
        $boleta_aguinaldo = obtenerBoletaAguinaldoUsuarioPorContrato($cod_operario_consulta);
    }
}

// Función para formatear valores
function formatearValor($valor, $esMonto = false) {
    if ($valor === null || $valor === '' || $valor == 0) {
        return '-';
    }
    
    if ($esMonto) {
        return number_format($valor, 2, '.', ',');
    }
    
    return $valor;
}

// Función para obtener el nombre completo del empleado
function obtenerNombreCompletoEmpleado($boleta) {
    if (isset($boleta['empleado_nombre']) && !empty($boleta['empleado_nombre'])) {
        return $boleta['empleado_nombre'];
    }
    
    // Si no viene en la boleta, construirlo desde la tabla Operarios
    $nombre = trim($boleta['Nombre'] ?? '');
    $apellido = trim($boleta['Apellido'] ?? '');
    $apellido2 = trim($boleta['Apellido2'] ?? '');
    
    $nombreCompleto = $nombre;
    if (!empty($apellido)) {
        $nombreCompleto .= ' ' . $apellido;
    }
    if (!empty($apellido2)) {
        $nombreCompleto .= ' ' . $apellido2;
    }
    
    return $nombreCompleto;
}

// Función para formatear fecha en español
function formatoFechaEspanol($fecha) {
    if (empty($fecha) || $fecha == '0000-00-00') {
        return '-';
    }
    
    $meses = [
        '01' => 'Enero', '02' => 'Febrero', '03' => 'Marzo', '04' => 'Abril',
        '05' => 'Mayo', '06' => 'Junio', '07' => 'Julio', '08' => 'Agosto',
        '09' => 'Septiembre', '10' => 'Octubre', '11' => 'Noviembre', '12' => 'Diciembre'
    ];
    
    $fechaObj = new DateTime($fecha);
    $dia = $fechaObj->format('d');
    $mes = $meses[$fechaObj->format('m')];
    $anio = $fechaObj->format('Y');
    
    return "$dia de $mes de $anio";
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Boleta de Aguinaldo - Batidos Pitaya</title>
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
            padding: 0;
            margin: 0;
        }
        
        .container {
            max-width: 800px;
            margin: 0 auto;
            background: white;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        /* Header styles */
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
        
        .btn-back {
            background: #6c757d;
            color: white;
            border: none;
            padding: 8px 15px;
            border-radius: 4px;
            cursor: pointer;
            transition: background 0.3s;
            text-decoration: none;
            display: inline-block;
            margin-right: 10px;
        }
        
        .btn-back:hover {
            background: #545b62;
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
        .btn-filtrar:hover { background: #0a3d37; }
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
        .btn-limpiar:hover { background: #545b62; }
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
        
        .subtitulo {
            color: #666;
            font-size: 18px;
            margin-bottom: 15px;
        }
        
        .empleado-info {
            font-size: 18px;
            margin-bottom: 5px;
            font-weight: bold;
        }
        
        .fecha-info {
            color: #666;
            margin-bottom: 10px;
        }
        
        .periodo-info {
            background-color: #f8f9fa;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
            border-left: 4px solid #51B8AC;
        }
        
        .periodo-info p {
            margin-bottom: 5px;
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
            padding: 12px;
            text-align: left;
            font-weight: normal;
        }
        
        .tabla-boleta td {
            padding: 12px;
            border-bottom: 1px solid #ddd;
        }
        
        .tabla-boleta tr:nth-child(even) {
            background-color: #f9f9f9;
        }
        
        .total-row {
            font-weight: bold;
            background-color: #e8f5e9 !important;
            font-size: 16px;
        }
        
        .total-aguinaldo {
            font-size: 22px;
            color: #0E544C;
            text-align: center;
            padding: 20px;
            background-color: #f1f8e9;
            border-radius: 4px;
            margin: 20px 0;
            border: 2px solid #51B8AC;
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
        
        .leyenda {
            font-size: 12px;
            color: #666;
            margin-top: 5px;
            font-style: italic;
        }
        
        .botones-accion {
            display: flex;
            justify-content: center;
            gap: 15px;
            margin-top: 30px;
            margin-bottom: 20px;
        }
        
        .btn-imprimir {
            background: #28a745;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 4px;
            cursor: pointer;
            transition: background 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        
        .btn-imprimir:hover {
            background: #218838;
        }
        
        .btn-descargar {
            background: #17a2b8;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 4px;
            cursor: pointer;
            transition: background 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        
        .btn-descargar:hover {
            background: #138496;
        }
        
        @media print {
            .btn-logout, .btn-back, .botones-accion, .panel-historico {
                display: none;
            }
            
            .header-container {
                border-bottom: none;
                margin-bottom: 10px;
            }
            
            body {
                background-color: white;
                padding: 0;
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
            
            .botones-accion {
                flex-direction: column;
            }
            .filtros-form { flex-direction: column; }
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
                                htmlspecialchars($usuario['Nombre'].' '.$usuario['Apellido']) ?>
                        </div>
                        <small>
                            <?= htmlspecialchars($cargoUsuario) ?>
                        </small>
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
            <h3><i class="fas fa-history"></i> Buscar boleta de aguinaldo histórica</h3>
            <form class="filtros-form" method="GET" action="boleta_aguinaldo.php">
                <div class="filtro-grupo">
                    <label for="input-colaborador">Colaborador</label>
                    <input
                        type="text"
                        id="input-colaborador"
                        name="nombre_buscado"
                        placeholder="Buscar por nombre..."
                        value="<?= htmlspecialchars($nombre_buscado) ?>"
                        autocomplete="off"
                    >
                    <!-- Campo oculto con CodOperario seleccionado -->
                    <input type="hidden" name="operario" id="input-operario-id" value="<?= $filtro_operario ?? '' ?>">
                </div>
                <div class="filtro-grupo">
                    <label for="select-fecha">Fecha de emisión</label>
                    <select id="select-fecha" name="fecha">
                        <option value="">— Más reciente —</option>
                        <?php foreach ($fechas_disponibles as $f): ?>
                            <option value="<?= $f['fecha_emision'] ?>"
                                <?= ($filtro_fecha === $f['fecha_emision']) ? 'selected' : '' ?>>
                                <?= date('d/m/Y', strtotime($f['fecha_emision'])) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="btn-filtrar">
                    <i class="fas fa-search"></i> Ver
                </button>
                <?php if ($filtro_operario || $filtro_fecha): ?>
                <a href="boleta_aguinaldo.php" class="btn-limpiar">
                    <i class="fas fa-times"></i> Limpiar
                </a>
                <?php endif; ?>
            </form>
            <?php if ($filtro_operario || $filtro_fecha): ?>
                <div style="margin-top:8px;">
                    <span class="badge-historico">
                        <i class="fas fa-filter"></i> Filtro activo
                        <?= $filtro_fecha ? ' — ' . date('d/m/Y', strtotime($filtro_fecha)) : '' ?>
                        <?php if ($filtro_operario && $boleta_aguinaldo): ?>
                            — <?= htmlspecialchars(obtenerNombreCompletoEmpleado($boleta_aguinaldo)) ?>
                        <?php endif; ?>
                    </span>
                    <!-- INFO PANEL (solo para usuarios con permiso de filtrar) -->
                    <div style="margin-top:6px; padding:7px 12px; background:#f0f4f8; border:1px solid #ccd6e0; border-left:3px solid #51B8AC; border-radius:4px; font-size:11px; color:#555; display:flex; flex-wrap:wrap; gap:12px;">
                        <span title="Código de operario real en tabla Operarios">
                            <i class="fas fa-id-badge" style="color:#51B8AC;"></i>
                            Cód. operario: <strong><?= $cod_operario_consulta ?></strong>
                        </span>
                        <span title="Código(s) de contrato usados para buscar en BoletaAguinaldo">
                            <i class="fas fa-file-contract" style="color:#51B8AC;"></i>
                            Contrato: <strong><?= !empty($_debug_contratos_ag) ? implode(', ', $_debug_contratos_ag) : '—' ?></strong>
                        </span>
                        <?php if ($boleta_aguinaldo): ?>
                            <span title="ID interno de la boleta de aguinaldo encontrada">
                                <i class="fas fa-receipt" style="color:#51B8AC;"></i>
                                ID boleta: <strong><?= $boleta_aguinaldo['id_boleta_aguinaldo'] ?></strong>
                            </span>
                            <span title="Nombre guardado en la boleta de aguinaldo">
                                <i class="fas fa-user" style="color:#51B8AC;"></i>
                                Nombre en boleta: <strong><?= htmlspecialchars(obtenerNombreCompletoEmpleado($boleta_aguinaldo)) ?></strong>
                            </span>
                        <?php else: ?>
                            <span style="color:#c0392b;">
                                <i class="fas fa-exclamation-triangle"></i>
                                No se encontró boleta de aguinaldo para este colaborador
                            </span>
                        <?php endif; ?>
                        <span title="Fechas de emisión disponibles para este colaborador">
                            <i class="fas fa-calendar-alt" style="color:#51B8AC;"></i>
                            Fechas disponibles: <strong><?= count($fechas_disponibles) ?></strong>
                        </span>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        
        <?php if ($boleta_aguinaldo): ?>
            <div class="boleta-header">
                <h1>BOLETA DE AGUINALDO</h1>
                <div class="subtitulo" style="display:none;">Ley No. 1104, "Ley de Aguinaldo"</div>
                <div class="empleado-info"><?= htmlspecialchars(obtenerNombreCompletoEmpleado($boleta_aguinaldo)) ?></div>
                <div class="fecha-info" style="display:none;">Fecha de Emisión: <?= formatoFechaEspanol($boleta_aguinaldo['fecha_emision']) ?></div>
            </div>
            
            <!-- Detalle del Cálculo -->
            <div class="seccion">
                <h2 style="display:none;">Cálculo del Aguinaldo</h2>
                <table class="tabla-boleta">
                    <tr>
                        <th style="display:none;">Concepto</th>
                        <th style="text-align: right; display:none;">Valor</th>
                    </tr>
                    <tr>
                        <td>Fecha de Ingreso</td>
                        <td style="text-align: right;"><?= formatoFecha($boleta_aguinaldo['fecha_ingreso']) ?></td>
                    </tr>
                    <tr>
                        <td>Fecha de Inicio del Periodo</td>
                        <td style="text-align: right;"><?= formatoFecha($boleta_aguinaldo['fecha_inicio_periodo']) ?></td>
                    </tr>
                    <tr>
                        <td>Fecha Final del Periodo</td>
                        <td style="text-align: right;"><?= formatoFecha($boleta_aguinaldo['fecha_final_periodo']) ?></td>
                    </tr>
                    <tr>
                        <td>Periodo Laborado (Meses)</td>
                        <td style="text-align: right;"><?= formatearValor($boleta_aguinaldo['periodo_laborado_meses']) ?></td>
                    </tr>
                    <tr>
                        <td>Salario Básico</td>
                        <td style="text-align: right;"><?= formatearValor($boleta_aguinaldo['salario_basico'], true) ?> C$</td>
                    </tr>
                </table>
            </div>
            
            <!-- Resumen del Aguinaldo -->
            <div class="total-aguinaldo">
                <strong>Total Aguinaldo: <?= formatearValor($boleta_aguinaldo['total_aguinaldo'], true) ?> C$</strong>
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
                    <div><?= htmlspecialchars(obtenerNombreCompletoEmpleado($boleta_aguinaldo)) ?></div>
                </div>
            </div>
            
            <!-- Botones de acción -->
            <div class="botones-accion" style="display:none;">
                <button class="btn-imprimir" onclick="window.print()">
                    <i class="fas fa-print"></i> Imprimir Boleta
                </button>
            </div>
            
            <div style="text-align: center; margin-top: 15px; font-style: italic; color: #666; margin-bottom:15px;">
                <p>Gracias por ser parte del Equipo Pitaya.</p>
                <p>Si tienes dudas, por favor contáctanos.</p>
            </div>
            
        <?php else: ?>
            <div class="sin-boleta">
                <i class="fas fa-file-invoice-dollar"></i>
                <h2>No se encontró boleta de aguinaldo</h2>
                <p>
                    <?php if ($filtro_fecha || $filtro_operario): ?>
                        No hay boleta de aguinaldo registrada para los filtros seleccionados.
                    <?php else: ?>
                        No hay boletas de aguinaldo registradas para su usuario.
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

            // ── AJAX: cargar fechas de emisión del colaborador seleccionado ──────
            var selectFecha = document.getElementById('select-fecha');

            function cargarFechasDisponibles(codOperario, preSelectedDate) {
                if (!selectFecha || !codOperario) return;

                selectFecha.innerHTML = '<option value="">— Cargando... —</option>';
                selectFecha.disabled = true;

                fetch('get_fechas_aguinaldo.php?operario=' + encodeURIComponent(codOperario))
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