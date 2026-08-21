<?php

namespace BBS\Controllers\Api;

use BBS\Core\Controller;
use BBS\Services\CertificateService;

/**
 * The TLS certificate, over the API.
 *
 * Renewal runs on a timer and is usually invisible until it stops working, at
 * which point the first sign is a browser warning. These endpoints let a
 * monitoring system see the expiry date and act on it without shelling into
 * the server.
 */
class SslApiController extends Controller
{
    private function getJsonInput(): array
    {
        $raw = file_get_contents('php://input');
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    /**
     * GET /api/v1/ssl
     *
     * `days_remaining` is the field to alert on. It is null when no
     * certificate is installed, which is not a fault — an install behind a
     * proxy that terminates TLS elsewhere has none and needs none.
     */
    public function show(): void
    {
        $this->requireApiToken();
        $status = (new CertificateService())->status();
        unset($status['raw']);
        $this->json($status);
    }

    /**
     * POST /api/v1/ssl/renew — {"force": false}
     *
     * certbot declines while more than 30 days remain and says so; that reply
     * is passed back rather than reported as a failure, because it is the
     * correct answer. `force` overrides it, and is rate-limited by Let's
     * Encrypt rather than by us.
     */
    public function renew(): void
    {
        $this->requireApiToken();
        $input = $this->getJsonInput();
        $force = filter_var($input['force'] ?? false, FILTER_VALIDATE_BOOLEAN);

        $svc = new CertificateService();
        $result = $svc->renew(null, $force);

        $this->db->insert('server_log', [
            'level' => $result['success'] ? 'info' : 'warning',
            'message' => 'Certificate renewal requested via API — '
                . ($result['success'] ? 'completed' : 'failed'),
        ]);

        $status = $svc->status();
        unset($status['raw']);

        $this->json([
            'status' => $result['success'] ? 'ok' : 'error',
            'output' => $result['output'],
            'certificate' => $status,
        ], $result['success'] ? 200 : 422);
    }

    /**
     * PUT /api/v1/ssl/email — {"email": "you@example.com"}
     *
     * The address Let's Encrypt sends expiry warnings to. An empty string
     * clears it, which is the same as certbot's
     * --register-unsafely-without-email.
     */
    public function setEmail(): void
    {
        $this->requireApiToken();
        $input = $this->getJsonInput();

        if (!array_key_exists('email', $input)) {
            $this->json(['error' => 'email is required (empty string clears the contact address)'], 422);
        }

        $email = trim((string) $input['email']);
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->json(['error' => 'email is not a valid address'], 422);
        }

        $result = (new CertificateService())->setContactEmail($email);
        if (!$result['success']) {
            $this->json(['error' => $result['output']], 422);
        }

        $this->db->insert('server_log', [
            'level' => 'info',
            'message' => $email === ''
                ? 'Certificate contact address cleared via API'
                : "Certificate contact address set to {$email} via API",
        ]);

        $this->json(['status' => 'ok', 'email' => $email]);
    }
}
