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
require_once 'includes/Database.php';

$auth = new Auth();
$auth->requireAdmin();

$db = Database::getInstance();

$message = '';
$error = '';

$radioPath = RADIO_BASE_DIR;
if (!is_string($radioPath) || $radioPath === '') {
    die('RADIO_BASE_DIR is not configured. Set it in ~/.privateradiomanager/config.php');
}
$specialsDir = 'specials';
$specialsPath = $radioPath . '/' . $specialsDir;

$allowedExtensions = ['mp3', 'wav', 'flac', 'ogg', 'aac', 'm4a'];
$writableExtensions = ['mp3', 'flac', 'ogg', 'm4a'];

function getSpecialFiles($specialsPath, $allowedExtensions) {
    $files = [];
    if (!is_dir($specialsPath)) {
        return $files;
    }
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
    return $files;
}

function ffprobeTag($filePath, $tag) {
    $command = 'ffprobe -v quiet -show_entries format_tags=' . $tag . ' -of csv=p=0 ' . escapeshellarg($filePath) . ' 2>/dev/null';
    $value = trim(shell_exec($command));
    return $value !== '' ? $value : null;
}

function ensureSpecialBroadcastSuffix($value) {
    if ($value === null) return null;
    if (stripos($value, 'special broadcast') !== false) return $value;
    return rtrim($value) . ' - special broadcast';
}

function writeTagsWithFfmpeg($filePath, $artist, $title) {
    $dir = dirname($filePath);
    $base = basename($filePath);
    $ext = strtolower(pathinfo($base, PATHINFO_EXTENSION));
    $tmpPath = $dir . '/.' . $base . '.tmp.' . $ext;

    $command = 'ffmpeg -y -i ' . escapeshellarg($filePath) . ' -codec copy -metadata artist=' . escapeshellarg($artist) . ' -metadata title=' . escapeshellarg($title) . ' ' . escapeshellarg($tmpPath) . ' 2>&1';
    exec($command, $output, $returnCode);
    if ($returnCode !== 0 || !file_exists($tmpPath)) {
        if (file_exists($tmpPath)) {
            unlink($tmpPath);
        }
        $tail = implode("\n", array_slice($output, -30));
        return ['success' => false, 'message' => 'ffmpeg tag write failed: ' . $tail];
    }

    chmod($tmpPath, 0644);
    if (!rename($tmpPath, $filePath)) {
        if (file_exists($tmpPath)) {
            unlink($tmpPath);
        }
        return ['success' => false, 'message' => 'Failed to replace file after tag write'];
    }

    return ['success' => true, 'message' => 'Tags updated'];
}

function runMp3FixScript($directoryPath) {
    $fixScriptPath = FIX_ID3_SCRIPT;
    if (!is_string($fixScriptPath) || $fixScriptPath === '' || !file_exists($fixScriptPath)) {
        return ['success' => false, 'message' => 'ID3 fix script not found'];
    }
    $command = escapeshellcmd($fixScriptPath) . ' ' . escapeshellarg($directoryPath);
    exec($command . ' 2>&1', $output, $returnCode);
    return ['success' => $returnCode === 0, 'message' => implode("\n", $output)];
}

function isFiveMinuteBoundary($timeStr) {
    if (!preg_match('/^(\d{2}):(\d{2})$/', $timeStr, $m)) {
        return false;
    }
    $minute = (int)$m[2];
    return $minute % 5 === 0;
}

