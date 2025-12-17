<?php
/**
 * Copyright (c) 2025 Robert Amstadt
 * 
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 * 
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 * 
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 */

require_once 'config.php';
require_once 'includes/Auth.php';
require_once 'includes/CSRF.php';

$auth = new Auth();
$auth->requireAdmin();

$message = '';
$error = '';

$radioPath = RADIO_BASE_DIR;
if (!is_string($radioPath) || $radioPath === '') {
    die('RADIO_BASE_DIR is not configured. Set it in ~/.privateradiomanager/config.php');
}
$specialsDir = 'specials';
$specialsPath = $radioPath . '/' . $specialsDir;

$allowedExtensions = ['mp3', 'wav', 'flac', 'ogg', 'aac', 'm4a'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::validateRequest();
    $action = $_POST['action'] ?? '';

    switch ($action) {
        case 'upload_file':
            if (isset($_FILES['audio_file'])) {
                $uploadErrors = [];
                $successCount = 0;
                $mp3Count = 0;

                if (!is_dir($specialsPath)) {
                    $error = "Specials directory not found: {$specialsPath}";
                    break;
                }

                if (is_array($_FILES['audio_file']['name'])) {
                    $fileCount = count($_FILES['audio_file']['name']);

                    for ($i = 0; $i < $fileCount; $i++) {
                        $uploadedFile = [
                            'name' => $_FILES['audio_file']['name'][$i],
                            'tmp_name' => $_FILES['audio_file']['tmp_name'][$i],
                            'error' => $_FILES['audio_file']['error'][$i],
                            'size' => $_FILES['audio_file']['size'][$i]
                        ];

                        if ($uploadedFile['error'] === UPLOAD_ERR_OK) {
                            $filename = basename($uploadedFile['name']);
                            $fileExtension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

                            if (in_array($fileExtension, $allowedExtensions)) {
                                $targetPath = $specialsPath . '/' . $filename;

                                if (!file_exists($targetPath)) {
                                    if (move_uploaded_file($uploadedFile['tmp_name'], $targetPath)) {
                                        chmod($targetPath, 0644);
                                        $successCount++;
                                        if ($fileExtension === 'mp3') {
                                            $mp3Count++;
                                        }
                                    } else {
                                        $uploadErrors[] = "Failed to upload '{$filename}'";
                                    }
                                } else {
                                    $uploadErrors[] = "'{$filename}' already exists in {$specialsDir}";
                                }
                            } else {
                                $uploadErrors[] = "'{$filename}' has invalid file type";
                            }
                        } else {
                            $uploadErrors[] = "'{$uploadedFile['name']}' upload error: " . $uploadedFile['error'];
                        }
                    }
                } else {
                    $uploadedFile = $_FILES['audio_file'];

                    if ($uploadedFile['error'] === UPLOAD_ERR_OK) {
                        $filename = basename($uploadedFile['name']);
                        $fileExtension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

                        if (in_array($fileExtension, $allowedExtensions)) {
                            $targetPath = $specialsPath . '/' . $filename;

                            if (!file_exists($targetPath)) {
                                if (move_uploaded_file($uploadedFile['tmp_name'], $targetPath)) {
                                    chmod($targetPath, 0644);
                                    $successCount++;
                                    if ($fileExtension === 'mp3') {
                                        $mp3Count++;
                                    }
                                } else {
                                    $uploadErrors[] = "Failed to upload '{$filename}'";
                                }
                            } else {
                                $uploadErrors[] = "'{$filename}' already exists in {$specialsDir}";
                            }
                        } else {
                            $uploadErrors[] = "'{$filename}' has invalid file type";
                        }
                    } else {
                        $uploadErrors[] = "Upload error: " . $uploadedFile['error'];
                    }
                }

                if ($successCount > 0) {
                    $message = "Successfully uploaded {$successCount} special program file(s).";
                    if ($mp3Count > 0) {
                        $message .= " MP3 ID3 tags will be validated when scheduled.";
                    }
                }

                if (!empty($uploadErrors)) {
                    $error = "Some files had issues: " . implode('; ', $uploadErrors);
                }
            } else {
                $error = 'Invalid upload parameters.';
            }
            break;

        case 'delete_file':
            $filename = $_POST['filename'] ?? '';
            $filename = basename($filename);
            if ($filename === '') {
                $error = 'Invalid delete parameters.';
                break;
            }

            $targetPath = $specialsPath . '/' . $filename;
            if (!file_exists($targetPath)) {
                $error = "File '{$filename}' not found.";
                break;
            }

            if (unlink($targetPath)) {
                $message = "Special program '{$filename}' deleted successfully.";
            } else {
                $error = "Failed to delete '{$filename}'.";
            }
            break;
    }
}

$files = [];
if (is_dir($specialsPath)) {
    $dirFiles = scandir($specialsPath);
    foreach ($dirFiles as $file) {
        if ($file === '.' || $file === '..') continue;
        $fullPath = $specialsPath . '/' . $file;
        if (!is_file($fullPath)) continue;
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if (in_array($ext, $allowedExtensions)) {
            $files[] = $file;
        }
    }
    sort($files, SORT_NATURAL | SORT_FLAG_CASE);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Specials Manager - <?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="/styles/main.css">
    <?php MobileSupport::renderMobileStyles(); ?>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="logo">Specials Manager - <?php echo SITE_NAME; ?></div>
            <div class="nav-links">
                <a href="/dashboard.php">Dashboard</a>
                <a href="/admin.php">Admin Panel</a>
                <a href="/file_manager.php">Music Manager</a>
                <a href="/announcer_manager.php">Announcer Manager</a>
                <a href="/specials_schedule.php">Specials Schedule</a>
                <a href="/logout.php" class="logout-btn">Logout</a>
            </div>
        </div>

        <?php if (!empty($message)): ?>
            <div class="message success"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>

        <?php if (!empty($error)): ?>
            <div class="message error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <div class="main-content">
            <div class="card">
                <h2>Upload Special Program</h2>
                <form method="POST" enctype="multipart/form-data">
                    <?php echo CSRF::getTokenInput(); ?>
                    <input type="hidden" name="action" value="upload_file">
                    <div class="form-group">
                        <input type="file" name="audio_file[]" accept="audio/*" multiple required>
                    </div>
                    <button type="submit" class="btn">Upload</button>
                </form>
            </div>

            <div class="card">
                <h2>Uploaded Specials</h2>
                <div class="file-list">
                    <?php if (empty($files)): ?>
                        <div class="file-item">No specials uploaded.</div>
                    <?php else: ?>
                        <?php foreach ($files as $file): ?>
                            <div class="file-item">
                                <div><?php echo htmlspecialchars($file); ?></div>
                                <form method="POST" style="margin: 0;">
                                    <?php echo CSRF::getTokenInput(); ?>
                                    <input type="hidden" name="action" value="delete_file">
                                    <input type="hidden" name="filename" value="<?php echo htmlspecialchars($file); ?>">
                                    <button type="submit" class="btn btn-danger" onclick="return confirm('Delete this special program?');">Delete</button>
                                </form>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
