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
require_once 'includes/CSRF.php';

$auth = new Auth();
$auth->requireLogin();

$db = Database::getInstance();
$message = '';
$error = '';

// Handle password change
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::validateRequest();
    $current_password = $_POST['current_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    
    if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
        $error = 'All fields are required.';
    } elseif ($new_password !== $confirm_password) {
        $error = 'New passwords do not match.';
    } elseif (strlen($new_password) < 12) {
        $error = 'New password must be at least 12 characters long.';
    } elseif (!preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&#])[A-Za-z\d@$!%*?&#]/', $new_password)) {
        $error = 'Password must contain at least one uppercase letter, one lowercase letter, one number, and one special character.';
    } else {
        // Verify current password
        $stmt = $db->query("SELECT password_hash, username FROM users WHERE id = ?", [$_SESSION['user_id']]);
        $user = $stmt->fetch();
        
        if (!password_verify($current_password, $user['password_hash'])) {
            $error = 'Current password is incorrect.';
        } else {
            // Update password in database
            $new_password_hash = password_hash($new_password, PASSWORD_DEFAULT);
            $db->query("UPDATE users SET password_hash = ? WHERE id = ?", [$new_password_hash, $_SESSION['user_id']]);
            
            // Update .htpasswd file using Auth class method
            $auth->updateHtpasswd($user['username'], $new_password);
            
            $message = 'Password changed successfully!';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Change Password - <?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="/styles/main.css">
    <link rel="stylesheet" href="/styles/auth.css">
    <?php MobileSupport::renderMobileStyles(); ?>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="logo"><?php echo SITE_NAME; ?></div>
            <div class="subtitle">Change Password</div>
        </div>
        
        <?php if ($message): ?>
            <div class="message success"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="message error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        
        <form method="POST">
            <?php echo CSRF::getTokenInput(); ?>
            <div class="form-group">
                <label for="current_password">Current Password:</label>
                <input type="password" id="current_password" name="current_password" required>
            </div>
            
            <div class="form-group">
                <label for="new_password">New Password:</label>
                <input type="password" id="new_password" name="new_password" required minlength="12">
                <small style="color: #666;">Minimum 12 characters (must include uppercase, lowercase, number, and special character)</small>
            </div>
            
            <div class="form-group">
                <label for="confirm_password">Confirm New Password:</label>
                <input type="password" id="confirm_password" name="confirm_password" required>
            </div>
            
            <button type="submit" class="btn">Change Password</button>
        </form>
        
        <div class="back-link">
            <a href="<?php echo $_SESSION['is_admin'] ? '/admin.php' : '/dashboard.php'; ?>">← Back to <?php echo $_SESSION['is_admin'] ? 'Admin Panel' : 'Dashboard'; ?></a>
        </div>
    </div>
</body>
</html>
