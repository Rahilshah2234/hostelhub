<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/auth.php';

require_role('Admin');

// Handle Edits & Deletions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf()) {
        set_flash('danger', 'Security token invalid.');
        redirect('admin/students.php');
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'edit') {
        $studentId = (int)($_POST['student_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $gender = trim($_POST['gender'] ?? 'Male');
        $course = trim($_POST['course'] ?? '');
        $year = (int)($_POST['year'] ?? 1);
        $eContact = trim($_POST['emergency_contact'] ?? '');
        $ePhone = trim($_POST['emergency_phone'] ?? '');
        $address = trim($_POST['address'] ?? '');

        if ($studentId > 0 && !empty($name) && !empty($email)) {
            try {
                $stmt = $pdo->prepare("
                    UPDATE students 
                    SET Name = :name, Email = :email, Phone = :phone, Gender = :gender, Course = :course, 
                        Year = :year, EmergencyContact = :ec, EmergencyPhone = :ep, Address = :addr
                    WHERE StudentID = :id
                ");
                $stmt->execute([
                    ':name'   => $name,
                    ':email'  => $email,
                    ':phone'  => $phone,
                    ':gender' => $gender,
                    ':course' => $course,
                    ':year'   => $year,
                    ':ec'     => $eContact,
                    ':ep'     => $ePhone,
                    ':addr'   => $address,
                    ':id'     => $studentId
                ]);
                set_flash('success', "Student profile for {$name} updated successfully.");
            } catch (PDOException $e) {
                set_flash('danger', 'Error updating student: ' . $e->getMessage());
            }
        }
        redirect('admin/students.php');
    }

    if ($action === 'delete') {
        $studentId = (int)($_POST['student_id'] ?? 0);
        if ($studentId > 0) {
            try {
                $pdo->beginTransaction();

                // If student has a room, decrement occupancy
                $curr = $pdo->prepare("SELECT RoomID FROM students WHERE StudentID = :id");
                $curr->execute([':id' => $studentId]);
                $roomId = $curr->fetchColumn();

                if ($roomId) {
                    $pdo->prepare("
                        UPDATE rooms 
                        SET Occupied = GREATEST(0, Occupied - 1), 
                            Status = CASE WHEN Status = 'Maintenance' THEN 'Maintenance' ELSE 'Available' END 
                        WHERE RoomID = :rid
                    ")->execute([':rid' => $roomId]);
                }

                // Delete student (cascades to logins, allocations, fees, complaints, visitors)
                $pdo->prepare("DELETE FROM students WHERE StudentID = :id")->execute([':id' => $studentId]);

                $pdo->commit();
                set_flash('success', 'Student record deleted.');
            } catch (Exception $e) {
                $pdo->rollBack();
                set_flash('danger', 'Error deleting student: ' . $e->getMessage());
            }
        }
        redirect('admin/students.php');
    }
}

// Fetch all students
$students = $pdo->query("
    SELECT 
        s.*, 
        r.RoomNumber, 
        b.BlockName,
        l.Username,
        (SELECT COALESCE(SUM(Amount), 0) FROM fees WHERE StudentID = s.StudentID AND Status IN ('Pending', 'Overdue')) as pending_fees
    FROM students s
    LEFT JOIN rooms r ON s.RoomID = r.RoomID
    LEFT JOIN blocks b ON r.BlockID = b.BlockID
    LEFT JOIN logins l ON l.StudentID = s.StudentID
    ORDER BY s.StudentID DESC
")->fetchAll();

$pageTitle = 'Student Records - ' . APP_NAME;
require_once __DIR__ . '/../includes/header.php';
?>

<div class="dashboard-container">
    <?php require_once __DIR__ . '/../includes/sidebar.php'; ?>

    <main class="main-content">
        <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-3">
            <div>
                <h3 class="fw-bold text-dark mb-1">Student Resident Directory</h3>
                <p class="text-muted small mb-0">View student profiles, room allocations, pending fee status, and emergency contacts.</p>
            </div>
            <a href="<?= BASE_URL ?>/auth/register.php" target="_blank" class="btn btn-teal btn-sm">
                <i class="bi bi-person-plus me-1"></i> Register New Student
            </a>
        </div>

        <?php display_flash(); ?>

        <div class="content-card">
            <div class="content-card-header">
                <h6 class="content-card-title"><i class="bi bi-people me-2 text-muted"></i>Registered Students (<?= count($students) ?>)</h6>
                <div style="max-width: 250px;">
                    <input type="text" class="form-control form-control-sm" placeholder="Search by name, course, room..." data-table-search="#studentsTable">
                </div>
            </div>
            <div class="table-responsive">
                <table class="table table-custom align-middle" id="studentsTable">
                    <thead>
                        <tr>
                            <th>Student Details</th>
                            <th>Course & Year</th>
                            <th>Allocated Room</th>
                            <th>Emergency Contact</th>
                            <th>Fee Status</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($students)): ?>
                            <tr><td colspan="6" class="text-center py-4 text-muted">No students registered yet.</td></tr>
                        <?php else: ?>
                            <?php foreach ($students as $st): ?>
                                <tr>
                                    <td>
                                        <div class="fw-bold text-dark"><?= htmlspecialchars($st['Name']) ?></div>
                                        <div class="small text-muted"><?= htmlspecialchars($st['Email']) ?></div>
                                        <div class="small text-muted"><i class="bi bi-telephone me-1"></i><?= htmlspecialchars($st['Phone']) ?></div>
                                    </td>
                                    <td>
                                        <div class="fw-semibold text-dark"><?= htmlspecialchars($st['Course']) ?></div>
                                        <div class="small text-muted">Year <?= $st['Year'] ?> &bull; <?= htmlspecialchars($st['Gender']) ?></div>
                                    </td>
                                    <td>
                                        <?php if (!empty($st['RoomNumber'])): ?>
                                            <span class="badge bg-light text-dark border fs-6"><?= htmlspecialchars($st['RoomNumber']) ?></span>
                                            <div class="small text-muted"><?= htmlspecialchars($st['BlockName']) ?></div>
                                        <?php else: ?>
                                            <span class="badge bg-warning text-dark">Unallocated</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="text-dark small fw-semibold"><?= htmlspecialchars($st['EmergencyContact']) ?></div>
                                        <div class="small text-muted"><i class="bi bi-telephone-inbound me-1"></i><?= htmlspecialchars($st['EmergencyPhone']) ?></div>
                                    </td>
                                    <td>
                                        <?php if ($st['pending_fees'] > 0): ?>
                                            <span class="badge bg-danger">Due <?= format_currency($st['pending_fees']) ?></span>
                                        <?php else: ?>
                                            <span class="badge bg-success">Clear</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <button class="btn btn-sm btn-outline-secondary me-1" 
                                                data-bs-toggle="modal" 
                                                data-bs-target="#editStudentModal"
                                                data-id="<?= $st['StudentID'] ?>"
                                                data-name="<?= htmlspecialchars($st['Name']) ?>"
                                                data-email="<?= htmlspecialchars($st['Email']) ?>"
                                                data-phone="<?= htmlspecialchars($st['Phone']) ?>"
                                                data-gender="<?= $st['Gender'] ?>"
                                                data-course="<?= htmlspecialchars($st['Course']) ?>"
                                                data-year="<?= $st['Year'] ?>"
                                                data-econtact="<?= htmlspecialchars($st['EmergencyContact']) ?>"
                                                data-ephone="<?= htmlspecialchars($st['EmergencyPhone']) ?>"
                                                data-addr="<?= htmlspecialchars($st['Address'] ?? '') ?>">
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                        <form method="POST" action="students.php" class="d-inline" onsubmit="return confirm('Are you sure you want to delete this student and their login account?');">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="student_id" value="<?= $st['StudentID'] ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </form>
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

<!-- Modal: Edit Student -->
<div class="modal fade" id="editStudentModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <form method="POST" action="students.php" class="modal-content needs-validation" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="student_id" id="edit_student_id">
            <div class="modal-header">
                <h5 class="modal-title fw-bold">Edit Student Profile</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Full Name *</label>
                        <input type="text" name="name" id="edit_name" class="form-control" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Email *</label>
                        <input type="email" name="email" id="edit_email" class="form-control" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Phone *</label>
                        <input type="text" name="phone" id="edit_phone" class="form-control" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Gender *</label>
                        <select name="gender" id="edit_gender" class="form-select" required>
                            <option value="Male">Male</option>
                            <option value="Female">Female</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Course *</label>
                        <input type="text" name="course" id="edit_course" class="form-control" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Year of Study *</label>
                        <select name="year" id="edit_year" class="form-select" required>
                            <option value="1">1st Year</option>
                            <option value="2">2nd Year</option>
                            <option value="3">3rd Year</option>
                            <option value="4">4th Year</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Emergency Contact Person *</label>
                        <input type="text" name="emergency_contact" id="edit_econtact" class="form-control" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Emergency Phone *</label>
                        <input type="text" name="emergency_phone" id="edit_ephone" class="form-control" required>
                    </div>
                    <div class="col-12">
                        <label class="form-label small fw-semibold">Permanent Address</label>
                        <textarea name="address" id="edit_address" class="form-control" rows="2"></textarea>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-teal">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const editModal = document.getElementById('editStudentModal');
    editModal.addEventListener('show.bs.modal', function (event) {
        const btn = event.relatedTarget;
        document.getElementById('edit_student_id').value = btn.getAttribute('data-id');
        document.getElementById('edit_name').value = btn.getAttribute('data-name');
        document.getElementById('edit_email').value = btn.getAttribute('data-email');
        document.getElementById('edit_phone').value = btn.getAttribute('data-phone');
        document.getElementById('edit_gender').value = btn.getAttribute('data-gender');
        document.getElementById('edit_course').value = btn.getAttribute('data-course');
        document.getElementById('edit_year').value = btn.getAttribute('data-year');
        document.getElementById('edit_econtact').value = btn.getAttribute('data-econtact');
        document.getElementById('edit_ephone').value = btn.getAttribute('data-ephone');
        document.getElementById('edit_address').value = btn.getAttribute('data-addr');
    });
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
