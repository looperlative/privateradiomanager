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
$auth->requireLogin();

$db = Database::getInstance();
$recentTracks = MusicHistory::getRecentTracks(20);

// Get user info
$stmt = $db->query("SELECT username, email FROM users WHERE id = ?", [$_SESSION['user_id']]);
$user = $stmt->fetch();

// Get today's invite count
$stmt = $db->query(
    "SELECT invite_count FROM daily_invites WHERE user_id = ? AND invite_date = CURDATE()",
    [$_SESSION['user_id']]
);
$todayInvites = $stmt->fetch();
$inviteCount = $todayInvites ? $todayInvites['invite_count'] : 0;
$canInvite = $inviteCount < MAX_DAILY_INVITES;

// Handle invite submission
$inviteMessage = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['invite_email'])) {
    CSRF::validateRequest();
    if (!$canInvite) {
        $inviteMessage = 'You have reached your daily invite limit.';
    } else {
        $email = filter_var($_POST['invite_email'], FILTER_VALIDATE_EMAIL);
        if (!$email) {
            $inviteMessage = 'Please enter a valid email address.';
        } else {
            // Check if user already exists
            $stmt = $db->query("SELECT id FROM users WHERE email = ?", [$email]);
            if ($stmt->fetch()) {
                $inviteMessage = 'User with this email already exists.';
            } else {
                // Create invite
                $token = bin2hex(random_bytes(32));
                $expiresAt = date('Y-m-d H:i:s', strtotime('+' . INVITE_EXPIRY_DAYS . ' days'));
                
                $db->query(
                    "INSERT INTO invites (invited_by, email, invite_token, expires_at) VALUES (?, ?, ?, ?)",
                    [$_SESSION['user_id'], $email, $token, $expiresAt]
                );
                
                // Update daily invite count
                $db->query(
                    "INSERT INTO daily_invites (user_id, invite_date, invite_count) VALUES (?, CURDATE(), 1) 
                     ON DUPLICATE KEY UPDATE invite_count = invite_count + 1",
                    [$_SESSION['user_id']]
                );
                
                // Send email
                $inviteUrl = SITE_URL . '/register.php?token=' . $token;
                $subject = 'Invitation to ' . SITE_NAME;
                
                // Read email template
                $template = file_get_contents(EMAIL_TEMPLATE);
                $body = str_replace(
                    ['{{SITE_NAME}}', '{{INVITE_URL}}', '{{INVITER_USERNAME}}', '{{EXPIRY_DAYS}}'],
                    [SITE_NAME, $inviteUrl, $user['username'], INVITE_EXPIRY_DAYS],
                    $template
                );
                
                if (Mailer::send($email, $subject, $body)) {
                    $inviteMessage = 'Invitation sent successfully!';
                    $inviteCount++;
                    $canInvite = $inviteCount < MAX_DAILY_INVITES;
                } else {
                    $inviteMessage = 'Failed to send invitation email.';
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - <?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="/styles/main.css">
    <?php MobileSupport::renderMobileStyles(); ?>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="logo"><?php echo SITE_NAME; ?></div>
            <div class="user-info">
                <span>Welcome, <?php echo htmlspecialchars($user['username']); ?></span>
                <?php if ($_SESSION['is_admin']): ?>
                    <a href="/admin.php" style="color: #3498db; text-decoration: none;">Admin Panel</a>
                    <a href="/file_manager.php" style="color: #3498db; text-decoration: none;">Music Manager</a>
                    <a href="/announcer_manager.php" style="color: #3498db; text-decoration: none;">Announcer Manager</a>
                    <a href="/specials_manager.php" style="color: #3498db; text-decoration: none;">Specials Manager</a>
                    <a href="/specials_schedule.php" style="color: #3498db; text-decoration: none;">Specials Schedule</a>
                <?php endif; ?>
                <a href="/change_password.php" style="color: #3498db; text-decoration: none;">Change Password</a>
                <a href="/logout.php" class="logout-btn">Logout</a>
            </div>
        </div>
        
        <div class="main-content">
            <div class="card">
                <h2>Radio Stream</h2>
                <p>Click the link below to listen to the radio stream:</p>
                <a href="<?php echo STREAM_URL; ?>" class="stream-link" target="_blank">🎵 Listen to Stream</a>
                
                <h3>Invite Friends</h3>
                <p>You can send up to <?php echo MAX_DAILY_INVITES; ?> invites per day. Today: <?php echo $inviteCount; ?>/<?php echo MAX_DAILY_INVITES; ?></p>
                
                <?php if ($canInvite): ?>
                    <form method="POST" class="invite-form">
                        <?php echo CSRF::getTokenInput(); ?>
                        <input type="email" name="invite_email" placeholder="Enter email address" required>
                        <button type="submit" class="invite-btn">Send Invite</button>
                    </form>
                <?php else: ?>
                    <p style="color: #e74c3c;">You have reached your daily invite limit.</p>
                <?php endif; ?>
                
                <?php if ($inviteMessage): ?>
                    <div class="message <?php echo (strpos($inviteMessage, 'successfully') !== false) ? 'success' : 'error'; ?>">
                        <?php echo htmlspecialchars($inviteMessage); ?>
                    </div>
                <?php endif; ?>
            </div>
            
            <div class="card">
                <h2>Recently Played <span class="update-indicator" id="updateIndicator"></span></h2>
                <div class="track-list" id="trackList">
                    <?php if (empty($recentTracks)): ?>
                        <p>No recent tracks available.</p>
                    <?php else: ?>
                        <?php foreach ($recentTracks as $track): ?>
                            <div class="track-item">
                                <div class="track-info">
                                    <div class="track-artist"><?php echo htmlspecialchars($track['artist']); ?></div>
                                    <div class="track-title"><?php echo htmlspecialchars($track['title']); ?></div>
                                </div>
                                <div class="track-time"><?php echo MusicHistory::formatTimestamp($track['timestamp']); ?></div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        // Track update functionality
        let lastModified = <?php echo MusicHistory::getLastModified(); ?>;
        let updateInterval;
        
        function updateTracks() {
            fetch(`/api/tracks.php?lastModified=${lastModified}&limit=20`)
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
                                    <div class="track-info">
                                        <div class="track-artist">${escapeHtml(track.artist)}</div>
                                        <div class="track-title">${escapeHtml(track.title)}</div>
                                    </div>
                                    <div class="track-time">${escapeHtml(track.timeAgo)}</div>
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
