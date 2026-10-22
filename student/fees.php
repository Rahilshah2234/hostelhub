<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/auth.php';

require_role('Student');

$studentId = (int)($_SESSION['user']['StudentID'] ?? 0);

// Auto-mark overdue
$pdo->query("UPDATE fees SET Status = 'Overdue' WHERE Status = 'Pending' AND DueDate < CURDATE()");

// Handle Student Payment Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf()) {
        set_flash('danger', 'Security session expired.');
        redirect('student/fees.php');
    }

    $feeId = (int)($_POST['fee_id'] ?? 0);
    $method = trim($_POST['payment_method'] ?? 'UPI');
    $txnRef = trim($_POST['transaction_ref'] ?? '');

    // Validate payment method against ENUM
    $allowedMethods = ['UPI', 'Net Banking', 'Card', 'Cash'];
    if (!in_array($method, $allowedMethods, true)) {
        $method = 'UPI';
    }

    if (empty($txnRef)) {
        $txnRef = 'TXN-' . strtoupper(substr(md5(uniqid()), 0, 10));
    }

    if ($feeId > 0) {
        // Verify fee belongs to this student and check current payment status
        $check = $pdo->prepare("SELECT Status FROM fees WHERE FeeID = :fid AND StudentID = :sid");
        $check->execute([':fid' => $feeId, ':sid' => $studentId]);
        $currentStatus = $check->fetchColumn();

        if ($currentStatus === false) {
            set_flash('danger', 'Invalid or unassociated fee invoice.');
        } elseif ($currentStatus === 'Paid') {
            set_flash('warning', 'This invoice has already been paid and finalized. Receipts cannot be altered.');
        } else {
            $stmt = $pdo->prepare("
                UPDATE fees 
                SET Status = 'Paid', PaidDate = CURDATE(), PaymentMethod = :pm, TransactionRef = :ref 
                WHERE FeeID = :fid AND StudentID = :sid AND Status IN ('Pending', 'Overdue')
            ");
            $stmt->execute([':pm' => $method, ':ref' => $txnRef, ':fid' => $feeId, ':sid' => $studentId]);
            set_flash('success', 'Fee payment successfully processed! Your electronic receipt has been generated.');
        }
    }
    redirect('student/fees.php');
}

