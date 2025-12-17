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
require_once 'includes/MusicHistory.php';
require_once 'includes/CSRF.php';

$auth = new Auth();
$auth->requireAdmin();

$db = Database::getInstance();
$message = '';

// Handle user actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::validateRequest();
    $action = $_POST['action'] ?? '';
    
    switch ($action) {
        case 'add_user':
            $username = trim($_POST['username'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $password = $_POST['password'] ?? '';
            $is_admin = isset($_POST['is_admin']) ? 1 : 0;
            
            if (empty($username) || empty($email) || empty($password)) {
                $message = 'All fields are required.';
            } else {
                // Check if user exists
                $stmt = $db->query("SELECT id FROM users WHERE username = ? OR email = ?", [$username, $email]);
                if ($stmt->fetch()) {
                    $message = 'Username or email already exists.';
                } else {
                    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                    $db->query(
                        "INSERT INTO users (username, email, password_hash, is_admin) VALUES (?, ?, ?, ?)",
                        [$username, $email, $passwordHash, $is_admin]
                    );
                    
                    // Update .htpasswd
                    $auth = new Auth();
                    $auth->updateHtpasswd($username, $password);
                    
                    $message = 'User added successfully.';
                }
            }
            break;
            
        case 'edit_user':
            $userId = $_POST['user_id'] ?? 0;
            $username = trim($_POST['username'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $password = $_POST['password'] ?? '';
            $is_admin = isset($_POST['is_admin']) ? 1 : 0;
            $is_blocked = isset($_POST['is_blocked']) ? 1 : 0;
            
            if (empty($username) || empty($email)) {
                $message = 'Username and email are required.';
            } else {
                $params = [$username, $email, $is_admin, $is_blocked, $userId];
                $sql = "UPDATE users SET username = ?, email = ?, is_admin = ?, is_blocked = ? WHERE id = ?";
                
                if (!empty($password)) {
                    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                    $sql = "UPDATE users SET username = ?, email = ?, password_hash = ?, is_admin = ?, is_blocked = ? WHERE id = ?";
                    $params = [$username, $email, $passwordHash, $is_admin, $is_blocked, $userId];
                    
                    // Update .htpasswd
                    $auth = new Auth();
                    $auth->updateHtpasswd($username, $password);
                }
                
                $db->query($sql, $params);
                $message = 'User updated successfully.';
            }
            break;
            
        case 'delete_user':
            $userId = $_POST['user_id'] ?? 0;
            if ($userId == $_SESSION['user_id']) {
                $message = 'You cannot delete your own account.';
            } else {
                // Get username before deletion
                $stmt = $db->query("SELECT username FROM users WHERE id = ?", [$userId]);
                $user = $stmt->fetch();
                
                if ($user) {
                    $db->query("DELETE FROM users WHERE id = ?", [$userId]);
                    
                    // Remove from .htpasswd
                    $htpasswd_content = file_get_contents(HTPASSWD_FILE);
                    $lines = explode("\n", $htpasswd_content);
                    $lines = array_filter($lines, function($line) use ($user) {
                        return strpos($line, $user['username'] . ':') !== 0;
                    });
                    file_put_contents(HTPASSWD_FILE, implode("\n", $lines));
                    
                    $message = 'User deleted successfully.';
                } else {
                    $message = 'User not found.';
                }
            }
            break;
            
        case 'send_invite':
            $email = filter_var($_POST['email'], FILTER_VALIDATE_EMAIL);
            if (!$email) {
                $message = 'Please enter a valid email address.';
            } else {
                // Check if user already exists
                $stmt = $db->query("SELECT id FROM users WHERE email = ?", [$email]);
                if ($stmt->fetch()) {
                    $message = 'User with this email already exists.';
                } else {
                    // Create invite
                    $token = bin2hex(random_bytes(32));
                    $expiresAt = date('Y-m-d H:i:s', strtotime('+' . INVITE_EXPIRY_DAYS . ' days'));
                    
                    $db->query(
                        "INSERT INTO invites (invited_by, email, invite_token, expires_at) VALUES (?, ?, ?, ?)",
                        [$_SESSION['user_id'], $email, $token, $expiresAt]
                    );
                    
                    // Send email
                    $inviteUrl = SITE_URL . '/register.php?token=' . $token;
                    $subject = 'Admin Invitation to ' . SITE_NAME;
                    
                    // Read email template
                    $template = file_get_contents(EMAIL_TEMPLATE);
                    $body = str_replace(
                        ['{{SITE_NAME}}', '{{INVITE_URL}}', '{{INVITER_USERNAME}}', '{{EXPIRY_DAYS}}'],
                        [SITE_NAME, $inviteUrl, $_SESSION['username'], INVITE_EXPIRY_DAYS],
                        $template
                    );
                    
                    if (Mailer::send($email, $subject, $body)) {
                        $message = 'Invitation sent successfully!';
                    } else {
                        $message = 'Failed to send invitation email.';
                    }
                }
            }
            break;
            
        case 'send_password_reset':
            $userId = $_POST['user_id'] ?? 0;
            if ($userId) {
                // Get user details
                $stmt = $db->query("SELECT username, email FROM users WHERE id = ?", [$userId]);
                $user = $stmt->fetch();
                
                if ($user) {
                    // Generate reset token
                    $token = bin2hex(random_bytes(32));
                    $expiresAt = date('Y-m-d H:i:s', strtotime('+1 hour')); // Token expires in 1 hour
                    
                    // Store token in database
                    $db->query(
                        "INSERT INTO password_reset_tokens (user_id, token, expires_at) VALUES (?, ?, ?)",
                        [$userId, $token, $expiresAt]
                    );
                    
                    // Send email
                    $resetUrl = SITE_URL . '/reset_password.php?token=' . $token;
                    $subject = 'Password Reset Link - ' . SITE_NAME;
                    
                    $body = "Hello " . $user['username'] . ",\n\n";
                    $body .= "An administrator has sent you a password reset link for " . SITE_NAME . ".\n\n";
                    $body .= "Please click the following link to reset your password:\n";
                    $body .= $resetUrl . "\n\n";
                    $body .= "This link will expire in 1 hour.\n\n";
                    $body .= "If you did not request this password reset, please contact an administrator.\n\n";
                    $body .= "Best regards,\n";
                    $body .= FROM_NAME;
                    
                    if (Mailer::send($user['email'], $subject, $body)) {
                        $message = 'Password reset link sent to ' . $user['username'] . ' successfully!';
                    } else {
                        $message = 'Failed to send password reset email.';
                    }
                } else {
                    $message = 'User not found.';
                }
            } else {
                $message = 'Invalid user ID.';
            }
            break;
    }
}

// Get all users
$stmt = $db->query("SELECT * FROM users ORDER BY created_at DESC");
$users = $stmt->fetchAll();

// Get recent invites
$stmt = $db->query("
    SELECT i.*, u.username as invited_by_username 
    FROM invites i 
    JOIN users u ON i.invited_by = u.id 
    ORDER BY i.created_at DESC 
    LIMIT 20
");
$invites = $stmt->fetchAll();

// Get recent tracks
$recentTracks = MusicHistory::getRecentTracks(10);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Panel - <?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="/styles/main.css">
    <?php MobileSupport::renderMobileStyles(); ?>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="logo">Admin Panel - <?php echo SITE_NAME; ?></div>
            <div class="nav-links">
                <a href="/dashboard.php">Dashboard</a>
                <a href="/file_manager.php">Music Manager</a>
                <a href="/announcer_manager.php">Announcer Manager</a>
                <a href="/specials_manager.php">Specials Manager</a>
                <a href="/specials_schedule.php">Specials Schedule</a>
                <a href="/change_password.php">Change Password</a>
                <a href="/logout.php" class="logout-btn">Logout</a>
            </div>
        </div>
        
        <?php if ($message): ?>
            <div class="message"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>
        
        <div class="main-content">
            <div class="left-column">
                <div class="card">
                    <h2>User Management</h2>
                    <button class="btn btn-success" onclick="showAddUserModal()">Add New User</button>
                    <button class="btn" onclick="showInviteModal()">Send Invite</button>
                    
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Username</th>
                                <th>Email</th>
                                <th>Status</th>
                                <th>Created</th>
                                <th>Last Login</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($users as $user): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($user['username']); ?></td>
                                    <td><?php echo htmlspecialchars($user['email']); ?></td>
                                    <td>
                                        <?php if ($user['is_blocked']): ?>
                                            <span class="status-badge status-blocked">Blocked</span>
                                        <?php elseif ($user['is_admin']): ?>
                                            <span class="status-badge status-admin">Admin</span>
                                        <?php else: ?>
                                            <span class="status-badge status-user">User</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo date('M j, Y', strtotime($user['created_at'])); ?></td>
                                    <td><?php echo $user['last_login'] ? date('M j, Y g:i A', strtotime($user['last_login'])) : 'Never'; ?></td>
                                    <td>
                                        <button class="btn" onclick="editUser(<?php echo htmlspecialchars(json_encode($user)); ?>)">Edit</button>
                                        <button class="btn btn-success" onclick="sendPasswordReset(<?php echo $user['id']; ?>, '<?php echo htmlspecialchars($user['username']); ?>')">Reset Password</button>
                                        <?php if ($user['id'] != $_SESSION['user_id']): ?>
                                            <button class="btn btn-danger" onclick="deleteUser(<?php echo $user['id']; ?>, '<?php echo htmlspecialchars($user['username']); ?>')">Delete</button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <div class="right-column">
                <div class="card">
                    <h2>Recent Invites</h2>
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Email</th>
                                <th>Invited By</th>
                                <th>Status</th>
                                <th>Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($invites as $invite): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($invite['email']); ?></td>
                                    <td><?php echo htmlspecialchars($invite['invited_by_username']); ?></td>
                                    <td>
                                        <?php if ($invite['used_at']): ?>
                                            <span class="status-badge status-admin">Used</span>
                                        <?php elseif (strtotime($invite['expires_at']) < time()): ?>
                                            <span class="status-badge status-blocked">Expired</span>
                                        <?php else: ?>
                                            <span class="status-badge status-user">Pending</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo date('M j', strtotime($invite['created_at'])); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <div class="card">
                    <h2>Recent Tracks <span class="update-indicator" id="updateIndicator"></span></h2>
                    <div id="trackList" style="max-height: 300px; overflow-y: auto;">
                        <?php foreach ($recentTracks as $track): ?>
                            <div class="track-item">
                                <div>
                                    <strong><?php echo htmlspecialchars($track['artist']); ?></strong><br>
                                    <small><?php echo htmlspecialchars($track['title']); ?></small>
                                </div>
                                <small><?php echo MusicHistory::formatTimestamp($track['timestamp']); ?></small>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Add User Modal -->
    <div id="addUserModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal('addUserModal')">&times;</span>
            <h2>Add New User</h2>
            <form method="POST">
                <?php echo CSRF::getTokenInput(); ?>
                <input type="hidden" name="action" value="add_user">
                <div class="form-group">
                    <label>Username:</label>
                    <input type="text" name="username" required>
                </div>
                <div class="form-group">
                    <label>Email:</label>
                    <input type="email" name="email" required>
                </div>
                <div class="form-group">
                    <label>Password:</label>
                    <input type="password" name="password" required>
                </div>
                <div class="form-group">
                    <div class="checkbox-group">
                        <input type="checkbox" name="is_admin" id="add_is_admin">
                        <label for="add_is_admin">Admin User</label>
                    </div>
                </div>
                <button type="submit" class="btn btn-success">Add User</button>
            </form>
        </div>
    </div>
    
    <!-- Edit User Modal -->
    <div id="editUserModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal('editUserModal')">&times;</span>
            <h2>Edit User</h2>
            <form method="POST">
                <?php echo CSRF::getTokenInput(); ?>
                <input type="hidden" name="action" value="edit_user">
                <input type="hidden" name="user_id" id="edit_user_id">
                <div class="form-group">
                    <label>Username:</label>
                    <input type="text" name="username" id="edit_username" required>
                </div>
                <div class="form-group">
                    <label>Email:</label>
                    <input type="email" name="email" id="edit_email" required>
                </div>
                <div class="form-group">
                    <label>New Password (leave blank to keep current):</label>
                    <input type="password" name="password" id="edit_password">
                </div>
                <div class="form-group">
                    <div class="checkbox-group">
                        <input type="checkbox" name="is_admin" id="edit_is_admin">
                        <label for="edit_is_admin">Admin User</label>
                    </div>
                </div>
                <div class="form-group">
                    <div class="checkbox-group">
                        <input type="checkbox" name="is_blocked" id="edit_is_blocked">
                        <label for="edit_is_blocked">Blocked User</label>
                    </div>
                </div>
                <button type="submit" class="btn">Update User</button>
            </form>
        </div>
    </div>
    
    <!-- Invite Modal -->
    <div id="inviteModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal('inviteModal')">&times;</span>
            <h2>Send Invitation</h2>
            <form method="POST">
                <?php echo CSRF::getTokenInput(); ?>
                <input type="hidden" name="action" value="send_invite">
                <div class="form-group">
                    <label>Email Address:</label>
                    <input type="email" name="email" required>
                </div>
                <button type="submit" class="btn btn-success">Send Invite</button>
            </form>
        </div>
    </div>
    
    <!-- Delete Confirmation Modal -->
    <div id="deleteModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal('deleteModal')">&times;</span>
            <h2>Confirm Deletion</h2>
            <p>Are you sure you want to delete user <strong id="delete_username"></strong>?</p>
            <form method="POST">
                <?php echo CSRF::getTokenInput(); ?>
                <input type="hidden" name="action" value="delete_user">
                <input type="hidden" name="user_id" id="delete_user_id">
                <button type="submit" class="btn btn-danger">Yes, Delete</button>
                <button type="button" class="btn" onclick="closeModal('deleteModal')">Cancel</button>
            </form>
        </div>
    </div>
    
    <!-- Password Reset Confirmation Modal -->
    <div id="passwordResetModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal('passwordResetModal')">&times;</span>
            <h2>Send Password Reset Link</h2>
            <p>Send a password reset link to user <strong id="reset_username"></strong>?</p>
            <p><small>The user will receive an email with instructions to reset their password. The link will expire in 1 hour.</small></p>
            <form method="POST">
                <?php echo CSRF::getTokenInput(); ?>
                <input type="hidden" name="action" value="send_password_reset">
                <input type="hidden" name="user_id" id="reset_user_id">
                <button type="submit" class="btn btn-success">Send Reset Link</button>
                <button type="button" class="btn" onclick="closeModal('passwordResetModal')">Cancel</button>
            </form>
        </div>
    </div>
    
    <script>
        function showAddUserModal() {
            document.getElementById('addUserModal').style.display = 'block';
        }
        
        function showInviteModal() {
            document.getElementById('inviteModal').style.display = 'block';
        }
        
        function editUser(user) {
            document.getElementById('edit_user_id').value = user.id;
            document.getElementById('edit_username').value = user.username;
            document.getElementById('edit_email').value = user.email;
            document.getElementById('edit_is_admin').checked = user.is_admin == 1;
            document.getElementById('edit_is_blocked').checked = user.is_blocked == 1;
            document.getElementById('editUserModal').style.display = 'block';
        }
        
        function deleteUser(userId, username) {
            document.getElementById('delete_user_id').value = userId;
            document.getElementById('delete_username').textContent = username;
            document.getElementById('deleteModal').style.display = 'block';
        }
        
        function sendPasswordReset(userId, username) {
            document.getElementById('reset_user_id').value = userId;
            document.getElementById('reset_username').textContent = username;
            document.getElementById('passwordResetModal').style.display = 'block';
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
        
        // Track update functionality
        let lastModified = <?php echo MusicHistory::getLastModified(); ?>;
        let updateInterval;
        
        function updateTracks() {
            fetch(`/api/tracks.php?lastModified=${lastModified}&limit=10`)
                .then(response => response.json())
                .then(data => {
                    if (data.updated) {
                        lastModified = data.lastModified;
                        
                        // Show update indicator
                        const indicator = document.getElementById('updateIndicator');
                        indicator.classList.add('active');
                        
                        // Update track list
                        const trackList = document.getElementById('trackList');
                        if (data.tracks.length === 0) {
                            trackList.innerHTML = '<p>No recent tracks available.</p>';
                        } else {
                            trackList.innerHTML = data.tracks.map(track => `
                                <div class="track-item">
                                    <div>
                                        <strong>${escapeHtml(track.artist)}</strong><br>
                                        <small>${escapeHtml(track.title)}</small>
                                    </div>
                                    <small>${escapeHtml(track.timeAgo)}</small>
                                </div>
                            `).join('');
                        }
                        
                        // Hide indicator after 2 seconds
                        setTimeout(() => {
                            indicator.classList.remove('active');
                        }, 2000);
                    }
                })
                .catch(error => {
                    console.error('Error updating tracks:', error);
                });
        }
        
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        
        // Start periodic updates every 30 seconds
        updateInterval = setInterval(updateTracks, 30000);
        
        // Update when page becomes visible (if user switches tabs)
        document.addEventListener('visibilitychange', function() {
            if (!document.hidden) {
                updateTracks();
            }
        });
        
        // Cleanup on page unload
        window.addEventListener('beforeunload', function() {
            if (updateInterval) {
                clearInterval(updateInterval);
            }
        });
    </script>
</body>
</html>
