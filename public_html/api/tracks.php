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

require_once '../config.php';
require_once '../includes/Auth.php';
require_once '../includes/MusicHistory.php';

// Set JSON header
header('Content-Type: application/json');

// Check authentication
$auth = new Auth();
if (!$auth->isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// Get request parameters
$lastModified = $_GET['lastModified'] ?? 0;
$limit = min(intval($_GET['limit'] ?? 20), 50); // Max 50 tracks

// Check if file has been modified since last check
$currentModified = MusicHistory::getLastModified();

if ($currentModified <= $lastModified) {
    // No changes
    echo json_encode([
        'updated' => false,
        'lastModified' => $currentModified
    ]);
    exit;
}

// Get updated tracks
$tracks = MusicHistory::getRecentTracks($limit);

// Format tracks for JSON response
$formattedTracks = [];
foreach ($tracks as $track) {
    $formattedTracks[] = [
        'artist' => $track['artist'],
        'title' => $track['title'],
        'timestamp' => $track['timestamp'],
        'timeAgo' => MusicHistory::formatTimestamp($track['timestamp'])
    ];
}

echo json_encode([
    'updated' => true,
    'lastModified' => $currentModified,
    'tracks' => $formattedTracks
]);
?>
