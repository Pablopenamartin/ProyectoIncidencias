<?php
/**
 * public/test_frontend.php
 * -------------------------------------------------------
 * Test mínimo aislado:
 * 1) Comprueba que el navegador ejecuta JS
 * 2) Comprueba fetch a dashboard.php
 * 3) Comprueba fetch a issues.php
 */
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Test Frontend</title>
</head>
<body>
  <h1>Test Frontend</h1>

  <pre id="out">Esperando...</pre>

  <script>
    alert('TEST JS OK');
    console.log('TEST JS OK');

    const out = document.getElementById('out');

    async function run() {
      try {
        out.textContent = '1) JS ejecutándose...\n';

        const dashRes = await fetch('./api/dashboard.php?t=' + Date.now());
        const dashText = await dashRes.text();
        out.textContent += '\n2) dashboard.php:\n' + dashText + '\n';

        const issuesRes = await fetch('./api/issues.php?t=' + Date.now());
        const issuesText = await issuesRes.text();
        out.textContent += '\n3) issues.php:\n' + issuesText + '\n';

      } catch (e) {
        out.textContent += '\nERROR:\n' + e.message;
        console.error(e);
      }
    }

    run();
  </script>
</body>
</html>