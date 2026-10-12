<?php
declare(strict_types=1);

try {
    require_once dirname(__DIR__) . '/app/bootstrap.php';
    require_once dirname(__DIR__) . '/app/auth.php';
} catch (Throwable $exception) {
    error_log((string) $exception);
    http_response_code(503);
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    ?>
    <!doctype html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Fees unavailable | Student Management System</title>
        <link rel="stylesheet" href="/assets/auth.css">
    </head>
    <body class="academic-page">
        <main class="academic-main">
            <div class="academic-card">
                <p class="eyebrow">School accounts</p>
                <h1>Fees unavailable</h1>
                <p>The application configuration is unavailable. Check your local <code>.env</code> file and MySQL service, then try again.</p>
            </div>
        </main>
    </body>
    </html>
    <?php
    exit;
}

function fee_record_id(mixed $value, string $label): int
{
    $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($id === false) {
        throw new InvalidArgumentException('Select a valid ' . $label . '.');
    }

    return $id;
}

function fee_valid_date(string $value): bool
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    $errors = DateTimeImmutable::getLastErrors();

    return $date !== false
        && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
        && $date->format('Y-m-d') === $value;
}

function fee_money_in_minor_units(mixed $value, string $label, bool $allowZero = false): int
{
    if (!is_string($value) && !is_int($value) && !is_float($value)) {
        throw new InvalidArgumentException('Enter a valid ' . $label . ' amount.');
    }

    $amount = trim((string) $value);
    if (!preg_match('/^(?:0|[1-9]\d{0,9})(?:\.\d{1,2})?$/D', $amount)) {
        throw new InvalidArgumentException('Enter a valid ' . $label . ' amount with no more than two decimal places.');
    }

    [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');
    $minorUnits = ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
    if (($allowZero ? $minorUnits < 0 : $minorUnits < 1) || $minorUnits > 999999999999) {
        throw new InvalidArgumentException('Enter a valid ' . $label . ' amount.');
    }

    return $minorUnits;
}

function fee_format_amount(int $minorUnits): string
{
    return number_format($minorUnits / 100, 2);
}

function fee_create_reference(string $prefix): string
{
    return $prefix . '-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(8)));
}

function fee_invoice_select_sql(): string
{
    return "SELECT fee_invoices.id, fee_invoices.invoice_number, fee_invoices.issued_on,
                   fee_invoices.due_on, fee_invoices.amount, fee_invoices.notes,
                   fee_categories.name AS category_name, fee_structures.frequency,
                   students.id AS student_id, students.admission_number,
                   students.first_name, students.last_name,
                   student_enrollments.roll_number, classes.name AS class_name,
                   sections.name AS section_name, academic_years.name AS year_name,
                   COALESCE(payment_totals.paid_amount, 0) AS paid_amount
            FROM fee_invoices
            INNER JOIN fee_structures ON fee_structures.id = fee_invoices.fee_structure_id
            INNER JOIN fee_categories ON fee_categories.id = fee_structures.fee_category_id
            INNER JOIN student_enrollments ON student_enrollments.id = fee_invoices.enrollment_id
            INNER JOIN students ON students.id = student_enrollments.student_id
            INNER JOIN classes ON classes.id = student_enrollments.class_id
            INNER JOIN sections ON sections.id = student_enrollments.section_id
            INNER JOIN academic_years ON academic_years.id = student_enrollments.academic_year_id
            LEFT JOIN (
                SELECT invoice_id, SUM(amount) AS paid_amount
                FROM payments GROUP BY invoice_id
            ) AS payment_totals ON payment_totals.invoice_id = fee_invoices.id";
}

$error = '';
$message = '';
$user = null;
$connection = null;
$canManage = false;
$categories = [];
$classes = [];
$structures = [];
$enrollments = [];
$invoices = [];
$payments = [];
$receipt = null;
$selectedInvoice = null;
$activeYear = null;
$filterStatus = (string) ($_GET['status'] ?? 'all');
$search = trim((string) ($_GET['q'] ?? ''));
$viewInvoiceId = null;
$receiptId = null;
$action = '';

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $user = require_permission('fees.manage');
        $canManage = true;
    } else {
        $user = require_permission('fees.read');
        $canManage = user_has_permission($user, 'fees.manage');
    }

    $connection = database_connection();
    $activeYear = $connection->query(
        'SELECT id, name FROM academic_years WHERE is_active = 1 ORDER BY id DESC LIMIT 1'
    )->fetch() ?: null;
    $categories = $connection->query('SELECT id, name, description FROM fee_categories ORDER BY name')->fetchAll();
    $classes = $connection->query('SELECT id, name FROM classes ORDER BY grade_level, name')->fetchAll();

    if ($activeYear !== null) {
        $structureStatement = $connection->prepare(
            'SELECT fee_structures.id, fee_structures.class_id, fee_structures.fee_category_id,
                    fee_structures.amount, fee_structures.frequency, fee_structures.due_day,
                    fee_categories.name AS category_name, classes.name AS class_name
             FROM fee_structures
             INNER JOIN fee_categories ON fee_categories.id = fee_structures.fee_category_id
             INNER JOIN classes ON classes.id = fee_structures.class_id
             WHERE fee_structures.academic_year_id = :year_id
             ORDER BY classes.grade_level, classes.name, fee_categories.name, fee_structures.frequency'
        );
        $structureStatement->execute(['year_id' => $activeYear['id']]);
        $structures = $structureStatement->fetchAll();

        $enrollmentStatement = $connection->prepare(
            "SELECT student_enrollments.id, student_enrollments.class_id, student_enrollments.roll_number,
                    students.admission_number, students.first_name, students.last_name,
                    classes.name AS class_name, sections.name AS section_name
             FROM student_enrollments
             INNER JOIN students ON students.id = student_enrollments.student_id
             INNER JOIN classes ON classes.id = student_enrollments.class_id
             INNER JOIN sections ON sections.id = student_enrollments.section_id
             WHERE student_enrollments.academic_year_id = :year_id
               AND student_enrollments.status = 'active'
               AND students.status = 'active'
             ORDER BY classes.grade_level, classes.name, sections.name, student_enrollments.roll_number"
        );
        $enrollmentStatement->execute(['year_id' => $activeYear['id']]);
        $enrollments = $enrollmentStatement->fetchAll();
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!csrf_token_is_valid($_POST['csrf_token'] ?? null)) {
            http_response_code(400);
            throw new RuntimeException('The form session expired. Reload the page and try again.');
        }

        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'create_category') {
            $name = trim((string) ($_POST['name'] ?? ''));
            $description = trim((string) ($_POST['description'] ?? ''));
            if ($name === '' || strlen($name) > 100 || strlen($description) > 255) {
                throw new InvalidArgumentException('Enter a fee category name up to 100 characters and a description up to 255 characters.');
            }
            $statement = $connection->prepare(
                'INSERT INTO fee_categories (name, description) VALUES (:name, :description)'
            );
            $statement->execute([
                'name' => $name,
                'description' => $description === '' ? null : $description,
            ]);
            $_SESSION['fees_flash'] = 'Fee category created successfully.';
            header('Location: /fees.php');
            exit;
        }

        if ($action === 'create_structure') {
            if ($activeYear === null) {
                throw new InvalidArgumentException('Activate an academic year before creating a fee structure.');
            }
            $classId = fee_record_id($_POST['class_id'] ?? null, 'class');
            $categoryId = fee_record_id($_POST['fee_category_id'] ?? null, 'fee category');
            $amountMinor = fee_money_in_minor_units($_POST['amount'] ?? null, 'fee');
            $frequency = (string) ($_POST['frequency'] ?? '');
            $dueDayRaw = trim((string) ($_POST['due_day'] ?? ''));
            $dueDay = $dueDayRaw === ''
                ? null
                : filter_var($dueDayRaw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 31]]);
            if (!in_array($frequency, ['one_time', 'monthly', 'termly', 'yearly'], true) || ($dueDayRaw !== '' && $dueDay === false)) {
                throw new InvalidArgumentException('Choose a valid fee frequency and optional due day from 1 to 31.');
            }

            $classCheck = $connection->prepare('SELECT 1 FROM classes WHERE id = :class_id');
            $classCheck->execute(['class_id' => $classId]);
            $categoryCheck = $connection->prepare('SELECT 1 FROM fee_categories WHERE id = :category_id');
            $categoryCheck->execute(['category_id' => $categoryId]);
            if ($classCheck->fetchColumn() === false || $categoryCheck->fetchColumn() === false) {
                throw new InvalidArgumentException('Select an existing class and fee category.');
            }

            $statement = $connection->prepare(
                'INSERT INTO fee_structures (academic_year_id, class_id, fee_category_id, amount, frequency, due_day)
                 VALUES (:year_id, :class_id, :category_id, :amount, :frequency, :due_day)'
            );
            $statement->execute([
                'year_id' => $activeYear['id'],
                'class_id' => $classId,
                'category_id' => $categoryId,
                'amount' => fee_format_amount($amountMinor),
                'frequency' => $frequency,
                'due_day' => $dueDay === false ? null : $dueDay,
            ]);
            $_SESSION['fees_flash'] = 'Fee structure created for ' . $activeYear['name'] . '.';
            header('Location: /fees.php');
            exit;
        }

        if ($action === 'create_invoice') {
            if ($activeYear === null) {
                throw new InvalidArgumentException('Activate an academic year before issuing invoices.');
            }
            $enrollmentId = fee_record_id($_POST['enrollment_id'] ?? null, 'student enrollment');
            $structureId = fee_record_id($_POST['fee_structure_id'] ?? null, 'fee structure');
            $issuedOn = trim((string) ($_POST['issued_on'] ?? ''));
            $dueOn = trim((string) ($_POST['due_on'] ?? ''));
            $notes = trim((string) ($_POST['notes'] ?? ''));
            if (!fee_valid_date($issuedOn) || !fee_valid_date($dueOn) || $dueOn < $issuedOn || strlen($notes) > 255) {
                throw new InvalidArgumentException('Enter valid invoice and due dates (due date must be on or after the invoice date) and notes up to 255 characters.');
            }

            $eligible = $connection->prepare(
                "SELECT student_enrollments.id
                 FROM student_enrollments
                 INNER JOIN students ON students.id = student_enrollments.student_id
                 WHERE student_enrollments.id = :enrollment_id
                   AND student_enrollments.academic_year_id = :year_id
                   AND student_enrollments.status = 'active'
                   AND students.status = 'active'"
            );
            $eligible->execute(['enrollment_id' => $enrollmentId, 'year_id' => $activeYear['id']]);
            $structureEligible = $connection->prepare(
                'SELECT fee_structures.amount
                 FROM fee_structures
                 WHERE fee_structures.id = :structure_id
                   AND fee_structures.academic_year_id = :year_id
                   AND EXISTS (
                       SELECT 1
                       FROM student_enrollments
                       WHERE student_enrollments.id = :enrollment_id
                         AND student_enrollments.class_id = fee_structures.class_id
                   )'
            );
            $structureEligible->execute([
                'structure_id' => $structureId,
                'year_id' => $activeYear['id'],
                'enrollment_id' => $enrollmentId,
            ]);
            $amount = $structureEligible->fetchColumn();
            if ($eligible->fetchColumn() === false || $amount === false) {
                throw new InvalidArgumentException('The selected active student and fee structure must belong to the same class and active academic year.');
            }
            if ($issuedOn < $activeYear['starts_on'] || $issuedOn > $activeYear['ends_on']) {
                throw new InvalidArgumentException('The invoice issue date must fall within the active academic year.');
            }

            $statement = $connection->prepare(
                'INSERT INTO fee_invoices
                    (enrollment_id, fee_structure_id, invoice_number, issued_on, due_on, amount, notes, created_by)
                 VALUES
                    (:enrollment_id, :structure_id, :invoice_number, :issued_on, :due_on, :amount, :notes, :created_by)'
            );
            $statement->execute([
                'enrollment_id' => $enrollmentId,
                'structure_id' => $structureId,
                'invoice_number' => fee_create_reference('INV'),
                'issued_on' => $issuedOn,
                'due_on' => $dueOn,
                'amount' => $amount,
                'notes' => $notes === '' ? null : $notes,
                'created_by' => (int) $user['id'],
            ]);
            $invoiceId = (int) $connection->lastInsertId();
            $_SESSION['fees_flash'] = 'Invoice issued successfully.';
            header('Location: /fees.php?invoice=' . $invoiceId);
            exit;
        }

        if ($action === 'record_payment') {
            $invoiceId = fee_record_id($_POST['invoice_id'] ?? null, 'invoice');
            $amountMinor = fee_money_in_minor_units($_POST['amount'] ?? null, 'payment');
            $method = (string) ($_POST['method'] ?? '');
            $reference = trim((string) ($_POST['transaction_reference'] ?? ''));
            $paidAt = trim((string) ($_POST['paid_at'] ?? ''));
            $notes = trim((string) ($_POST['notes'] ?? ''));
            if (!in_array($method, ['cash', 'bank_transfer', 'card', 'mobile_banking', 'other'], true)
                || strlen($reference) > 100
                || strlen($notes) > 255
                || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/D', $paidAt)
                || !fee_valid_date(substr($paidAt, 0, 10))) {
                throw new InvalidArgumentException('Enter a valid payment date, payment method, optional reference up to 100 characters and notes up to 255 characters.');
            }

            $paidDateTime = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $paidAt);
            if ($paidDateTime === false || $paidDateTime->format('Y-m-d\TH:i') !== $paidAt) {
                throw new InvalidArgumentException('Enter a valid payment date and time.');
            }
            if ($paidDateTime > new DateTimeImmutable('now')) {
                throw new InvalidArgumentException('A payment cannot be recorded with a future date or time.');
            }

            $connection->beginTransaction();
            $invoiceLock = $connection->prepare(
                'SELECT amount FROM fee_invoices WHERE id = :invoice_id FOR UPDATE'
            );
            $invoiceLock->execute(['invoice_id' => $invoiceId]);
            $invoiceAmount = $invoiceLock->fetchColumn();
            if ($invoiceAmount === false) {
                throw new InvalidArgumentException('The selected invoice was not found.');
            }
            $paidTotalStatement = $connection->prepare(
                'SELECT COALESCE(SUM(amount), 0) FROM payments WHERE invoice_id = :invoice_id'
            );
            $paidTotalStatement->execute(['invoice_id' => $invoiceId]);
            $balanceMinor = fee_money_in_minor_units((string) $invoiceAmount, 'invoice', true)
                - fee_money_in_minor_units((string) $paidTotalStatement->fetchColumn(), 'paid', true);
            if ($amountMinor > $balanceMinor) {
                throw new InvalidArgumentException('Payment exceeds the remaining invoice balance of ' . fee_format_amount($balanceMinor) . '.');
            }

            $paymentStatement = $connection->prepare(
                'INSERT INTO payments
                    (invoice_id, receipt_number, amount, method, transaction_reference, paid_at, received_by, notes)
                 VALUES
                    (:invoice_id, :receipt_number, :amount, :method, :reference, :paid_at, :received_by, :notes)'
            );
            $paymentStatement->execute([
                'invoice_id' => $invoiceId,
                'receipt_number' => fee_create_reference('RCP'),
                'amount' => fee_format_amount($amountMinor),
                'method' => $method,
                'reference' => $reference === '' ? null : $reference,
                'paid_at' => $paidDateTime->format('Y-m-d H:i:s'),
                'received_by' => (int) $user['id'],
                'notes' => $notes === '' ? null : $notes,
            ]);
            $paymentId = (int) $connection->lastInsertId();
            $connection->commit();

            $_SESSION['fees_flash'] = 'Payment recorded. Open the receipt to print or save a copy.';
            header('Location: /fees.php?receipt=' . $paymentId);
            exit;
        }

        throw new InvalidArgumentException('Choose a valid fees action.');
    }

    if (isset($_SESSION['fees_flash']) && is_string($_SESSION['fees_flash'])) {
        $message = $_SESSION['fees_flash'];
        unset($_SESSION['fees_flash']);
    }

    if (strlen($search) > 100) {
        $search = substr($search, 0, 100);
    }
    if (!in_array($filterStatus, ['all', 'paid', 'partial', 'unpaid', 'overdue'], true)) {
        $filterStatus = 'all';
    }

    if (isset($_GET['invoice'])) {
        $viewInvoiceId = fee_record_id($_GET['invoice'], 'invoice');
    }
    if (isset($_GET['receipt'])) {
        $receiptId = fee_record_id($_GET['receipt'], 'receipt');
    }

    if ($viewInvoiceId !== null || $receiptId !== null) {
        if ($receiptId !== null) {
            $receiptStatement = $connection->prepare(
                fee_invoice_select_sql() . '
                 INNER JOIN payments ON payments.invoice_id = fee_invoices.id
                 WHERE payments.id = :payment_id LIMIT 1'
            );
            $receiptStatement->execute(['payment_id' => $receiptId]);
            $receipt = $receiptStatement->fetch() ?: null;
            $receiptPayment = $connection->prepare(
                'SELECT payments.id, payments.receipt_number, payments.amount AS payment_amount,
                        payments.method, payments.transaction_reference, payments.paid_at, payments.notes,
                        payment_receiver.name AS received_by_name
                 FROM payments
                 INNER JOIN users AS payment_receiver ON payment_receiver.id = payments.received_by
                 WHERE payments.id = :payment_id LIMIT 1'
            );
            $receiptPayment->execute(['payment_id' => $receiptId]);
            $receiptDetails = $receiptPayment->fetch() ?: null;
            if ($receipt !== null && $receiptDetails !== null) {
                $receipt = array_merge($receipt, $receiptDetails);
            } else {
                $receipt = null;
            }
            if ($receipt === null) {
                http_response_code(404);
                throw new InvalidArgumentException('The requested payment receipt was not found.');
            }
        } else {
            $invoiceStatement = $connection->prepare(fee_invoice_select_sql() . ' WHERE fee_invoices.id = :invoice_id LIMIT 1');
            $invoiceStatement->execute(['invoice_id' => $viewInvoiceId]);
            $selectedInvoice = $invoiceStatement->fetch() ?: null;
            if ($selectedInvoice === null) {
                http_response_code(404);
                throw new InvalidArgumentException('The requested invoice was not found.');
            }
            $paymentHistory = $connection->prepare(
                'SELECT payments.id, payments.receipt_number, payments.amount, payments.method,
                        payments.transaction_reference, payments.paid_at, payments.notes,
                        users.name AS received_by_name
                 FROM payments
                 INNER JOIN users ON users.id = payments.received_by
                 WHERE payments.invoice_id = :invoice_id
                 ORDER BY payments.paid_at DESC, payments.id DESC'
            );
            $paymentHistory->execute(['invoice_id' => $viewInvoiceId]);
            $payments = $paymentHistory->fetchAll();
        }
    } else {
        $conditions = [];
        $parameters = [];
        if ($filterStatus !== 'all') {
            $conditions[] = match ($filterStatus) {
                'paid' => 'COALESCE(payment_totals.paid_amount, 0) >= fee_invoices.amount',
                'partial' => 'COALESCE(payment_totals.paid_amount, 0) > 0 AND COALESCE(payment_totals.paid_amount, 0) < fee_invoices.amount AND fee_invoices.due_on >= :today',
                'overdue' => 'fee_invoices.due_on < :today AND COALESCE(payment_totals.paid_amount, 0) < fee_invoices.amount',
                default => 'COALESCE(payment_totals.paid_amount, 0) = 0 AND fee_invoices.due_on >= :today',
            };
            if (in_array($filterStatus, ['overdue', 'unpaid', 'partial'], true)) {
                $parameters['today'] = date('Y-m-d');
            }
        }
        if ($search !== '') {
            $conditions[] = '(fee_invoices.invoice_number LIKE :search_invoice OR students.admission_number LIKE :search_admission OR students.first_name LIKE :search_first_name OR students.last_name LIKE :search_last_name OR fee_categories.name LIKE :search_category)';
            foreach (['invoice', 'admission', 'first_name', 'last_name', 'category'] as $searchField) {
                $parameters['search_' . $searchField] = '%' . $search . '%';
            }
        }

        $fromSql = fee_invoice_select_sql();
        $whereSql = $conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions);
        $totalStatement = $connection->prepare(
            'SELECT COUNT(*) FROM (' . $fromSql . $whereSql . ') AS matching_invoices'
        );
        $totalStatement->execute($parameters);
        $totalInvoices = (int) $totalStatement->fetchColumn();

        $listStatement = $connection->prepare(
            $fromSql . $whereSql . '
             ORDER BY fee_invoices.issued_on DESC, fee_invoices.id DESC
             LIMIT 100'
        );
        $listStatement->execute($parameters);
        $invoices = $listStatement->fetchAll();

        $summary = $connection->query(
            'SELECT COUNT(*) AS invoice_count,
                    COALESCE(SUM(fee_invoices.amount), 0) AS invoiced_total,
                    COALESCE(SUM(COALESCE(payment_totals.paid_amount, 0)), 0) AS paid_total,
                    COALESCE(SUM(GREATEST(fee_invoices.amount - COALESCE(payment_totals.paid_amount, 0), 0)), 0) AS due_total
             FROM fee_invoices
             LEFT JOIN (
                 SELECT invoice_id, SUM(amount) AS paid_amount FROM payments GROUP BY invoice_id
             ) AS payment_totals ON payment_totals.invoice_id = fee_invoices.id'
        )->fetch();
    }
} catch (InvalidArgumentException $exception) {
    if ($connection instanceof PDO && $connection->inTransaction()) {
        $connection->rollBack();
    }
    if (http_response_code() < 400) {
        http_response_code(422);
    }
    $error = $exception->getMessage();
} catch (PDOException $exception) {
    if ($connection instanceof PDO && $connection->inTransaction()) {
        $connection->rollBack();
    }
    error_log((string) $exception);
    http_response_code($exception->getCode() === '23000' ? 422 : 503);
    $error = match ($action) {
        'create_category' => 'A fee category with that name already exists, or the category could not be saved.',
        'create_structure' => 'A fee structure already exists for this class, category and frequency, or the structure could not be saved.',
        'create_invoice' => 'The invoice could not be issued. Check its enrollment and fee structure and try again.',
        'record_payment' => 'The receipt could not be recorded. Check the payment details and remaining balance.',
        default => 'Fees could not be saved or loaded. Check the database connection and try again.',
    };
} catch (Throwable $exception) {
    if ($connection instanceof PDO && $connection->inTransaction()) {
        $connection->rollBack();
    }
    error_log((string) $exception);
    if (http_response_code() < 400) {
        http_response_code(503);
    }
    $error = str_contains($exception->getMessage(), 'permission')
        ? $exception->getMessage()
        : (http_response_code() === 400
            ? 'The form session expired. Reload the page and try again.'
            : 'Fees could not be loaded. Check the database connection and try again.');
}

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');
$token = csrf_token();
$currency = getenv('APP_CURRENCY') ?: 'BDT';
$workspaceUser = $user;
$workspaceCurrent = 'fees';
$workspaceTitle = $receipt !== null ? 'Payment receipt' : ($selectedInvoice !== null ? 'Invoice details' : 'Fees management');
$workspaceDescription = $receipt !== null
    ? 'Payment receipt and invoice information.'
    : ($selectedInvoice !== null ? 'Invoice balance and payment history.' : 'Configure class fees, issue invoices, and record student payments.');
