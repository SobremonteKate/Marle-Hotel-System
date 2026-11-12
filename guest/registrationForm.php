<?php
include('../dbconnect.php');
include('../superadmin/auth.php');
include('../csrf.php');

$notif   = '';
$message = '';

if (isset($_POST['btnRegister'])) {

    require_csrf_token();

    $username        = trim($_POST['username']);
    $password        = trim($_POST['newPassword']);
    $confirmPassword = trim($_POST['confirmPassword']);
    $usertype        = trim($_POST['usertype']);

    // Validate usertype
    $allowedTypes = ['admin', 'employee'];

    if (empty($username) || empty($password) || empty($confirmPassword) || empty($usertype)) {
        $notif = 'Please complete all fields.';
    } elseif (!in_array($usertype, $allowedTypes)) {
        $notif = 'Invalid account type selected.';
    } elseif ($password !== $confirmPassword) {
        $notif = 'Passwords do not match.';
    } elseif (strlen($password) < 6) {
        $notif = 'Password must be at least 6 characters.';
    } elseif (preg_match('/[\?~`@#$%^&*\-+=>{}]/', $username)) {
        $notif = 'No special characters allowed in username.';
    } else {
        $checkUser = mysqli_prepare($conn, "SELECT id FROM userstbl WHERE username = ? LIMIT 1");
        mysqli_stmt_bind_param($checkUser, 's', $username);
        mysqli_stmt_execute($checkUser);
        mysqli_stmt_store_result($checkUser);
        if (mysqli_stmt_num_rows($checkUser) > 0) {
            $notif = 'Username already exists.';
        } else {
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
            $insert = mysqli_prepare($conn,
                "INSERT INTO userstbl (username, password, usertype) VALUES (?, ?, ?)"
            );
            mysqli_stmt_bind_param($insert, 'sss', $username, $hashedPassword, $usertype);
            if (mysqli_stmt_execute($insert)) {
                $message = 'Registration successful! You can now log in.';
            } else {
                $notif = 'Registration failed. Please try again.';
            }
            mysqli_stmt_close($insert);
        }
        mysqli_stmt_close($checkUser);
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.6/dist/css/bootstrap.min.css" rel="stylesheet"
        integrity="sha384-4Q6Gf2aSP4eDXB8Miphtr37CMZZQ5oXLH2yaXMJ2w8e2ZtHTl7GptT4jmndRuHDT" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.lineicons.com/5.0/lineicons.css" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/7.0.1/css/all.min.css">
    <link rel="icon" href="../img/reception.png">

    <title>Register</title>

    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: "Segoe UI", sans-serif;
            background: #f2ece9;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 40px 20px;
        }

        .register-wrapper {
            width: 100%;
            max-width: 460px;
        }

        .register-logo {
            width: 130px;
            display: block;
            margin: 0 auto 10px auto;
        }

        .main-title {
            font-size: 16px;
            font-weight: 500;
            margin-bottom: 28px;
            color: #111;
            text-align: center;
        }

        .register-card {
            background: #fff;
            padding: 35px 30px;
            border-radius: 20px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.08);
        }

        .register-card h5 {
            font-size: 20px;
            font-weight: 600;
            color: #333;
            margin-bottom: 22px;
            text-align: center;
        }

        .form-label {
            font-size: 13px;
            font-weight: 600;
            color: #555;
            margin-bottom: 5px;
        }

        .form-control,
        .form-select {
            height: 50px;
            border-radius: 12px;
            border: 1px solid #ffb60a;
            font-size: 15px;
        }

        .form-control:focus,
        .form-select:focus {
            box-shadow: none;
            border-color: #933310;
        }

        /* Usertype pills */
        .usertype-group {
            display: flex;
            gap: 12px;
        }

        .usertype-group input[type="radio"] { display: none; }

        .usertype-group label {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 6px;
            padding: 14px 10px;
            border: 2px solid #e0e0e0;
            border-radius: 14px;
            cursor: pointer;
            transition: all 0.2s ease;
            font-size: 13px;
            font-weight: 600;
            color: #777;
        }

        .usertype-group label i {
            font-size: 22px;
        }

        .usertype-group input[type="radio"]:checked + label {
            border-color: #ffb60a;
            background-color: #fff8e6;
            color: #c47f00;
        }

        .btn-register {
            width: 100%;
            height: 50px;
            font-size: 17px;
            border-radius: 12px;
            background: #ffb60a;
            color: #fff;
            border: none;
            transition: 0.3s;
            margin-top: 6px;
        }

        .btn-register:hover {
            background-color: transparent;
            border: 2px solid #933310;
            color: #933310;
        }

        .login-link {
            text-align: center;
            margin-top: 18px;
            font-size: 14px;
            color: #777;
        }

        .login-link a {
            display: inline-block;
            min-width: 40px;
            padding-block: 11px;
            margin-block: -11px;
            color: #ffb60a;
            text-decoration: none;
            font-weight: 600;
        }

        .login-link a:hover { color: #933310; }

        .input-icon-wrap {
            position: relative;
        }

        /* 40×40 target around a 17px glyph; the larger box is centred on the
           same spot, so the icon does not move. */
        .input-icon-wrap .toggle-eye {
            position: absolute;
            right: 2px;
            top: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 40px;
            height: 40px;
            transform: translateY(-50%);
            cursor: pointer;
            color: #aaa;
        }

        .alert { border-radius: 12px; font-size: 14px; }
    </style>
</head>

<body>
    <div class="register-wrapper">

        <img src="../img/logo2.png" alt="logo" class="register-logo">
        <p class="main-title"><i>Excellence in Service, Everyday</i></p>

        <!-- Alerts -->
        <?php if (!empty($notif)): ?>
            <div class="alert alert-danger alert-dismissible fade show auto-hide" role="alert">
                <?php echo htmlspecialchars($notif); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if (!empty($message)): ?>
            <div class="alert alert-success alert-dismissible fade show auto-hide" role="alert">
                <?php echo htmlspecialchars($message); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <div class="register-card">
            <h5><i class="lni lni-user me-2"></i>Create an Account</h5>

            <form method="POST">

                <!-- Account Type -->
                <div class="mb-4">
                    <label class="form-label">Account Type</label>
                    <div class="usertype-group">

                        <input type="radio" id="typeAdmin" name="usertype" value="admin"
                            <?php echo (isset($_POST['usertype']) && $_POST['usertype'] === 'admin') ? 'checked' : ''; ?>>
                        <label for="typeAdmin">
                            <i class="lni lni-shield"></i>
                            Admin
                        </label>

                        <input type="radio" id="typeEmployee" name="usertype" value="employee"
                            <?php echo (!isset($_POST['usertype']) || $_POST['usertype'] === 'employee') ? 'checked' : ''; ?>>
                        <label for="typeEmployee">
                            <i class="lni lni-users"></i>
                            Employee
                        </label>

                    </div>
                </div>

                <!-- Username -->
                <div class="mb-3">
                    <label class="form-label" for="username">Username</label>
                    <input type="text" class="form-control" id="username" name="username"
                           placeholder="Enter username" autocomplete="off"
                           value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>"
                           required>
                </div>

                <!-- Password -->
                <div class="mb-3">
                    <label class="form-label" for="newPassword">Password</label>
                    <div class="input-icon-wrap">
                        <input type="password" class="form-control pe-5" id="newPassword"
                               name="newPassword" placeholder="Enter password" required>
                        <span class="toggle-eye" onclick="togglePass('newPassword', 'eye1')">
                            <i class="lni lni-eye" id="eye1"></i>
                        </span>
                    </div>
                </div>

                <!-- Confirm Password -->
                <div class="mb-4">
                    <label class="form-label" for="confirmPassword">Confirm Password</label>
                    <div class="input-icon-wrap">
                        <input type="password" class="form-control pe-5" id="confirmPassword"
                               name="confirmPassword" placeholder="Re-enter password" required>
                        <span class="toggle-eye" onclick="togglePass('confirmPassword', 'eye2')">
                            <i class="lni lni-eye" id="eye2"></i>
                        </span>
                    </div>
                </div>

                <button type="submit" name="btnRegister" class="btn-register">
                    Register
                </button>

            </form>

            <div class="login-link">
                Already have an account? <a href="../login.php">Log in</a>
            </div>

        </div>
    </div>

    <script>
        function togglePass(fieldId, iconId) {
            const f = document.getElementById(fieldId);
            const i = document.getElementById(iconId);
            if (f.type === 'password') {
                f.type = 'text';
                i.classList.replace('lni-eye', 'lni-eye-slash');
            } else {
                f.type = 'password';
                i.classList.replace('lni-eye-slash', 'lni-eye');
            }
        }
    </script>

    <script>
        document.addEventListener("DOMContentLoaded", function () {
            document.querySelectorAll(".alert.auto-hide").forEach(alert => {
                setTimeout(() => {
                    alert.classList.remove("show");
                    alert.classList.add("hide");
                    setTimeout(() => alert.remove(), 300);
                }, 3500);
            });
        });
    </script>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.6/dist/js/bootstrap.bundle.min.js"
        integrity="sha384-j1CDi7MgGQ12Z7Qab0qlWQ/Qqz24Gc6BM0thvEMVjHnfYGF0rmFCozFSxQBxwHKO"
        crossorigin="anonymous"></script>
</body>
</html>