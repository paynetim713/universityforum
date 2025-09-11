<?php
require_once 'includes/config.php';

// 检查是否存在 is_admin 列
try {
    $pdo->query("SELECT is_admin FROM users LIMIT 1");
} catch (PDOException $e) {
    // 如果列不存在，添加它
    $pdo->exec("ALTER TABLE users ADD COLUMN is_admin TINYINT(1) DEFAULT 0");
    echo "Added is_admin column<br>";
}

// 将指定用户设置为管理员
$admin_username = 'admin'; 
$stmt = $pdo->prepare("UPDATE users SET is_admin = 1 WHERE username = ?");
if ($stmt->execute([$admin_username])) {
    echo "Successfully set {$admin_username} as admin<br>";
} else {
    echo "Failed to set admin privileges<br>";
}

// 显示所有管理员
$stmt = $pdo->query("SELECT username, is_admin FROM users WHERE is_admin = 1");
$admins = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "<br>Current admins:<br>";
foreach ($admins as $admin) {
    echo "- " . htmlspecialchars($admin['username']) . " (is_admin = {$admin['is_admin']})<br>";
}

// 使用完后删除此文件
unlink(__FILE__);
?> 