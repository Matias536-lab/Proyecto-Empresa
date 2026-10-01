<?php
/* Pie común. Se usa con $script_pagina = 'asistencia.js' antes del require.
   El script va acá abajo y no en el <head> porque si no se ejecuta antes
   de que existan los botones y las tablas que maneja. */
?>

<?php if (!empty($script_pagina)): ?>
<script src="js/<?= htmlspecialchars($script_pagina, ENT_QUOTES, 'UTF-8') ?>"></script>
<?php endif; ?>

</body>
</html>