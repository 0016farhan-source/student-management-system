# Student Management System

A school administration application being developed module by module with PHP, PDO, MySQL, HTML, CSS and JavaScript.

## Current implementation status

The initial implementation provides:

- A PHP application entry point at `public/index.php`.
- Environment configuration loaded from the project-root `.env` file.
- A PDO MySQL connection using native prepared statements.
- Strict, HTTP-only, SameSite session cookie defaults.
- A MySQL 8 schema for accounts and roles, academics, students, teachers, attendance, fees, exams, notices and audit logs.
- A one-time first-administrator setup page, secure login/logout, CSRF checks, and role/permission authorization helpers.
- A responsive, database-backed application entry page with no seeded demo account or fake application data.
- A shared, responsive workspace layout with a profile/navigation sidebar and a dashboard showing live database totals and recent records.
- A redesigned admin workspace with a persistent navigation rail, compact utility bar, clearer dashboard metrics, clean tables and forms, and responsive mobile navigation.
- Authenticated academic setup CRUD for academic years, classes, sections, subjects, and section/teacher subject assignments.
- Permission-controlled teacher directory with create, edit, profile, search, status filter, and archive/restore actions.
- Permission-controlled student directory with profile CRUD, active-year enrollment, class/status/search filters, and enrollment history.
- Permission-controlled daily attendance registers with class/date filters, Present/Absent/Late marking, optional remarks, per-day summaries, and safe updates to existing records.
- Permission-controlled fee management with fee categories and class structures, active-enrollment invoices, partial or full payments, payment history, printable receipts, and billed/collected/outstanding totals.
- Permission-controlled exam scheduling, section-subject setup, mark entry, result publication and exam result reports.
- Permission-controlled notice drafts with role/section audiences, publication and archival.
- Permission-controlled student, attendance, fee-payment and published-result reports with CSV export and spreadsheet-formula-injection protection.
- Administrator-only user access management for account creation, role assignment, password resets, and safe account disabling.
- A matching navy-and-cyan theme across the PHP sign-in, dashboard and management workspace, including responsive layouts and the shared school hero banner.

Dashboard totals and directories read from current database records and show empty states when records are absent. The React app remains a read-only design preview; operational data and actions use the PHP/MySQL portal.

## Dashboard preview

The React app in `frontend/` is a read-only design preview; it does not authenticate users or provide live school records. Start it from that directory with `npm run dev` to preview the dashboard. Its sign-in links open the PHP/MySQL portal in the same tab. After a successful sign-in, the PHP dashboard and management modules use the same navy-and-cyan visual theme while retaining the live data and permissions.

## Requirements

- PHP 8.1 or newer with the `pdo_mysql` extension enabled.
- MySQL 8.0.16 or newer, or MariaDB 10.4 or newer (the schema uses enforced `CHECK` constraints).
- A browser and terminal.

## Local installation

1. Start the MySQL/MariaDB service. With XAMPP, start **MySQL** in the XAMPP Control Panel. Import `database/database.sql` in MySQL Workbench, phpMyAdmin, or from Windows Command Prompt:

   ```cmd
   C:\xampp\mysql\bin\mysql.exe -u root -p < database\database.sql
   ```

   You can also import the file with MySQL Workbench. The schema uses `CREATE TABLE IF NOT EXISTS` and idempotent role/permission seeds; importing it again does not drop application records. Re-import the updated schema on an existing installation to add the `notices.read` permission and accountant fee-management grant.

2. Copy `.env.example` to `.env`, then enter the credentials for your local MySQL account:

   ```powershell
   Copy-Item .env.example .env
   ```

   Update `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, and `DB_PASSWORD` as required. Keep `.env` private; it is excluded from Git.
   `APP_CURRENCY` controls the currency label shown for fee amounts (defaults to `BDT`).

3. Confirm PHP and PDO MySQL are available. For the XAMPP installation path:

   ```powershell
   C:\xampp\php\php.exe -v
   C:\xampp\php\php.exe -m
   ```

   The module list should include `PDO` and `pdo_mysql`.

4. From the repository root, start PHP's local development server:

   ```powershell
   C:\xampp\php\php.exe -S localhost:8000 -t public
   ```

5. Open `http://localhost:8000/`. If there are no user accounts yet, create the first administrator; after the one-time setup, the app requires administrator sign-in. If the database is unavailable, verify the MySQL service, schema import and `.env` settings.