// Fetch all fees for this student
$fees = $pdo->prepare("
    SELECT f.*, s.Name as StudentName, r.RoomNumber, b.BlockName 
    FROM fees f
    JOIN students s ON f.StudentID = s.StudentID
    LEFT JOIN rooms r ON s.RoomID = r.RoomID
    LEFT JOIN blocks b ON r.BlockID = b.BlockID
    WHERE f.StudentID = :sid
    ORDER BY f.FeeID DESC
");
$fees->execute([':sid' => $studentId]);
$feeList = $fees->fetchAll();

$pageTitle = 'Fee Records - ' . APP_NAME;
require_once __DIR__ . '/../includes/header.php';
?>

<div class="dashboard-container">
    <?php require_once __DIR__ . '/../includes/sidebar.php'; ?>

    <main class="main-content">
        <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-3">
            <div>
                <h3 class="fw-bold text-dark mb-1">My Hostel Fee Dues & Receipts</h3>
                <p class="text-muted small mb-0">Track hostel accommodation charges, submit online payment references, and download official receipts.</p>
            </div>
        </div>

        <?php display_flash(); ?>

        <div class="content-card">
            <div class="content-card-header">
                <h6 class="content-card-title"><i class="bi bi-credit-card me-2 text-muted"></i>Fee Invoices (<?= count($feeList) ?>)</h6>
            </div>
            <div class="table-responsive">
                <table class="table table-custom align-middle">
                    <thead>
                        <tr>
                            <th>Invoice Number</th>
                            <th>Description</th>
                            <th>Amount Due</th>
                            <th>Due Date</th>
                            <th>Status</th>
                            <th>Payment Date & Mode</th>
                            <th class="text-end">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($feeList)): ?>
                            <tr><td colspan="7" class="text-center py-4 text-muted">No fee invoices issued for your account.</td></tr>
                        <?php else: ?>
                            <?php foreach ($feeList as $f): ?>
                                <tr>
                                    <td>
                                        <span class="fw-bold text-dark">#FEE-<?= str_pad($f['FeeID'], 4, '0', STR_PAD_LEFT) ?></span>
                                    </td>
                                    <td>
                                        <div class="fw-semibold text-dark"><?= htmlspecialchars($f['Title']) ?></div>
                                        <div class="small text-muted"><?= htmlspecialchars($f['RoomNumber'] ?? '') ?> (<?= htmlspecialchars($f['BlockName'] ?? '') ?>)</div>
                                    </td>
                                    <td>
                                        <div class="fw-bold text-dark fs-6"><?= format_currency($f['Amount']) ?></div>
                                    </td>
                                    <td>
                                        <span class="small <?= ($f['Status'] !== 'Paid' && strtotime($f['DueDate']) < time()) ? 'text-danger fw-bold' : 'text-muted' ?>">
                                            <?= format_date($f['DueDate']) ?>
                                        </span>
                                    </td>
                                    <td><?= get_status_badge($f['Status']) ?></td>
                                    <td>
                                        <?php if ($f['Status'] === 'Paid'): ?>
                                            <div class="small fw-semibold text-dark"><?= format_date($f['PaidDate']) ?></div>
                                            <div class="small text-muted"><?= htmlspecialchars($f['PaymentMethod'] ?? '') ?>: <?= htmlspecialchars($f['TransactionRef'] ?? 'N/A') ?></div>
                                        <?php else: ?>
                                            <span class="text-muted small fst-italic">Awaiting payment</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <?php if ($f['Status'] !== 'Paid'): ?>
                                            <button class="btn btn-sm btn-teal py-1 px-3"
                                                    data-bs-toggle="modal"
                                                    data-bs-target="#payModal"
                                                    data-id="<?= $f['FeeID'] ?>"
                                                    data-title="<?= htmlspecialchars($f['Title']) ?>"
                                                    data-amount="<?= $f['Amount'] ?>">
                                                <i class="bi bi-wallet2 me-1"></i>Pay Now
                                            </button>
                                        <?php else: ?>
                                            <button class="btn btn-sm btn-outline-secondary py-1 px-3"
                                                    data-bs-toggle="modal"
                                                    data-bs-target="#studentReceiptModal"
                                                    data-id="<?= str_pad($f['FeeID'], 4, '0', STR_PAD_LEFT) ?>"
                                                    data-student="<?= htmlspecialchars($f['StudentName']) ?>"
                                                    data-title="<?= htmlspecialchars($f['Title']) ?>"
                                                    data-amount="<?= format_currency($f['Amount']) ?>"
                                                    data-date="<?= format_date($f['PaidDate']) ?>"
                                                    data-method="<?= htmlspecialchars($f['PaymentMethod'] ?? 'Online') ?>"
                                                    data-ref="<?= htmlspecialchars($f['TransactionRef'] ?? 'N/A') ?>">
                                                <i class="bi bi-printer me-1"></i>View Receipt
                                            </button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </main>
</div>

