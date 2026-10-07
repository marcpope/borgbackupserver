<?php
/**
 * Migration 128: encrypt plugin secrets saved in plain text
 *
 * Editing a plugin configuration in the web form stored a newly typed
 * password or key without encrypting it. Reading still worked, because
 * decryption falls back to the stored value, so nothing looked wrong.
 * This encrypts every sensitive field that does not decrypt. The values
 * are AES-GCM, so a plain-text value never decrypts by accident.
 */

use BBS\Services\Encryption;
use BBS\Services\PluginManager;

$manager = new PluginManager();
$schemas = [];
$fixed = 0;

foreach ($db->fetchAll("SELECT pc.id, pc.config, p.slug FROM plugin_configs pc JOIN plugins p ON p.id = pc.plugin_id") as $row) {
    $config = json_decode($row['config'] ?? '', true);
    if (!is_array($config)) {
        continue;
    }
    $schemas[$row['slug']] ??= $manager->getPluginSchema($row['slug']);
    $fields = ['password'];
    foreach ($schemas[$row['slug']] as $field => $def) {
        if (!empty($def['sensitive'])) {
            $fields[] = $field;
        }
    }

    $changed = false;
    foreach (array_unique($fields) as $field) {
        $value = $config[$field] ?? null;
        if (!is_string($value) || $value === '') {
            continue;
        }
        try {
            Encryption::decrypt($value);
        } catch (\Throwable $e) {
            $config[$field] = Encryption::encrypt($value);
            $changed = true;
        }
    }
    if ($changed) {
        $db->update('plugin_configs', ['config' => json_encode($config)], 'id = ?', [$row['id']]);
        $fixed++;
    }
}

echo "  Encrypted plain-text secrets in {$fixed} plugin configuration(s).\n";
