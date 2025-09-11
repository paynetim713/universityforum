<?php
session_start();
require_once 'includes/config.php';

// 如果已经登录，重定向到论坛
if(isset($_SESSION['user_id'])) {
    header("Location: forum.php");
    exit();
}

// SMTP 邮件设置
$smtp_server = "smtp.gmail.com";  
$smtp_port = 465;  
$sender_email = "aslm2l1k123@gmail.com";
$sender_password = "lcfypbwumaufqnlj";

$error = '';
$success = '';

// 处理重置码发送请求 
if (isset($_POST['send_reset_code'])) {
    $email = trim($_POST['email']);
    
    // 验证邮箱格式
    if (!preg_match('/^[a-zA-Z0-9]+@siswa\.ukm\.edu\.my$/', $email)) {
        echo json_encode(['status' => 'error', 'message' => 'Please use your UKM student email (@siswa.ukm.edu.my).']);
        exit;
    }
    
    // 检查邮箱是否已注册 
    $stmt = $pdo->prepare("SELECT id, username FROM users WHERE email = ?");
    $stmt->execute([$email]);
    if ($stmt->rowCount() == 0) {
        echo json_encode(['status' => 'error', 'message' => 'This email is not registered in our system.']);
        exit;
    }
    
    $user = $stmt->fetch();
    
    // 调用Python脚本发送重置码 
    $command = escapeshellcmd("python3 send_verification_email.py " . 
               escapeshellarg($email) . " " . 
               escapeshellarg($smtp_server) . " " . 
               escapeshellarg($smtp_port) . " " . 
               escapeshellarg($sender_email) . " " . 
               escapeshellarg($sender_password));
    
    $output = shell_exec($command);
    $result = explode("|", $output);
    
    if (isset($result[0]) && $result[0] != "error") {
        $reset_code = trim($result[0]);
        
        // 检查数据库中是否已有password表
        try {
            $pdo->query("SELECT 1 FROM password_reset_codes LIMIT 1");
        } catch (PDOException $e) {
            // 没有就创建 
            $pdo->exec("CREATE TABLE password_reset_codes (
                id INT AUTO_INCREMENT PRIMARY KEY,
                email VARCHAR(100) NOT NULL UNIQUE,
                code VARCHAR(10) NOT NULL,
                expiry_time DATETIME NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )");
        }
      
        // 15分钟过期时间
        $expiry_time = date('Y-m-d H:i:s', strtotime('+15 minutes'));
        
        // 检查是否已有此邮箱的重置码记录
        $stmt = $pdo->prepare("SELECT id FROM password_reset_codes WHERE email = ?");
        $stmt->execute([$email]);
        
        if ($stmt->rowCount() > 0) {
            // 更新现有记录
            $stmt = $pdo->prepare("UPDATE password_reset_codes SET code = ?, expiry_time = ? WHERE email = ?");
            $stmt->execute([$reset_code, $expiry_time, $email]);
        } else {
            // 插入新记录
            $stmt = $pdo->prepare("INSERT INTO password_reset_codes (email, code, expiry_time) VALUES (?, ?, ?)");
            $stmt->execute([$email, $reset_code, $expiry_time]);
        }
        
        echo json_encode(['status' => 'success', 'message' => 'Reset code sent to your email.']);
    } else {
        $error_message = isset($result[1]) ? trim($result[1]) : "Failed to send reset code.";
        echo json_encode(['status' => 'error', 'message' => $error_message]);
    }
    exit;
}

// 验证重置码
if (isset($_POST['verify_reset_code'])) {
    $email = trim($_POST['email']);
    $code = trim($_POST['code']);
    
    // 检查是否正确
    $stmt = $pdo->prepare("SELECT code, expiry_time FROM password_reset_codes WHERE email = ?");
    $stmt->execute([$email]);
    
    if ($stmt->rowCount() == 0) {
        echo json_encode(['status' => 'error', 'message' => 'Please request a reset code first.']);
        exit;
    }
    
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $db_code = $row['code'];
    $expiry_time = $row['expiry_time'];
    
    // 检查时间是否过期
    if (strtotime($expiry_time) < time()) {
        echo json_encode(['status' => 'error', 'message' => 'Reset code has expired. Please request a new one.']);
        exit;
    }
    
    // 检查验证码是否正确
    if ($code != $db_code) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid reset code.']);
    } else {
        echo json_encode(['status' => 'success', 'message' => 'Code verified successfully.']);
    }
    exit;
}

