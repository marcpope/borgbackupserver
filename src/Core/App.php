<?php

namespace BBS\Core;

class App
{
    private \AltoRouter $router;

    public function __construct()
    {
        Config::load();

        // Skip session_start() for agent API endpoints — agents authenticate
        // with a Bearer token (no cookie), so starting a session would just
        // create an empty sess_* file per request. With agents polling every
        // 30s, that adds up to hundreds of thousands of orphan files per
        // month. Browser-initiated API routes (e.g. /api/templates/{id}) still
        // need their session for requireAuth().
        $reqPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
        $isAgentApi = str_starts_with($reqPath, '/api/agent/');

        if (!$isAgentApi) {
            // Set gc_maxlifetime to 30 days so Ubuntu's sessionclean cron
            // doesn't delete session files before our app-level timeout
            ini_set('session.gc_maxlifetime', 30 * 86400);

            $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

            session_set_cookie_params([
                'lifetime' => 30 * 86400,
                'path' => '/',
                'httponly' => true,
                'samesite' => 'Lax',
                'secure' => $isHttps,
            ]);
            session_start();
        }
        $this->router = new \AltoRouter();
    }

    public function run(): void
    {
        $this->registerRoutes();

        // Redirect all UI routes to /upgrade while an upgrade is in progress
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
        if (!str_starts_with($path, '/upgrade') && !str_starts_with($path, '/api/')
            && !str_starts_with($path, '/login') && !str_starts_with($path, '/logout')) {
            try {
                $db = Database::getInstance();
                $row = $db->fetchOne("SELECT `value` FROM settings WHERE `key` = 'upgrade_in_progress'");
                if ($row && $row['value'] === '1') {
                    header('Location: /upgrade');
                    exit;
                }
            } catch (\Exception $e) { /* DB not available — skip guard */ }
        }

        $match = $this->router->match();

        if ($match) {
            [$controller, $method] = explode('@', $match['target']);
            $controller = "BBS\\Controllers\\{$controller}";
            $instance = new $controller();
            call_user_func_array([$instance, $method], $match['params']);
        } else {
            http_response_code(404);
            echo '404 Not Found';
        }
    }

