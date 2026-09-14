<?php

declare(strict_types=1);
/**
 * Copyright 2026 Kai Randles
 * SPDX-License-Identifier: Apache-2.0.
 */

namespace Box\Mod\Enhance;

use Box\Mod\Servicehosting\Entity\ServiceHostingHp;
use Box\Mod\Servicehosting\Entity\ServiceHostingServer;
use FOSSBilling\InformationException;
use FOSSBilling\InjectionAwareInterface;

class Service implements InjectionAwareInterface
{
    protected ?\Pimple\Container $di = null;

    public function setDi(\Pimple\Container $di): void
    {
        $this->di = $di;
    }

    public function getDi(): ?\Pimple\Container
    {
        return $this->di;
    }

    /**
     * Hosting servers in FOSSBilling that use the Enhance manager.
     *
     * @return list<array{id: int, name: string, hostname: string}>
     */
    public function getServers(): array
    {
        $servers = [];
        foreach ($this->di['em']->getRepository(ServiceHostingServer::class)->findBy(['manager' => 'Enhance'], ['name' => 'ASC']) as $server) {
            $servers[] = ['id' => (int) $server->getId(), 'name' => (string) $server->getName(), 'hostname' => (string) $server->getHostname()];
        }

        return $servers;
    }

    public function getServer(int $id): ServiceHostingServer
    {
        $server = $this->di['em']->getRepository(ServiceHostingServer::class)->find($id);
        if (!$server instanceof ServiceHostingServer || $server->getManager() !== 'Enhance') {
            throw new InformationException('Enhance server not found');
        }

        return $server;
    }

    /**
     * Packages configured on the server, each with the hosting plan it already corresponds to, if any.
     * A plan matches by the package's custom values (`plan_id`) first and by name second.
     */
    public function getServerPackages(ServiceHostingServer $server): array
    {
        $plans = $this->di['em']->getRepository(ServiceHostingHp::class)->findAll();
        $packages = [];
        foreach ($this->manager($server)->listPackages() as $package) {
            [$plan, $matchedBy] = $this->findHostingPlanForPackage($plans, $package);
            $package['hosting_plan_id'] = $plan?->getId();
            $package['hosting_plan_name'] = $plan?->getName();
            $package['matched_by'] = $matchedBy;
            $packages[] = $package;
        }

        return $packages;
    }

    /**
     * Creates hosting plans for the given packages and, when asked to, updates the plans that already match one.
     *
     * @param list<string> $packageIds package IDs to sync; all packages when empty
     *
     * @return array{created: int, updated: int, skipped: int}
     */
    public function syncHostingPlans(ServiceHostingServer $server, array $packageIds, bool $overwrite): array
    {
        $hostingService = $this->di['mod_service']('servicehosting');
        $planRepository = $this->di['em']->getRepository(ServiceHostingHp::class);
        $summary = ['created' => 0, 'updated' => 0, 'skipped' => 0];
        $limits = ['quota', 'bandwidth', 'max_addon', 'max_sub', 'max_park', 'max_ftp', 'max_sql', 'max_pop'];

        foreach ($this->getServerPackages($server) as $package) {
            if ($packageIds !== [] && !in_array((string) $package['id'], $packageIds, true)) {
                continue;
            }

            $data = ['config' => $package['config'] ?? []];
            foreach ($limits as $limit) {
                if (array_key_exists($limit, $package)) {
                    $data[$limit] = $package[$limit] === null ? 'unlimited' : (string) $package[$limit];
                }
            }

            if ($package['hosting_plan_id'] !== null) {
                if (!$overwrite) {
                    ++$summary['skipped'];

                    continue;
                }

                $plan = $planRepository->find($package['hosting_plan_id']);
                if ($plan instanceof ServiceHostingHp) {
                    $hostingService->updateHp($plan, $data + ['name' => $package['name']]);
                    ++$summary['updated'];
                }

                continue;
            }

            $id = $hostingService->createHp($package['name'], $data);
            $plan = $id !== null ? $planRepository->find($id) : null;
            if ($plan instanceof ServiceHostingHp && $data['config'] !== []) {
                $hostingService->updateHp($plan, ['config' => $data['config']]);
            }
            ++$summary['created'];
        }

        $this->di['logger']->info('Synced hosting plans from Enhance server {server_id}: {created} created, {updated} updated, {skipped} skipped', ['server_id' => $server->getId()] + $summary);

        return $summary;
    }

    private function manager(ServiceHostingServer $server): \Server_Manager_Enhance
    {
        $manager = $this->di['mod_service']('servicehosting')->getServerManager($server);
        if (!$manager instanceof \Server_Manager_Enhance) {
            throw new InformationException('The Enhance server manager is not installed. Copy Enhance.php to library/Server/Manager/.');
        }

        return $manager;
    }

    /**
     * @param ServiceHostingHp[] $plans
     *
     * @return array{0: ?ServiceHostingHp, 1: ?string}
     */
    private function findHostingPlanForPackage(array $plans, array $package): array
    {
        $config = $package['config'] ?? [];
        if ($config !== []) {
            foreach ($plans as $plan) {
                $planConfig = json_decode($plan->getConfig() ?? '', true) ?? [];
                foreach ($config as $key => $value) {
                    if ((string) ($planConfig[$key] ?? '') !== (string) $value) {
                        continue 2;
                    }
                }

                return [$plan, 'config'];
            }
        }

        foreach ($plans as $plan) {
            if (strcasecmp((string) $plan->getName(), (string) $package['name']) === 0) {
                return [$plan, 'name'];
            }
        }

        return [null, null];
    }
}
