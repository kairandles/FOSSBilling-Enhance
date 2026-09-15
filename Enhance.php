<?php

declare(strict_types=1);
/**
 * Enhance server manager for FOSSBilling.
 *
 * Copyright 2026 Kai Randles
 * SPDX-License-Identifier: Apache-2.0.
 *
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

use FOSSBilling\Tools;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

/**
 * Enhance keeps a customer organization per client, a subscription per plan and a website per domain.
 * Nothing about them is stored in FOSSBilling: every operation looks the customer up by the client's
 * email and the website by the account domain, so accounts that already exist on the panel can be
 * imported and createAccount() can safely be retried.
 */
class Server_Manager_Enhance extends Server_Manager
{
    private const string TYPE = 'Enhance';
    private const int DEFAULT_PORT = 443;
    private const int PAGE_SIZE = 100;
    private const int MAX_PAGES = 100;
    private const string ROLE_OWNER = 'Owner';
    private const int METRICS_DAYS = 30;

    /** Website kinds shown to clients; control panel, webmail and hostname sites are Enhance's own. */
    private const array WEBSITE_KINDS = ['normal', 'staging'];

    /** Enhance resource names that are counts, and the keys accountUsage() reports them under. */
    private const array COUNTED_RESOURCES = [
        'websites' => 'websites',
        'addonDomains' => 'addon_domains',
        'subdomains' => 'subdomains',
        'domainAliases' => 'domain_aliases',
        'mailboxes' => 'mailboxes',
        'mysqlDbs' => 'databases',
        'ftpUsers' => 'ftp_users',
    ];

    public static function getForm(): array
    {
        return [
            'label' => 'Enhance',
            'form' => [
                'credentials' => [
                    'fields' => [
                        [
                            'name' => 'username',
                            'type' => 'text',
                            'label' => 'Organization ID',
                            'placeholder' => 'UUID of the organization the API token belongs to (Settings > Organization in Enhance)',
                            'required' => true,
                        ],
                        [
                            'name' => 'accesshash',
                            'type' => 'text',
                            'label' => 'API token',
                            'placeholder' => 'Access token created under Settings > Access tokens in Enhance',
                            'required' => true,
                            'secret' => true,
                        ],
                    ],
                ],
            ],
        ];
    }

    public function init(): void
    {
        if (empty($this->_config['host'])) {
            throw new Server_Exception('The ":server_manager" server manager is not fully configured. Please configure the :missing', [':server_manager' => self::TYPE, ':missing' => 'hostname'], 2001);
        }

        if (empty($this->_config['username'])) {
            throw new Server_Exception('The ":server_manager" server manager is not fully configured. Please configure the :missing', [':server_manager' => self::TYPE, ':missing' => 'organization ID'], 2001);
        }

        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', (string) $this->_config['username'])) {
            throw new Server_Exception('The ":server_manager" server manager is not fully configured. Please configure the :missing', [':server_manager' => self::TYPE, ':missing' => 'organization ID (it must be the organization UUID, not the API token)'], 2001);
        }

        if (empty($this->_config['accesshash'])) {
            throw new Server_Exception('The ":server_manager" server manager is not fully configured. Please configure the :missing', [':server_manager' => self::TYPE, ':missing' => 'API token'], 2001);
        }