// 处理密码重置 
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['reset_password'])) {
    $email = trim($_POST['email']);
    $code = trim($_POST['reset_code']);
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];
    
    // 验证用户的输入
    if (empty($email) || empty($code) || empty($new_password) || empty($confirm_password)) {
        $error = "All fields are required.";
    } elseif (strlen($new_password) < 6) {
        $error = "Password must be at least 6 characters.";
    } elseif ($new_password !== $confirm_password) {
        $error = "Passwords do not match.";
    } else {
        // 验证重置码
        $stmt = $pdo->prepare("SELECT code, expiry_time FROM password_reset_codes WHERE email = ?");
        $stmt->execute([$email]);
        
        if ($stmt->rowCount() == 0) {
            $error = "Invalid reset request.";
        } else {
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $db_code = $row['code'];
            $expiry_time = $row['expiry_time'];
            
            // 检查验证码是否过期
            if (strtotime($expiry_time) < time()) {
                $error = "Reset code has expired. Please request a new one.";
            }
            // 检查验证码是否正确
            elseif ($code != $db_code) {
                $error = "Invalid reset code.";
            } else {
                // 更新密码
                $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE email = ?");
                
                if ($stmt->execute([$hashed_password, $email])) {
                    // 删除重置码记录
                    $delete_stmt = $pdo->prepare("DELETE FROM password_reset_codes WHERE email = ?");
                    $delete_stmt->execute([$email]);
                    
                    $success = "Password reset successfully! You can now log in with your new password.";
                    header("refresh:3;url=index.php");
                } else {
                    $error = "Failed to reset password. Please try again.";
                }
            }
        }
    }
}

