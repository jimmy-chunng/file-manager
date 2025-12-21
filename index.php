<?php
/**
 * Simple & Secure File Manager
 * Author: Gemini Code Assist
 */

// 配置：文件存储目录
define('STORAGE_DIR', __DIR__ . '/storage');
define('MAX_STORAGE_LIMIT', 100 * 1024 * 1024); // 存储限额：100MB

// 初始化存储目录
if (!is_dir(STORAGE_DIR)) {
    mkdir(STORAGE_DIR, 0755, true);
}

class FileManager
{
    private string $baseDir;
    public string $message = '';
    public string $messageType = ''; // success or danger

    public function __construct(string $dir)
    {
        $this->baseDir = $dir;
    }

    /**
     * 处理用户请求
     */
    public function handleRequest(): void
    {
        if (isset($_GET['action']) && $_GET['action'] === 'download') {
            try {
                $this->downloadFile($_GET['filename'] ?? '');
            } catch (Exception $e) {
                $this->message = $e->getMessage();
                $this->messageType = 'danger';
            }
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $action = $_POST['action'] ?? '';
            
            try {
                if ($action === 'create') {
                    $this->createFile($_POST['filename'] ?? '', $_POST['content'] ?? '');
                } elseif ($action === 'delete') {
                    $this->deleteFile($_POST['filename'] ?? '');
                } elseif ($action === 'upload') {
                    $this->uploadFiles($_FILES['uploads'] ?? []);
                }
            } catch (Exception $e) {
                $this->message = $e->getMessage();
                $this->messageType = 'danger';
            }
        }
    }

    /**
     * 获取文件列表
     */
    public function getFiles(): array
    {
        $files = [];
        $scanned = scandir($this->baseDir);
        
        foreach ($scanned as $item) {
            if ($item === '.' || $item === '..') continue;
            
            $path = $this->baseDir . '/' . $item;
            if (is_file($path)) {
                $files[] = [
                    'name' => $item,
                    'size' => $this->formatSize(filesize($path)),
                    'time' => date('Y-m-d H:i:s', filemtime($path))
                ];
            }
        }
        return $files;
    }

    /**
     * 创建文件
     */
    private function createFile(string $filename, string $content): void
    {
        $filename = trim($filename);
        $this->validateFilename($filename);

        $path = $this->baseDir . '/' . $filename;
        
        if (file_exists($path)) {
            throw new Exception("文件 '{$filename}' 已存在。");
        }

        $this->checkStorageQuota(strlen($content));

        if (file_put_contents($path, $content) === false) {
            throw new Exception("无法写入文件，请检查权限。");
        }

        $this->message = "文件 '{$filename}' 创建成功！";
        $this->messageType = 'success';
    }

    /**
     * 删除文件
     */
    private function deleteFile(string $filename): void
    {
        $this->validateFilename($filename);
        $path = $this->baseDir . '/' . $filename;

        if (!file_exists($path)) {
            throw new Exception("文件不存在。");
        }

        if (!unlink($path)) {
            throw new Exception("删除失败，请检查权限。");
        }

        $this->message = "文件 '{$filename}' 已删除。";
        $this->messageType = 'success';
    }

    /**
     * 上传文件
     */
    private function uploadFiles(array $files): void
    {
        if (empty($files['name'][0])) {
            throw new Exception("请选择要上传的文件。");
        }

        $count = count($files['name']);
        $successCount = 0;

        for ($i = 0; $i < $count; $i++) {
            if ($files['error'][$i] === UPLOAD_ERR_OK) {
                $name = $files['name'][$i];
                $tmpName = $files['tmp_name'][$i];
                
                try {
                    $this->validateFilename($name);
                    $this->checkStorageQuota($files['size'][$i]);
                    $destination = $this->baseDir . '/' . $name;
                    if (move_uploaded_file($tmpName, $destination)) {
                        $successCount++;
                    }
                } catch (Exception $e) {
                    // 忽略非法文件，继续处理下一个
                    continue;
                }
            }
        }

        $this->message = "成功上传 {$successCount} 个文件。";
        $this->messageType = 'success';
    }

