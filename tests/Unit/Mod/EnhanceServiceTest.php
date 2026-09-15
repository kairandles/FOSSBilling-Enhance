<?php

declare(strict_types=1);

use Box\Mod\Enhance\Service;
use Box\Mod\Order\Entity\Order;
use Box\Mod\Servicehosting\Entity\ServiceHosting;
use Box\Mod\Servicehosting\Entity\ServiceHostingHp;
use Box\Mod\Servicehosting\Entity\ServiceHostingServer;

/*
 * The 0.8 releases of FOSSBilling manage hosting with RedBean models, which a main line checkout
 * does not ship, so stand-ins are declared here to type the hosting service of that generation.
 */
if (!class_exists('Model_ServiceHostingServer')) {
    class Model_ServiceHostingServer
    {
        public function __construct(public int $id = 1, public string $name = 'Enhance', public string $hostname = 'panel.example.com', public string $manager = 'Enhance')
        {
        }
    }
}

if (!class_exists('Model_ServiceHostingHp')) {
    class Model_ServiceHostingHp
    {
        public function __construct(public int $id = 5, public string $name = 'Basic', public ?string $config = null)
        {
        }
    }
}

if (!class_exists('Model_ServiceHosting')) {
    class Model_ServiceHosting
    {
        public function __construct(public int $id = 9, public string $sld = 'example', public string $tld = '.com')
        {
        }
    }
}

if (!class_exists('Model_Client')) {
    class Model_Client
    {
        public function __construct(public int $id = 4)
        {
        }
    }
}

if (!class_exists('Model_ClientOrder')) {
    class Model_ClientOrder
    {
        public function __construct(public int $id = 3, public int $client_id = 2, public string $service_type = 'hosting', public string $title = 'Basic Plan', public string $status = 'active')
        {
        }
    }
}

function enhanceStubManager(): Server_Manager_Enhance
{
    return new class(['host' => 'panel.example.com', 'username' => '11111111-1111-1111-1111-111111111111', 'accesshash' => 'token-secret']) extends Server_Manager_Enhance {
        public function listPackages(): array
        {
            return [['id' => '3', 'name' => 'Basic', 'quota' => 7000, 'config' => ['plan_id' => '3']]];
        }

        public function accountUsage(Server_Account $account): array
        {
            return ['plan_name' => 'Basic', 'disk_used_mb' => 12];
        }
    };
}

/** Hosting service of the main line: every method takes a Doctrine entity. */
final class EnhanceNewHostingService
{
    public array $received = [];

    public function __construct(private readonly Server_Manager_Enhance $manager)
    {
    }

    public function getServerManager(ServiceHostingServer $model): Server_Manager_Enhance
    {
        $this->received[] = $model;

        return $this->manager;
    }

    public function _getAM(ServiceHosting $model): array
    {
        $this->received[] = $model;

        return [$this->manager, new Server_Account()];
    }

    public function updateHp(ServiceHostingHp $model, array $data): bool
    {
        $this->received[] = $model;

        return true;
    }

    public function createHp($name, $data)
    {
        return 5;
    }
}

/** Hosting service of the 0.8 releases: every method takes a RedBean model. */
final class EnhanceOldHostingService
{
    public array $received = [];

    public function __construct(private readonly Server_Manager_Enhance $manager)
    {
    }

    public function getServerManager(Model_ServiceHostingServer $model): Server_Manager_Enhance
    {
        $this->received[] = $model;

        return $this->manager;
    }

    public function _getAM(Model_ServiceHosting $model): array
    {
        $this->received[] = $model;

        return [$this->manager, new Server_Account()];
    }

    public function updateHp(Model_ServiceHostingHp $model, array $data): bool
    {
        $this->received[] = $model;

        return true;
    }

    public function createHp($name, $data)
    {
        return 5;
    }
}

final class EnhanceFakeOrderService
{
    public function __construct(private readonly array $services)
    {
    }

    public function getOrderService(object $order)
    {
        $id = method_exists($order, 'getId') ? $order->getId() : $order->id;

        return $this->services[$id] ?? null;
    }
}

final class EnhanceFakeRepository
{
    public function __construct(private readonly array $rows)
    {
    }

    public function find($id): ?object
    {
        return $this->rows[$id] ?? null;
    }

    public function findAll(): array
    {
        return array_values($this->rows);
    }