    private function registerRoutes(): void
    {
        // Auth
        $this->router->map('GET', '/login', 'AuthController@loginForm');
        $this->router->map('POST', '/login', 'AuthController@login');
        $this->router->map('GET', '/login/2fa', 'AuthController@twoFactorForm');
        $this->router->map('POST', '/login/2fa', 'AuthController@twoFactorVerify');
        $this->router->map('GET', '/login/oidc', 'AuthController@oidcLogin');
        $this->router->map('GET', '/login/oidc/callback', 'AuthController@oidcCallback');
        $this->router->map('GET', '/logout', 'AuthController@logout');
        $this->router->map('GET', '/forgot-password', 'AuthController@forgotPasswordForm');
        $this->router->map('POST', '/forgot-password', 'AuthController@forgotPassword');
        $this->router->map('GET', '/reset-password/[:token]', 'AuthController@resetPasswordForm');
        $this->router->map('POST', '/reset-password', 'AuthController@resetPassword');

        // Dashboard
        $this->router->map('GET', '/', 'DashboardController@index');
        $this->router->map('GET', '/dashboard', 'DashboardController@index');
        $this->router->map('GET', '/dashboard/json', 'DashboardController@apiJson');
        $this->router->map('GET', '/dashboard/stats-json', 'DashboardController@apiStatsJson');
        $this->router->map('GET', '/dashboard/health-json', 'DashboardController@apiHealthJson');
        $this->router->map('GET', '/dashboard/active-json', 'DashboardController@apiActiveJson');

        // Clients (Agents)
        $this->router->map('GET', '/clients', 'ClientController@index');
        $this->router->map('GET', '/clients/add', 'ClientController@add');
        $this->router->map('POST', '/clients/add', 'ClientController@store');
        $this->router->map('GET', '/clients/[i:id]', 'ClientController@detail');
        $this->router->map('GET', '/clients/[i:id]/json', 'ClientController@detailJson');
        $this->router->map('GET', '/clients/[i:id]/repos', 'ClientController@repos');
        $this->router->map('GET', '/clients/[i:id]/schedules', 'ClientController@schedules');
        $this->router->map('GET', '/clients/[i:id]/restore', 'ClientController@restore');
        $this->router->map('POST', '/clients/[i:id]/edit', 'ClientController@update');
        $this->router->map('POST', '/clients/[i:id]/delete', 'ClientController@delete');
        $this->router->map('POST', '/clients/[i:id]/update-borg', 'ClientController@updateBorg');
        $this->router->map('POST', '/clients/[i:id]/update-agent', 'ClientController@updateAgent');

        // Plugins
        $this->router->map('POST', '/clients/[i:id]/plugins', 'PluginController@updateAgentPlugins');

        // Plugin Configs (named configurations)
        $this->router->map('POST', '/clients/[i:id]/plugin-configs', 'PluginConfigController@store');
        $this->router->map('POST', '/clients/[i:id]/plugin-configs/[i:configId]/edit', 'PluginConfigController@update');
        $this->router->map('POST', '/clients/[i:id]/plugin-configs/[i:configId]/delete', 'PluginConfigController@delete');
        $this->router->map('POST', '/clients/[i:id]/plugin-configs/[i:configId]/test', 'PluginConfigController@test');
        $this->router->map('GET', '/clients/[i:id]/plugin-configs/[i:configId]/test-status', 'PluginConfigController@testStatus');

        // Repositories
        $this->router->map('POST', '/repositories/create', 'RepositoryController@store');
        $this->router->map('POST', '/repositories/[i:id]/delete', 'RepositoryController@delete');
        $this->router->map('POST', '/repositories/[i:id]/maintenance', 'RepositoryController@maintenance');
        $this->router->map('POST', '/repositories/[i:id]/rename', 'RepositoryController@rename');
        $this->router->map('GET', '/clients/[i:agentId]/repo/[i:id]', 'RepositoryController@detail');
        $this->router->map('GET', '/clients/[i:agentId]/repo/[i:id]/archive/[i:archiveId]', 'RepositoryController@archiveDetail');
        $this->router->map('GET', '/clients/[i:agentId]/repo/[i:id]/archive/[i:archiveId]/files', 'RepositoryController@archiveFiles');
        $this->router->map('GET', '/clients/[i:agentId]/repo/[i:id]/archive/[i:archiveId]/deleted-summary', 'RepositoryController@archiveDeletedSummary');
        $this->router->map('POST', '/clients/[i:agentId]/repo/[i:id]/archive/[i:archiveId]/delete', 'RepositoryController@deleteArchive');
        $this->router->map('POST', '/clients/[i:agentId]/repo/[i:id]/s3-restore', 'RepositoryController@s3Restore');
        $this->router->map('POST', '/clients/[i:agentId]/repo/[i:id]/s3-config', 'RepositoryController@s3Config');
        $this->router->map('POST', '/clients/[i:agentId]/repo/[i:id]/s3-config/delete', 'RepositoryController@s3ConfigDelete');
        $this->router->map('POST', '/clients/[i:id]/restore-orphan', 'RepositoryController@restoreOrphan');
        $this->router->map('POST', '/repositories/import/verify', 'RepositoryController@verifyImport');
        $this->router->map('POST', '/repositories/import', 'RepositoryController@import');

        // Backup Plans
        $this->router->map('POST', '/plans/create', 'BackupPlanController@store');
        $this->router->map('POST', '/plans/[i:id]/edit', 'BackupPlanController@update');
        $this->router->map('POST', '/plans/[i:id]/delete', 'BackupPlanController@delete');
        $this->router->map('POST', '/plans/[i:id]/trigger', 'BackupPlanController@trigger');
        $this->router->map('POST', '/plans/[i:id]/duplicate', 'BackupPlanController@duplicate');

        // Schedules
        $this->router->map('GET',  '/schedules', 'ScheduleController@week');
        $this->router->map('POST', '/schedules/[i:id]/toggle', 'ScheduleController@toggle');
        $this->router->map('POST', '/schedules/[i:id]/delete', 'ScheduleController@delete');
        $this->router->map('POST', '/schedules/[i:id]/time',   'ScheduleController@updateTime');

        // Queue
        $this->router->map('GET', '/queue', 'QueueController@index');
        $this->router->map('GET', '/queue/json', 'QueueController@indexJson');
        $this->router->map('GET', '/queue/[i:id]', 'QueueController@detail');
        $this->router->map('GET', '/queue/[i:id]/json', 'QueueController@detailJson');
        $this->router->map('POST', '/queue/[i:id]/cancel', 'QueueController@cancel');
        $this->router->map('POST', '/queue/[i:id]/retry', 'QueueController@retry');

        // Notifications
        $this->router->map('GET', '/notifications', 'NotificationController@index');
        $this->router->map('POST', '/notifications/[i:id]/read', 'NotificationController@markRead');
        $this->router->map('POST', '/notifications/read-all', 'NotificationController@markAllRead');

        // Log
        $this->router->map('GET', '/log', 'LogController@index');

        // Settings
        $this->router->map('GET', '/settings', 'SettingsController@index');
        $this->router->map('POST', '/settings', 'SettingsController@update');
        $this->router->map('POST', '/settings/templates/add', 'SettingsController@addTemplate');
        $this->router->map('POST', '/settings/templates/[i:id]/edit', 'SettingsController@editTemplate');
        $this->router->map('POST', '/settings/templates/[i:id]/delete', 'SettingsController@deleteTemplate');
        $this->router->map('POST', '/settings/branding', 'SettingsController@saveBranding');

        // Branding icon dispenser — single-source app icon resized on demand
        // (favicons, apple-touch-icon, PWA tiles all served from one upload).
        $this->router->map('GET', '/branding/icon/[i:size]', 'BrandingController@icon');
        $this->router->map('POST', '/settings/oidc', 'SettingsController@saveOidc');
        $this->router->map('POST', '/settings/api/tokens/create', 'SettingsController@createApiToken');
        $this->router->map('POST', '/settings/api/tokens/[i:id]/revoke', 'SettingsController@revokeApiToken');
        $this->router->map('POST', '/settings/docker-setup', 'SettingsController@dockerSetup');
        $this->router->map('POST', '/settings/test-smtp', 'SettingsController@testSmtp');
        $this->router->map('POST', '/settings/check-update', 'SettingsController@checkUpdate');

        // Storage Locations
        $this->router->map('GET', '/storage-locations', 'StorageLocationController@index');
        $this->router->map('POST', '/storage-locations', 'StorageLocationController@store');
        $this->router->map('POST', '/storage-locations/[i:id]/delete', 'StorageLocationController@destroy');
        $this->router->map('POST', '/storage-locations/s3', 'StorageLocationController@saveS3');
        $this->router->map('POST', '/storage-locations/s3/test', 'StorageLocationController@testS3');
        $this->router->map('POST', '/storage-locations/s3/list-backups', 'StorageLocationController@listS3Backups');
        $this->router->map('POST', '/storage-locations/s3/restore-backup', 'StorageLocationController@restoreS3Backup');

        // Remote SSH Configs
        $this->router->map('POST', '/remote-ssh-configs/create', 'RemoteSshConfigController@store');
        $this->router->map('POST', '/remote-ssh-configs/[i:id]/update', 'RemoteSshConfigController@update');
        $this->router->map('POST', '/remote-ssh-configs/[i:id]/delete', 'RemoteSshConfigController@delete');
        $this->router->map('POST', '/remote-ssh-configs/[i:id]/test', 'RemoteSshConfigController@test');
        $this->router->map('POST', '/remote-ssh-configs/test-new', 'RemoteSshConfigController@testNew');
        $this->router->map('POST', '/remote-ssh-configs/borgbase-api-test', 'RemoteSshConfigController@testBorgBaseApi');

        // Notification Services
        $this->router->map('GET', '/notification-services', 'NotificationServiceController@index');
        $this->router->map('POST', '/notification-services', 'NotificationServiceController@store');
        $this->router->map('POST', '/notification-services/[i:id]/update', 'NotificationServiceController@update');
        $this->router->map('POST', '/notification-services/[i:id]/delete', 'NotificationServiceController@delete');
        $this->router->map('POST', '/notification-services/[i:id]/toggle', 'NotificationServiceController@toggle');
        $this->router->map('POST', '/notification-services/[i:id]/test', 'NotificationServiceController@test');
        $this->router->map('POST', '/notification-services/[i:id]/duplicate', 'NotificationServiceController@duplicate');
        // Upgrade
        $this->router->map('GET', '/upgrade', 'UpgradeController@index');
        $this->router->map('GET', '/upgrade/status', 'UpgradeController@statusJson');
        $this->router->map('POST', '/upgrade/dismiss', 'UpgradeController@dismiss');
        $this->router->map('POST', '/settings/upgrade', 'SettingsController@upgrade');
        $this->router->map('POST', '/settings/sync', 'SettingsController@sync');
        $this->router->map('POST', '/settings/upgrade-agents', 'SettingsController@upgradeAgents');
        $this->router->map('GET', '/api/agent-updates', 'SettingsController@agentUpdatesJson');
        $this->router->map('GET', '/api/borg-status', 'SettingsController@borgStatusJson');
        $this->router->map('GET', '/api/templates/[i:id]', 'SettingsController@templateJson');
        $this->router->map('POST', '/settings/offsite-storage', 'StorageLocationController@saveS3');
        $this->router->map('POST', '/settings/offsite-storage/test', 'StorageLocationController@testS3');
        $this->router->map('POST', '/settings/borg/sync', 'SettingsController@syncBorgVersions');
        $this->router->map('POST', '/settings/borg/save', 'SettingsController@saveBorgSettings');
        $this->router->map('POST', '/settings/borg/update-server', 'SettingsController@updateServerBorg');
        $this->router->map('POST', '/settings/borg/update-all', 'SettingsController@updateBorgBulk');
        $this->router->map('POST', '/settings/borg/update-agent/[i:id]', 'SettingsController@updateBorgAgent');

        // Users (admin)
        $this->router->map('GET', '/users', 'UserController@index');
        $this->router->map('POST', '/users/add', 'UserController@store');
        $this->router->map('GET', '/users/[i:id]/edit', 'UserController@edit');
        $this->router->map('POST', '/users/[i:id]/edit', 'UserController@update');
        $this->router->map('POST', '/users/[i:id]/reset-2fa', 'UserController@reset2fa');
        $this->router->map('POST', '/users/[i:id]/delete', 'UserController@delete');
        $this->router->map('POST', '/users/[i:id]/approve-oidc', 'UserController@approveOidc');

        // Profile
        $this->router->map('GET', '/profile', 'ProfileController@index');
        $this->router->map('POST', '/profile', 'ProfileController@update');
        $this->router->map('POST', '/profile/theme', 'ProfileController@theme');
        $this->router->map('POST', '/profile/detect-timezone', 'ProfileController@detectTimezone');
        $this->router->map('POST', '/profile/2fa/setup', 'ProfileController@twoFactorSetup');
        $this->router->map('POST', '/profile/2fa/enable', 'ProfileController@twoFactorEnable');
        $this->router->map('POST', '/profile/2fa/disable', 'ProfileController@twoFactorDisable');
        $this->router->map('POST', '/profile/2fa/regenerate-codes', 'ProfileController@twoFactorRegenerateCodes');
        $this->router->map('POST', '/profile/reports/preferences', 'ProfileController@reportPreferences');
        $this->router->map('POST', '/profile/reports/generate', 'ProfileController@reportGenerate');
        $this->router->map('POST', '/profile/reports/email', 'ProfileController@reportEmail');

        // Toasts (global live notifications)
        $this->router->map('GET', '/api/toasts', 'DashboardController@toasts');

        // Agent API
        $this->router->map('POST', '/api/agent/register', 'Api\\AgentApiController@register');
        $this->router->map('GET', '/api/agent/tasks', 'Api\\AgentApiController@tasks');
        $this->router->map('POST', '/api/agent/progress', 'Api\\AgentApiController@progress');
        $this->router->map('POST', '/api/agent/status', 'Api\\AgentApiController@status');
        $this->router->map('POST', '/api/agent/heartbeat', 'Api\\AgentApiController@heartbeat');
        $this->router->map('POST', '/api/agent/info', 'Api\\AgentApiController@info');
        $this->router->map('POST', '/api/agent/catalog', 'Api\\AgentApiController@catalog');
        $this->router->map('GET', '/api/agent/ssh-key', 'Api\\AgentApiController@sshKey');
        $this->router->map('GET', '/api/agent/download', 'Api\\AgentApiController@downloadFile');
        $this->router->map('GET', '/get-agent', 'Api\\AgentApiController@getAgent');
        $this->router->map('GET', '/get-agent-windows', 'Api\\AgentApiController@getAgentWindows');

        // Admin API (token-authenticated)
        $this->router->map('GET', '/api/v1/summary', 'Api\\AdminApiController@summary');
        $this->router->map('GET', '/api/v1/clients', 'Api\\AdminApiController@listClients');
        $this->router->map('POST', '/api/v1/clients', 'Api\\AdminApiController@createClient');
        $this->router->map('GET', '/api/v1/clients/[i:id]', 'Api\\AdminApiController@getClient');
        $this->router->map('PUT', '/api/v1/clients/[i:id]', 'Api\\AdminApiController@updateClient');
        $this->router->map('DELETE', '/api/v1/clients/[i:id]', 'Api\\AdminApiController@deleteClient');
        $this->router->map('GET', '/api/v1/clients/[i:id]/repositories', 'Api\\AdminApiController@listRepositories');
        $this->router->map('POST', '/api/v1/clients/[i:id]/repositories', 'Api\\AdminApiController@createRepository');
        $this->router->map('PUT', '/api/v1/clients/[i:id]/repositories/[i:repoId]', 'Api\\AdminApiController@renameRepository');
        $this->router->map('DELETE', '/api/v1/clients/[i:id]/repositories/[i:repoId]', 'Api\\AdminApiController@deleteRepository');
        $this->router->map('GET', '/api/v1/clients/[i:id]/plans', 'Api\\AdminApiController@listPlans');
        $this->router->map('POST', '/api/v1/clients/[i:id]/plans', 'Api\\AdminApiController@createPlan');
        $this->router->map('PUT', '/api/v1/clients/[i:id]/plans/[i:planId]', 'Api\\AdminApiController@updatePlan');
        $this->router->map('DELETE', '/api/v1/clients/[i:id]/plans/[i:planId]', 'Api\\AdminApiController@deletePlan');
        $this->router->map('POST', '/api/v1/clients/[i:id]/plans/[i:planId]/pause', 'Api\\AdminApiController@pausePlan');
        $this->router->map('POST', '/api/v1/clients/[i:id]/plans/[i:planId]/resume', 'Api\\AdminApiController@resumePlan');
        $this->router->map('POST', '/api/v1/clients/[i:id]/plans/[i:planId]/trigger', 'Api\\AdminApiController@triggerPlan');
        $this->router->map('GET', '/api/v1/clients/[i:id]/jobs', 'Api\\AdminApiController@listJobs');
        $this->router->map('GET', '/api/v1/clients/[i:id]/jobs/[i:jobId]', 'Api\\AdminApiController@getJob');
        $this->router->map('GET', '/api/v1/queue', 'Api\\AdminApiController@getQueue');
        $this->router->map('GET', '/api/v1/plugins', 'Api\\AdminApiController@listPlugins');
        $this->router->map('GET', '/api/v1/plugins/schema', 'Api\\AdminApiController@getPluginSchema');
        $this->router->map('GET', '/api/v1/clients/[i:id]/plugin-configs', 'Api\\AdminApiController@listPluginConfigs');
        $this->router->map('POST', '/api/v1/clients/[i:id]/plugin-configs', 'Api\\AdminApiController@createPluginConfig');
        $this->router->map('GET', '/api/v1/storage', 'Api\\AdminApiController@listStorageLocations');

        // Catalog & Restore (client-facing)
        $this->router->map('GET', '/clients/[i:id]/catalog/[i:archive_id]', 'ClientController@catalog');
        $this->router->map('GET', '/clients/[i:id]/catalog/[i:archive_id]/tree', 'ClientController@catalogTree');
        $this->router->map('GET', '/clients/[i:id]/catalog/search-all', 'ClientController@catalogSearchAll');
        $this->router->map('POST', '/clients/[i:id]/restore', 'ClientController@restoreSubmit');
        $this->router->map('POST', '/clients/[i:id]/restore-mysql', 'ClientController@restoreMysqlSubmit');
        $this->router->map('POST', '/clients/[i:id]/restore-pg', 'ClientController@restorePgSubmit');
        $this->router->map('POST', '/clients/[i:id]/restore-mongo', 'ClientController@restoreMongoSubmit');
        $this->router->map('GET', '/clients/[i:id]/archive/[i:archive_id]/databases', 'ClientController@archiveDatabases');
        $this->router->map('POST', '/clients/[i:id]/download', 'ClientController@download');
    }
}
