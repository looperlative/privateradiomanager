# Private Radio Manager
 
 A small PHP/MySQL web application for running a private radio station portal.
 
 It provides:
 - **Authentication** (sessions + optional reCAPTCHA)
 - **Admin user management**
 - **Invite-based registration**
 - **Recently played track list** (from a playback log)
 - **Media management pages** (optional; depends on your radio directory layout)
 
 This repository is intended to be safe for a **public GitHub/GitLab** repo: sensitive values and machine-specific paths are loaded from a private config file in your home directory.
 
 ## Requirements
 
 ### Web stack
 - **PHP** 8.x recommended (7.4+ may work)
 - **Web server**: Apache or Nginx
 - **MySQL/MariaDB**
 
 ### Optional runtime tools
 - **ffmpeg/ffprobe**: used for audio metadata + tag repair flows
 - **cron**: if you use scheduled jobs (e.g. specials processing)
 
 ## Installation
 
 ### 1) Deploy the code
 Place the contents of this repo in your web root (or configure your vhost to point to it).
 
 ### 2) Create the database
 Import the schema:
 
 ```bash
 mysql -u <db_user> -p <db_name> < setup_database.sql
 ```
 
 ### 3) Create the private home config
 Create:
 
 - `~/.privateradiomanager/config.php`
 
 It must return a PHP array. Example:
 
 ```php
 <?php
 return [
   'DB_HOST' => 'localhost',
   'DB_NAME' => 'privateradiomanager',
   'DB_USER' => 'privateradiomanager',
   'DB_PASS' => 'your_password_here',
 
   'SITE_NAME' => 'Your Site Name',
   'SITE_URL'  => 'https://example.com',
 
   // Optional (recommended): reCAPTCHA v3
   'RECAPTCHA_SITE_KEY'   => '...',
   'RECAPTCHA_SECRET_KEY' => '...',
 
   // Paths on your server
   'HTPASSWD_FILE' => '/path/to/.htpasswd',
   'PLAYBACK_LOG'  => '/path/to/playback.log',
   'RADIO_BASE_DIR' => '/path/to/Radio',
 
   // Optional overrides
   'METADATA_CACHE_PATH' => '/tmp/radio_metadata_cache.json',
 
   // Email settings used for invites/resets
   'FROM_EMAIL' => 'no-reply@example.com',
   'FROM_NAME'  => 'Your Email Name',
 ];
 ```
 
 Recommended permissions:
 
 ```bash
 mkdir -p ~/.privateradiomanager
 chmod 700 ~/.privateradiomanager
 chmod 600 ~/.privateradiomanager/config.php
 ```
 
 ### 4) Optional: login background image
 
 If you place a file here:
 - `~/.privateradiomanager/background.jpg`
 
 then the login page will automatically load it via the `background.php` endpoint.
 If it does not exist, the login page falls back to a plain color background.
 
 ### 5) Optional: reCAPTCHA setup
 
 Create keys at:
 - https://www.google.com/recaptcha/admin/create
 
 Put the keys in `~/.privateradiomanager/config.php` (`RECAPTCHA_SITE_KEY` / `RECAPTCHA_SECRET_KEY`).
 
 ## Notes
 
 ### ID3 fix script
 This repo includes `fix_id3_tags.sh` in the project directory. By default the app will use:
 - `public_html/fix_id3_tags.sh`
 
 (You can override this via the private config if needed.)
 
 ### Security
 - Keep `~/.privateradiomanager/config.php` private (not in the repo).
 - Restrict access to admin endpoints using your normal authentication policies.
 - Make sure your web server does not expose sensitive directories.
 
 ## Troubleshooting
 
 - If pages error with missing config values, confirm `~/.privateradiomanager/config.php` exists and is readable by the PHP process.
 - For DB issues, verify `DB_*` values and that MySQL is reachable.
 - For email issues, check your server mail transport/logs.
