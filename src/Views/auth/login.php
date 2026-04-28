<?php if (!empty($flash)): ?>
<div class="alert alert-<?= $flash['type'] ?> alert-dismissible fade show" role="alert">
    <?= htmlspecialchars($flash['message']) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<h1>Welcome back</h1>
<div class="auth-subtitle">Sign in to your Account.</div>

<?php if (!empty($oidcEnabled)): ?>
<a href="/login/oidc" class="btn btn-primary btn-lg w-100 mb-3">
    <i class="bi bi-box-arrow-in-right me-2"></i><?= htmlspecialchars($oidcButtonLabel ?? 'Login with SSO') ?>
</a>
<div class="text-center text-muted small mb-3">— or sign in with username —</div>
<?php endif; ?>

<form method="POST" action="/login">
    <div class="mb-3">
        <label for="username" class="form-label fw-semibold">Username</label>
        <div class="input-group">
            <span class="input-group-text"><i class="bi bi-person"></i></span>
            <input type="text" class="form-control" id="username" name="username" required autofocus autocomplete="username">
        </div>
    </div>
    <div class="mb-2">
        <label for="password" class="form-label fw-semibold">Password</label>
        <div class="input-group">
            <span class="input-group-text"><i class="bi bi-lock"></i></span>
            <input type="password" class="form-control" id="password" name="password" required autocomplete="current-password">
        </div>
    </div>
    <div class="d-flex justify-content-end mb-4">
        <a href="/forgot-password" class="text-decoration-none small">Forgot password?</a>
    </div>
    <button type="submit" class="btn btn-primary w-100 py-2 fw-semibold">
        <i class="bi bi-lock-fill me-2"></i>Sign in
    </button>
</form>
