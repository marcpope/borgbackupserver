<?php if (!empty($flash)): ?>
<div class="alert alert-<?= $flash['type'] ?> alert-dismissible fade show" role="alert">
    <?= htmlspecialchars($flash['message']) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<h1>Two-factor authentication</h1>
<div class="auth-subtitle">
    Enter the 6-digit code from your authenticator app<?php if (!empty($username)): ?> to continue as <strong><?= htmlspecialchars($username) ?></strong><?php endif; ?>.
</div>

<form method="POST" action="/login/2fa">
    <div class="mb-2">
        <label for="code" class="form-label fw-semibold">Authentication Code</label>
        <div class="input-group">
            <span class="input-group-text"><i class="bi bi-shield-lock"></i></span>
            <input
                type="text"
                class="form-control text-center"
                id="code"
                name="code"
                placeholder="000000"
                maxlength="9"
                required
                autofocus
                autocomplete="one-time-code"
            >
        </div>
        <div class="form-text small mt-2">Or enter a recovery code (XXXX-XXXX).</div>
    </div>
    <button type="submit" class="btn btn-primary w-100 py-2 fw-semibold mt-3 mb-3">
        <i class="bi bi-shield-check me-2"></i>Verify
    </button>
    <div class="text-center">
        <a href="/login" class="text-decoration-none small">Back to login</a>
    </div>
</form>
