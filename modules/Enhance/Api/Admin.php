<?php

declare(strict_types=1);
/**
 * Copyright 2026 Kai Randles
 * SPDX-License-Identifier: Apache-2.0.
 */

namespace Box\Mod\Enhance\Api;

use FOSSBilling\Tools;
use FOSSBilling\Validation\Api\RequiredParams;

/**
 * Hosting plan sync for Enhance servers.
 */
class Admin extends \FOSSBilling\Api\AbstractApi
{
    /**
     * Hosting servers that use the Enhance manager.
     */
    public function server_get_list($data): array
    {
        $this->checkPermissions('servicehosting', 'manage_plans');

        return $this->getService()->getServers();
    }

    /**
     * Packages configured on an Enhance server, with the hosting plan each one already matches.
     */
    #[RequiredParams(['id' => 'Server ID was not passed'])]
    public function server_get_packages($data): array
    {
        $this->checkPermissions('servicehosting', 'manage_plans');

        return $this->getService()->getServerPackages($this->getService()->getServer((int) $data['id']));
    }

    /**
     * Create hosting plans from the packages configured on an Enhance server.
     *
     * @optional array $packages - package IDs to sync. All packages when omitted
     * @optional bool $overwrite - also update hosting plans that already match a package. Default: false
     *
     * @return array counts of created, updated and skipped hosting plans
     */
    #[RequiredParams(['server_id' => 'Server ID was not passed'])]
    public function hp_sync($data): array
    {
        $this->checkPermissions('servicehosting', 'manage_plans');

        $packages = array_values(array_map(strval(...), (array) ($data['packages'] ?? [])));

        return $this->getService()->syncHostingPlans($this->getService()->getServer((int) $data['server_id']), $packages, Tools::normalizeBoolean($data['overwrite'] ?? false));
    }
}