function getTimesForDay() {
    $times = [];
    for ($h = 0; $h < 24; $h++) {
        for ($m = 0; $m < 60; $m += 5) {
            $times[] = sprintf('%02d:%02d', $h, $m);
        }
    }
    return $times;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    switch ($action) {
        case 'schedule_special':
            $filename = $_POST['filename'] ?? '';
            $filename = basename($filename);
            $date = trim($_POST['date_utc'] ?? ($_POST['date'] ?? ''));
            $time = trim($_POST['time_utc'] ?? ($_POST['time'] ?? ''));

            if ($filename === '' || $date === '' || $time === '') {
                $error = 'All fields are required.';
                break;
            }

            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                $error = 'Invalid date format.';
                break;
            }

            if (!isFiveMinuteBoundary($time)) {
                $error = 'Time must be on a 5-minute boundary.';
                break;
            }

            if (!is_dir($specialsPath)) {
                $error = "Specials directory not found: {$specialsPath}";
                break;
            }

            $fullPath = $specialsPath . '/' . $filename;
            if (!file_exists($fullPath)) {
                $error = 'Selected file not found.';
                break;
            }

            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            if (!in_array($ext, $allowedExtensions)) {
                $error = 'Invalid file type.';
                break;
            }

            if (!in_array($ext, $writableExtensions)) {
                $error = 'This file type is not supported for scheduling because tags cannot be reliably written.';
                break;
            }

            $scheduledAt = $date . ' ' . $time . ':00';

            $stmt = $db->query(
                "SELECT id FROM specials_schedule WHERE scheduled_at = ? AND status IN ('scheduled','queued')",
                [$scheduledAt]
            );
            if ($stmt->fetch()) {
                $error = 'A special is already scheduled at that time.';
                break;
            }

            if ($ext === 'mp3') {
                $fixResult = runMp3FixScript($specialsPath);
                if (!$fixResult['success']) {
                    $error = 'ID3 tag fix failed; cannot schedule.';
                    break;
                }
            }

            $artist = ffprobeTag($fullPath, 'artist');
            $title = ffprobeTag($fullPath, 'title');

            if ($artist === null || $title === null) {
                $error = 'File must contain both artist and title metadata to be scheduled.';
                break;
            }

            $artistUpdated = ensureSpecialBroadcastSuffix($artist);
            $titleUpdated = ensureSpecialBroadcastSuffix($title);

            $writeResult = writeTagsWithFfmpeg($fullPath, $artistUpdated, $titleUpdated);
            if (!$writeResult['success']) {
                $error = $writeResult['message'];
                break;
            }

            $verifyArtist = ffprobeTag($fullPath, 'artist');
            $verifyTitle = ffprobeTag($fullPath, 'title');

            if ($verifyArtist === null || $verifyTitle === null || stripos($verifyArtist, 'special broadcast') === false || stripos($verifyTitle, 'special broadcast') === false) {
                $error = 'Metadata verification failed after writing tags.';
                break;
            }

            $db->query(
                "INSERT INTO specials_schedule (filename, full_path, scheduled_at, status, created_by) VALUES (?, ?, ?, 'scheduled', ?)",
                [$filename, $fullPath, $scheduledAt, $_SESSION['user_id']]
            );
            $message = 'Special scheduled successfully.';
            break;

        case 'cancel_schedule':
            $scheduleId = (int)($_POST['schedule_id'] ?? 0);
            if ($scheduleId <= 0) {
                $error = 'Invalid schedule entry.';
                break;
            }
            $db->query(
                "UPDATE specials_schedule SET status = 'canceled', canceled_at = NOW() WHERE id = ? AND status = 'scheduled'",
                [$scheduleId]
            );
            $message = 'Schedule entry canceled.';
            break;
    }
}

$files = getSpecialFiles($specialsPath, $allowedExtensions);

$upcoming = $db->query(
    "SELECT ss.id, ss.filename, ss.scheduled_at, ss.status, u.username AS created_by_username\n     FROM specials_schedule ss\n     LEFT JOIN users u ON ss.created_by = u.id\n     WHERE ss.status IN ('scheduled')\n     ORDER BY ss.scheduled_at ASC\n     LIMIT 100"
)->fetchAll();

