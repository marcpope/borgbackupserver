<?php if (!empty($flash)): ?>
<div class="alert alert-<?= $flash['type'] ?> alert-dismissible fade show" role="alert">
    <?= htmlspecialchars($flash['message']) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<h1>Forgot your password?</h1>
<div class="auth-subtitle">Enter your email address and we'll send you a reset link.</div>

<form method="POST" action="/forgot-password">
    <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
    <div class="mb-4">
        <label for="email" class="form-label fw-semibold">Email Address</label>
        <div class="input-group">
            <span class="input-group-text"><i class="bi bi-envelope"></i></span>
            <input type="email" class="form-control" id="email" name="email" required autofocus autocomplete="email">
        </div>
    </div>
    <button type="submit" class="btn btn-primary w-100 py-2 fw-semibold mb-3">
        <i class="bi bi-envelope me-2"></i>Send Reset Link
    </button>
    <div class="text-center">
        <a href="/login" class="text-decoration-none small">Back to login</a>
    </div>
</form>
