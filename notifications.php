<?php
session_start();
require_once 'includes/config.php';

// 检查登录了么
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

$user_id = $_SESSION['user_id'];

// 标记已读
if (isset($_POST['mark_all_read'])) {
    $stmt = $pdo->prepare("UPDATE notifications SET is_read = TRUE WHERE user_id = ?");
    $stmt->execute([$user_id]);
    header("Location: notifications.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['delete_read'])) {
    // 删除所有已读的
    $stmt = $pdo->prepare("DELETE FROM notifications WHERE user_id = ? AND is_read = 1");
    if ($stmt->execute([$_SESSION['user_id']])) {
        $success = "Read notifications have been deleted successfully!";
        header("Location: notifications.php");
        exit();
    } else {
        $error = "Failed to delete read notifications";
    }
}

// 获取带过滤功能的通知
$type_filter = isset($_GET['type']) ? $_GET['type'] : 'all';
$time_filter = isset($_GET['time']) ? $_GET['time'] : 'all';

$where_clauses = ["user_id = ?"];
$params = [$user_id];

if ($type_filter != 'all') {
    $where_clauses[] = "type = ?";
    $params[] = $type_filter;
}

if ($time_filter != 'all') {
    switch ($time_filter) {
        case 'today':
            $where_clauses[] = "created_at >= CURDATE()";
            break;
        case 'week':
            $where_clauses[] = "created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)";
            break;
        case 'month':
            $where_clauses[] = "created_at >= DATE_SUB(CURDATE(), INTERVAL 1 MONTH)";
            break;
    }
}

$where_clause = implode(" AND ", $where_clauses);
$sql = "SELECT * FROM notifications WHERE $where_clause ORDER BY created_at DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$notifications = $stmt->fetchAll();

// 未读取的数量
$stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = FALSE");
$stmt->execute([$user_id]);
$unread_count = $stmt->fetchColumn();

// 在获取通知前添加以下内容
$stmt = $pdo->prepare("SELECT COUNT(*) as total FROM notifications WHERE user_id = ?");
$stmt->execute([$_SESSION['user_id']]);
$result = $stmt->fetch();
$total_notifications = $result['total'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>UKM NEXUS - Notifications</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <div class="page-wrapper">
        <?php require_once 'includes/navbar.php'; ?>
        
        <div class="main-container">
            <div class="container">
                <div class="card">
                    <div class="flex justify-between items-center mb-4">
                        <h1 class="text-2xl font-semibold">Notifications</h1>
                        <div class="flex gap-2">
                            <?php if ($unread_count > 0): ?>
                                <form method="POST" class="inline">
                                    <button type="submit" name="mark_all_read" class="btn btn-primary btn-sm">
                                        <i class="fas fa-check-double"></i> Mark All as Read
                                    </button>
                                </form>
                            <?php endif; ?>
                            <?php if ($total_notifications > 0): ?>
                                <form method="POST" class="inline" onsubmit="return confirm('Are you sure you want to delete all read notifications?');">
                                    <button type="submit" name="delete_read" class="btn btn-danger btn-sm">
                                        <i class="fas fa-trash"></i> Delete Read
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="flex gap-4 mb-4">
                        <select name="type" class="form-select" onchange="window.location.href='?type='+this.value+'&time=<?php echo $time_filter; ?>'">
                            <option value="all" <?php echo $type_filter == 'all' ? 'selected' : ''; ?>>All Notifications</option>
                            <option value="answer" <?php echo $type_filter == 'answer' ? 'selected' : ''; ?>>Question Answers</option>
                            <option value="system" <?php echo $type_filter == 'system' ? 'selected' : ''; ?>>System Notifications</option>
                        </select>

                        <select name="time" class="form-select" onchange="window.location.href='?type=<?php echo $type_filter; ?>&time='+this.value">
                            <option value="all" <?php echo $time_filter == 'all' ? 'selected' : ''; ?>>All Time</option>
                            <option value="today" <?php echo $time_filter == 'today' ? 'selected' : ''; ?>>Today</option>
                            <option value="week" <?php echo $time_filter == 'week' ? 'selected' : ''; ?>>Last Week</option>
                            <option value="month" <?php echo $time_filter == 'month' ? 'selected' : ''; ?>>Last Month</option>
                        </select>
                    </div>
                    <div class="notification-icon">
    <?php if ($notification['type'] == 'answer'): ?>
        <i class="fas fa-comment-dots text-primary"></i>
    <?php elseif ($notification['type'] == 'sensitive_content'): ?>
        <i class="fas fa-exclamation-triangle text-danger"></i>
    <?php else: ?>
        <i class="fas fa-bell text-warning"></i>
    <?php endif; ?>
</div>
                    <?php if (count($notifications) > 0): ?>
                        <div class="space-y-4">
                            <?php foreach ($notifications as $notification): ?>
                                <div class="notification-card <?php echo $notification['is_read'] ? 'is-read' : ''; ?>">
                                    <div class="notification-icon">
                                        <?php if ($notification['type'] == 'answer'): ?>
                                            <i class="fas fa-comment-dots text-primary"></i>
                                        <?php else: ?>
                                            <i class="fas fa-bell text-warning"></i>
                                        <?php endif; ?>
                                    </div>
                                    <div class="notification-content">
                                        <a href="<?php echo htmlspecialchars($notification['link']); ?>" class="notification-message">
                                            <?php 
                                            $content = $notification['content'];
                                            // Replace Chinese text with English
                                            $content = str_replace('收到了新的回答:', 'New answer received:', $content);
                                            echo htmlspecialchars($content); 
                                            ?>
                                        </a>
                                        <div class="notification-meta">
                                            <?php echo date('Y-m-d H:i', strtotime($notification['created_at'])); ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-8">
                            <p class="text-secondary">No notifications yet</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