<!-- Modal: Pay Fee -->
<div class="modal fade" id="payModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" action="fees.php" class="modal-content needs-validation" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="fee_id" id="modal_fee_id">
            <div class="modal-header">
                <h5 class="modal-title fw-bold">Pay Hostel Fee Online</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="p-3 bg-light rounded-3 mb-3 border">
                    <div class="small text-muted">Particulars:</div>
                    <div class="fw-bold text-dark" id="modal_fee_title"></div>
                    <div class="small text-muted mt-2">Amount to Pay:</div>
                    <div class="fw-bold fs-4 text-teal" id="modal_fee_amount"></div>
                </div>

                <div class="mb-3">
                    <label class="form-label small fw-semibold">Select Payment Method *</label>
                    <select name="payment_method" class="form-select" required>
                        <option value="UPI">UPI (Google Pay / PhonePe / Paytm / BHIM)</option>
                        <option value="Net Banking">Net Banking (HDFC, SBI, ICICI, Axis)</option>
                        <option value="Card">Debit / Credit Card</option>
                        <option value="Cash">Cash at Hostel Accounts Counter</option>
                    </select>
                </div>

                <div class="mb-3">
                    <label class="form-label small fw-semibold">UPI Ref / UTR / Transaction ID</label>
                    <input type="text" name="transaction_ref" class="form-control" placeholder="e.g. UPI/260810/987123 (or leave blank to auto-generate demo ID)">
                    <div class="form-text small">Enter your bank reference number after completing payment.</div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-teal">
                    <i class="bi bi-check2-circle me-1"></i> Submit Payment
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Printable Receipt -->
<div class="modal fade" id="studentReceiptModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold">Official Payment Receipt</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <div class="text-center mb-4">
                    <div class="fw-bold fs-4 text-dark"><?= APP_NAME ?></div>
                    <div class="small text-muted"><?= APP_TITLE ?></div>
                    <div class="badge bg-success mt-2">PAID & VERIFIED</div>
                </div>
                <hr>
                <div class="row g-2 mb-2 small">
                    <div class="col-6 text-muted">Receipt Number:</div>
                    <div class="col-6 fw-bold text-end" id="s_rcpt_id"></div>
                    <div class="col-6 text-muted">Student Name:</div>
                    <div class="col-6 fw-bold text-end" id="s_rcpt_student"></div>
                    <div class="col-6 text-muted">Description:</div>
                    <div class="col-6 fw-bold text-end" id="s_rcpt_title"></div>
                    <div class="col-6 text-muted">Amount Paid:</div>
                    <div class="col-6 fw-bold text-end text-success fs-6" id="s_rcpt_amount"></div>
                    <div class="col-6 text-muted">Payment Date:</div>
                    <div class="col-6 text-end" id="s_rcpt_date"></div>
                    <div class="col-6 text-muted">Payment Mode:</div>
                    <div class="col-6 text-end" id="s_rcpt_method"></div>
                    <div class="col-6 text-muted">Transaction ID:</div>
                    <div class="col-6 text-end font-monospace" id="s_rcpt_ref"></div>
                </div>
                <div class="alert alert-light border small text-center text-muted mt-3 mb-0">
                    This is a computerized receipt issued by <?= APP_NAME ?>.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-teal" onclick="window.print()"><i class="bi bi-printer me-1"></i> Print Receipt</button>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const payModal = document.getElementById('payModal');
    payModal.addEventListener('show.bs.modal', function (event) {
        const btn = event.relatedTarget;
        document.getElementById('modal_fee_id').value = btn.getAttribute('data-id');
        document.getElementById('modal_fee_title').textContent = btn.getAttribute('data-title');
        document.getElementById('modal_fee_amount').textContent = '₹ ' + parseFloat(btn.getAttribute('data-amount')).toFixed(2);
    });

    const rcptModal = document.getElementById('studentReceiptModal');
    rcptModal.addEventListener('show.bs.modal', function (event) {
        const btn = event.relatedTarget;
        document.getElementById('s_rcpt_id').textContent = '#REC-' + btn.getAttribute('data-id');
        document.getElementById('s_rcpt_student').textContent = btn.getAttribute('data-student');
        document.getElementById('s_rcpt_title').textContent = btn.getAttribute('data-title');
        document.getElementById('s_rcpt_amount').textContent = btn.getAttribute('data-amount');
        document.getElementById('s_rcpt_date').textContent = btn.getAttribute('data-date');
        document.getElementById('s_rcpt_method').textContent = btn.getAttribute('data-method');
        document.getElementById('s_rcpt_ref').textContent = btn.getAttribute('data-ref');
    });
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
