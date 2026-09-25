<h4 class="mb-4">Add New Client</h4>

<div class="row">
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <form method="POST" action="/clients/add">
                    <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Client Name</label>
                        <input type="text" class="form-control" name="name" required autofocus placeholder="e.g. Web-Server-01">
                        <div class="form-text">A descriptive name for this endpoint.</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Client Profile</label>
                        <select class="form-select" name="client_profile_id">
                            <?php foreach ($clientProfiles as $cp): ?>
                            <option value="<?= (int) $cp['id'] ?>" <?= !empty($cp['is_default']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($cp['name']) ?><?= !empty($cp['description']) ? ' — ' . htmlspecialchars($cp['description']) : '' ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">
                            What kind of machine this is. The profile decides what its first backup plan is filled in
                            with — directories, schedule and retention — and how patient BBS is when it drops out
                            mid-backup.<?php if (($_SESSION['user_role'] ?? '') === 'admin'): ?> <a href="/settings?tab=profiles">Manage profiles</a>.<?php endif; ?>
                        </div>
                    </div>

                    <?php if (($_SESSION['user_role'] ?? '') === 'admin'): ?>
                    <div class="mb-4">
                        <label class="form-label fw-semibold">Assign to User</label>
                        <select class="form-select" name="user_id">
                            <option value="">-- No owner (admin only) --</option>
                            <?php foreach ($users as $user): ?>
                            <option value="<?= $user['id'] ?>"><?= htmlspecialchars($user['username']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">Optional. Assign this client to a specific user.</div>
                    </div>
                    <?php else: ?>
                    <div class="mb-4 form-text">This client will be assigned to you.</div>
                    <?php endif; ?>

                    <button type="submit" class="btn btn-success">
                        <i class="bi bi-plus-circle me-1"></i> Create Client
                    </button>
                    <a href="/clients" class="btn btn-outline-secondary ms-2">Cancel</a>
                </form>
            </div>
        </div>
    </div>
</div>
