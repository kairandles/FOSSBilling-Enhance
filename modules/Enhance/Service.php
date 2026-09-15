<?php

declare(strict_types=1);
/**
 * Copyright 2026 Kai Randles
 * SPDX-License-Identifier: Apache-2.0.
 */

namespace Box\Mod\Enhance;

use FOSSBilling\InformationException;
use FOSSBilling\InjectionAwareInterface;

/**
 * FOSSBilling manages hosting through RedBean models in the 0.8 releases and through Doctrine
 * entities on the main line. Both are supported: rows are read with whichever layer the install
 * uses, so that the hosting service is always handed the object type its own methods declare.
 */
class Service implements InjectionAwareInterface
{
    private const string MANAGER = 'Enhance';
    private const string SERVICE_TYPE = 'hosting';
    private const string STATUS_ACTIVE = 'active';
    private const string STATUS_SUSPENDED = 'suspended';

    /**
     * Entity classes of the main line, named rather than imported because the 0.8 releases do not
     * ship them and the names are only ever resolved on installs that do.
     */
    private const string ORDER_ENTITY = 'Box\Mod\Order\Entity\Order';
    private const string SERVER_ENTITY = 'Box\Mod\Servicehosting\Entity\ServiceHostingServer';
    private const string PLAN_ENTITY = 'Box\Mod\Servicehosting\Entity\ServiceHostingHp';

    protected ?\Pimple\Container $di = null;

    private ?bool $entities = null;

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
        foreach ($this->serverRows() as $server) {
            $servers[] = [
                'id' => (int) $this->column($server, 'id'),
                'name' => (string) $this->column($server, 'name'),
                'hostname' => (string) $this->column($server, 'hostname'),
            ];
        }

