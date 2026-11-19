<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/layout.php';

require_login('admin');

$pdo = db();
$departments = $pdo->query('SELECT id, name, hod, password_plain FROM department ORDER BY name')->fetchAll();

render_header('Departments');
?>
<section class="mb-4 fade-rise">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div>
            <h1 class="h3 mb-1">Departments</h1>
            <p class="text-muted mb-0">View and manage department accounts in one place.</p>
        </div>
        <a class="btn btn-outline-secondary btn-sm" href="<?= e(app_url('admin/dashboard.php')) ?>">Back to dashboard</a>
    </div>
</section>

<section class="row g-4 mb-4 fade-rise" id="register-department">
    <div class="col-12">
        <div class="form-panel">
            <h2 class="h5">Register a Department/Unit</h2>
            <p class="small text-muted">Create a department account for a unit head to submit monthly reports and manage staff evaluations.</p>
            <form method="post" action="<?= e(app_url('actions/admin_manage_department.php')) ?>" class="row g-3">
                <input type="hidden" name="action" value="create">
                <div class="col-md-6"><label class="form-label" for="new-department-name">Department / Unit name</label><input class="form-control" id="new-department-name" name="department_name" maxlength="120" required></div>
                <div class="col-md-6"><label class="form-label" for="new-department-hod">HOD / Unit head</label><input class="form-control" id="new-department-hod" name="hod_name" maxlength="120" required></div>
                <div class="col-md-6"><label class="form-label" for="new-department-username">Login username</label><input class="form-control" id="new-department-username" name="username" autocomplete="username" maxlength="80" required></div>
                <div class="col-md-6"><label class="form-label" for="new-department-email">Email (optional)</label><input class="form-control" id="new-department-email" name="email" type="email" autocomplete="email" maxlength="190"></div>
                <div class="col-md-6"><label class="form-label" for="new-department-password">Password</label><input class="form-control" id="new-department-password" name="password" type="password" autocomplete="new-password" minlength="8" required></div>
                <div class="col-md-6"><label class="form-label" for="new-department-confirm-password">Confirm password</label><input class="form-control" id="new-department-confirm-password" name="confirm_password" type="password" autocomplete="new-password" minlength="8" required></div>
                <div class="col-12"><button class="btn btn-primary" type="submit">Register Department/Unit</button></div>
            </form>
        </div>
    </div>
</section>

<section class="row g-4 mb-4 fade-rise">
    <div class="col-12">
        <div class="form-panel">
            <div class="table-wrap">
                <table class="table table-sm align-middle mb-0">
                    <thead>
                    <tr>
                        <th>Department Name</th>
                        <th>HOD</th>
                        <th>Password</th>
                        <th class="text-end">Action</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (!$departments): ?>
                        <tr><td colspan="4" class="text-muted">No registered departments found.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($departments as $dept): ?>
                        <tr>
                            <td><?= e($dept['name']) ?></td>
                            <td><?= e($dept['hod']) ?></td>
                            <td><code><?= e($dept['password_plain'] ?: 'Not saved') ?></code></td>
                            <td class="text-end">
                                <form method="post" action="<?= e(app_url('actions/admin_manage_department.php')) ?>" class="d-inline">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="department_id" value="<?= e((string) $dept['id']) ?>">
                                    <button type="submit" class="btn btn-outline-danger btn-sm js-confirm" data-confirm="Delete this department and all related data?">Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</section>
<?php
render_footer();
