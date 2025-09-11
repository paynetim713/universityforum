<?php
session_start();
require_once 'includes/config.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['is_admin']) || !$_SESSION['is_admin']) {
    header("Location: index.php");
    exit();
}
// 处理文件批准/拒绝
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['approve_file'])) {
        $file_id = $_POST['file_id'];
        $stmt = $pdo->prepare("UPDATE question_attachments SET status = 'approved', reviewed_by = ?, review_date = NOW() WHERE id = ?");
        if ($stmt->execute([$_SESSION['user_id'], $file_id])) {
            $success = "File approved successfully!";
        } else {
            $error = "Failed to approve file.";
        }
    } elseif (isset($_POST['reject_file'])) {
        $file_id = $_POST['file_id'];
        
        // 首先获取文件信息以及所属的问题ID
        $stmt = $pdo->prepare("SELECT qa.upload_path, qa.question_id, q.user_id as question_user_id 
                               FROM question_attachments qa
                               JOIN questions q ON qa.question_id = q.id
                               WHERE qa.id = ?");
        $stmt->execute([$file_id]);
        $file = $stmt->fetch();
        
        if ($file) {
            try {
                // 开始事务
                $pdo->beginTransaction();
                
                // 删除物理文件
                if (file_exists($file['upload_path'])) {
                    unlink($file['upload_path']);
                }
                
                // 删除问题的所有附件
                $stmt = $pdo->prepare("DELETE FROM question_attachments WHERE question_id = ?");
                $stmt->execute([$file['question_id']]);
                
                // 删除问题的所有标签关联
                $stmt = $pdo->prepare("DELETE FROM question_tags WHERE question_id = ?");
                $stmt->execute([$file['question_id']]);
                
                // 删除问题的所有回答
                $stmt = $pdo->prepare("DELETE FROM answers WHERE question_id = ?");
                $stmt->execute([$file['question_id']]);
                
                // 最后删除问题本身
                $stmt = $pdo->prepare("DELETE FROM questions WHERE id = ?");
                $stmt->execute([$file['question_id']]);
                
                // 给用户发送通知，告知其问题因为附件不合规而被删除
                $notification_content = "Your question has been removed because the attached file was rejected by administrators.";
                $stmt = $pdo->prepare("INSERT INTO notifications (user_id, type, content, link, is_read, created_at) 
                                      VALUES (?, 'system', ?, 'forum.php', 0, NOW())");
                $stmt->execute([$file['question_user_id'], $notification_content]);
                
                // 提交事务
                $pdo->commit();
                
                $success = "File rejected and its associated question removed successfully!";
                
            } catch (Exception $e) {
                // 如果发生错误，回滚事务
                $pdo->rollBack();
                $error = "Error during rejection process: " . $e->getMessage();
            }
        } else {
            $error = "Failed to reject file: File not found.";
        }
    }
}

// 获取审核文件
$stmt = $pdo->prepare("
    SELECT qa.*, q.title as question_title, u.username 
    FROM question_attachments qa
    JOIN questions q ON qa.question_id = q.id
    JOIN users u ON q.user_id = u.id
    WHERE qa.status = 'pending'
    ORDER BY qa.created_at DESC
");
$stmt->execute();
$pending_files = $stmt->fetchAll();

// 获取最近审核的文件（最近 20 个）
$stmt = $pdo->prepare("
    SELECT qa.*, q.title as question_title, u.username, 
           u2.username as reviewer_name
    FROM question_attachments qa
    JOIN questions q ON qa.question_id = q.id
    JOIN users u ON q.user_id = u.id
    LEFT JOIN users u2 ON qa.reviewed_by = u2.id
    WHERE qa.status != 'pending'
    ORDER BY qa.review_date DESC
    LIMIT 20
");
$stmt->execute();
$moderated_files = $stmt->fetchAll();

// 根据文件扩展名获取文件图标类的函数
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

// 格式化文件大小
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
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>UKM NEXUS - File Moderation</title>
    <link rel="stylesheet" href="style.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .file-card {
            background-color: var(--background-color);
            border: 1px solid var(--border-color);
            border-radius: 0.5rem;
            padding: 1rem;
            margin-bottom: 1rem;
            transition: var(--transition-fast);
        }
        
        .file-card:hover {
            box-shadow: var(--shadow-md);
            transform: translateY(-2px);
        }
        
        .file-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.75rem;
        }
        
        .file-info {
            display: flex;
            gap: 1rem;
            align-items: center;
        }
        
        .file-icon {
            font-size: 2rem;
        }
        
        .file-preview {
            width: 100%;
            max-height: 300px;
            margin-bottom: 1rem;
            border-radius: 0.25rem;
            overflow: hidden;
            border: 1px solid var(--border-color);
            text-align: center; /* Center images */
        }
        
        .file-preview img {
            max-width: 100%;
            max-height: 300px;
            object-fit: contain; /* This preserves aspect ratio */
            display: inline-block; /* Helps with centering */
            cursor: pointer; /* Indicate the image is clickable */
            transition: transform 0.2s ease;
        }
        
        .file-preview img:hover {
            transform: scale(1.02); /* Slight zoom on hover */
        }
        
        .file-actions {
            display: flex;
            gap: 0.5rem;
            justify-content: flex-end;
        }
        
        .tabs {
            display: flex;
            border-bottom: 1px solid var(--border-color);
            margin-bottom: 1rem;
        }
        
        .tab {
            padding: 0.75rem 1.5rem;
            cursor: pointer;
            font-weight: 500;
            border-bottom: 2px solid transparent;
            transition: var(--transition-fast);
        }
        
        .tab.active {
            color: var(--primary-color);
            border-bottom-color: var(--primary-color);
        }
        
        .tab-content {
            display: none;
        }
        
        .tab-content.active {
            display: block;
        }
        
        .status-badge {
            padding: 0.25rem 0.5rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 500;
        }
        
        .status-approved {
            background-color: var(--success-color-light);
            color: var(--success-color);
        }
        
        .status-rejected {
            background-color: var(--error-color-light);
            color: var(--error-color);
        }
        
        .status-pending {
            background-color: var(--warning-color-light);
            color: var(--warning-color);
        }
        
        .file-metadata {
            margin-bottom: 0.75rem;
            color: var(--text-secondary);
            font-size: 0.875rem;
        }
        
        .empty-state {
            text-align: center;
            padding: 3rem 1rem;
            color: var(--text-secondary);
        }
        
        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            color: var(--border-color);
        }
        
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.8);
            overflow: auto;
            opacity: 0;
            transition: opacity 0.3s ease;
        }
        
        .modal.show {
            opacity: 1;
        }
        
        .modal-content {
            position: relative;
            margin: auto;
            padding: 0;
            width: 90%;
            max-width: 1200px;
            height: 90vh;
            display: flex;
            justify-content: center;
            align-items: center;
        }
        
        .modal-content img {
            max-width: 100%;
            max-height: 90vh;
            object-fit: contain;
        }
        
        .close {
            position: absolute;
            top: 15px;
            right: 35px;
            color: #f1f1f1;
            font-size: 40px;
            font-weight: bold;
            transition: 0.3s;
            z-index: 1001;
        }
        
        .close:hover,
        .close:focus {
            color: #bbb;
            text-decoration: none;
            cursor: pointer;
        }
    
        .btn-primary {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 1rem;
            background: linear-gradient(45deg, #6366f1, #8b5cf6);
            color: white;
            border: none;
            border-radius: 0.5rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        
        .btn-primary:hover {
            background: linear-gradient(45deg, #5558d6, #714bd3);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(99, 102, 241, 0.4);
        }
        
        .btn-danger {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 1rem;
            background: linear-gradient(45deg, #ef4444, #f87171);
            color: white;
            border: none;
            border-radius: 0.5rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        
        .btn-danger:hover {
            background: linear-gradient(45deg, #dc2626, #ef4444);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(239, 68, 68, 0.4);
        }
        
        .btn-outline {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 1rem;
            background: var(--background-color);
            color: var(--text-secondary);
            border: 1px solid var(--border-color);
            border-radius: 0.5rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        
        .btn-outline:hover {
            background: var(--background-hover);
            color: var(--text-primary);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }

        .reject-warning {
            color: var(--error-color);
            font-weight: bold;
            margin-top: 0.5rem;
            font-size: 0.875rem;
        }
    </style>
</head>
<body>
    <div class="page-wrapper">
        <?php require_once 'includes/navbar.php'; ?>

        <main class="main-content">
            <div class="container">
                <div class="card">
                    <div class="card-header">
                        <h1 class="text-2xl font-bold mb-2">
                            <i class="fas fa-shield-alt text-primary"></i>
                            File Moderation
                        </h1>
                        <p class="text-secondary">
                            Review and approve uploaded files to ensure they meet community guidelines.
                        </p>
                    </div>

                    <?php if (isset($success)): ?>
                        <div class="alert alert-success">
                            <i class="fas fa-check-circle"></i>
                            <?php echo $success; ?>
                        </div>
                    <?php endif; ?>
                    <?php if (isset($error)): ?>
                        <div class="alert alert-error">
                            <i class="fas fa-exclamation-circle"></i>
                            <?php echo $error; ?>
                        </div>
                    <?php endif; ?>

                    <div class="tabs">
                        <div class="tab active" data-tab="pending">
                            Pending Review 
                            <?php if (count($pending_files) > 0): ?>
                                <span class="badge badge-primary"><?php echo count($pending_files); ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="tab" data-tab="moderated">
                            Recently Moderated
                        </div>
                    </div>

                    <div class="tab-content active" id="pending-tab">
                        <?php if (count($pending_files) > 0): ?>
                            <?php foreach ($pending_files as $file): ?>
                                <?php 
                                    $file_ext = pathinfo($file['original_filename'], PATHINFO_EXTENSION);
                                    $is_image = in_array(strtolower($file_ext), ['jpg', 'jpeg', 'png', 'gif']);
                                    $icon_class = getFileIconClass($file_ext);
                                ?>
                                <div class="file-card">
                                    <div class="file-header">
                                        <div class="file-info">
                                            <div class="file-icon">
                                                <i class="<?php echo $icon_class; ?>"></i>
                                            </div>
                                            <div>
                                                <h3 class="font-semibold"><?php echo htmlspecialchars($file['original_filename']); ?></h3>
                                                <div class="text-secondary text-sm">
                                                    <?php echo formatFileSize($file['file_size']); ?> • 
                                                    <?php echo strtoupper($file_ext); ?> • 
                                                    Uploaded by <?php echo htmlspecialchars($file['username']); ?>
                                                </div>
                                            </div>
                                        </div>
                                        <span class="status-badge status-pending">Pending Review</span>
                                    </div>
                                    
                                    <div class="file-metadata">
                                        <strong>Question:</strong> 
                                        <a href="question.php?id=<?php echo $file['question_id']; ?>" target="_blank">
                                            <?php echo htmlspecialchars($file['question_title']); ?>
                                        </a>
                                    </div>
                                    
                                    <?php if ($is_image): ?>
                                        <div class="file-preview">
                                            <img src="<?php echo htmlspecialchars($file['upload_path']); ?>" 
                                                alt="<?php echo htmlspecialchars($file['original_filename']); ?>"
                                                class="preview-image"
                                                data-id="<?php echo $file['id']; ?>"
                                                onclick="openModal(this.src)">
                                        </div>
                                    <?php else: ?>
                                        <div class="mt-2 mb-4">
                                            <a href="<?php echo htmlspecialchars($file['upload_path']); ?>" 
                                               class="btn btn-outline" target="_blank">
                                                <i class="fas fa-external-link-alt"></i> 
                                                Preview File
                                            </a>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <div class="file-actions">
                                        <form method="POST" onsubmit="return confirmReject();">
                                            <input type="hidden" name="file_id" value="<?php echo $file['id']; ?>">
                                            <button type="submit" name="reject_file" class="btn btn-danger">
                                                <i class="fas fa-times"></i> Reject & Delete Post
                                            </button>
                                        </form>
                                        <form method="POST">
                                            <input type="hidden" name="file_id" value="<?php echo $file['id']; ?>">
                                            <button type="submit" name="approve_file" class="btn btn-primary">
                                                <i class="fas fa-check"></i> Approve
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="fas fa-check-circle"></i>
                                <p>All caught up! No files waiting for review.</p>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="tab-content" id="moderated-tab">
                        <?php if (count($moderated_files) > 0): ?>
                            <?php foreach ($moderated_files as $file): ?>
                                <?php 
                                    $file_ext = pathinfo($file['original_filename'], PATHINFO_EXTENSION);
                                    $is_image = in_array(strtolower($file_ext), ['jpg', 'jpeg', 'png', 'gif']);
                                    $icon_class = getFileIconClass($file_ext);
                                ?>
                                <div class="file-card">
                                    <div class="file-header">
                                        <div class="file-info">
                                            <div class="file-icon">
                                                <i class="<?php echo $icon_class; ?>"></i>
                                            </div>
                                            <div>
                                                <h3 class="font-semibold"><?php echo htmlspecialchars($file['original_filename']); ?></h3>
                                                <div class="text-secondary text-sm">
                                                    <?php echo formatFileSize($file['file_size']); ?> • 
                                                    <?php echo strtoupper($file_ext); ?> • 
                                                    Uploaded by <?php echo htmlspecialchars($file['username']); ?>
                                                </div>
                                            </div>
                                        </div>
                                        <span class="status-badge status-<?php echo $file['status']; ?>">
                                            <?php echo ucfirst($file['status']); ?>
                                        </span>
                                    </div>
                                    
                                    <div class="file-metadata">
                                        <strong>Question:</strong> 
                                        <?php if ($file['status'] === 'approved'): ?>
                                            <a href="question.php?id=<?php echo $file['question_id']; ?>" target="_blank">
                                                <?php echo htmlspecialchars($file['question_title']); ?>
                                            </a>
                                        <?php else: ?>
                                            <span class="text-error">Question deleted</span>
                                        <?php endif; ?>
                                        <br>
                                        <strong>Reviewed by:</strong> <?php echo htmlspecialchars($file['reviewer_name']); ?> 
                                        on <?php echo date('M j, Y g:i a', strtotime($file['review_date'])); ?>
                                    </div>
                                    
                                    <?php if ($is_image && $file['status'] === 'approved'): ?>
                                        <div class="file-preview">
                                            <img src="<?php echo htmlspecialchars($file['upload_path']); ?>" 
                                                alt="<?php echo htmlspecialchars($file['original_filename']); ?>"
                                                onclick="openModal(this.src)">
                                        </div>
                                    <?php elseif ($file['status'] === 'approved'): ?>
                                        <div class="mt-2 mb-4">
                                            <a href="<?php echo htmlspecialchars($file['upload_path']); ?>" 
                                               class="btn btn-outline" target="_blank">
                                                <i class="fas fa-external-link-alt"></i> 
                                                View File
                                            </a>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="fas fa-history"></i>
                                <p>No moderated files yet.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <div id="imageModal" class="modal">
        <span class="close" onclick="closeModal()">&times;</span>
        <div class="modal-content">
            <img id="modalImage" src="">
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const tabs = document.querySelectorAll('.tab');
            const tabContents = document.querySelectorAll('.tab-content');
            
            tabs.forEach(tab => {
                tab.addEventListener('click', function() {
                    tabs.forEach(t => t.classList.remove('active'));
                    tabContents.forEach(content => content.classList.remove('active'));
                    this.classList.add('active');
                  
                    const tabName = this.getAttribute('data-tab');
                    document.getElementById(tabName + '-tab').classList.add('active');
                });
            });
        });
        
        function openModal(src) {
            const modal = document.getElementById('imageModal');
            const modalImg = document.getElementById('modalImage');
            
            modal.style.display = "flex";
            modalImg.src = src;
           
            setTimeout(() => {
                modal.classList.add('show');
            }, 10);
        }
        
        function closeModal() {
            const modal = document.getElementById('imageModal');
         
            modal.classList.remove('show');
            
            setTimeout(() => {
                modal.style.display = "none";
            }, 300);
        }
        
        window.onclick = function(event) {
            const modal = document.getElementById('imageModal');
            if (event.target == modal) {
                closeModal();
            }
        }
      
        document.addEventListener('keydown', function(event) {
            if (event.key === "Escape") {
                closeModal();
            }
        });

        // 拒绝文件确认
        function confirmReject() {
            return confirm('警告: 拒绝此文件将删除整个问题及其所有回答。确定要拒绝吗？\n\nWarning: Rejecting this file will delete the entire question and all its answers. Are you sure?');
        }
    </script>
</body>
</html>