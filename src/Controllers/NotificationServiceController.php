<?php

namespace BBS\Controllers;

use BBS\Core\Controller;
use BBS\Services\AppriseService;

class NotificationServiceController extends Controller
{
    private array $eventTypes = [
        // Backups
        'backup_completed' => 'Backup Completed',
        'backup_warning' => 'Backup Completed with Warnings',
        'backup_failed' => 'Backup Failed',
        // Restores
        'restore_completed' => 'Restore Completed',
        'restore_failed' => 'Restore Failed',
        // Clients
        'agent_offline' => 'Client Offline',
        'agent_online' => 'Client Online',
        // Repositories
        'repo_check_failed' => 'Check Failed',
        'repo_compact_done' => 'Compact Done',
        // Storage
        'storage_low' => 'Storage Low',
        's3_sync_failed' => 'S3 Sync Failed',
        's3_sync_done' => 'S3 Sync Done',
        // Schedules
        'missed_schedule' => 'Missed Schedule',
    ];

    private array $serviceNames = [
        'discord' => 'Discord',
        'tgram' => 'Telegram',
        'slack' => 'Slack',
        'pover' => 'Pushover',
        'ntfy' => 'ntfy',
        'gotify' => 'Gotify',
        'mailto' => 'Email',
        'msteams' => 'MS Teams',
        'matrix' => 'Matrix',
        'rocket' => 'Rocket.Chat',
        'json' => 'JSON/Webhook',
        'xml' => 'XML',
        'form' => 'Form POST',
        'gets' => 'GET Request',
        'posts' => 'POST Request',
    ];

    public function index(): void
    {
        // Redirect to settings push tab
        $this->redirect('/settings?tab=push');
    }

    public function store(): void
    {
        $this->requireAdmin();
        $this->verifyCsrf();

        $name = trim($_POST['name'] ?? '');
        $appriseUrl = trim($_POST['apprise_url'] ?? '');

        if (empty($name) || empty($appriseUrl)) {
            $this->flash('danger', 'Name and Apprise URL are required.');
            $this->redirect('/settings?tab=push');
        }

        // Build events JSON from checkboxes
        $events = [];
        foreach (array_keys($this->eventTypes) as $event) {
            $events[$event] = isset($_POST['events'][$event]) && $_POST['events'][$event] ? true : false;
        }

        $serviceType = $this->detectServiceType($appriseUrl);

        $this->db->insert('notification_services', [
            'name' => $name,
            'service_type' => $serviceType,
            'apprise_url' => $appriseUrl,
            'enabled' => 1,
            'events' => json_encode($events),
        ]);

        $this->flash('success', "Notification service \"{$name}\" created.");
        $this->redirect('/settings?tab=push');
    }

    public function update(int $id): void
    {
        $this->requireAdmin();
        $this->verifyCsrf();

        $service = $this->db->fetchOne("SELECT id FROM notification_services WHERE id = ?", [$id]);
        if (!$service) {
            $this->flash('danger', 'Service not found.');
            $this->redirect('/settings?tab=push');
        }

        $name = trim($_POST['name'] ?? '');
        $appriseUrl = trim($_POST['apprise_url'] ?? '');

        if (empty($name) || empty($appriseUrl)) {
            $this->flash('danger', 'Name and Apprise URL are required.');
            $this->redirect('/settings?tab=push');
        }

        // Build events JSON from checkboxes
        $events = [];
        foreach (array_keys($this->eventTypes) as $event) {
            $events[$event] = isset($_POST['events'][$event]) && $_POST['events'][$event] ? true : false;
        }

        $serviceType = $this->detectServiceType($appriseUrl);

        $this->db->update('notification_services', [
            'name' => $name,
            'service_type' => $serviceType,
            'apprise_url' => $appriseUrl,
            'events' => json_encode($events),
        ], 'id = ?', [$id]);

        $this->flash('success', "Notification service updated.");
        $this->redirect('/settings?tab=push');
    }

    public function delete(int $id): void
    {
        $this->requireAdmin();
        $this->verifyCsrf();

        $service = $this->db->fetchOne("SELECT name FROM notification_services WHERE id = ?", [$id]);
        if (!$service) {
            $this->flash('danger', 'Service not found.');
            $this->redirect('/settings?tab=push');
        }

        $this->db->delete('notification_services', 'id = ?', [$id]);

        $this->flash('success', "Notification service \"{$service['name']}\" deleted.");
        $this->redirect('/settings?tab=push');
    }

    public function toggle(int $id): void
    {
        $this->requireAdmin();
        $this->verifyCsrf();

        $service = $this->db->fetchOne("SELECT enabled FROM notification_services WHERE id = ?", [$id]);
        if (!$service) {
            $this->flash('danger', 'Service not found.');
            $this->redirect('/settings?tab=push');
        }

        $newEnabled = $service['enabled'] ? 0 : 1;
        $this->db->update('notification_services', ['enabled' => $newEnabled], 'id = ?', [$id]);

        $this->redirect('/settings?tab=push');
    }

    public function test(int $id): void
    {
        $this->requireAdmin();
        $this->verifyCsrf();

        $service = $this->db->fetchOne("SELECT * FROM notification_services WHERE id = ?", [$id]);
        if (!$service) {
            $this->json(['success' => false, 'error' => 'Service not found']);
            return;
        }

        $apprise = new AppriseService();
        if (!$apprise->isAppriseInstalled()) {
            $this->json(['success' => false, 'error' => 'Apprise is not installed on the server.']);
            return;
        }

        // Send test notification to this specific service
        $title = escapeshellarg('BBS Test Notification');
        $body = escapeshellarg('This is a test notification from Borg Backup Server. If you receive this, the service is configured correctly.');
        $url = escapeshellarg($service['apprise_url']);

        $cmd = "apprise -t {$title} -b {$body} {$url} 2>&1";
        exec($cmd, $output, $code);

        if ($code === 0) {
            // Update last_used_at
            $this->db->update('notification_services', ['last_used_at' => date('Y-m-d H:i:s')], 'id = ?', [$id]);
            $this->json(['success' => true]);
        } else {
            $this->json(['success' => false, 'error' => implode("\n", $output) ?: 'Apprise command failed.']);
        }
    }

    public function duplicate(int $id): void
    {
        $this->requireAdmin();
        $this->verifyCsrf();

        $service = $this->db->fetchOne("SELECT * FROM notification_services WHERE id = ?", [$id]);
        if (!$service) {
            $this->flash('danger', 'Service not found.');
            $this->redirect('/settings?tab=push');
        }

        $this->db->insert('notification_services', [
            'name' => $service['name'] . ' (copy)',
            'service_type' => $service['service_type'],
            'apprise_url' => $service['apprise_url'],
            'enabled' => 0,
            'events' => $service['events'],
        ]);

        $this->flash('success', "Service duplicated.");
        $this->redirect('/settings?tab=push');
    }

    private function detectServiceType(string $url): string
    {
        if (preg_match('/^(\w+):\/\//', $url, $m)) {
            return strtolower($m[1]);
        }
        return 'unknown';
    }

    private function getServiceName(string $type): string
    {
        return $this->serviceNames[strtolower($type)] ?? ucfirst($type);
    }
}
