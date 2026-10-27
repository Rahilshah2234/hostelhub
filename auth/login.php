<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/auth.php';

// Redirect if already logged in
if (is_logged_in()) {
    match (strtolower(current_role())) {
        'admin'   => redirect('admin/dashboard.php'),
        'warden'  => redirect('warden/dashboard.php'),
        'student' => redirect('student/dashboard.php'),
        default   => redirect('index.php'),
    };
}

$error = '';
$usernameVal = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf()) {
        $error = 'Security session expired. Please refresh and try again.';
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = trim($_POST['password'] ?? '');
        $usernameVal = htmlspecialchars($username);

        if (empty($username) || empty($password)) {
            $error = 'Please enter both username and password.';
        } else {
            // Find user in logins table
            $stmt = $pdo->prepare("SELECT * FROM logins WHERE Username = :username LIMIT 1");
            $stmt->execute([':username' => $username]);
            $user = $stmt->fetch();

            if ($user) {
                $isPasswordCorrect = false;

                // Support standard password_verify() and plain-text fallback (auto-upgrade to bcrypt)
                if (password_verify($password, $user['Password'])) {
                    $isPasswordCorrect = true;
                } elseif ($user['Password'] === $password) {
                    $isPasswordCorrect = true;
                    // Auto-upgrade plain-text password to secure BCRYPT hash in DB
                    $newHash = password_hash($password, PASSWORD_BCRYPT);
                    $upStmt = $pdo->prepare("UPDATE logins SET Password = :pwd WHERE LoginID = :id");
                    $upStmt->execute([':pwd' => $newHash, ':id' => $user['LoginID']]);
                }

                if ($isPasswordCorrect) {
                    // Prevent session fixation
                    session_regenerate_id(true);

                    // Populate session
                    $_SESSION['user'] = [
                        'LoginID'   => $user['LoginID'],
                        'Username'  => $user['Username'],
                        'Role'      => $user['Role'],
                        'StudentID' => $user['StudentID'],
                        'WardenID'  => $user['WardenID'],
                        'AdminID'   => $user['AdminID']
                    ];

                    // Fetch full profile name
                    if ($user['Role'] === 'Admin') {
                        $pStmt = $pdo->prepare("SELECT Name FROM admins WHERE AdminID = :id");
                        $pStmt->execute([':id' => $user['AdminID']]);
                        $profile = $pStmt->fetch();
                        $_SESSION['user']['FullName'] = $profile['Name'] ?? $user['Username'];
                        set_flash('success', 'Welcome back, ' . $_SESSION['user']['FullName'] . '!');
                        redirect('admin/dashboard.php');
                    } elseif ($user['Role'] === 'Warden') {
                        $pStmt = $pdo->prepare("SELECT Name, BlockID FROM wardens WHERE WardenID = :id");
                        $pStmt->execute([':id' => $user['WardenID']]);
                        $profile = $pStmt->fetch();
                        $_SESSION['user']['FullName'] = $profile['Name'] ?? $user['Username'];
                        $_SESSION['user']['BlockID'] = $profile['BlockID'] ?? null;
                        set_flash('success', 'Welcome back, Warden ' . $_SESSION['user']['FullName'] . '!');
                        redirect('warden/dashboard.php');
                    } else {
                        $pStmt = $pdo->prepare("SELECT Name, RoomID FROM students WHERE StudentID = :id");
                        $pStmt->execute([':id' => $user['StudentID']]);
                        $profile = $pStmt->fetch();
                        $_SESSION['user']['FullName'] = $profile['Name'] ?? $user['Username'];
                        $_SESSION['user']['RoomID'] = $profile['RoomID'] ?? null;
                        set_flash('success', 'Welcome back, ' . $_SESSION['user']['FullName'] . '!');
                        redirect('student/dashboard.php');
                    }
                } else {
                    $error = 'Invalid username or password.';
                }
            } else {
                $error = 'Invalid username or password.';
            }
        }
    }
}

