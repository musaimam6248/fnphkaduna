<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $pdo = new PDO(
        DB_DSN,
        DB_USER ?: null,
        DB_PASS ?: null,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );

    if (str_starts_with(DB_DSN, 'sqlite:')) {
        $pdo->exec('PRAGMA foreign_keys = ON');
    }

    initialize_database($pdo);

    return $pdo;
}

function initialize_database(PDO $pdo): void
{
    static $initialized = false;

    if ($initialized) {
        return;
    }

    $initialized = true;

    $schemaExists = true;
    try {
        $pdo->query('SELECT 1 FROM users LIMIT 1');
    } catch (Throwable $exception) {
        $schemaExists = false;
    }

    if (!$schemaExists) {
        $schemaPath = BASE_PATH . '/database/schema.sql';
        $schemaSql = file_get_contents($schemaPath);
        if ($schemaSql === false) {
            throw new RuntimeException('Unable to read database schema file.');
        }
        $pdo->exec($schemaSql);
    }

    ensure_schema_compatibility($pdo);
    seed_default_records($pdo);
}

function seed_default_records(PDO $pdo): void
{
    $adminExists = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
    if ($adminExists === 0) {
        if (DEFAULT_ADMIN_PASSWORD === '') {
            throw new RuntimeException('Set ADMIN_DEFAULT_PASSWORD before initializing the database.');
        }
        $stmt = $pdo->prepare(
            'INSERT INTO users (username, email, password_hash, role) VALUES (:username, :email, :password_hash, :role)'
        );
        $stmt->execute([
            ':username' => DEFAULT_ADMIN_USERNAME,
            ':email' => 'admin@example.com',
            ':password_hash' => password_hash(DEFAULT_ADMIN_PASSWORD, PASSWORD_DEFAULT),
            ':role' => 'admin',
        ]);
    }

    $infoExists = (int) $pdo->query('SELECT COUNT(*) FROM department_info')->fetchColumn();
    if ($infoExists === 0) {
        $stmt = $pdo->prepare(
            'INSERT INTO department_info (vision, mission, objectives, image) VALUES (:vision, :mission, :objectives, :image)'
        );
        $stmt->execute([
            ':vision' => 'To build a high-performing and values-driven institution.',
            ':mission' => 'To recognize excellence, integrity, and teamwork across every department.',
            ':objectives' => 'Celebrate monthly impact, inspire healthy competition, and promote measurable growth.',
            ':image' => null,
        ]);
    }

    $scheduleExists = (int) $pdo->query('SELECT COUNT(*) FROM submission_schedule')->fetchColumn();
    if ($scheduleExists === 0) {
        $openTime = (new DateTimeImmutable('first day of this month 00:00:00'))->format('Y-m-d H:i:s');
        $closeTime = (new DateTimeImmutable('last day of this month 23:59:59'))->format('Y-m-d H:i:s');

        $stmt = $pdo->prepare(
            'INSERT INTO submission_schedule (id, opens_at, closes_at, is_enabled, note)
             VALUES (1, :opens_at, :closes_at, 1, :note)'
        );
        $stmt->execute([
            ':opens_at' => $openTime,
            ':closes_at' => $closeTime,
            ':note' => 'Default monthly submission window',
        ]);
    }
}

