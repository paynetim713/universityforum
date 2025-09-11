<?php
session_start();
require_once 'includes/config.php';

if(isset($_SESSION['user_id'])) {
    header("Location: forum.php");
    exit();
}
//smtp 邮件设置
$smtp_server = "smtp.gmail.com";  
$smtp_port = 465;  
$sender_email = "aslm2l1k123@gmail.com";
$sender_password = "lcfypbwumaufqnlj";  //

$error = '';
$success = '';

// 处理验证码发送请求
if (isset($_POST['send_verification_code'])) {
    $email = trim($_POST['email']);
    
    // 验证邮箱格式
    if (!preg_match('/^[a-zA-Z0-9]+@siswa\.ukm\.edu\.my$/', $email)) {
        echo json_encode(['status' => 'error', 'message' => 'Please use your UKM student email (@siswa.ukm.edu.my).']);
        exit;
    }
    
    // 检查邮箱是否已注册
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->execute([$email]);
    if ($stmt->rowCount() > 1) {
        echo json_encode(['status' => 'error', 'message' => 'This email is already registered.']);
        exit;
    }
    
    // 调用Python脚本发送验证码
    $command = escapeshellcmd("python3 send_verification_email.py " . 
               escapeshellarg($email) . " " . 
               escapeshellarg($smtp_server) . " " . 
               escapeshellarg($smtp_port) . " " . 
               escapeshellarg($sender_email) . " " . 
               escapeshellarg($sender_password));
    
    $output = shell_exec($command);
    $result = explode("|", $output);
    
    if (isset($result[0]) && $result[0] != "error") {
        $verification_code = trim($result[0]);
        
        // 检查数据库中是否已有验证码表
        try {
            $pdo->query("SELECT 1 FROM verification_codes LIMIT 1");
        } catch (PDOException $e) {
            //没有就创建
            $pdo->exec("CREATE TABLE verification_codes (
                id INT AUTO_INCREMENT PRIMARY KEY,
                email VARCHAR(100) NOT NULL UNIQUE,
                code VARCHAR(10) NOT NULL,
                expiry_time DATETIME NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )");
        }
      
        $expiry_time = date('Y-m-d H:i:s', strtotime('+10 minutes'));
        
        // 检查是否已有此邮箱的验证码记录
        $stmt = $pdo->prepare("SELECT id FROM verification_codes WHERE email = ?");
        $stmt->execute([$email]);
        
        if ($stmt->rowCount() > 0) {
            // 更新现有记录
            $stmt = $pdo->prepare("UPDATE verification_codes SET code = ?, expiry_time = ? WHERE email = ?");
            $stmt->execute([$verification_code, $expiry_time, $email]);
        } else {
            // 插入新记录
            $stmt = $pdo->prepare("INSERT INTO verification_codes (email, code, expiry_time) VALUES (?, ?, ?)");
            $stmt->execute([$email, $verification_code, $expiry_time]);
        }
        
        echo json_encode(['status' => 'success', 'message' => 'Verification code sent to your email.']);
    } else {
        $error_message = isset($result[1]) ? trim($result[1]) : "Failed to send verification code.";
        echo json_encode(['status' => 'error', 'message' => $error_message]);
    }
    exit;
}

// 验证校验
if (isset($_POST['verify_code'])) {
    $email = trim($_POST['email']);
    $code = trim($_POST['code']);
    
    // 检查是否正确
    $stmt = $pdo->prepare("SELECT code, expiry_time FROM verification_codes WHERE email = ?");
    $stmt->execute([$email]);
    
    if ($stmt->rowCount() == 0) {
        echo json_encode(['status' => 'error', 'message' => 'Please request a verification code first.']);
        exit;
    }
    
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $db_code = $row['code'];
    $expiry_time = $row['expiry_time'];
    
    // 时间是否过期
    if (strtotime($expiry_time) < time()) {
        echo json_encode(['status' => 'error', 'message' => 'Verification code has expired. Please request a new one.']);
        exit;
    }
    
    // 检查验证码是否正确
    if ($code != $db_code) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid verification code.']);
    } else {
        echo json_encode(['status' => 'success', 'message' => 'Email verified successfully.']);
    }
    exit;
}

// 处理注册请求
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['username'])) {
    $username = trim($_POST['username']);
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    $verification_code = isset($_POST['verification_code']) ? trim($_POST['verification_code']) : '';
    
    // 验证用户的输入
    if (empty($username) || empty($email) || empty($password) || empty($confirm_password) || empty($verification_code)) {
        $error = "All fields are required.";
    } elseif (strlen($username) < 3) {
        $error = "Username must be at least 3 characters.";
    } elseif (!preg_match('/^[a-zA-Z0-9]+@siswa\.ukm\.edu\.my$/', $email)) {
        $error = "Please use your UKM student email (@siswa.ukm.edu.my).";
    } elseif (strlen($password) < 6) {
        $error = "Password must be at least 6 characters.";
    } elseif ($password !== $confirm_password) {
        $error = "Passwords do not match.";
    } else {
        // 验证验证码
        $stmt = $pdo->prepare("SELECT code, expiry_time FROM verification_codes WHERE email = ?");
        $stmt->execute([$email]);
        
        if ($stmt->rowCount() == 0) {
            $error = "Please verify your email first.";
        } else {
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $db_code = $row['code'];
            $expiry_time = $row['expiry_time'];
            
            // 检查验证码是否过期
            if (strtotime($expiry_time) < time()) {
                $error = "Verification code has expired. Please request a new one.";
            }
            // 检查验证码是否正确
            elseif ($verification_code != $db_code) {
                $error = "Invalid verification code.";
            } else {
                // 检查用户名或邮箱是否已被使用
                $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
                $stmt->execute([$username, $email]);
                if ($stmt->rowCount() > 0) {
                    $error = "Username or email already taken.";
                } else {
                    // 创建新用户
                    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                    $stmt = $pdo->prepare("INSERT INTO users (username, email, password) VALUES (?, ?, ?)");
                    
                    if ($stmt->execute([$username, $email, $hashed_password])) {
                        // 删除验证码记录
                        $delete_stmt = $pdo->prepare("DELETE FROM verification_codes WHERE email = ?");
                        $delete_stmt->execute([$email]);
                        
                        $success = "Registration successful! Please log in.";
                        header("refresh:2;url=index.php");
                    } else {
                        $error = "Registration failed, please try again later.";
                    }
                }
            }
        }
    }
}

