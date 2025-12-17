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

// Check if reCAPTCHA is enabled
$auth = new Auth();
$recaptcha_enabled = $auth->isRecaptchaEnabled();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(SITE_NAME ?: 'Login'); ?></title>
    <link rel="icon" type="image/x-icon" href="favicon.ico">
    <link rel="stylesheet" href="/styles/main.css">
    <link rel="stylesheet" href="/styles/login.css">
    <?php MobileSupport::renderMobileStyles(); ?>
    <?php if ($recaptcha_enabled): ?>
        <script src="https://www.google.com/recaptcha/api.js?render=<?php echo RECAPTCHA_SITE_KEY; ?>"></script>
    <?php endif; ?>
</head>
<body>
    <div class="container">
        <div class="logo"><?php echo htmlspecialchars(SITE_NAME ?: 'Login'); ?></div>
        
        <button class="show-login-btn" onclick="showLoginForm()">Login</button>
        
        <div id="login-form" class="login-form">
            <form method="POST" action="login.php">
                <?php echo CSRF::getTokenInput(); ?>
                <div class="form-group">
                    <label for="username">Username:</label>
                    <input type="text" id="username" name="username" required>
                </div>
                
                <div class="form-group">
                    <label for="password">Password:</label>
                    <input type="password" id="password" name="password" required>
                </div>
                
                <button type="submit" class="login-btn">Login</button>
            </form>
            
            <div style="text-align: center; margin-top: 15px;">
                <a href="/forgot_password.php" style="color: #3498db; text-decoration: none; font-size: 14px;">Forgot your password?</a>
            </div>
            
            <?php if (isset($_GET['error'])): ?>
                <div class="error">
                    <?php echo htmlspecialchars($_GET['error']); ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <script>
        function showLoginForm() {
            document.querySelector('.show-login-btn').style.display = 'none';
            document.getElementById('login-form').style.display = 'block';
        }
        
        <?php if ($recaptcha_enabled): ?>
        // Handle reCAPTCHA v3 form submission
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.querySelector('#login-form form');
            if (form) {
                form.addEventListener('submit', function(e) {
                    e.preventDefault();
                    
                    grecaptcha.ready(function() {
                        grecaptcha.execute('<?php echo RECAPTCHA_SITE_KEY; ?>', {action: 'login'}).then(function(token) {
                            // Add the token to the form
                            let tokenInput = document.createElement('input');
                            tokenInput.type = 'hidden';
                            tokenInput.name = 'g-recaptcha-response';
                            tokenInput.value = token;
                            form.appendChild(tokenInput);
                            
                            // Submit the form
                            form.submit();
                        });
                    });
                });
            }
        });
        <?php endif; ?>
    </script>
</body>
</html>
