<?php
session_start();
require_once 'includes/config.php';
require_once 'SensitiveWordFilter.php';  // 引入你的独立敏感词过滤类

// 设置性能优化
ini_set('memory_limit', '256M'); 
set_time_limit(120); 

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$error = '';
$success = '';

// 确保上传目录存在
$upload_dir = 'uploads/questions/';
if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0755, true);
}

// 检查并添加数据库字段
try {
    $check_stmt = $pdo->query("SHOW COLUMNS FROM question_attachments LIKE 'status'");
    if ($check_stmt->rowCount() == 0) {
        $pdo->exec("ALTER TABLE question_attachments 
                   ADD COLUMN status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
                   ADD COLUMN reviewed_by INT NULL,
                   ADD COLUMN review_date DATETIME NULL");
    }
} catch (PDOException $e) {
    error_log('Error checking or adding status field: ' . $e->getMessage());
}

try {
    $check_stmt = $pdo->query("SHOW COLUMNS FROM questions LIKE 'preferred_responder'");
    if ($check_stmt->rowCount() == 0) {
        $pdo->exec("ALTER TABLE questions 
                   ADD COLUMN preferred_responder ENUM('any', 'lecturer', 'student') NOT NULL DEFAULT 'any'");
    }
} catch (PDOException $e) {
    error_log('Error checking or adding preferred_responder field: ' . $e->getMessage());
}

// 检查并添加 faculty_id 字段
try {
    $check_stmt = $pdo->query("SHOW COLUMNS FROM questions LIKE 'faculty_id'");
    if ($check_stmt->rowCount() == 0) {
        $pdo->exec("ALTER TABLE questions ADD COLUMN faculty_id INT DEFAULT NULL");
        $pdo->exec("ALTER TABLE questions ADD FOREIGN KEY (faculty_id) REFERENCES faculties(id)");
    }
} catch (PDOException $e) {
    error_log('Error checking or adding faculty_id field: ' . $e->getMessage());
}

// 获取标签和学院
$stmt = $pdo->query("SELECT * FROM tags ORDER BY name");
$tags = $stmt->fetchAll();

$stmt = $pdo->query("SELECT * FROM faculties ORDER BY name");
$all_faculties = $stmt->fetchAll();

// 定义允许的文件类型和最大文件大小
$allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'zip', 'rar', 'txt'];
$max_file_size = 5 * 1024 * 1024; 

// MIME类型映射
$mime_map = [
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png' => 'image/png',
    'gif' => 'image/gif',
    'pdf' => 'application/pdf',
    'doc' => 'application/msword',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'xls' => 'application/vnd.ms-excel',
    'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'zip' => 'application/zip',
    'rar' => 'application/x-rar-compressed',
    'txt' => 'text/plain'
];

// 处理问题提交
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit'])) {
    $title = trim($_POST['title']);
    $content = trim($_POST['content']);
    $preferred_responder = isset($_POST['preferred_responder']) ? $_POST['preferred_responder'] : 'any';
    $faculty_id = isset($_POST['faculty']) && !empty($_POST['faculty']) ? intval($_POST['faculty']) : null;
    
    // 保存原始内容检测敏感词
    $original_title = $title;
    $original_content = $content;

    $filter = new SensitiveWordFilter();
    
    // 加载敏感词从文本
    $filter->loadFromFile('CensorWords.txt');
    
    // 使用改进的智能过滤方法
    $filtered_title = $filter->smartFilter($title);
    $filtered_content = $filter->smartFilter($content);
    
    // 再次检查是否含有敏感词
    $has_sensitive_words = ($original_title !== $filtered_title || $original_content !== $filtered_content);

    // 使用过滤的内容
    $title = $filtered_title;
    $content = $filtered_content;

    $selected_tags = isset($_POST['tags']) ? $_POST['tags'] : [];

    if (empty($title) || empty($content) || empty($selected_tags)) {
        $error = "Title, content, and tags are required fields";
    } else {
        try {
            $pdo->beginTransaction();

            // 插入问题，包含faculty_id
            $stmt = $pdo->prepare("INSERT INTO questions (user_id, title, content, preferred_responder, faculty_id) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$_SESSION['user_id'], $title, $content, $preferred_responder, $faculty_id]);
            $question_id = $pdo->lastInsertId();

            // 插入标签关联
            $stmt = $pdo->prepare("INSERT INTO question_tags (question_id, tag_id) VALUES (?, ?)");
            foreach ($selected_tags as $tag_id) {
                $stmt->execute([$question_id, $tag_id]);
            }
            
            // 处理文件上传 
            $has_attachments = false;
            if (!empty($_FILES['attachments']['name'][0])) {
                $has_attachments = true;
                $attachment_stmt = $pdo->prepare("INSERT INTO question_attachments (question_id, filename, original_filename, file_type, file_size, upload_path, status) VALUES (?, ?, ?, ?, ?, ?, 'pending')");
                
                foreach ($_FILES['attachments']['name'] as $key => $filename) {
                    if (empty($filename)) continue;
                    
                    $file_tmp = $_FILES['attachments']['tmp_name'][$key];
                    $file_size = $_FILES['attachments']['size'][$key];
                    $file_error = $_FILES['attachments']['error'][$key];
                    
                    if ($file_error !== UPLOAD_ERR_OK) {
                        continue; 
                    }
                    
                    if ($file_size > $max_file_size) {
                        continue; 
                    }
                    
                    $file_ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                    if (!in_array($file_ext, $allowed_extensions)) {
                        continue; 
                    }
                    
                    $new_filename = uniqid('attachment_') . '_' . time() . '.' . $file_ext;
                    $file_destination = $upload_dir . $new_filename;
                    
                    if (move_uploaded_file($file_tmp, $file_destination)) {
                        $mime_type = 'application/octet-stream';
                        if (function_exists('mime_content_type')) {
                            $mime_type = mime_content_type($file_destination);
                        } elseif (isset($mime_map[$file_ext])) {
                            $mime_type = $mime_map[$file_ext];
                        }
                        
                        $attachment_stmt->execute([
                            $question_id,
                            $new_filename,
                            $filename,
                            $mime_type,
                            $file_size,
                            $file_destination
                        ]);
                        
                        // 给管理员发送通知，告知有新文件需要审核
                        $admin_notification = "A new file has been uploaded and requires review: " . htmlspecialchars(substr($filename, 0, 30) . (strlen($filename) > 30 ? "..." : ""));
                        $admin_link = "file_moderation.php";

                        // 获取所有管理员ID
                        $admin_stmt = $pdo->prepare("SELECT id FROM users WHERE is_admin = 1");
                        $admin_stmt->execute();
                        $admins = $admin_stmt->fetchAll(PDO::FETCH_COLUMN);

                        // 给所有管理员发送通知 - 批量处理
                        if (!empty($admins)) {
                            $notify_values = [];
                            $notify_params = [];
                            $i = 0;
                            
                            foreach ($admins as $admin_id) {
                                $notify_values[] = "(?, 'file_moderation', ?, ?, 0, NOW())";
                                $notify_params[] = $admin_id;
                                $notify_params[] = $admin_notification;
                                $notify_params[] = $admin_link;
                                $i++;
                                
                                // 每50个管理员执行一次批量插入
                                if ($i % 50 == 0 || $i == count($admins)) {
                                    $notify_sql = "INSERT INTO notifications (user_id, type, content, link, is_read, created_at) VALUES " . implode(', ', $notify_values);
                                    $notify_stmt = $pdo->prepare($notify_sql);
                                    $notify_stmt->execute($notify_params);
                                    
                                    $notify_values = [];
                                    $notify_params = [];
                                }
                            }
                        }
                    }
                }
            }
            
            // 如果检测到敏感词，立即通知管理员
            if ($has_sensitive_words) {
                // 获取所有管理员用户 ID
                $stmt = $pdo->prepare("SELECT id FROM users WHERE is_admin = 1");
                $stmt->execute();
                $admins = $stmt->fetchAll(PDO::FETCH_COLUMN);
                
                if (!empty($admins)) {
                    // 判断敏感词出现的位置
                    $location = [];
                    if ($original_title !== $filtered_title) $location[] = "title";
                    if ($original_content !== $filtered_content) $location[] = "content";
                    
                    $sensitive_location = count($location) > 0 ? implode(" and ", $location) : "unknown location";
                    
                    // 创建通知内容
                    $notification_content = "User ".$_SESSION['username']." posted a question \"".mb_substr($filtered_title, 0, 30).(mb_strlen($filtered_title) > 30 ? "..." : "")."\" containing sensitive words in ".$sensitive_location;
                    
                    // 批量处理管理员通知
                    $notify_values = [];
                    $notify_params = [];
                    $i = 0;
                    
                    foreach ($admins as $admin_id) {
                        $notify_values[] = "(?, 'sensitive_content', ?, ?, 0, NOW())";
                        $notify_params[] = $admin_id;
                        $notify_params[] = $notification_content;
                        $notify_params[] = "question.php?id=".$question_id;
                        $i++;
                        
                        // 每50个管理员执行一次批量插入
                        if ($i % 50 == 0 || $i == count($admins)) {
                            $notify_sql = "INSERT INTO notifications (user_id, type, content, link, is_read, created_at) VALUES " . implode(', ', $notify_values);
                            $notify_stmt = $pdo->prepare($notify_sql);
                            $notify_stmt->execute($notify_params);
                            
                            $notify_values = [];
                            $notify_params = [];
                        }
                    }
                    
                    // 向用户显示的消息
                    $success = "Your question has been posted, but the system detected potentially sensitive content. Administrators will review it.";
                }
            }

            // 如果用户选择了讲师回答，则通知所有讲师
            if ($preferred_responder === 'lecturer') {
                $stmt = $pdo->prepare("SELECT id FROM users WHERE is_admin = 1");  // 用管理员代替讲师
                $stmt->execute();
                $lecturers = $stmt->fetchAll(PDO::FETCH_COLUMN);
                
                if (!empty($lecturers)) {
                    $notification_content = "A student is specifically requesting lecturer feedback: \"".mb_substr($filtered_title, 0, 30).(mb_strlen($filtered_title) > 30 ? "..." : "")."\"";
                    
                    // 批量处理讲师通知
                    $notify_values = [];
                    $notify_params = [];
                    $i = 0;
                    
                    foreach ($lecturers as $lecturer_id) {
                        $notify_values[] = "(?, 'lecturer_request', ?, ?, 0, NOW())";
                        $notify_params[] = $lecturer_id;
                        $notify_params[] = $notification_content;
                        $notify_params[] = "question.php?id=".$question_id;
                        $i++;
                        
                        if ($i % 50 == 0 || $i == count($lecturers)) {
                            $notify_sql = "INSERT INTO notifications (user_id, type, content, link, is_read, created_at) VALUES " . implode(', ', $notify_values);
                            $notify_stmt = $pdo->prepare($notify_sql);
                            $notify_stmt->execute($notify_params);
                            
                            $notify_values = [];
                            $notify_params = [];
                        }
                    }
                }
            }

            $pdo->commit();
            
            // 如果有上传附件，告知用户审核信息
            if ($has_attachments) {
                if (empty($success)) {
                    $success = "Your question has been posted! Note: Uploaded files need to be reviewed by an administrator before they are visible to others.";
                } else {
                    $success .= " Uploaded files need to be reviewed by an administrator before they are visible to others.";
                }
            } else if (empty($success)) {
                $success = "Your question has been posted successfully!";
            }
            
            // 无论是否包含敏感词，都重定向到问题页面
            header("Location: question.php?id=" . $question_id);
            exit();
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = "Failed to post, please try again. Error: " . $e->getMessage();
        }
    }
}
?>


<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>UKM NEXUS - Post a New Question</title>
    <link rel="stylesheet" href="style.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <style>
/* 基本容器样式 */
.select2-container {
    width: 100% !important;
    box-sizing: border-box !important;
}

/* 选择器样式 */
.select2-container--default .select2-selection--multiple {
    border: 1px solid var(--border-color);
    border-radius: 0.5rem;
    padding: 0.25rem;
    min-height: 42px;
    width: 100% !important;
    box-sizing: border-box !important;
}

.select2-container--default.select2-container--focus .select2-selection--multiple {
    border-color: var(--primary-color);
    box-shadow: 0 0 0 2px var(--primary-color-light);
}

/* 标签项样式 - 解决重合问题 */
.select2-container--default .select2-selection--multiple .select2-selection__choice {
    background-color: var(--primary-color-light);
    border: none;
    border-radius: 0.25rem;
    padding: 0.25rem 0.5rem 0.25rem 0.25rem;
    color: var(--primary-color);
    margin: 0.25rem;
    display: flex !important;
    align-items: center !important;
}

/* 删除按钮样式 - 确保正确放置 */
.select2-container--default .select2-selection--multiple .select2-selection__choice__remove {
    color: var(--primary-color);
    margin-right: 0.5rem !important;
    margin-left: 0 !important;
    padding-right: 0.25rem !important;
    position: relative !important;
    float: left !important;
}

/* 标签文本样式 - 确保可见 */
.select2-container--default .select2-selection--multiple .select2-selection__choice__display {
    color: var(--primary-color) !important;
    padding-left: 0.25rem !important;
    position: relative !important;
    float: right !important;
}

/* 选择项渲染容器 */
.select2-container--default .select2-selection--multiple .select2-selection__rendered {
    display: flex;
    flex-wrap: wrap;
    padding: 0.25rem;
    box-sizing: border-box !important;
}

/* 隐藏搜索框 */
.select2-search--inline {
    display: none !important;
}

/* 下拉菜单样式 - 防止页面变宽 */
.select2-dropdown {
    border-color: var(--border-color);
    border-radius: 0.5rem;
    box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
    width: 10% !important;
    max-width: 100% !important;
    box-sizing: border-box !important;
    overflow: hidden !important;
}

/* 下拉选项容器 */
.select2-results {
    max-width: 100% !important;
}

/* 选项列表 */
.select2-results__options {
    max-width: 100% !important;
}

/* 高亮选项样式 */
.select2-container--default .select2-results__option--highlighted[aria-selected] {
    background-color: var(--primary-color);
    color: white;
}

/* 选项样式 - 允许文本换行 */
.select2-container--default .select2-results__option {
    padding: 8px 12px;
    white-space: normal !important;
    word-wrap: break-word !important;
}

/* 防止页面水平滚动 */
.form-group {
    overflow: hidden !important;
    width: 100% !important;
    box-sizing: border-box !important;
}

/* 文件上传样式 */
.file-upload-container {
    border: 2px dashed var(--border-color);
    padding: 1.5rem;
    border-radius: 0.5rem;
    text-align: center;
    margin-top: 0.5rem;
    cursor: pointer;
    transition: all 0.3s ease;
}

.file-upload-container:hover {
    border-color: var(--primary-color);
    background-color: var(--primary-color-light);
}

.file-preview {
    display: flex;
    flex-wrap: wrap;
    gap: 0.75rem;
    margin-top: 1rem;
}

.file-item {
    display: flex;
    align-items: center;
    padding: 0.5rem 0.75rem;
    background-color: #f8f9fa;
    border-radius: 0.375rem;
    font-size: 0.875rem;
    border: 1px solid var(--border-color);
}

.file-item i {
    margin-right: 0.75rem;
    font-size: 1.25rem;
}

.file-item .file-name {
    flex: 1;
}

.file-item .remove-file {
    margin-left: 0.75rem;
    color: #dc3545;
    cursor: pointer;
    padding: 0.25rem;
}

.file-item .remove-file:hover {
    background-color: rgba(220, 53, 69, 0.1);
    border-radius: 50%;
}

/* 审核状态通知 */
.file-moderation-notice {
    margin-top: 1rem;
    padding: 0.75rem;
    background-color: var(--primary-color-light);
    border-radius: 0.5rem;
    border-left: 4px solid var(--primary-color);
    font-size: 0.875rem;
}

.file-moderation-notice i {
    color: var(--primary-color);
    margin-right: 0.5rem;
}

/* 回答者偏好样式 */
.responder-options {
    display: flex;
    gap: 1rem;
    margin-top: 0.5rem;
}

.responder-option {
    flex: 1;
    border: 1px solid var(--border-color);
    border-radius: 0.5rem;
    padding: 1rem;
    cursor: pointer;
    transition: all 0.2s ease;
    position: relative;
}

.responder-option:hover {
    border-color: var(--primary-color);
    background-color: var(--primary-color-light);
    transform: translateY(-2px);
}

.responder-option.selected {
    border-color: var(--primary-color);
    background-color: var(--primary-color-light);
    box-shadow: 0 0 0 1px var(--primary-color);
}

.responder-option input[type="radio"] {
    position: absolute;
    opacity: 0;
    width: 0;
    height: 0;
}

.responder-option label {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 0.5rem;
    width: 100%;
    height: 100%;
    cursor: pointer;
}

.responder-option i {
    font-size: 1.5rem;
    color: var(--primary-color);
}

.responder-option .option-title {
    font-weight: 600;
    color: var(--text-primary);
}

.responder-option .option-description {
    font-size: 0.875rem;
    color: var(--text-secondary);
    text-align: center;
}

/* 学院选择器样式 */
.faculty-select-container {
    margin-bottom: 1.5rem;
}

.faculty-select {
    width: 100%;
    padding: 0.875rem 1rem;
    border: 1px solid var(--border-color);
    border-radius: 0.5rem;
    background-color: var(--background-color);
    color: var(--text-primary);
    font-size: 0.9375rem;
    transition: var(--transition-fast);
}

.faculty-select:focus {
    outline: none;
    border-color: var(--primary-color);
    box-shadow: 0 0 0 2px var(--primary-color-light);
}

.faculty-select option {
    background-color: var(--background-color);
    color: var(--text-primary);
    padding: 0.5rem;
}

/* 标签建议区域 */
.tag-suggestions {
    display: none;
    position: absolute;
    top: 100%;
    left: 0;
    right: 0;
    background-color: var(--background-color);
    border: 1px solid var(--border-color);
    border-radius: 0.5rem;
    margin-top: 0.5rem;
    padding: 0.5rem;
    box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
    z-index: 50;
    max-height: 200px;
    overflow-y: auto;
}

.tag-suggestions.show {
    display: block;
}

.tag-suggestion {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.5rem;
    cursor: pointer;
    border-radius: 0.25rem;
    transition: var(--transition-fast);
}

.tag-suggestion:hover {
    background-color: var(--primary-color-light);
}

.tag-suggestion i {
    color: var(--primary-color);
    font-size: 0.875rem;
}

.tag-count {
    margin-left: auto;
    font-size: 0.75rem;
    color: var(--text-secondary);
    background-color: var(--background-secondary);
    padding: 0.25rem 0.5rem;
    border-radius: 9999px;
}
</style>

</head>
<body>
    <div class="page-wrapper">
        <?php require_once 'includes/navbar.php'; ?>

        <main class="main-content">
            <div class="container">
                <?php if ($error): ?>
                    <div class="alert alert-error">
                        <i class="fas fa-exclamation-circle"></i>
                        <?php echo $error; ?>
                    </div>
                <?php endif; ?>
                <?php if ($success): ?>
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle"></i>
                        <?php echo $success; ?>
                    </div>
                <?php endif; ?>

                <div class="card">
                    <div class="card-header">
                        <h1 class="text-2xl font-bold mb-2">
                            <i class="fas fa-pen-fancy text-primary"></i>
                            Post a New Question
                        </h1>
                        <p class="text-secondary">
                            Share your question and let the community help you find an answer. 
                            A clear description and relevant tags will help you get a response faster.
                        </p>
                    </div>

                    <form method="POST" action="" class="space-y-6" enctype="multipart/form-data">
                        <div class="form-group">
                            <label for="title" class="form-label">
                                <i class="fas fa-heading text-primary"></i>
                                Question Title
                            </label>
                            <input type="text" id="title" name="title" class="form-control" required 
                                   maxlength="255" 
                                   placeholder="Describe your question in one sentence, e.g. 'How to apply for a major transfer?'"
                                   value="<?php echo isset($_POST['title']) ? htmlspecialchars($_POST['title']) : ''; ?>">
                            <small class="form-help">
                                <i class="fas fa-info-circle"></i>
                                A good title should be concise and contain the key information of your question
                            </small>
                        </div>

                        <!-- 学院选择器 -->
                        <div class="form-group faculty-select-container">
                            <label for="faculty" class="form-label">
                                <i class="fas fa-university text-primary"></i>
                                Faculty (Optional)
                            </label>
                            <select id="faculty" name="faculty" class="faculty-select">
                                <option value="">Select Faculty (General Question)</option>
                                <?php foreach ($all_faculties as $faculty): ?>
                                    <option value="<?php echo $faculty['id']; ?>"
                                        <?php echo isset($_POST['faculty']) && $_POST['faculty'] == $faculty['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($faculty['code'] . ' - ' . $faculty['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <small class="form-help">
                                <i class="fas fa-info-circle"></i>
                                Select your faculty to help route your question to the right audience
                            </small>
                        </div>

                        <div class="form-group">
                            <label for="tags" class="form-label">
                                <i class="fas fa-tags text-primary"></i>
                                Question Tags
                            </label>
                            <div style="position: relative;">
                                <select id="tags" name="tags[]" multiple class="select2-tags" required>
                                    <?php foreach ($tags as $tag): ?>
                                        <option value="<?php echo $tag['id']; ?>"
                                            <?php echo isset($_POST['tags']) && in_array($tag['id'], $_POST['tags']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($tag['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                
                                <!-- 标签建议区域 -->
                                <div id="tagSuggestions" class="tag-suggestions">
                                    <!-- 动态加载的标签建议会显示在这里 -->
                                </div>
                            </div>
                            <small class="form-help">
                                <i class="fas fa-lightbulb"></i>
                                Choose 1-5 relevant tags to help the right people see your question
                            </small>
                        </div>

                        <div class="form-group">
                            <label class="form-label">
                                <i class="fas fa-user-graduate text-primary"></i>
                                Who do you want to answer your question?
                            </label>
                            <div class="responder-options">
                                <div class="responder-option" data-value="any">
                                    <input type="radio" id="responder-any" name="preferred_responder" value="any" checked>
                                    <label for="responder-any">
                                        <i class="fas fa-users"></i>
                                        <span class="option-title">Anyone</span>
                                        <span class="option-description">Allow anyone from the community to respond</span>
                                    </label>
                                </div>
                                <div class="responder-option" data-value="lecturer">
                                    <input type="radio" id="responder-lecturer" name="preferred_responder" value="lecturer">
                                    <label for="responder-lecturer">
                                        <i class="fas fa-chalkboard-teacher"></i>
                                        <span class="option-title">Lecturer</span>
                                        <span class="option-description">Request a response from a lecturer or professor</span>
                                    </label>
                                </div>
                                <div class="responder-option" data-value="student">
                                    <input type="radio" id="responder-student" name="preferred_responder" value="student">
                                    <label for="responder-student">
                                        <i class="fas fa-user-graduate"></i>
                                        <span class="option-title">Student</span>
                                        <span class="option-description">Request a response from fellow students</span>
                                    </label>
                                </div>
                            </div>
                            <small class="form-help">
                                <i class="fas fa-info-circle"></i>
                                Select who you'd prefer to answer your question. This helps direct your question to the right audience.
                            </small>
                        </div>

                        <div class="form-group">
                            <label for="content" class="form-label">
                                <i class="fas fa-align-left text-primary"></i>
                                Question Details
                            </label>
                            <textarea id="content" name="content" class="form-control" required rows="8"
                                      placeholder="Please describe your question in detail:

1. What is the specific situation?
2. What methods have you tried so far?
3. What difficulties are you facing?
4. What kind of answer are you expecting?
"><?php echo isset($_POST['content']) ? htmlspecialchars($_POST['content']) : ''; ?></textarea>
                            <small class="form-help">
                                <i class="fas fa-check-circle"></i>
                                A detailed description will help others provide a more accurate answer to your question
                            </small>
                        </div>

                        <!-- 文件上传部分 -->
                        <div class="form-group">
                            <label class="form-label">
                                <i class="fas fa-paperclip text-primary"></i>
                                Attachments (Optional)
                            </label>
                            <div class="file-upload-container" id="upload-container">
                                <i class="fas fa-cloud-upload-alt fa-2x text-primary mb-2"></i>
                                <p>Click here to upload files or drag & drop</p>
                                <p class="text-secondary text-sm mt-1">
                                    Supported: JPG, PNG, GIF, PDF, DOC, DOCX, XLS, XLSX, ZIP, RAR, TXT (Max: 5MB)
                                </p>
                            </div>
                            <!-- 文件输入元素放在上传容器外部，但仍保持隐藏 -->
                            <input type="file" name="attachments[]" id="file-upload" multiple 
                                   accept=".jpg,.jpeg,.png,.gif,.pdf,.doc,.docx,.xls,.xlsx,.zip,.rar,.txt" 
                                   style="display: none;">
                            <div class="file-preview" id="file-preview"></div>
                            <small class="form-help">
                                <i class="fas fa-info-circle"></i>
                                Add images, documents, or other files to better illustrate your question
                            </small>
                            
                            <!-- 添加文件审核通知 -->
                            <div class="file-moderation-notice">
                                <i class="fas fa-info-circle"></i>
                                <span>Uploaded files will need to be reviewed by administrators before they are visible to other users.</span>
                            </div>
                        </div>

                        <div class="flex items-center justify-end gap-4">
                            <a href="forum.php" class="btn btn-secondary">
                                <i class="fas fa-times"></i>
                                Cancel
                            </a>
                            <button type="submit" name="submit" class="btn btn-primary">
                                <i class="fas fa-paper-plane"></i>
                                Post Question
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </main>
    </div>

    <script>
    $(document).ready(function() {
        // 初始化Select2
        $('.select2-tags').select2({
            placeholder: 'Choose relevant tags (required)',
            maximumSelectionLength: 5,
            width: '100%',
            language: {
                maximumSelected: function (e) {
                    return 'You can only select ' + e.maximum + ' tags';
                }
            },
            // 禁用搜索功能
            minimumResultsForSearch: Infinity
        });
        
        // 修复下拉菜单宽度问题
        $(window).on('resize', function() {
            $('.select2-container').css('width', '100%');
        });
        
        // 打开下拉菜单时修复宽度
        $(document).on('select2:open', function() {
            setTimeout(function() {
                // 确保下拉菜单宽度不超过容器
                var containerWidth = $('.select2-container').width();
                $('.select2-dropdown').css({
                    'width': containerWidth + 'px',
                    'max-width': '100%'
                });
            }, 0);
        });

        // 学院选择变化时动态更新标签建议
        $('#faculty').on('change', function() {
            const facultyId = this.value;
            loadTagSuggestions(facultyId);
        });

        // 加载标签建议
        function loadTagSuggestions(facultyId = '') {
            fetch(`get_faculty_tags.php?faculty_id=${facultyId}`)
                .then(response => response.json())
                .then(tags => {
                    const tagSuggestions = document.getElementById('tagSuggestions');
                    tagSuggestions.innerHTML = '';
                    
                    if (tags && tags.length > 0) {
                        tags.forEach(tag => {
                            const tagElement = document.createElement('div');
                            tagElement.className = 'tag-suggestion';
                            tagElement.setAttribute('data-tag-id', tag.id || '');
                            tagElement.setAttribute('data-tag-name', tag.name);
                            tagElement.innerHTML = `
                                <i class="fas fa-tag"></i>
                                ${tag.name}
                                <span class="tag-count">${tag.count || 0}</span>
                            `;
                            
                            // 添加点击事件
                            tagElement.addEventListener('click', function() {
                                const tagId = this.getAttribute('data-tag-id');
                                const tagName = this.getAttribute('data-tag-name');
                                
                                if (tagId) {
                                    // 检查是否已经选择了这个标签
                                    const currentValues = $('#tags').val() || [];
                                    if (!currentValues.includes(tagId)) {
                                        // 添加到选择中
                                        currentValues.push(tagId);
                                        $('#tags').val(currentValues).trigger('change');
                                    }
                                }
                            });
                            
                            tagSuggestions.appendChild(tagElement);
                        });
                    }
                })
                .catch(error => {
                    console.error('Error loading tag suggestions:', error);
                });
        }

        // 初始加载标签建议
        loadTagSuggestions();

        // Auto-adjust textarea height
        $('textarea').on('input', function() {
            this.style.height = 'auto';
            this.style.height = (this.scrollHeight) + 'px';
        });

        // 文件上传功能
        console.log("Document ready, initializing file upload...");
        
        // 点击上传容器激活文件选择
        $('#upload-container').on('click', function(e) {
            console.log("Upload container clicked");
            $('#file-upload').trigger('click');
        });

        // 拖放功能
        $('#upload-container').on('dragover', function(e) {
            console.log("Drag over event");
            e.preventDefault();
            e.stopPropagation();
            $(this).addClass('border-primary');
        });

        $('#upload-container').on('dragleave', function(e) {
            e.preventDefault();
            e.stopPropagation();
            $(this).removeClass('border-primary');
        });

        $('#upload-container').on('drop', function(e) {
            console.log("Drop event detected");
            e.preventDefault();
            e.stopPropagation();
            $(this).removeClass('border-primary');

            var files = e.originalEvent.dataTransfer.files;
            $('#file-upload').prop('files', files);
            updateFilePreview(files);
        });

        // 文件选择更新预览
        $('#file-upload').on('change', function(e) {
            console.log("File input changed", this.files);
            updateFilePreview(this.files);
        });

        // 更新文件预览
        function updateFilePreview(files) {
            $('#file-preview').empty();
            
            if(files.length > 0) {
                console.log("Updating preview with " + files.length + " files");
                for(var i = 0; i < files.length; i++) {
                    var file = files[i];
                    var fileSize = formatFileSize(file.size);
                    var fileType = file.type.split('/')[0];
                    var fileIcon = getFileIcon(file.name);
                    
                    var fileItem = $('<div class="file-item"></div>');
                    fileItem.append('<i class="' + fileIcon + '"></i>');
                    fileItem.append('<span class="file-name">' + file.name + ' (' + fileSize + ')</span>');
                    fileItem.append('<span class="remove-file" data-index="' + i + '"><i class="fas fa-times"></i></span>');
                    
                    $('#file-preview').append(fileItem);
                }
                
                // 绑定删除文件事件
                $('.remove-file').on('click', function() {
                    var index = $(this).data('index');
                    removeFile(index);
                });
            }
        }

        // 移除文件
        function removeFile(index) {
            var input = document.getElementById('file-upload');
            var files = Array.from(input.files);
            
            // 创建新的FileList对象
            var dt = new DataTransfer();
            
            for(var i = 0; i < files.length; i++) {
                // 跳过要删除的文件
                if(i !== index) {
                    dt.items.add(files[i]);
                }
            }
            
            input.files = dt.files;
            updateFilePreview(input.files);
        }

        // 获取文件图标
        function getFileIcon(fileName) {
            var extension = fileName.split('.').pop().toLowerCase();
            var iconClass = 'fas fa-file';
            
            if(['jpg', 'jpeg', 'png', 'gif'].includes(extension)) {
                iconClass = 'fas fa-file-image';
            } else if(['pdf'].includes(extension)) {
                iconClass = 'fas fa-file-pdf';
            } else if(['doc', 'docx'].includes(extension)) {
                iconClass = 'fas fa-file-word';
            } else if(['xls', 'xlsx'].includes(extension)) {
                iconClass = 'fas fa-file-excel';
            } else if(['zip', 'rar'].includes(extension)) {
                iconClass = 'fas fa-file-archive';
            } else if(['txt'].includes(extension)) {
                iconClass = 'fas fa-file-alt';
            }
            
            return iconClass;
        }

        // 格式化文件大小
        function formatFileSize(bytes) {
            if(bytes < 1024) {
                return bytes + ' bytes';
            } else if(bytes < 1048576) {
                return (bytes / 1024).toFixed(1) + ' KB';
            } else {
                return (bytes / 1048576).toFixed(1) + ' MB';
            }
        }

        // 响应者选择交互
        $('.responder-option').on('click', function() {
            var value = $(this).data('value');
            
            // 更新选中状态
            $('.responder-option').removeClass('selected');
            $(this).addClass('selected');
            
            // 更新单选按钮值
            $('input[name="preferred_responder"][value="' + value + '"]').prop('checked', true);
        });
        
        // 初始化选中状态
        var initialValue = $('input[name="preferred_responder"]:checked').val() || 'any';
        $('.responder-option[data-value="' + initialValue + '"]').addClass('selected');
    });
    </script>
</body>
</html>