<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/auth.php';

require_role('Admin');

// Handle Form Submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf()) {
        set_flash('danger', 'Security token invalid. Please try again.');
        redirect('admin/blocks.php');
    }

    $action = $_POST['action'] ?? '';

    // Add Block
    if ($action === 'add') {
        $name = trim($_POST['block_name'] ?? '');
        $gender = trim($_POST['gender'] ?? 'Coed');
        $desc = trim($_POST['description'] ?? '');

        if (empty($name)) {
            set_flash('danger', 'Block name is required.');
        } else {
            try {
                $stmt = $pdo->prepare("INSERT INTO blocks (BlockName, Gender, Description) VALUES (:name, :gender, :desc)");
                $stmt->execute([':name' => $name, ':gender' => $gender, ':desc' => $desc]);
                set_flash('success', 'Hostel block added successfully!');
            } catch (PDOException $e) {
                set_flash('danger', 'Error: Block name must be unique.');
            }
        }
        redirect('admin/blocks.php');
    }

    // Edit Block
    if ($action === 'edit') {
        $id = (int)($_POST['block_id'] ?? 0);
        $name = trim($_POST['block_name'] ?? '');
        $gender = trim($_POST['gender'] ?? 'Coed');
        $desc = trim($_POST['description'] ?? '');

        if ($id > 0 && !empty($name)) {
            try {
                $stmt = $pdo->prepare("UPDATE blocks SET BlockName = :name, Gender = :gender, Description = :desc WHERE BlockID = :id");
                $stmt->execute([':name' => $name, ':gender' => $gender, ':desc' => $desc, ':id' => $id]);
                set_flash('success', 'Hostel block updated successfully!');
            } catch (PDOException $e) {
                set_flash('danger', 'Error updating block: ' . $e->getMessage());
            }
        }
        redirect('admin/blocks.php');
    }

    // Delete Block
    if ($action === 'delete') {
        $id = (int)($_POST['block_id'] ?? 0);
        if ($id > 0) {
            // Check if rooms exist in this block to prevent accidental CASCADE deletion of rooms & allocations
            $checkRooms = $pdo->prepare("SELECT COUNT(*) FROM rooms WHERE BlockID = :id");
            $checkRooms->execute([':id' => $id]);
            if ($checkRooms->fetchColumn() > 0) {
                set_flash('danger', 'Cannot delete block: Associated rooms exist in this block. Please delete or reassign the rooms first.');
            } else {
                try {
                    $stmt = $pdo->prepare("DELETE FROM blocks WHERE BlockID = :id");
                    $stmt->execute([':id' => $id]);
                    set_flash('success', 'Hostel block deleted successfully.');
                } catch (PDOException $e) {
                    set_flash('danger', 'Cannot delete block: Associated records exist.');
                }
            }
        }
        redirect('admin/blocks.php');
    }
}

