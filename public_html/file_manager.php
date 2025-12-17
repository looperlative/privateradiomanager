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

// Define radio directories
$radioPath = RADIO_BASE_DIR;
if (!is_string($radioPath) || $radioPath === '') {
    die('RADIO_BASE_DIR is not configured. Set it in ~/.privateradiomanager/config.php');
}
$directories = ['heavy', 'medium', 'light', 'inactive'];

// Allowed audio file extensions
$allowedExtensions = ['mp3', 'wav', 'flac', 'ogg', 'aac', 'm4a'];

// Handle file operations
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::validateRequest();
    $action = $_POST['action'] ?? '';
    
    switch ($action) {
        case 'move_file':
            $sourceDir = $_POST['source_dir'] ?? '';
            $targetDir = $_POST['target_dir'] ?? '';
            $filename = $_POST['filename'] ?? '';
            
            if (in_array($sourceDir, $directories) && in_array($targetDir, $directories) && !empty($filename)) {
                $sourcePath = $radioPath . '/' . $sourceDir . '/' . $filename;
                $targetPath = $radioPath . '/' . $targetDir . '/' . $filename;
                
                if (file_exists($sourcePath)) {
                    if (!file_exists($targetPath)) {
                        if (rename($sourcePath, $targetPath)) {
                            // Invalidate cache for old path and update for new path
                            invalidateCacheEntry($sourcePath);
                            $message = "File '{$filename}' moved from {$sourceDir} to {$targetDir} successfully.";
                        } else {
                            $error = "Failed to move file '{$filename}'.";
                        }
                    } else {
                        // File already exists in target, just delete from source
                        if (unlink($sourcePath)) {
                            // Invalidate cache for deleted file
                            invalidateCacheEntry($sourcePath);
                            $message = "File '{$filename}' already exists in {$targetDir}. Removed duplicate from {$sourceDir}.";
                        } else {
                            $error = "File '{$filename}' already exists in {$targetDir}, but failed to remove duplicate from {$sourceDir}.";
                        }
                    }
                } else {
                    $error = "Source file '{$filename}' not found.";
                }
            } else {
                $error = "Invalid move operation parameters.";
            }
            break;
            
            
        case 'upload_file':
            $targetDir = $_POST['target_directory'] ?? '';
            
            if (in_array($targetDir, $directories) && isset($_FILES['audio_file'])) {
                $uploadResults = [];
                $uploadErrors = [];
                $successCount = 0;
                $mp3Count = 0;
                
                // Handle multiple files
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
                                $targetPath = $radioPath . '/' . $targetDir . '/' . $filename;
                                
                                if (!file_exists($targetPath)) {
                                    if (move_uploaded_file($uploadedFile['tmp_name'], $targetPath)) {
                                        // Set proper permissions
                                        chmod($targetPath, 0644);
                                        $uploadResults[] = $filename;
                                        $successCount++;
                                        if ($fileExtension === 'mp3') {
                                            $mp3Count++;
                                        }
                                    } else {
                                        $uploadErrors[] = "Failed to upload '{$filename}'";
                                    }
                                } else {
                                    $uploadErrors[] = "'{$filename}' already exists in {$targetDir}";
                                }
                            } else {
                                $uploadErrors[] = "'{$filename}' has invalid file type";
                            }
                        } else {
                            $uploadErrors[] = "'{$uploadedFile['name']}' upload error: " . $uploadedFile['error'];
                        }
                    }
                } else {
                    // Handle single file (backward compatibility)
                    $uploadedFile = $_FILES['audio_file'];
                    
                    if ($uploadedFile['error'] === UPLOAD_ERR_OK) {
                        $filename = basename($uploadedFile['name']);
                        $fileExtension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                        
                        if (in_array($fileExtension, $allowedExtensions)) {
                            $targetPath = $radioPath . '/' . $targetDir . '/' . $filename;
                            
                            if (!file_exists($targetPath)) {
                                if (move_uploaded_file($uploadedFile['tmp_name'], $targetPath)) {
                                    chmod($targetPath, 0644);
                                    $uploadResults[] = $filename;
                                    $successCount++;
                                    if ($fileExtension === 'mp3') {
                                        $mp3Count++;
                                    }
                                } else {
                                    $uploadErrors[] = "Failed to upload '{$filename}'";
                                }
                            } else {
                                $uploadErrors[] = "'{$filename}' already exists in {$targetDir}";
                            }
                        } else {
                            $uploadErrors[] = "'{$filename}' has invalid file type";
                        }
                    } else {
                        $uploadErrors[] = "Upload error: " . $uploadedFile['error'];
                    }
                }
                
                // Run ID3 tag fix script if MP3 files were uploaded
                if ($mp3Count > 0) {
                    $fixScriptPath = FIX_ID3_SCRIPT;
                    if (is_string($fixScriptPath) && $fixScriptPath !== '' && file_exists($fixScriptPath)) {
                        $command = escapeshellcmd($fixScriptPath) . ' ' . escapeshellarg($radioPath . '/' . $targetDir);
                        exec($command . ' 2>&1', $output, $returnCode);
                    }
                }
                
                // Generate result messages
                if ($successCount > 0) {
                    $message = "Successfully uploaded {$successCount} file(s) to {$targetDir}.";
                    if ($mp3Count > 0) {
                        $message .= " ID3 tags have been processed for {$mp3Count} MP3 file(s).";
                    }
                }
                
                if (!empty($uploadErrors)) {
                    $error = "Some files had issues: " . implode('; ', $uploadErrors);
                }
            } else {
                $error = "Invalid upload parameters.";
            }
            break;
    }
}