    /**
     * 下载文件
     */
    private function downloadFile(string $filename): void
    {
        $this->validateFilename($filename);
        $path = $this->baseDir . '/' . $filename;

        if (!file_exists($path)) {
            throw new Exception("文件不存在。");
        }

        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . basename($path) . '"');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . filesize($path));
        readfile($path);
        exit;
    }

    /**
     * 安全验证：检查文件名是否合法
     * 防止目录遍历 (../) 和危险后缀 (.php)
     */
    private function validateFilename(string $filename): void
    {
        if (empty($filename)) {
            throw new Exception("文件名不能为空。");
        }

        // 仅允许字母、数字、点、下划线、中划线
        if (!preg_match('/^[a-zA-Z0-9._-]+$/', $filename)) {
            throw new Exception("文件名包含非法字符。");
        }

        // 禁止目录遍历
        if (strpos($filename, '..') !== false) {
            throw new Exception("非法的文件路径。");
        }

        // 安全检查：禁止创建 PHP 可执行文件
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (in_array($ext, ['php', 'php5', 'phtml', 'exe', 'sh'])) {
            throw new Exception("出于安全考虑，禁止操作此类文件后缀。");
        }
    }

    /**
     * 检查存储配额
     */
    private function checkStorageQuota(int $newSize): void
    {
        $currentUsage = 0;
        $scanned = scandir($this->baseDir);
        
        foreach ($scanned as $item) {
            if ($item === '.' || $item === '..') continue;
            $currentUsage += filesize($this->baseDir . '/' . $item);
        }

        if (($currentUsage + $newSize) > MAX_STORAGE_LIMIT) {
            throw new Exception("存储空间不足！限额: " . $this->formatSize(MAX_STORAGE_LIMIT) . "，当前已用: " . $this->formatSize($currentUsage));
        }
    }

    private function formatSize($bytes): string
    {
        if ($bytes >= 1073741824) return number_format($bytes / 1073741824, 2) . ' GB';
        if ($bytes >= 1048576) return number_format($bytes / 1048576, 2) . ' MB';
        if ($bytes >= 1024) return number_format($bytes / 1024, 2) . ' KB';
        return $bytes . ' bytes';
    }
}

// 实例化并处理
$fm = new FileManager(STORAGE_DIR);
$fm->handleRequest();
$files = $fm->getFiles();

?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>简易文件管理系统</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        body { background-color: #f8f9fa; padding-top: 2rem; }
        .container { max-width: 900px; background: white; padding: 1.5rem; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .action-btn { width: 80px; }
        @media (min-width: 768px) {
            .container { padding: 2rem; }
        }
    </style>
</head>
<body>

<div class="container">
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-center mb-4 gap-3">
        <h3>📂 在线文件管理</h3>
        <div>
            <button class="btn btn-success me-2" data-bs-toggle="modal" data-bs-target="#uploadModal">
                <i class="bi bi-cloud-upload"></i> 上传文件
            </button>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createModal">
                <i class="bi bi-file-earmark-plus"></i> 新建文件
            </button>
        </div>
    </div>

    <!-- 消息提示 -->
    <?php if ($fm->message): ?>
        <div class="alert alert-<?= $fm->messageType ?> alert-dismissible fade show" role="alert">
            <?= htmlspecialchars($fm->message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- 文件列表 -->
    <div class="table-responsive">
    <table class="table table-hover align-middle">
        <thead class="table-light">
            <tr>
                <th>文件名</th>
                <th>大小</th>
                <th>修改时间</th>
                <th class="text-end">操作</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($files)): ?>
                <tr><td colspan="4" class="text-center text-muted py-4">暂无文件</td></tr>
            <?php else: ?>
                <?php foreach ($files as $file): ?>
                <tr>
                    <td>
                        <span class="text-primary">📄 <?= htmlspecialchars($file['name']) ?></span>
                    </td>
                    <td><?= $file['size'] ?></td>
                    <td><?= $file['time'] ?></td>
                    <td class="text-end">
                        <div class="d-flex flex-column flex-md-row gap-2 align-items-end justify-content-md-end">
                            <a href="?action=download&filename=<?= urlencode($file['name']) ?>" class="btn btn-sm btn-outline-primary action-btn"><i class="bi bi-download"></i> 下载</a>
                            <form method="POST" onsubmit="return confirm('确定要删除 <?= htmlspecialchars($file['name']) ?> 吗？');">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="filename" value="<?= htmlspecialchars($file['name']) ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger action-btn"><i class="bi bi-trash"></i> 删除</button>
                            </form>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
    </div>
</div>

<!-- 新建文件模态框 -->
<div class="modal fade" id="createModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header">
                    <h5 class="modal-title">新建文件</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="create">
                    <div class="mb-3">
                        <label class="form-label">文件名 (包含后缀)</label>
                        <input type="text" name="filename" class="form-control" placeholder="example.txt" required>
                        <div class="form-text">禁止使用 .php 后缀</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">文件内容</label>
                        <textarea name="content" class="form-control" rows="5" placeholder="在此输入内容..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                    <button type="submit" class="btn btn-primary">保存</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- 上传文件模态框 -->
<div class="modal fade" id="uploadModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" enctype="multipart/form-data">
                <div class="modal-header">
                    <h5 class="modal-title">上传文件</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="upload">
                    <div class="mb-3">
                        <label class="form-label">选择文件 (支持多选)</label>
                        <input type="file" name="uploads[]" class="form-control" multiple required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                    <button type="submit" class="btn btn-success">开始上传</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
