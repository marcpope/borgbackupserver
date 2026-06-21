<!DOCTYPE html>
<html lang="en" data-bs-theme="<?= htmlspecialchars($defaultTheme ?? 'dark') ?>">
<head>
    <?php if (empty($loginThemeForced)): ?>
    <script>(function(){var t=localStorage.getItem('bbs-theme');if(t)document.documentElement.setAttribute('data-bs-theme',t);})()</script>
    <?php endif; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle ?? 'Login') ?> - Borg Backup Server</title>
    <link href="/assets/inter/inter.css" rel="stylesheet">
    <link href="/assets/bootstrap/bootstrap.min.css" rel="stylesheet">
    <link href="/assets/bootstrap-icons/bootstrap-icons.min.css" rel="stylesheet">
    <link href="/css/style.css" rel="stylesheet">
    <link rel="manifest" href="/manifest.json">
    <link rel="icon" type="image/png" sizes="16x16" href="/branding/icon/16">
    <link rel="icon" type="image/png" sizes="32x32" href="/branding/icon/32">
    <link rel="icon" type="image/png" sizes="96x96" href="/branding/icon/96">
    <link rel="shortcut icon" href="/favicon.ico">
    <link rel="apple-touch-icon" sizes="180x180" href="/branding/icon/180">
    <meta name="theme-color" content="#07101f">
    <style>
        html, body { height: 100%; }
        body.auth-split {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 32px;
            /* Outer page is a near-black field so the framed card pops. */
            background:
                radial-gradient(ellipse 60% 50% at 50% 50%, rgba(35, 75, 165, 0.08), transparent 70%),
                #03070d;
            color: #e8edf5;
        }
        /* Centered card frame — the whole login experience lives inside this
           rounded panel, with a thin highlight ring and a soft drop shadow
           so it floats above the page. */
        .auth-frame {
            position: relative;
            display: flex;
            width: 100%;
            max-width: 1000px;
            min-height: 580px;
            border-radius: 18px;
            overflow: hidden;
            background: linear-gradient(180deg, #07101f 0%, #050a14 100%);
            z-index: 1;
            /* Layered halo: tight inner cyan ring, soft outer atmospheric
               glow, plus the original drop shadow and 1px highlight ring.
               Keeps the card feeling "lit from within" without becoming
               cheesy. */
            box-shadow:
                0 0 0 1px rgba(255, 255, 255, 0.05),
                0 0 30px rgba(78, 167, 255, 0.18),
                0 0 80px rgba(78, 167, 255, 0.12),
                0 24px 60px rgba(0, 0, 0, 0.55);
        }
        .auth-art {
            flex: 1 1 50%;
            min-width: 0;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 20px;
            padding: 0;
            position: relative;
            background: radial-gradient(ellipse at center, rgba(35, 75, 165, 0.22), transparent 60%);
            border-right: 1px solid rgba(255, 255, 255, 0.07);
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
            display: block;
            width: 100%;
            height: auto;
            position: relative;
            z-index: 1;
            filter: drop-shadow(0 10px 30px rgba(0, 0, 0, 0.5));
        }
        /* Custom uploaded brand logos (#261). They don't share the default
           Borg art's edge-to-edge framing — give them breathing room and
           drop the drop-shadow, which looks wrong on logos that already
           have their own shadow or a white background. */
        .auth-art-brand {
            padding: 24px;
            filter: none;
        }
        .auth-features {
            display: flex;
            gap: 8px;
            justify-content: space-between;
            width: calc(100% - 48px);
            max-width: 460px;
            margin-bottom: 24px;
            background: rgba(255, 255, 255, 0.04);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 12px;
            padding: 10px 14px;
            backdrop-filter: blur(8px);
            position: relative;
            z-index: 1;
        }
        .auth-feature {
            display: flex;
            align-items: center;
            gap: 8px;
            color: #fff;
            flex: 1;
            min-width: 0;
        }
        .auth-feature i {
            font-size: 1.05rem;
            color: #4ea7ff;
            flex-shrink: 0;
        }
        .auth-feature-title { font-weight: 600; font-size: 0.7rem; line-height: 1.2; }
        .auth-feature-sub   { font-size: 0.55rem; color: rgba(255,255,255,0.55); line-height: 1.2; margin-top: 1px; }

        .auth-form-pane {
            flex: 1 1 50%;
            min-width: 0;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            padding: 44px 44px 22px;
            background: linear-gradient(180deg, #0c1729 0%, #08111e 100%);
        }
        .auth-form-inner {
            max-width: 360px;
            width: 100%;
            margin: auto 0;
        }
        .auth-form-inner h1 {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 4px;
            color: #f3f6fb;
        }
        .auth-subtitle {
            color: rgba(255, 255, 255, 0.55);
            margin-bottom: 22px;
            font-size: 0.875rem;
        }
        /* Form inputs: subtle dark surface with a soft border so they read on
           the navy background. Inherits Bootstrap structure, just retones. */
        .auth-form-inner .form-label { color: rgba(255, 255, 255, 0.85); }
        .auth-form-inner .form-control,
        .auth-form-inner .input-group-text {
            background-color: rgba(255, 255, 255, 0.04);
            border-color: rgba(255, 255, 255, 0.12);
            color: #f3f6fb;
        }
        .auth-form-inner .input-group-text { color: rgba(255, 255, 255, 0.6); }
        .auth-form-inner .form-control::placeholder { color: rgba(255, 255, 255, 0.35); }
        .auth-form-inner .form-control:focus {
            background-color: rgba(255, 255, 255, 0.06);
            border-color: rgba(99, 161, 255, 0.6);
            box-shadow: 0 0 0 0.2rem rgba(99, 161, 255, 0.15);
            color: #f3f6fb;
        }
        .auth-footer {
            font-size: 0.7rem;
            color: rgba(255, 255, 255, 0.45);
            padding-top: 20px;
            max-width: 360px;
            width: 100%;
        }
        .auth-footer a { color: rgba(255, 255, 255, 0.6); }

        /* Mobile-only header logo. Hidden on desktop where the split-pane
           art carries the brand; shown above the form on mobile. */
        .auth-mobile-logo { display: none; }

        /* When the art pane is suppressed (e.g. the upgrade page passes
           hideAuthArt=true via #222), the form pane should fill the entire
           frame so the upgrade log gets the full reading width. The mobile
           logo also stays hidden — this isn't a login screen, no branding
           cue needed. */
        body.auth-no-art {
            /* Login pages center vertically with align-items: center because
               their content always fits in the viewport. The upgrade page's
               step list + release notes can be much taller than the viewport,
               and align-items: center pushes the top of the content above
               the viewport with no way to scroll up to it (#239). Anchor at
               the top so everything is reachable. */
            align-items: flex-start;
        }
        body.auth-no-art .auth-frame { max-width: 1000px; }
        body.auth-no-art .auth-form-pane {
            flex: 1 1 100%;
            padding: 40px 56px 28px;
        }
        body.auth-no-art .auth-form-inner { max-width: none; }
        body.auth-no-art .auth-footer     { max-width: none; }
        body.auth-no-art .auth-mobile-logo { display: none !important; }

        /* ----------------------------------------------------------------
           Light theme overrides. Same split-pane layout, but warmer
           backgrounds and dark text so the login still works for users
           whose branding reads better on light. The art pane keeps its
           navy radial since that's what the mascot artwork is composed
           against; the form pane goes light.
           ---------------------------------------------------------------- */
        [data-bs-theme="light"] body.auth-split {
            background:
                radial-gradient(ellipse 60% 50% at 50% 50%, rgba(35, 75, 165, 0.05), transparent 70%),
                #eef1f5;
            color: #1f2937;
        }
        [data-bs-theme="light"] .auth-frame {
            background: #ffffff;
            box-shadow:
                0 0 0 1px rgba(15, 23, 42, 0.06),
                0 0 30px rgba(78, 167, 255, 0.15),
                0 0 80px rgba(78, 167, 255, 0.08),
                0 24px 60px rgba(15, 23, 42, 0.18);
        }
        [data-bs-theme="light"] .auth-art {
            /* Keep the dark navy on the art side — the mascot artwork
               itself is composed against deep blue, lifting it off a
               white background looks washed out. The divider then reads
               clearly against the form pane's lighter surface. */
            background:
                radial-gradient(ellipse at center, rgba(35, 75, 165, 0.22), transparent 60%),
                linear-gradient(180deg, #07101f 0%, #050a14 100%);
            border-right: 1px solid rgba(15, 23, 42, 0.08);
        }
        [data-bs-theme="light"] .auth-form-pane {
            background: linear-gradient(180deg, #ffffff 0%, #f4f6fa 100%);
        }
        [data-bs-theme="light"] .auth-form-inner h1 { color: #0f172a; }
        [data-bs-theme="light"] .auth-subtitle      { color: #5b6473; }
        [data-bs-theme="light"] .auth-form-inner .form-label {
            color: #1f2937;
        }
        [data-bs-theme="light"] .auth-form-inner .form-control,
        [data-bs-theme="light"] .auth-form-inner .input-group-text {
            background-color: #ffffff;
            border-color: rgba(15, 23, 42, 0.18);
            color: #0f172a;
        }
        [data-bs-theme="light"] .auth-form-inner .input-group-text {
            color: #5b6473;
        }
        [data-bs-theme="light"] .auth-form-inner .form-control::placeholder {
            color: rgba(15, 23, 42, 0.35);
        }
        [data-bs-theme="light"] .auth-form-inner .form-control:focus {
            background-color: #ffffff;
            border-color: rgba(99, 161, 255, 0.6);
            box-shadow: 0 0 0 0.2rem rgba(99, 161, 255, 0.15);
            color: #0f172a;
        }
        [data-bs-theme="light"] .auth-footer        { color: #5b6473; }
        [data-bs-theme="light"] .auth-footer a      { color: #5b6473; }
        /* Drifting binary stream is composed for dark — hide on light. */
        [data-bs-theme="light"] .bg-binary { display: none; }

        /* Background binary-stream layer. A tiny, slow drift of 0/1 glyphs
           across the page — sparse on purpose. Keeps the page from feeling
           static without ever becoming the focus. Each glyph is spawned
           by JS below with random Y, size, opacity, and duration. */
        .bg-binary {
            position: fixed;
            inset: 0;
            pointer-events: none;
            overflow: hidden;
            z-index: 0;
        }
        .bg-bit {
            position: absolute;
            left: -120px;
            color: #6ab0ff;
            font-family: 'JetBrains Mono', 'Menlo', 'Consolas', monospace;
            font-weight: 600;
            letter-spacing: 0.04em;
            text-shadow: 0 0 8px rgba(78, 167, 255, 0.4);
            white-space: nowrap;
            animation: bg-bit-fly linear forwards;
            will-change: transform;
        }
        /* Reverse-direction variant: starts off-screen right, drifts left.
           Mixing both directions gives the field a sense of independent
           data streams rather than one uniform conveyor. */
        .bg-bit.reverse {
            left: auto;
            right: -120px;
            animation-name: bg-bit-fly-reverse;
        }
        @keyframes bg-bit-fly {
            from { transform: translateX(0); }
            to   { transform: translateX(calc(100vw + 240px)); }
        }
        @keyframes bg-bit-fly-reverse {
            from { transform: translateX(0); }
            to   { transform: translateX(calc(-100vw - 240px)); }
        }
        @media (prefers-reduced-motion: reduce) {
            .bg-binary { display: none; }
        }

        /* On small screens drop the framing — full-bleed form, no art pane,
           no card chrome. Trying to keep an inset frame on a phone wastes
           too much real estate. */
        @media (max-width: 991.98px) {
            body.auth-split {
                padding: 0;
                align-items: stretch;
                background: linear-gradient(180deg, #07101f 0%, #050a14 100%);
            }
            .auth-frame {
                flex-direction: column;
                max-width: none;
                min-height: 100vh;
                border-radius: 0;
                box-shadow: none;
            }
            .auth-art { display: none; }
            .auth-form-pane { padding: 32px 24px 24px; }
            .bg-binary { display: none; }
            .auth-mobile-logo {
                display: flex;
                justify-content: center;
                margin: 0 auto 24px;
            }
            .auth-mobile-logo img {
                width: 88px;
                height: 88px;
                object-fit: contain;
                filter: drop-shadow(0 6px 18px rgba(0, 0, 0, 0.5));
            }
        }
    </style>
</head>
<body class="auth-split <?= !empty($hideAuthArt) ? 'auth-no-art' : '' ?>">
    <?php if (empty($hideAuthArt)): ?>
    <div class="bg-binary" aria-hidden="true"></div>
    <?php endif; ?>
    <div class="auth-frame">
    <?php if (empty($hideAuthArt)): ?>
    <div class="auth-art">
        <?php if (!empty($loginLogo)): ?>
            <img src="data:image/png;base64,<?= $loginLogo ?>" alt="Logo" class="auth-art-logo auth-art-brand">
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
                <i class="bi bi-shield-lock-fill"></i>
                <div>
                    <div class="auth-feature-title">Zero Trust Security</div>
                    <div class="auth-feature-sub">Secure by design.</div>
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
    <?php endif; ?>

    <div class="auth-form-pane">
        <?php
        // Mobile-only header logo. The full split-pane art is hidden on
        // narrow viewports, so we put a small standalone logo above the
        // form. Falls back to the navbar branding icon (smaller, sized
        // for a small slot) before the default favicon — the larger
        // login_logo would crowd a phone-width pane.
        ?>
        <div class="auth-mobile-logo">
            <?php if (!empty($brandingIcon)): ?>
                <img src="data:image/png;base64,<?= $brandingIcon ?>" alt="Logo">
            <?php else: ?>
                <img src="/images/favicon.png" alt="Borg Backup Server">
            <?php endif; ?>
        </div>
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
    </div><!-- /.auth-frame -->
    <script src="/assets/bootstrap/bootstrap.bundle.min.js"></script>
    <script>
    /* Sparse binary-stream background: spawns one glyph every ~450ms,
       randomized Y / size / opacity / speed so they feel like data
       drifting past in the deep distance rather than a Matrix wall.
       Each glyph fades out near the end of its travel and removes
       itself once the animation completes. */
    (function () {
        var layer = document.querySelector('.bg-binary');
        if (!layer) return;

        // Honor reduced-motion at the JS level too — defense in depth
        // against the CSS not loading or being overridden.
        if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
            return;
        }

        function rand(min, max) { return min + Math.random() * (max - min); }

        function spawn() {
            var bit = document.createElement('span');
            bit.className = 'bg-bit';
            // Roughly 45% of streams flow right-to-left for variety.
            if (Math.random() < 0.45) bit.className += ' reverse';
            // Each glyph is a short cluster of 3-12 bits — feels like a
            // packet drifting by rather than a lone digit.
            var len = 3 + Math.floor(Math.random() * 10);
            var s = '';
            for (var i = 0; i < len; i++) s += Math.random() < 0.5 ? '0' : '1';
            bit.textContent = s;
            bit.style.top = rand(2, 96).toFixed(2) + 'vh';
            bit.style.fontSize = rand(10, 17).toFixed(1) + 'px';
            bit.style.opacity = rand(0.06, 0.18).toFixed(2);
            // Faster glyphs feel closer; slower feel deeper. Mixing both
            // gives a parallax sense of depth.
            bit.style.animationDuration = rand(3.5, 9).toFixed(2) + 's';
            layer.appendChild(bit);
            bit.addEventListener('animationend', function () { bit.remove(); });
        }

        // Initial scatter so the field isn't empty on first paint.
        for (var i = 0; i < 6; i++) setTimeout(spawn, i * 250);
        setInterval(spawn, 450);
    })();
    </script>
</body>
</html>