// Fetch all blocks with statistics (Subquery avoids Cartesian multiplication and ONLY_FULL_GROUP_BY errors)
$blocks = $pdo->query("
    SELECT 
        b.*,
        COALESCE(r.total_rooms, 0) as total_rooms,
        COALESCE(r.total_capacity, 0) as total_capacity,
        COALESCE(r.total_occupied, 0) as total_occupied,
        (SELECT GROUP_CONCAT(w.Name SEPARATOR ', ') FROM wardens w WHERE w.BlockID = b.BlockID) as WardenName
    FROM blocks b
    LEFT JOIN (
        SELECT 
            BlockID,
            COUNT(RoomID) as total_rooms,
            SUM(Capacity) as total_capacity,
            SUM(Occupied) as total_occupied
        FROM rooms
        GROUP BY BlockID
    ) r ON b.BlockID = r.BlockID
    ORDER BY b.BlockID ASC
")->fetchAll();

$pageTitle = 'Hostel Blocks - ' . APP_NAME;
require_once __DIR__ . '/../includes/header.php';
?>

<div class="dashboard-container">
    <?php require_once __DIR__ . '/../includes/sidebar.php'; ?>

    <main class="main-content">
        <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-3">
            <div>
                <h3 class="fw-bold text-dark mb-1">Hostel Blocks Management</h3>
                <p class="text-muted small mb-0">Configure dormitory blocks, wings, gender separation, and resident capacity.</p>
            </div>
            <button class="btn btn-teal btn-sm" data-bs-toggle="modal" data-bs-target="#addBlockModal">
                <i class="bi bi-plus-lg me-1"></i> Add New Block
            </button>
        </div>

        <?php display_flash(); ?>

        <div class="content-card">
            <div class="content-card-header">
                <h6 class="content-card-title"><i class="bi bi-building me-2 text-muted"></i>All Registered Blocks</h6>
                <div style="max-width: 260px;">
                    <input type="text" class="form-control form-control-sm" placeholder="Filter blocks..." data-table-search="#blocksTable">
                </div>
            </div>
            <div class="table-responsive">
                <table class="table table-custom align-middle" id="blocksTable">
                    <thead>
                        <tr>
                            <th>Block Name</th>
                            <th>Gender Allocation</th>
                            <th>Description</th>
                            <th>Rooms</th>
                            <th>Occupancy (Beds)</th>
                            <th>Assigned Warden</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($blocks)): ?>
                            <tr><td colspan="7" class="text-center py-4 text-muted">No hostel blocks created yet.</td></tr>
                        <?php else: ?>
                            <?php foreach ($blocks as $b): ?>
                                <?php 
                                    $cap = (int)$b['total_capacity'];
                                    $occ = (int)$b['total_occupied'];
                                    $pct = $cap > 0 ? round(($occ / $cap) * 100) : 0;
                                ?>
                                <tr>
                                    <td>
                                        <div class="fw-bold text-dark"><?= htmlspecialchars($b['BlockName']) ?></div>
                                        <span class="small text-muted">ID #<?= $b['BlockID'] ?></span>
                                    </td>
                                    <td>
                                        <span class="badge <?= $b['Gender'] === 'Boys' ? 'bg-primary' : ($b['Gender'] === 'Girls' ? 'bg-danger' : 'bg-secondary') ?>">
                                            <?= htmlspecialchars($b['Gender']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="text-muted small" style="max-width: 250px;"><?= htmlspecialchars($b['Description'] ?? 'None') ?></div>
                                    </td>
                                    <td>
                                        <span class="fw-semibold"><?= (int)$b['total_rooms'] ?></span> rooms
                                    </td>
                                    <td>
                                        <div class="d-flex align-items-center gap-2">
                                            <div class="progress flex-grow-1" style="height: 6px; width: 70px;">
                                                <div class="progress-bar bg-teal" style="background-color: var(--primary-teal); width: <?= $pct ?>%;"></div>
                                            </div>
                                            <span class="small fw-semibold"><?= $occ ?> / <?= $cap ?></span>
                                        </div>
                                    </td>
                                    <td>
                                        <?= !empty($b['WardenName']) ? '<i class="bi bi-person-check text-success me-1"></i>' . htmlspecialchars($b['WardenName']) : '<span class="text-muted fst-italic">Not assigned</span>' ?>
                                    </td>
                                    <td class="text-end">
                                        <button class="btn btn-sm btn-outline-secondary me-1" 
                                                data-bs-toggle="modal" 
                                                data-bs-target="#editBlockModal"
                                                data-id="<?= $b['BlockID'] ?>"
                                                data-name="<?= htmlspecialchars($b['BlockName']) ?>"
                                                data-gender="<?= $b['Gender'] ?>"
                                                data-desc="<?= htmlspecialchars($b['Description'] ?? '') ?>">
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                        <form method="POST" action="blocks.php" class="d-inline" onsubmit="return confirm('Are you sure you want to delete this block?');">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="block_id" value="<?= $b['BlockID'] ?>">
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

<!-- Modal: Add Block -->
<div class="modal fade" id="addBlockModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" action="blocks.php" class="modal-content needs-validation" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add">
            <div class="modal-header">
                <h5 class="modal-title fw-bold">Add New Hostel Block</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label small fw-semibold">Block Name *</label>
                    <input type="text" name="block_name" class="form-control" required placeholder="e.g. Block D (Freshers)">
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-semibold">Gender Type *</label>
                    <select name="gender" class="form-select" required>
                        <option value="Boys">Boys</option>
                        <option value="Girls">Girls</option>
                        <option value="Coed">Coed</option>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-semibold">Description</label>
                    <textarea name="description" class="form-control" rows="3" placeholder="Brief block notes, floors, facilities..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-teal">Save Block</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Edit Block -->
<div class="modal fade" id="editBlockModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" action="blocks.php" class="modal-content needs-validation" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="block_id" id="edit_block_id">
            <div class="modal-header">
                <h5 class="modal-title fw-bold">Edit Hostel Block</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label small fw-semibold">Block Name *</label>
                    <input type="text" name="block_name" id="edit_block_name" class="form-control" required>
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-semibold">Gender Type *</label>
                    <select name="gender" id="edit_gender" class="form-select" required>
                        <option value="Boys">Boys</option>
                        <option value="Girls">Girls</option>
                        <option value="Coed">Coed</option>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-semibold">Description</label>
                    <textarea name="description" id="edit_description" class="form-control" rows="3"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-teal">Update Block</button>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const editModal = document.getElementById('editBlockModal');
    editModal.addEventListener('show.bs.modal', function (event) {
        const button = event.relatedTarget;
        document.getElementById('edit_block_id').value = button.getAttribute('data-id');
        document.getElementById('edit_block_name').value = button.getAttribute('data-name');
        document.getElementById('edit_gender').value = button.getAttribute('data-gender');
        document.getElementById('edit_description').value = button.getAttribute('data-desc');
    });
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
