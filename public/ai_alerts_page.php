<?php
/**
 * public/ai_alerts_page.php
 * =========================================================
 * FUNCIÓN GENERAL DEL ARCHIVO:
 * Pantalla inicial de Alertas.
 *
 * RELACIÓN CON OTROS ARCHIVOS:
 * - Usa Auth.php para exigir sesión y rol.
 * - Incluye navbar.php para la navegación común.
 *
 * FUNCIONES PRINCIPALES:
 * - Permitir acceso a admin y operador.
 * - Servir como home del operador tras login.
 * - Dejar preparada la pantalla para la futura lógica de alertas críticas.
 */

require_once __DIR__ . '/../app/config/constants.php';
require_once __DIR__ . '/../app/helpers/Auth.php';

auth_require_role(['admin', 'operador']);
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Alertas</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
    <?php include __DIR__ . '/partials/navbar.php'; ?>

    <div id="page-wrapper">
        <div class="container py-4">
            <div class="card shadow-sm">
                <div class="card-body">
                    <h1 class="h4 mb-2">Alertas</h1>
                    <p class="text-muted mb-0">
                        Pantalla de alertas críticas sin asignar. En el siguiente paso conectaremos esta vista
                        con el backend de alertas y la acción de “Coger incidencia”.
                    </p>
                </div>
            </div>
        </div>
    </div>
</body>
</html>