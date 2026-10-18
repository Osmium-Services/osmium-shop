<?php

declare(strict_types=1);

namespace Osmium\Modules\Admin\Controllers;

use Osmium\Modules\Admin\Core\AdminController;
use Osmium\Modules\Admin\Services\ConfigJson;
use Osmium\Modules\Admin\Services\OsmiumAdmin;
use Osmium\Core\Osmium;

require_once \Osmium\Core\Library\AdminPaths::path('core/AdminController.php');
require_once \Osmium\Core\Library\AdminPaths::path('services/ConfigJson.php');

/**
 * What the shop and payment settings pages share: reading the shop and checkout config files, the saved/error
 * messages, the CSRF check and saving a config file.
 */
abstract class ShopSettingsBase extends AdminController
{
    protected string $shopConfigFilePath = 'app/config/modules/shop.json.php'; // Properties, not constants, so a test can point them at a temp copy
    protected string $checkoutConfigFilePath = 'app/config/modules/checkout.json.php';

    /** @var array<string, mixed> */
    protected array $config = [];

    public function __construct(Osmium $osmium, OsmiumAdmin $admin, array &$data)
    {
        parent::__construct($osmium, $admin, $data);
        $this->loadConfig();
        $this->handleMessages();
    }

    private function loadConfig(): void
    {
        foreach ([$this->shopConfigFilePath, $this->checkoutConfigFilePath] as $path) {
            $content = \file_get_contents($path);
            $jsonStart = \strpos(haystack: $content, needle: '{');
            $this->config = \array_merge($this->config, \json_decode(json: \substr(string: $content, offset: $jsonStart), associative: true));
        }

        $this->data['admin']['config'] = $this->config;
    }

    private function handleMessages(): void
    {
        $this->data['admin']['settingsSaved'] = $_SESSION['settings_saved'] ?? false;
        $this->data['admin']['settingsError'] = $_SESSION['settings_error'] ?? false;

        unset($_SESSION['settings_saved'], $_SESSION['settings_error']);
    }

    protected function validateCsrfOrRedirect(string $path): void
    {
        $csrfValid = $this->admin->auth->validateCsrf();
        if (!$csrfValid) {
            $_SESSION['settings_error'] = 'Invalid form submission. Please try again.';
            $this->redirect($path);
        }
    }

    protected function saveConfigAndRedirect(string $configContent, string $path, string $filePath): void
    {
        $saved = ConfigJson::save($configContent, $filePath);
        if (!$saved) {
            $_SESSION['settings_error'] = 'Failed to save settings - invalid configuration generated.';
            $this->redirect($path);
        }

        $hasError = isset($_SESSION['settings_error']);
        if (!$hasError) $_SESSION['settings_saved'] = true;

        $this->redirect($path);
    }

    protected function isPost(): bool
    {
        return $_SERVER['REQUEST_METHOD'] === 'POST';
    }
}