        return $servers;
    }

    public function getServer(int $id): object
    {
        $server = $this->usesEntities()
            ? $this->di['em']->getRepository(self::SERVER_ENTITY)->find($id)
            : $this->di['db']->load('ServiceHostingServer', $id);

        if (!is_object($server) || $this->column($server, 'manager') !== self::MANAGER) {
            throw new InformationException('Enhance server not found');
        }

        return $server;
    }

    /**
     * Packages configured on the server, each with the hosting plan it already corresponds to, if any.
     * A plan matches by the package's custom values (`plan_id`) first and by name second.
     */
    public function getServerPackages(object $server): array
    {
        $plans = $this->planRows();
        $packages = [];
        foreach ($this->manager($server)->listPackages() as $package) {
            [$plan, $matchedBy] = $this->findHostingPlanForPackage($plans, $package);
            $package['hosting_plan_id'] = $plan === null ? null : (int) $this->column($plan, 'id');
            $package['hosting_plan_name'] = $plan === null ? null : (string) $this->column($plan, 'name');
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
    public function syncHostingPlans(object $server, array $packageIds, bool $overwrite): array
    {
        $hostingService = $this->di['mod_service']('servicehosting');
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

                $plan = $this->planRow((int) $package['hosting_plan_id']);
                if ($plan !== null) {
                    $hostingService->updateHp($plan, $data + ['name' => $package['name']]);
                    ++$summary['updated'];
                }

                continue;
            }

            $id = $hostingService->createHp($package['name'], $data);
            $plan = $id === null ? null : $this->planRow((int) $id);
            if ($plan !== null && $data['config'] !== []) {
                $hostingService->updateHp($plan, ['config' => $data['config']]);
            }
            ++$summary['created'];
        }

        $this->di['logger']->info('Synced hosting plans from Enhance server {server_id}: {created} created, {updated} updated, {skipped} skipped', ['server_id' => $this->column($server, 'id')] + $summary);

        return $summary;
    }

    /**
     * Live usage for one of the client's hosting orders. The order ID comes off a URL, so the order is
     * looked up for that client only.
     */
    public function usageForOrder(int $orderId, int $clientId): array
    {
        $order = $this->clientOrder($orderId, $clientId);
        if ($order === null) {
            throw new InformationException('Order not found');
        }

        $model = $this->column($order, 'service_type') === self::SERVICE_TYPE
            ? $this->di['mod_service']('order')->getOrderService($order)
            : null;
        if (!is_object($model)) {
            throw new InformationException('Order is not activated');
        }

        [$manager, $account] = $this->di['mod_service']('servicehosting')->_getAM($model);
        if (!$manager instanceof \Server_Manager_Enhance) {
            throw new InformationException('This order is not hosted on an Enhance server');
        }

        return $manager->accountUsage($account);
    }

    /**
     * The client's active and suspended hosting orders, each with its usage. A failure is reported
     * against its own order so one unreachable server does not blank the page.
     *
     * @return list<array{order_id: int, title: string, domain: string, status: string, usage: ?array, error: ?string}>
     */
    public function accountsForClient(int $clientId): array
    {
        $accounts = [];
        foreach ($this->clientHostingOrders($clientId) as $order) {
            $orderId = (int) $this->column($order, 'id');
            $model = $this->di['mod_service']('order')->getOrderService($order);
            $row = [
                'order_id' => $orderId,
                'title' => (string) $this->column($order, 'title'),
                'domain' => is_object($model) ? (string) $this->column($model, 'sld') . (string) $this->column($model, 'tld') : '',
                'status' => (string) $this->column($order, 'status'),
                'usage' => null,
                'error' => null,
            ];

            try {
                $row['usage'] = $this->usageForOrder($orderId, $clientId);
            } catch (\Throwable $e) {
                $this->di['logger']->warning('Enhance usage for order {order_id} is unavailable: {message}', ['order_id' => $orderId, 'message' => $e->getMessage()]);
                $row['error'] = $e instanceof InformationException ? $e->getMessage() : 'Usage is unavailable right now';
            }

            $accounts[] = $row;
        }

        return $accounts;
    }

    /**
     * The signed-in client's ID. The identity is a Doctrine entity on the main line and a RedBean
     * model in the 0.8 releases, which expose their columns differently.
     */
    public function clientId(object $identity): int
    {
        return (int) $this->column($identity, 'id');
    }

    private function manager(object $server): \Server_Manager_Enhance
    {
        $manager = $this->di['mod_service']('servicehosting')->getServerManager($server);
        if (!$manager instanceof \Server_Manager_Enhance) {
            throw new InformationException('The Enhance server manager is not installed. Copy Enhance.php to library/Server/Manager/.');
        }

        return $manager;
    }

    /**
     * @param list<object> $plans
     *
     * @return array{0: ?object, 1: ?string}
     */
    private function findHostingPlanForPackage(array $plans, array $package): array
    {
        $config = $package['config'] ?? [];
        if ($config !== []) {
            foreach ($plans as $plan) {
                $planConfig = json_decode((string) $this->column($plan, 'config'), true) ?? [];
                foreach ($config as $key => $value) {
                    if ((string) ($planConfig[$key] ?? '') !== (string) $value) {
                        continue 2;
                    }
                }

                return [$plan, 'config'];
            }
        }

        foreach ($plans as $plan) {
            if (strcasecmp((string) $this->column($plan, 'name'), (string) $package['name']) === 0) {
                return [$plan, 'name'];
            }
        }

        return [null, null];
    }

    /**
     * @return list<object>
     */
    private function serverRows(): array
    {
        if ($this->usesEntities()) {
            return $this->di['em']->getRepository(self::SERVER_ENTITY)->findBy(['manager' => self::MANAGER], ['name' => 'ASC']);
        }

        return array_values($this->di['db']->find('ServiceHostingServer', 'manager = :manager ORDER BY name ASC', [':manager' => self::MANAGER]));
    }

    /**
     * @return list<object>
     */
    private function planRows(): array
    {
        if ($this->usesEntities()) {
            return $this->di['em']->getRepository(self::PLAN_ENTITY)->findAll();
        }

        return array_values($this->di['db']->find('ServiceHostingHp'));
    }

    private function planRow(int $id): ?object
    {
        $plan = $this->usesEntities()
            ? $this->di['em']->getRepository(self::PLAN_ENTITY)->find($id)
            : $this->di['db']->load('ServiceHostingHp', $id);

        return is_object($plan) ? $plan : null;
    }

    private function clientOrder(int $orderId, int $clientId): ?object
    {
        if ($this->usesEntities()) {
            return $this->di['em']->getRepository(self::ORDER_ENTITY)->findOneBy(['id' => $orderId, 'clientId' => $clientId]);
        }

        return $this->di['db']->findOne('ClientOrder', 'id = :id AND client_id = :client_id', [':id' => $orderId, ':client_id' => $clientId]);
    }

    /**
     * @return list<object>
     */
    private function clientHostingOrders(int $clientId): array
    {
        if ($this->usesEntities()) {
            $criteria = ['clientId' => $clientId, 'serviceType' => self::SERVICE_TYPE, 'status' => [self::STATUS_ACTIVE, self::STATUS_SUSPENDED]];

            return $this->di['em']->getRepository(self::ORDER_ENTITY)->findBy($criteria, ['id' => 'ASC']);
        }

        $orders = $this->di['db']->find('ClientOrder', 'client_id = :client_id AND service_type = :service_type AND status IN (:active, :suspended) ORDER BY id ASC', [
            ':client_id' => $clientId,
            ':service_type' => self::SERVICE_TYPE,
            ':active' => self::STATUS_ACTIVE,
            ':suspended' => self::STATUS_SUSPENDED,
        ]);

        return array_values($orders);
    }

    /**
     * Whether this install manages hosting with Doctrine entities. The hosting service's own signature
     * is the dependable test: an install can carry entity classes left behind by an update while its
     * services still expect RedBean models, and passing those services the wrong object is fatal.
     */
    private function usesEntities(): bool
    {
        if ($this->entities === null) {
            $type = (new \ReflectionMethod($this->di['mod_service']('servicehosting'), 'getServerManager'))->getParameters()[0]->getType();
            $this->entities = $type instanceof \ReflectionNamedType && $type->getName() === self::SERVER_ENTITY;
        }

        return $this->entities;
    }

    /**
     * Doctrine entities expose their columns through getters, RedBean models as properties.
     */
    private function column(object $row, string $column): mixed
    {
        $getter = 'get' . str_replace('_', '', ucwords($column, '_'));

        return method_exists($row, $getter) ? $row->{$getter}() : $row->{$column};
    }
}