function ensure_schema_compatibility(PDO $pdo): void
{
    if (!str_starts_with(DB_DSN, 'sqlite:')) {
        return;
    }

    $winnerColumns = $pdo->query('PRAGMA table_info(winner)')->fetchAll();
    $winnerColumnNames = array_map(static fn($column) => $column['name'] ?? '', $winnerColumns);

    if (!in_array('image', $winnerColumnNames, true)) {
        $pdo->exec('ALTER TABLE winner ADD COLUMN image TEXT');
    }

    $departmentColumns = $pdo->query('PRAGMA table_info(department)')->fetchAll();
    $departmentColumnNames = array_map(static fn($column) => $column['name'] ?? '', $departmentColumns);
    if (!in_array('password_plain', $departmentColumnNames, true)) {
        $pdo->exec('ALTER TABLE department ADD COLUMN password_plain TEXT');
    }

    $departmentInfoColumns = $pdo->query('PRAGMA table_info(department_info)')->fetchAll();
    $departmentInfoColumnNames = array_map(static fn($column) => $column['name'] ?? '', $departmentInfoColumns);
    if (!in_array('image', $departmentInfoColumnNames, true)) {
        $pdo->exec('ALTER TABLE department_info ADD COLUMN image TEXT');
    }

    $homepageSettingsExists = (int) $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='homepage_settings'")->fetchColumn();
    if ($homepageSettingsExists === 0) {
        $pdo->exec(
            "CREATE TABLE homepage_settings (
                id INTEGER PRIMARY KEY CHECK (id = 1),
                background_image TEXT,
                app_background_color TEXT,
                homepage_background_color TEXT,
                school_name TEXT,
                school_badge TEXT,
                updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )"
        );
    }

    $homepageSettingsColumns = $pdo->query('PRAGMA table_info(homepage_settings)')->fetchAll();
    $homepageSettingsColumnNames = array_map(static fn($column) => $column['name'] ?? '', $homepageSettingsColumns);
    if (!in_array('app_background_color', $homepageSettingsColumnNames, true)) {
        $pdo->exec('ALTER TABLE homepage_settings ADD COLUMN app_background_color TEXT');
    }
    if (!in_array('homepage_background_color', $homepageSettingsColumnNames, true)) {
        $pdo->exec('ALTER TABLE homepage_settings ADD COLUMN homepage_background_color TEXT');
    }
    if (!in_array('school_name', $homepageSettingsColumnNames, true)) {
        $pdo->exec('ALTER TABLE homepage_settings ADD COLUMN school_name TEXT');
    }
    if (!in_array('school_badge', $homepageSettingsColumnNames, true)) {
        $pdo->exec('ALTER TABLE homepage_settings ADD COLUMN school_badge TEXT');
    }

    $homepageSettingsCount = (int) $pdo->query('SELECT COUNT(*) FROM homepage_settings WHERE id = 1')->fetchColumn();
    if ($homepageSettingsCount === 0) {
        $pdo->exec('INSERT INTO homepage_settings (id, background_image) VALUES (1, NULL)');
    }

    $homepageBackgroundTableExists = (int) $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='homepage_background_image'")->fetchColumn();
    if ($homepageBackgroundTableExists === 0) {
        $pdo->exec(
            "CREATE TABLE homepage_background_image (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                image TEXT NOT NULL,
                sort_order INTEGER NOT NULL DEFAULT 0,
                uploaded_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )"
        );
    }
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_homepage_background_image_order ON homepage_background_image (sort_order, id)');

    $legacyHomepageImage = $pdo->query('SELECT background_image FROM homepage_settings WHERE id = 1 LIMIT 1')->fetchColumn();
    $homepageImageCount = (int) $pdo->query('SELECT COUNT(*) FROM homepage_background_image')->fetchColumn();
    if ($homepageImageCount === 0 && is_string($legacyHomepageImage) && $legacyHomepageImage !== '') {
        $legacyStmt = $pdo->prepare('INSERT INTO homepage_background_image (image, sort_order) VALUES (:image, 1)');
        $legacyStmt->execute([':image' => $legacyHomepageImage]);
        $pdo->exec('UPDATE homepage_settings SET background_image = NULL WHERE id = 1');
    }

    $scheduleExists = (int) $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='submission_schedule'")->fetchColumn();
    if ($scheduleExists === 0) {
        $pdo->exec(
            "CREATE TABLE submission_schedule (
                id INTEGER PRIMARY KEY CHECK (id = 1),
                opens_at TEXT,
                closes_at TEXT,
                is_enabled INTEGER NOT NULL DEFAULT 1,
                note TEXT,
                updated_by INTEGER,
                updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
            )"
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_submission_schedule_enabled ON submission_schedule (is_enabled)');
    }

    $submissionTableSql = (string) $pdo->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='submission'")->fetchColumn();
    $submissionScoreColumn = $pdo->query("PRAGMA table_info(submission)")->fetchAll();
    $submissionScoreColumnNames = array_map(static fn($col) => $col['name'] ?? '', $submissionScoreColumn);

    if (str_contains($submissionTableSql, 'score <= 100')) {
        $pdo->exec('PRAGMA foreign_keys = OFF');
        $pdo->exec('BEGIN TRANSACTION');
        $pdo->exec('CREATE TABLE submission_new (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            department_id INTEGER NOT NULL,
            staff_id INTEGER NOT NULL,
            month INTEGER NOT NULL CHECK (month BETWEEN 1 AND 12),
            year INTEGER NOT NULL CHECK (year >= 2000),
            reason_one TEXT NOT NULL DEFAULT "",
            reason_two TEXT NOT NULL DEFAULT "",
            score INTEGER NOT NULL DEFAULT 0 CHECK (score >= 0),
            final_remarks TEXT DEFAULT "",
            status TEXT NOT NULL DEFAULT "pending" CHECK (status IN ("pending", "reviewed", "winner", "rejected")),
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (department_id) REFERENCES department(id) ON DELETE CASCADE,
            FOREIGN KEY (staff_id) REFERENCES staff(id) ON DELETE CASCADE,
            UNIQUE (department_id, month, year)
        )');
        $hasFinalRemarks = in_array('final_remarks', $submissionScoreColumnNames, true);
        $remarksSelect = $hasFinalRemarks ? 'final_remarks' : '"" AS final_remarks';
        $pdo->exec("INSERT INTO submission_new (id, department_id, staff_id, month, year, reason_one, reason_two, score, final_remarks, status, created_at)
                    SELECT id, department_id, staff_id, month, year, reason_one, reason_two, score, {$remarksSelect}, status, created_at FROM submission");
        $pdo->exec('DROP TABLE submission');
        $pdo->exec('ALTER TABLE submission_new RENAME TO submission');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_submission_dept_status_date ON submission (department_id, status, year, month, created_at)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_submission_staff ON submission (staff_id)');
        $pdo->exec('COMMIT');
        $pdo->exec('PRAGMA foreign_keys = ON');
    } else {
        if (!in_array('score', $submissionScoreColumnNames, true)) {
            $pdo->exec('ALTER TABLE submission ADD COLUMN score INTEGER NOT NULL DEFAULT 0');
        }
        if (!in_array('final_remarks', $submissionScoreColumnNames, true)) {
            $pdo->exec('ALTER TABLE submission ADD COLUMN final_remarks TEXT DEFAULT ""');
        }
    }

    $evalTableExists = (int) $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='performance_evaluation'")->fetchColumn();
    if ($evalTableExists === 0) {
        $pdo->exec(
            "CREATE TABLE performance_evaluation (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                submission_id INTEGER NOT NULL,
                category TEXT NOT NULL,
                criterion_key TEXT NOT NULL,
                criterion_label TEXT NOT NULL,
                score INTEGER NOT NULL CHECK (score >= 1 AND score <= 5),
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (submission_id) REFERENCES submission(id) ON DELETE CASCADE,
                UNIQUE(submission_id, category, criterion_key)
            )"
        );
    }

    $evalCriteriaTableExists = (int) $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='evaluation_criteria'")->fetchColumn();
    if ($evalCriteriaTableExists === 0) {
        $pdo->exec(
            "CREATE TABLE evaluation_criteria (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                category TEXT NOT NULL,
                category_label TEXT NOT NULL,
                criterion_key TEXT NOT NULL,
                criterion_label TEXT NOT NULL,
                sort_order INTEGER NOT NULL DEFAULT 0
            )"
        );
    }

    $eventVideoExists = (int) $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='event_video'")->fetchColumn();
    if ($eventVideoExists === 0) {
        $pdo->exec(
            "CREATE TABLE event_video (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                video TEXT NOT NULL,
                title TEXT NOT NULL,
                uploaded_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )"
        );
    }
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_event_video_date ON event_video (uploaded_at)');

    $lectureTableExists = (int) $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='lecture'")->fetchColumn();
    if ($lectureTableExists === 0) {
        $pdo->exec(
            "CREATE TABLE lecture (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                title TEXT NOT NULL,
                file_name TEXT NOT NULL,
                uploaded_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )"
        );
    }
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_lecture_date ON lecture (uploaded_at)');

    $lectureColumns = $pdo->query('PRAGMA table_info(lecture)')->fetchAll();
    $lectureColumnNames = array_map(static fn($column) => $column['name'] ?? '', $lectureColumns);
    if (!in_array('presenter', $lectureColumnNames, true)) {
        $pdo->exec("ALTER TABLE lecture ADD COLUMN presenter TEXT NOT NULL DEFAULT ''");
    }
    if (!in_array('specialty', $lectureColumnNames, true)) {
        $pdo->exec("ALTER TABLE lecture ADD COLUMN specialty TEXT NOT NULL DEFAULT 'General Psychiatry'");
    }

    $galleryTableExists = (int) $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='gallery'")->fetchColumn();
    if ($galleryTableExists === 0) {
        $pdo->exec("CREATE TABLE gallery (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            image TEXT NOT NULL,
            title TEXT NOT NULL,
            category TEXT NOT NULL DEFAULT 'Other',
            uploaded_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )");
    }
    $galleryColumns = $pdo->query('PRAGMA table_info(gallery)')->fetchAll();
    $galleryColumnNames = array_map(static fn($column) => $column['name'] ?? '', $galleryColumns);
    if (!in_array('category', $galleryColumnNames, true)) {
        $pdo->exec("ALTER TABLE gallery ADD COLUMN category TEXT NOT NULL DEFAULT 'Other'");
    }

    $savedReportTableExists = (int) $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='saved_report'")->fetchColumn();
    if ($savedReportTableExists === 0) {
        $pdo->exec(
            "CREATE TABLE saved_report (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                month INTEGER NOT NULL,
                year INTEGER NOT NULL,
                format TEXT NOT NULL,
                file_path TEXT NOT NULL,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )"
        );
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS department_annual_plan (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            department_id INTEGER NOT NULL,
            plan_year INTEGER NOT NULL CHECK (plan_year >= 2000),
            period_from TEXT NOT NULL,
            period_to TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT 'draft' CHECK (status IN ('draft', 'submitted')),
            submitted_at TEXT,
            edit_approved_at TEXT,
            edit_approved_by INTEGER,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (department_id) REFERENCES department(id) ON DELETE CASCADE,
            UNIQUE (department_id, plan_year)
        )"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS department_annual_plan_item (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            plan_id INTEGER NOT NULL,
            sort_order INTEGER NOT NULL DEFAULT 0,
            kra TEXT NOT NULL,
            smart_objective TEXT NOT NULL,
            kpi TEXT NOT NULL,
            target TEXT NOT NULL,
            target_value REAL,
            unit TEXT NOT NULL DEFAULT '',
            direction TEXT NOT NULL DEFAULT 'higher',
            verification TEXT NOT NULL DEFAULT '',
            FOREIGN KEY (plan_id) REFERENCES department_annual_plan(id) ON DELETE CASCADE
        )"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS department_monthly_report (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            department_id INTEGER NOT NULL,
            report_month INTEGER NOT NULL CHECK (report_month BETWEEN 1 AND 12),
            report_year INTEGER NOT NULL CHECK (report_year >= 2000),
            period_from TEXT NOT NULL,
            period_to TEXT NOT NULL,
            narrative_html TEXT NOT NULL DEFAULT '',
            attachment_filename TEXT,
            attachment_original_name TEXT,
            status TEXT NOT NULL DEFAULT 'submitted' CHECK (status IN ('submitted', 'reviewed')),
            review_status TEXT NOT NULL DEFAULT 'pending',
            submitted_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            reviewed_at TEXT,
            edit_approved_at TEXT,
            edit_approved_by INTEGER,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (department_id) REFERENCES department(id) ON DELETE CASCADE,
            UNIQUE (department_id, report_month, report_year)
        )"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS department_monthly_kpi_update (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            report_id INTEGER NOT NULL,
            annual_plan_item_id INTEGER,
            sort_order INTEGER NOT NULL DEFAULT 0,
            kra TEXT NOT NULL,
            smart_objective TEXT NOT NULL,
            kpi TEXT NOT NULL,
            target TEXT NOT NULL,
            target_value REAL,
            unit TEXT NOT NULL DEFAULT '',
            direction TEXT NOT NULL DEFAULT 'higher',
            achievement TEXT NOT NULL,
            achievement_percent REAL,
            actual_value REAL,
            variance_value REAL,
            status TEXT NOT NULL DEFAULT 'on_track',
            evidence TEXT NOT NULL DEFAULT '',
            achievement_attachment_filename TEXT,
            achievement_attachment_original_name TEXT,
            evidence_attachment_filename TEXT,
            evidence_attachment_original_name TEXT,
            commentary TEXT NOT NULL DEFAULT '',
            next_step TEXT NOT NULL DEFAULT '',
            FOREIGN KEY (report_id) REFERENCES department_monthly_report(id) ON DELETE CASCADE,
            FOREIGN KEY (annual_plan_item_id) REFERENCES department_annual_plan_item(id) ON DELETE SET NULL
        )"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS department_monthly_report_review (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            report_id INTEGER NOT NULL,
            kpi_update_id INTEGER,
            admin_id INTEGER NOT NULL,
            action TEXT NOT NULL CHECK (action IN ('comment', 'returned', 'reviewed')),
            comment TEXT NOT NULL DEFAULT '',
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (report_id) REFERENCES department_monthly_report(id) ON DELETE CASCADE,
            FOREIGN KEY (kpi_update_id) REFERENCES department_monthly_kpi_update(id) ON DELETE CASCADE,
            FOREIGN KEY (admin_id) REFERENCES users(id) ON DELETE RESTRICT
        )"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS department_annual_event_report (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            department_id INTEGER NOT NULL,
            report_year INTEGER NOT NULL CHECK (report_year >= 2000),
            status TEXT NOT NULL DEFAULT 'draft' CHECK (status IN ('draft', 'submitted', 'reviewed')),
            attachment_filename TEXT,
            attachment_original_name TEXT,
            submitted_at TEXT,
            reviewed_at TEXT,
            edit_approved_at TEXT,
            edit_approved_by INTEGER,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (department_id) REFERENCES department(id) ON DELETE CASCADE,
            FOREIGN KEY (edit_approved_by) REFERENCES users(id) ON DELETE SET NULL,
            UNIQUE (department_id, report_year)
        )"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS department_annual_event_item (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            report_id INTEGER NOT NULL,
            sort_order INTEGER NOT NULL DEFAULT 0,
            event_name TEXT NOT NULL,
            event_date_from TEXT NOT NULL,
            event_date_to TEXT NOT NULL,
            purpose TEXT NOT NULL,
            planned_activities TEXT NOT NULL,
            responsible_officer TEXT NOT NULL,
            expected_outcomes TEXT NOT NULL,
            evidence TEXT NOT NULL DEFAULT '',
            FOREIGN KEY (report_id) REFERENCES department_annual_event_report(id) ON DELETE CASCADE
        )"
    );
    foreach (['department_annual_plan', 'department_monthly_report'] as $approvalTable) {
        $approvalColumns = $pdo->query('PRAGMA table_info(' . $approvalTable . ')')->fetchAll();
        $approvalColumnNames = array_map(static fn($column) => $column['name'] ?? '', $approvalColumns);
        if (!in_array('edit_approved_at', $approvalColumnNames, true)) {
            $pdo->exec('ALTER TABLE ' . $approvalTable . ' ADD COLUMN edit_approved_at TEXT');
        }
        if (!in_array('edit_approved_by', $approvalColumnNames, true)) {
            $pdo->exec('ALTER TABLE ' . $approvalTable . ' ADD COLUMN edit_approved_by INTEGER');
        }
    }
    $monthlyReportColumns = $pdo->query('PRAGMA table_info(department_monthly_report)')->fetchAll();
    $monthlyReportColumnNames = array_map(static fn($column) => $column['name'] ?? '', $monthlyReportColumns);
    if (!in_array('review_status', $monthlyReportColumnNames, true)) {
        $pdo->exec("ALTER TABLE department_monthly_report ADD COLUMN review_status TEXT NOT NULL DEFAULT 'pending'");
        $pdo->exec("UPDATE department_monthly_report SET review_status = 'reviewed' WHERE status = 'reviewed'");
    }
    $newColumnsByTable = [
        'department_annual_plan_item' => [
            'target_value' => 'REAL',
            'unit' => "TEXT NOT NULL DEFAULT ''",
            'direction' => "TEXT NOT NULL DEFAULT 'higher'",
        ],
        'department_monthly_kpi_update' => [
            'actual_value' => 'REAL',
            'variance_value' => 'REAL',
            'target_value' => 'REAL',
            'unit' => "TEXT NOT NULL DEFAULT ''",
            'direction' => "TEXT NOT NULL DEFAULT 'higher'",
            'status' => "TEXT NOT NULL DEFAULT 'on_track'",
            'commentary' => "TEXT NOT NULL DEFAULT ''",
            'next_step' => "TEXT NOT NULL DEFAULT ''",
            'achievement_attachment_filename' => 'TEXT',
            'achievement_attachment_original_name' => 'TEXT',
            'evidence_attachment_filename' => 'TEXT',
            'evidence_attachment_original_name' => 'TEXT',
        ],
    ];
    foreach ($newColumnsByTable as $tableName => $newColumns) {
        $columnInfo = $pdo->query('PRAGMA table_info(' . $tableName . ')')->fetchAll();
        $columnNames = array_map(static fn($column) => $column['name'] ?? '', $columnInfo);
        foreach ($newColumns as $columnName => $columnDefinition) {
            if (!in_array($columnName, $columnNames, true)) {
                $pdo->exec('ALTER TABLE ' . $tableName . ' ADD COLUMN ' . $columnName . ' ' . $columnDefinition);
                if ($tableName === 'department_monthly_kpi_update' && $columnName === 'status') {
                    $pdo->exec("UPDATE department_monthly_kpi_update SET status = CASE WHEN COALESCE(achievement_percent, 0) >= 100 THEN 'on_track' WHEN COALESCE(achievement_percent, 0) >= 80 THEN 'at_risk' ELSE 'off_track' END");
                }
            }
        }
    }
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_department_annual_plan_year ON department_annual_plan (department_id, plan_year DESC)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_department_annual_plan_item_order ON department_annual_plan_item (plan_id, sort_order, id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_department_monthly_report_period ON department_monthly_report (report_year DESC, report_month DESC, department_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_department_monthly_kpi_report ON department_monthly_kpi_update (report_id, sort_order, id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_monthly_report_review_report ON department_monthly_report_review (report_id, kpi_update_id, id DESC)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_department_annual_event_report_year ON department_annual_event_report (report_year DESC, department_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_department_annual_event_item_order ON department_annual_event_item (report_id, sort_order, id)');
}
