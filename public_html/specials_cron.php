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
require_once 'includes/Database.php';

$db = Database::getInstance();

$radioPath = RADIO_BASE_DIR;
if (!is_string($radioPath) || $radioPath === '') {
    fwrite(STDERR, "RADIO_BASE_DIR is not configured. Set it in ~/.privateradiomanager/config.php\n");
    exit(1);
}
$specialsDir = 'specials';
$specialsPath = $radioPath . '/' . $specialsDir;

$allowedExtensions = ['mp3', 'flac', 'ogg', 'm4a'];

function logLine($message) {
    $ts = gmdate('Y-m-d H:i:s');
    fwrite(STDOUT, "[$ts] $message\n");
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

function liquidsoapProgramsPush($fullPath) {
    $fp = @fsockopen('127.0.0.1', 1234, $errno, $errstr, 3);
    if (!$fp) {
        return ['success' => false, 'message' => "Telnet connect failed: {$errstr} ({$errno})"];
    }

    stream_set_timeout($fp, 3);

    $cmd = "programs.push " . $fullPath . "\n";
    fwrite($fp, $cmd);

    $response = '';
    while (!feof($fp)) {
        $line = fgets($fp, 4096);
        if ($line === false) break;
        $response .= $line;
        if (strpos($line, "END") !== false) break;
        if (strlen($response) > 8192) break;
    }

    fclose($fp);

    // Liquidsoap telnet replies vary. Treat any response as success unless it contains 'ERROR'
    if (stripos($response, 'error') !== false) {
        return ['success' => false, 'message' => trim($response)];
    }

    return ['success' => true, 'message' => trim($response)];
}

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo "Forbidden";
    exit;
}

if (!is_dir($specialsPath)) {
    logLine("Specials directory not found: {$specialsPath}");
    exit(1);
}

$now = gmdate('Y-m-d H:i:00');
logLine("Checking scheduled specials for {$now} UTC");

$rows = $db->query(
    "SELECT id, filename, full_path, scheduled_at FROM specials_schedule WHERE status = 'scheduled' AND scheduled_at = ? ORDER BY id ASC",
    [$now]
)->fetchAll();

if (empty($rows)) {
    logLine('No due specials.');
    exit(0);
}

foreach ($rows as $row) {
    $id = (int)$row['id'];
    $filename = $row['filename'];
    $fullPath = $row['full_path'];

    logLine("Processing schedule id={$id} file={$filename}");

    if (!file_exists($fullPath)) {
        $db->query("UPDATE specials_schedule SET status='error', last_error=?, queued_at=NOW() WHERE id=?", ['File missing', $id]);
        logLine('ERROR: file missing');
        continue;
    }

    $ext = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
    if (!in_array($ext, $allowedExtensions)) {
        $db->query("UPDATE specials_schedule SET status='error', last_error=?, queued_at=NOW() WHERE id=?", ['Unsupported file type for tag writing', $id]);
        logLine('ERROR: unsupported file type');
        continue;
    }

    if ($ext === 'mp3') {
        $fix = runMp3FixScript($specialsPath);
        if (!$fix['success']) {
            $db->query("UPDATE specials_schedule SET status='error', last_error=?, queued_at=NOW() WHERE id=?", ['ID3 tag fix failed', $id]);
            logLine('ERROR: ID3 tag fix failed');
            continue;
        }
    }

    $artist = ffprobeTag($fullPath, 'artist');
    $title = ffprobeTag($fullPath, 'title');

    if ($artist === null || $title === null) {
        $db->query("UPDATE specials_schedule SET status='error', last_error=?, queued_at=NOW() WHERE id=?", ['Missing artist/title metadata', $id]);
        logLine('ERROR: missing artist/title metadata');
        continue;
    }

    $artistUpdated = ensureSpecialBroadcastSuffix($artist);
    $titleUpdated = ensureSpecialBroadcastSuffix($title);

    $write = writeTagsWithFfmpeg($fullPath, $artistUpdated, $titleUpdated);
    if (!$write['success']) {
        $db->query("UPDATE specials_schedule SET status='error', last_error=?, queued_at=NOW() WHERE id=?", [$write['message'], $id]);
        logLine('ERROR: tag write failed');
        continue;
    }

    $verifyArtist = ffprobeTag($fullPath, 'artist');
    $verifyTitle = ffprobeTag($fullPath, 'title');

    if ($verifyArtist === null || $verifyTitle === null || stripos($verifyArtist, 'special broadcast') === false || stripos($verifyTitle, 'special broadcast') === false) {
        $db->query("UPDATE specials_schedule SET status='error', last_error=?, queued_at=NOW() WHERE id=?", ['Metadata verification failed', $id]);
        logLine('ERROR: metadata verification failed');
        continue;
    }

    $push = liquidsoapProgramsPush($fullPath);
    if (!$push['success']) {
        $db->query("UPDATE specials_schedule SET status='error', last_error=?, queued_at=NOW() WHERE id=?", [$push['message'], $id]);
        logLine('ERROR: liquidsoap push failed');
        continue;
    }

    $db->query("UPDATE specials_schedule SET status='done', queued_at=NOW(), last_error=NULL WHERE id=?", [$id]);
    logLine('Queued successfully and marked as done');
}