// 处理 AJAX 电子邮件验证
if (isset($_POST['check_email'])) {
    $email = trim($_POST['email']);
    $is_valid = preg_match('/^[a-zA-Z0-9]+@siswa\.ukm\.edu\.my$/', $email);
    echo json_encode(['valid' => $is_valid]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>UKM NEXUS - Create Account</title>
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
                        <h1 class="mb-2">Create Account</h1>
                        <p class="text-secondary">Join UKM NEXUS community today</p>
                    </div>

                    <?php if ($error): ?>
                        <div class="alert alert-error"><?php echo $error; ?></div>
                    <?php endif; ?>
                    <?php if ($success): ?>
                        <div class="alert alert-success"><?php echo $success; ?></div>
                    <?php endif; ?>

                    <form method="POST" action="" class="fade-in">
                        <div class="form-group">
                            <label class="form-label" for="username">Username</label>
                            <input type="text" id="username" name="username" class="form-control" 
                                   required minlength="3" placeholder="Choose a username"
                                   value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>">
                            <small class="text-secondary">Must be at least 3 characters long</small>
                        </div>

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
                            <small class="text-secondary">Must be a valid UKM student email</small>
                            <div id="email-validation-message"></div>
                        </div>

                        <!-- 添加验证码输入字段 -->
                        <div class="form-group">
                            <label class="form-label" for="verification_code">Verification Code</label>
                            <div class="input-group">
                                <input type="text" id="verification_code" name="verification_code" class="form-control"
                                       required placeholder="Enter 6-digit code"
                                       value="<?php echo isset($_POST['verification_code']) ? htmlspecialchars($_POST['verification_code']) : ''; ?>">
                                <button type="button" class="btn btn-outline" id="send-code">
                                    <i class="fas fa-paper-plane"></i>
                                </button>
                            </div>
                            <small class="text-secondary">Enter the code sent to your email</small>
                            <div id="verification-message"></div>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="password">Password</label>
                            <input type="password" id="password" name="password" class="form-control"
                                   required minlength="6" placeholder="Create a password">
                            <small class="text-secondary">Must be at least 6 characters long</small>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="confirm_password">Confirm Password</label>
                            <input type="password" id="confirm_password" name="confirm_password" class="form-control"
                                   required minlength="6" placeholder="Confirm your password">
                        </div>

                        <div class="text-center">
                            <button type="submit" class="btn btn-primary inline-block px-6 mx-auto">Create Account</button>
                        </div>

                        <div class="text-center mt-4">
                            <p class="text-secondary">
                                Already have an account? 
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
    #email-validation-message, #verification-message {
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
                url: 'register.php',
                method: 'POST',
                data: { check_email: true, email: email },
                success: function(response) {
                    const result = JSON.parse(response);
                    if (result.valid) {
                        messageDiv.html('<span class="text-success"><i class="fas fa-check"></i> Valid UKM student email</span>');
                    } else {
                        messageDiv.html('<span class="text-error"><i class="fas fa-times"></i> Invalid UKM student email</span>');
                    }
                }
            });
        });

        // 发送验证码
        $('#send-code').click(function() {
            const email = $('#email').val();
            const messageDiv = $('#verification-message');
            const button = $(this);
            
            // 检查邮箱格式
            if (!email.match(/^[a-zA-Z0-9]+@siswa\.ukm\.edu\.my$/)) {
                messageDiv.html('<span class="text-error"><i class="fas fa-times"></i> Please enter a valid UKM student email first</span>');
                return;
            }
            
            // 按钮状态
            //禁用和启动
            button.prop('disabled', true);
            button.html('<i class="fas fa-spinner fa-spin"></i>');
            messageDiv.html('<span class="text-secondary"><i class="fas fa-hourglass"></i> Sending verification code...</span>');
            
            $.ajax({
                url: 'register.php',
                method: 'POST',
                data: { send_verification_code: true, email: email },
                success: function(response) {
                    const result = JSON.parse(response);
                    
                    if (result.status === 'success') {
                        messageDiv.html('<span class="text-success"><i class="fas fa-check"></i> ' + result.message + '</span>');
                        
                        // 设置倒计时
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
        
        // 验证验证码
        $('#verification_code').on('blur', function() {
            const code = $(this).val();
            const email = $('#email').val();
            const messageDiv = $('#verification-message');
            
            if (code.length < 6) return;
            
            if (!email.match(/^[a-zA-Z0-9]+@siswa\.ukm\.edu\.my$/)) {
                messageDiv.html('<span class="text-error"><i class="fas fa-times"></i> Please enter a valid UKM student email first</span>');
                return;
            }
            
            $.ajax({
                url: 'register.php',
                method: 'POST',
                data: { verify_code: true, email: email, code: code },
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