$pageTitle = 'Login - ' . APP_NAME;
require_once __DIR__ . '/../includes/header.php';
?>

<div class="container py-5 my-auto">
    <div class="row justify-content-center">
        <div class="col-md-7 col-lg-5">
            <div class="content-card shadow-sm border-0">
                <div class="p-4 p-md-5">
                    <div class="text-center mb-4">
                        <div class="brand-icon-box mx-auto mb-3" style="width: 48px; height: 48px; font-size: 1.5rem;">
                            <i class="bi bi-buildings"></i>
                        </div>
                        <h3 class="fw-bold text-dark">Sign In to HostelHub</h3>
                        <p class="text-muted small">Enter your credentials to access your dashboard</p>
                    </div>

                    <?php display_flash(); ?>

                    <?php if (!empty($error)): ?>
                        <div class="alert alert-danger py-2 small" role="alert">
                            <i class="bi bi-exclamation-triangle-fill me-2"></i><?= htmlspecialchars($error) ?>
                        </div>
                    <?php endif; ?>

                    <form method="POST" action="login.php" class="needs-validation" novalidate>
                        <?= csrf_field() ?>

                        <div class="mb-3">
                            <label for="username" class="form-label small fw-semibold">Username</label>
                            <div class="input-group">
                                <span class="input-group-text bg-light text-muted"><i class="bi bi-person"></i></span>
                                <input type="text" class="form-control" id="username" name="username" value="<?= $usernameVal ?>" required placeholder="e.g. admin or rahil" autofocus>
                                <div class="invalid-feedback">Please enter your username.</div>
                            </div>
                        </div>


                        <div class="mb-4">
                            <label for="password" class="form-label small fw-semibold">Password</label>
                            <div class="input-group">
                                <span class="input-group-text bg-light text-muted">
                                    <i class="bi bi-lock"></i>
                                </span>

                                <input type="password" class="form-control" id="password" name="password" required placeholder="Enter password">

                                <button type="button"
                                        class="btn btn-outline-secondary"
                                        id="togglePassword"
                                        aria-label="Show password"
                                        aria-controls="password"
                                        aria-pressed="false">
                                    <i class="bi bi-eye" aria-hidden="true"></i>
                                </button>

                                <div class="invalid-feedback">Please enter your password.</div>
                            </div>
                        </div>


                        <div class="d-grid mb-3">
                            <button type="submit" class="btn btn-teal btn-lg" style="font-size: 1rem;">
                                <i class="bi bi-box-arrow-in-right me-2"></i>Sign In
                            </button>
                        </div>
                    </form>

                    <div class="text-center mt-3 pt-3 border-top">
                        <p class="small text-muted mb-0">
                            New student? <a href="<?= BASE_URL ?>/auth/register.php" class="text-decoration-none fw-semibold" style="color: var(--primary-teal);">Register an Account</a>
                        </p>
                    </div>

                    <!-- Quick Demo Credentials Picker -->
                    <div class="mt-4 p-3 bg-light rounded-3 border">
                        <div class="small fw-bold text-secondary mb-2 text-uppercase" style="font-size: 0.72rem; letter-spacing: 0.05em;">
                            <i class="bi bi-key-fill me-1"></i> Quick Demo Accounts (password: password123)
                        </div>
                        <div class="d-flex flex-wrap gap-2">
                            <button type="button" class="btn btn-sm btn-outline-dark" onclick="fillDemo('admin')">Admin</button>
                            <button type="button" class="btn btn-sm btn-outline-dark" onclick="fillDemo('warden_patil')">Warden</button>
                            <button type="button" class="btn btn-sm btn-outline-dark" onclick="fillDemo('rahil')">Student</button>
                        </div>
                    </div>

                </div>
            </div>
        </div>
    </div>
</div>

<script>
function fillDemo(username) {
    document.getElementById('username').value = username;
    document.getElementById('password').value = 'password123';
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
