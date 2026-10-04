<?php
/**
 * Sistema de Permisos para Tools ERP
 * Adaptado para planilla.batidospitaya
 * 
 * Usa la misma BD (u839374897_erp) y las mismas tablas:
 *   tools_erp / acciones_tools_erp / permisos_tools_erp
 * 
 * NOTA: Incluir DESPUÉS de conexion.php
 */

/**
 * Carga todos los permisos de una herramienta para un cargo en la sesión PHP
 */
function cargarPermisosHerramientaEnSesion($nombreHerramienta, $codNivelCargo)
{
    global $conn;

    try {
        $sql = "
            SELECT a.nombre_accion, p.permiso
            FROM tools_erp t
            INNER JOIN acciones_tools_erp a ON t.id = a.tool_erp_id
            LEFT JOIN permisos_tools_erp p ON a.id = p.accion_tool_erp_id
                AND p.CodNivelesCargos = :codNivelCargo
            WHERE t.nombre = :nombreHerramienta
        ";

        $stmt = $conn->prepare($sql);
        $stmt->execute([
            ':nombreHerramienta' => $nombreHerramienta,
            ':codNivelCargo'     => $codNivelCargo
        ]);

        $permisos = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $permisos[$row['nombre_accion']] = ($row['permiso'] === 'allow');
        }

        if (!isset($_SESSION['permisos_tools_erp'])) {
            $_SESSION['permisos_tools_erp'] = [];
        }
        if (!isset($_SESSION['permisos_tools_erp'][$codNivelCargo])) {
            $_SESSION['permisos_tools_erp'][$codNivelCargo] = [];
        }

        $_SESSION['permisos_tools_erp'][$codNivelCargo][$nombreHerramienta] = $permisos;

    } catch (PDOException $e) {
        error_log("Error en cargarPermisosHerramientaEnSesion: " . $e->getMessage());
    }
}

/**
 * Verifica si un cargo tiene permiso para realizar una acción en una herramienta
 *
 * @param string $nombreHerramienta  Slug de la herramienta (campo 'nombre' de tools_erp)
 * @param string $nombreAccion       Slug de la acción (ej: 'cambiar_fecha')
 * @param int    $codNivelCargo      CodNivelesCargos del usuario actual
 * @return bool  true si tiene permiso, false si no
 */
function tienePermiso($nombreHerramienta, $nombreAccion, $codNivelCargo)
{
    if (empty($nombreHerramienta) || empty($nombreAccion) || empty($codNivelCargo)) {
        return false;
    }

    if (!isset($_SESSION['permisos_tools_erp'][$codNivelCargo][$nombreHerramienta])) {
        cargarPermisosHerramientaEnSesion($nombreHerramienta, $codNivelCargo);
    }

    return $_SESSION['permisos_tools_erp'][$codNivelCargo][$nombreHerramienta][$nombreAccion] ?? false;
}
