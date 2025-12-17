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

$home = getenv('HOME');
if (!$home && function_exists('posix_getuid') && function_exists('posix_getpwuid')) {
    $pw = posix_getpwuid(posix_getuid());
    if (is_array($pw) && isset($pw['dir'])) {
        $home = $pw['dir'];
    }
}

if (!$home) {
    http_response_code(404);
    exit;
}

$path = rtrim($home, '/') . '/.privateradiomanager/background.jpg';
if (!is_file($path)) {
    http_response_code(404);
    exit;
}

$size = filesize($path);
if ($size === false || $size <= 0) {
    http_response_code(404);
    exit;
}

$mtime = filemtime($path);
if ($mtime !== false) {
    $etag = 'W/"' . sha1($path . '|' . $size . '|' . $mtime) . '"';
    header('ETag: ' . $etag);
    header('Cache-Control: private, max-age=3600');

    $ifNoneMatch = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';
    if ($ifNoneMatch === $etag) {
        http_response_code(304);
        exit;
    }
}

header('Content-Type: image/jpeg');
header('Content-Length: ' . $size);
readfile($path);
