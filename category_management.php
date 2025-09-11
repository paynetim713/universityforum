<?php
session_start();
require_once 'includes/config.php';

// 检查是否是管理员
if (!isset($_SESSION['user_id']) || !$_SESSION['is_admin']) {
    header("Location: index.php");
    exit();
}

$error_message = '';
$success_message = '';

// 处理添加分类
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'add') {
        $name = trim($_POST['name']);
        if (!empty($name)) {
            $stmt = $pdo->prepare("INSERT INTO tags (name) VALUES (?)");
            $stmt->execute([$name]);
            $success_message = "分类添加成功！";
        }
    }
    // 处理编辑分类
    elseif ($_POST['action'] === 'edit') {
        $id = $_POST['id'];
        $name = trim($_POST['name']);
        if (!empty($name)) {
            $stmt = $pdo->prepare("UPDATE tags SET name = ? WHERE id = ?");
            $stmt->execute([$name, $id]);
            $success_message = "分类更新成功！";
        }
    }
    // 处理删除分类
    elseif ($_POST['action'] === 'delete') {
        $id = $_POST['id'];
        // 首先检查是否有关联的问题
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM question_tags WHERE tag_id = ?");
        $stmt->execute([$id]);
        $count = $stmt->fetchColumn();
        
        if ($count == 0) {
            $stmt = $pdo->prepare("DELETE FROM tags WHERE id = ?");
            $stmt->execute([$id]);
            $success_message = "分类删除成功！";
        } else {
            $error_message = "无法删除该分类，因为还有 {$count} 个问题使用此分类。请先移除这些问题的分类标签后再试。";
        }
    }
    // 处理批量删除
    elseif ($_POST['action'] === 'batch_delete') {
        $ids = $_POST['ids'];
        $failed_deletions = [];
        
        foreach ($ids as $id) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM question_tags WHERE tag_id = ?");
            $stmt->execute([$id]);
            if ($stmt->fetchColumn() == 0) {
                $stmt = $pdo->prepare("DELETE FROM tags WHERE id = ?");
                $stmt->execute([$id]);
            } else {
                // 获取分类名称
                $stmt = $pdo->prepare("SELECT name FROM tags WHERE id = ?");
                $stmt->execute([$id]);
                $tag_name = $stmt->fetchColumn();
                $failed_deletions[] = $tag_name;
            }
        }
        
        if (!empty($failed_deletions)) {
            $error_message = "以下分类无法删除（存在关联的问题）：" . implode(", ", $failed_deletions);
        } else {
            $success_message = "选中的分类已全部删除！";
        }
    }
    
    if (!isset($_POST['ajax'])) {
        header("Location: category_management.php" . 
               (!empty($error_message) ? "?error=" . urlencode($error_message) : "") .
               (!empty($success_message) ? "?success=" . urlencode($success_message) : ""));
        exit();
    }
}

// 获取所有分类及其问题数量
$stmt = $pdo->query("
    SELECT t.id, t.name, COUNT(qt.question_id) as question_count 
    FROM tags t 
    LEFT JOIN question_tags qt ON t.id = qt.tag_id 
    GROUP BY t.id, t.name 
    ORDER BY t.name ASC
");
$categories = $stmt->fetchAll();

// 如果是通过 GET 参数传递的消息
if (isset($_GET['error'])) {
    $error_message = $_GET['error'];
}
if (isset($_GET['success'])) {
    $success_message = $_GET['success'];
}

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
    <title>UKM NEXUS - Category Management</title>
    <link rel="stylesheet" href="style.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
    .modal {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background-color: rgba(0, 0, 0, 0.5);
        z-index: 1000;
    }
    .modal-content {
        position: relative;
        background-color: var(--background-color);
        margin: 15% auto;
        padding: 2rem;
        border-radius: 0.5rem;
        max-width: 500px;
        box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
    }
    .close {
        position: absolute;
        right: 1rem;
        top: 1rem;
        font-size: 1.5rem;
        cursor: pointer;
        color: var(--text-tertiary);
    }
    .close:hover {
        color: var(--text-primary);
    }
    .admin-table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 1rem;
    }
    .admin-table th {
        background-color: var(--background-secondary);
        padding: 0.75rem 1rem;
        text-align: left;
        font-weight: 600;
        color: var(--text-primary);
    }
    .admin-table td {
        padding: 0.75rem 1rem;
        border-bottom: 1px solid var(--border-color);
    }
    .admin-table tr:hover {
        background-color: var(--background-hover);
    }
    .actions {
        display: flex;
        gap: 0.5rem;
    }
    .btn-icon {
        padding: 0.5rem;
        border-radius: 0.375rem;
        border: none;
        cursor: pointer;
        transition: all 0.2s;
    }
    .btn-edit {
        color: var(--primary-color);
        background-color: var(--primary-color-light);
    }
    .btn-edit:hover {
        background-color: var(--primary-color);
        color: white;
    }
    .btn-delete {
        color: var(--error-color);
        background-color: var(--error-color-light);
    }
    .btn-delete:hover {
        background-color: var(--error-color);
        color: white;
    }
    .bulk-actions {
        margin-top: 1rem;
        padding: 1rem 0;
        border-top: 1px solid var(--border-color);
    }
    </style>
