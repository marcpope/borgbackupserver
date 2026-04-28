<!DOCTYPE html>
<html lang="en" data-bs-theme="<?= htmlspecialchars($defaultTheme ?? 'dark') ?>">
<head>
    <?php if (empty($loginThemeForced)): ?>
    <script>(function(){var t=localStorage.getItem('bbs-theme');if(t)document.documentElement.setAttribute('data-bs-theme',t);})()</script>
    <?php endif; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle ?? 'Login') ?> - Borg Backup Server</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="/css/style.css" rel="stylesheet">
    <style>
        html, body { height: 100%; }
        body.auth-split {
            margin: 0;
            min-height: 100vh;
            display: flex;
            background: var(--bs-body-bg);
        }
        .auth-art {
            flex: 1 1 50%;
            min-width: 0;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 48px 40px 200px;
            position: relative;
            background: radial-gradient(ellipse at center, rgba(35, 75, 165, 0.18), transparent 60%),
                        linear-gradient(180deg, #07101f 0%, #050a14 100%);
            overflow: hidden;
        }
        /* Subtle starfield-style dot pattern in the background */
        .auth-art::before {
            content: "";
            position: absolute;
            inset: 0;
            background-image: radial-gradient(rgba(99, 161, 255, 0.08) 1px, transparent 1px);
            background-size: 28px 28px;
            opacity: 0.6;
            pointer-events: none;
        }
        .auth-art-logo {
            max-width: 460px;
            width: 70%;
            height: auto;
            position: relative;
            z-index: 1;
            filter: drop-shadow(0 12px 40px rgba(0, 0, 0, 0.6));
        }
        .auth-features {
            position: absolute;
            left: 40px;
            right: 40px;
            bottom: 40px;
            display: flex;
            gap: 12px;
            justify-content: space-between;
            background: rgba(255, 255, 255, 0.04);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 14px;
            padding: 16px 20px;
            backdrop-filter: blur(8px);
        }
        .auth-feature {
            display: flex;
            align-items: center;
            gap: 12px;
            color: #fff;
            flex: 1;
            min-width: 0;
        }
        .auth-feature i {
            font-size: 1.6rem;
            color: #4ea7ff;
            flex-shrink: 0;
        }
        .auth-feature-title { font-weight: 600; font-size: 0.9rem; line-height: 1.2; }
        .auth-feature-sub   { font-size: 0.72rem; color: rgba(255,255,255,0.55); line-height: 1.2; margin-top: 2px; }

        .auth-form-pane {
            flex: 1 1 50%;
            min-width: 0;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            padding: 64px 56px 24px;
            background: var(--bs-body-bg);
        }
        .auth-form-inner {
            max-width: 440px;
            width: 100%;
            margin: auto 0;
        }
        .auth-form-inner h1 {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 6px;
            color: var(--bs-body-color);
        }
        .auth-subtitle {
            color: var(--bs-secondary-color);
            margin-bottom: 32px;
        }
        .auth-footer {
            text-align: center;
            font-size: 0.75rem;
            color: var(--bs-secondary-color);
            padding-top: 24px;
        }
        .auth-footer a { color: var(--bs-secondary-color); }

        @media (max-width: 991.98px) {
            body.auth-split { flex-direction: column; }
            .auth-art { display: none; }
            .auth-form-pane { padding: 48px 24px 24px; min-height: 100vh; }
        }
    </style>
</head>
<body class="auth-split">
    <div class="auth-art">
        <?php if (!empty($loginLogo)): ?>
            <img src="data:image/png;base64,<?= $loginLogo ?>" alt="Logo" class="auth-art-logo">
        <?php else: ?>
            <img src="/images/login-logo.png" alt="Borg Backup Server" class="auth-art-logo">
        <?php endif; ?>
        <div class="auth-features">
            <div class="auth-feature">
                <i class="bi bi-cloud-arrow-up-fill"></i>
                <div>
                    <div class="auth-feature-title">Reliable Backups</div>
                    <div class="auth-feature-sub">Protect what matters.</div>
                </div>
            </div>
            <div class="auth-feature">
                <i class="bi bi-shield-fill-check"></i>
                <div>
                    <div class="auth-feature-title">Secure by Design</div>
                    <div class="auth-feature-sub">Your data, your control.</div>
                </div>
            </div>
            <div class="auth-feature">
                <i class="bi bi-hdd-stack-fill"></i>
                <div>
                    <div class="auth-feature-title">Anywhere Storage</div>
                    <div class="auth-feature-sub">On-prem, cloud, or both.</div>
                </div>
            </div>
        </div>
    </div>

    <div class="auth-form-pane">
        <div class="auth-form-inner">
            <?php require $viewPath . $template . '.php'; ?>
        </div>
        <div class="auth-footer">
            &copy; <?= date('Y') ?> Borg Backup Server &mdash; <a href="https://github.com/marcpope/borgbackupserver/blob/main/LICENSE">MIT Open Source License</a>
            <?php
            $versionFile = dirname(__DIR__, 2) . '/VERSION';
            $versionStr = is_readable($versionFile) ? trim((string) @file_get_contents($versionFile)) : '';
            if ($versionStr !== ''): ?>
                <br>v<?= htmlspecialchars($versionStr) ?>
            <?php endif; ?>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
