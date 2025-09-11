<?php
session_start();
require_once '../includes/config.php';

if (!isset($_SESSION['user_id']) || !$_SESSION['is_admin']) {
    header("Location: ../index.php");
    exit();
}
$base_url = "../";  

if (isset($_POST['delete_question'])) {
    $question_id = $_POST['question_id'];
    $stmt = $pdo->prepare("DELETE FROM answers WHERE question_id = ?");
    $stmt->execute([$question_id]);
    $stmt = $pdo->prepare("DELETE FROM questions WHERE id = ?");
    if ($stmt->execute([$question_id])) {
        $success = "Question deleted successfully!";
    }
}

// 新增：删除用户功能
if (isset($_POST['delete_user'])) {
    $user_id = $_POST['user_id'];
    
    // 安全检查：不能删除当前登录的管理员
    if ($user_id == $_SESSION['user_id']) {
        $error = "You cannot delete your own account!";
    } else {
        try {
            // 开始事务
            $pdo->beginTransaction();
            
            // 删除用户相关的答案
            $stmt = $pdo->prepare("DELETE FROM answers WHERE user_id = ?");
            $stmt->execute([$user_id]);
            
            // 删除用户相关的问题（这会触发外键约束删除相关答案，如果有的话）
            $stmt = $pdo->prepare("DELETE FROM questions WHERE user_id = ?");
            $stmt->execute([$user_id]);
            
            // 删除用户相关的文件附件（如果有这个表的话）
            $stmt = $pdo->prepare("DELETE FROM question_attachments WHERE question_id IN (SELECT id FROM questions WHERE user_id = ?)");
            $stmt->execute([$user_id]);
            
            // 最后删除用户
            $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
            if ($stmt->execute([$user_id])) {
                $pdo->commit();
                $success = "User deleted successfully!";
            } else {
                $pdo->rollBack();
                $error = "Failed to delete user!";
            }
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = "Error deleting user: " . $e->getMessage();
        }
    }
}

if (isset($_POST['toggle_admin'])) {
    $user_id = $_POST['user_id'];
    $stmt = $pdo->prepare("UPDATE users SET is_admin = NOT is_admin WHERE id = ?");
    if ($stmt->execute([$user_id])) {
        $success = "User admin status updated!";
    }
}

