<?php
/* Barra de arriba y solapas, en un archivo aparte para agregar secciones
   en un solo lugar.
   Van arriba y no al costado porque las planillas del sistema son anchas
   y una barra lateral fija se comía 260 píxeles en todas las pantallas. */

if (!isset($pagina_activa)) {
    $pagina_activa = '';
}

/* RRHH ve las cuatro secciones; el empleado solo las dos suyas. */
$es_rrhh = (($_SESSION['rol'] ?? '') === 'rrhh');

$secciones = [];

if ($es_rrhh) {
    $secciones['dashboard'] = ['archivo' => 'dashboard.php', 'texto' => 'Panel'];
    $secciones['empleados'] = ['archivo' => 'empleados.php', 'texto' => 'Empleados'];
}

$secciones['asistencia'] = ['archivo' => 'asistencia.php', 'texto' => 'Asistencia'];
$secciones['licencias']  = ['archivo' => 'licencias.php',  'texto' => 'Licencias'];

if ($es_rrhh) {
    $secciones['reportes']    = ['archivo' => 'reportes.php',    'texto' => 'Reportes'];
    $secciones['liquidacion'] = ['archivo' => 'liquidacion.php', 'texto' => 'Liquidación'];
}
?>

<header class="cabecera">
    <div class="cabecera__marca">
        <?php logo(42); ?>
        <strong>ASISTENS</strong>
        <span>Sistema de gestión de personal</span>
    </div>

    <div class="cabecera__usuario">
        <span>
            <b><?= limpio($_SESSION['nombre'] ?? '') ?></b>
            <small>(<?= $es_rrhh ? 'Recursos Humanos' : 'Empleado' ?>)</small>
        </span>
        <a href="salir.php" class="cabecera__salir">Cerrar sesión</a>
    </div>
</header>

<nav class="solapas">
    <?php foreach ($secciones as $clave => $s): ?>
        <a href="<?= $s['archivo'] ?>"
           class="<?= $pagina_activa === $clave ? 'activa' : '' ?>"><?= $s['texto'] ?></a>
    <?php endforeach; ?>
</nav>