    public function findBy(array $criteria, ?array $orderBy = null): array
    {
        return array_values(array_filter($this->rows, fn (object $row): bool => $this->matches($row, $criteria)));
    }

    public function findOneBy(array $criteria): ?object
    {
        return $this->findBy($criteria)[0] ?? null;
    }

    private function matches(object $row, array $criteria): bool
    {
        foreach ($criteria as $field => $expected) {
            $actual = $row->{'get' . ucfirst($field)}();
            if (is_array($expected) ? !in_array($actual, $expected, true) : $actual !== $expected) {
                return false;
            }
        }

        return true;
    }
}

final class EnhanceFakeEntityManager
{
    public function __construct(private readonly array $repositories)
    {
    }

    public function getRepository(string $class): EnhanceFakeRepository
    {
        return $this->repositories[$class] ?? new EnhanceFakeRepository([]);
    }
}

final class EnhanceFakeDatabase
{
    /** @param array<string, list<object>> $rows */
    public function __construct(private readonly array $rows)
    {
    }

    public function find(string $model, ?string $sql = null, $values = []): array
    {
        return array_values(array_filter($this->rows[$model] ?? [], fn (object $row): bool => $this->matches($row, $values)));
    }

    public function findOne(string $model, ?string $sql = null, $values = []): ?object
    {
        return $this->find($model, $sql, $values)[0] ?? null;
    }

    public function load(string $model, $id): ?object
    {
        foreach ($this->rows[$model] ?? [] as $row) {
            if ($row->id === (int) $id) {
                return $row;
            }
        }

        return null;
    }

    private function matches(object $row, array $values): bool
    {
        foreach ($values as $placeholder => $expected) {
            $column = ltrim((string) $placeholder, ':');
            if (in_array($column, ['active', 'suspended'], true)) {
                continue;
            }
            if (($row->{$column} ?? null) != $expected) {
                return false;
            }
        }

        return true;
    }
}

function enhanceModuleService(object $hosting, ?object $orders = null, array $entities = [], array $models = []): Service
{
    $di = new Pimple\Container();
    $di['mod_service'] = $di->protect(fn (string $mod): object => $mod === 'servicehosting' ? $hosting : ($orders ?? new EnhanceFakeOrderService([])));
    $di['em'] = new EnhanceFakeEntityManager($entities);
    $di['db'] = new EnhanceFakeDatabase($models);
    $di['logger'] = new class {
        public function info(...$arguments): void
        {
        }

        public function warning(...$arguments): void
        {
        }
    };

    $service = new Service();
    $service->setDi($di);

    return $service;
}

function enhanceServerEntity(): ServiceHostingServer
{
    $server = (new ServiceHostingServer())->setName('Enhance')->setHostname('panel.example.com')->setManager('Enhance');
    (new ReflectionProperty($server, 'id'))->setValue($server, 1);

    return $server;
}

function enhanceOrderEntity(): Order
{
    $order = (new Order())->setClientId(2)->setServiceType('hosting')->setTitle('Basic Plan')->setStatus('active');
    (new ReflectionProperty($order, 'id'))->setValue($order, 3);

    return $order;
}

test('servers are read as entities when the hosting service takes entities', function (): void {
    $server = enhanceServerEntity();
    $hosting = new EnhanceNewHostingService(enhanceStubManager());
    $service = enhanceModuleService($hosting, null, [ServiceHostingServer::class => new EnhanceFakeRepository([1 => $server])]);

    expect($service->getServers())->toBe([['id' => 1, 'name' => 'Enhance', 'hostname' => 'panel.example.com']])
        ->and($service->getServer(1))->toBe($server)
        ->and($service->getServerPackages($server)[0])->toMatchArray(['id' => '3', 'hosting_plan_id' => null])
        ->and($hosting->received[0])->toBeInstanceOf(ServiceHostingServer::class);
});

test('servers are read as models when the hosting service takes RedBean models', function (): void {
    $server = new Model_ServiceHostingServer();
    $hosting = new EnhanceOldHostingService(enhanceStubManager());
    $service = enhanceModuleService($hosting, null, [], ['ServiceHostingServer' => [$server]]);

    expect($service->getServers())->toBe([['id' => 1, 'name' => 'Enhance', 'hostname' => 'panel.example.com']])
        ->and($service->getServer(1))->toBe($server)
        ->and($service->getServerPackages($server)[0])->toMatchArray(['id' => '3', 'hosting_plan_id' => null])
        ->and($hosting->received[0])->toBeInstanceOf(Model_ServiceHostingServer::class);
});

