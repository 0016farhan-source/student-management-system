CREATE DATABASE IF NOT EXISTS student_management
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE student_management;

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS roles (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(50) NOT NULL,
  description VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_roles_name (name)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS permissions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code VARCHAR(100) NOT NULL,
  description VARCHAR(255) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_permissions_code (code)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS users (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(150) NOT NULL,
  email VARCHAR(190) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  status ENUM('active', 'disabled') NOT NULL DEFAULT 'active',
  last_login_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_email (email),
  KEY idx_users_status (status)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS user_roles (
  user_id BIGINT UNSIGNED NOT NULL,
  role_id BIGINT UNSIGNED NOT NULL,
  assigned_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, role_id),
  CONSTRAINT fk_user_roles_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT fk_user_roles_role FOREIGN KEY (role_id) REFERENCES roles (id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS role_permissions (
  role_id BIGINT UNSIGNED NOT NULL,
  permission_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (role_id, permission_id),
  CONSTRAINT fk_role_permissions_role FOREIGN KEY (role_id) REFERENCES roles (id) ON DELETE CASCADE,
  CONSTRAINT fk_role_permissions_permission FOREIGN KEY (permission_id) REFERENCES permissions (id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS academic_years (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(50) NOT NULL,
  starts_on DATE NOT NULL,
  ends_on DATE NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_academic_years_name (name),
  CONSTRAINT chk_academic_year_dates CHECK (ends_on >= starts_on)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS classes (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(100) NOT NULL,
  grade_level SMALLINT UNSIGNED NULL,
  description VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_classes_name (name)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS teachers (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NULL,
  employee_number VARCHAR(50) NOT NULL,
  first_name VARCHAR(100) NOT NULL,
  last_name VARCHAR(100) NOT NULL,
  email VARCHAR(190) NULL,
  phone VARCHAR(40) NULL,
  hire_date DATE NULL,
  status ENUM('active', 'inactive', 'archived') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_teachers_employee_number (employee_number),
  UNIQUE KEY uq_teachers_user (user_id),
  KEY idx_teachers_name (last_name, first_name),
  CONSTRAINT fk_teachers_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS sections (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  class_id BIGINT UNSIGNED NOT NULL,
  academic_year_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(50) NOT NULL,
  room VARCHAR(50) NULL,
  homeroom_teacher_id BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_sections_class_year_name (class_id, academic_year_id, name),
  UNIQUE KEY uq_sections_id_class_year (id, class_id, academic_year_id),
  KEY idx_sections_academic_year (academic_year_id),
  CONSTRAINT fk_sections_class FOREIGN KEY (class_id) REFERENCES classes (id) ON DELETE RESTRICT,
  CONSTRAINT fk_sections_academic_year FOREIGN KEY (academic_year_id) REFERENCES academic_years (id) ON DELETE RESTRICT,
  CONSTRAINT fk_sections_teacher FOREIGN KEY (homeroom_teacher_id) REFERENCES teachers (id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS students (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NULL,
  admission_number VARCHAR(50) NOT NULL,
  first_name VARCHAR(100) NOT NULL,
  last_name VARCHAR(100) NOT NULL,
  date_of_birth DATE NULL,
  gender ENUM('female', 'male', 'other', 'unspecified') NOT NULL DEFAULT 'unspecified',
  email VARCHAR(190) NULL,
  phone VARCHAR(40) NULL,
  guardian_name VARCHAR(150) NULL,
  guardian_phone VARCHAR(40) NULL,
  address TEXT NULL,
  admitted_on DATE NOT NULL,
  status ENUM('active', 'inactive', 'graduated', 'archived') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_students_admission_number (admission_number),
  UNIQUE KEY uq_students_user (user_id),
  KEY idx_students_name (last_name, first_name),
  KEY idx_students_status (status),
  CONSTRAINT fk_students_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS student_enrollments (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  student_id BIGINT UNSIGNED NOT NULL,
  section_id BIGINT UNSIGNED NOT NULL,
  class_id BIGINT UNSIGNED NOT NULL,
  academic_year_id BIGINT UNSIGNED NOT NULL,
  roll_number VARCHAR(30) NOT NULL,
  enrolled_on DATE NOT NULL,
  status ENUM('active', 'transferred', 'completed', 'withdrawn') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_enrollment_student_year (student_id, academic_year_id),
  UNIQUE KEY uq_enrollment_section_roll (section_id, roll_number),
  UNIQUE KEY uq_enrollment_id_section (id, section_id),
  KEY idx_enrollments_section_status (section_id, status),
  KEY idx_enrollments_year_status (academic_year_id, status),
  CONSTRAINT fk_enrollments_student FOREIGN KEY (student_id) REFERENCES students (id) ON DELETE RESTRICT,
  CONSTRAINT fk_enrollments_section_class_year FOREIGN KEY (section_id, class_id, academic_year_id)
    REFERENCES sections (id, class_id, academic_year_id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS subjects (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code VARCHAR(30) NOT NULL,
  name VARCHAR(120) NOT NULL,
  description VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_subjects_code (code),
  UNIQUE KEY uq_subjects_name (name)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS class_subjects (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  section_id BIGINT UNSIGNED NOT NULL,
  subject_id BIGINT UNSIGNED NOT NULL,
  teacher_id BIGINT UNSIGNED NULL,
  weekly_periods TINYINT UNSIGNED NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_class_subject (section_id, subject_id),
  UNIQUE KEY uq_class_subject_id_section (id, section_id),
  CONSTRAINT fk_class_subjects_section FOREIGN KEY (section_id) REFERENCES sections (id) ON DELETE CASCADE,
  CONSTRAINT fk_class_subjects_subject FOREIGN KEY (subject_id) REFERENCES subjects (id) ON DELETE RESTRICT,
  CONSTRAINT fk_class_subjects_teacher FOREIGN KEY (teacher_id) REFERENCES teachers (id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS attendance_records (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  enrollment_id BIGINT UNSIGNED NOT NULL,
  section_id BIGINT UNSIGNED NOT NULL,
  attendance_date DATE NOT NULL,
  status ENUM('present', 'absent', 'late') NOT NULL,
  remarks VARCHAR(255) NULL,
  marked_by BIGINT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_attendance_enrollment_date (enrollment_id, attendance_date),
  KEY idx_attendance_section_date (section_id, attendance_date, status),
  CONSTRAINT fk_attendance_enrollment_section FOREIGN KEY (enrollment_id, section_id)
    REFERENCES student_enrollments (id, section_id) ON DELETE RESTRICT,
  CONSTRAINT fk_attendance_marker FOREIGN KEY (marked_by) REFERENCES users (id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS fee_categories (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(100) NOT NULL,
  description VARCHAR(255) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_fee_categories_name (name)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS fee_structures (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  academic_year_id BIGINT UNSIGNED NOT NULL,
  class_id BIGINT UNSIGNED NOT NULL,
  fee_category_id BIGINT UNSIGNED NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  frequency ENUM('one_time', 'monthly', 'termly', 'yearly') NOT NULL DEFAULT 'one_time',
  due_day TINYINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_fee_structure (academic_year_id, class_id, fee_category_id, frequency),
  CONSTRAINT chk_fee_structure_amount CHECK (amount >= 0),
  CONSTRAINT chk_fee_due_day CHECK (due_day IS NULL OR due_day BETWEEN 1 AND 31),
  CONSTRAINT fk_fee_structures_year FOREIGN KEY (academic_year_id) REFERENCES academic_years (id) ON DELETE RESTRICT,
  CONSTRAINT fk_fee_structures_class FOREIGN KEY (class_id) REFERENCES classes (id) ON DELETE RESTRICT,
  CONSTRAINT fk_fee_structures_category FOREIGN KEY (fee_category_id) REFERENCES fee_categories (id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS fee_invoices (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  enrollment_id BIGINT UNSIGNED NOT NULL,
  fee_structure_id BIGINT UNSIGNED NOT NULL,
  invoice_number VARCHAR(50) NOT NULL,
  issued_on DATE NOT NULL,
  due_on DATE NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  notes VARCHAR(255) NULL,
  created_by BIGINT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_fee_invoice_number (invoice_number),
  KEY idx_fee_invoice_enrollment_due (enrollment_id, due_on),
  CONSTRAINT chk_fee_invoice_amount CHECK (amount >= 0),
  CONSTRAINT chk_fee_invoice_dates CHECK (due_on >= issued_on),
  CONSTRAINT fk_fee_invoices_enrollment FOREIGN KEY (enrollment_id) REFERENCES student_enrollments (id) ON DELETE RESTRICT,
  CONSTRAINT fk_fee_invoices_structure FOREIGN KEY (fee_structure_id) REFERENCES fee_structures (id) ON DELETE RESTRICT,
  CONSTRAINT fk_fee_invoices_creator FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS payments (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  invoice_id BIGINT UNSIGNED NOT NULL,
  receipt_number VARCHAR(50) NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  method ENUM('cash', 'bank_transfer', 'card', 'mobile_banking', 'other') NOT NULL,
  transaction_reference VARCHAR(100) NULL,
  paid_at DATETIME NOT NULL,
  received_by BIGINT UNSIGNED NOT NULL,
  notes VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_payments_receipt_number (receipt_number),
  KEY idx_payments_invoice_date (invoice_id, paid_at),
  CONSTRAINT chk_payment_amount CHECK (amount > 0),
  CONSTRAINT fk_payments_invoice FOREIGN KEY (invoice_id) REFERENCES fee_invoices (id) ON DELETE RESTRICT,
  CONSTRAINT fk_payments_receiver FOREIGN KEY (received_by) REFERENCES users (id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS exams (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  academic_year_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(150) NOT NULL,
  exam_type ENUM('class_test', 'midterm', 'final', 'assessment', 'other') NOT NULL DEFAULT 'other',
  starts_on DATE NOT NULL,
  ends_on DATE NOT NULL,
  status ENUM('draft', 'published', 'completed', 'archived') NOT NULL DEFAULT 'draft',
  created_by BIGINT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_exam_id_year (id, academic_year_id),
  KEY idx_exams_year_status (academic_year_id, status),
  CONSTRAINT chk_exam_dates CHECK (ends_on >= starts_on),
  CONSTRAINT fk_exams_year FOREIGN KEY (academic_year_id) REFERENCES academic_years (id) ON DELETE RESTRICT,
  CONSTRAINT fk_exams_creator FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS exam_subjects (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  exam_id BIGINT UNSIGNED NOT NULL,
  academic_year_id BIGINT UNSIGNED NOT NULL,
  class_subject_id BIGINT UNSIGNED NOT NULL,
  section_id BIGINT UNSIGNED NOT NULL,
  exam_date DATE NULL,
  maximum_marks DECIMAL(7,2) NOT NULL,
  pass_marks DECIMAL(7,2) NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_exam_subject (exam_id, class_subject_id),
  UNIQUE KEY uq_exam_subject_id_class_subject_section (id, class_subject_id, section_id),
  CONSTRAINT chk_exam_subject_marks CHECK (maximum_marks > 0 AND pass_marks >= 0 AND pass_marks <= maximum_marks),
  CONSTRAINT fk_exam_subjects_exam_year FOREIGN KEY (exam_id, academic_year_id)
    REFERENCES exams (id, academic_year_id) ON DELETE CASCADE,
  CONSTRAINT fk_exam_subjects_class_subject_section FOREIGN KEY (class_subject_id, section_id)
    REFERENCES class_subjects (id, section_id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS grade_scales (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(100) NOT NULL,
  is_default TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_grade_scales_name (name)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS grade_rules (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  grade_scale_id BIGINT UNSIGNED NOT NULL,
  minimum_percent DECIMAL(5,2) NOT NULL,
  maximum_percent DECIMAL(5,2) NOT NULL,
  letter_grade VARCHAR(5) NOT NULL,
  grade_point DECIMAL(4,2) NOT NULL,
  remarks VARCHAR(100) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_grade_rule_min (grade_scale_id, minimum_percent),
  CONSTRAINT chk_grade_rule_percent CHECK (
    minimum_percent >= 0 AND maximum_percent <= 100 AND maximum_percent >= minimum_percent
  ),
  CONSTRAINT chk_grade_rule_point CHECK (grade_point >= 0 AND grade_point <= 5),
  CONSTRAINT fk_grade_rules_scale FOREIGN KEY (grade_scale_id) REFERENCES grade_scales (id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS marks (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  enrollment_id BIGINT UNSIGNED NOT NULL,
  section_id BIGINT UNSIGNED NOT NULL,
  exam_subject_id BIGINT UNSIGNED NOT NULL,
  class_subject_id BIGINT UNSIGNED NOT NULL,
  obtained_marks DECIMAL(7,2) NOT NULL,
  remarks VARCHAR(255) NULL,
  entered_by BIGINT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_marks_enrollment_exam_subject (enrollment_id, exam_subject_id),
  KEY idx_marks_exam_subject (exam_subject_id),
  CONSTRAINT chk_marks_obtained CHECK (obtained_marks >= 0),
  CONSTRAINT fk_marks_enrollment_section FOREIGN KEY (enrollment_id, section_id)
    REFERENCES student_enrollments (id, section_id) ON DELETE RESTRICT,
  CONSTRAINT fk_marks_exam_class_subject_section FOREIGN KEY (exam_subject_id, class_subject_id, section_id)
    REFERENCES exam_subjects (id, class_subject_id, section_id) ON DELETE RESTRICT,
  CONSTRAINT fk_marks_entered_by FOREIGN KEY (entered_by) REFERENCES users (id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS notices (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  title VARCHAR(180) NOT NULL,
  body TEXT NOT NULL,
  status ENUM('draft', 'published', 'archived') NOT NULL DEFAULT 'draft',
  published_at DATETIME NULL,
  expires_at DATETIME NULL,
  created_by BIGINT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_notices_status_publish (status, published_at),
  CONSTRAINT fk_notices_creator FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS notice_targets (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  notice_id BIGINT UNSIGNED NOT NULL,
  role_id BIGINT UNSIGNED NULL,
  section_id BIGINT UNSIGNED NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_notice_target (notice_id, role_id, section_id),
  CONSTRAINT chk_notice_target CHECK (role_id IS NOT NULL OR section_id IS NOT NULL),
  CONSTRAINT fk_notice_targets_notice FOREIGN KEY (notice_id) REFERENCES notices (id) ON DELETE CASCADE,
  CONSTRAINT fk_notice_targets_role FOREIGN KEY (role_id) REFERENCES roles (id) ON DELETE CASCADE,
  CONSTRAINT fk_notice_targets_section FOREIGN KEY (section_id) REFERENCES sections (id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS audit_logs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  actor_user_id BIGINT UNSIGNED NULL,
  action VARCHAR(80) NOT NULL,
  entity_type VARCHAR(80) NOT NULL,
  entity_id BIGINT UNSIGNED NULL,
  details JSON NULL,
  ip_address VARCHAR(45) NULL,
  user_agent VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_audit_entity (entity_type, entity_id, created_at),
  KEY idx_audit_actor_created (actor_user_id, created_at),
  CONSTRAINT fk_audit_actor FOREIGN KEY (actor_user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB;

INSERT IGNORE INTO roles (name, description) VALUES
  ('admin', 'Full system administration'),
  ('teacher', 'Academic and attendance access'),
  ('accountant', 'Fee and payment access');

INSERT IGNORE INTO permissions (code, description) VALUES
  ('dashboard.read', 'View dashboard statistics'),
  ('users.manage', 'Manage user accounts and role assignments'),
  ('students.read', 'View student records'),
  ('students.manage', 'Create, update and archive student records'),
  ('teachers.read', 'View teacher records'),
  ('teachers.manage', 'Manage teacher records'),
  ('academics.manage', 'Manage academic years, classes, sections and subjects'),
  ('attendance.read', 'View attendance records'),
  ('attendance.manage', 'Record and update attendance'),
  ('fees.read', 'View invoices and payment history'),
  ('fees.manage', 'Manage fee structures, invoices and payments'),
  ('exams.read', 'View exams, marks and report cards'),
  ('exams.manage', 'Manage exams and marks'),
  ('notices.read', 'View published notices addressed to the account'),
  ('notices.manage', 'Create and manage notices'),
  ('reports.read', 'View and export reports');

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT roles.id, permissions.id
FROM roles
CROSS JOIN permissions
WHERE roles.name = 'admin';

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT roles.id, permissions.id
FROM roles
JOIN permissions ON permissions.code IN (
  'dashboard.read',
  'students.read',
  'teachers.read',
  'attendance.read',
  'attendance.manage',
  'exams.read',
  'exams.manage',
  'notices.read',
  'notices.manage',
  'reports.read'
)
WHERE roles.name = 'teacher';

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT roles.id, permissions.id
FROM roles
JOIN permissions ON permissions.code IN (
  'dashboard.read',
  'students.read',
  'fees.read',
  'fees.manage',
  'notices.read',
  'reports.read'
)
WHERE roles.name = 'accountant';
