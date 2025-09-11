<?php
session_start();
require_once 'includes/config.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$success = '';
$error = '';

// 头像存在
$avatar_dir = "assets/avatars";
if (!file_exists($avatar_dir)) {
    mkdir($avatar_dir, 0777, true);
}

// 添加头像
try {
    $pdo->query("SELECT avatar FROM users LIMIT 1");
} catch (PDOException $e) {
    //如果不存在
    $pdo->exec("ALTER TABLE users ADD COLUMN avatar VARCHAR(255) DEFAULT 'default-avatar.jpg'");
}

// 处理用户名更新
if (isset($_POST['update_username'])) {
    $new_username = trim($_POST['new_username']);
    $current_password = $_POST['current_password_username'];
    
    if (empty($new_username)) {
        $error = "Username cannot be empty.";
    } elseif (strlen($new_username) < 3) {
        $error = "Username must be at least 3 characters long.";
    } elseif (strlen($new_username) > 50) {
        $error = "Username cannot exceed 50 characters.";
    } elseif (!preg_match('/^[a-zA-Z0-9_.-]+$/', $new_username)) {
        $error = "Username can only contain letters, numbers, dots, hyphens, and underscores.";
    } else {
        // 验证当前密码
        $stmt = $pdo->prepare("SELECT password FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch();
        
        if (password_verify($current_password, $user['password'])) {
            // 检查用户名是否已存在
            $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
            $stmt->execute([$new_username, $user_id]);
            
            if ($stmt->rowCount() > 0) {
                $error = "This username is already taken. Please choose another one.";
            } else {
                // 更新用户名
                $stmt = $pdo->prepare("UPDATE users SET username = ? WHERE id = ?");
                if ($stmt->execute([$new_username, $user_id])) {
                    $_SESSION['username'] = $new_username; // 更新session
                    $success = "Username updated successfully!";
                } else {
                    $error = "Failed to update username. Please try again.";
                }
            }
        } else {
            $error = "Current password is incorrect.";
        }
    }
}

// 上传头像
if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
    $file_info = getimagesize($_FILES["avatar"]["tmp_name"]);
    if ($file_info !== false) {
        $allowed_types = array(IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF);
        if (in_array($file_info[2], $allowed_types)) {
            $extension = image_type_to_extension($file_info[2]);
            $new_filename = $user_id . '_' . time() . $extension;
            $target_file = $avatar_dir . '/' . $new_filename;

            if (move_uploaded_file($_FILES["avatar"]["tmp_name"], $target_file)) {
                // Delete old avatar
                $stmt = $pdo->prepare("SELECT avatar FROM users WHERE id = ?");
                $stmt->execute([$user_id]);
                $old_avatar = $stmt->fetchColumn();
                
                if ($old_avatar && $old_avatar !== 'default-avatar.jpg' && file_exists($avatar_dir . '/' . $old_avatar)) {
                    unlink($avatar_dir . '/' . $old_avatar);
                }

                //数据库更新
                $stmt = $pdo->prepare("UPDATE users SET avatar = ? WHERE id = ?");
                if ($stmt->execute([$new_filename, $user_id])) {
                    $success = "Avatar updated successfully!";
                } else {
                    $error = "Failed to update database, please try again.";
                }
            } else {
                $error = "Upload failed, please try again.";
            }
        } else {
            $error = "Only JPG, PNG or GIF images are allowed.";
        }
    } else {
        $error = "Please upload a valid image file.";
    }
}

// 密码更新
if (isset($_POST['update_password'])) {
    $current_password = $_POST['current_password'];
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];

    $stmt = $pdo->prepare("SELECT password FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch();

    if (password_verify($current_password, $user['password'])) {
        if ($new_password === $confirm_password) {
            if (strlen($new_password) >= 6) {
                $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
                if ($stmt->execute([$hashed_password, $user_id])) {
                    $success = "Password updated successfully!";
                }
            } else {
                $error = "New password must be at least 6 characters long";
            }
        } else {
            $error = "New password and confirm password do not match";
        }
    } else {
        $error = "Current password is incorrect";
    }
}

// 问题删除
if (isset($_POST['delete_question'])) {
    $question_id = $_POST['question_id'];
    $stmt = $pdo->prepare("DELETE FROM questions WHERE id = ? AND user_id = ?");
    if ($stmt->execute([$question_id, $user_id])) {
        $success = "Question deleted successfully!";
    } else {
        $error = "Failed to delete question, please try again.";
    }
}

// 用户信息
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user_info = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user_info) {
    // 没有用户信息返回到index
    session_destroy();
    header("Location: index.php");
    exit();
}

