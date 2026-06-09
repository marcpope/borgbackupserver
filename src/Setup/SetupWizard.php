<?php
/**
 * BBS Setup Wizard — self-contained installer that runs before the app is bootstrapped.
 * Activated when config/.env does not exist.
 *
 * This file handles its own session, routing, rendering, and DB operations
 * without depending on App, Config, Database, or Controller classes.
 */

// Match the App's session cookie params so session persists after wizard
session_set_cookie_params([
    'lifetime' => 30 * 86400,
    'path' => '/',
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

$basePath = dirname(__DIR__, 2);

// Safety: if .env already exists, redirect to the app
if (file_exists($basePath . '/config/.env')) {
    header('Location: /');
    exit;
}

// Handle form submissions and determine current step
$step = (int) ($_POST['step'] ?? $_GET['step'] ?? 1);
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    switch ($step) {
        case 2:
            // Database connection test
            $dbHost = trim($_POST['db_host'] ?? 'localhost');
            $dbName = trim($_POST['db_name'] ?? 'bbs');
            $dbUser = trim($_POST['db_user'] ?? 'root');
            $dbPass = $_POST['db_pass'] ?? '';

            if (empty($dbName) || empty($dbUser)) {
                $error = 'Database name and user are required.';
                break;
            }

            try {
                $pdo = new PDO(
                    "mysql:host={$dbHost};charset=utf8mb4",
                    $dbUser,
                    $dbPass,
                    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
                );

                // Check if database exists, create if not
                $safeName = str_replace('`', '``', $dbName);
                $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$safeName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                $pdo->exec("USE `{$safeName}`");

                $_SESSION['setup'] = $_SESSION['setup'] ?? [];
                $_SESSION['setup']['db_host'] = $dbHost;
                $_SESSION['setup']['db_name'] = $dbName;
                $_SESSION['setup']['db_user'] = $dbUser;
                $_SESSION['setup']['db_pass'] = $dbPass;

                $step = 3;
            } catch (PDOException $e) {
                $error = 'Database connection failed: ' . $e->getMessage();
            }
            break;

        case 3:
            // Admin account
            $username = trim($_POST['username'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $password = $_POST['password'] ?? '';
            $passwordConfirm = $_POST['password_confirm'] ?? '';

            if (empty($username) || empty($email) || empty($password)) {
                $error = 'All fields are required.';
                break;
            }
            if (strlen($password) < 8) {
                $error = 'Password must be at least 8 characters.';
                break;
            }
            if ($password !== $passwordConfirm) {
                $error = 'Passwords do not match.';
                break;
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error = 'Please enter a valid email address.';
                break;
            }

            $_SESSION['setup']['admin_username'] = $username;
            $_SESSION['setup']['admin_email'] = $email;
            $_SESSION['setup']['admin_password'] = $password;
            $step = 4;
            break;

        case 4:
            // Storage & server
            $storagePath = trim($_POST['storage_path'] ?? '');
            $serverHost = trim($_POST['server_host'] ?? '');
            $enableSsl = isset($_POST['enable_ssl']) ? true : false;

            if (empty($storagePath)) {
                $error = 'Storage path is required.';
                break;
            }
            if ($storagePath[0] !== '/') {
                $error = 'Storage path must be an absolute path (starting with /).';
                break;
            }
            // Warn against system directories that require root ownership
            $blocked = ['/', '/bin', '/boot', '/dev', '/etc', '/home', '/lib', '/opt', '/proc', '/root', '/run', '/sbin', '/srv', '/sys', '/tmp', '/usr', '/var'];
            if (in_array(rtrim($storagePath, '/'), $blocked)) {
                $error = "Cannot use {$storagePath} as a storage path — it is a system directory. Use a subdirectory such as /var/bbs/home or mount your storage volume at a dedicated path.";
                break;
            }
            // Validate the storage path is usable by the web server
            if (is_dir($storagePath)) {
                if (!is_writable($storagePath)) {
                    $error = "Storage path exists but is not writable by the web server. Run: sudo chown www-data:www-data " . escapeshellarg($storagePath);
                    break;
                }
            } else {
                // Try to create it
                if (!@mkdir($storagePath, 0750, true)) {
                    $parent = dirname($storagePath);
                    $error = "Cannot create storage directory. Run: sudo mkdir -p " . escapeshellarg($storagePath) . " && sudo chown www-data:www-data " . escapeshellarg($storagePath);
                    break;
                }
            }
            if (empty($serverHost)) {
                $error = 'Server hostname is required for agent connections.';
                break;
            }

            $_SESSION['setup']['storage_path'] = $storagePath;
            $_SESSION['setup']['server_host'] = $serverHost;
            $_SESSION['setup']['enable_ssl'] = $enableSsl;
            $step = 5;
            break;

        case 5:
            // Execute installation
            $setup = $_SESSION['setup'] ?? [];
            if (empty($setup['db_host']) || empty($setup['admin_username'])) {
                $error = 'Session expired. Please start over.';
                $step = 1;
                break;
            }

            try {
                // 1. Generate APP_KEY
                $appKey = bin2hex(random_bytes(32));

                // 2. Connect to database
                $pdo = new PDO(
                    "mysql:host={$setup['db_host']};dbname={$setup['db_name']};charset=utf8mb4",
                    $setup['db_user'],
                    $setup['db_pass'],
                    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
                );

                // 3. Import schema.sql
                $schemaPath = $basePath . '/schema.sql';
                if (!file_exists($schemaPath)) {
                    throw new RuntimeException('schema.sql not found');
                }
                $schema = file_get_contents($schemaPath);

                // Allow re-running the wizard on an existing database
                $schema = str_replace('CREATE TABLE', 'CREATE TABLE IF NOT EXISTS', $schema);
                $schema = str_replace('INSERT INTO', 'INSERT IGNORE INTO', $schema);

                // Remove the INSERT for default admin user — we'll create our own
                $schema = preg_replace(
                    "/INSERT INTO users.*?;\s*/s",
                    '',
                    $schema,
                    1 // Only remove the first INSERT INTO users
                );

                $pdo->exec($schema);

                // 4. Create admin user (update password if user already exists)
                $passwordHash = password_hash($setup['admin_password'], PASSWORD_BCRYPT, ['cost' => 12]);
                $stmt = $pdo->prepare("INSERT INTO users (username, email, password_hash, role) VALUES (?, ?, ?, 'admin') ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash), email = VALUES(email)");
                $stmt->execute([$setup['admin_username'], $setup['admin_email'], $passwordHash]);

                // 5. Set storage_path and server_host in settings
                $stmt = $pdo->prepare("INSERT INTO settings (`key`, `value`) VALUES ('storage_path', ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)");
                $stmt->execute([$setup['storage_path']]);

                $stmt = $pdo->prepare("UPDATE settings SET `value` = ? WHERE `key` = 'server_host'");
                $stmt->execute([$setup['server_host']]);

                // 7. Run migrations against the imported schema, then record
                // them as applied.
                //
                // schema.sql is meant to be the full current schema, so most
                // migrations are duplicates here. But if schema.sql has drifted
                // from migrations/ (a missed fold-in), simply *marking* them
                // applied — as this step used to — left a fresh install missing
                // columns AND flagged the migrations done, so they could never
                // be applied later either: a permanent 500 (#308). Instead we
                // actually run each migration; duplicate-column/table errors are
                // expected and ignored, and any genuinely-missing change gets
                // applied. Either way the migration is recorded so updates don't
                // re-run it.
                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS migrations (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        filename VARCHAR(255) NOT NULL UNIQUE,
                        executed_at DATETIME DEFAULT CURRENT_TIMESTAMP
                    )
                ");

                $migrationFiles = glob($basePath . '/migrations/*.sql');
                sort($migrationFiles);
                $stmt = $pdo->prepare("INSERT IGNORE INTO migrations (filename) VALUES (?)");
                foreach ($migrationFiles as $mf) {
                    try {
                        $pdo->exec(file_get_contents($mf));
                    } catch (\PDOException $e) {
                        // Expected when schema.sql already covers this migration
                        // (duplicate column/table). Anything else is logged but
                        // not fatal — the column may simply already exist.
                    }
                    $stmt->execute([basename($mf)]);
                }

                // 8. Write config/.env
                $protocol = !empty($setup['enable_ssl']) ? 'https' : 'http';
                $envContent = <<<ENV
APP_NAME="Borg Backup Server"
APP_URL={$protocol}://{$setup['server_host']}
APP_ENV=production
APP_DEBUG=false

DB_HOST={$setup['db_host']}
DB_NAME={$setup['db_name']}
DB_USER={$setup['db_user']}
DB_PASS={$setup['db_pass']}

SESSION_LIFETIME=3600

APP_KEY={$appKey}
ENV;

                $configDir = $basePath . '/config';
                if (!is_dir($configDir)) {
                    mkdir($configDir, 0755, true);
                }
                file_put_contents($configDir . '/.env', $envContent);
                chmod($configDir . '/.env', 0600);

                // 9. Ensure storage directory exists (validated in step 4)
                $storagePath = $setup['storage_path'];
                if (!is_dir($storagePath)) {
                    mkdir($storagePath, 0750, true);
                }

                // 10. Create borg cache directory
                if (!is_dir('/var/bbs/cache')) {
                    @mkdir('/var/bbs/cache', 0755, true);
                }
                if (!is_dir('/var/bbs/cache/www-data')) {
                    @mkdir('/var/bbs/cache/www-data', 0700, true);
                }

                // Auto-set target borg version from server-hosted binaries
                $borgDir = $basePath . '/public/borg';
                if (is_dir($borgDir)) {
                    $dirs = array_filter(scandir($borgDir), fn($d) => $d !== '.' && $d !== '..' && is_dir($borgDir . '/' . $d));
                    if (!empty($dirs)) {
                        usort($dirs, 'version_compare');
                        $latestVersion = end($dirs);
                        $stmt = $pdo->prepare("INSERT INTO settings (`key`, `value`) VALUES ('target_borg_version', ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)");
                        $stmt->execute([$latestVersion]);
                    }
                }

                // Auto-login the admin user so they can proceed directly
                $adminUser = $pdo->prepare("SELECT id, username, role, timezone FROM users WHERE username = ?");
                $adminUser->execute([$setup['admin_username']]);
                $admin = $adminUser->fetch(\PDO::FETCH_ASSOC);
                if ($admin) {
                    session_regenerate_id(true);
                    $_SESSION['user_id'] = $admin['id'];
                    $_SESSION['username'] = $admin['username'];
                    $_SESSION['user_role'] = $admin['role'];
                    $_SESSION['timezone'] = $admin['timezone'] ?? 'America/New_York';
                    $_SESSION['time_format'] = $admin['time_format'] ?? '12h';
                    $_SESSION['login_time'] = time();
                    $_SESSION['last_activity'] = time();
                }

                // Clear setup session
                unset($_SESSION['setup']);

                $step = 6;
            } catch (Exception $e) {
                $error = 'Installation failed: ' . $e->getMessage();
                // Clean up .env if it was written but install failed
                @unlink($basePath . '/config/.env');
            }
            break;
    }
}

// Check system requirements for step 1
$requirements = [
    'php_version' => [
        'label' => 'PHP >= 8.1',
        'ok' => version_compare(PHP_VERSION, '8.1.0', '>='),
        'value' => PHP_VERSION,
    ],
    'pdo_mysql' => [
        'label' => 'PDO MySQL extension',
        'ok' => extension_loaded('pdo_mysql'),
        'value' => extension_loaded('pdo_mysql') ? 'Installed' : 'Missing',
    ],
    'mbstring' => [
        'label' => 'Mbstring extension',
        'ok' => extension_loaded('mbstring'),
        'value' => extension_loaded('mbstring') ? 'Installed' : 'Missing',
    ],
    'openssl' => [
        'label' => 'OpenSSL extension',
        'ok' => extension_loaded('openssl'),
        'value' => extension_loaded('openssl') ? 'Installed' : 'Missing',
    ],
    'config_writable' => [
        'label' => 'Config directory writable',
        'ok' => is_writable($basePath . '/config') || is_writable($basePath),
        'value' => is_writable($basePath . '/config') ? 'Writable' : 'Not writable',
    ],
];
$allRequirementsMet = !in_array(false, array_column($requirements, 'ok'));

// Check SSH helper for step 5 summary
$sshHelperInstalled = file_exists('/usr/local/bin/bbs-ssh-helper');

// Render the wizard view
$viewPath = $basePath . '/src/Views/';
require $viewPath . 'setup/wizard.php';