        $this->_config['port'] = Tools::normalizePort($this->_config['port'] ?? null, self::DEFAULT_PORT);
    }

    public function getResellerLoginUrl(?Server_Account $account = null): string
    {
        return $this->getLoginUrl();
    }

    /**
     * Without an account this is the panel address used in emails and the admin area.
     * With an account it is a one-time SSO link for the client's Enhance login.
     */
    public function getLoginUrl(?Server_Account $account = null): string
    {
        if ($account === null) {
            return $this->baseUrl() . '/';
        }

        try {
            [$org] = $this->resolve($account);
            $member = $this->findMember($org['id'], $this->clientEmail($account));
            if ($member === null) {
                throw new Server_Exception('No :type: login for :email: was found', [':type:' => self::TYPE, ':email:' => $this->clientEmail($account)]);
            }

            $url = $this->request('GET', "/orgs/{$org['id']}/members/{$member['id']}/sso");
            if (is_string($url) && $url !== '') {
                return $url;
            }

            $this->getLog()->error('Unexpected SSO response from the Enhance server');
        } catch (Server_Exception $e) {
            $this->getLog()->error("Failed to get login URL: {$e->getMessage()}.");
        }

        return $this->baseUrl() . '/';
    }

    public static function supportsPackageSync(): bool
    {
        return true;
    }

    public function getPort(): int
    {
        return (int) ($this->_config['port'] ?? self::DEFAULT_PORT);
    }

    public function testConnection(): bool
    {
        $org = $this->request('GET', $this->orgPath());

        if (!is_array($org) || ($org['id'] ?? null) !== $this->orgId() || ($org['status'] ?? '') === 'deleted') {
            throw new Server_Exception('Failed to connect to the :type: server. Please verify your credentials and configuration', [':type:' => self::TYPE]);
        }

        return true;
    }

    /**
     * Enhance plans carry many settings FOSSBilling has no field for, so only the limits that line up are mapped.
     */
    public function listPackages(): array
    {
        $packages = [];
        foreach ($this->paginate($this->orgPath('/plans')) as $plan) {
            if (!isset($plan['id'], $plan['name'])) {
                continue;
            }

            $totals = [];
            foreach ($plan['resources'] ?? [] as $resource) {
                if (isset($resource['name'])) {
                    $totals[$resource['name']] = $resource['total'] ?? null;
                }
            }

            $packages[] = [
                'id' => (string) $plan['id'],
                'name' => (string) $plan['name'],
                'quota' => $this->megabytes($totals['diskspace'] ?? null),
                'bandwidth' => $this->megabytes($totals['transfer'] ?? null),
                'max_addon' => $this->limit($totals['addonDomains'] ?? null),
                'max_sub' => $this->limit($totals['subdomains'] ?? null),
                'max_park' => $this->limit($totals['domainAliases'] ?? null),
                'max_ftp' => $this->limit($totals['ftpUsers'] ?? null),
                'max_sql' => $this->limit($totals['mysqlDbs'] ?? null),
                'max_pop' => $this->limit($totals['mailboxes'] ?? null),
                'config' => ['plan_id' => (string) $plan['id']],
            ];
        }

        return $packages;
    }

    /**
     * Enhance logins are email addresses and each website has its own server, so report both back.
     */
    public function synchronizeAccount(Server_Account $account): Server_Account
    {
        [$org, $website] = $this->resolve($account);
        $fresh = $this->request('GET', "/orgs/{$org['id']}/websites/{$website['id']}");

        $updated = clone $account;
        $updated->setUsername($this->clientEmail($account));

        $ip = $this->primaryIp(is_array($fresh) ? $fresh : $website);
        if ($ip !== null) {
            $updated->setIp($ip);
        }

        return $updated;
    }

    /**
     * Live usage of the subscription behind the account. Enhance tracks usage per subscription, so the
     * figures cover every website on it, not only the account domain. Sizes are in MB; a null limit is unlimited.
     *
     * @return array{plan_name: string, status: string, suspended: bool, disk_used_mb: int, disk_quota_mb: ?int, bandwidth_used_mb: int, bandwidth_limit_mb: ?int, counts: array<string, array{used: int, limit: ?int}>, websites: list<array<string, mixed>>}
     */
    public function accountUsage(Server_Account $account): array
    {
        [$org, $website] = $this->resolve($account);
        $subscriptionId = $this->subscriptionId($website);
        if ($subscriptionId === null) {
            $this->fail(__trans('read the account usage'));
        }

        $subscription = $this->request('GET', $this->subscriptionPath($org['id'], $subscriptionId));
        if (!is_array($subscription)) {
            $this->fail(__trans('read the account usage'));
        }

        $resources = [];
        foreach ($subscription['resources'] ?? [] as $resource) {
            if (isset($resource['name'])) {
                $resources[$resource['name']] = $resource;
            }
        }

        $counts = [];
        foreach (self::COUNTED_RESOURCES as $name => $key) {
            if (isset($resources[$name])) {
                $counts[$key] = ['used' => (int) ($resources[$name]['usage'] ?? 0), 'limit' => $this->limit($resources[$name]['total'] ?? null)];
            }
        }

        return [
            'plan_name' => (string) ($subscription['planName'] ?? ''),
            'status' => (string) ($subscription['status'] ?? ''),
            'suspended' => ($subscription['status'] ?? '') === 'suspended' || $this->websiteSuspended($website),
            'disk_used_mb' => $this->megabytes($resources['diskspace']['usage'] ?? 0) ?? 0,
            'disk_quota_mb' => $this->megabytes($resources['diskspace']['total'] ?? null),
            'bandwidth_used_mb' => $this->megabytes($resources['transfer']['usage'] ?? 0) ?? 0,
            'bandwidth_limit_mb' => $this->megabytes($resources['transfer']['total'] ?? null),
            'counts' => $counts,
            'websites' => $this->websiteUsage($org['id'], $subscriptionId, (string) ($website['domain']['domain'] ?? '')),
        ];
    }

    /**
     * Every website on the subscription with its size, last successful backup and the traffic of the last
     * 30 days, the account domain first. Enhance's own preview hostnames are not listed among the aliases.
     * Metrics and backups are a call each per website and are left out for that site when they fail.
     *
     * @return list<array{domain: string, primary: bool, aliases: list<string>, kind: string, suspended: bool, disk_used_bytes: int, php_version: string, server: string, created_at: string, last_backup_at: ?string, stats: ?array{days: int, visitors: int, requests: int, bot_requests: int, bytes_sent: int, bytes_received: int}}>
     */
    private function websiteUsage(string $orgId, int $subscriptionId, string $primaryDomain): array
    {
        $start = new DateTimeImmutable('-' . self::METRICS_DAYS . ' days', new DateTimeZone('UTC'));
        $websites = [];
        foreach ($this->paginate("/orgs/{$orgId}/websites", ['subscriptionId' => $subscriptionId]) as $site) {
            if (empty($site['id']) || ($site['status'] ?? '') === 'deleted' || !in_array($site['kind'] ?? 'normal', self::WEBSITE_KINDS, true)) {
                continue;
            }
            $domain = (string) ($site['domain']['domain'] ?? '');

            $stats = null;

            try {
                $metrics = $this->request('GET', "/orgs/{$orgId}/websites/{$site['id']}/metrics", [], ['start' => $start->format('Y-m-d\TH:i:s\Z'), 'granularity' => 'day']);
                $stats = $this->sumMetrics(is_array($metrics) ? ($metrics['items'] ?? []) : []);
            } catch (Server_Exception $e) {
                $this->getLog()->warning("Metrics for website {$domain} were not available: {$e->getMessage()}");
            }

            $lastBackup = null;

            try {
                $backups = $this->request('GET', "/orgs/{$orgId}/websites/{$site['id']}/backups");
                $lastBackup = $this->lastSuccessfulBackup(is_array($backups) ? ($backups['items'] ?? []) : []);
            } catch (Server_Exception $e) {
                $this->getLog()->warning("Backups for website {$domain} were not available: {$e->getMessage()}");
            }

            $aliases = [];
            foreach ($site['aliases'] ?? [] as $alias) {
                if (!empty($alias['domain']) && ($alias['kind'] ?? 'alias') === 'alias') {
                    $aliases[] = (string) $alias['domain'];
                }
            }

            $websites[] = [
                'domain' => $domain,
                'primary' => strcasecmp($domain, $primaryDomain) === 0,
                'aliases' => $aliases,
                'kind' => (string) ($site['kind'] ?? 'normal'),
                'suspended' => $this->websiteSuspended($site),
                'disk_used_bytes' => (int) ($site['size'] ?? 0),
                'php_version' => preg_replace('/^php(\d)(\d+)$/', '$1.$2', (string) ($site['phpVersion'] ?? '')),
                'server' => (string) ($site['appServerName'] ?? ''),
                'created_at' => (string) ($site['createdAt'] ?? ''),
                'last_backup_at' => $lastBackup,
                'stats' => $stats,
            ];
        }

        usort($websites, static fn (array $a, array $b): int => (int) $b['primary'] <=> (int) $a['primary']);

        return $websites;
    }

    /**
     * Newest backup whose files were stored, as an ISO 8601 date in UTC. Partial and failed runs are skipped.
     */
    private function lastSuccessfulBackup(array $backups): ?string
    {
        $latest = null;
        foreach ($backups as $backup) {
            if (!is_array($backup) || ($backup['homeDirStatus'] ?? '') !== 'successful' || empty($backup['startedAt'])) {
                continue;
            }
            $startedAt = (string) $backup['startedAt'];
            if ($latest === null || strcmp($startedAt, $latest) > 0) {
                $latest = $startedAt;
            }
        }

        return $latest;
    }

    /**
     * @return array{days: int, visitors: int, requests: int, bot_requests: int, bytes_sent: int, bytes_received: int}
     */
    private function sumMetrics(array $entries): array
    {
        $sum = ['days' => self::METRICS_DAYS, 'visitors' => 0, 'requests' => 0, 'bot_requests' => 0, 'bytes_sent' => 0, 'bytes_received' => 0];
        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $sum['visitors'] += (int) ($entry['uniqueHits'] ?? 0);
            $sum['requests'] += (int) ($entry['totalHits'] ?? 0);
            $sum['bot_requests'] += (int) ($entry['botHits'] ?? 0);
            $sum['bytes_sent'] += (int) ($entry['bytesSent'] ?? 0);
            $sum['bytes_received'] += (int) ($entry['bytesReceived'] ?? 0);
        }

        return $sum;
    }

    /**
     * Enhance reports a suspended website as status "disabled" with the suspending organization set.
     */
    private function websiteSuspended(array $website): bool
    {
        return ($website['status'] ?? '') === 'disabled' || !empty($website['suspendedBy']);
    }

    /**
     * Create the customer organization, login, subscription and website, reusing whatever already exists.
     */
    public function createAccount(Server_Account $account): bool
    {
        $email = $this->clientEmail($account);
        $domain = (string) $account->getDomain();
        $planId = $this->resolvePlanId($account->getPackage());

        $org = $this->findCustomerOrg($account);
        $createdOrg = false;
        if ($org === null) {
            $client = $account->getClient();
            $name = trim((string) $client?->getCompany()) ?: trim((string) $client?->getFullName()) ?: $email;
            $created = $this->request('POST', $this->orgPath('/customers'), ['name' => $name]);
            $org = ['id' => $this->requireId($created, 'create customer organization')];
            $createdOrg = true;
        }
        $orgId = (string) $org['id'];

        $this->ensureOwner($orgId, $account);

        if ($this->findWebsite($orgId, $domain) !== null) {
            $this->getLog()->info("Website {$domain} already exists on the Enhance server, reusing it");
            $this->sendSetupEmail($account);

            return true;
        }

        $subscription = $this->request('POST', $this->orgPath("/customers/{$orgId}/subscriptions"), [
            'planId' => $planId,
            'friendlyName' => (string) $account->getPackage()->getName(),
        ]);
        $subscriptionId = (int) $this->requireId($subscription, 'create subscription');

        try {
            $this->request('POST', "/orgs/{$orgId}/websites", ['domain' => $domain, 'subscriptionId' => $subscriptionId]);
        } catch (Server_Exception $e) {
            $this->rollback($orgId, $subscriptionId, $createdOrg);

            throw $e;
        }

        $this->sendSetupEmail($account);

        return true;
    }

    public function suspendAccount(Server_Account $account): bool
    {
        return $this->setSuspended($account, true);
    }

    public function unsuspendAccount(Server_Account $account): bool
    {
        return $this->setSuspended($account, false);
    }

    /**
     * Deleting the subscription removes every website under it. The customer organization is kept
     * because the client may have other services in it and a later order reuses it.
     */
    public function cancelAccount(Server_Account $account): bool
    {
        [$org, $website] = $this->resolve($account);
        $subscriptionId = $this->subscriptionId($website);

        if ($subscriptionId !== null) {
            $this->request('DELETE', $this->subscriptionPath($org['id'], $subscriptionId), [], [], [404]);
        } else {
            $this->request('DELETE', "/orgs/{$org['id']}/websites/{$website['id']}", [], [], [404]);
        }

        return true;
    }

    public function changeAccountPackage(Server_Account $account, Server_Package $package): bool
    {
        [$org, $website] = $this->resolve($account);
        $subscriptionId = $this->subscriptionId($website);
        if ($subscriptionId === null) {
            $this->fail(__trans('change the plan'));
        }

        $this->request('PATCH', $this->subscriptionPath($org['id'], $subscriptionId), ['planId' => $this->resolvePlanId($package)]);

        return true;
    }

    public function changeAccountUsername(Server_Account $account, string $newUsername): never
    {
        throw new Server_Exception(':type: does not support :action:', [':type:' => self::TYPE, ':action:' => __trans('username changes')]);
    }

    /**
     * The new domain is mapped onto the website and made primary; the old one stays as an alias.
     */
    public function changeAccountDomain(Server_Account $account, string $newDomain): bool
    {
        [$org, $website] = $this->resolve($account);

        $mapping = $this->request('POST', "/orgs/{$org['id']}/websites/{$website['id']}/domains", ['domain' => $newDomain, 'kind' => 'alias']);
        $domainId = $this->requireId($mapping, 'add domain');
        $this->request('PUT', "/orgs/{$org['id']}/websites/{$website['id']}/domains/primary", ['domainId' => $domainId]);

        return true;
    }

    public function changeAccountPassword(Server_Account $account, string $newPassword): bool
    {
        $email = $this->clientEmail($account);
        [$org] = $this->resolve($account);

        $member = $this->findMember($org['id'], $email);
        if ($member === null || empty($member['loginId'])) {
            throw new Server_Exception('No :type: login for :email: was found', [':type:' => self::TYPE, ':email:' => $email]);
        }

        $this->request('PUT', "/v2/logins/{$member['loginId']}/password", ['newPassword' => $newPassword]);

        return true;
    }

    public function changeAccountIp(Server_Account $account, string $newIp): never
    {
        throw new Server_Exception(':type: does not support :action:', [':type:' => self::TYPE, ':action:' => __trans('changing the account IP')]);
    }

    /**
     * The subscription is the billing unit and normally cascades to its websites, but a site can be
     * left on a subscription that no longer exists, so the website is always flagged as well.
     */
    private function setSuspended(Server_Account $account, bool $suspended): bool
    {
        [$org, $website] = $this->resolve($account);

        $subscriptionId = $this->subscriptionId($website);
        if ($subscriptionId !== null) {
            $this->request('PATCH', $this->subscriptionPath($org['id'], $subscriptionId), ['isSuspended' => $suspended], [], [404]);
        }
        $this->request('PATCH', "/orgs/{$org['id']}/websites/{$website['id']}", ['isSuspended' => $suspended]);

        return true;
    }

    private function ensureOwner(string $orgId, Server_Account $account): void
    {
        $email = $this->clientEmail($account);
        if ($this->findMember($orgId, $email) !== null) {
            return;
        }

        $client = $account->getClient();
        $login = $this->request('POST', '/logins', [
            'email' => $email,
            'name' => trim((string) $client?->getFullName()) ?: $email,
            'password' => (string) $account->getPassword(),
        ], ['orgId' => $orgId], [409]);

        $loginId = is_array($login) && !empty($login['id']) ? (string) $login['id'] : $this->findLoginId($email);
        if ($loginId === null) {
            $this->fail(__trans('create login'));
        }

        $this->request('POST', "/orgs/{$orgId}/members", ['loginId' => $loginId, 'roles' => [self::ROLE_OWNER]]);
    }

    /**
     * Opt-in per hosting plan: Enhance sends its own "set your password" email to the client.
     */
    private function sendSetupEmail(Server_Account $account): void
    {
        if (!Tools::normalizeBoolean($account->getPackage()->getCustomValue('send_setup_email'), false)) {
            return;
        }

        try {
            $this->request('PUT', '/login/password-recovery', ['email' => $this->clientEmail($account)]);
        } catch (Server_Exception $e) {
            $this->getLog()->warning("The Enhance password setup email was not sent: {$e->getMessage()}");
        }
    }

    private function rollback(string $orgId, int $subscriptionId, bool $deleteOrg): void
    {
        try {
            $this->request('DELETE', $this->subscriptionPath($orgId, $subscriptionId), [], [], [404]);
        } catch (Server_Exception $e) {
            $this->getLog()->error("Failed to remove subscription {$subscriptionId} after the website could not be created: {$e->getMessage()}");
        }

        if (!$deleteOrg) {
            return;
        }

        try {
            $this->request('DELETE', "/orgs/{$orgId}", [], [], [404]);
        } catch (Server_Exception $e) {
            $this->getLog()->error("Failed to remove customer organization {$orgId} after the website could not be created: {$e->getMessage()}");
        }
    }

    /**
     * @return array{0: array, 1: array} the customer organization and the website for the account domain
     */
    private function resolve(Server_Account $account): array
    {
        $email = $this->clientEmail($account);
        $domain = (string) $account->getDomain();

        foreach ($this->findCustomerOrgs($email) as $org) {
            $website = $this->findWebsite((string) $org['id'], $domain);
            if ($website !== null) {
                return [$org, $website];
            }
        }

        $website = $this->findWebsiteAcrossCustomers($domain);
        if ($website !== null && !empty($website['orgId'])) {
            $org = $this->request('GET', '/orgs/' . $website['orgId']);
            if (is_array($org) && !empty($org['id'])) {
                $this->getLog()->info("Website {$domain} was resolved by domain because no customer organization is owned by {$email}");

                return [$org, $website];
            }
        }

        throw new Server_Exception('No website for :domain: belonging to :email: was found on the :type: server', [':domain:' => $domain, ':email:' => $email, ':type:' => self::TYPE]);
    }

    private function findCustomerOrg(Server_Account $account): ?array
    {
        $orgs = $this->findCustomerOrgs($this->clientEmail($account));
        if (count($orgs) > 1) {
            $this->getLog()->warning(count($orgs) . " customer organizations on the Enhance server are owned by {$this->clientEmail($account)}, using the first");
        }

        return $orgs[0] ?? null;
    }

    private function findCustomerOrgs(string $email): array
    {
        $matches = [];
        foreach ($this->paginate($this->orgPath('/customers')) as $org) {
            if (($org['status'] ?? '') === 'deleted') {
                continue;
            }
            if (strcasecmp((string) ($org['ownerEmail'] ?? ''), $email) === 0) {
                $matches[] = $org;
            }
        }

        return $matches;
    }

    private function findWebsite(string $orgId, string $domain): ?array
    {
        return $this->pickWebsite($this->paginate("/orgs/{$orgId}/websites", ['search' => $domain]), $domain);
    }

    private function findWebsiteAcrossCustomers(string $domain): ?array
    {
        return $this->pickWebsite($this->paginate($this->orgPath('/websites'), ['search' => $domain, 'recursion' => 'directCustomers']), $domain);
    }

    private function pickWebsite(iterable $websites, string $domain): ?array
    {
        $fallback = null;
        foreach ($websites as $website) {
            if (($website['status'] ?? '') === 'deleted') {
                continue;
            }
            if (strcasecmp((string) ($website['domain']['domain'] ?? ''), $domain) !== 0) {
                continue;
            }
            if (($website['status'] ?? '') === 'active') {
                return $website;
            }
            $fallback ??= $website;
        }

        return $fallback;
    }

    private function findMember(string $orgId, string $email): ?array
    {
        $fallback = null;
        foreach ($this->paginate("/orgs/{$orgId}/members") as $member) {
            if (strcasecmp((string) ($member['email'] ?? ''), $email) !== 0) {
                continue;
            }
            if (in_array(self::ROLE_OWNER, $member['roles'] ?? [], true)) {
                return $member;
            }
            $fallback ??= $member;
        }

        return $fallback;
    }

    private function findLoginId(string $email): ?string
    {
        foreach ($this->paginate('/v2' . $this->orgPath('/customers/logins')) as $login) {
            if (strcasecmp((string) ($login['email'] ?? ''), $email) === 0 && !empty($login['id'])) {
                return (string) $login['id'];
            }
        }

        return null;
    }

    private function resolvePlanId(Server_Package $package): int
    {
        $custom = $package->getCustomValue('plan_id');
        if ($custom !== null && is_numeric($custom)) {
            return (int) $custom;
        }

        $name = (string) $package->getName();
        foreach ($this->paginate($this->orgPath('/plans')) as $plan) {
            if (strcasecmp((string) ($plan['name'] ?? ''), $name) === 0 && isset($plan['id'])) {
                return (int) $plan['id'];
            }
        }

        throw new Server_Exception('No :type: plan matches the hosting plan ":plan:". Set the plan_id custom value on the hosting plan', [':type:' => self::TYPE, ':plan:' => $name]);
    }

    private function subscriptionId(array $website): ?int
    {
        return isset($website['subscriptionId']) ? (int) $website['subscriptionId'] : null;
    }

    private function subscriptionPath(string $orgId, int $subscriptionId): string
    {
        return "/orgs/{$orgId}/subscriptions/{$subscriptionId}";
    }

    private function primaryIp(array $website): ?string
    {
        $first = null;
        foreach ($website['serverIps'] ?? [] as $entry) {
            $ip = trim((string) ($entry['ip'] ?? ''));
            if ($ip === '') {
                continue;
            }
            if (!empty($entry['isPrimary'])) {
                return $ip;
            }
            $first ??= $ip;
        }

        return $first;
    }

    private function megabytes(mixed $bytes): ?int
    {
        return is_numeric($bytes) ? (int) round((float) $bytes / 1_000_000) : null;
    }

    private function limit(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private function clientEmail(Server_Account $account): string
    {
        $email = trim((string) $account->getClient()?->getEmail());
        if ($email === '') {
            $this->fail(__trans('resolve the client email'));
        }

        return $email;
    }

    private function orgId(): string
    {
        return (string) $this->_config['username'];
    }

    private function orgPath(string $suffix = ''): string
    {
        return '/orgs/' . $this->orgId() . $suffix;
    }

    private function baseUrl(): string
    {
        $url = 'https://' . $this->_config['host'];
        if ($this->getPort() !== self::DEFAULT_PORT) {
            $url .= ':' . $this->getPort();
        }

        return $url;
    }

    private function requireId(mixed $response, string $action): string
    {
        if (!is_array($response) || !isset($response['id']) || $response['id'] === '') {
            $this->fail(__trans($action));
        }

        return (string) $response['id'];
    }

    private function errorMessage(string $body): string
    {
        $decoded = json_decode($body, true);

        return is_array($decoded) ? trim((string) ($decoded['message'] ?? $decoded['detail'] ?? '')) : '';
    }

    private function fail(string $action): never
    {
        throw new Server_Exception('Failed to :action: on the :type: server, check the error logs for further details', [':action:' => $action, ':type:' => self::TYPE]);
    }

    private function paginate(string $path, array $query = []): Generator
    {
        $offset = 0;
        for ($page = 0; $page < self::MAX_PAGES; ++$page) {
            $result = $this->request('GET', $path, [], $query + ['offset' => $offset, 'limit' => self::PAGE_SIZE]);
            $items = is_array($result) ? ($result['items'] ?? []) : [];
            if (!is_array($items) || $items === []) {
                return;
            }

            foreach ($items as $item) {
                if (is_array($item)) {
                    yield $item;
                }
            }

            $offset += count($items);
            if ($offset >= (int) ($result['total'] ?? 0)) {
                return;
            }
        }
    }

    /**
     * Sends an authenticated request. Statuses listed in $tolerate return null instead of throwing.
     */
    private function request(string $method, string $path, array $json = [], array $query = [], array $tolerate = []): mixed
    {
        $verifyTls = Tools::normalizeBoolean($this->_config['config']['tls_verify'] ?? true, true);

        $url = $this->baseUrl() . '/api' . $path;
        if ($query !== []) {
            $url .= '?' . http_build_query($query);
        }

        $options = [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->_config['accesshash'],
                'Accept' => 'application/json',
            ],
        ];
        if (in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
            $options['json'] = $json;
        }

        $this->getLog()->debug("Enhance API {$method} {$path}");

        try {
            $client = $this->getHttpClient()->withOptions([
                'verify_peer' => $verifyTls,
                'verify_host' => $verifyTls,
                'timeout' => 60,
            ]);
            $response = $client->request($method, $url, $options);
            $status = $response->getStatusCode();
            $content = $response->getContent();
        } catch (TransportExceptionInterface|HttpExceptionInterface $error) {
            $status = 0;
            $detail = '';
            if ($error instanceof HttpExceptionInterface) {
                $status = $error->getResponse()->getStatusCode();

                try {
                    $detail = substr($error->getResponse()->getContent(false), 0, 500);
                } catch (TransportExceptionInterface) {
                }
            }

            if (in_array($status, $tolerate, true)) {
                return null;
            }

            $this->getLog()->error("Enhance API {$method} {$path} failed with status {$status}: {$detail}");

            if (in_array($status, [401, 403], true)) {
                $reason = $this->errorMessage($detail);

                throw new Server_Exception('Failed to connect to the :type: server. Please verify your credentials and configuration:reason:', [':type:' => self::TYPE, ':reason:' => $reason === '' ? '' : ' (' . $reason . ')']);
            }

            throw new Server_Exception('HttpClientException: :error', [':error' => $error->getMessage()]);
        }

        if ($status === 204 || trim($content) === '') {
            return null;
        }

        try {
            return json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $this->getLog()->error("Unexpected response from the Enhance server for {$method} {$path}");
            $this->fail("{$method} {$path}");
        }
    }
}
