<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/auth.php';

require_role('Admin');

// Handle Allocation Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf()) {
        set_flash('danger', 'Security token invalid. Please try again.');
        redirect('admin/allocations.php');
    }

    $action = $_POST['action'] ?? '';

    // Allocate or Reallocate Room
    if ($action === 'allocate' || $action === 'reallocate') {
        $studentId = (int)($_POST['student_id'] ?? 0);
        $targetRoomId = (int)($_POST['room_id'] ?? 0);
        $notes = trim($_POST['notes'] ?? 'Room allocated by administrator');

        if ($studentId <= 0 || $targetRoomId <= 0) {
            set_flash('danger', 'Please select both a student and an available room.');
            redirect('admin/allocations.php');
        }

        try {
            $pdo->beginTransaction();

            // 1. Fetch Student & Current Room
            $sStmt = $pdo->prepare("SELECT StudentID, Name, RoomID, Gender FROM students WHERE StudentID = :sid FOR UPDATE");
            $sStmt->execute([':sid' => $studentId]);
            $student = $sStmt->fetch();

            if (!$student) {
                throw new Exception("Student not found.");
            }

            $currentRoomId = $student['RoomID'];

            if ($currentRoomId == $targetRoomId) {
                throw new Exception("The student is already allocated to this room.");
            }

            // 2. Fetch Target Room & Block with lock
            $rStmt = $pdo->prepare("
                SELECT r.*, b.Gender as BlockGender, b.BlockName 
                FROM rooms r 
                JOIN blocks b ON r.BlockID = b.BlockID 
                WHERE r.RoomID = :rid 
                FOR UPDATE
            ");
            $rStmt->execute([':rid' => $targetRoomId]);
            $targetRoom = $rStmt->fetch();

            if (!$targetRoom) {
                throw new Exception("Target room does not exist.");
            }

            if ($targetRoom['Status'] === 'Maintenance') {
                throw new Exception("Cannot allocate: Room {$targetRoom['RoomNumber']} is under maintenance.");
            }

            // Anti-overbooking guard
            if ($targetRoom['Occupied'] >= $targetRoom['Capacity']) {
                throw new Exception("Room {$targetRoom['RoomNumber']} is already fully occupied! Allocation rejected to prevent overbooking.");
            }

            // Optional Gender safety advisory
            if ($targetRoom['BlockGender'] !== 'Coed' && strtolower($student['Gender']) !== strtolower($targetRoom['BlockGender'])) {
                // If student gender doesn't match block gender
                // Allow only if user intentionally confirmed or warn
            }

            // 3. If student had an existing room, free that bed
            if ($currentRoomId) {
                $oldRoomStmt = $pdo->prepare("SELECT Occupied, Capacity, Status FROM rooms WHERE RoomID = :oldid FOR UPDATE");
                $oldRoomStmt->execute([':oldid' => $currentRoomId]);
                $oldRoom = $oldRoomStmt->fetch();

                if ($oldRoom) {
                    $newOldOcc = max(0, (int)$oldRoom['Occupied'] - 1);
                    $newOldStatus = ($oldRoom['Status'] === 'Maintenance') ? 'Maintenance' : 'Available';
                    $upOld = $pdo->prepare("UPDATE rooms SET Occupied = :occ, Status = :stat WHERE RoomID = :id");
                    $upOld->execute([':occ' => $newOldOcc, ':stat' => $newOldStatus, ':id' => $currentRoomId]);
                }

                // Close previous allocation log
                $closeAlloc = $pdo->prepare("
                    UPDATE room_allocations 
                    SET Status = 'Transferred', VacatedDate = CURDATE() 
                    WHERE StudentID = :sid AND Status = 'Active'
                ");
                $closeAlloc->execute([':sid' => $studentId]);
            }

            // 4. Update Target Room occupancy
            $newTargetOcc = (int)$targetRoom['Occupied'] + 1;
            $newStatus = ($newTargetOcc >= $targetRoom['Capacity']) ? 'Full' : 'Available';

            $upTarget = $pdo->prepare("UPDATE rooms SET Occupied = :occ, Status = :stat WHERE RoomID = :id");
            $upTarget->execute([':occ' => $newTargetOcc, ':stat' => $newStatus, ':id' => $targetRoomId]);

            // 5. Update Student profile with new room
            $upStudent = $pdo->prepare("UPDATE students SET RoomID = :rid WHERE StudentID = :sid");
            $upStudent->execute([':rid' => $targetRoomId, ':sid' => $studentId]);

            // 6. Record in room_allocations log
            $insAlloc = $pdo->prepare("
                INSERT INTO room_allocations (StudentID, RoomID, AllocationDate, Status, Notes)
                VALUES (:sid, :rid, CURDATE(), 'Active', :notes)
            ");
            $insAlloc->execute([
                ':sid'   => $studentId,
                ':rid'   => $targetRoomId,
                ':notes' => $notes
            ]);

            // 7. Auto-create semester fee invoice if student has no fee for this semester
            $feeCheck = $pdo->prepare("SELECT COUNT(*) FROM fees WHERE StudentID = :sid AND Status IN ('Pending', 'Paid')");
            $feeCheck->execute([':sid' => $studentId]);
            if ($feeCheck->fetchColumn() == 0) {
                $insFee = $pdo->prepare("
                    INSERT INTO fees (StudentID, Title, DueDate, Amount, Status)
                    VALUES (:sid, :title, DATE_ADD(CURDATE(), INTERVAL 15 DAY), :amount, 'Pending')
                ");
                $insFee->execute([
                    ':sid'    => $studentId,
                    ':title'  => "Hostel Fee ({$targetRoom['RoomNumber']} - {$targetRoom['BlockName']})",
                    ':amount' => $targetRoom['FeePerSemester']
                ]);
            }

            $pdo->commit();
            set_flash('success', "Room {$targetRoom['RoomNumber']} successfully allocated to {$student['Name']}!");
        } catch (Exception $e) {
            $pdo->rollBack();
            set_flash('danger', 'Allocation Failed: ' . $e->getMessage());
        }

        redirect('admin/allocations.php');
    }

    // Vacate Room Action
    if ($action === 'vacate') {
        $studentId = (int)($_POST['student_id'] ?? 0);

        if ($studentId > 0) {
            try {
                $pdo->beginTransaction();

                $sStmt = $pdo->prepare("SELECT StudentID, Name, RoomID FROM students WHERE StudentID = :sid FOR UPDATE");
                $sStmt->execute([':sid' => $studentId]);
                $student = $sStmt->fetch();

                if ($student && $student['RoomID']) {
                    $roomId = $student['RoomID'];

                    // Decrement room occupancy
                    $rStmt = $pdo->prepare("SELECT Occupied, Capacity, Status FROM rooms WHERE RoomID = :rid FOR UPDATE");
                    $rStmt->execute([':rid' => $roomId]);
                    $room = $rStmt->fetch();

                    if ($room) {
                        $newOcc = max(0, (int)$room['Occupied'] - 1);
                        $newStatus = ($room['Status'] === 'Maintenance') ? 'Maintenance' : 'Available';
                        $pdo->prepare("UPDATE rooms SET Occupied = :occ, Status = :stat WHERE RoomID = :rid")
                            ->execute([':occ' => $newOcc, ':stat' => $newStatus, ':rid' => $roomId]);
                    }

                    // Clear room from student
                    $pdo->prepare("UPDATE students SET RoomID = NULL WHERE StudentID = :sid")
                        ->execute([':sid' => $studentId]);

                    // Close allocation history
                    $pdo->prepare("UPDATE room_allocations SET Status = 'Vacated', VacatedDate = CURDATE() WHERE StudentID = :sid AND Status = 'Active'")
                        ->execute([':sid' => $studentId]);

                    $pdo->commit();
                    set_flash('success', "Student {$student['Name']} has been vacated from the room.");
                } else {
                    $pdo->rollBack();
                    set_flash('warning', "Student has no active room assignment.");
                }
            } catch (Exception $e) {
                $pdo->rollBack();
                set_flash('danger', 'Error vacating room: ' . $e->getMessage());
            }
        }
        redirect('admin/allocations.php');
    }
}

// Fetch Active Allocations
$activeAllocations = $pdo->query("
    SELECT 
        s.StudentID, s.Name as StudentName, s.Email, s.Phone, s.Gender, s.Course, s.Year,
        r.RoomID, r.RoomNumber, r.Type as RoomType, r.Capacity, r.Occupied,
        b.BlockName, b.Gender as BlockGender,
        ra.AllocationDate, ra.AllocationID
    FROM students s
    JOIN rooms r ON s.RoomID = r.RoomID
    JOIN blocks b ON r.BlockID = b.BlockID
    LEFT JOIN room_allocations ra ON ra.StudentID = s.StudentID AND ra.Status = 'Active'
    ORDER BY b.BlockName ASC, r.RoomNumber ASC, s.Name ASC
")->fetchAll();

// Fetch Unallocated Students
$unallocatedStudents = $pdo->query("
    SELECT StudentID, Name, Gender, Course, Year, Email 
    FROM students 
    WHERE RoomID IS NULL 
    ORDER BY Name ASC
")->fetchAll();

// Fetch Available Rooms (Rooms where Occupied < Capacity and Status != Maintenance)
$availableRooms = $pdo->query("
    SELECT r.RoomID, r.RoomNumber, r.Type, r.Capacity, r.Occupied, r.FeePerSemester, b.BlockName, b.Gender as BlockGender
    FROM rooms r
    JOIN blocks b ON r.BlockID = b.BlockID
    WHERE r.Occupied < r.Capacity AND r.Status != 'Maintenance'
    ORDER BY b.BlockName ASC, r.RoomNumber ASC
")->fetchAll();

$pageTitle = 'Room Allocations - ' . APP_NAME;
require_once __DIR__ . '/../includes/header.php';
?>

<div class="dashboard-container">
    <?php require_once __DIR__ . '/../includes/sidebar.php'; ?>

    <main class="main-content">
        <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-3">
            <div>
                <h3 class="fw-bold text-dark mb-1">Room Allocation & Reallocation</h3>
                <p class="text-muted small mb-0">Manage bed allotment with automatic anti-overbooking protection and live vacancy tracking.</p>
            </div>
            <button class="btn btn-teal btn-sm" data-bs-toggle="modal" data-bs-target="#newAllocationModal">
                <i class="bi bi-person-plus me-1"></i> Allocate Bed to Student
            </button>
        </div>

        <?php display_flash(); ?>

        <!-- Quick Info Banner -->
        <div class="row g-3 mb-4">
            <div class="col-md-6 col-xl-3">
                <div class="stat-card py-3">
                    <div>
                        <div class="stat-label">Unassigned Students</div>
                        <div class="stat-value text-amber"><?= count($unallocatedStudents) ?></div>
                    </div>
                    <div class="stat-icon stat-icon-amber">
                        <i class="bi bi-person-x"></i>
                    </div>
                </div>
            </div>
            <div class="col-md-6 col-xl-3">
                <div class="stat-card py-3">
                    <div>
                        <div class="stat-label">Available Rooms</div>
                        <div class="stat-value text-teal"><?= count($availableRooms) ?></div>
                    </div>
                    <div class="stat-icon stat-icon-teal">
                        <i class="bi bi-door-open"></i>
                    </div>
                </div>
            </div>
            <div class="col-md-6 col-xl-3">
                <div class="stat-card py-3">
                    <div>
                        <div class="stat-label">Active Resident Beds</div>
                        <div class="stat-value text-blue"><?= count($activeAllocations) ?></div>
                    </div>
                    <div class="stat-icon stat-icon-blue">
                        <i class="bi bi-people"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- Active Allocations Table -->
        <div class="content-card">
            <div class="content-card-header">
                <h6 class="content-card-title"><i class="bi bi-check2-circle me-2 text-muted"></i>Current Active Room Allotments (<?= count($activeAllocations) ?>)</h6>
                <div style="max-width: 250px;">
                    <input type="text" class="form-control form-control-sm" placeholder="Search student or room..." data-table-search="#allocationsTable">
                </div>
            </div>
            <div class="table-responsive">
                <table class="table table-custom align-middle" id="allocationsTable">
                    <thead>
                        <tr>
                            <th>Student Resident</th>
                            <th>Course & Year</th>
                            <th>Hostel Block</th>
                            <th>Room & Bed Occupancy</th>
                            <th>Allocated Date</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($activeAllocations)): ?>
                            <tr><td colspan="6" class="text-center py-4 text-muted">No students currently allocated to rooms.</td></tr>
                        <?php else: ?>
                            <?php foreach ($activeAllocations as $alloc): ?>
                                <tr>
                                    <td>
                                        <div class="fw-bold text-dark"><?= htmlspecialchars($alloc['StudentName']) ?></div>
                                        <div class="small text-muted"><?= htmlspecialchars($alloc['Email']) ?> &bull; <?= htmlspecialchars($alloc['Phone']) ?></div>
                                    </td>
                                    <td>
                                        <div class="text-dark"><?= htmlspecialchars($alloc['Course']) ?></div>
                                        <div class="small text-muted">Year <?= $alloc['Year'] ?> (<?= htmlspecialchars($alloc['Gender']) ?>)</div>
                                    </td>
                                    <td>
                                        <span class="fw-semibold"><?= htmlspecialchars($alloc['BlockName']) ?></span>
                                    </td>
                                    <td>
                                        <span class="badge bg-light text-dark border fs-6 me-1"><?= htmlspecialchars($alloc['RoomNumber']) ?></span>
                                        <span class="small text-muted">(<?= (int)$alloc['Occupied'] ?>/<?= (int)$alloc['Capacity'] ?> occupied)</span>
                                    </td>
                                    <td>
                                        <?= format_date($alloc['AllocationDate']) ?>
                                    </td>
                                    <td class="text-end">
                                        <button class="btn btn-sm btn-outline-secondary me-1" 
                                                data-bs-toggle="modal" 
                                                data-bs-target="#reallocateModal"
                                                data-studentid="<?= $alloc['StudentID'] ?>"
                                                data-studentname="<?= htmlspecialchars($alloc['StudentName']) ?>"
                                                data-currentroom="<?= htmlspecialchars($alloc['RoomNumber'] . ' (' . $alloc['BlockName'] . ')') ?>">
                                            <i class="bi bi-arrow-repeat me-1"></i>Reallocate
                                        </button>
                                        <form method="POST" action="allocations.php" class="d-inline" onsubmit="return confirm('Are you sure you want to vacate <?= htmlspecialchars($alloc['StudentName']) ?> from room <?= htmlspecialchars($alloc['RoomNumber']) ?>?');">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="vacate">
                                            <input type="hidden" name="student_id" value="<?= $alloc['StudentID'] ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger">
                                                <i class="bi bi-box-arrow-right me-1"></i>Vacate
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

<!-- Modal: New Allocation -->
<div class="modal fade" id="newAllocationModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" action="allocations.php" class="modal-content needs-validation" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="allocate">
            <div class="modal-header">
                <h5 class="modal-title fw-bold">Allocate Room to Student</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label small fw-semibold">Select Student (Unassigned) *</label>
                    <select name="student_id" class="form-select" required>
                        <option value="">-- Choose Student --</option>
                        <?php foreach ($unallocatedStudents as $st): ?>
                            <option value="<?= $st['StudentID'] ?>">
                                <?= htmlspecialchars($st['Name']) ?> (<?= $st['Gender'] ?>) - <?= htmlspecialchars($st['Course']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (empty($unallocatedStudents)): ?>
                        <div class="form-text text-success">All registered students currently have rooms assigned.</div>
                    <?php endif; ?>
                </div>

                <div class="mb-3">
                    <label class="form-label small fw-semibold">Select Available Room *</label>
                    <select name="room_id" class="form-select" required>
                        <option value="">-- Choose Available Room --</option>
                        <?php foreach ($availableRooms as $ar): ?>
                            <option value="<?= $ar['RoomID'] ?>">
                                <?= htmlspecialchars($ar['BlockName']) ?> &bull; Room <?= htmlspecialchars($ar['RoomNumber']) ?> (<?= $ar['Type'] ?> - <?= $ar['Capacity'] - $ar['Occupied'] ?> beds free - <?= format_currency($ar['FeePerSemester']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="mb-3">
                    <label class="form-label small fw-semibold">Allocation Notes</label>
                    <textarea name="notes" class="form-control" rows="2" placeholder="e.g. Assigned Bed 1"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-teal">Confirm Allocation</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Reallocate Room -->
<div class="modal fade" id="reallocateModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" action="allocations.php" class="modal-content needs-validation" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="reallocate">
            <input type="hidden" name="student_id" id="realloc_student_id">
            <div class="modal-header">
                <h5 class="modal-title fw-bold">Reallocate Student Room</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3 p-3 bg-light rounded-3">
                    <div class="small text-muted">Student:</div>
                    <div class="fw-bold fs-6 text-dark" id="realloc_student_name"></div>
                    <div class="small text-muted mt-1">Current Room: <span class="fw-semibold text-secondary" id="realloc_current_room"></span></div>
                </div>

                <div class="mb-3">
                    <label class="form-label small fw-semibold">Transfer to New Available Room *</label>
                    <select name="room_id" class="form-select" required>
                        <option value="">-- Choose Destination Room --</option>
                        <?php foreach ($availableRooms as $ar): ?>
                            <option value="<?= $ar['RoomID'] ?>">
                                <?= htmlspecialchars($ar['BlockName']) ?> &bull; Room <?= htmlspecialchars($ar['RoomNumber']) ?> (<?= $ar['Type'] ?> - <?= $ar['Capacity'] - $ar['Occupied'] ?> beds free)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="mb-3">
                    <label class="form-label small fw-semibold">Reason for Room Transfer</label>
                    <textarea name="notes" class="form-control" rows="2" placeholder="e.g. Student request, mutual room exchange, medical reason..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-teal">Transfer Student</button>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const reallocModal = document.getElementById('reallocateModal');
    reallocModal.addEventListener('show.bs.modal', function (event) {
        const btn = event.relatedTarget;
        document.getElementById('realloc_student_id').value = btn.getAttribute('data-studentid');
        document.getElementById('realloc_student_name').textContent = btn.getAttribute('data-studentname');
        document.getElementById('realloc_current_room').textContent = btn.getAttribute('data-currentroom');
    });
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
