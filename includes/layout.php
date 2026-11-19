<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

function render_header(string $title): void
{
    $user = current_user();
    $currentScript = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $currentPage = basename($currentScript);
    $currentArea = basename(dirname($currentScript));
    $themeSettings = db()->query('SELECT app_background_color FROM homepage_settings WHERE id = 1 LIMIT 1')->fetch() ?: [];
    $appBackgroundColor = normalize_hex_color((string) ($themeSettings['app_background_color'] ?? ''));
    ?>
    <!doctype html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="theme-color" content="#174526">
        <title><?= e($title) ?> | <?= e(APP_NAME) ?></title>
        <link rel="stylesheet" href="<?= e(app_url('assets/css/bootstrap-lite.css')) ?>">
        <link rel="stylesheet" href="<?= e(app_url('assets/css/style.css')) ?>">
    </head>
    <body class="<?= $currentPage === 'login.php' ? 'login-page' : '' ?>"<?php if ($appBackgroundColor): ?> style="--app-background-color: <?= e($appBackgroundColor) ?>;"<?php endif; ?>>
    <nav class="navbar navbar-expand-lg navbar-dark app-navbar sticky-top">
        <div class="container">
            <a class="navbar-brand fw-semibold app-brand" href="<?= e(app_url('index.php')) ?>">
                <?php if (file_exists(BASE_PATH . '/assets/fnph_logo.png')): ?>
                    <img src="<?= e(app_url('assets/fnph_logo.png')) ?>" alt="FNPH" class="app-brand-mark" style="object-fit: contain; background: #fff; padding: 2px;">
                <?php else: ?>
                    <span class="app-brand-mark" aria-hidden="true">FN</span>
                <?php endif; ?>
                <span class="app-brand-text"><?= e(APP_NAME) ?></span>
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav" aria-controls="mainNav" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="mainNav">
                <ul class="navbar-nav ms-auto align-items-lg-center gap-lg-2">
                    <li class="nav-item">
                        <a class="nav-link <?= $currentPage === 'index.php' ? 'active' : '' ?>" href="<?= e(app_url('index.php')) ?>" <?= $currentPage === 'index.php' ? 'aria-current="page"' : '' ?>>Home</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= $currentPage === 'gallery.php' ? 'active' : '' ?>" href="<?= e(app_url('gallery.php')) ?>" <?= $currentPage === 'gallery.php' ? 'aria-current="page"' : '' ?>>Gallery</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= $currentPage === 'lecture.php' ? 'active' : '' ?>" href="<?= e(app_url('lecture.php')) ?>" <?= $currentPage === 'lecture.php' ? 'aria-current="page"' : '' ?>>Lectures</a>
                    </li>
                    <?php if ($user && $user['role'] === 'admin'): ?>
                        <li class="nav-item">
                            <a class="nav-link <?= $currentPage === 'dashboard.php' && $currentArea === 'admin' ? 'active' : '' ?>" href="<?= e(app_url('admin/dashboard.php')) ?>" <?= $currentPage === 'dashboard.php' && $currentArea === 'admin' ? 'aria-current="page"' : '' ?>>Admin Panel</a>
                        </li>
                        <li class="nav-item">
                            <?php $isAdminReportsPage = in_array($currentPage, ['department_reports.php', 'monthly_report_review.php', 'monthly_report_analytics.php', 'monthly_kpi_reports.php', 'monthly_reports.php'], true); ?>
                            <a class="nav-link <?= $isAdminReportsPage ? 'active' : '' ?>" href="<?= e(app_url('admin/monthly_kpi_reports.php')) ?>" <?= $isAdminReportsPage ? 'aria-current="page"' : '' ?>>Monthly Reports</a>
                        </li>
                    <?php endif; ?>
                    <?php if ($user && $user['role'] === 'department'): ?>
                        <li class="nav-item">
                            <a class="nav-link <?= $currentArea === 'department' ? 'active' : '' ?>" href="<?= e(app_url('department/dashboard.php')) ?>" <?= $currentArea === 'department' ? 'aria-current="page"' : '' ?>>Department Dashboard</a>
                        </li>
                    <?php endif; ?>
                    <?php if (!$user): ?>
                        <li class="nav-item">
                            <a class="nav-link" href="<?= e(app_url('register_department.php')) ?>">Register Department</a>
                        </li>
                        <li class="nav-item">
                            <a class="btn btn-sm btn-light ms-lg-2" href="<?= e(app_url('login.php')) ?>">Login</a>
                        </li>
                    <?php else: ?>
                        <li class="nav-item">
                            <span class="navbar-text small text-light-emphasis">
                                <?= e($user['username']) ?> (<?= e(ucfirst($user['role'])) ?>)
                            </span>
                        </li>
                        <li class="nav-item">
                            <a class="btn btn-sm btn-outline-light ms-lg-2" href="<?= e(app_url('logout.php')) ?>">Logout</a>
                        </li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
    </nav>
    <main class="py-4">
        <div class="container">
            <?php if ($currentPage !== 'login.php') render_flash_messages(); ?>
    <?php
}

function render_flash_messages(): void
{
    $success = get_flash('success');
    $error = get_flash('error');

    if ($success) {
        echo '<div class="alert alert-success shadow-sm" role="alert">' . e($success) . '</div>';
    }
    if ($error) {
        echo '<div class="alert alert-danger shadow-sm" role="alert">' . e($error) . '</div>';
    }
}

function render_footer(): void
{
    ?>
        </div>
    </main>
        <script src="<?= e(app_url('assets/js/vendor-offline.js')) ?>"></script>
        <script src="<?= e(app_url('assets/js/viewport-update.js')) ?>"></script>
        <script src="<?= e(app_url('assets/js/main.js')) ?>"></script>
    </body>
    </html>
    <?php
}
