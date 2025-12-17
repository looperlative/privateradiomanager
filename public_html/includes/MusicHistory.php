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

class MusicHistory {
    
    public static function getLastModified() {
        if (!file_exists(PLAYBACK_LOG)) {
            return 0;
        }
        return filemtime(PLAYBACK_LOG);
    }
    
    public static function getRecentTracks($limit = 20) {
        $tracks = [];
        
        if (!file_exists(PLAYBACK_LOG)) {
            return $tracks;
        }
        
        $lines = file(PLAYBACK_LOG, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $lines = array_reverse($lines); // Most recent first
        
        $count = 0;
        foreach ($lines as $line) {
            if ($count >= $limit) break;
            
            $track = self::parseLine($line);
            if ($track && !empty($track['artist']) && !empty($track['title'])) {
                $tracks[] = $track;
                $count++;
            }
        }
        
        return $tracks;
    }
    
    private static function parseLine($line) {
        // Format: 2025-12-14 17:34:07 | Playing: Cocteau Twins - Pearly-Dewdrops' Drops | /path/to/file.mp3
        if (!preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}) \| Playing: (.+?) \| (.+)$/', $line, $matches)) {
            return null;
        }
        
        $timestamp = $matches[1];
        $artistTitle = $matches[2];
        $filepath = $matches[3];
        
        // Skip announcer files and blank audio
        if (strpos($filepath, 'announcers/') !== false || trim($artistTitle) === '' || $artistTitle === ' - ') {
            return null;
        }
        
        // Parse artist and title
        $parts = explode(' - ', $artistTitle, 2);
        if (count($parts) !== 2) {
            return null;
        }
        
        return [
            'timestamp' => $timestamp,
            'artist' => trim($parts[0]),
            'title' => trim($parts[1]),
            'datetime' => DateTime::createFromFormat('Y-m-d H:i:s', $timestamp)
        ];
    }
    
    public static function formatTimestamp($timestamp) {
        $datetime = DateTime::createFromFormat('Y-m-d H:i:s', $timestamp);
        $now = new DateTime();
        $diff = $now->diff($datetime);
        
        if ($diff->days > 0) {
            return $datetime->format('M j, g:i A');
        } elseif ($diff->h > 0) {
            return $diff->h . 'h ' . $diff->i . 'm ago';
        } elseif ($diff->i > 0) {
            return $diff->i . 'm ago';
        } else {
            return 'Just now';
        }
    }
}
?>