// Metadata cache management
function getMetadataCachePath() {
    return METADATA_CACHE_PATH;
}

function loadMetadataCache() {
    $cachePath = getMetadataCachePath();
    if (file_exists($cachePath)) {
        $cacheData = json_decode(file_get_contents($cachePath), true);
        return is_array($cacheData) ? $cacheData : [];
    }
    return [];
}

function saveMetadataCache($cache) {
    $cachePath = getMetadataCachePath();
    file_put_contents($cachePath, json_encode($cache, JSON_PRETTY_PRINT));
}

function invalidateCacheEntry($filePath) {
    $cache = loadMetadataCache();
    if (isset($cache[$filePath])) {
        unset($cache[$filePath]);
        saveMetadataCache($cache);
    }
}

// Function to extract artist from audio file metadata with caching
function getArtistFromFile($filePath) {
    $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
    
    // Only try to extract metadata from supported audio formats
    if (!in_array($extension, ['mp3', 'flac', 'ogg', 'm4a'])) {
        return null;
    }
    
    // Check if file exists
    if (!file_exists($filePath)) {
        return null;
    }
    
    $fileModTime = filemtime($filePath);
    $cache = loadMetadataCache();
    
    // Check if we have cached data for this file
    if (isset($cache[$filePath]) && $cache[$filePath]['mtime'] == $fileModTime) {
        return $cache[$filePath]['artist'];
    }
    
    // Extract metadata using ffprobe
    $command = 'ffprobe -v quiet -show_entries format_tags=artist -of csv=p=0 ' . escapeshellarg($filePath) . ' 2>/dev/null';
    $artist = trim(shell_exec($command));
    $artist = !empty($artist) ? $artist : null;
    
    // Cache the result
    $cache[$filePath] = [
        'artist' => $artist,
        'mtime' => $fileModTime
    ];
    
    // Clean up cache entries for files that no longer exist
    foreach ($cache as $cachedPath => $data) {
        if (!file_exists($cachedPath)) {
            unset($cache[$cachedPath]);
        }
    }
    
    saveMetadataCache($cache);
    
    return $artist;
}

// Get files from each directory
function getDirectoryFiles($directory) {
    global $radioPath, $allowedExtensions;
    $files = [];
    $dirPath = $radioPath . '/' . $directory;
    
    if (is_dir($dirPath)) {
        $items = scandir($dirPath);
        foreach ($items as $item) {
            if ($item !== '.' && $item !== '..' && is_file($dirPath . '/' . $item)) {
                $extension = strtolower(pathinfo($item, PATHINFO_EXTENSION));
                if (in_array($extension, $allowedExtensions)) {
                    $filePath = $dirPath . '/' . $item;
                    $artist = getArtistFromFile($filePath);
                    
                    $files[] = [
                        'name' => $item,
                        'artist' => $artist,
                        'size' => filesize($filePath),
                        'modified' => filemtime($filePath)
                    ];
                }
            }
        }
    }
    
    // Sort by artist, then by name
    usort($files, function($a, $b) {
        // If both have artists, sort by artist first, then by name
        if ($a['artist'] && $b['artist']) {
            $artistCompare = strcmp($a['artist'], $b['artist']);
            if ($artistCompare !== 0) {
                return $artistCompare;
            }
        }
        // If only one has artist, put it first
        if ($a['artist'] && !$b['artist']) {
            return -1;
        }
        if (!$a['artist'] && $b['artist']) {
            return 1;
        }
        // Fall back to filename sorting
        return strcmp($a['name'], $b['name']);
    });
    
    return $files;
}

