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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::validateRequest();
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    $recaptcha = $_POST['g-recaptcha-response'] ?? '';
    
    if (empty($username) || empty($password)) {
        header('Location: /index.php?error=' . urlencode('Please fill in all fields'));
        exit;
    }
    
    $auth = new Auth();
    $result = $auth->login($username, $password, $recaptcha);
    
    if ($result['success']) {
        if ($result['is_admin']) {
            header('Location: /admin.php');
        } else {
            header('Location: /dashboard.php');
        }
        exit;
    } else {
        header('Location: /index.php?error=' . urlencode($result['message']));
        exit;
    }
} else {
    header('Location: /index.php');
    exit;
}
?>
