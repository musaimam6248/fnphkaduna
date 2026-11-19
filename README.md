# FNSTM Staff Excellence Portal

Full PHP web application for:
- Public homepage (first display)
- Admin dashboard
- Department dashboard
- Image management with resizing
- Role-based authentication
- Analytics charts
- Excel/PDF/print reporting

## Stack
- PHP 8+
- SQLite (default via PDO)
- Offline local UI assets (Bootstrap-like, chart-lite, lightbox-lite)

## Implemented Features

### Homepage (`index.php`)
- Navbar
- Current winner (image + name + reasons)
- Submission window display (open and close time set by admin)
- Past winners carousel
- Event gallery with lightbox
- Event video highlights (admin-uploaded short clips)
- Committee members cards
- Department info (vision, mission, objectives)
- Analytics charts
- All images pulled dynamically from database file names
- Mobile-responsive layout

### Admin Dashboard (`admin/dashboard.php`)
- View all department submissions
- Search/filter/pagination
- Select monthly winner with reasons
- Set submission open/close time window for departments
- Manage committee members
- Upload/manage gallery images
- Upload/manage event video clips
- Analytics charts
- Export reports (Excel-compatible CSV + PDF)
- Print report
- Send notifications/reminders (+ email logging)
- Update department vision/mission/objectives

### Department Dashboard (`department/dashboard.php`)
- Staff submission form (name, rank, photo)
- Selection submission (choose staff + 2 reasons)
- Selection submission is allowed only during the admin-defined open window
- Submission history
- Notifications from admin

### Department Annual Event Reports and Monthly KPI Reports
- Each department can submit an annual event workflow with event dates, purpose, planned activities, responsible officer, expected outcomes, evidence, and an optional supporting attachment.
- Each department can save or submit an annual KPI target plan with KRA, SMART objectives, KPI, target, and evidence/verification source. Monthly KPI reports use this plan as their reference.
- Monthly KPI updates are linked to the submitted annual plan and capture achievement, percentage, and evidence for each KPI.
- Monthly reports support formatted narrative text or an attached PDF, Word, Excel, PowerPoint, or image file (up to 50 MB).
- Departments can reopen and update a month’s report; admins can review submissions and print annual plans and monthly reports.
- Admins can print staff selections by department and reporting period with each selected staff member’s photo.

### Image Pipeline
- Upload and save images to:
  - `uploads/staff`
  - `uploads/gallery`
  - `uploads/committee`
  - `uploads/winners`
- Store file names in database
- Resize images for consistency (GD-enabled PHP)
- Displayed on homepage and dashboards

### Auth & Security
- Login page for admin and departments
- Department registration (HOD, password confirmation)
- Forgot password for department login using HOD name as reset key
- Password hashing (`password_hash`)
- Session management
- Role-based access control

### Database
Schema in `database/schema.sql`:
- `users`
- `department`
- `staff`
- `submission`
- `winner`
- `gallery`
- `event_video`
- `committee`
- `department_info`
- `notifications`
- `email_logs`
- `submission_schedule`

Includes relationships and indexes for search/filter/pagination performance.

## Quick Start
1. Ensure PHP 8+ with extensions:
   - `pdo_sqlite`
   - `gd` (recommended for resize)
2. From project root, run:
   ```bash
   # PowerShell: set a strong first-admin password before creating the database
   $env:ADMIN_DEFAULT_PASSWORD = 'choose-a-strong-password'
   php database/migrate.php
   php -S localhost:8000
   ```
3. Open:
   - `http://localhost:8000/`

## Offline Start (Windows)
Use the included launcher to run fully offline without needing internet access.

1. Double-click `start-offline.bat`
2. Or run:
   ```powershell
   .\start-offline.ps1
   ```
3. Open:
   - `http://127.0.0.1:8000/`

Notes:
- The script auto-finds `php.exe` (or uses `PHP_EXE` env var if set).
- It loads required extensions (`pdo_sqlite`, `sqlite3`, `gd`, `mbstring`), runs migration, and starts the server.
- If port `8000` is busy, run:
  ```powershell
  .\start-offline.ps1 -Port 8001
  ```

## Online Update (Windows)
Use this before starting online mode to apply DB/schema updates and verify required PHP extensions.

1. Double-click `online-update.bat`
2. Or run:
   ```powershell
   .\online-update.ps1
   ```

Then start online server:
```powershell
.\start-online.ps1
```
## Online Start (Windows)
Use this to expose the app on your local network (LAN).

1. Double-click `start-online.bat`
2. Or run:
   ```powershell
   .\start-online.ps1
   ```
3. Open:
   - `http://localhost:8000/`
   - `http://<your-lan-ip>:8000/` (from another device on same network)

Notes:
- This mode binds to `0.0.0.0` so other devices on your network can access it.
- If port `8000` is busy, run:
  ```powershell
  .\start-online.ps1 -Port 8001
  ```

## Free PHP Hosting (School Demo)
This app can run on a PHP host that supports PDO SQLite and writable files. GitHub Pages cannot run the PHP backend. For hosts that keep the project inside a public `htdocs` folder:

1. Create a free PHP hosting account and a site/subdomain.
2. Copy `config/local.php.example` to `config/local.php`, set a unique admin password, and upload the app files to the site's web root. Do not upload `database/app.db`, session files, local logs, or real staff data.
3. Keep the included `.htaccess` files in `config`, `database`, and private upload folders. They prevent direct web access to setup settings, the SQLite database, and private attachments.
4. Make `database` and `uploads` writable by PHP, then visit the site. The app will initialize a fresh database and create the initial `admin` account.

Free hosts can change limits and may not provide backups. Back up the database and uploads separately; this is suitable for a school demonstration, not sensitive production records.
## Admin Setup
The database seed creates the initial admin account as `admin` unless `ADMIN_DEFAULT_USERNAME` is set. Set `ADMIN_DEFAULT_PASSWORD` before the first database initialization; the application does not use a shared default password. Choose a strong password and keep it private.

You can also configure these environment variables:
- `ADMIN_DEFAULT_USERNAME`
- `ADMIN_DEFAULT_PASSWORD`
- `DB_DSN` (for custom database)
- `DB_USER`
- `DB_PASS`
- `APP_BASE_URL`
- `APP_TIMEZONE`