// 处理 AJAX 电子邮件验证 
if (isset($_POST['check_email'])) {
    $email = trim($_POST['email']);
    
    // 首先检查邮箱格式
    if (!preg_match('/^[a-zA-Z0-9]+@siswa\.ukm\.edu\.my$/', $email)) {
        echo json_encode(['valid' => false, 'message' => 'Invalid UKM student email format']);
        exit;
    }
    
    // 检查邮箱是否已经注册 
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->execute([$email]);
    
    if ($stmt->rowCount() > 0) {
        echo json_encode(['valid' => true, 'message' => 'Email found in our system']);
    } else {
        echo json_encode(['valid' => false, 'message' => 'This email is not registered in our system']);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>UKM NEXUS - Forgot Password</title>
    <link rel="stylesheet" href="style.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
</head>
<body>
    <div class="page-wrapper">
        <?php require_once 'includes/navbar.php'; ?>

        <main class="main-content">
            <div class="container">
                <div class="card mx-auto" style="max-width: 400px;">
                    <div class="text-center mb-4">
                        <h1 class="mb-2">Reset Password</h1>
                        <p class="text-secondary">Reset your UKM NEXUS password</p>
                    </div>

                    <?php if ($error): ?>
                        <div class="alert alert-error"><?php echo $error; ?></div>
                    <?php endif; ?>
                    <?php if ($success): ?>
                        <div class="alert alert-success"><?php echo $success; ?></div>
                    <?php endif; ?>

                    <form method="POST" action="" class="fade-in">
                        <div class="form-group">
                            <label class="form-label" for="email">UKM Student Email</label>
                            <div class="input-group">
                                <input type="email" id="email" name="email" class="form-control" 
                                       required placeholder="your.matric@siswa.ukm.edu.my"
                                       value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>">
                                <button type="button" class="btn btn-outline" id="verify-email">
                                    <i class="fas fa-check"></i>
                                </button>
                            </div>
                            <small class="text-secondary">Enter your registered UKM student email</small>
                            <div id="email-validation-message"></div>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="reset_code">Reset Code</label>
                            <div class="input-group">
                                <input type="text" id="reset_code" name="reset_code" class="form-control"
                                       required placeholder="Enter 6-digit code"
                                       value="<?php echo isset($_POST['reset_code']) ? htmlspecialchars($_POST['reset_code']) : ''; ?>">
                                <button type="button" class="btn btn-outline" id="send-code">
                                    <i class="fas fa-paper-plane"></i>
                                </button>
                            </div>
                            <small class="text-secondary">Enter the code sent to your email</small>
                            <div id="reset-message"></div>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="new_password">New Password</label>
                            <input type="password" id="new_password" name="new_password" class="form-control"
                                   required minlength="6" placeholder="Enter new password">
                            <small class="text-secondary">Must be at least 6 characters long</small>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="confirm_password">Confirm New Password</label>
                            <input type="password" id="confirm_password" name="confirm_password" class="form-control"
                                   required minlength="6" placeholder="Confirm new password">
                        </div>

                        <div class="text-center">
                            <button type="submit" name="reset_password" class="btn btn-primary inline-block px-6 mx-auto">
                                <i class="fas fa-key"></i>
                                Reset Password
                            </button>
                        </div>

                        <div class="text-center mt-4">
                            <p class="text-secondary">
                                Remember your password? 
                                <a href="index.php" class="text-primary">Sign in</a>
                            </p>
                        </div>
                    </form>
                </div>
            </div>
        </main>
    </div>

    <style>
    .input-group {
        display: flex;
        gap: 0.5rem;
    }
    .input-group .form-control {
        flex: 1;
    }
    #email-validation-message, #reset-message {
        margin-top: 0.25rem;
        font-size: 0.875rem;
    }
    .text-success {
        color: var(--success-color);
    }
    .text-error {
        color: var(--error-color);
    }
    </style>

    <script>
    $(document).ready(function() {
        // 邮箱验证
        $('#verify-email').click(function() {
            const email = $('#email').val();
            const messageDiv = $('#email-validation-message');
            
            // 检查邮箱格式
            if (!email.match(/^[a-zA-Z0-9]+@siswa\.ukm\.edu\.my$/)) {
                messageDiv.html('<span class="text-error"><i class="fas fa-times"></i> Invalid UKM student email format</span>');
                return;
            }

            $.ajax({
                url: 'forgot_password.php',
                method: 'POST',
                data: { check_email: true, email: email },
                success: function(response) {
                    const result = JSON.parse(response);
                    if (result.valid) {
                        messageDiv.html('<span class="text-success"><i class="fas fa-check"></i> ' + result.message + '</span>');
                    } else {
                        messageDiv.html('<span class="text-error"><i class="fas fa-times"></i> ' + result.message + '</span>');
                    }
                }
            });
        });

        // 发送重置码 
        $('#send-code').click(function() {
            const email = $('#email').val();
            const messageDiv = $('#reset-message');
            const button = $(this);
            
            // 检查邮箱格式
            if (!email.match(/^[a-zA-Z0-9]+@siswa\.ukm\.edu\.my$/)) {
                messageDiv.html('<span class="text-error"><i class="fas fa-times"></i> Please enter a valid UKM student email first</span>');
                return;
            }
            
            // 按钮状态
            button.prop('disabled', true);
            button.html('<i class="fas fa-spinner fa-spin"></i>');
            messageDiv.html('<span class="text-secondary"><i class="fas fa-hourglass"></i> Sending reset code...</span>');
            
            $.ajax({
                url: 'forgot_password.php',
                method: 'POST',
                data: { send_reset_code: true, email: email },
                success: function(response) {
                    const result = JSON.parse(response);
                    
                    if (result.status === 'success') {
                        messageDiv.html('<span class="text-success"><i class="fas fa-check"></i> ' + result.message + '</span>');
                        
                        // 设置倒计时（60秒）
                        let countdown = 60;
                        button.html(countdown + 's');
                        
                        const timer = setInterval(function() {
                            countdown--;
                            button.html(countdown + 's');
                            
                            if (countdown <= 0) {
                                clearInterval(timer);
                                button.prop('disabled', false);
                                button.html('<i class="fas fa-paper-plane"></i>');
                            }
                        }, 1000);
                    } else {
                        messageDiv.html('<span class="text-error"><i class="fas fa-times"></i> ' + result.message + '</span>');
                        button.prop('disabled', false);
                        button.html('<i class="fas fa-paper-plane"></i>');
                    }
                },
                error: function() {
                    messageDiv.html('<span class="text-error"><i class="fas fa-times"></i> Server error. Please try again later.</span>');
                    button.prop('disabled', false);
                    button.html('<i class="fas fa-paper-plane"></i>');
                }
            });
        });
        
        // 验证重置码 
        $('#reset_code').on('blur', function() {
            const code = $(this).val();
            const email = $('#email').val();
            const messageDiv = $('#reset-message');
            
            if (code.length < 6) return;
            
            if (!email.match(/^[a-zA-Z0-9]+@siswa\.ukm\.edu\.my$/)) {
                messageDiv.html('<span class="text-error"><i class="fas fa-times"></i> Please enter a valid UKM student email first</span>');
                return;
            }
            
            $.ajax({
                url: 'forgot_password.php',
                method: 'POST',
                data: { verify_reset_code: true, email: email, code: code },
                success: function(response) {
                    const result = JSON.parse(response);
                    
                    if (result.status === 'success') {
                        messageDiv.html('<span class="text-success"><i class="fas fa-check"></i> ' + result.message + '</span>');
                    } else {
                        messageDiv.html('<span class="text-error"><i class="fas fa-times"></i> ' + result.message + '</span>');
                    }
                }
            });
        });
    });
    </script>
</body>
</html>