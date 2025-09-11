<?php
session_start();
require_once 'includes/config.php';
require_once 'SensitiveWordFilter.php';

// 检查用户是否已登录
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

// 检查是否提供了问题 ID
if (!isset($_GET['id'])) {
    header("Location: forum.php");
    exit();
}

$question_id = $_GET['id'];

//  先获取用户头像，再获取问题详细信息
$stmt = $pdo->prepare("
    SELECT q.*, u.username, u.avatar,
           GROUP_CONCAT(t.name) as tags
    FROM questions q
    LEFT JOIN users u ON q.user_id = u.id
    LEFT JOIN question_tags qt ON q.id = qt.question_id
    LEFT JOIN tags t ON qt.tag_id = t.id
    WHERE q.id = ?
    GROUP BY q.id
");
$stmt->execute([$question_id]);
$question = $stmt->fetch();

if (!$question) {
    header("Location: forum.php");
    exit();
}

// 获取问题的附件 - 修改为只显示已批准的附件（对于管理员和问题所有者例外）
if (isset($_SESSION['is_admin']) && $_SESSION['is_admin'] || $question['user_id'] == $_SESSION['user_id']) {
    // 管理员和问题所有者可以看到所有附件（包括待审核的）
    $stmt = $pdo->prepare("SELECT * FROM question_attachments WHERE question_id = ? ORDER BY created_at");
    $stmt->execute([$question_id]);
} else {
    // 普通用户只能看到已批准的附件
    $stmt = $pdo->prepare("SELECT * FROM question_attachments WHERE question_id = ? AND status = 'approved' ORDER BY created_at");
    $stmt->execute([$question_id]);
}
$attachments = $stmt->fetchAll();

// 检查关注状态和获取关注者数量
$is_following = false;
$follower_count = 0;

if (isset($_SESSION['user_id'])) {
    // 检查当前用户是否关注
    $stmt = $pdo->prepare("SELECT * FROM question_followers WHERE question_id = ? AND user_id = ?");
    $stmt->execute([$question_id, $_SESSION['user_id']]);
    $is_following = $stmt->rowCount() > 0;
}

// 获取关注者数量
$stmt = $pdo->prepare("SELECT COUNT(*) FROM question_followers WHERE question_id = ?");
$stmt->execute([$question_id]);
$follower_count = $stmt->fetchColumn();

// 处理提交的新答案、回复等
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // 处理关注/取消关注请求
    if (isset($_POST['toggle_follow'])) {
        $user_id = $_SESSION['user_id'];
        
        // 检查是否已经关注
        $stmt = $pdo->prepare("SELECT * FROM question_followers WHERE question_id = ? AND user_id = ?");
        $stmt->execute([$question_id, $user_id]);
        $is_following_now = $stmt->rowCount() > 0;
        
        if ($is_following_now) {
            // 取消关注
            $stmt = $pdo->prepare("DELETE FROM question_followers WHERE question_id = ? AND user_id = ?");
            $stmt->execute([$question_id, $user_id]);
            
            if (isset($_POST['ajax'])) {
                echo json_encode(['status' => 'success', 'action' => 'unfollowed', 'message' => 'Unfollowed question successfully']);
                exit();
            }
        } else {
            // 添加关注
            $stmt = $pdo->prepare("INSERT INTO question_followers (question_id, user_id) VALUES (?, ?)");
            $stmt->execute([$question_id, $user_id]);
            
            if (isset($_POST['ajax'])) {
                echo json_encode(['status' => 'success', 'action' => 'followed', 'message' => 'Following question successfully']);
                exit();
            }
        }
        
        // 非AJAX请求重定向
        header("Location: question.php?id=" . $question_id);
        exit();
    }
    
    // 处理新答案
    if (isset($_POST['content'])) {
        $content = trim($_POST['content']);

        $filter = new SensitiveWordFilter();
    
        // 从文件加载敏感词
        $filter->loadFromFile('CensorWords.txt');
        
        // 过滤并清理空格
        $text = $content;
        $cleanText = $filter->filter($text);
        $content = trim($cleanText); // 修复：添加trim()清理过滤后的空格
        
        if (empty($content)) {
            $error = "Answer content cannot be empty.";
        } else {
            $user_id = $_SESSION['user_id'];
            $stmt = $pdo->prepare("INSERT INTO answers (question_id, user_id, content) VALUES (?, ?, ?)");
            if ($stmt->execute([$question_id, $user_id, $content])) {
                // 向问题作者发送通知
                if ($question['user_id'] != $user_id) {  //如果用户回答了自己的问题，则不发出通知
                    $notification_content = "New answer received: " . mb_substr($content, 0, 50) . (mb_strlen($content) > 50 ? "..." : "");
                    $stmt = $pdo->prepare("INSERT INTO notifications (user_id, type, content, link, created_at) VALUES (?, 'answer', ?, ?, NOW())");
                    $stmt->execute([$question['user_id'], $notification_content, "question.php?id=" . $question_id]);
                }
                
                // 通知所有关注者 (除了答案作者和问题作者)
                $stmt = $pdo->prepare("
                    SELECT DISTINCT qf.user_id 
                    FROM question_followers qf 
                    WHERE qf.question_id = ? 
                    AND qf.user_id != ? 
                    AND qf.user_id != ?
                ");
                $stmt->execute([$question_id, $user_id, $question['user_id']]);
                $followers = $stmt->fetchAll(PDO::FETCH_COLUMN);
                
                if (!empty($followers)) {
                    $follower_notification = "New answer posted on a question you're following: \"" . 
                                           mb_substr($question['title'], 0, 30) . 
                                           (mb_strlen($question['title']) > 30 ? "..." : "") . "\"";
                    
                    // 批量插入通知
                    $notify_values = [];
                    $notify_params = [];
                    
                    foreach ($followers as $follower_id) {
                        $notify_values[] = "(?, 'follow_answer', ?, ?, 0, NOW())";
                        $notify_params[] = $follower_id;
                        $notify_params[] = $follower_notification;
                        $notify_params[] = "question.php?id=" . $question_id;
                    }
                    
                    if (!empty($notify_values)) {
                        $notify_sql = "INSERT INTO notifications (user_id, type, content, link, is_read, created_at) VALUES " . implode(', ', $notify_values);
                        $notify_stmt = $pdo->prepare($notify_sql);
                        $notify_stmt->execute($notify_params);
                    }
                }
                
                $success = "Answer posted successfully!";
                // 使用绝对路径进行重定向
                header("Location: question.php?id=" . $question_id);
                exit();
            } else {
                $error = "Failed to post answer";
            }
        }
    }
    // 处理回复提交
    elseif (isset($_POST['reply_content']) && isset($_POST['answer_id'])) {
        $reply_content = trim($_POST['reply_content']);
        $answer_id = $_POST['answer_id'];
        
        // 过滤敏感词并清理空格
        $filter = new SensitiveWordFilter();
        $filter->loadFromFile('CensorWords.txt');
        $reply_content = trim($filter->filter($reply_content)); // 修复：添加trim()
        
        if (empty($reply_content)) {
            $error = "Reply content cannot be empty.";
        } else {
            $user_id = $_SESSION['user_id'];
            
            // 验证答案是否存在
            $stmt = $pdo->prepare("SELECT id, user_id FROM answers WHERE id = ?");
            $stmt->execute([$answer_id]);
            $answer = $stmt->fetch();
            
            if ($answer) {
                // 插入回复
                $stmt = $pdo->prepare("INSERT INTO answer_replies (answer_id, user_id, content) VALUES (?, ?, ?)");
                if ($stmt->execute([$answer_id, $user_id, $reply_content])) {
                    // 通知答案作者（如果不是自己的回复）
                    if ($answer['user_id'] != $user_id) {
                        $notification_content = "Someone replied to your answer: " . mb_substr($reply_content, 0, 50) . (mb_strlen($reply_content) > 50 ? "..." : "");
                        $stmt = $pdo->prepare("INSERT INTO notifications (user_id, type, content, link, is_read, created_at) VALUES (?, 'reply', ?, ?, 0, NOW())");
                        $stmt->execute([$answer['user_id'], $notification_content, "question.php?id=" . $question_id . "#answer-" . $answer_id]);
                    }
                    
                    $success = "Reply posted successfully!";
                    // 重定向避免表单重复提交
                    header("Location: question.php?id=" . $question_id . "#answer-" . $answer_id);
                    exit();
                } else {
                    $error = "Failed to post reply";
                }
            } else {
                $error = "Invalid answer";
            }
        }
    }
    elseif (isset($_POST['delete_answer'])) {
        $answer_id = $_POST['answer_id'];
        
        // 获取删除通知的答案内容
        $stmt = $pdo->prepare("SELECT content FROM answers WHERE id = ?");
        $stmt->execute([$answer_id]);
        $answer = $stmt->fetch();
        
        // 删除答案
        $stmt = $pdo->prepare("DELETE FROM answers WHERE id = ? AND (user_id = ? OR ? = TRUE)");
        if ($stmt->execute([$answer_id, $_SESSION['user_id'], isset($_SESSION['is_admin']) && $_SESSION['is_admin']])) {
            // 删除相关通知
            if ($answer) {
                $notification_content = "New answer received: " . mb_substr($answer['content'], 0, 50) . (mb_strlen($answer['content']) > 50 ? "..." : "");
                $stmt = $pdo->prepare("DELETE FROM notifications WHERE user_id = ? AND type = 'answer' AND content = ?");
                $stmt->execute([$question['user_id'], $notification_content]);
            }
            header("Location: question.php?id=" . $question_id);
            exit();
        }
    }
    elseif (isset($_POST['delete_reply'])) {
        $reply_id = $_POST['reply_id'];
        $stmt = $pdo->prepare("DELETE FROM answer_replies WHERE id = ? AND (user_id = ? OR ? = TRUE)");
        if ($stmt->execute([$reply_id, $_SESSION['user_id'], isset($_SESSION['is_admin']) && $_SESSION['is_admin']])) {
            header("Location: question.php?id=" . $question_id);
            exit();
        }
    }
    elseif (isset($_POST['delete_question'])) {
        $question_id = $_POST['delete_question'];
        
        // 检查用户是问题作者还是管理员
        if ($question['user_id'] == $_SESSION['user_id'] || (isset($_SESSION['is_admin']) && $_SESSION['is_admin'] == true)) {
            try {
                $pdo->beginTransaction();
                
                // 删除关注记录
                $stmt = $pdo->prepare("DELETE FROM question_followers WHERE question_id = ?");
                $stmt->execute([$question_id]);
                
                // 首先删除与此问题相关的所有答案回复
                $stmt = $pdo->prepare("DELETE FROM answer_replies WHERE answer_id IN (SELECT id FROM answers WHERE question_id = ?)");
                $stmt->execute([$question_id]);
                
                // 删除所有答案
                $stmt = $pdo->prepare("DELETE FROM answers WHERE question_id = ?");
                $stmt->execute([$question_id]);
                
                // 删除tag
                $stmt = $pdo->prepare("DELETE FROM question_tags WHERE question_id = ?");
                $stmt->execute([$question_id]);
                
                // 删除附件文件和记录
                $stmt = $pdo->prepare("SELECT * FROM question_attachments WHERE question_id = ?");
                $stmt->execute([$question_id]);
                $attachments_to_delete = $stmt->fetchAll();
                
                foreach ($attachments_to_delete as $attachment) {
                    // 删除物理文件
                    if (file_exists($attachment['upload_path'])) {
                        unlink($attachment['upload_path']);
                    }
                }
                
                // 删除附件记录
                $stmt = $pdo->prepare("DELETE FROM question_attachments WHERE question_id = ?");
                $stmt->execute([$question_id]);
                
                // 删除相关通知
                $stmt = $pdo->prepare("DELETE FROM notifications WHERE link LIKE ?");
                $stmt->execute(['%question.php?id=' . $question_id . '%']);
                
                //问题彻底删除
                $stmt = $pdo->prepare("DELETE FROM questions WHERE id = ?");
                $stmt->execute([$question_id]);
                
                $pdo->commit();
                
                // 成功删除后重定向到论坛页面
                header("Location: forum.php");
                exit();
            } catch (Exception $e) {
                $pdo->rollBack();
                $error = "Failed to delete question: " . $e->getMessage();
            }
        } else {
            $error = "You don't have permission to delete this question";
        }
    } elseif (isset($_POST['delete_attachment'])) {
        $attachment_id = $_POST['attachment_id'];
        
        // 检查用户是问题作者还是管理员
        if ($question['user_id'] == $_SESSION['user_id'] || (isset($_SESSION['is_admin']) && $_SESSION['is_admin'] == true)) {
            // 获取附件信息
            $stmt = $pdo->prepare("SELECT * FROM question_attachments WHERE id = ? AND question_id = ?");
            $stmt->execute([$attachment_id, $question_id]);
            $attachment = $stmt->fetch();
            
            if ($attachment) {
                // 删除物理文件
                if (file_exists($attachment['upload_path'])) {
                    unlink($attachment['upload_path']);
                }
                
                // 删除数据库记录
                $stmt = $pdo->prepare("DELETE FROM question_attachments WHERE id = ?");
                if ($stmt->execute([$attachment_id])) {
                    // 重定向回问题页面
                    header("Location: question.php?id=" . $question_id);
                    exit();
                } else {
                    $error = "Failed to delete attachment";
                }
            } else {
                $error = "Attachment not found";
            }
        } else {
            $error = "You don't have permission to delete this attachment";
        }
    }
}

// 修改查询，加入用户的管理员状态
$stmt = $pdo->prepare("
    SELECT a.*, u.username, u.avatar, u.is_admin,
           (SELECT COUNT(*) FROM answer_replies WHERE answer_id = a.id) as reply_count
    FROM answers a 
    JOIN users u ON a.user_id = u.id 
    WHERE a.question_id = ? 
    ORDER BY a.created_at ASC
");
$stmt->execute([$question_id]);
$answers = $stmt->fetchAll();

// 获取所有回复，也包含用户的管理员状态
$answer_replies = [];
if (!empty($answers)) {
    $answer_ids = array_column($answers, 'id');
    $placeholders = str_repeat('?,', count($answer_ids) - 1) . '?';
    
    $stmt = $pdo->prepare("
        SELECT r.*, u.username, u.avatar, u.is_admin
        FROM answer_replies r
        JOIN users u ON r.user_id = u.id
        WHERE r.answer_id IN ($placeholders)
        ORDER BY r.created_at ASC
    ");
    $stmt->execute($answer_ids);
    $replies = $stmt->fetchAll();
    
    // 按答案ID分组回复
    foreach ($replies as $reply) {
        if (!isset($answer_replies[$reply['answer_id']])) {
            $answer_replies[$reply['answer_id']] = [];
        }
        $answer_replies[$reply['answer_id']][] = $reply;
    }
}

$error = '';
$success = '';

// 格式化文件大小的辅助函数
function formatFileSize($bytes) {
    if ($bytes < 1024) {
        return $bytes . ' B';
    } elseif ($bytes < 1048576) {
        return round($bytes / 1024, 2) . ' KB';
    } elseif ($bytes < 1073741824) {
        return round($bytes / 1048576, 2) . ' MB';
    } else {
        return round($bytes / 1073741824, 2) . ' GB';
    }
}

// 根据文件扩展名获取图标类
function getFileIconClass($extension) {
    $extension = strtolower($extension);
    
    $iconMap = [
        'pdf' => 'fas fa-file-pdf text-red-600',
        'doc' => 'fas fa-file-word text-blue-600',
        'docx' => 'fas fa-file-word text-blue-600',
        'xls' => 'fas fa-file-excel text-green-600',
        'xlsx' => 'fas fa-file-excel text-green-600',
        'ppt' => 'fas fa-file-powerpoint text-orange-600',
        'pptx' => 'fas fa-file-powerpoint text-orange-600',
        'zip' => 'fas fa-file-archive text-yellow-600',
        'rar' => 'fas fa-file-archive text-yellow-600',
        'txt' => 'fas fa-file-alt text-gray-600',
        'jpg' => 'fas fa-file-image text-purple-600',
        'jpeg' => 'fas fa-file-image text-purple-600',
        'png' => 'fas fa-file-image text-purple-600',
        'gif' => 'fas fa-file-image text-purple-600'
    ];
    
    return isset($iconMap[$extension]) ? $iconMap[$extension] : 'fas fa-file text-gray-600';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>UKM NEXUS - <?php echo htmlspecialchars($question['title']); ?></title>
    <link rel="stylesheet" href="style.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* 讲师标签样式 */
        .lecturer-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            background: linear-gradient(135deg, #3b82f6, #1d4ed8);
            color: white;
            padding: 0.25rem 0.5rem;
            border-radius: 0.375rem;
            font-size: 0.75rem;
            font-weight: 600;
            margin-left: 0.5rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .lecturer-badge i {
            font-size: 0.75rem;
            color: #fbbf24;
        }

        /* 用户头像修复 */
        .user-avatar {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            object-fit: cover;
            margin-right: 0.5rem;
            flex-shrink: 0;
        }

        .user-avatar-small {
            width: 24px;
            height: 24px;
            border-radius: 50%;
            object-fit: cover;
            margin-right: 0.5rem;
            flex-shrink: 0;
        }

        /* 用户信息区域 */
        .user-info {
            display: flex;
            align-items: center;
        }

        .user-details {
            display: flex;
            flex-direction: column;
        }

        .username {
            font-weight: 600;
            color: var(--text-primary);
        }

        .answer-date, .reply-date {
            font-size: 0.875rem;
            color: var(--text-secondary);
        }

        /* 附件样式 */
        .attachments-container {
            border-top: 1px solid var(--border-color);
            padding-top: 1rem;
            margin-top: 1.5rem;
        }
        .attachments-header {
            display: flex;
            align-items: center;
            margin-bottom: 1rem;
        }
        .attachments-title {
            font-weight: 600;
            margin-right: 0.5rem;
        }
        .attachments-count {
            background-color: var(--primary-color-light);
            color: var(--primary-color);
            border-radius: 9999px;
            padding: 0.1rem 0.5rem;
            font-size: 0.75rem;
        }
        .attachments-grid {
            display: grid;
            grid-template-columns: repeat(1, minmax(0, 1fr));
            gap: 0.75rem;
        }
        @media (min-width: 640px) {
            .attachments-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }
        .attachment-item {
            border: 1px solid var(--border-color);
            border-radius: 0.375rem;
            padding: 0.75rem;
            display: flex;
            align-items: center;
            transition: all 0.2s ease;
        }
        .attachment-item:hover {
            border-color: var(--primary-color);
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        }
        .attachment-icon {
            font-size: 2rem;
            margin-right: 1rem;
            flex-shrink: 0;
        }
        .attachment-thumbnail {
            width: 3rem;
            height: 3rem;
            border-radius: 0.25rem;
            overflow: hidden;
            margin-right: 1rem;
            flex-shrink: 0;
        }
        .attachment-thumbnail img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .attachment-details {
            flex: 1;
            min-width: 0;
        }
        .attachment-filename {
            font-weight: 500;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .attachment-info {
            color: var(--text-secondary);
            font-size: 0.875rem;
            display: flex;
            align-items: center;
        }
        .attachment-actions {
            display: flex;
            gap: 0.5rem;
            margin-left: 0.5rem;
        }
        .lightbox {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.8);
            justify-content: center;
            align-items: center;
        }
        .lightbox-content {
            max-width: 90%;
            max-height: 90%;
        }
        .lightbox-close {
            position: absolute;
            top: 1rem;
            right: 1.5rem;
            color: white;
            font-size: 2rem;
            cursor: pointer;
        }
        
        /* 附件状态标签 */
        .attachment-status {
            font-size: 0.75rem;
            padding: 0.25rem 0.5rem;
            border-radius: 9999px;
            margin-left: 0.5rem;
        }
        
        .status-pending {
            background-color: var(--warning-color-light);
            color: var(--warning-color);
        }
        
        .status-approved {
            background-color: var(--success-color-light);
            color: var(--success-color);
        }
        
        .status-rejected {
            background-color: var(--error-color-light);
            color: var(--error-color);
        }
        
        /* 回复样式 */
        .reply-form {
            background-color: var(--background-secondary);
            border-radius: 0.5rem;
            padding: 1rem;
            margin-top: 0.5rem;
            margin-bottom: 1rem;
        }

        .replies-container {
            margin-left: 1.5rem;
            border-left: 2px solid var(--primary-color-light);
            padding-left: 1rem;
            margin-top: 1rem;
        }

        .reply-card {
            padding: 0.75rem;
            background-color: var(--background-secondary);
            border-radius: 0.5rem;
            margin-bottom: 0.5rem;
        }

        .reply-header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 0.25rem;
        }

        .reply-content {
            font-size: 0.9375rem;
            color: var(--text-primary);
            margin-top: 0.5rem;
            white-space: pre-line; /* 保持换行但不保留多余空格 */
        }

        .answer-content {
            white-space: pre-line; /* 保持换行但不保留多余空格 */
        }

        .btn-icon {
            background: none;
            border: none;
            cursor: pointer;
            padding: 0.25rem;
            border-radius: 0.25rem;
            transition: var(--transition-fast);
        }

        .btn-icon:hover {
            background-color: rgba(0, 0, 0, 0.05);
        }

        .text-error {
            color: var(--error-color);
        }
        
        .answer-actions {
            display: flex;
            gap: 0.5rem;
            margin-top: 1rem;
        }
        
      /*关注样式*/
        .question-actions {
            border-top: 1px solid var(--border-color);
            padding-top: 1rem;
            margin-top: 1rem;
        }

        .btn-outline {
            background-color: var(--background-color);
            color: var(--text-secondary);
            border: 1px solid var(--border-color);
        }

        .btn-outline:hover {
            background-color: var(--background-hover);
            color: var(--primary-color);
            border-color: var(--primary-color);
        }

        .btn-outline:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }

        .btn-primary:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }

        /* 通知弹窗样式 */
        .notification-popup {
            position: fixed;
            top: 100px;
            right: 20px;
            z-index: 1000;
            min-width: 300px;
            animation: slideInRight 0.3s ease-out;
        }

        @keyframes slideInRight {
            from {
                transform: translateX(100%);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }

        .alert-info {
            background: linear-gradient(45deg, var(--info-color-light), #dbeafe);
            color: var(--info-color);
            border-color: color-mix(in srgb, var(--info-color) 30%, transparent);
        }

        /* 关注按钮特殊样式 */
        .follow-btn-following {
            background: linear-gradient(45deg, #22c55e, #16a34a);
            color: white;
        }

        .follow-btn-following:hover {
            background: linear-gradient(45deg, #16a34a, #15803d);
        }

        /* 修复：确保没有text-indent或其他导致前导空格的CSS */
        .answer-content p:first-child,
        .reply-content p:first-child {
            margin-top: 0;
        }
        
        .answer-content,
        .reply-content {
            text-indent: 0; /* 确保没有首行缩进 */
        }
    </style>
</head>
<body>
    <div class="page-wrapper">
        <?php require_once 'includes/navbar.php'; ?>

        <main class="main-content">
            <div class="container">
                <?php if (isset($error) && $error): ?>
                    <div class="alert alert-error"><?php echo $error; ?></div>
                <?php endif; ?>
                <?php if (isset($success) && $success): ?>
                    <div class="alert alert-success"><?php echo $success; ?></div>
                <?php endif; ?>

                <div class="card">
                    <div class="question-header mb-4">
                        <h1 class="mb-2"><?php echo htmlspecialchars($question['title']); ?></h1>
                        <div class="flex items-center gap-4">
                            <div class="user-info">
                                <?php if (!empty($question['avatar'])): ?>
                                    <img src="uploads/avatars/<?php echo htmlspecialchars($question['avatar']); ?>" 
                                         alt="User Avatar" 
                                         class="user-avatar"
                                         onerror="this.style.display='none'; this.nextElementSibling.style.display='inline';">
                                    <i class="fas fa-user-circle fa-2x" style="display: none; margin-right: 0.5rem; color: var(--text-secondary);"></i>
                                <?php else: ?>
                                    <i class="fas fa-user-circle fa-2x" style="margin-right: 0.5rem; color: var(--text-secondary);"></i>
                                <?php endif; ?>
                                <span class="text-secondary">
                                    <?php echo htmlspecialchars($question['username']); ?>
                                </span>
                            </div>
                            <span class="text-tertiary">
                                <i class="fas fa-clock"></i>
                                <?php echo date('M j, Y', strtotime($question['created_at'])); ?>
                            </span>
                        </div>
                        <?php if (!empty($question['tags'])): ?>
                            <div class="tags-container mt-3">
                                <?php foreach (explode(',', $question['tags']) as $tag): ?>
                                    <span class="tag"><?php echo htmlspecialchars($tag); ?></span>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                        
                        <!-- 关注和操作按钮区域 -->
                        <div class="question-actions">
                            <div class="flex items-center gap-4">
                                <!-- 关注按钮 -->
                                <button type="button" id="followBtn" 
                                        class="btn <?php echo $is_following ? 'btn-primary follow-btn-following' : 'btn-outline'; ?>" 
                                        onclick="toggleFollow(<?php echo $question_id; ?>)">
                                    <i class="<?php echo $is_following ? 'fas fa-heart' : 'far fa-heart'; ?>" id="followIcon"></i>
                                    <span id="followText"><?php echo $is_following ? 'Following' : 'Follow'; ?></span>
                                </button>
                                
                                <!-- 关注者数量 -->
                                <span class="text-secondary">
                                    <i class="fas fa-users"></i>
                                    <span id="followerCount"><?php echo $follower_count; ?></span> 
                                    <span id="followerLabel"><?php echo $follower_count == 1 ? 'follower' : 'followers'; ?></span>
                                </span>
                                
                                <!-- 删除问题按钮 -->
                                <?php if ($question['user_id'] == $_SESSION['user_id'] || (isset($_SESSION['is_admin']) && $_SESSION['is_admin'] == true)): ?>
                                    <form method="POST" onsubmit="return confirm('Are you sure you want to delete this question? This action cannot be undone.');">
                                        <input type="hidden" name="delete_question" value="<?php echo $question['id']; ?>">
                                        <button type="submit" class="btn btn-danger btn-sm">
                                            <i class="fas fa-trash"></i> Delete Question
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="question-content mb-4">
                        <?php echo nl2br(htmlspecialchars($question['content'])); ?>
                    </div>

                    <?php if (!empty($attachments)): ?>
                        <div class="attachments-container">
                            <div class="attachments-header">
                                <div class="attachments-title">
                                    <i class="fas fa-paperclip text-primary"></i> Attachments
                                </div>
                                <div class="attachments-count"><?php echo count($attachments); ?></div>
                            </div>
                            <div class="attachments-grid">
                                <?php foreach ($attachments as $attachment): ?>
                                    <?php 
                                    $file_ext = pathinfo($attachment['original_filename'], PATHINFO_EXTENSION);
                                    $is_image = in_array(strtolower($file_ext), ['jpg', 'jpeg', 'png', 'gif']);
                                    $icon_class = getFileIconClass($file_ext);
                                    ?>
                                    
                                    <div class="attachment-item">
                                        <?php if ($is_image && ($attachment['status'] == 'approved' || $question['user_id'] == $_SESSION['user_id'] || (isset($_SESSION['is_admin']) && $_SESSION['is_admin']))): ?>
                                            <div class="attachment-thumbnail">
                                                <img src="<?php echo htmlspecialchars($attachment['upload_path']); ?>" 
                                                    alt="<?php echo htmlspecialchars($attachment['original_filename']); ?>"
                                                    class="preview-image" data-src="<?php echo htmlspecialchars($attachment['upload_path']); ?>"
                                                    onclick="openModal(this.src)">
                                            </div>
                                        <?php else: ?>
                                            <div class="attachment-icon">
                                                <i class="<?php echo $icon_class; ?>"></i>
                                            </div>
                                        <?php endif; ?>
                                        
                                        <div class="attachment-details">
                                            <div class="attachment-filename">
                                                <?php echo htmlspecialchars($attachment['original_filename']); ?>
                                                
                                                <?php if ($question['user_id'] == $_SESSION['user_id'] || (isset($_SESSION['is_admin']) && $_SESSION['is_admin'])): ?>
                                                    <span class="attachment-status status-<?php echo $attachment['status']; ?>">
                                                        <?php echo ucfirst($attachment['status']); ?>
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="attachment-info">
                                                <span><?php echo formatFileSize($attachment['file_size']); ?></span>
                                                <span class="mx-1">·</span>
                                                <span><?php echo strtoupper($file_ext); ?></span>
                                            </div>
                                        </div>
                                        
                                        <div class="attachment-actions">
                                            <?php if ($attachment['status'] == 'approved' || $question['user_id'] == $_SESSION['user_id'] || (isset($_SESSION['is_admin']) && $_SESSION['is_admin'])): ?>
                                                <a href="<?php echo htmlspecialchars($attachment['upload_path']); ?>" 
                                                download="<?php echo htmlspecialchars($attachment['original_filename']); ?>"
                                                class="btn btn-sm btn-primary" title="Download">
                                                    <i class="fas fa-download"></i>
                                                </a>
                                            <?php endif; ?>
                                            
                                            <?php if ($question['user_id'] == $_SESSION['user_id'] || (isset($_SESSION['is_admin']) && $_SESSION['is_admin'] == true)): ?>
                                                <form method="POST" onsubmit="return confirm('Are you sure you want to delete this attachment?');">
                                                    <input type="hidden" name="attachment_id" value="<?php echo $attachment['id']; ?>">
                                                    <button type="submit" name="delete_attachment" class="btn btn-sm btn-danger" title="Delete">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            
                            <?php if ($question['user_id'] == $_SESSION['user_id'] && count(array_filter($attachments, function($a) { return $a['status'] == 'pending'; })) > 0): ?>
                                <div class="alert alert-info mt-3">
                                    <i class="fas fa-info-circle"></i>
                                    Some of your attachments are still pending review by administrators. 
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="card mt-4">
                    <div class="flex justify-between items-center mb-4">
                        <h2 class="text-xl font-semibold">
                            <?php echo count($answers); ?> Answers
                        </h2>
                    </div>

                    <?php if (count($answers) > 0): ?>
                        <div class="space-y-4">
                            <?php foreach ($answers as $answer): ?>
                                <div class="answer-card" id="answer-<?php echo $answer['id']; ?>">
                                    <div class="flex-1">
                                        <div class="flex justify-between items-center mb-2">
                                            <div class="user-info">
                                                <?php if (!empty($answer['avatar'])): ?>
                                                    <img src="uploads/avatars/<?php echo htmlspecialchars($answer['avatar']); ?>" 
                                                         alt="Avatar" 
                                                         class="user-avatar"
                                                         onerror="this.style.display='none'; this.nextElementSibling.style.display='inline';">
                                                    <i class="fas fa-user-circle fa-lg" style="display: none; margin-right: 0.5rem; color: var(--text-secondary);"></i>
                                                <?php else: ?>
                                                    <i class="fas fa-user-circle fa-lg" style="margin-right: 0.5rem; color: var(--text-secondary);"></i>
                                                <?php endif; ?>
                                                <div class="user-details">
                                                    <span class="username"><?php echo htmlspecialchars($answer['username']); ?></span>
                                                    <!-- 添加讲师标签 -->
                                                    <?php if ($answer['is_admin']): ?>
                                                        <span class="lecturer-badge">
                                                            <i class="fas fa-star"></i>
                                                            Lecturer
                                                        </span>
                                                    <?php endif; ?>
                                                    <span class="answer-date"><?php echo date('M j, Y', strtotime($answer['created_at'])); ?></span>
                                                </div>
                                            </div>
                                            <div class="flex items-center gap-4">
                                                <?php if ($answer['user_id'] == $_SESSION['user_id'] || (isset($_SESSION['is_admin']) && $_SESSION['is_admin'] == true)): ?>
                                                    <form method="POST" class="delete-answer-form" onsubmit="return confirm('Are you sure you want to delete this answer?');">
                                                        <input type="hidden" name="answer_id" value="<?php echo $answer['id']; ?>">
                                                        <button type="submit" name="delete_answer" class="btn btn-danger btn-sm">
                                                            <i class="fas fa-trash"></i>
                                                        </button>
                                                    </form>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <div class="answer-content">
                                            <?php echo nl2br(htmlspecialchars($answer['content'])); ?>
                                        </div>
                                        
                                        <!-- 回复按钮区域 -->
                                        <div class="answer-actions">
                                            <button type="button" class="btn btn-outline btn-sm" onclick="toggleReplyForm(<?php echo $answer['id']; ?>)">
                                                <i class="fas fa-reply"></i> Reply
                                                <?php if (isset($answer['reply_count']) && $answer['reply_count'] > 0): ?>
                                                    <span class="badge badge-sm"><?php echo $answer['reply_count']; ?></span>
                                                <?php endif; ?>
                                            </button>
                                        </div>
                                        
                                        <!-- 回复表单 -->
                                        <div id="reply-form-<?php echo $answer['id']; ?>" class="reply-form" style="display: none;">
                                            <form method="POST" class="space-y-3">
                                                <input type="hidden" name="answer_id" value="<?php echo $answer['id']; ?>">
                                                <div class="form-group">
                                                    <textarea name="reply_content" class="form-control" rows="2" 
                                                            required placeholder="Write your reply..."></textarea>
                                                </div>
                                                <div class="flex justify-end">
                                                    <button type="submit" class="btn btn-primary btn-sm">
                                                        <i class="fas fa-paper-plane"></i> Post Reply
                                                    </button>
                                                </div>
                                            </form>
                                        </div>
                                        
                                        <!-- 显示回复 -->
                                        <?php if (isset($answer_replies[$answer['id']]) && !empty($answer_replies[$answer['id']])): ?>
                                            <div class="replies-container">
                                                <?php foreach ($answer_replies[$answer['id']] as $reply): ?>
                                                    <div class="reply-card">
                                                        <div class="reply-header">
                                                            <div class="flex items-center gap-2">
                                                                <div class="user-info">
                                                                    <?php if (!empty($reply['avatar'])): ?>
                                                                        <img src="uploads/avatars/<?php echo htmlspecialchars($reply['avatar']); ?>" 
                                                                             alt="Avatar" 
                                                                             class="user-avatar-small"
                                                                             onerror="this.style.display='none'; this.nextElementSibling.style.display='inline';">
                                                                        <i class="fas fa-user-circle" style="display: none; margin-right: 0.5rem; color: var(--text-secondary);"></i>
                                                                    <?php else: ?>
                                                                        <i class="fas fa-user-circle" style="margin-right: 0.5rem; color: var(--text-secondary);"></i>
                                                                    <?php endif; ?>
                                                                    <span class="username"><?php echo htmlspecialchars($reply['username']); ?></span>
                                                                    <!-- 添加讲师标签到回复 -->
                                                                    <?php if ($reply['is_admin']): ?>
                                                                        <span class="lecturer-badge">
                                                                            <i class="fas fa-star"></i>
                                                                            Lecturer
                                                                        </span>
                                                                    <?php endif; ?>
                                                                    <span class="reply-date">
                                                                        <?php echo date('M j, Y', strtotime($reply['created_at'])); ?>
                                                                    </span>
                                                                </div>
                                                                
                                                                <?php if ($reply['user_id'] == $_SESSION['user_id'] || (isset($_SESSION['is_admin']) && $_SESSION['is_admin'] == true)): ?>
                                                                    <form method="POST" class="delete-reply-form" onsubmit="return confirm('Delete this reply?');">
                                                                        <input type="hidden" name="reply_id" value="<?php echo $reply['id']; ?>">
                                                                        <button type="submit" name="delete_reply" class="btn-icon text-error">
                                                                            <i class="fas fa-times"></i>
                                                                        </button>
                                                                    </form>
                                                                <?php endif; ?>
                                                            </div>
                                                        </div>
                                                        <div class="reply-content">
                                                            <?php echo nl2br(htmlspecialchars($reply['content'])); ?>
                                                        </div>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-8">
                            <p class="text-secondary mb-4">No answers yet. Be the first to answer!</p>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="card mt-4" id="answer-form">
                    <h3 class="text-xl font-semibold mb-4">Your Answer</h3>
                    <form method="POST" class="space-y-4">
                        <div class="form-group">
                            <label class="form-label" for="content">Write your answer</label>
                            <textarea id="content" name="content" class="form-control" rows="6" 
                                    required placeholder="Share your knowledge..."><?php echo isset($_POST['content']) ? htmlspecialchars($_POST['content']) : ''; ?></textarea>
                        </div>
                        <div class="flex justify-end">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-paper-plane"></i> Post Answer
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </main>
    </div>

   
    <div id="lightbox" class="lightbox">
        <span class="lightbox-close" onclick="closeLightbox()">&times;</span>
        <img id="lightbox-img" class="lightbox-content">
    </div>

    <script>
        // 关注功能实现
        function toggleFollow(questionId) {
            const followBtn = document.getElementById('followBtn');
            const followIcon = document.getElementById('followIcon');
            const followText = document.getElementById('followText');
            const followerCount = document.getElementById('followerCount');
            const followerLabel = document.getElementById('followerLabel');
            
            // 禁用按钮防止重复点击
            followBtn.disabled = true;
            
            // 发送AJAX请求
            fetch('question.php?id=' + questionId, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'toggle_follow=1&question_id=' + questionId + '&ajax=1'
            })
            .then(response => response.json())
            .then(data => {
                if (data.status === 'success') {
                    if (data.action === 'followed') {
                        // 更新关注状态的切换
                        followBtn.className = 'btn btn-primary follow-btn-following';
                        followIcon.className = 'fas fa-heart';
                        followText.textContent = 'Following';
                        const newCount = parseInt(followerCount.textContent) + 1;
                        followerCount.textContent = newCount;
                        followerLabel.textContent = newCount === 1 ? 'follower' : 'followers';
                        
                        // 显示成功消息
                        showNotification('You are now following this question!', 'success');
                    } else {
                        // 更新为未关注状态
                        followBtn.className = 'btn btn-outline';
                        followIcon.className = 'far fa-heart';
                        followText.textContent = 'Follow';
                        const newCount = parseInt(followerCount.textContent) - 1;
                        followerCount.textContent = newCount;
                        followerLabel.textContent = newCount === 1 ? 'follower' : 'followers';
                        
                        // 显示成功消息
                        showNotification('You unfollowed this question.', 'info');
                    }
                } else {
                    showNotification('Operation failed. Please try again.', 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showNotification('Network error. Please try again.', 'error');
            })
            .finally(() => {
                // 重新启用按钮
                followBtn.disabled = false;
            });
        }

        // 显示通知消息的函数
        function showNotification(message, type = 'info') {
            // 创建通知元素
            const notification = document.createElement('div');
            notification.className = `alert alert-${type} notification-popup`;
            notification.innerHTML = `
                <i class="fas fa-${type === 'success' ? 'check-circle' : type === 'error' ? 'exclamation-circle' : 'info-circle'}"></i>
                ${message}
            `;
            
            // 添加到页面
            document.body.appendChild(notification);
            
            // 自动删除
            setTimeout(() => {
                notification.remove();
            }, 3000);
        }

        // 图片预览功能
        document.addEventListener('DOMContentLoaded', function() {
            const previewImages = document.querySelectorAll('.preview-image');
            const lightbox = document.getElementById('lightbox');
            const lightboxImg = document.getElementById('lightbox-img');
            
            previewImages.forEach(function(img) {
                img.addEventListener('click', function() {
                    lightboxImg.src = this.getAttribute('data-src');
                    lightbox.style.display = 'flex';
                });
            });
            
            lightbox.addEventListener('click', function(e) {
                if (e.target === lightbox) {
                    closeLightbox();
                }
            });
        });

        function closeLightbox() {
            document.getElementById('lightbox').style.display = 'none';
        }
        
        // 自动调整文本区域高度
        const textarea = document.querySelector('textarea');
        if (textarea) {
            textarea.addEventListener('input', function() {
                this.style.height = 'auto';
                this.style.height = (this.scrollHeight) + 'px';
            });
        }
        
        // 回复表单切换
        function toggleReplyForm(answerId) {
            const formElement = document.getElementById('reply-form-' + answerId);
            if (formElement.style.display === 'none') {
                formElement.style.display = 'block';
                // 聚焦到文本区域
                formElement.querySelector('textarea').focus();
            } else {
                formElement.style.display = 'none';
            }
        }
        
        // 如果URL中有锚点，滚动到相应位置
        document.addEventListener('DOMContentLoaded', function() {
            if (window.location.hash) {
                const targetElement = document.querySelector(window.location.hash);
                if (targetElement) {
                    setTimeout(function() {
                        targetElement.scrollIntoView({ behavior: 'smooth', block: 'start' });
                        // 高亮显示目标元素
                        targetElement.classList.add('highlight');
                        setTimeout(function() {
                            targetElement.classList.remove('highlight');
                        }, 2000);
                    }, 100);
                }
            }
        });
    </script>
</body>
</html>