PRAGMA foreign_keys = ON;

CREATE TABLE IF NOT EXISTS department (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL UNIQUE,
    hod TEXT NOT NULL,
    password_plain TEXT,
    password_hash TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT NOT NULL UNIQUE,
    email TEXT,
    password_hash TEXT NOT NULL,
    role TEXT NOT NULL CHECK (role IN ('admin', 'department')),
    department_id INTEGER NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (department_id) REFERENCES department(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS staff (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    department_id INTEGER NOT NULL,
    name TEXT NOT NULL,
    photo TEXT,
    rank TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (department_id) REFERENCES department(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS submission (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    department_id INTEGER NOT NULL,
    staff_id INTEGER NOT NULL,
    month INTEGER NOT NULL CHECK (month BETWEEN 1 AND 12),
    year INTEGER NOT NULL CHECK (year >= 2000),
    reason_one TEXT NOT NULL DEFAULT '',
    reason_two TEXT NOT NULL DEFAULT '',
    score INTEGER NOT NULL DEFAULT 0 CHECK (score >= 0),
    final_remarks TEXT DEFAULT '',
    status TEXT NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'reviewed', 'winner', 'rejected')),
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (department_id) REFERENCES department(id) ON DELETE CASCADE,
    FOREIGN KEY (staff_id) REFERENCES staff(id) ON DELETE CASCADE,
    UNIQUE (department_id, month, year)
);

CREATE TABLE IF NOT EXISTS performance_evaluation (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    submission_id INTEGER NOT NULL,
    category TEXT NOT NULL,
    criterion_key TEXT NOT NULL,
    criterion_label TEXT NOT NULL,
    score INTEGER NOT NULL CHECK (score >= 1 AND score <= 5),
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (submission_id) REFERENCES submission(id) ON DELETE CASCADE,
    UNIQUE(submission_id, category, criterion_key)
);

CREATE TABLE IF NOT EXISTS evaluation_criteria (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    category TEXT NOT NULL,
    category_label TEXT NOT NULL,
    criterion_key TEXT NOT NULL,
    criterion_label TEXT NOT NULL,
    sort_order INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE IF NOT EXISTS winner (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    staff_id INTEGER NOT NULL UNIQUE,
    submission_id INTEGER UNIQUE,
    image TEXT,
    month INTEGER NOT NULL CHECK (month BETWEEN 1 AND 12),
    year INTEGER NOT NULL CHECK (year >= 2000),
    reasons TEXT NOT NULL,
    selected_by INTEGER,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (staff_id) REFERENCES staff(id) ON DELETE CASCADE,
    FOREIGN KEY (submission_id) REFERENCES submission(id) ON DELETE SET NULL,
    FOREIGN KEY (selected_by) REFERENCES users(id) ON DELETE SET NULL,
    UNIQUE (month, year)
);

CREATE TABLE IF NOT EXISTS gallery (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    image TEXT NOT NULL,
    title TEXT NOT NULL,
    category TEXT NOT NULL DEFAULT 'Other',
    uploaded_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS event_video (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    video TEXT NOT NULL,
    title TEXT NOT NULL,
    uploaded_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS lecture (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    title TEXT NOT NULL,
    file_name TEXT NOT NULL,
    presenter TEXT NOT NULL DEFAULT '',
    specialty TEXT NOT NULL DEFAULT 'General Psychiatry',
    uploaded_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS committee (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    role TEXT NOT NULL,
    photo TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS department_info (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    vision TEXT NOT NULL,
    mission TEXT NOT NULL,
    objectives TEXT NOT NULL,
    image TEXT,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS homepage_settings (
    id INTEGER PRIMARY KEY CHECK (id = 1),
    background_image TEXT,
    app_background_color TEXT,
    homepage_background_color TEXT,
    school_name TEXT,
    school_badge TEXT,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS homepage_background_image (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    image TEXT NOT NULL,
    sort_order INTEGER NOT NULL DEFAULT 0,
    uploaded_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS notifications (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    department_id INTEGER NULL,
    message TEXT NOT NULL,
    is_read INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (department_id) REFERENCES department(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS email_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    recipient_email TEXT NOT NULL,
    subject TEXT NOT NULL,
    body TEXT NOT NULL,
    status TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS submission_schedule (
    id INTEGER PRIMARY KEY CHECK (id = 1),
    opens_at TEXT,
    closes_at TEXT,
    is_enabled INTEGER NOT NULL DEFAULT 1,
    note TEXT,
    updated_by INTEGER,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS department_annual_plan (
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
);

CREATE TABLE IF NOT EXISTS department_annual_plan_item (
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
);

CREATE TABLE IF NOT EXISTS department_monthly_report (
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
);

CREATE TABLE IF NOT EXISTS department_monthly_kpi_update (
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
);

CREATE TABLE IF NOT EXISTS department_monthly_report_review (
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
);

CREATE TABLE IF NOT EXISTS department_annual_event_report (
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
);

CREATE TABLE IF NOT EXISTS department_annual_event_item (
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
);

CREATE INDEX IF NOT EXISTS idx_users_role_username ON users (role, username);
CREATE INDEX IF NOT EXISTS idx_department_name ON department (name);
CREATE INDEX IF NOT EXISTS idx_staff_department_name ON staff (department_id, name);
CREATE INDEX IF NOT EXISTS idx_staff_rank ON staff (rank);
CREATE INDEX IF NOT EXISTS idx_submission_dept_status_date ON submission (department_id, status, year, month, created_at);
CREATE INDEX IF NOT EXISTS idx_submission_staff ON submission (staff_id);
CREATE INDEX IF NOT EXISTS idx_winner_date ON winner (year, month);
CREATE INDEX IF NOT EXISTS idx_gallery_date ON gallery (uploaded_at);
CREATE INDEX IF NOT EXISTS idx_event_video_date ON event_video (uploaded_at);
CREATE INDEX IF NOT EXISTS idx_lecture_date ON lecture (uploaded_at);
CREATE INDEX IF NOT EXISTS idx_committee_name ON committee (name);
CREATE INDEX IF NOT EXISTS idx_submission_schedule_enabled ON submission_schedule (is_enabled);
CREATE INDEX IF NOT EXISTS idx_homepage_background_image_order ON homepage_background_image (sort_order, id);
CREATE INDEX IF NOT EXISTS idx_department_annual_plan_year ON department_annual_plan (department_id, plan_year DESC);
CREATE INDEX IF NOT EXISTS idx_department_annual_plan_item_order ON department_annual_plan_item (plan_id, sort_order, id);
CREATE INDEX IF NOT EXISTS idx_department_monthly_report_period ON department_monthly_report (report_year DESC, report_month DESC, department_id);
CREATE INDEX IF NOT EXISTS idx_monthly_report_review_report ON department_monthly_report_review (report_id, kpi_update_id, id DESC);
CREATE INDEX IF NOT EXISTS idx_department_monthly_kpi_report ON department_monthly_kpi_update (report_id, sort_order, id);
CREATE INDEX IF NOT EXISTS idx_department_annual_event_report_year ON department_annual_event_report (report_year DESC, department_id);
CREATE INDEX IF NOT EXISTS idx_department_annual_event_item_order ON department_annual_event_item (report_id, sort_order, id);
