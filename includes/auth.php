<?php
session_start();
require_once 'conexion.php';
require_once 'funciones.php';

function verificarAutenticacion() {
    if (!isset($_SESSION['usuario_id'])) {
        header("Location: login.php");
        exit();
    }
}

// Obtener información del usuario actual
function obtenerUsuarioActual() {
    if (!isset($_SESSION['usuario_id'])) {
        return null;
    }
    
    global $conn;
    $stmt = $conn->prepare("
        SELECT o.CodOperario, o.Nombre, o.Nombre2, o.Apellido, o.Apellido2,
               o.foto_perfil,
               nc.Nombre as cargo_nombre, nc.CodNivelesCargos
        FROM Operarios o
        JOIN AsignacionNivelesCargos anc ON o.CodOperario = anc.CodOperario
        JOIN NivelesCargos nc ON anc.CodNivelesCargos = nc.CodNivelesCargos
        WHERE o.CodOperario = ? 
        AND (anc.Fin IS NULL OR anc.Fin >= NOW())
        ORDER BY anc.Fecha DESC
        LIMIT 1
    ");
    $stmt->execute([$_SESSION['usuario_id']]);
    return $stmt->fetch();
}
?>