<?php
/**
 * Navbar común de la aplicación
 * ---------------------------------------
 * - Fondo azul (Bootstrap primary)
 * - Texto blanco
 * - Logo DXC como Home
 * - Navegación central escalable
 * - Acción Exportar a la derecha
 */
require_once __DIR__ . '/../../app/helpers/Auth.php';
auth_boot();

// Usuario logado actual
$user = auth_user();
$current = basename($_SERVER['PHP_SELF']);

$isAdmin = auth_is_admin();
$isOperator = auth_is_operator();

// Home dinámico según rol
$homeHref = auth_home_path();
$displayName = $user['display_name'] ?? 'Usuario';

// Detectar página activa (simple)
$current = basename($_SERVER['PHP_SELF']);
?>

<style>
/* Importar fuente tipo DXC directamente aquí */
@import url('https://fonts.googleapis.com/css2?family=Rajdhani:wght@600;700&display=swap');

/* Logo textual DXC */
.navbar-brand-dxc {
  font-family: 'Rajdhani', sans-serif;
  font-weight: 700;
  font-size: 1.5rem;
  letter-spacing: 0.18em;
  color: #ffffff;
  text-decoration: none;
}

.navbar-brand-dxc:hover {
  color: #ffffff;
  text-decoration: none;
}
</style>

<style>
/* =========================
   Transición entre páginas
========================= */

body {
  overflow-x: hidden;
}

/* Estado inicial al cargar */
#page-wrapper {
  transform: translateX(100%);
  animation: slideIn 0.35s ease-out forwards;
}

/* Salida a la izquierda */
body.slide-out-left #page-wrapper {
  animation: slideOutLeft 0.35s ease-in forwards;
}

/* Salida a la derecha */
body.slide-out-right #page-wrapper {
  animation: slideOutRight 0.35s ease-in forwards;
}

/* Animaciones */
@keyframes slideIn {
  from { transform: translateX(100%); }
  to   { transform: translateX(0); }
}

@keyframes slideOutLeft {
  from { transform: translateX(0); }
  to   { transform: translateX(-100%); }
}

@keyframes slideOutRight {
  from { transform: translateX(0); }
  to   { transform: translateX(100%); }
}

/* Entrada desde la derecha (para index.php) */
body.enter-from-right #page-wrapper {
  transform: translateX(100%);
  animation: slideInFromRight 0.35s ease-out forwards;
}

/* Entrada desde la izquierda (para timeline_page.php) */
body.enter-from-left #page-wrapper {
  transform: translateX(-100%);
  animation: slideInFromLeft 0.35s ease-out forwards;
}

/* Keyframes */
@keyframes slideInFromRight {
  from { transform: translateX(100%); }
  to   { transform: translateX(0); }
}

@keyframes slideInFromLeft {
  from { transform: translateX(-100%); }
  to   { transform: translateX(0); }
}

</style>

<script>
document.addEventListener('DOMContentLoaded', () => {

  // -------------------------------------
  // Decidir animación de ENTRADA
  // -------------------------------------
  const path = window.location.pathname;

  if (path.endsWith('index.php') || path.endsWith('/')) {
    // Index SIEMPRE entra desde la derecha
    document.body.classList.add('enter-from-right');
  } else if (path.endsWith('timeline_page.php')) {
    // Timeline entra desde la izquierda
    document.body.classList.add('enter-from-left');
  }

  // -------------------------------------
  // Interceptar enlaces con data-slide
  // -------------------------------------
  document.querySelectorAll('a[data-slide]').forEach(link => {
    link.addEventListener('click', e => {
      e.preventDefault();

      const direction = link.dataset.slide;
      const href = link.getAttribute('href');

      document.body.classList.add(
        direction === 'right'
          ? 'slide-out-right'
          : 'slide-out-left'
      );

      setTimeout(() => {
        window.location.href = href;
      }, 320);
    });
  });

});
</script>



<nav class="navbar navbar-expand-lg navbar-dark bg-primary px-3">

  <!-- LOGO / HOME -->
  <a href="<?= htmlspecialchars($homeHref, ENT_QUOTES, 'UTF-8') ?>"
    class="navbar-brand navbar-brand-dxc"
    data-slide="right">
    DXC
  </a>


  <!-- Expansión responsive -->
  <button class="navbar-toggler"
          type="button"
          data-bs-toggle="collapse"
          data-bs-target="#mainNavbar">
    <span class="navbar-toggler-icon"></span>
  </button>

  <!-- CONTENIDO CENTRAL -->
  <div class="collapse navbar-collapse" id="mainNavbar">

    <!-- Navegación principal: else if admin / operador -->
    <ul class="navbar-nav mx-auto align-items-center gap-3">

      <?php if ($isAdmin): ?>
        <!-- TIMELINE -->
        <li class="nav-item">
          <a class="nav-link <?= $current === 'timeline_page.php' ? 'active fw-bold' : '' ?>"
            href="timeline_page.php"
            data-slide="left">
            TIMELINE
          </a>
        </li>

        <li class="nav-item text-white-50">|</li>

        <!-- CONF IA -->
        <li class="nav-item">
          <a class="nav-link <?= $current === 'ai_config.php' ? 'active fw-bold' : '' ?>"
            href="ai_config.php"
            data-slide="left">
            CONF IA
          </a>
        </li>

        <li class="nav-item text-white-50">|</li>

        <!-- INFORMES -->
        <li class="nav-item">
          <a class="nav-link <?= $current === 'ai_reports_page.php' ? 'active fw-bold' : '' ?>"
            href="ai_reports_page.php"
            data-slide="left">
            INFORMES
          </a>
        </li>

        <li class="nav-item text-white-50">|</li>

        <!-- ALERTAS -->
        <li class="nav-item">
          <a class="nav-link <?= $current === 'ai_alerts_page.php' ? 'active fw-bold' : '' ?>"
            href="ai_alerts_page.php"
            data-slide="left">
            ALERTAS
          </a>
        </li>

      <?php elseif ($isOperator): ?>
        <!-- ALERTAS -->
        <li class="nav-item">
          <a class="nav-link <?= $current === 'ai_alerts_page.php' ? 'active fw-bold' : '' ?>"
            href="ai_alerts_page.php"
            data-slide="left">
            ALERTAS
          </a>
        </li>
      <?php endif; ?>

    </ul>
  </div>
  
  <div class="d-flex align-items-center gap-2">
    <span class="text-white small">
      <?= htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8') ?>
    </span>
    <a href="logout.php" class="btn btn-outline-light btn-sm">
      Logout
    </a>

  </div>

</nav>