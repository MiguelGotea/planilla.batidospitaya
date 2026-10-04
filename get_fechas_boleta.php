<?php
/**
 * Endpoint AJAX: devuelve las fechas de planilla disponibles para un operario
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

// NOTA: BoletaPago.cod_operario almacena CodContrato (viene del Excel contable),
//       NO CodOperario. Buscamos SOLO por los CodContratos del operario.
$stmt_contratos = ejecutarConsulta(
    "SELECT CodContrato FROM Contratos WHERE cod_operario = ?",
    [$codOperario]
);
$lista_contratos = $stmt_contratos ? $stmt_contratos->fetchAll(PDO::FETCH_COLUMN) : [];

if (empty($lista_contratos)) {
    echo json_encode([]);
    exit;
}

$placeholders = implode(',', array_fill(0, count($lista_contratos), '?'));
$stmt_fechas = ejecutarConsulta(
    "SELECT DISTINCT fecha_planilla
     FROM BoletaPago
     WHERE cod_operario IN ($placeholders)
     ORDER BY fecha_planilla DESC",
    $lista_contratos
);

$resultado = [];
if ($stmt_fechas) {
    while ($row = $stmt_fechas->fetch()) {
        $resultado[] = [
            'fecha' => $row['fecha_planilla'],
            'label' => date('d/m/Y', strtotime($row['fecha_planilla']))
        ];
    }
}

echo json_encode($resultado);
