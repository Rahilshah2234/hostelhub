<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/auth.php';

$isAdmin = is_logged_in() && strtolower(current_role()) === 'admin';

// Non-admin logged-in users should not access registration
if (is_logged_in() && !$isAdmin) {
    redirect('index.php');
}

$errors = [];
$formData = [
    'name' => '', 'username' => '', 'email' => '', 'phone' => '',
    'gender' => 'Male', 'course' => 'B.Tech AI & Data Science', 'year' => '1',
    'emergency_contact' => '', 'emergency_phone' => '', 'address' => ''
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf()) {
        $errors[] = 'Security token expired. Please try again.';
    } else {
        foreach ($formData as $key => $val) {
            $formData[$key] = trim($_POST[$key] ?? '');
        }
        $password = $_POST['password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        // Validations
        if (empty($formData['name'])) $errors[] = 'Full name is required.';
        if (empty($formData['username'])) $errors[] = 'Username is required.';
        if (empty($formData['email']) || !filter_var($formData['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Valid college email is required.';
        if (empty($formData['phone'])) $errors[] = 'Contact phone number is required.';
        if (empty($formData['emergency_contact'])) $errors[] = 'Emergency contact person name is required.';
        if (empty($formData['emergency_phone'])) $errors[] = 'Emergency phone number is required.';
        if (strlen($password) < 6) $errors[] = 'Password must be at least 6 characters.';
        if ($password !== $confirmPassword) $errors[] = 'Passwords do not match.';

        // Check uniqueness of username and email
        if (empty($errors)) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM logins WHERE Username = :user");
            $stmt->execute([':user' => $formData['username']]);
            if ($stmt->fetchColumn() > 0) {
                $errors[] = 'Username is already taken. Please choose another.';
            }

            $stmt = $pdo->prepare("SELECT COUNT(*) FROM students WHERE Email = :email");
            $stmt->execute([':email' => $formData['email']]);
            if ($stmt->fetchColumn() > 0) {
                $errors[] = 'An account with this email address already exists.';
            }
        }

        // Insert into database with transaction
        if (empty($errors)) {
            try {
                $pdo->beginTransaction();

                // 1. Insert student
                $studentStmt = $pdo->prepare("
                    INSERT INTO students (Name, Email, Phone, Gender, Course, Year, EmergencyContact, EmergencyPhone, Address)
                    VALUES (:name, :email, :phone, :gender, :course, :year, :econtact, :ephone, :addr)
                ");
                $studentStmt->execute([
                    ':name'     => $formData['name'],
                    ':email'    => $formData['email'],
                    ':phone'    => $formData['phone'],
                    ':gender'   => $formData['gender'],
                    ':course'   => $formData['course'],
                    ':year'     => (int)$formData['year'],
                    ':econtact' => $formData['emergency_contact'],
                    ':ephone'   => $formData['emergency_phone'],
                    ':addr'     => $formData['address']
                ]);
                $studentId = $pdo->lastInsertId();

                // 2. Insert login record
                $passwordHash = password_hash($password, PASSWORD_BCRYPT);
                $loginStmt = $pdo->prepare("
                    INSERT INTO logins (Username, Password, Role, StudentID)
                    VALUES (:username, :pwd, 'Student', :sid)
                ");
                $loginStmt->execute([
                    ':username' => $formData['username'],
                    ':pwd'      => $passwordHash,
                    ':sid'      => $studentId
                ]);

                $pdo->commit();

                if ($isAdmin) {
                    set_flash('success', "Student account for {$formData['name']} registered successfully!");
                    redirect('admin/students.php');
                } else {
                    set_flash('success', 'Registration successful! You can now log in with your credentials.');
                    redirect('auth/login.php');
                }
            } catch (Exception $e) {
                $pdo->rollBack();
                $errors[] = 'Registration failed: ' . $e->getMessage();
            }
        }
    }
}

$pageTitle = 'Student Registration - ' . APP_NAME;
require_once __DIR__ . '/../includes/header.php';
?>

<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-lg-8">
            <div class="content-card shadow-sm border-0">
                <div class="p-4 p-md-5">
                    <div class="text-center mb-4">
                        <div class="brand-icon-box mx-auto mb-3" style="width: 48px; height: 48px; font-size: 1.5rem;">
                            <i class="bi bi-person-plus"></i>
                        </div>
                        <?php if ($isAdmin): ?>
                            <div class="mb-2"><span class="badge bg-secondary"><i class="bi bi-shield-lock me-1"></i>Admin Mode</span></div>
                            <h3 class="fw-bold text-dark">Register New Student</h3>
                            <p class="text-muted small">Create a new student resident account and login credentials directly from admin panel</p>
                        <?php else: ?>
                            <h3 class="fw-bold text-dark">Student Registration</h3>
                            <p class="text-muted small">Create your student account to apply for hostel rooms and manage payments</p>
                        <?php endif; ?>
                    </div>

                    <?php if (!empty($errors)): ?>
                        <div class="alert alert-danger" role="alert">
                            <ul class="mb-0 ps-3 small">
                                <?php foreach ($errors as $err): ?>
                                    <li><?= htmlspecialchars($err) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>

                    <form method="POST" action="register.php" class="needs-validation" novalidate>
                        <?= csrf_field() ?>

                        <h6 class="text-uppercase text-secondary fw-bold small mb-3" style="letter-spacing: 0.05em;">
                            1. Personal & Student Details
                        </h6>

                        <div class="row g-3 mb-4">
                            <div class="col-md-6">
                                <label class="form-label small fw-semibold">Full Name *</label>
                                <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($formData['name']) ?>" required placeholder="e.g. Rahil Shah">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-semibold">Gender *</label>
                                <select name="gender" class="form-select" required>
                                    <option value="Male" <?= $formData['gender'] === 'Male' ? 'selected' : '' ?>>Male</option>
                                    <option value="Female" <?= $formData['gender'] === 'Female' ? 'selected' : '' ?>>Female</option>
                                    <option value="Other" <?= $formData['gender'] === 'Other' ? 'selected' : '' ?>>Other</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-semibold">Course / Degree *</label>
                                <input type="text" name="course" class="form-control" value="<?= htmlspecialchars($formData['course']) ?>" required placeholder="e.g. B.Tech AI & Data Science">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-semibold">Current Year of Study *</label>
                                <select name="year" class="form-select" required>
                                    <option value="1" <?= $formData['year'] == 1 ? 'selected' : '' ?>>First Year (FE)</option>
                                    <option value="2" <?= $formData['year'] == 2 ? 'selected' : '' ?>>Second Year (SE)</option>
                                    <option value="3" <?= $formData['year'] == 3 ? 'selected' : '' ?>>Third Year (TE)</option>
                                    <option value="4" <?= $formData['year'] == 4 ? 'selected' : '' ?>>Final Year (BE)</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-semibold">Email Address *</label>
                                <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($formData['email']) ?>" required placeholder="name@college.edu">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-semibold">Mobile Phone Number *</label>
                                <input type="tel" name="phone" class="form-control" value="<?= htmlspecialchars($formData['phone']) ?>" required placeholder="+91 9876543210">
                            </div>
                        </div>

                        <h6 class="text-uppercase text-secondary fw-bold small mb-3" style="letter-spacing: 0.05em;">
                            2. Emergency Contact & Residence
                        </h6>

                        <div class="row g-3 mb-4">
                            <div class="col-md-6">
                                <label class="form-label small fw-semibold">Emergency Contact Person & Relation *</label>
                                <input type="text" name="emergency_contact" class="form-control" value="<?= htmlspecialchars($formData['emergency_contact']) ?>" required placeholder="e.g. Mukesh Shah (Father)">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-semibold">Emergency Contact Phone Number *</label>
                                <input type="tel" name="emergency_phone" class="form-control" value="<?= htmlspecialchars($formData['emergency_phone']) ?>" required placeholder="+91 9823011223">
                            </div>
                            <div class="col-12">
                                <label class="form-label small fw-semibold">Permanent Home Address</label>
                                <textarea name="address" class="form-control" rows="2" placeholder="House/Flat No., Street, City, State, PIN"><?= htmlspecialchars($formData['address']) ?></textarea>
                            </div>
                        </div>

                        <h6 class="text-uppercase text-secondary fw-bold small mb-3" style="letter-spacing: 0.05em;">
                            3. Account Credentials
                        </h6>

                        <div class="row g-3 mb-4">
                            <div class="col-md-4">
                                <label class="form-label small fw-semibold">Choose Username *</label>
                                <input type="text" name="username" class="form-control" value="<?= htmlspecialchars($formData['username']) ?>" required placeholder="e.g. rahil_shah">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small fw-semibold">Password *</label>
                                <input type="password" name="password" class="form-control" required placeholder="Min 6 characters">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small fw-semibold">Confirm Password *</label>
                                <input type="password" name="confirm_password" class="form-control" required placeholder="Repeat password">
                            </div>
                        </div>

                        <div class="d-grid pt-2">
                            <button type="submit" class="btn btn-teal btn-lg">
                                <i class="bi bi-check-circle me-2"></i>Create Student Account
                            </button>
                        </div>
                    </form>

                    <div class="text-center mt-4 pt-3 border-top">
                        <?php if ($isAdmin): ?>
                            <a href="<?= BASE_URL ?>/admin/students.php" class="small fw-semibold text-decoration-none" style="color: var(--primary-teal);">
                                <i class="bi bi-arrow-left me-1"></i>Back to Student Directory
                            </a>
                        <?php else: ?>
                            <span class="small text-muted">Already registered? </span>
                            <a href="<?= BASE_URL ?>/auth/login.php" class="small fw-semibold text-decoration-none" style="color: var(--primary-teal);">Sign in here</a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
