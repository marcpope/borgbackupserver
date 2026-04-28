<?php if (!empty($flash)): ?>
<div class="alert alert-<?= $flash['type'] ?> alert-dismissible fade show" role="alert">
    <?= htmlspecialchars($flash['message']) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<h1>Set a new password</h1>
<div class="auth-subtitle">Choose a strong password you haven't used elsewhere.</div>

<form method="POST" action="/reset-password">
    <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
    <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
    <div class="mb-3">
        <label for="password" class="form-label fw-semibold">New Password</label>
        <div class="input-group">
            <span class="input-group-text"><i class="bi bi-lock"></i></span>
            <input type="password" class="form-control" id="password" name="password" required autofocus minlength="6" autocomplete="new-password">
        </div>
    </div>
    <div class="mb-4">
        <label for="password_confirm" class="form-label fw-semibold">Confirm Password</label>
        <div class="input-group">
            <span class="input-group-text"><i class="bi bi-lock"></i></span>
            <input type="password" class="form-control" id="password_confirm" name="password_confirm" required minlength="6" autocomplete="new-password">
        </div>
    </div>
    <button type="submit" class="btn btn-primary w-100 py-2 fw-semibold mb-3">
        <i class="bi bi-check-lg me-2"></i>Reset Password
    </button>
    <div class="text-center">
        <a href="/login" class="text-decoration-none small">Back to login</a>
    </div>
</form>
