<?php
// 获取当前页面文件名
$current_page = basename($_SERVER['PHP_SELF']);

// 获取未读通知数量（如果用户已登录）
$unread_count = 0;
if (isset($_SESSION['user_id'])) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = FALSE");
    $stmt->execute([$_SESSION['user_id']]);
    $unread_count = $stmt->fetchColumn();
}

// 获取审核队列中的内容数量（仅管理员可见）
$reviewQueueCount = 0;
$pendingFilesCount = 0;
if (isset($_SESSION['is_admin']) && $_SESSION['is_admin']) {
    try {
        // 检查content_review_queue表是否存在
        $check_stmt = $pdo->query("SHOW TABLES LIKE 'content_review_queue'");
        if ($check_stmt->rowCount() > 0) {
            $stmt = $pdo->query("SELECT COUNT(*) FROM content_review_queue");
            $reviewQueueCount = $stmt->fetchColumn();
        }
        
        // 检查question_attachments表是否存在
        $check_stmt = $pdo->query("SHOW TABLES LIKE 'question_attachments'");
        if ($check_stmt->rowCount() > 0) {
            // 检查status字段是否存在
            $check_col = $pdo->query("SHOW COLUMNS FROM question_attachments LIKE 'status'");
            if ($check_col->rowCount() > 0) {
                $stmt = $pdo->query("SELECT COUNT(*) FROM question_attachments WHERE status = 'pending'");
                $pendingFilesCount = $stmt->fetchColumn();
            }
        }
    } catch (PDOException $e) {
        // 表可能不存在，忽略错误
        error_log("Error checking admin tables: " . $e->getMessage());
    }
}

// 确定当前活动页面
$is_active = function($page) use ($current_page) {
    return $current_page == $page ? 'active' : '';
};
?>
<nav class="navbar">
    <a href="<?php echo isset($_SESSION['user_id']) ? 'forum.php' : 'index.php'; ?>" class="nav-brand">
        <img src="assets/images/logo.jpg" alt="UKM Logo" class="nav-logo">
        <div class="logo-text">
            <h1>UKM NEXUS</h1>
            <p>Universiti Kebangsaan Malaysia</p>
        </div>
    </a>
    
    <?php if (isset($sensitive_count) && $sensitive_count > 0): ?>
        <span class="sensitive-badge" title="<?php echo $sensitive_count; ?> 个敏感内容通知">!</span>
    <?php endif; ?>
    
    <?php if (isset($_SESSION['user_id'])): ?>
        <ul class="nav-menu">
            <li><a href="new_question.php" class="btn btn-primary">
                <i class="fas fa-plus"></i> Ask Question
            </a></li>
            
            <li><a href="forum.php" class="nav-link <?php echo $is_active('forum.php'); ?>">
                <i class="fas fa-home"></i> Forum
            </a></li>
            
            <?php if (isset($_SESSION['is_admin']) && $_SESSION['is_admin'] == 1): ?>
                <li><a href="category_management.php" class="nav-link <?php echo $is_active('category_management.php'); ?>">
                    <i class="fas fa-tags"></i> Categories
                </a></li>
                
                <li><a href="admin/dashboard.php" class="nav-link <?php echo $is_active('dashboard.php'); ?>">
                    <i class="fas fa-tachometer-alt"></i> Admin
                </a></li>
                
                <li><a href="file_moderation.php" class="nav-link <?php echo $is_active('file_moderation.php'); ?>">
                    <i class="fas fa-file-alt"></i> Files
                    <?php if ($pendingFilesCount > 0): ?>
                        <span class="badge badge-primary badge-sm"><?php echo $pendingFilesCount; ?></span>
                    <?php endif; ?>
                </a></li>
            <?php endif; ?>
            
            <li><a href="notifications.php" class="nav-link <?php echo $is_active('notifications.php'); ?>">
                <i class="fas fa-bell"></i> Notifications
                <?php if ($unread_count > 0): ?>
                    <span class="badge badge-primary badge-sm"><?php echo $unread_count; ?></span>
                <?php endif; ?>
            </a></li>
            
            <li><a href="profile.php" class="nav-link <?php echo $is_active('profile.php'); ?>">
                <i class="fas fa-user"></i> Profile
            </a></li>
            
            <li><a href="logout.php" class="nav-link">
                <i class="fas fa-sign-out-alt"></i> Logout
            </a></li>
        </ul>
    <?php endif; ?>
</nav>