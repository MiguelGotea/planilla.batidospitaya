<?php
/**
 * Endpoint AJAX: devuelve las fechas de emisión de aguinaldo disponibles para un operario
 * dado su CodOperario (busca en todos sus contratos).
 *
 * GET ?operario=<CodOperario>
 * Responde JSON: [ { "fecha": "YYYY-MM-DD", "label": "DD/MM/YYYY" }, ... ]
 */

require_once 'includes/conexion.php';
require_once 'includes/auth.php';
require_once 'includes/funciones.php';
require_once 'includes/permissions.php';

header('Content-Type: application/json; charset=utf-8');

verificarAutenticacion();

$usuario   = obtenerUsuarioActual();
$codCargo  = $usuario['CodNivelesCargos'] ?? null;
$puedeChangeFecha = tienePermiso('boletas_planilla', 'cambiar_fecha', $codCargo);

if (!$puedeChangeFecha) {
    echo json_encode(['error' => 'Sin permiso']);
    exit;
}

if (empty($_GET['operario']) || !is_numeric($_GET['operario'])) {
    echo json_encode([]);
    exit;
}

$codOperario = (int) $_GET['operario'];

// NOTA: BoletaAguinaldo.cod_operario almacena CodContrato (Excel). Buscamos SOLO por CodContratos.
$stmt_contratos = ejecutarConsulta(
    "SELECT CodContrato FROM Contratos WHERE cod_operario = ?",
    [$codOperario]
);
$lista_contratos = [];
if ($stmt_contratos) {
    $lista_contratos = $stmt_contratos->fetchAll(PDO::FETCH_COLUMN);
}

if (empty($lista_contratos)) {
    echo json_encode([]);
    exit;
}

$placeholders = implode(',', array_fill(0, count($lista_contratos), '?'));

// Buscar fechas de aguinaldo solo por CodContratos del operario
$params_fechas = array_merge($lista_contratos, $lista_contratos);
$stmt_fechas = ejecutarConsulta(
    "SELECT DISTINCT ba.fecha_emision
     FROM BoletaAguinaldo ba
     LEFT JOIN Contratos c ON ba.cod_contrato = c.CodContrato
     WHERE ba.cod_contrato IN ($placeholders)
        OR ba.cod_operario IN ($placeholders)
     ORDER BY ba.fecha_emision DESC",
    $params_fechas
);

$resultado = [];
if ($stmt_fechas) {
    while ($row = $stmt_fechas->fetch()) {
        $resultado[] = [
            'fecha' => $row['fecha_emision'],
            'label' => date('d/m/Y', strtotime($row['fecha_emision']))
        ];
    }
}

echo json_encode($resultado);