6. Test authentication: submit a short password (it should be rejected), create the initial administrator with a password of 12–72 bytes, confirm the signed-in page shows your account name, and use **Sign out**. The sign-out action requires a CSRF-protected POST request. After signing out, opening `/` should return to sign-in; setup should no longer allow another initial administrator.

7. While signed in, open **Academic setup** and add an academic year, class, section, and subject; optionally add a teacher first so one can be assigned to the section-subject entry. Edit a record and verify the table shows the updated value. Attempt to reuse an existing year/class/section/subject code and confirm the form displays an error. Delete unused entries; MySQL foreign-key constraints prevent deleting records still referenced by school data. Switch the active year and confirm only that year is marked current.

8. Open **Teachers** to create a profile, search by name, employee number, email or phone, and filter by status. Open a profile to view contact details, edit it, or archive it. Archived profiles remain in the database for class/subject history and can be restored.

9. Open **Students** to create a profile. With an active academic year and sections configured, assign the student to a section and enter a roll number and enrollment date. Search by admission number, name, email, phone or guardian; filter by status and active-year class. Open a profile to edit details, inspect enrollment history, archive/restore, or permanently delete a record that has no linked school history. Referenced students cannot be permanently deleted; archive them to preserve attendance, fee and exam records.

10. Open **Attendance**, select an active-year section and date, and record Present, Absent or Late for every active enrolled student. Use **Mark all present** to set the whole roster before correcting exceptions. Save the register, refresh the page to confirm records persist, then change a status and save again to verify the existing date's record is updated rather than duplicated. The date must fall within the active academic year's configured range; unmarked students remain unrecorded until saved.

11. Open **Fees** to add a fee category, create a class/category/frequency fee structure for the active year, and issue an invoice to a currently enrolled student in the matching class. Open the invoice to record one or more payments using the supported methods; each successful payment has an individual receipt and remains in the invoice payment history. Confirm that overpayment is rejected and that billed, collected, outstanding, and invoice status totals update from the database. Use **Print receipt** to print or save a receipt as PDF.

12. In **Exams & results**, create a draft within an academic year's dates, add assigned section subjects with maximum/pass marks, enter marks for every active enrolled student, then publish. Publication is blocked while any active student's marks are missing; published result data is available in **Reports**.

13. In **School notices**, create a draft, choose everyone, a role, or a section as the audience, optionally set a future expiry, review/edit the draft and publish or archive it.

14. In **Reports**, select a student, attendance, fee-payment or published-result report, refine the academic-year/date/exam filters, and export CSV. CSV export is capped at 10,000 rows; the on-screen preview shows up to 500.

If PHP is already on your `PATH`, you can use `php` instead of `C:\xampp\php\php.exe`.

For a deployed environment, configure the web server document root to the project's `public/` directory and enable HTTPS. Never expose the project root, `.env`, or `storage/` as public web content.

## Schema overview

The schema models role-based accounts and permissions, academic years, classes, sections, teachers, students and enrollments. Attendance, fee invoices and payments, exams and marks, grade scales, notices, and audit history reference their related records with foreign keys.

## Authentication and authorization

`public/setup-admin.php` can create an account only while the `users` table is empty. It serializes concurrent first-account setup with a MySQL advisory lock, hashes the password with `password_hash()`, and assigns the seeded `admin` role. Login uses `password_verify()`, checks for active accounts, updates the last-login timestamp, and regenerates the session ID. Login, initial setup, logout, academic, teacher, student, attendance, fees, exams, notices, reports and user-management forms use CSRF tokens. Reusable helpers in `app/auth.php` provide login checks and permission checks for protected modules.

## Development sequence

1. Foundation, database schema, and secure administrator authentication (implemented).
2. Academic year, class, section and subject management (implemented).
3. Teacher management (implemented).
4. Student management and enrollment (implemented).
5. Attendance and fees (implemented).
6. Exam scheduling, marks and published result reports (implemented; school-specific letter-grade scales are not configured by default).
7. Notice management and filtered CSV reports (implemented).
8. Administrator-only user creation, role assignment, password resets and account disabling (implemented).

Parent/student self-service accounts, configurable letter-grade scales and report-card layouts, database-backed audit-event recording, and production deployment hardening remain separate follow-up scope; the application does not claim those features are already present.

The UI displays a © copyright attribution to Farhan Ahmed. Confirm the name and ownership attribution are correct for your distribution before publishing the system.