test('a package is matched to an existing hosting plan in either generation', function (string $generation): void {
    $manager = enhanceStubManager();
    $config = json_encode(['plan_id' => '3']);

    if ($generation === 'entities') {
        $plan = (new ServiceHostingHp())->setName('Basic')->setConfig($config);
        (new ReflectionProperty($plan, 'id'))->setValue($plan, 5);
        $hosting = new EnhanceNewHostingService($manager);
        $service = enhanceModuleService($hosting, null, [
            ServiceHostingServer::class => new EnhanceFakeRepository([1 => enhanceServerEntity()]),
            ServiceHostingHp::class => new EnhanceFakeRepository([5 => $plan]),
        ]);
    } else {
        $hosting = new EnhanceOldHostingService($manager);
        $service = enhanceModuleService($hosting, null, [], [
            'ServiceHostingServer' => [new Model_ServiceHostingServer()],
            'ServiceHostingHp' => [new Model_ServiceHostingHp(config: $config)],
        ]);
    }

    $server = $service->getServer(1);

    expect($service->getServerPackages($server)[0])->toMatchArray(['hosting_plan_id' => 5, 'hosting_plan_name' => 'Basic', 'matched_by' => 'config'])
        ->and($service->syncHostingPlans($server, [], false))->toBe(['created' => 0, 'updated' => 0, 'skipped' => 1]);
})->with(['entities', 'models']);

test('usage is read through the hosting service of the main line', function (): void {
    $hosting = new EnhanceNewHostingService(enhanceStubManager());
    $model = (new ServiceHosting())->setSld('example')->setTld('.com');
    $service = enhanceModuleService($hosting, new EnhanceFakeOrderService([3 => $model]), [Order::class => new EnhanceFakeRepository([3 => enhanceOrderEntity()])]);

    expect($service->usageForOrder(3, 2))->toBe(['plan_name' => 'Basic', 'disk_used_mb' => 12])
        ->and($hosting->received[0])->toBeInstanceOf(ServiceHosting::class)
        ->and($service->accountsForClient(2)[0])->toMatchArray(['order_id' => 3, 'title' => 'Basic Plan', 'domain' => 'example.com', 'status' => 'active', 'error' => null]);
});

test('usage is read through the hosting service of the 0.8 releases', function (): void {
    $hosting = new EnhanceOldHostingService(enhanceStubManager());
    $service = enhanceModuleService($hosting, new EnhanceFakeOrderService([3 => new Model_ServiceHosting()]), [], ['ClientOrder' => [new Model_ClientOrder()]]);

    expect($service->usageForOrder(3, 2))->toBe(['plan_name' => 'Basic', 'disk_used_mb' => 12])
        ->and($hosting->received[0])->toBeInstanceOf(Model_ServiceHosting::class)
        ->and($service->accountsForClient(2)[0])->toMatchArray(['order_id' => 3, 'domain' => 'example.com', 'error' => null]);
});

test('the client ID is read from the identity of either generation', function (): void {
    $service = enhanceModuleService(new EnhanceOldHostingService(enhanceStubManager()));
    $entity = (new Box\Mod\Client\Entity\Client())->setEmail('client@example.com');
    (new ReflectionProperty($entity, 'id'))->setValue($entity, 7);

    expect($service->clientId($entity))->toBe(7)
        ->and($service->clientId(new Model_Client()))->toBe(4);
});

test('another client\'s order is not found', function (): void {
    $hosting = new EnhanceOldHostingService(enhanceStubManager());
    $service = enhanceModuleService($hosting, new EnhanceFakeOrderService([3 => new Model_ServiceHosting()]), [], ['ClientOrder' => [new Model_ClientOrder()]]);

    $service->usageForOrder(3, 99);
})->throws(FOSSBilling\InformationException::class, 'Order not found');

test('a server that is not managed by Enhance is not found', function (): void {
    $hosting = new EnhanceOldHostingService(enhanceStubManager());
    $service = enhanceModuleService($hosting, null, [], ['ServiceHostingServer' => [new Model_ServiceHostingServer(manager: 'Hestia')]]);

    $service->getServer(1);
})->throws(FOSSBilling\InformationException::class, 'Enhance server not found');
