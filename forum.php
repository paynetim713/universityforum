<?php
session_start();
require_once 'includes/config.php';

// 检查用户是否登录
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

// 获取所有标签和学院
$stmt = $pdo->query("SELECT * FROM tags ORDER BY name");
$all_tags = $stmt->fetchAll();

$stmt = $pdo->query("SELECT * FROM faculties ORDER BY name");
$all_faculties = $stmt->fetchAll();

// 分页设置
$questions_per_page = 10;
$current_page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$offset = ($current_page - 1) * $questions_per_page;

// 确保分页变量都是整数且非空
$current_page = (int)$current_page ?: 1;
$offset = (int)$offset ?: 0;
$questions_per_page = (int)$questions_per_page ?: 10;

// 调试输出 - 可以删除
// echo "<!-- Debug: Current Page = $current_page, Offset = $offset -->";

// 基础查询
$base_query = "
    SELECT DISTINCT q.*, u.username, u.avatar, f.name as faculty_name, f.code as faculty_code,
    (SELECT COUNT(*) FROM answers a WHERE a.question_id = q.id) as answer_count,
    GROUP_CONCAT(t.name) as tags
    FROM questions q
    LEFT JOIN users u ON q.user_id = u.id
    LEFT JOIN faculties f ON q.faculty_id = f.id
    LEFT JOIN question_tags qt ON q.id = qt.question_id
    LEFT JOIN tags t ON qt.tag_id = t.id
";

$conditions = [];
$params = [];

// 处理搜索
if (isset($_GET['search']) && !empty($_GET['search'])) {
    $search = $_GET['search'];
    
    // 分离标签和普通搜索词
    preg_match_all('/#(\w+)/', $search, $tag_matches);
    $search_text = trim(preg_replace('/#\w+/', '', $search));

    // 如果有普通搜索词
    if (!empty($search_text)) {
        $conditions[] = "(q.title LIKE ? OR q.content LIKE ?)";
        $params[] = "%{$search_text}%";
        $params[] = "%{$search_text}%";
    }

    // 如果有标签
    if (!empty($tag_matches[1])) {
        $tag_placeholders = str_repeat('?,', count($tag_matches[1]) - 1) . '?';
        $conditions[] = "t.name IN ($tag_placeholders)";
        $params = array_merge($params, $tag_matches[1]);
    }
}

// 处理学院过滤
if (isset($_GET['faculty']) && !empty($_GET['faculty'])) {
    $faculty_id = intval($_GET['faculty']);
    $conditions[] = "q.faculty_id = ?";
    $params[] = $faculty_id;
}

// 组合查询条件
if (!empty($conditions)) {
    $base_query .= " WHERE " . implode(" AND ", $conditions);
}

// 处理排序
$sort_by = isset($_GET['sort']) ? $_GET['sort'] : 'latest';
$order_clause = "";
switch($sort_by) {
    case 'most_answers':
        $order_clause = "ORDER BY answer_count DESC, MAX(q.created_at) DESC";
        break;
    case 'latest':
    default:
        $order_clause = "ORDER BY MAX(q.created_at) DESC";
        break;
}

$base_query .= " GROUP BY q.id " . $order_clause;

// 先获取总记录数 - 重新构建查询避免GROUP BY问题
$count_base_query = "
    SELECT COUNT(DISTINCT q.id) as total
    FROM questions q
    LEFT JOIN users u ON q.user_id = u.id
    LEFT JOIN faculties f ON q.faculty_id = f.id
    LEFT JOIN question_tags qt ON q.id = qt.question_id
    LEFT JOIN tags t ON qt.tag_id = t.id
";

// 添加相同的WHERE条件
if (!empty($conditions)) {
    $count_base_query .= " WHERE " . implode(" AND ", $conditions);
}

$stmt = $pdo->prepare($count_base_query);
$stmt->execute($params);
$result = $stmt->fetchColumn();
$total_questions = $result !== false && $result !== null ? (int)$result : 0; // 确保有效的整数值
$total_pages = $total_questions > 0 ? (int)ceil($total_questions / max(1, $questions_per_page)) : 0;

// 添加分页到主查询
$base_query .= " LIMIT " . (int)$questions_per_page . " OFFSET " . (int)$offset;

// 执行查询
$stmt = $pdo->prepare($base_query);
$stmt->execute($params);
$questions = $stmt->fetchAll();