$questions = $pdo->query("
    SELECT q.*, u.username 
    FROM questions q 
    JOIN users u ON q.user_id = u.id 
    ORDER BY q.created_at DESC
")->fetchAll();

// 修改：添加邮箱字段到用户查询
$users = $pdo->query("
    SELECT id, username, email, created_at, is_admin 
    FROM users 
    WHERE id != " . $_SESSION['user_id'] . "
    ORDER BY created_at DESC
")->fetchAll();

$pending_files_stmt = $pdo->query("SELECT COUNT(*) FROM question_attachments WHERE status = 'pending'");
$pending_files_count = $pending_files_stmt->fetchColumn();

$reviewQueueCount = 0;
try {
    $check_stmt = $pdo->query("SHOW TABLES LIKE 'content_review_queue'");
    if ($check_stmt->rowCount() > 0) {
        $stmt = $pdo->query("SELECT COUNT(*) FROM content_review_queue");
        $reviewQueueCount = $stmt->fetchColumn();
    }
} catch (PDOException $e) {
    error_log("Error checking content_review_queue: " . $e->getMessage());
}

$total_users = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
$total_questions = $pdo->query("SELECT COUNT(*) FROM questions")->fetchColumn();
$total_answers = $pdo->query("SELECT COUNT(*) FROM answers")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>UKM Forum - Admin Dashboard</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link rel="stylesheet" href="../style.css">
    <style>
        .nav-logo {
            width: 80px;
            height: 50px;
            max-width: 100%;
            max-height: 100%;
            filter: brightness(1.1);
            transition: var(--transition-fast);
            content: url("../assets/images/logo.jpg");
        }

        .nav-logo:hover {
            filter: brightness(1.3);
        }

        .admin-dashboard {
            background-color: #f8f9fa;
            border-radius: 8px;
            padding: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .dashboard-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 1px solid #e3e3e3;
        }
        
        .dashboard-header h1 {
            color: #2c3e50;
            margin: 0;
        }
        
        .dashboard-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: #fff;
            border-radius: 8px;
            padding: 15px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
            text-align: center;
            transition: transform 0.2s;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }
        
        .stat-card i {
            font-size: 2rem;
            color: #3498db;
            margin-bottom: 10px;
        }
        
        .stat-card .stat-value {
            font-size: 1.8rem;
            font-weight: bold;
            color: #2c3e50;
        }
        
        .stat-card .stat-label {
            color: #7f8c8d;
            font-size: 0.9rem;
        }
        
        .admin-section {
            background: #fff;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 30px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }
        
        .admin-section h2 {
            color: #2c3e50;
            margin-top: 0;
            padding-bottom: 10px;
            border-bottom: 1px solid #f1f1f1;
        }
        
        .admin-table {
            width: 100%;
            border-collapse: collapse;
            overflow-x: auto;
            display: table;
        }
        
        .admin-table th {
            background-color: #f8f9fa;
            padding: 12px;
            text-align: left;
            font-weight: 600;
            color: #2c3e50;
            border-bottom: 2px solid #e3e3e3;
            white-space: nowrap;
        }
        
        .admin-table td {
            padding: 12px;
            border-bottom: 1px solid #f1f1f1;
            word-wrap: break-word;
        }
        
        .admin-table tr:hover {
            background-color: #f8f9fa;
        }
        
        .action-btn {
            background-color: #3498db;
            color: white;
            border: none;
            padding: 6px 12px;
            border-radius: 4px;
            cursor: pointer;
            transition: background-color 0.2s;
            margin-right: 5px;
            margin-bottom: 5px;
            font-size: 0.8rem;
        }
        
        .action-btn.delete {
            background-color: #e74c3c;
        }
        
        .action-btn:hover {
            background-color: #2980b9;
        }
        
        .action-btn.delete:hover {
            background-color: #c0392b;
        }
        
        .badge {
            display: inline-block;
            padding: 3px 7px;
            border-radius: 50px;
            font-size: 0.7rem;
            font-weight: bold;
            background-color: #3498db;
            color: white;
        }
        
        .badge-warning {
            background-color: #f1c40f;
        }
        
        .badge-danger {
            background-color: #e74c3c;
        }
        
        .success-message {
            background-color: #d4edda;
            color: #155724;
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 4px;
            border-left: 4px solid #28a745;
        }
        
        /* 新增：错误消息样式 */
        .error-message {
            background-color: #f8d7da;
            color: #721c24;
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 4px;
            border-left: 4px solid #dc3545;
        }
        
        .admin-actions {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
        }
        
        .admin-actions .btn {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px 16px;
            border-radius: 4px;
            background-color: #3498db;
            color: white;
            text-decoration: none;
            font-weight: 600;
            transition: background-color 0.2s;
        }
        
        .admin-actions .btn:hover {
            background-color: #2980b9;
        }
        
        .tab-nav {
            display: flex;
            border-bottom: 1px solid #e3e3e3;
            margin-bottom: 20px;
        }
        
        .tab-nav button {
            background: none;
            border: none;
            padding: 10px 20px;
            font-size: 1rem;
            cursor: pointer;
            position: relative;
            color: #7f8c8d;
        }
        
        .tab-nav button.active {
            color: #3498db;
            font-weight: 600;
        }
        
        .tab-nav button.active::after {
            content: '';
            position: absolute;
            bottom: -1px;
            left: 0;
            width: 100%;
            height: 2px;
            background-color: #3498db;
        }
        
        .tab-content {
            display: none;
        }
        
        .tab-content.active {
            display: block;
        }
        
        /* 新增：操作按钮容器样式 */
        .action-buttons {
            display: flex;
            flex-wrap: wrap;
            gap: 5px;
        }
        
        /* 新增：邮箱列样式 */
        .email-cell {
            max-width: 200px;
            word-break: break-all;
        }
        
        /* 响应式设计 */
        @media (max-width: 768px) {
            .admin-table {
                font-size: 0.8rem;
            }
            
            .action-btn {
                padding: 4px 8px;
                font-size: 0.7rem;
            }
            
            .action-buttons {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
<?php 
$current_dir = dirname($_SERVER['PHP_SELF']);
$_SERVER['PHP_SELF'] = str_replace('/admin', '', $_SERVER['PHP_SELF']);
include '../includes/navbar.php';
$_SERVER['PHP_SELF'] = $current_dir . '/' . basename($_SERVER['PHP_SELF']);
?>
    
    <div class="container">
        <div class="admin-dashboard">
            <div class="dashboard-header">
                <h1>Admin Dashboard</h1>
                <div class="admin-actions">
                    <a href="../forum.php" class="btn">
                        <i class="fas fa-arrow-left"></i> Back to Forum
                    </a>
                    <a href="../index.php" class="btn">
                        <i class="fas fa-home"></i> Main Page
                    </a>
                </div>
            </div>
            
            <?php if (isset($success)): ?>
                <div class="success-message">
                    <i class="fas fa-check-circle"></i> <?php echo $success; ?>
                </div>
            <?php endif; ?>
            
            <?php if (isset($error)): ?>
                <div class="error-message">
                    <i class="fas fa-exclamation-circle"></i> <?php echo $error; ?>
                </div>
            <?php endif; ?>
            
            <div class="dashboard-stats">
                <div class="stat-card">
                    <i class="fas fa-users"></i>
                    <div class="stat-value"><?php echo $total_users; ?></div>
                    <div class="stat-label">Total Users</div>
                </div>
                
                <div class="stat-card">
                    <i class="fas fa-question-circle"></i>
                    <div class="stat-value"><?php echo $total_questions; ?></div>
                    <div class="stat-label">Questions</div>
                </div>
                
                <div class="stat-card">
                    <i class="fas fa-comments"></i>
                    <div class="stat-value"><?php echo $total_answers; ?></div>
                    <div class="stat-label">Answers</div>
                </div>
                
                <div class="stat-card">
                    <i class="fas fa-tasks"></i>
                    <div class="stat-value"><?php echo $pending_files_count + $reviewQueueCount; ?></div>
                    <div class="stat-label">Pending Reviews</div>
                </div>
            </div>
            
            <div class="admin-actions">
                <a href="../file_moderation.php" class="btn">
                    <i class="fas fa-file-image"></i> File Moderation
                    <?php if ($pending_files_count > 0): ?>
                        <span class="badge"><?php echo $pending_files_count; ?></span>
                    <?php endif; ?>
                </a>
                
                <?php if ($reviewQueueCount > 0): ?>
                <a href="../content_review.php" class="btn">
                    <i class="fas fa-clipboard-check"></i> Content Review
                    <span class="badge"><?php echo $reviewQueueCount; ?></span>
                </a>
                <?php endif; ?>
            </div>
            
            <div class="tab-nav">
                <button class="tab-btn active" data-tab="questions">Manage Questions</button>
                <button class="tab-btn" data-tab="users">Manage Users</button>
            </div>
            
            <div id="questions-tab" class="tab-content active">
                <div class="admin-section">
                    <h2>Question Management</h2>
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>Title</th>
                                <th>Posted By</th>
                                <th>Date</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($questions as $question): ?>
                                <tr>
                                    <td>
                                        <a href="<?php echo $base_url; ?>question.php?id=<?php echo $question['id']; ?>">
                                            <?php echo htmlspecialchars($question['title']); ?>
                                        </a>
                                    </td>
                                    <td><?php echo htmlspecialchars($question['username']); ?></td>
                                    <td><?php echo date('M j, Y', strtotime($question['created_at'])); ?></td>
                                    <td>
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="question_id" value="<?php echo $question['id']; ?>">
                                            <button type="submit" name="delete_question" class="action-btn delete" onclick="return confirm('Are you sure you want to delete this question?')">
                                                <i class="fas fa-trash"></i> Delete
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <div id="users-tab" class="tab-content">
                <div class="admin-section">
                    <h2>User Management</h2>
                    <div style="overflow-x: auto;">
                        <table class="admin-table">
                            <thead>
                                <tr>
                                    <th>Username</th>
                                    <th>Email</th>
                                    <th>Joined Date</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($users as $user): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($user['username']); ?></td>
                                        <td class="email-cell"><?php echo htmlspecialchars($user['email']); ?></td>
                                        <td><?php echo date('M j, Y', strtotime($user['created_at'])); ?></td>
                                        <td>
                                            <?php if ($user['is_admin']): ?>
                                                <span class="badge">Admin</span>
                                            <?php else: ?>
                                                User
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="action-buttons">
                                                <form method="POST" style="display: inline;">
                                                    <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                                    <button type="submit" name="toggle_admin" class="action-btn">
                                                        <?php echo $user['is_admin'] ? '<i class="fas fa-user-minus"></i> Remove Admin' : '<i class="fas fa-user-shield"></i> Make Admin'; ?>
                                                    </button>
                                                </form>
                                                
                                                <form method="POST" style="display: inline;">
                                                    <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                                    <button type="submit" name="delete_user" class="action-btn delete" 
                                                            onclick="return confirm('Are you sure you want to delete user \'<?php echo htmlspecialchars($user['username']); ?>\'? This will also delete all their questions and answers!')">
                                                        <i class="fas fa-trash"></i> Delete User
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const tabBtns = document.querySelectorAll('.tab-btn');
            const tabContents = document.querySelectorAll('.tab-content');
            
            tabBtns.forEach(btn => {
                btn.addEventListener('click', function() {
                    const tabId = this.getAttribute('data-tab');
                  
                    tabBtns.forEach(b => b.classList.remove('active'));
                    tabContents.forEach(c => c.classList.remove('active'));
                    
                    this.classList.add('active');
                    document.getElementById(tabId + '-tab').classList.add('active');
                });
            });
        });

        document.addEventListener('DOMContentLoaded', function() {
            const navLinks = document.querySelectorAll('.navbar a');
            navLinks.forEach(link => {
                const href = link.getAttribute('href');
                if (href && !href.startsWith('http') && !href.startsWith('/') && !href.startsWith('../')) {
                    link.setAttribute('href', '../' + href);
                }
            });
            
            const logoImg = document.querySelector('.nav-logo');
            if (logoImg) {
                logoImg.src = '../assets/images/logo.jpg';
                setTimeout(function() {
                    if (logoImg.naturalWidth === 0) {
                        const paths = [
                            '../assets/images/logo.jpg',
                            '/assets/images/logo.jpg',
                            '/bishe/assets/images/logo.jpg',
                            'D:/phpstudy_pro/WWW/www.test.com/bishe/assets/images/logo.jpg'
                        ];
                        function tryPath(index) {
                            if (index >= paths.length) return;
                            
                            const img = new Image();
                            img.onload = function() {
                                logoImg.src = paths[index];
                                console.log('Logo loaded from path: ' + paths[index]);
                            };
                            img.onerror = function() {
                                tryPath(index + 1);
                            };
                            img.src = paths[index];
                        }
                        
                        tryPath(0);
                    }
                }, 300);
            }
        });
    </script>
</body>
</html>