<?php
session_start();
require_once 'includes/config.php';

// 检查用户是否登录
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

// 获取学院ID参数
$faculty_id = isset($_GET['faculty_id']) ? intval($_GET['faculty_id']) : 0;

try {
    // 根据学院ID获取热门标签
    if ($faculty_id > 0) {
        // 获取特定学院的标签
        $stmt = $pdo->prepare("
            SELECT t.name, COUNT(qt.question_id) as count 
            FROM tags t 
            LEFT JOIN question_tags qt ON t.id = qt.tag_id 
            LEFT JOIN questions q ON qt.question_id = q.id
            WHERE (t.faculty_id = ? OR t.faculty_id IS NULL) 
            AND (q.faculty_id = ? OR q.faculty_id IS NULL OR q.faculty_id = 0)
            GROUP BY t.id, t.name 
            ORDER BY count DESC, t.name ASC
            LIMIT 10
        ");
        $stmt->execute([$faculty_id, $faculty_id]);
    } else {
        // 获取所有标签
        $stmt = $pdo->prepare("
            SELECT t.name, COUNT(qt.question_id) as count 
            FROM tags t 
            LEFT JOIN question_tags qt ON t.id = qt.tag_id 
            GROUP BY t.id, t.name 
            ORDER BY count DESC, t.name ASC
            LIMIT 10
        ");
        $stmt->execute();
    }
    
    $tags = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 确保count字段是数字
    foreach ($tags as &$tag) {
        $tag['count'] = intval($tag['count']);
    }
    
    // 返回JSON格式的标签数据
    header('Content-Type: application/json');
    echo json_encode($tags);
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
}
?>