</head>
<body>
    <div class="page-wrapper">
        <?php require_once 'includes/navbar.php'; ?>

        <main class="main-content">
            <div class="container">
                <?php if (!empty($error_message)): ?>
                    <div class="alert alert-error">
                        <i class="fas fa-exclamation-circle"></i>
                        <?php echo htmlspecialchars($error_message); ?>
                    </div>
                <?php endif; ?>
                
                <?php if (!empty($success_message)): ?>
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle"></i>
                        <?php echo htmlspecialchars($success_message); ?>
                    </div>
                <?php endif; ?>

                <div class="card">
                    <div class="card-header">
                        <h1 class="text-2xl font-bold mb-2">
                            <i class="fas fa-tags text-primary"></i>
                            Category Management
                        </h1>
                        <p class="text-secondary">
                            Manage categories for the forum. Categories help organize questions and make them easier to find.
                        </p>
                    </div>

                    <div class="card-body">
                        <!-- 添加分类表单 -->
                        <div class="mb-6">
                            <h2 class="text-xl font-semibold mb-4">Add New Category</h2>
                            <form method="POST" class="flex gap-4">
                                <input type="hidden" name="action" value="add">
                                <div class="flex-1">
                                    <input type="text" name="name" class="form-control" 
                                           placeholder="Enter category name" required>
                                </div>
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-plus"></i>
                                    Add Category
                                </button>
                            </form>
                        </div>

                        <!-- 分类列表 -->
                        <div>
                            <h2 class="text-xl font-semibold mb-4">Categories</h2>
                            <form method="POST" id="categoryForm">
                                <input type="hidden" name="action" id="formAction" value="">
                                <table class="admin-table">
                                    <thead>
                                        <tr>
                                            <th width="40">
                                                <input type="checkbox" id="selectAll" class="form-checkbox">
                                            </th>
                                            <th>Name</th>
                                            <th width="100">Questions</th>
                                            <th width="100">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($categories as $category): ?>
                                        <tr>
                                            <td>
                                                <input type="checkbox" name="ids[]" value="<?php echo $category['id']; ?>" 
                                                       class="form-checkbox">
                                            </td>
                                            <td><?php echo htmlspecialchars($category['name']); ?></td>
                                            <td class="text-center">
                                                <span class="tag"><?php echo $category['question_count']; ?></span>
                                            </td>
                                            <td>
                                                <div class="actions">
                                                    <button type="button" class="btn-icon btn-edit" 
                                                            onclick="editCategory(<?php echo $category['id']; ?>, '<?php echo htmlspecialchars($category['name']); ?>')">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                    <button type="button" class="btn-icon btn-delete" 
                                                            onclick="deleteCategory(<?php echo $category['id']; ?>, <?php echo $category['question_count']; ?>)">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                                <div class="bulk-actions">
                                    <button type="button" class="btn btn-danger" onclick="batchDelete()">
                                        <i class="fas fa-trash"></i>
                                        Delete Selected
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- 编辑分类的模态框 -->
    <div id="editModal" class="modal">
        <div class="modal-content">
            <span class="close">&times;</span>
            <h3 class="text-xl font-semibold mb-4">Edit Category</h3>
            <form method="POST" class="space-y-4">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="id" id="editCategoryId">
                <div class="form-group">
                    <label class="form-label" for="editCategoryName">Category Name</label>
                    <input type="text" name="name" id="editCategoryName" class="form-control" required>
                </div>
                <div class="flex justify-end gap-4">
                    <button type="button" class="btn btn-secondary" onclick="closeModal()">
                        Cancel
                    </button>
                    <button type="submit" class="btn btn-primary">
                        Save Changes
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // 全选功能
        document.getElementById('selectAll').addEventListener('change', function() {
            var checkboxes = document.getElementsByName('ids[]');
            for (var checkbox of checkboxes) {
                checkbox.checked = this.checked;
            }
        });

        // 编辑分类
        function editCategory(id, name) {
            document.getElementById('editCategoryId').value = id;
            document.getElementById('editCategoryName').value = name;
            document.getElementById('editModal').style.display = 'block';
        }

        // 关闭模态框
        function closeModal() {
            document.getElementById('editModal').style.display = 'none';
        }

        // 点击关闭按钮
        document.querySelector('.close').addEventListener('click', closeModal);

        // 点击模态框外部关闭
        window.addEventListener('click', function(event) {
            var modal = document.getElementById('editModal');
            if (event.target == modal) {
                closeModal();
            }
        });

        // 删除分类
        function deleteCategory(id, questionCount) {
            if (questionCount > 0) {
                alert('无法删除该分类，因为还有 ' + questionCount + ' 个问题使用此分类。\n请先移除这些问题的分类标签后再试。');
                return;
            }
            
            if (confirm('确定要删除这个分类吗？')) {
                var form = document.getElementById('categoryForm');
                document.getElementById('formAction').value = 'delete';
                var input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'id';
                input.value = id;
                form.appendChild(input);
                form.submit();
            }
        }

        // 批量删除
        function batchDelete() {
            var checkboxes = document.getElementsByName('ids[]');
            var selected = false;
            for (var checkbox of checkboxes) {
                if (checkbox.checked) {
                    selected = true;
                    break;
                }
            }
            
            if (!selected) {
                alert('请先选择要删除的分类！');
                return;
            }
            
            if (confirm('确定要删除选中的分类吗？')) {
                document.getElementById('formAction').value = 'batch_delete';
                document.getElementById('categoryForm').submit();
            }
        }
    </script>
</body>
</html>