function formatFileSize($bytes) {
    if ($bytes >= 1073741824) {
        return number_format($bytes / 1073741824, 2) . ' GB';
    } elseif ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 2) . ' MB';
    } elseif ($bytes >= 1024) {
        return number_format($bytes / 1024, 2) . ' KB';
    } else {
        return $bytes . ' bytes';
    }
}

$directoryFiles = [];
foreach ($directories as $dir) {
    $directoryFiles[$dir] = getDirectoryFiles($dir);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Music Manager - <?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="/styles/main.css">
    <link rel="stylesheet" href="/styles/manager.css">
    <?php MobileSupport::renderMobileStyles(); ?>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="logo">Music Manager - <?php echo SITE_NAME; ?></div>
            <div class="nav-links">
                <a href="/dashboard.php">Dashboard</a>
                <a href="/admin.php">Admin Panel</a>
                <a href="/announcer_manager.php">Announcer Manager</a>
                <a href="/specials_manager.php">Specials Manager</a>
                <a href="/specials_schedule.php">Specials Schedule</a>
                <a href="/logout.php" class="logout-btn">Logout</a>
            </div>
        </div>
        
        <?php if ($message): ?>
            <div class="message success"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="message error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        
        <div class="card">
            <h2>Radio Playlist Management</h2>
            
            <!-- Statistics -->
            <div class="stats">
                <?php foreach ($directories as $dir): ?>
                    <div class="stat-card">
                        <div class="stat-number"><?php echo count($directoryFiles[$dir]); ?></div>
                        <div class="stat-label"><?php echo ucfirst($dir); ?> Files</div>
                    </div>
                <?php endforeach; ?>
            </div>
            
            <!-- Upload Section -->
            <div class="upload-section">
                <h3>Upload Audio Files</h3>
                <form method="POST" enctype="multipart/form-data">
                    <?php echo CSRF::getTokenInput(); ?>
                    <input type="hidden" name="action" value="upload_file">
                    <div class="upload-grid">
                        <div class="form-group">
                            <label>Select Audio Files (multiple allowed):</label>
                            <input type="file" name="audio_file[]" accept=".mp3,.wav,.flac,.ogg,.aac,.m4a" multiple required>
                        </div>
                        <div class="form-group">
                            <label>Target Playlist:</label>
                            <select name="target_directory" required>
                                <option value="">Select Playlist</option>
                                <?php foreach ($directories as $dir): ?>
                                    <option value="<?php echo $dir; ?>"><?php echo ucfirst($dir); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <button type="submit" class="btn btn-success">Upload Files</button>
                        </div>
                    </div>
                </form>
                <p class="upload-note">
                    Allowed formats: <?php echo implode(', ', array_map('strtoupper', $allowedExtensions)); ?>
                </p>
            </div>
        </div>
        
        <!-- Directory Files -->
        <div class="directory-grid">
            <?php foreach ($directories as $directory): ?>
                <div class="directory-card">
                    <div class="directory-header">
                        <?php echo ucfirst($directory); ?> Playlist (<?php echo count($directoryFiles[$directory]); ?> files)
                    </div>
                    <div class="directory-content">
                        <?php if (!empty($directoryFiles[$directory])): ?>
                            <?php foreach ($directoryFiles[$directory] as $file): ?>
                                <div class="file-item" draggable="true" data-source-directory="<?php echo htmlspecialchars($directory, ENT_QUOTES); ?>" data-filename="<?php echo htmlspecialchars($file['name'], ENT_QUOTES); ?>">
                                    <div class="file-info">
                                        <div class="file-name">
                                            <?php if ($file['artist']): ?>
                                                <span class="artist-name"><?php echo htmlspecialchars($file['artist']); ?></span> - <?php echo htmlspecialchars($file['name']); ?>
                                            <?php else: ?>
                                                <?php echo htmlspecialchars($file['name']); ?>
                                            <?php endif; ?>
                                        </div>
                                        <div class="file-meta">
                                            <?php echo formatFileSize($file['size']); ?> • 
                                            <?php echo date('M j, Y g:i A', $file['modified']); ?>
                                        </div>
                                    </div>
                                    <div class="file-actions">
                                        <button class="btn btn-small move-btn" data-directory="<?php echo htmlspecialchars($directory, ENT_QUOTES); ?>" data-filename="<?php echo htmlspecialchars($file['name'], ENT_QUOTES); ?>">Move</button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        <div class="drop-zone" data-target-directory="<?php echo $directory; ?>">
                            <?php if (empty($directoryFiles[$directory])): ?>
                                Drop music files here or no files found
                            <?php else: ?>
                                Drop music files here
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    
    <!-- Move File Modal -->
    <div id="moveModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal('moveModal')">&times;</span>
            <h2>Move Audio File</h2>
            <form method="POST">
                <?php echo CSRF::getTokenInput(); ?>
                <input type="hidden" name="action" value="move_file">
                <input type="hidden" name="source_dir" id="move_source_dir">
                <input type="hidden" name="filename" id="move_filename">
                <div class="form-group">
                    <label>File:</label>
                    <input type="text" id="move_file_display" readonly style="background: #f8f9fa;">
                </div>
                <div class="form-group">
                    <label>From:</label>
                    <input type="text" id="move_source_display" readonly style="background: #f8f9fa;">
                </div>
                <div class="form-group">
                    <label>To Playlist:</label>
                    <select name="target_dir" id="move_target_dir" required>
                        <option value="">Select Target Playlist</option>
                        <?php foreach ($directories as $dir): ?>
                            <option value="<?php echo $dir; ?>"><?php echo ucfirst($dir); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="btn btn-success">Move File</button>
                <button type="button" class="btn" onclick="closeModal('moveModal')">Cancel</button>
            </form>
        </div>
    </div>
    
    
    <script>
        function showMoveModal(sourceDir, filename) {
            console.log('showMoveModal called with:', sourceDir, filename);
            
            // Check if elements exist
            const modal = document.getElementById('moveModal');
            if (!modal) {
                console.error('moveModal element not found');
                return;
            }
            
            const sourceDirInput = document.getElementById('move_source_dir');
            const filenameInput = document.getElementById('move_filename');
            const fileDisplayInput = document.getElementById('move_file_display');
            const sourceDisplayInput = document.getElementById('move_source_display');
            const targetSelect = document.getElementById('move_target_dir');
            
            if (!sourceDirInput || !filenameInput || !fileDisplayInput || !sourceDisplayInput || !targetSelect) {
                console.error('One or more modal form elements not found');
                return;
            }
            
            sourceDirInput.value = sourceDir;
            filenameInput.value = filename;
            fileDisplayInput.value = filename;
            sourceDisplayInput.value = sourceDir.charAt(0).toUpperCase() + sourceDir.slice(1);
            
            // Reset target selection
            targetSelect.value = '';
            
            // Remove source directory from target options
            for (let option of targetSelect.options) {
                option.style.display = option.value === sourceDir ? 'none' : 'block';
            }
            
            modal.style.display = 'block';
            console.log('Modal should now be visible');
        }
        
        
        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }
        
        // Close modal when clicking outside
        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.style.display = 'none';
            }
        }
        
        // Add event listeners for move buttons
        document.addEventListener('DOMContentLoaded', function() {
            const moveButtons = document.querySelectorAll('.move-btn');
            moveButtons.forEach(button => {
                button.addEventListener('click', function() {
                    const directory = this.getAttribute('data-directory');
                    const filename = this.getAttribute('data-filename');
                    showMoveModal(directory, filename);
                });
            });
            
            // Initialize drag and drop
            initializeDragAndDrop();
        });
        
        function initializeDragAndDrop() {
            const fileItems = document.querySelectorAll('.file-item[draggable="true"]');
            const dropZones = document.querySelectorAll('.drop-zone');
            const directoryCards = document.querySelectorAll('.directory-card');
            
            // Drag start
            fileItems.forEach(item => {
                item.addEventListener('dragstart', function(e) {
                    this.classList.add('dragging');
                    e.dataTransfer.setData('text/plain', JSON.stringify({
                        sourceDirectory: this.getAttribute('data-source-directory'),
                        filename: this.getAttribute('data-filename')
                    }));
                    e.dataTransfer.effectAllowed = 'move';
                });
                
                item.addEventListener('dragend', function(e) {
                    this.classList.remove('dragging');
                    // Remove drag-over class from all cards
                    directoryCards.forEach(card => card.classList.remove('drag-over'));
                    dropZones.forEach(zone => zone.classList.remove('drag-over'));
                });
            });
            
            // Drop zones
            dropZones.forEach(zone => {
                zone.addEventListener('dragover', function(e) {
                    e.preventDefault();
                    e.dataTransfer.dropEffect = 'move';
                    this.classList.add('drag-over');
                    this.closest('.directory-card').classList.add('drag-over');
                });
                
                zone.addEventListener('dragleave', function(e) {
                    // Only remove if we're actually leaving the zone
                    if (!this.contains(e.relatedTarget)) {
                        this.classList.remove('drag-over');
                        this.closest('.directory-card').classList.remove('drag-over');
                    }
                });
                
                zone.addEventListener('drop', function(e) {
                    e.preventDefault();
                    this.classList.remove('drag-over');
                    this.closest('.directory-card').classList.remove('drag-over');
                    
                    const data = JSON.parse(e.dataTransfer.getData('text/plain'));
                    const targetDirectory = this.getAttribute('data-target-directory');
                    
                    if (data.sourceDirectory !== targetDirectory) {
                        moveFile(data.sourceDirectory, targetDirectory, data.filename);
                    }
                });
            });
            
            // Directory cards as drop targets
            directoryCards.forEach(card => {
                card.addEventListener('dragover', function(e) {
                    e.preventDefault();
                    e.dataTransfer.dropEffect = 'move';
                });
            });
        }
        
        function moveFile(sourceDir, targetDir, filename) {
            // Create a form and submit it
            const form = document.createElement('form');
            form.method = 'POST';
            form.style.display = 'none';
            
            const csrfToken = document.querySelector('input[name="csrf_token"]');
            if (csrfToken) {
                const csrfInput = document.createElement('input');
                csrfInput.type = 'hidden';
                csrfInput.name = 'csrf_token';
                csrfInput.value = csrfToken.value;
                form.appendChild(csrfInput);
            }
            
            const actionInput = document.createElement('input');
            actionInput.type = 'hidden';
            actionInput.name = 'action';
            actionInput.value = 'move_file';
            
            const sourceDirInput = document.createElement('input');
            sourceDirInput.type = 'hidden';
            sourceDirInput.name = 'source_dir';
            sourceDirInput.value = sourceDir;
            
            const targetDirInput = document.createElement('input');
            targetDirInput.type = 'hidden';
            targetDirInput.name = 'target_dir';
            targetDirInput.value = targetDir;
            
            const filenameInput = document.createElement('input');
            filenameInput.type = 'hidden';
            filenameInput.name = 'filename';
            filenameInput.value = filename;
            
            form.appendChild(actionInput);
            form.appendChild(sourceDirInput);
            form.appendChild(targetDirInput);
            form.appendChild(filenameInput);
            
            document.body.appendChild(form);
            form.submit();
        }
        
        // Form validation
        document.querySelector('form[enctype="multipart/form-data"]').addEventListener('submit', function(e) {
            const fileInput = this.querySelector('input[type="file"]');
            const directorySelect = this.querySelector('select[name="target_directory"]');
            
            if (!fileInput.files.length) {
                alert('Please select a file to upload.');
                e.preventDefault();
                return;
            }
            
            if (!directorySelect.value) {
                alert('Please select a target playlist.');
                e.preventDefault();
                return;
            }
            
            // Check file size (limit to 100MB)
            const maxSize = 100 * 1024 * 1024; // 100MB in bytes
            if (fileInput.files[0].size > maxSize) {
                alert('File size must be less than 100MB.');
                e.preventDefault();
                return;
            }
        });
    </script>
</body>
</html>