// 获取未读通知数量
$stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = FALSE");
$stmt->execute([$_SESSION['user_id']]);
$unread_count = $stmt->fetchColumn();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>UKM NEXUS - Forum</title>
    <link rel="stylesheet" href="style.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <style>
        /* 容器宽度调整 */
        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 0 2rem;
            width: 100%;
        }
        
        /* 学院选择器样式 */
        .faculty-select {
            min-width: 200px;
            padding: 0.875rem 1rem;
            border: none;
            background: transparent;
            color: var(--text-primary);
            font-size: 0.9375rem;
            cursor: pointer;
            border-left: 1px solid var(--border-color);
            transition: var(--transition-fast);
        }

        .faculty-select:focus {
            outline: none;
            color: var(--primary-color);
        }

        .faculty-select option {
            background-color: var(--background-color);
            color: var(--text-primary);
            padding: 0.5rem;
        }

        /* 搜索输入容器调整 */
        .search-input-container {
            display: flex;
            align-items: center;
            background-color: #ffffff;
            border: 1px solid var(--border-color);
            border-radius: 0.75rem;
            transition: all 0.2s;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
        }

        .search-input-container:focus-within {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px var(--primary-color-light);
        }
        
        /* 欢迎区域调整 */
        .welcome-section-container {
            width: 100%;
            max-width: 100%;
            padding: 0;
            margin-bottom: 2rem;
        }
        
        .welcome-with-image {
            border-radius: 0.75rem;
            box-shadow: 0 1px 3px 0 rgb(0 0 0 / 0.1), 0 1px 2px -1px rgb(0 0 0 / 0.1);
            margin-bottom: 1rem;
            transition: var(--transition-all);
            overflow: hidden;
            display: flex;
            min-height: 200px;
        }
        
        .welcome-content {
            width: 50%;
            padding: 2rem;
            display: flex;
            flex-direction: column;
            justify-content: center;
            background: linear-gradient(to right, #3b82f6, #4f46e5);
            overflow: hidden;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
        }
        
        .student-community-tag {
            color: rgba(255, 255, 255, 0.8);
            text-transform: uppercase;
            font-size: 0.875rem;
            font-weight: 600;
            letter-spacing: 0.05em;
        }
        
        .welcome-title {
            color: white !important;
            font-size: 2rem;
            font-weight: 700;
            margin: 0.5rem 0 1rem;
        }
        
        .welcome-text {
            color: white !important;
            font-size: 1.125rem;
            line-height: 1.6;
            margin-bottom: 2rem;
        }
        
        .welcome-cta-btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.875rem 1.75rem;
            background: linear-gradient(to right, #3b82f6, #4f46e5);
            color: white;
            border-radius: 0.5rem;
            font-weight: 600;
            text-decoration: none;
            transition: var(--transition-fast);
            font-size: 1rem;
        }
        
        .welcome-cta-btn:hover {
            background: linear-gradient(45deg, #5558d6, #714bd3);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(99, 102, 241, 0.4);
        }
        
        .campus-image-container {
            width: 50%;
            position: relative;
            overflow: hidden;
            cursor: pointer;
        }
        
        .campus-image-container::before {
            content: 'Click to check';
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            background: rgba(0, 0, 0, 0.7);
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 0.5rem;
            z-index: 3;
            font-size: 0.875rem;
            opacity: 0;
            transition: opacity 0.3s ease;
            white-space: nowrap;
        }
        
        .campus-image-container:hover::before {
            opacity: 1;
        }
        
        .campus-image {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.5s ease;
        }
        
        .campus-image-overlay {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(to top, rgba(0,0,0,0.3) 0%, transparent 50%);
        }
        
        .campus-label {
            position: absolute;
            bottom: 2rem;
            left: 2rem;
            color: white;
            z-index: 2;
        }
        
        .campus-label h3 {
            font-size: 1.5rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
            text-shadow: 2px 2px 4px rgba(0,0,0,0.5);
        }
        
        .campus-label p {
            font-size: 0.875rem;
            opacity: 0.9;
            text-shadow: 0 1px 3px rgba(0,0,0,0.3);
        }
        
        .welcome-with-image:hover .campus-image {
            transform: scale(1.05);
        }
        
        .welcome-with-image:hover {
            box-shadow: 0 4px 6px -1px rgb(0 0 0 / 0.1), 0 2px 4px -2px rgb(0 0 0 / 0.1);
            transform: translateY(-2px);
        }
        
        /* 学院标识 */
        .faculty-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            padding: 0.25rem 0.5rem;
            background: linear-gradient(45deg, var(--info-color-light), #dbeafe);
            color: var(--info-color);
            font-size: 0.75rem;
            border-radius: 0.25rem;
            font-weight: 500;
            margin-bottom: 0.5rem;
        }
        
        /* Lightbox 样式 */
        .lightbox {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.9);
            z-index: 1000;
            opacity: 0;
            transition: opacity 0.3s ease;
        }
        
        .lightbox.show {
            display: flex;
            opacity: 1;
        }
        
        .lightbox-content {
            display: flex;
            width: 100%;
            height: 100%;
            align-items: center;
            justify-content: center;
            padding: 2rem;
            gap: 2rem;
        }
        
        .lightbox-image-container {
            flex: 2;
            height: 80vh;
            position: relative;
        }
        
        .lightbox-image {
            width: 100%;
            height: 100%;
            object-fit: contain;
        }
        
        .lightbox-info {
            flex: 1;
            color: white;
            padding: 2rem;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 1rem;
            backdrop-filter: blur(10px);
            overflow-y: auto;
            max-height: 80vh;
        }
        
        .lightbox-info h2 {
            font-size: 1.875rem;
            font-weight: 700;
            margin-bottom: 1rem;
            color: #ffffff;
        }
        
        .lightbox-info h3 {
            font-size: 1.5rem;
            font-weight: 600;
            margin-top: 2rem;
            margin-bottom: 1rem;
            color: #e2e8f0;
            text-align: center;
        }
        
        .lightbox-info p {
            font-size: 1rem;
            line-height: 1.6;
            margin-bottom: 1rem;
            color: #f8fafc;
        }
        
        .lightbox-info ul {
            list-style-type: disc;
            padding-left: 1.5rem;
            margin-bottom: 1rem;
        }
        
        .lightbox-info li {
            margin-bottom: 0.5rem;
            color: #f8fafc;
        }
        
        .close-lightbox {
            position: absolute;
            top: 1rem;
            right: 1rem;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.1);
            color: white;
            border: none;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: background 0.3s ease;
            z-index: 10;
        }
        
        .close-lightbox:hover {
            background: rgba(255, 255, 255, 0.2);
        }
        
        .lightbox-close-hint {
            position: absolute;
            bottom: 2rem;
            left: 50%;
            transform: translateX(-50%);
            color: rgba(255, 255, 255, 0.7);
            font-size: 0.875rem;
            background: rgba(0, 0, 0, 0.3);
            padding: 0.5rem 1rem;
            border-radius: 1rem;
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        
        .avatar-container {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            overflow: hidden;
            display: flex;
            align-items: center;
            justify-content: center;
            background-color: #f3f4f6;
        }
        
        .user-avatar {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        /* 天气和日期样式 */
        .search-container {
            display: flex;
            justify-content: space-between;
            align-items: center;
            width: 100%;
        }
        
        .search-form {
            flex: 1;
            position: relative;
            width: 100%;
            max-width: 800px;
        }
        
        /* 天气和日期 - 绝对定位到右上角 */
        .weather-date-absolute {
            position: absolute;
            top: 1rem;
            right: 1rem;
            display: flex;
            align-items: center;
            gap: 1rem;
            background-color: var(--background-color);
            padding: 0.5rem 1rem;
            border-radius: 0.5rem;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            z-index: 10;
        }
        
        .weather-info, .date-info {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: var(--text-secondary);
            font-size: 0.875rem;
        }
        
        .weather-info i, .date-info i {
            color: var(--primary-color);
        }
        
        .weather-info i.fa-sun {
            color: #f59e0b;
        }
        
        .weather-info i.fa-cloud {
            color: #6b7280;
        }
        
        .weather-info i.fa-cloud-rain {
            color: #3b82f6;
        }
        
        .weather-info i.fa-snowflake {
            color: #60a5fa;
        }
        
        /* 分页样式 */
        .pagination-container {
            margin-top: 2rem;
            padding: 1.5rem 0;
            border-top: 1px solid var(--border-color);
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 1rem;
        }

        .pagination {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            flex-wrap: wrap;
            justify-content: center;
        }

        .pagination-btn {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 1rem;
            border: 1px solid var(--border-color);
            border-radius: 0.5rem;
            color: var(--text-primary);
            text-decoration: none;
            transition: var(--transition-fast);
            background: var(--background-color);
            font-weight: 500;
        }

        .pagination-btn:hover {
            border-color: var(--primary-color);
            background: var(--primary-color-light);
            color: var(--primary-color);
        }

        .pagination-number {
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 1px solid var(--border-color);
            border-radius: 0.5rem;
            color: var(--text-primary);
            text-decoration: none;
            transition: var(--transition-fast);
            background: var(--background-color);
            font-weight: 500;
        }

        .pagination-number:hover {
            border-color: var(--primary-color);
            background: var(--primary-color-light);
            color: var(--primary-color);
        }

        .pagination-number.active {
            background: var(--primary-color) !important;
            color: white !important;
            border-color: var(--primary-color) !important;
            box-shadow: 0 2px 4px rgba(99, 102, 241, 0.4);
            font-weight: 600;
            position: relative;
        }

        .pagination-number.active::before {
            content: '';
            position: absolute;
            top: -2px;
            left: -2px;
            right: -2px;
            bottom: -2px;
            background: linear-gradient(45deg, var(--primary-color), var(--accent-color));
            border-radius: 0.5rem;
            z-index: -1;
        }

        .pagination-dots {
            color: var(--text-secondary);
            padding: 0 0.5rem;
        }

        .pagination-info {
            text-align: center;
            color: var(--text-secondary);
            font-size: 0.875rem;
        }
        
        /* 响应式设计 */
        @media (max-width: 768px) {
            .search-input-container {
                flex-direction: column;
                border-radius: 0.5rem;
            }
            
            .faculty-select {
                width: 100%;
                border-left: none;
                border-top: 1px solid var(--border-color);
                min-width: auto;
            }
            
            .welcome-with-image {
                flex-direction: column;
                min-height: auto;
            }
            
            .welcome-content, .campus-image-container {
                width: 100%;
            }
            
            .welcome-content {
                padding: 1.5rem;
            }
            
            .campus-image-container {
                height: 200px;
            }
            
            .welcome-title {
                font-size: 1.5rem;
            }
            
            .welcome-text {
                font-size: 1rem;
            }
            
            .lightbox-content {
                flex-direction: column;
                padding: 1rem;
            }
            
            .lightbox-image-container {
                height: 40vh;
            }
            
            .lightbox-info {
                padding: 1rem;
                font-size: 0.875rem;
            }
            
            .weather-date-absolute {
                position: static;
                margin: 0 auto 1rem;
                width: 90%;
                justify-content: center;
            }
            
            .pagination {
                gap: 0.25rem;
            }
            
            .pagination-btn {
                padding: 0.375rem 0.75rem;
                font-size: 0.875rem;
            }
            
            .pagination-number {
                width: 35px;
                height: 35px;
                font-size: 0.875rem;
            }
            
            .pagination-info {
                font-size: 0.75rem;
            }
        }

/* 主容器 - 同行布局，完美对齐 */
.search-and-filter-container {
    display: flex;
    gap: 1rem;
    align-items: stretch; /* 改为stretch确保高度一致 */
    background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
    padding: 1.5rem;
    border-radius: 1rem;
    box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -2px rgba(0, 0, 0, 0.1);
    border: 1px solid var(--border-color);
}

/* 排序过滤器部分 */
.sort-filter-wrapper {
    display: flex;
    flex-direction: column;
    justify-content: flex-end;
    min-width: 180px;
    flex-shrink: 0;
}

.sort-filter-select {
    width: 100%;
    height: 60px;
    padding: 0 1rem;
    border: 2px solid var(--border-color);
    border-radius: 0.75rem;
    background: linear-gradient(135deg, #e0f2fe 0%, #f0f9ff 100%);
    color: var(--text-primary);
    font-size: 0.9375rem;
    font-weight: 500;
    cursor: pointer;
    transition: var(--transition-fast);
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
}

.sort-filter-select:focus {
    outline: none;
    border-color: var(--primary-color);
    box-shadow: 0 0 0 3px var(--primary-color-light), 0 4px 6px rgba(0, 0, 0, 0.1);
    transform: translateY(-1px);
}

.sort-filter-select:hover {
    border-color: var(--primary-color);
    transform: translateY(-1px);
    box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
}

/* 学院过滤器部分 */
.faculty-filter-wrapper {
    display: flex;
    flex-direction: column;
    justify-content: flex-end; /* 底部对齐 */
    min-width: 180px;
    flex-shrink: 0;
}

.filter-label {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-weight: 600;
    color: var(--text-primary);
    font-size: 0.875rem;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    margin-bottom: 0.5rem; /* 减小间距 */
}

.filter-label i {
    color: var(--primary-color);
    font-size: 1rem;
}

.faculty-filter-select {
    width: 100%;
    height: 60px; /* 固定高度与搜索框匹配 */
    padding: 0 1rem;
    border: 2px solid var(--border-color);
    border-radius: 0.75rem;
    background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
    color: var(--text-primary);
    font-size: 0.9375rem;
    font-weight: 500;
    cursor: pointer;
    transition: var(--transition-fast);
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
    display: flex;
    align-items: center;
}

.faculty-filter-select:focus {
    outline: none;
    border-color: var(--primary-color);
    box-shadow: 0 0 0 3px var(--primary-color-light), 0 4px 6px rgba(0, 0, 0, 0.1);
    transform: translateY(-1px);
}

.faculty-filter-select:hover {
    border-color: var(--primary-color);
    transform: translateY(-1px);
    box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
}

.faculty-filter-select option {
    background-color: var(--background-color);
    color: var(--text-primary);
    padding: 0.75rem;
    font-weight: 500;
}

/* 搜索部分 */
.search-wrapper {
    flex: 1;
    position: relative;
    display: flex;
    flex-direction: column;
    justify-content: flex-end; /* 底部对齐 */
}

.search-form {
    position: relative;
    width: 100%;
}

.search-input-container {
    display: flex;
    align-items: center;
    background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
    border: 2px solid var(--border-color);
    border-radius: 0.75rem;
    transition: all 0.2s;
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
    width: 100%;
    height: 60px; /* 固定高度与学院选择器匹配 */
}

.search-input-container:focus-within {
    border-color: var(--primary-color);
    box-shadow: 0 0 0 3px var(--primary-color-light), 0 4px 6px rgba(0, 0, 0, 0.1);
    transform: translateY(-1px);
}

.search-input-container:hover {
    border-color: var(--primary-color);
    transform: translateY(-1px);
    box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
}

.search-input {
    flex: 1;
    padding: 0 1.25rem;
    border: none;
    background: transparent;
    font-size: 1rem;
    color: var(--text-primary);
    font-weight: 500;
    height: 100%;
}

.search-input::placeholder {
    color: var(--text-secondary);
    font-weight: 400;
}

.search-input:focus {
    outline: none;
}

.search-button {
    width: 60px; /* 固定宽度 */
    height: 60px; /* 与容器高度一致 */
    background: linear-gradient(45deg, var(--primary-color), var(--accent-color));
    border: none;
    color: white;
    cursor: pointer;
    transition: var(--transition-fast);
    border-radius: 0 0.5rem 0.5rem 0;
    font-size: 1rem;
    display: flex;
    align-items: center;
    justify-content: center;
}

.search-button:hover {
    background: linear-gradient(45deg, var(--primary-color-dark), #714bd3);
    transform: translateY(-1px);
    box-shadow: 0 4px 8px rgba(99, 102, 241, 0.4);
}

/* 标签建议样式 */
.tag-suggestions {
    display: none;
    position: absolute;
    top: 100%;
    left: 0;
    right: 0;
    background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
    border: 2px solid var(--border-color);
    border-radius: 0.75rem;
    margin-top: 0.5rem;
    padding: 0.75rem;
    box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -4px rgba(0, 0, 0, 0.1);
    z-index: 50;
    max-height: 300px;
    overflow-y: auto;
    backdrop-filter: blur(10px);
    -webkit-backdrop-filter: blur(10px);
}

.tag-suggestions.show {
    display: block;
    animation: slideDownBounce 0.3s ease-out;
}

.tag-suggestion {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 0.75rem 1rem;
    cursor: pointer;
    border-radius: 0.5rem;
    transition: var(--transition-fast);
    color: var(--text-primary);
    font-weight: 500;
    border: 1px solid transparent;
}

.tag-suggestion:hover {
    background: linear-gradient(45deg, var(--primary-color-light), var(--accent-color-light));
    transform: translateX(5px);
    border-color: var(--primary-color);
    box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
}

.tag-suggestion i {
    color: var(--primary-color);
    font-size: 0.875rem;
}

.tag-count {
    margin-left: auto;
    font-size: 0.75rem;
    color: var(--primary-color);
    background: linear-gradient(45deg, var(--primary-color-light), var(--accent-color-light));
    padding: 0.25rem 0.625rem;
    border-radius: 9999px;
    font-weight: 600;
    border: 1px solid var(--primary-color);
}

/* 响应式设计 */
@media (max-width: 968px) {
    .search-and-filter-container {
        flex-direction: column;
        gap: 1rem;
    }
    
    .sort-filter-wrapper,
    .faculty-filter-wrapper,
    .search-wrapper {
        justify-content: flex-start;
    }
    
    .sort-filter-wrapper,
    .faculty-filter-wrapper {
        min-width: auto;
        width: 100%;
    }
    
    .filter-label {
        justify-content: center;
    }
}

@media (max-width: 640px) {
    .search-and-filter-container {
        padding: 1rem;
        margin: 0 0.5rem;
    }
    
    .sort-filter-select,
    .faculty-filter-select,
    .search-input {
        font-size: 16px; /* 防止iOS缩放 */
    }
    
    .search-input-container,
    .sort-filter-select,
    .faculty-filter-select {
        height: 50px; /* 移动端稍微小一点 */
    }
    
    .search-button {
        width: 50px;
        height: 50px;
    }
}

/* 动画 */
@keyframes slideDownBounce {
    0% {
        opacity: 0;
        transform: translateY(-10px) scale(0.95);
    }
    60% {
        opacity: 1;
        transform: translateY(2px) scale(1.02);
    }
    100% {
        opacity: 1;
        transform: translateY(0) scale(1);
    }
}

/* 聚焦状态增强 */
.faculty-filter-select:focus,
.search-input:focus {
    position: relative;
    z-index: 10;
}
    </style>
    
</head>
<body>
    <div class="page-wrapper">
        <div class="main-container">
            <?php require_once 'includes/navbar.php'; ?>

<!-- 搜索部分 - 同行对齐版本 -->
<div class="search-section">
    <div class="container">
        <div class="search-and-filter-container">
            <!-- 排序选择器 -->
            <div class="sort-filter-wrapper">
                <select name="sort" id="sortFilter" class="sort-filter-select">
                    <option value="latest" <?php echo (!isset($_GET['sort']) || $_GET['sort'] == 'latest') ? 'selected' : ''; ?>>
                        Latest Questions
                    </option>
                    <option value="most_answers" <?php echo (isset($_GET['sort']) && $_GET['sort'] == 'most_answers') ? 'selected' : ''; ?>>
                        Most Answers
                    </option>
                </select>
            </div>

            <!-- 学院过滤器 -->
            <div class="faculty-filter-wrapper">
                <select name="faculty" id="facultyFilter" class="faculty-filter-select">
                    <option value="">All Faculties</option>
                    <?php foreach ($all_faculties as $faculty): ?>
                        <option value="<?php echo $faculty['id']; ?>" 
                                <?php echo (isset($_GET['faculty']) && $_GET['faculty'] == $faculty['id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($faculty['code']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- 搜索表单 -->
            <div class="search-wrapper">
                <form action="forum.php" method="GET" class="search-form">
                    <!-- 隐藏的学院过滤器值 -->
                    <input type="hidden" name="faculty" id="hiddenFacultyInput" value="<?php echo isset($_GET['faculty']) ? htmlspecialchars($_GET['faculty']) : ''; ?>">
                    <!-- 隐藏的排序值 -->
                    <input type="hidden" name="sort" id="hiddenSortInput" value="<?php echo isset($_GET['sort']) ? htmlspecialchars($_GET['sort']) : 'latest'; ?>">
                    
                    <div class="search-input-container">
                        <input type="text" name="search" id="searchInput" class="search-input" 
                               placeholder="Search questions or click tags below..."
                               value="<?php echo isset($_GET['search']) ? htmlspecialchars($_GET['search']) : ''; ?>"
                               autocomplete="off">
                        <button type="submit" class="search-button">
                            <i class="fas fa-search"></i>
                        </button>
                    </div>
                    
                    <!-- 标签建议区域 -->
                    <div id="tagSuggestions" class="tag-suggestions">
                        <?php
                        // 显示热门标签，可以按学院过滤
                        $faculty_filter = "";
                        $tag_params = [];
                        
                        if (isset($_GET['faculty']) && !empty($_GET['faculty'])) {
                            $faculty_filter = "WHERE (t.faculty_id = ? OR t.faculty_id IS NULL)";
                            $tag_params[] = intval($_GET['faculty']);
                        }
                        
                        $tag_query = "
                            SELECT t.name, COUNT(qt.question_id) as count 
                            FROM tags t 
                            LEFT JOIN question_tags qt ON t.id = qt.tag_id 
                            $faculty_filter
                            GROUP BY t.id, t.name 
                            ORDER BY count DESC 
                            LIMIT 10
                        ";
                        
                        $stmt = $pdo->prepare($tag_query);
                        $stmt->execute($tag_params);
                        $popular_tags = $stmt->fetchAll();
                        
                        foreach ($popular_tags as $tag): ?>
                            <div class="tag-suggestion" data-tag="<?php echo htmlspecialchars($tag['name']); ?>">
                                <i class="fas fa-tag"></i>
                                <?php echo htmlspecialchars($tag['name']); ?>
                                <span class="tag-count"><?php echo $tag['count']; ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
            
            <!-- 天气和日期显示 -->
            <div class="weather-date-absolute">
                <div class="weather-info">
                    <i id="weather-icon" class="fas fa-cloud-sun"></i>
                    <span id="temperature">Loading...</span>
                </div>
                <div class="date-info">
                    <i class="far fa-calendar-alt"></i>
                    <span id="current-date">Loading...</span>
                </div>
            </div>

            <div class="container">
                <div class="content-area">
                    <!-- 欢迎区块 -->
                    <div class="welcome-with-image">
                        <div class="welcome-content">
                            <span class="student-community-tag">STUDENT COMMUNITY</span>
                            <h2 class="welcome-title">Welcome to UKM NEXUS</h2>
                            <p class="welcome-text">Connect with fellow students, share knowledge, and find answers to your academic questions.</p>
                            <a href="new_question.php" class="welcome-cta-btn">
                                <i class="fas fa-question-circle"></i>
                                Ask Your First Question
                            </a>
                        </div>

                        <!-- 校园图片区域 -->
                        <div class="campus-image-container" onclick="openLightbox()">
                            <?php if(file_exists('assets/images/ukm_campus.jpg')): ?>
                                <img src="assets/images/ukm_campus.jpg" alt="UKM Campus" class="campus-image">
                                <div class="campus-image-overlay"></div>
                                <div class="campus-label">
                                    <h3>Universiti Kebangsaan Malaysia</h3>
                                    <p>Experience excellence in education</p>
                                </div>
                            <?php else: ?>
                                <div style="width: 100%; height: 100%; background: linear-gradient(45deg, #4f46e5, #6366f1); 
                                display: flex; align-items: center; justify-content: center; color: white; font-size: 1.5rem;">
                                    <div style="text-align: center;">
                                        <i class="fas fa-university fa-3x" style="margin-bottom: 1rem;"></i>
                                        <p>UKM Campus</p>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Lightbox -->
                    <div id="lightbox" class="lightbox" onclick="handleLightboxClick(event)">
                        <button class="close-lightbox" onclick="closeLightbox(event)">
                            <i class="fas fa-times"></i>
                        </button>
                        <div class="lightbox-content" onclick="event.stopPropagation()">
                            <div class="lightbox-image-container">
                                <img src="assets/images/ukm_campus.jpg" alt="UKM Campus" class="lightbox-image">
                            </div>
                            <div class="lightbox-info">
                                <h2>About UKM NEXUS</h2>
                                <p>Welcome to UKM NEXUS, the premier student community platform of Universiti Kebangsaan Malaysia.</p>

                                <h3>Universiti Kebangsaan Malaysia</h3>
                                <p>UKM, established in 1970, is one of Malaysia's premier research universities. With a commitment to excellence in teaching, research, and innovation, UKM has consistently ranked among the top universities in Asia.</p>

                                <h3>Key Features:</h3>
                                <ul>
                                    <li>World-class academic programs</li>
                                    <li>State-of-the-art research facilities</li>
                                    <li>Diverse student community</li>
                                    <li>Strategic location in Bangi, Selangor</li>
                                </ul>

                                <h3>UKM NEXUS Platform</h3>
                                <p>This forum serves as a central hub for UKM students to:</p>
                                <ul>
                                    <li>Ask and answer academic questions</li>
                                    <li>Share knowledge and experiences</li>
                                    <li>Connect with fellow students</li>
                                    <li>Discuss campus activities and events</li>
                                    <li>Seek advice on career and internship opportunities</li>
                                </ul>

                                <p>Join our growing community and be part of UKM's digital transformation in education!</p>
                            </div>
                        </div>
                        <div class="lightbox-close-hint">
                            <span>Press Esc to close</span>
                        </div>
                    </div>

                    <!-- 问题列表 -->
                    <?php if (count($questions) > 0): ?>
                        <!-- Debug info - 可以删除 -->
                        <?php if (isset($_GET['debug'])): ?>
                            <div style="background: #f0f0f0; padding: 10px; margin: 10px 0; border-radius: 5px;">
                                <strong>Debug Info:</strong><br>
                                Current Page: <?php echo (int)($current_page ?? 1); ?><br>
                                Total Pages: <?php echo (int)($total_pages ?? 0); ?><br>
                                Total Questions: <?php echo (int)($total_questions ?? 0); ?><br>
                                Offset: <?php echo (int)($offset ?? 0); ?><br>
                                Questions Per Page: <?php echo (int)($questions_per_page ?? 10); ?><br>
                                GET Parameters: <?php echo htmlspecialchars(print_r($_GET, true)); ?>
                            </div>
                        <?php endif; ?>
                        
                        <?php foreach ($questions as $question): ?>
                            <div class="question-box">
                                <div class="question-header">
                                    <div class="flex items-center gap-2">
                                        <span class="avatar-container">
                                            <?php if (!empty($question['avatar'])): ?>
                                                <img src="assets/avatars/<?php echo htmlspecialchars($question['avatar']); ?>" alt="User Avatar" class="user-avatar">
                                            <?php else: ?>
                                                <i class="fas fa-user-circle fa-lg text-primary"></i>
                                            <?php endif; ?>
                                        </span>
                                        <div class="question-meta">
                                            <span class="text-secondary">
                                                <?php echo htmlspecialchars($question['username']); ?>
                                            </span>
                                            <span class="text-secondary">•</span>
                                            <span class="text-secondary">
                                                <?php echo date('M j, Y', strtotime($question['created_at'])); ?>
                                            </span>
                                        </div>
                                    </div>
                                </div>

                                <!-- 学院标识 -->
                                <?php if (!empty($question['faculty_name'])): ?>
                                    <div class="faculty-badge">
                                        <i class="fas fa-university"></i>
                                        <?php echo htmlspecialchars($question['faculty_code']); ?>
                                    </div>
                                <?php endif; ?>

                                <a href="question.php?id=<?php echo $question['id']; ?>" class="question-title">
                                    <?php echo htmlspecialchars($question['title']); ?>
                                </a>

                                <div class="question-content">
                                    <?php 
                                    $content = strip_tags($question['content']);
                                    echo strlen($content) > 200 ? substr($content, 0, 200) . '...' : $content;
                                    ?>
                                </div>

                                <?php if (!empty($question['tags'])): ?>
                                    <div class="tags-container">
                                        <?php foreach (explode(',', $question['tags']) as $tag): ?>
                                            <span class="tag"><?php echo htmlspecialchars(trim($tag)); ?></span>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>

                                <div class="question-stats">
                                    <div class="stat-item">
                                        <i class="fas fa-comment"></i>
                                        <?php echo $question['answer_count']; ?> answers
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        
                        <!-- 分页导航 -->
                        <?php if ((int)$total_pages > 1): ?>
                            <div class="pagination-container">
                                <div class="pagination">
                                    <?php
                                    // 确保所有分页变量都是整数
                                    $safe_current_page = (int)($current_page ?? 1);
                                    $safe_total_pages = (int)($total_pages ?? 1);
                                    ?>
                                    
                                    <!-- 上一页 -->
                                    <?php if ($safe_current_page > 1): ?>
                                        <?php
                                        // 构建上一页URL参数
                                        $prev_params = array_merge($_GET, ['page' => $safe_current_page - 1]);
                                        ?>
                                        <a href="?<?php echo http_build_query($prev_params); ?>" class="pagination-btn">
                                            <i class="fas fa-chevron-left"></i>
                                            Previous
                                        </a>
                                    <?php endif; ?>
                                    
                                    <!-- 页码 -->
                                    <?php
                                    $start_page = max(1, $safe_current_page - 2);
                                    $end_page = min($safe_total_pages, $safe_current_page + 2);
                                    
                                    // 第一页和省略号
                                    if ($start_page > 1): 
                                        $first_params = array_merge($_GET, ['page' => 1]);
                                        ?>
                                        <a href="?<?php echo http_build_query($first_params); ?>" 
                                           class="pagination-number <?php echo (1 == $safe_current_page) ? 'active' : ''; ?>">1</a>
                                        <?php if ($start_page > 2): ?>
                                            <span class="pagination-dots">...</span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                    
                                    <!-- 中间页码 -->
                                    <?php for ($i = $start_page; $i <= $end_page; $i++): 
                                        $page_params = array_merge($_GET, ['page' => $i]);
                                        $is_current = ($i == $safe_current_page);
                                        ?>
                                        <a href="?<?php echo http_build_query($page_params); ?>" 
                                           class="pagination-number <?php echo $is_current ? 'active' : ''; ?>"
                                           <?php if ($is_current): ?>aria-current="page"<?php endif; ?>>
                                            <?php echo $i; ?>
                                        </a>
                                    <?php endfor; ?>
                                    
                                    <!-- 最后页和省略号 -->
                                    <?php if ($end_page < $safe_total_pages): ?>
                                        <?php if ($end_page < $safe_total_pages - 1): ?>
                                            <span class="pagination-dots">...</span>
                                        <?php endif; ?>
                                        <?php
                                        $last_params = array_merge($_GET, ['page' => $safe_total_pages]);
                                        ?>
                                        <a href="?<?php echo http_build_query($last_params); ?>" 
                                           class="pagination-number <?php echo ($safe_total_pages == $safe_current_page) ? 'active' : ''; ?>">
                                            <?php echo $safe_total_pages; ?>
                                        </a>
                                    <?php endif; ?>
                                    
                                    <!-- 下一页 -->
                                    <?php if ($safe_current_page < $safe_total_pages): ?>
                                        <?php
                                        $next_params = array_merge($_GET, ['page' => $safe_current_page + 1]);
                                        ?>
                                        <a href="?<?php echo http_build_query($next_params); ?>" class="pagination-btn">
                                            Next
                                            <i class="fas fa-chevron-right"></i>
                                        </a>
                                    <?php endif; ?>
                                </div>
                                
                                <!-- 页面信息 -->
                                <div class="pagination-info">
                                    <?php 
                                    // 确保所有变量都是整数
                                    $safe_offset = (int)($offset ?? 0);
                                    $safe_total = (int)($total_questions ?? 0);
                                    $safe_per_page = (int)($questions_per_page ?? 10);
                                    
                                    $start_item = $safe_total > 0 ? $safe_offset + 1 : 0;
                                    $end_item = min($safe_offset + $safe_per_page, $safe_total);
                                    ?>
                                    Showing <?php echo (int)$start_item; ?>-<?php echo (int)$end_item; ?> of <?php echo (int)$safe_total; ?> questions
                                    <!-- Debug: Current Page = <?php echo (int)$current_page; ?> -->
                                </div>
                            </div>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="text-center py-8">
                            <i class="fas fa-search fa-3x text-secondary mb-4"></i>
                            <h3 class="text-xl font-semibold mb-2">No questions found</h3>
                            <p class="text-secondary mb-4">
                                <?php if (isset($_GET['search']) || isset($_GET['faculty'])): ?>
                                    Try adjusting your search criteria or browse all questions.
                                <?php else: ?>
                                    Be the first to ask a question in this community!
                                <?php endif; ?>
                            </p>
                            <a href="new_question.php" class="btn btn-primary">
                                <i class="fas fa-plus"></i>
                                Ask a Question
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <script>
document.addEventListener('DOMContentLoaded', function() {
    const searchInput = document.getElementById('searchInput');
    const tagSuggestions = document.getElementById('tagSuggestions');
    const facultyFilter = document.getElementById('facultyFilter');
    const sortFilter = document.getElementById('sortFilter');
    const hiddenFacultyInput = document.getElementById('hiddenFacultyInput');
    const hiddenSortInput = document.getElementById('hiddenSortInput');
    const searchForm = document.querySelector('.search-form');

    // 使用事件委托处理标签点击
    tagSuggestions.addEventListener('click', function(e) {
        const tagElement = e.target.closest('.tag-suggestion');
        if (tagElement) {
            const tagName = tagElement.dataset.tag;
            const currentValue = searchInput.value;
            const tags = currentValue.match(/#\w+/g) || [];

            // 添加新标签如果不存在
            if (!tags.includes('#' + tagName)) {
                const newValue = currentValue.trim() + (currentValue ? ' ' : '') + '#' + tagName;
                searchInput.value = newValue;
            }

            searchInput.focus();
            tagSuggestions.classList.remove('show');
        }
    });

    // 显示标签建议
    searchInput.addEventListener('focus', function() {
        tagSuggestions.classList.add('show');
    });

    // 点击外部隐藏建议
    document.addEventListener('click', function(e) {
        if (!searchInput.contains(e.target) && !tagSuggestions.contains(e.target)) {
            tagSuggestions.classList.remove('show');
        }
    });

    // 排序选择器变化时的处理
    sortFilter.addEventListener('change', function() {
        const sortValue = this.value;
        
        // 更新隐藏的输入字段
        hiddenSortInput.value = sortValue;
        
        // 重置到第一页并提交表单
        const url = new URL(window.location);
        url.searchParams.set('sort', sortValue);
        url.searchParams.delete('page'); // 重置到第一页
        window.location.href = url.toString();
    });

    // 学院过滤器变化时的处理
    facultyFilter.addEventListener('change', function() {
        const facultyId = this.value;
        
        // 更新隐藏的输入字段
        hiddenFacultyInput.value = facultyId;
        
        // 显示加载状态
        tagSuggestions.innerHTML = '<div style="padding: 1rem; text-align: center; color: var(--text-secondary);"><i class="fas fa-spinner fa-spin"></i> Loading tags...</div>';
        
        // 通过AJAX获取该学院的热门标签
        fetch(`get_faculty_tags.php?faculty_id=${facultyId}`)
            .then(response => {
                if (!response.ok) {
                    throw new Error('Network response was not ok');
                }
                return response.json();
            })
            .then(tags => {
                // 清空当前标签建议
                tagSuggestions.innerHTML = '';
                
                if (tags.length === 0) {
                    tagSuggestions.innerHTML = '<div style="padding: 1rem; text-align: center; color: var(--text-secondary);">No tags found for this faculty</div>';
                    return;
                }
                
                // 添加新的标签建议
                tags.forEach(tag => {
                    const tagElement = document.createElement('div');
                    tagElement.className = 'tag-suggestion';
                    tagElement.setAttribute('data-tag', tag.name);
                    tagElement.innerHTML = `
                        <i class="fas fa-tag"></i>
                        ${tag.name}
                        <span class="tag-count">${tag.count}</span>
                    `;
                    
                    tagSuggestions.appendChild(tagElement);
                });
            })
            .catch(error => {
                console.error('Error fetching faculty tags:', error);
                tagSuggestions.innerHTML = '<div style="padding: 1rem; text-align: center; color: var(--error-color);">Error loading tags</div>';
            });
        
        // 重置到第一页并提交表单
        const url = new URL(window.location);
        url.searchParams.set('faculty', facultyId);
        url.searchParams.delete('page'); // 重置到第一页
        window.location.href = url.toString();
    });

    // 搜索表单提交时同步所有过滤器值
    searchForm.addEventListener('submit', function(e) {
        // 确保隐藏字段有正确的值
        hiddenFacultyInput.value = facultyFilter.value;
        hiddenSortInput.value = sortFilter.value;
    });
    
    // 更新当前日期
    function updateDate() {
        const dateElement = document.getElementById('current-date');
        if (dateElement) {
            const now = new Date();
            const options = { year: 'numeric', month: 'short', day: 'numeric' };
            dateElement.textContent = now.toLocaleDateString('en-US', options);
        }
    }
    updateDate();

    // 获取天气信息
    async function getWeather() {
        try {
            const temperatureElement = document.getElementById('temperature');
            if (!temperatureElement) return;
            
            const apiKey = 'b17d8035ce104a86a08892b214437a9c'; 
            const lat = 3.139;
            const lon = 101.6869;
            const url = `https://api.weatherbit.io/v2.0/current?lat=${lat}&lon=${lon}&key=${apiKey}&units=M`;
            
            const response = await fetch(url);
            const data = await response.json();
            
            if (data.data && data.data[0]) {
                const weatherData = data.data[0];
                
                // 更新温度
                const temp = Math.round(weatherData.temp);
                temperatureElement.textContent = `${temp}°C`;
                
                // 更新天气图标
                const weatherIcon = document.getElementById('weather-icon');
                if (weatherIcon) {
                    const weatherCode = weatherData.weather.code;
                    
                    weatherIcon.className = ''; 
                    
                    if (weatherCode >= 200 && weatherCode < 300) {
                        weatherIcon.className = 'fas fa-bolt';
                    } else if (weatherCode >= 300 && weatherCode < 400) {
                        weatherIcon.className = 'fas fa-cloud-rain';
                    } else if (weatherCode >= 500 && weatherCode < 600) {
                        weatherIcon.className = 'fas fa-cloud-showers-heavy';
                    } else if (weatherCode >= 600 && weatherCode < 700) {
                        weatherIcon.className = 'fas fa-snowflake';
                    } else if (weatherCode >= 700 && weatherCode < 800) {
                        weatherIcon.className = 'fas fa-smog';
                    } else if (weatherCode === 800) {
                        weatherIcon.className = 'fas fa-sun';
                    } else if (weatherCode > 800) {
                        weatherIcon.className = 'fas fa-cloud';
                    }
                }
            }
        } catch (error) {
            console.error('获取天气数据失败:', error);
            const temperatureElement = document.getElementById('temperature');
            if (temperatureElement) {
                temperatureElement.textContent = '28°C';
            }
        }
    }
    
    getWeather();
});

// Lightbox 功能
function openLightbox() {
    const lightbox = document.getElementById('lightbox');
    if (lightbox) {
        lightbox.classList.add('show');
        document.body.style.overflow = 'hidden';
    }
}

function closeLightbox(event) {
    if (event) {
        event.stopPropagation();
    }
    const lightbox = document.getElementById('lightbox');
    if (lightbox) {
        lightbox.classList.remove('show');
        document.body.style.overflow = '';
    }
}

function handleLightboxClick(event) {
    closeLightbox();
}

// ESC键关闭lightbox
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeLightbox();
    }
});
</script>
</body>
</html>