$times = getTimesForDay();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Specials Schedule - <?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="/styles/main.css">
    <?php MobileSupport::renderMobileStyles(); ?>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="logo">Specials Schedule - <?php echo SITE_NAME; ?></div>
            <div class="nav-links">
                <a href="/dashboard.php">Dashboard</a>
                <a href="/admin.php">Admin Panel</a>
                <a href="/file_manager.php">Music Manager</a>
                <a href="/announcer_manager.php">Announcer Manager</a>
                <a href="/specials_manager.php">Specials Manager</a>
                <a href="/logout.php" class="logout-btn">Logout</a>
            </div>
        </div>

        <?php if (!empty($message)): ?>
            <div class="message success"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>

        <?php if (!empty($error)): ?>
            <div class="message error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <div class="card">
            <h2>Schedule a Special Program (Local Time)</h2>
            <form method="POST" id="schedule-form">
                <input type="hidden" name="action" value="schedule_special">
                <input type="hidden" name="date_utc" id="date_utc" value="">
                <input type="hidden" name="time_utc" id="time_utc" value="">
                <div class="form-grid">
                    <div class="form-group">
                        <label for="filename">Program</label>
                        <select name="filename" id="filename" required>
                            <option value="">Select a program</option>
                            <?php foreach ($files as $file): ?>
                                <option value="<?php echo htmlspecialchars($file); ?>"><?php echo htmlspecialchars($file); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="date">Date (Local)</label>
                        <input type="date" name="date" id="date" required>
                    </div>
                    <div class="form-group">
                        <label for="time">Time (Local, 5-min increments)</label>
                        <select name="time" id="time" required>
                            <option value="">Select time</option>
                            <?php foreach ($times as $t): ?>
                                <option value="<?php echo htmlspecialchars($t); ?>"><?php echo htmlspecialchars($t); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div style="margin-top: 15px;">
                    <button type="submit" class="btn">Schedule</button>
                </div>
                <div class="note">
                    Scheduling enforces writable metadata. Files must contain artist and title tags and will be updated to include "special broadcast".
                </div>
            </form>
        </div>

        <div class="card">
            <h2>Upcoming / Queued</h2>
            <?php if (empty($upcoming)): ?>
                <div>No scheduled specials.</div>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>When (UTC)</th>
                            <th>When (Local)</th>
                            <th>File</th>
                            <th>Status</th>
                            <th>Created By</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($upcoming as $row): ?>
                            <tr>
                                <td><span class="utc-dt" data-utc="<?php echo htmlspecialchars($row['scheduled_at']); ?>"><?php echo htmlspecialchars($row['scheduled_at']); ?></span></td>
                                <td><span class="local-dt" data-utc="<?php echo htmlspecialchars($row['scheduled_at']); ?>">—</span></td>
                                <td><?php echo htmlspecialchars($row['filename']); ?></td>
                                <td><?php echo htmlspecialchars($row['status']); ?></td>
                                <td><?php echo htmlspecialchars($row['created_by_username'] ?? ''); ?></td>
                                <td>
                                    <?php if ($row['status'] === 'scheduled'): ?>
                                        <form method="POST" style="margin:0;">
                                            <input type="hidden" name="action" value="cancel_schedule">
                                            <input type="hidden" name="schedule_id" value="<?php echo (int)$row['id']; ?>">
                                            <button type="submit" class="btn btn-danger" onclick="return confirm('Cancel this schedule entry?');">Cancel</button>
                                        </form>
                                    <?php else: ?>
                                        
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

    <script>
        (function () {
            function pad2(n) {
                return String(n).padStart(2, '0');
            }

            var scheduleForm = document.getElementById('schedule-form');
            if (scheduleForm) {
                scheduleForm.addEventListener('submit', function (e) {
                    var dateEl = document.getElementById('date');
                    var timeEl = document.getElementById('time');
                    var dateUtcEl = document.getElementById('date_utc');
                    var timeUtcEl = document.getElementById('time_utc');

                    if (!dateEl || !timeEl || !dateUtcEl || !timeUtcEl) return;
                    if (!dateEl.value || !timeEl.value) return;

                    var parts = dateEl.value.split('-');
                    if (parts.length !== 3) return;
                    var tParts = timeEl.value.split(':');
                    if (tParts.length !== 2) return;

                    var year = parseInt(parts[0], 10);
                    var month = parseInt(parts[1], 10) - 1;
                    var day = parseInt(parts[2], 10);
                    var hour = parseInt(tParts[0], 10);
                    var minute = parseInt(tParts[1], 10);

                    var localDate = new Date(year, month, day, hour, minute, 0, 0);
                    if (isNaN(localDate.getTime())) return;

                    var utcMinute = localDate.getUTCMinutes();
                    if (utcMinute % 5 !== 0) {
                        e.preventDefault();
                        alert('This local time does not map to a 5-minute UTC boundary. Please choose a different time.');
                        return;
                    }

                    var utcDate = localDate.getUTCFullYear() + '-' + pad2(localDate.getUTCMonth() + 1) + '-' + pad2(localDate.getUTCDate());
                    var utcTime = pad2(localDate.getUTCHours()) + ':' + pad2(localDate.getUTCMinutes());

                    dateUtcEl.value = utcDate;
                    timeUtcEl.value = utcTime;
                });
            }

            function parseUtc(utcString) {
                if (!utcString) return null;
                var iso = utcString.replace(' ', 'T') + 'Z';
                var d = new Date(iso);
                return isNaN(d.getTime()) ? null : d;
            }

            var tz = Intl.DateTimeFormat().resolvedOptions().timeZone;
            var formatter = new Intl.DateTimeFormat(undefined, {
                year: 'numeric', month: '2-digit', day: '2-digit',
                hour: '2-digit', minute: '2-digit', second: '2-digit',
                hour12: false,
                timeZoneName: 'short'
            });

            document.querySelectorAll('.local-dt[data-utc]').forEach(function (el) {
                var utc = el.getAttribute('data-utc');
                var d = parseUtc(utc);
                if (!d) {
                    el.textContent = '—';
                    return;
                }
                el.textContent = formatter.format(d);
                el.setAttribute('title', 'Local time (' + tz + ')');
            });
        })();
    </script>
</body>
</html>
