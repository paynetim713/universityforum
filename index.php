<?php
session_start();
require_once 'includes/config.php';

if(isset($_SESSION['user_id'])) {
    header("Location: forum.php");
    exit();
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = $_POST['username'];
    $password = $_POST['password'];
    
    if (empty($username) || empty($password)) {
        $error = "Please enter your username and password";
    } else {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch();
        
        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['is_admin'] = $user['is_admin'];
            
            header("Location: forum.php");
            exit();
        } else {
            $error = "Invalid username or password";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>UKM NEXUS - Welcome Back</title>
    <link rel="stylesheet" href="style.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .login-navbar {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            height: 72px;
            padding: 0 1.5rem;
            background: linear-gradient(to right, #312e81, #4338ca, #6366f1);
            display: flex;
            align-items: center;
            justify-content: space-between;
            z-index: 1000;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
        }

        .login-nav-brand {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            color: rgba(255, 255, 255, 0.9);
            text-decoration: none;
            font-weight: 600;
            font-size: 1.125rem;
        }

        .login-nav-logo {
            width: 80px;
            height: 50px;
            max-width: 100%;
            max-height: 100%;
            filter: brightness(1.1);
            transition: var(--transition-fast);
        }

        .login-nav-logo:hover {
            filter: brightness(1.3);
        }

        .login-nav-logo-fallback {
            width: 40px;
            height: 40px;
            border-radius: 8px;
            background: linear-gradient(45deg, #6366f1, #8b5cf6);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
        }

        .login-nav-actions {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .login-nav-link {
            color: rgba(255, 255, 255, 0.85);
            text-decoration: none;
            padding: 0.5rem 1rem;
            border-radius: 0.5rem;
            transition: all 0.2s ease;
            font-size: 0.9375rem;
        }

        .login-nav-link:hover {
            color: #fff;
            background-color: rgba(255, 255, 255, 0.1);
        }

        .login-nav-link.btn-primary {
            background: linear-gradient(45deg, #6366f1, #8b5cf6);
            color: white;
            border: none;
        }

        .login-nav-link.btn-primary:hover {
            background: linear-gradient(45deg, #5558d6, #714bd3);
            transform: translateY(-1px);
        }

        @media (max-width: 768px) {
            .login-navbar {
                padding: 0 1rem;
            }

            .login-nav-brand .logo-text h1 {
                font-size: 1rem;
            }

            .login-nav-brand .logo-text p {
                font-size: 0.6rem;
            }

            .login-nav-logo {
                width: 35px;
                height: 35px;
            }

            .login-nav-logo-fallback {
                width: 35px;
                height: 35px;
            }

            .login-nav-actions {
                gap: 0.5rem;
            }

            .login-nav-link {
                padding: 0.5rem 0.75rem;
                font-size: 0.875rem;
            }
        }

        @media (max-width: 480px) {
            .login-navbar {
                height: 64px;
            }

            .page-wrapper {
                padding-top: 64px;
            }

            .login-nav-brand .logo-text p {
                display: none;
            }

            .login-nav-logo {
                width: 32px;
                height: 32px;
            }

            .login-nav-logo-fallback {
                width: 32px;
                height: 32px;
                font-size: 0.8rem;
            }
        }

        @media (max-width: 768px) {
            .container {
                padding: 0 1rem;
            }

            .card {
                margin: 1rem 0;
                padding: 1.5rem;
            }

            .form-control {
                font-size: 16px; 
                padding: 0.875rem 1rem;
            }

            .btn {
                font-size: 1rem;
                padding: 0.875rem 1.5rem;
                width: 100%;
            }

            .text-center .btn {
                width: auto;
                min-width: 120px;
            }
        }
    </style>
</head>
<body>
    <div class="page-wrapper">
        <nav class="login-navbar">
            <a href="index.php" class="login-nav-brand">
                <?php if (file_exists('assets/images/logo.jpg')): ?>
                    <img src="assets/images/logo.jpg" alt="UKM Logo" class="login-nav-logo">
                <?php else: ?>
                    <div class="login-nav-logo-fallback">
                        <i class="fas fa-graduation-cap"></i>
                    </div>
                <?php endif; ?>
                <div class="logo-text">
                    <h1>UKM NEXUS</h1>
                    <p>Student Community</p>
                </div>
            </a>

        </nav>

        <main class="main-content">
            <div class="container">
                <div class="card mx-auto" style="max-width: 400px;">
                    <div class="text-center mb-4">
                        <h1 class="mb-2">Welcome Back</h1>
                        <p class="text-secondary">Sign in to continue to UKM NEXUS</p>
                    </div>

                    <?php if ($error): ?>
                        <div class="alert alert-error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
                    <?php endif; ?>

                    <form method="POST" action="" class="fade-in">
                        <div class="form-group">
                            <label class="form-label" for="username">Username</label>
                            <input type="text" id="username" name="username" class="form-control" 
                                   required placeholder="Enter your username"
                                   value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username'], ENT_QUOTES, 'UTF-8') : ''; ?>">
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="password">Password</label>
                            <input type="password" id="password" name="password" class="form-control"
                                   required placeholder="Enter your password">
                        </div>

                        <div class="text-center">
                            <button type="submit" class="btn btn-primary inline-block px-6 mx-auto">Sign In</button>
                        </div>

                        <div class="text-center mt-4">
                            <p class="text-secondary">
                                <a href="forgot_password.php" class="text-primary">
                                    <i class="fas fa-key"></i> Forgot your password?
                                </a>
                            </p>
                            <p class="text-secondary">
                                New to UKM NEXUS? 
                                <a href="register.php" class="text-primary">Create an account</a>
                            </p>
                        </div>
                    </form>
                </div>
            </div>
        </main>
    </div>

    <script>
        // 自动聚焦到用户名输入框
        document.addEventListener('DOMContentLoaded', function() {
            const usernameInput = document.getElementById('username');
            if (usernameInput) {
                setTimeout(function() {
                    usernameInput.focus();
                }, 300);
            }
        });

        // 防止表单重复提交
        const form = document.querySelector('form');
        if (form) {
            form.addEventListener('submit', function() {
                const submitBtn = this.querySelector('button[type="submit"]');
                if (submitBtn) {
                    submitBtn.disabled = true;
                    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Signing In...';
                    
                    // 5秒后重新启用按钮（防止卡住）
                    setTimeout(function() {
                        submitBtn.disabled = false;
                        submitBtn.innerHTML = 'Sign In';
                    }, 5000);
                }
            });
        }
    </script>
</body>
</html>