// 获取问题
$stmt = $pdo->prepare("
    SELECT q.*, 
           COALESCE((SELECT COUNT(*) FROM answers WHERE question_id = q.id), 0) as answer_count,
           GROUP_CONCAT(t.name) as tags
    FROM questions q 
    LEFT JOIN question_tags qt ON q.id = qt.question_id
    LEFT JOIN tags t ON qt.tag_id = t.id
    WHERE q.user_id = ? 
    GROUP BY q.id
    ORDER BY q.created_at DESC
");
$stmt->execute([$user_id]);
$questions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 确认头像路径
$avatar_path = $avatar_dir . '/' . ($user_info['avatar'] ?? 'default-avatar.jpg');
if (!file_exists($avatar_path) || !is_file($avatar_path)) {
    // 如果用户头像不存在，则使用默认头像
    $default_avatar_path = $avatar_dir . '/default-avatar.jpg';
    if (!file_exists($default_avatar_path)) {
        // 创建简单的默认头像或使用静态路径
        $avatar_path = 'assets/images/default-avatar.jpg';
    } else {
        $avatar_path = $default_avatar_path;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>UKM NEXUS - Profile</title>
    <link rel="stylesheet" href="style.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <div class="page-wrapper">
        <?php require_once 'includes/navbar.php'; ?>

        <main class="main-content">
            <div class="container">
                <?php if ($success): ?>
                    <div class="alert alert-success"><?php echo $success; ?></div>
                <?php endif; ?>
                <?php if ($error): ?>
                    <div class="alert alert-error"><?php echo $error; ?></div>
                <?php endif; ?>

                <div class="profile-header card">
                    <div class="flex items-center gap-4">
                        <div class="relative">
                            <img src="<?php echo htmlspecialchars($avatar_path); ?>" alt="User Avatar" class="profile-avatar">
                            <form method="POST" enctype="multipart/form-data" id="avatarForm">
                                <label for="avatar" class="btn btn-outline absolute bottom-0 right-0 p-2" style="border-radius: 50%;" title="Change avatar">
                                    <i class="fas fa-camera"></i>
                                </label>
                                <input type="file" id="avatar" name="avatar" accept="image/*" class="hidden" title="Select a file">
                            </form>
                        </div>
                        <div>
                            <h1 class="text-2xl font-bold mb-1"><?php echo htmlspecialchars($_SESSION['username']); ?></h1>
                            <p class="text-secondary mb-2">
                                <i class="fas fa-calendar"></i> Joined <?php echo date('F Y', strtotime($user_info['created_at'])); ?>
                            </p>
                            <div class="flex gap-2">
                                <button type="button" class="btn btn-outline" onclick="openUsernameModal()">
                                    <i class="fas fa-user-edit"></i> Change Username
                                </button>
                                <button type="button" class="btn btn-outline" onclick="openPasswordModal()">
                                    <i class="fas fa-key"></i> Change Password
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card mt-4">
                    <div class="flex justify-between items-center mb-4">
                        <h2 class="text-xl font-semibold">My Questions</h2>
                        <a href="new_question.php" class="btn btn-primary">
                            <i class="fas fa-plus"></i> Ask Question
                        </a>
                    </div>

                    <?php if ($questions): ?>
                        <div class="space-y-4">
                            <?php foreach ($questions as $question): ?>
                                <div class="question-card">
                                    <div class="flex justify-between items-start">
                                        <div class="flex-1">
                                            <a href="question.php?id=<?php echo $question['id']; ?>" class="question-title">
                                                <?php echo htmlspecialchars($question['title']); ?>
                                            </a>
                                            <div class="question-meta">
                                                <span class="text-secondary">
                                                    <i class="fas fa-calendar"></i>
                                                    <?php echo date('M j, Y', strtotime($question['created_at'])); ?>
                                                </span>
                                                <span class="text-secondary">
                                                    <i class="fas fa-comments"></i>
                                                    <?php echo $question['answer_count']; ?> answers
                                                </span>
                                            </div>
                                            <?php if ($question['tags']): ?>
                                                <div class="tags-container mt-2">
                                                    <?php foreach (explode(',', $question['tags']) as $tag): ?>
                                                        <span class="tag"><?php echo htmlspecialchars($tag); ?></span>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        <form method="POST" class="ml-4" onsubmit="return confirm('Are you sure you want to delete this question?');">
                                            <input type="hidden" name="question_id" value="<?php echo $question['id']; ?>">
                                            <button type="submit" name="delete_question" class="btn btn-danger btn-sm">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-8">
                            <p class="text-secondary mb-4">You haven't asked any questions yet.</p>
                            <a href="new_question.php" class="btn btn-primary">
                                Ask Your First Question
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>

    <!-- 用户名修改模态框 -->
    <div id="usernameModal" class="modal" style="display: none;">
        <div class="modal-content">
            <span class="close" onclick="closeUsernameModal()">&times;</span>
            <h2 class="modal-title">Change Username</h2>
            <form method="POST" class="space-y-4">
                <div class="form-group">
                    <label class="form-label" for="new_username">New Username</label>
                    <input type="text" id="new_username" name="new_username" class="form-control" 
                           required minlength="3" maxlength="50" pattern="[a-zA-Z0-9_.-]+"
                           placeholder="Enter new username"
                           value="<?php echo htmlspecialchars($_SESSION['username']); ?>">
                    <small class="form-help">
                        Username must be 3-50 characters long and can only contain letters, numbers, dots, hyphens, and underscores.
                    </small>
                </div>
                <div class="form-group">
                    <label class="form-label" for="current_password_username">Current Password</label>
                    <input type="password" id="current_password_username" name="current_password_username" 
                           class="form-control" required placeholder="Enter your current password">
                    <small class="form-help">
                        Please enter your current password to confirm this change.
                    </small>
                </div>
                <div class="flex justify-end gap-2">
                    <button type="button" class="btn btn-secondary" onclick="closeUsernameModal()">Cancel</button>
                    <button type="submit" name="update_username" class="btn btn-primary">
                        <i class="fas fa-save"></i> Update Username
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- 密码修改模态框 -->
    <div id="passwordModal" class="modal" style="display: none;">
        <div class="modal-content">
            <span class="close" onclick="closePasswordModal()">&times;</span>
            <h2 class="modal-title">Change Password</h2>
            <form method="POST" class="space-y-4">
                <div class="form-group">
                    <label class="form-label" for="current_password">Current Password</label>
                    <input type="password" id="current_password" name="current_password" class="form-control" required>
                </div>
                <div class="form-group">
                    <label class="form-label" for="new_password">New Password</label>
                    <input type="password" id="new_password" name="new_password" class="form-control" required minlength="6">
                </div>
                <div class="form-group">
                    <label class="form-label" for="confirm_password">Confirm New Password</label>
                    <input type="password" id="confirm_password" name="confirm_password" class="form-control" required minlength="6">
                </div>
                <div class="flex justify-end gap-2">
                    <button type="button" class="btn btn-secondary" onclick="closePasswordModal()">Cancel</button>
                    <button type="submit" name="update_password" class="btn btn-primary">
                        <i class="fas fa-save"></i> Update Password
                    </button>
                </div>
            </form>
        </div>
    </div>

    <style>
        .modal {
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            display: flex;
            justify-content: center;
            align-items: center;
        }

        .modal-content {
            background-color: var(--background-color);
            padding: 2rem;
            border-radius: 0.5rem;
            max-width: 500px;
            width: 90%;
            max-height: 80vh;
            overflow-y: auto;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
            position: relative;
        }

        .modal-title {
            font-size: 1.5rem;
            font-weight: 600;
            margin-bottom: 1.5rem;
            color: var(--text-primary);
        }

        .close {
            position: absolute;
            right: 1rem;
            top: 1rem;
            font-size: 1.5rem;
            cursor: pointer;
            color: var(--text-tertiary);
            transition: color 0.2s;
        }

        .close:hover {
            color: var(--text-primary);
        }

        .profile-avatar {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            object-fit: cover;
            border: 4px solid var(--primary-color-light);
        }

        .question-card {
            border: 1px solid var(--border-color);
            border-radius: 0.5rem;
            padding: 1.5rem;
            transition: all 0.2s ease;
        }

        .question-card:hover {
            border-color: var(--primary-color);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }

        .question-title {
            font-size: 1.125rem;
            font-weight: 600;
            color: var(--primary-color);
            text-decoration: none;
            display: block;
            margin-bottom: 0.5rem;
        }

        .question-title:hover {
            color: var(--primary-color-dark);
        }

        .question-meta {
            display: flex;
            gap: 1rem;
            margin-bottom: 0.5rem;
        }
    </style>

    <script>
        // 头像上传自动提交
        document.getElementById('avatar').addEventListener('change', function() {
            if (this.files.length > 0) {
                document.getElementById('avatarForm').submit();
            }
        });

        // 用户名修改模态框
        function openUsernameModal() {
            document.getElementById('usernameModal').style.display = 'flex';
        }

        function closeUsernameModal() {
            document.getElementById('usernameModal').style.display = 'none';
        }

        // 密码修改模态框
        function openPasswordModal() {
            document.getElementById('passwordModal').style.display = 'flex';
        }

        function closePasswordModal() {
            document.getElementById('passwordModal').style.display = 'none';
        }

        // 点击模态框外部关闭
        window.addEventListener('click', function(event) {
            const usernameModal = document.getElementById('usernameModal');
            const passwordModal = document.getElementById('passwordModal');
            
            if (event.target === usernameModal) {
                closeUsernameModal();
            }
            if (event.target === passwordModal) {
                closePasswordModal();
            }
        });

        // 实时用户名验证
        document.getElementById('new_username').addEventListener('input', function() {
            const username = this.value;
            const isValid = /^[a-zA-Z0-9_.-]+$/.test(username) && username.length >= 3 && username.length <= 50;
            
            if (!isValid && username.length > 0) {
                this.setCustomValidity('Username can only contain letters, numbers, dots, hyphens, and underscores, and must be 3-50 characters long.');
            } else {
                this.setCustomValidity('');
            }
        });

        // 密码确认验证
        document.getElementById('confirm_password').addEventListener('input', function() {
            const newPassword = document.getElementById('new_password').value;
            const confirmPassword = this.value;
            
            if (newPassword !== confirmPassword) {
                this.setCustomValidity('Passwords do not match.');
            } else {
                this.setCustomValidity('');
            }
        });
    </script>
</body>
</html>