$workspaceHeadingAction = '<span class="workspace-count">'
    . ($activeYear !== null ? 'Academic year: ' . escape_html($activeYear['name']) : 'No active academic year')
    . '</span>';
$workspaceScript = '/assets/academics.js';
require dirname(__DIR__) . '/app/views/workspace-header.php';
?>
        <div class="workspace-section-heading"><span>School fees and payments</span></div>
        <?php if ($error !== ''): ?><p class="alert" role="alert"><?= escape_html($error) ?></p><?php endif; ?>
        <?php if ($message !== ''): ?><p class="alert alert-success" role="status"><?= escape_html($message) ?></p><?php endif; ?>

        <?php if ($receipt !== null): ?>
            <?php
            $receiptBalance = max(0, (int) round((float) $receipt['amount'] * 100) - (int) round((float) $receipt['paid_amount'] * 100));
            ?>
            <section class="academic-card fee-receipt" aria-labelledby="receipt-title">
                <div class="section-heading">
                    <div><p class="eyebrow">Official payment receipt</p><h2 id="receipt-title"><?= escape_html($receipt['receipt_number']) ?></h2><p>Issued <?= escape_html(date('F j, Y · g:i A', strtotime($receipt['paid_at']))) ?></p></div>
                    <button class="table-action fee-print-button" type="button" onclick="window.print()">Print receipt</button>
                </div>
                <dl class="teacher-details">
                    <div><dt>Student</dt><dd><?= escape_html($receipt['first_name'] . ' ' . $receipt['last_name']) ?></dd></div>
                    <div><dt>Admission number</dt><dd><?= escape_html($receipt['admission_number']) ?></dd></div>
                    <div><dt>Class / section</dt><dd><?= escape_html($receipt['class_name'] . ' — ' . $receipt['section_name']) ?></dd></div>
                    <div><dt>Invoice</dt><dd><?= escape_html($receipt['invoice_number']) ?> · <?= escape_html($receipt['category_name']) ?></dd></div>
                    <div><dt>Payment method</dt><dd><?= escape_html(ucwords(str_replace('_', ' ', $receipt['method']))) ?></dd></div>
                    <div><dt>Received by</dt><dd><?= escape_html($receipt['received_by_name']) ?></dd></div>
                    <div><dt>Invoice amount</dt><dd><?= escape_html($currency) ?> <?= number_format((float) $receipt['amount'], 2) ?></dd></div>
                    <div><dt>Payment received</dt><dd><?= escape_html($currency) ?> <?= number_format((float) $receipt['payment_amount'], 2) ?></dd></div>
                    <div><dt>Remaining invoice balance</dt><dd><?= escape_html($currency) ?> <?= fee_format_amount($receiptBalance) ?></dd></div>
                    <?php if ($receipt['transaction_reference'] !== null && $receipt['transaction_reference'] !== ''): ?><div><dt>Transaction reference</dt><dd><?= escape_html($receipt['transaction_reference']) ?></dd></div><?php endif; ?>
                    <?php if ($receipt['notes'] !== null && $receipt['notes'] !== ''): ?><div><dt>Notes</dt><dd><?= escape_html($receipt['notes']) ?></dd></div><?php endif; ?>
                </dl>
                <p class="fee-receipt-disclaimer">This receipt confirms only the payment shown above. Keep it for your records.</p>
                <a class="table-action" href="<?= escape_html('/fees.php?invoice=' . (int) $receipt['id']) ?>">Back to invoice</a>
            </section>
        <?php elseif ($selectedInvoice !== null): ?>
            <?php
            $invoiceAmountMinor = (int) round((float) $selectedInvoice['amount'] * 100);
            $paidAmountMinor = (int) round((float) $selectedInvoice['paid_amount'] * 100);
            $invoiceBalanceMinor = max(0, $invoiceAmountMinor - $paidAmountMinor);
            ?>
            <section class="academic-card fee-invoice-detail" aria-labelledby="invoice-detail-heading">
                <div class="section-heading">
                    <div><p class="eyebrow">Invoice <?= escape_html($selectedInvoice['invoice_number']) ?></p><h2 id="invoice-detail-heading"><?= escape_html($selectedInvoice['first_name'] . ' ' . $selectedInvoice['last_name']) ?></h2><p><?= escape_html($selectedInvoice['category_name'] . ' · ' . $selectedInvoice['class_name'] . ' — ' . $selectedInvoice['section_name'] . ' · ' . $selectedInvoice['year_name']) ?></p></div>
                    <a class="table-action" href="/fees.php">Back to fees</a>
                </div>
                <dl class="teacher-details">
                    <div><dt>Admission number</dt><dd><?= escape_html($selectedInvoice['admission_number']) ?></dd></div>
                    <div><dt>Roll number</dt><dd><?= escape_html($selectedInvoice['roll_number']) ?></dd></div>
                    <div><dt>Frequency</dt><dd><?= escape_html(ucwords(str_replace('_', ' ', $selectedInvoice['frequency']))) ?></dd></div>
                    <div><dt>Issued on</dt><dd><?= escape_html(date('M j, Y', strtotime($selectedInvoice['issued_on']))) ?></dd></div>
                    <div><dt>Due on</dt><dd><?= escape_html(date('M j, Y', strtotime($selectedInvoice['due_on']))) ?></dd></div>
                    <div><dt>Invoice total</dt><dd><?= escape_html($currency) ?> <?= fee_format_amount($invoiceAmountMinor) ?></dd></div>
                    <div><dt>Paid</dt><dd><?= escape_html($currency) ?> <?= fee_format_amount($paidAmountMinor) ?></dd></div>
                    <div><dt>Balance due</dt><dd><?= escape_html($currency) ?> <?= fee_format_amount($invoiceBalanceMinor) ?></dd></div>
                    <?php if ($selectedInvoice['notes'] !== null && $selectedInvoice['notes'] !== ''): ?><div><dt>Invoice notes</dt><dd><?= escape_html($selectedInvoice['notes']) ?></dd></div><?php endif; ?>
                </dl>
                <?php if ($canManage && $invoiceBalanceMinor > 0): ?>
                    <div class="fee-payment-panel">
                        <div class="section-heading"><div><h2>Record a payment</h2><p>Payment cannot exceed the remaining balance of <?= escape_html($currency) ?> <?= fee_format_amount($invoiceBalanceMinor) ?>.</p></div></div>
                        <form class="academic-create fee-payment-form" method="post" action="/fees.php">
                            <input type="hidden" name="csrf_token" value="<?= escape_html($token) ?>">
                            <input type="hidden" name="action" value="record_payment">
                            <input type="hidden" name="invoice_id" value="<?= (int) $selectedInvoice['id'] ?>">
                            <label>Amount (<?= escape_html($currency) ?>)<input name="amount" type="number" min="0.01" max="<?= escape_html(fee_format_amount($invoiceBalanceMinor)) ?>" step="0.01" required></label>
                            <label>Payment method<select name="method" required><option value="cash">Cash</option><option value="bank_transfer">Bank transfer</option><option value="card">Card</option><option value="mobile_banking">Mobile banking</option><option value="other">Other</option></select></label>
                            <label>Paid at<input name="paid_at" type="datetime-local" value="<?= escape_html(date('Y-m-d\TH:i')) ?>" required></label>
                            <label>Transaction reference<input name="transaction_reference" maxlength="100"></label>
                            <label class="fee-notes-field">Notes<input name="notes" maxlength="255"></label>
                            <button class="primary-button" type="submit">Record payment</button>
                        </form>
                    </div>
                <?php elseif ($invoiceBalanceMinor === 0): ?>
                    <p class="alert alert-success" role="status">This invoice is fully paid.</p>
                <?php endif; ?>
            </section>

            <section class="academic-card" aria-labelledby="payment-history-heading">
                <div class="section-heading"><div><h2 id="payment-history-heading">Payment history</h2><p>Every payment is listed separately with its own receipt.</p></div></div>
                <?php if ($payments === []): ?>
                    <p class="empty-state">No payments have been recorded for this invoice yet.</p>
                <?php else: ?>
                    <div class="table-wrap">
                        <table>
                            <thead><tr><th>Receipt</th><th>Date</th><th>Amount</th><th>Method</th><th>Reference</th><th>Received by</th><th>Action</th></tr></thead>
                            <tbody>
                            <?php foreach ($payments as $payment): ?>
                                <tr>
                                    <td><?= escape_html($payment['receipt_number']) ?></td>
                                    <td><?= escape_html(date('M j, Y · g:i A', strtotime($payment['paid_at']))) ?></td>
                                    <td><?= escape_html($currency) ?> <?= number_format((float) $payment['amount'], 2) ?></td>
                                    <td><?= escape_html(ucwords(str_replace('_', ' ', $payment['method']))) ?></td>
                                    <td><?= $payment['transaction_reference'] !== null ? escape_html($payment['transaction_reference']) : '—' ?></td>
                                    <td><?= escape_html($payment['received_by_name']) ?></td>
                                    <td><a class="table-action" href="<?= escape_html('/fees.php?receipt=' . (int) $payment['id']) ?>">Receipt</a></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>
        <?php else: ?>
            <section class="academic-card fee-summary-grid" aria-label="Fee totals">
                <div><span>Invoices</span><strong><?= number_format((int) ($summary['invoice_count'] ?? 0)) ?></strong></div>
                <div><span>Total billed</span><strong><?= escape_html($currency) ?> <?= number_format((float) ($summary['invoiced_total'] ?? 0), 2) ?></strong></div>
                <div><span>Collected</span><strong><?= escape_html($currency) ?> <?= number_format((float) ($summary['paid_total'] ?? 0), 2) ?></strong></div>
                <div><span>Outstanding</span><strong><?= escape_html($currency) ?> <?= number_format((float) ($summary['due_total'] ?? 0), 2) ?></strong></div>
            </section>

            <?php if ($activeYear === null): ?>
                <p class="student-year-notice">Activate an academic year in Academic setup before configuring fee structures or issuing invoices.</p>
            <?php elseif ($canManage): ?>
                <section class="academic-card" aria-labelledby="fee-category-heading">
                    <div class="section-heading"><div><h2 id="fee-category-heading">Fee categories</h2><p>Create reusable categories such as tuition or transport. Existing categories are loaded from the database.</p></div></div>
                    <div class="fee-category-grid">
                        <div class="table-wrap">
                            <?php if ($categories === []): ?>
                                <p class="empty-state">No fee categories are configured yet.</p>
                            <?php else: ?>
                                <table><thead><tr><th>Category</th><th>Description</th></tr></thead><tbody>
                                <?php foreach ($categories as $category): ?><tr><td><?= escape_html($category['name']) ?></td><td><?= $category['description'] !== null ? escape_html($category['description']) : '—' ?></td></tr><?php endforeach; ?>
                                </tbody></table>
                            <?php endif; ?>
                        </div>
                        <form class="academic-create fee-category-form" method="post" action="/fees.php">
                            <input type="hidden" name="csrf_token" value="<?= escape_html($token) ?>">
                            <input type="hidden" name="action" value="create_category">
                            <label>Category name<input name="name" maxlength="100" required></label>
                            <label>Description<input name="description" maxlength="255"></label>
                            <button class="primary-button" type="submit">Add category</button>
                        </form>
                    </div>
                </section>

                <section class="academic-card" aria-labelledby="fee-structure-heading">
                    <div class="section-heading"><div><h2 id="fee-structure-heading">Fee structures</h2><p>Set the charge by class, category and frequency for <?= escape_html($activeYear['name']) ?>.</p></div></div>
                    <?php if ($categories === [] || $classes === []): ?>
                        <p class="student-year-notice">Add at least one class and fee category before creating a fee structure.</p>
                    <?php else: ?>
                        <form class="academic-create fee-structure-form" method="post" action="/fees.php">
                            <input type="hidden" name="csrf_token" value="<?= escape_html($token) ?>">
                            <input type="hidden" name="action" value="create_structure">
                            <label>Class<select name="class_id" required><option value="">Choose class</option><?php foreach ($classes as $class): ?><option value="<?= (int) $class['id'] ?>"><?= escape_html($class['name']) ?></option><?php endforeach; ?></select></label>
                            <label>Fee category<select name="fee_category_id" required><option value="">Choose category</option><?php foreach ($categories as $category): ?><option value="<?= (int) $category['id'] ?>"><?= escape_html($category['name']) ?></option><?php endforeach; ?></select></label>
                            <label>Amount (<?= escape_html($currency) ?>)<input name="amount" type="number" min="0.01" step="0.01" required></label>
                            <label>Frequency<select name="frequency" required><option value="one_time">One time</option><option value="monthly">Monthly</option><option value="termly">Termly</option><option value="yearly">Yearly</option></select></label>
                            <label>Due day (optional)<input name="due_day" type="number" min="1" max="31"></label>
                            <button class="primary-button" type="submit">Add fee structure</button>
                        </form>
                    <?php endif; ?>
                    <?php if ($structures === []): ?>
                        <p class="empty-state">No fee structures are configured for the active academic year yet.</p>
                    <?php else: ?>
                        <div class="table-wrap">
                            <table><thead><tr><th>Class</th><th>Category</th><th>Frequency</th><th>Amount</th><th>Due day</th></tr></thead><tbody>
                            <?php foreach ($structures as $structure): ?><tr><td><?= escape_html($structure['class_name']) ?></td><td><?= escape_html($structure['category_name']) ?></td><td><?= escape_html(ucwords(str_replace('_', ' ', $structure['frequency']))) ?></td><td><?= escape_html($currency) ?> <?= number_format((float) $structure['amount'], 2) ?></td><td><?= $structure['due_day'] !== null ? (int) $structure['due_day'] : '—' ?></td></tr><?php endforeach; ?>
                            </tbody></table>
                        </div>
                    <?php endif; ?>
                </section>

                <section class="academic-card" aria-labelledby="create-invoice-heading">
                    <div class="section-heading"><div><h2 id="create-invoice-heading">Issue an invoice</h2><p>Invoices use the selected student's active enrollment and matching class fee structure.</p></div></div>
                    <?php if ($enrollments === [] || $structures === []): ?>
                        <p class="student-year-notice">Add a fee structure and actively enroll students in a class for <?= escape_html($activeYear['name']) ?> before issuing invoices.</p>
                    <?php else: ?>
                        <form class="academic-create fee-invoice-form" method="post" action="/fees.php">
                            <input type="hidden" name="csrf_token" value="<?= escape_html($token) ?>">
                            <input type="hidden" name="action" value="create_invoice">
                            <label>Student enrollment<select name="enrollment_id" required><option value="">Choose enrolled student</option><?php foreach ($enrollments as $enrollment): ?><option value="<?= (int) $enrollment['id'] ?>"><?= escape_html($enrollment['class_name'] . ' — ' . $enrollment['section_name'] . ' · ' . $enrollment['roll_number'] . ' · ' . $enrollment['admission_number'] . ' · ' . $enrollment['first_name'] . ' ' . $enrollment['last_name']) ?></option><?php endforeach; ?></select></label>
                            <label>Fee structure<select name="fee_structure_id" required><option value="">Choose class fee</option><?php foreach ($structures as $structure): ?><option value="<?= (int) $structure['id'] ?>"><?= escape_html($structure['class_name'] . ' · ' . $structure['category_name'] . ' · ' . ucwords(str_replace('_', ' ', $structure['frequency'])) . ' · ' . $currency . ' ' . number_format((float) $structure['amount'], 2)) ?></option><?php endforeach; ?></select></label>
                            <label>Issue date<input name="issued_on" type="date" value="<?= escape_html(date('Y-m-d')) ?>" required></label>
                            <label>Due date<input name="due_on" type="date" value="<?= escape_html(date('Y-m-d', strtotime('+14 days'))) ?>" required></label>
                            <label class="fee-notes-field">Notes<input name="notes" maxlength="255"></label>
                            <button class="primary-button" type="submit">Issue invoice</button>
                        </form>
                    <?php endif; ?>
                </section>
            <?php endif; ?>

            <section class="academic-card" aria-labelledby="invoice-list-heading">
                <div class="section-heading"><div><h2 id="invoice-list-heading">Invoices and payments</h2><p>Search invoices, students and categories. Payment totals are calculated from recorded receipts.</p></div></div>
                <form class="teacher-filters fee-filters" method="get" action="/fees.php">
                    <label>Search<input type="search" name="q" maxlength="100" value="<?= escape_html($search) ?>" placeholder="Invoice, admission no. or student"></label>
                    <label>Balance status<select name="status"><?php foreach (['all' => 'All invoices', 'unpaid' => 'Unpaid', 'partial' => 'Partially paid', 'overdue' => 'Overdue', 'paid' => 'Paid'] as $value => $label): ?><option value="<?= $value ?>"<?= $filterStatus === $value ? ' selected' : '' ?>><?= escape_html($label) ?></option><?php endforeach; ?></select></label>
                    <button class="primary-button" type="submit">Filter invoices</button>
                    <?php if ($search !== '' || $filterStatus !== 'all'): ?><a class="table-action" href="/fees.php">Clear filters</a><?php endif; ?>
                </form>
                <?php if ($invoices === []): ?>
                    <p class="empty-state">No invoices match the selected filters. Invoices will appear here after they are issued.</p>
                <?php else: ?>
                    <div class="table-wrap">
                        <table>
                            <thead><tr><th>Invoice</th><th>Student</th><th>Class</th><th>Category</th><th>Due date</th><th>Amount</th><th>Paid</th><th>Balance</th><th>Status</th><th>Action</th></tr></thead>
                            <tbody>
                            <?php foreach ($invoices as $invoice): ?>
                                <?php
                                $invoiceCents = (int) round((float) $invoice['amount'] * 100);
                                $paidCents = (int) round((float) $invoice['paid_amount'] * 100);
                                $balanceCents = max(0, $invoiceCents - $paidCents);
                                $invoiceStatus = $balanceCents === 0
                                    ? 'paid'
                                    : ($invoice['due_on'] < date('Y-m-d')
                                        ? 'overdue'
                                        : ($paidCents > 0 ? 'partial' : 'unpaid'));
                                ?>
                                <tr>
                                    <td><?= escape_html($invoice['invoice_number']) ?></td>
                                    <td><?= escape_html($invoice['first_name'] . ' ' . $invoice['last_name']) ?><small class="fee-subline"><?= escape_html($invoice['admission_number']) ?></small></td>
                                    <td><?= escape_html($invoice['class_name'] . ' — ' . $invoice['section_name']) ?></td>
                                    <td><?= escape_html($invoice['category_name']) ?></td>
                                    <td><?= escape_html(date('M j, Y', strtotime($invoice['due_on']))) ?></td>
                                    <td><?= escape_html($currency) ?> <?= number_format((float) $invoice['amount'], 2) ?></td>
                                    <td><?= escape_html($currency) ?> <?= fee_format_amount($paidCents) ?></td>
                                    <td><?= escape_html($currency) ?> <?= fee_format_amount($balanceCents) ?></td>
                                    <td><span class="teacher-status teacher-status-<?= escape_html($invoiceStatus) ?>"><?= escape_html(ucfirst($invoiceStatus)) ?></span></td>
                                    <td><a class="table-action" href="<?= escape_html('/fees.php?invoice=' . (int) $invoice['id']) ?>">Details</a></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php if ($totalInvoices > count($invoices)): ?><p class="teacher-limit">Showing the first 100 matching invoices. Refine your search or status filter to find other records.</p><?php endif; ?>
                <?php endif; ?>
            </section>
        <?php endif; ?>
<?php require dirname(__DIR__) . '/app/views/workspace-footer.php'; ?>
