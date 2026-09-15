<?php

declare(strict_types=1);

use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

function enhanceOrgId(): string
{
    return '11111111-1111-1111-1111-111111111111';
}

function enhanceCustomerId(): string
{
    return '22222222-2222-2222-2222-222222222222';
}

function enhanceWebsiteId(): string
{
    return '33333333-3333-3333-3333-333333333333';
}

function enhanceMemberId(): string
{
    return '44444444-4444-4444-4444-444444444444';
}

function enhanceLoginId(): string
{
    return '55555555-5555-5555-5555-555555555555';
}

function createEnhanceManager(HttpClientInterface $httpClient, array $overrides = []): Server_Manager_Enhance
{
    $options = array_merge(['host' => 'panel.example.com', 'username' => enhanceOrgId(), 'accesshash' => 'token-secret'], $overrides);

    return new class($options, $httpClient) extends Server_Manager_Enhance {
        public function __construct(array $options, private readonly HttpClientInterface $httpClient)
        {
            parent::__construct($options);
        }

        public function getHttpClient(): HttpClientInterface
        {
            return $this->httpClient;
        }
    };
}

function createEnhanceAccount(array $customValues = []): Server_Account
{
    $client = (new Server_Client())
        ->setEmail('client@example.com')
        ->setFirstName('Jane')
        ->setLastName('Doe')
        ->setFullName('Jane Doe')
        ->setCompany('Example Ltd');

    $package = (new Server_Package())->setName('Business')->setCustomValues($customValues);

    return (new Server_Account())
        ->setUsername('example')
        ->setPassword('secret-pass')
        ->setDomain('example.com')
        ->setIp('10.0.0.1')
        ->setClient($client)
        ->setPackage($package);
}

function enhanceCustomerOrg(array $overrides = []): array
{
    return array_merge(['id' => enhanceCustomerId(), 'name' => 'Example Ltd', 'status' => 'active', 'ownerEmail' => 'Client@Example.com'], $overrides);
}

function enhanceWebsite(array $overrides = []): array
{
    return array_merge([
        'id' => enhanceWebsiteId(),
        'domain' => ['id' => 'd1', 'domain' => 'example.com', 'kind' => 'primary'],
        'status' => 'active',
        'orgId' => enhanceCustomerId(),
        'subscriptionId' => 42,
        'kind' => 'normal',
        'size' => 6434000,
        'phpVersion' => 'php85',
        'appServerName' => 'web01.example.net',
        'createdAt' => '2026-09-13T20:10:22Z',
        'aliases' => [['id' => 'd3', 'domain' => 'example.net', 'kind' => 'alias'], ['id' => 'd4', 'domain' => 'example-com.preview.panel.example.com', 'kind' => 'preview']],
        'serverIps' => [['ip' => '203.0.113.5', 'isPrimary' => false], ['ip' => '203.0.113.10', 'isPrimary' => true]],
    ], $overrides);
}

function enhanceMember(): array
{
    return ['id' => enhanceMemberId(), 'loginId' => enhanceLoginId(), 'email' => 'client@example.com', 'roles' => ['Owner']];
}

function enhanceSubscription(array $overrides = []): array
{
    return array_merge([
        'id' => 42,
        'planId' => 7,
        'planName' => 'Business',
        'status' => 'active',
        'resources' => [
            ['name' => 'diskspace', 'total' => 10000000000, 'usage' => 2500000000],
            ['name' => 'transfer', 'usage' => 750000000],
            ['name' => 'websites', 'total' => 1, 'usage' => 1],
            ['name' => 'addonDomains', 'total' => 2, 'usage' => 0],
            ['name' => 'subdomains', 'total' => 5, 'usage' => 3],
            ['name' => 'domainAliases', 'total' => 0, 'usage' => 0],
            ['name' => 'mailboxes', 'total' => 10, 'usage' => 4],
            ['name' => 'mysqlDbs', 'usage' => 2],
            ['name' => 'ftpUsers', 'total' => 3, 'usage' => 1],
            ['name' => 'pageViews', 'usage' => 1200],
        ],
    ], $overrides);
}

function enhanceListing(array $items): array
{
    return ['items' => $items, 'total' => count($items)];
}

/**
 * Builds a mock client that records every request and answers from $state. Unknown paths return 404.
 *
 * @param array<int, array{method: string, path: string, query: array, json: mixed, options: array}> $requests
 */
function enhanceClient(array &$requests, array $state = []): MockHttpClient
{
    $state += [
        'customers' => [enhanceCustomerOrg()],
        'members' => [enhanceMember()],
        'websites' => [enhanceWebsite()],
        'resellerWebsites' => [],
        'plans' => [['id' => 7, 'name' => 'Business']],
        'logins' => [['id' => enhanceLoginId(), 'email' => 'client@example.com']],
        'loginConflict' => false,
        'websiteCreationFails' => false,
        'subscription' => enhanceSubscription(),
        'subscriptionFails' => false,
        'metrics' => [
            ['bytesReceived' => 9567, 'bytesSent' => 39674, 'uniqueHits' => 10, 'botHits' => 0, 'totalHits' => 39, 'datetime' => '2026-09-14T00:00:00Z'],
            ['bytesReceived' => 233934, 'bytesSent' => 293581, 'uniqueHits' => 64, 'botHits' => 3, 'totalHits' => 277, 'datetime' => '2026-09-13T00:00:00Z'],
        ],
        'metricsFails' => false,
        'backups' => [
            ['id' => 1, 'startedAt' => '2026-09-12T13:44:06Z', 'finishedAt' => '2026-09-12T13:44:06Z', 'kind' => 'automatic', 'homeDirStatus' => 'successful'],
            ['id' => 2, 'startedAt' => '2026-09-13T13:44:09Z', 'finishedAt' => '2026-09-13T13:44:09Z', 'kind' => 'automatic', 'homeDirStatus' => 'successful'],
            ['id' => 3, 'startedAt' => '2026-09-14T13:44:09Z', 'kind' => 'automatic', 'homeDirStatus' => 'failed'],
        ],
        'backupsFails' => false,
        'orgStatus' => 'active',
    ];

    $org = enhanceOrgId();
    $customer = enhanceCustomerId();
    $website = enhanceWebsiteId();

    return new MockHttpClient(function (string $method, string $url, array $options) use (&$requests, $state, $org, $customer, $website): MockResponse {
        $path = (string) parse_url($url, PHP_URL_PATH);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $requests[] = [
            'method' => $method,
            'path' => $path,
            'query' => $query,
            'json' => isset($options['body']) ? json_decode((string) $options['body'], true) : null,
            'options' => $options,
        ];

        $json = static fn (mixed $data, int $code = 200): MockResponse => new MockResponse(json_encode($data), ['http_code' => $code]);
        $empty = static fn (): MockResponse => new MockResponse('', ['http_code' => 204]);
        $route = "{$method} {$path}";

        return match (true) {
            $route === "GET /api/orgs/{$org}" => $json(['id' => $org, 'name' => 'Reseller', 'status' => $state['orgStatus']]),
            $route === "GET /api/orgs/{$org}/customers" => $json(enhanceListing($state['customers'])),
            $route === "POST /api/orgs/{$org}/customers" => $json(['id' => $customer], 201),
            $route === "GET /api/orgs/{$customer}/members" => $json(enhanceListing($state['members'])),
            $route === 'POST /api/logins' => $state['loginConflict'] ? $json(['message' => 'exists'], 409) : $json(['id' => enhanceLoginId()], 201),
            $route === "GET /api/v2/orgs/{$org}/customers/logins" => $json(enhanceListing($state['logins'])),
            $route === "POST /api/orgs/{$customer}/members" => $json(['id' => enhanceMemberId()], 201),
            $route === "GET /api/orgs/{$customer}/websites" => $json(enhanceListing($state['websites'])),
            $route === "GET /api/orgs/{$org}/websites" => $json(enhanceListing($state['resellerWebsites'])),
            $route === "GET /api/orgs/{$customer}" => $json(enhanceCustomerOrg()),
            $route === "POST /api/orgs/{$org}/customers/{$customer}/subscriptions" => $json(['id' => 42], 201),
            $route === "POST /api/orgs/{$customer}/websites" => $state['websiteCreationFails'] ? $json(['message' => 'quota'], 500) : $json(['id' => $website], 201),
            $route === "GET /api/orgs/{$customer}/websites/{$website}" => $json(enhanceWebsite()),
            $route === "GET /api/orgs/{$org}/plans" => $json(enhanceListing($state['plans'])),
            str_ends_with($route, '/metrics') && str_starts_with($route, "GET /api/orgs/{$customer}/websites/") => $state['metricsFails'] ? $json(['message' => 'boom'], 500) : $json(['items' => $state['metrics']]),
            str_ends_with($route, '/backups') && str_starts_with($route, "GET /api/orgs/{$customer}/websites/") => $state['backupsFails'] ? $json(['message' => 'boom'], 500) : $json(['items' => $state['backups']]),
            $route === "GET /api/orgs/{$customer}/subscriptions/42" => $state['subscriptionFails'] ? $json(['message' => 'boom'], 500) : $json($state['subscription']),
            $route === "GET /api/orgs/{$customer}/members/" . enhanceMemberId() . '/sso' => $json('https://panel.example.com/sso?otp=one-time', 201),
            $route === "POST /api/orgs/{$customer}/websites/{$website}/domains" => $json(['id' => 'd2'], 201),
            str_starts_with($route, "PATCH /api/orgs/{$customer}/"),
            str_starts_with($route, "DELETE /api/orgs/{$customer}/"),
            $route === "DELETE /api/orgs/{$customer}",
            $route === "PUT /api/orgs/{$customer}/websites/{$website}/domains/primary",
            $route === 'PUT /api/v2/logins/' . enhanceLoginId() . '/password' => $empty(),
            $route === 'PUT /api/login/password-recovery' => $json([]),
            default => $json(['message' => 'not found'], 404),
        };
    });
}

function enhanceRoutes(array $requests): array
{
    return array_map(static fn (array $request): string => $request['method'] . ' ' . preg_replace('#^/api#', '', $request['path']), $requests);
}

test('init requires a hostname, an organization ID and an API token', function (string $missing): void {
    $requests = [];
    createEnhanceManager(enhanceClient($requests), [$missing => '']);
})->with(['host', 'username', 'accesshash'])->throws(Server_Exception::class, 'not fully configured');

test('init rejects an organization ID that is not a UUID', function (): void {
    $requests = [];
    createEnhanceManager(enhanceClient($requests), ['username' => 'token-secret']);
})->throws(Server_Exception::class, 'organization UUID');

test('the form marks the API token as secret and labels the username field as the organization ID', function (): void {
    $fields = Server_Manager_Enhance::getForm()['form']['credentials']['fields'];

    expect(array_column($fields, 'name'))->toBe(['username', 'accesshash'])
        ->and($fields[0]['label'])->toBe('Organization ID')
        ->and($fields[0])->not->toHaveKey('secret')
        ->and($fields[1]['secret'])->toBeTrue();
});

test('the panel URL is built without calling the API and honours a non-default port', function (): void {
    $requests = [];
    $manager = createEnhanceManager(enhanceClient($requests));

    expect($manager->getLoginUrl())->toBe('https://panel.example.com/')
        ->and($manager->getResellerLoginUrl())->toBe('https://panel.example.com/')
        ->and(createEnhanceManager(enhanceClient($requests), ['port' => 8443])->getLoginUrl())->toBe('https://panel.example.com:8443/')
        ->and($requests)->toBe([]);
});

test('requests carry the bearer token and honour the tls_verify server setting', function (): void {
    $requests = [];
    createEnhanceManager(enhanceClient($requests), ['config' => ['tls_verify' => false]])->testConnection();

    expect($requests)->toHaveCount(1)
        ->and($requests[0]['options']['normalized_headers']['authorization'][0])->toBe('Authorization: Bearer token-secret')
        ->and($requests[0]['options']['verify_peer'])->toBeFalse()
        ->and($requests[0]['options']['verify_host'])->toBeFalse();
});

test('testConnection succeeds when the configured organization is returned', function (): void {
    $requests = [];

    expect(createEnhanceManager(enhanceClient($requests))->testConnection())->toBeTrue()
        ->and(enhanceRoutes($requests))->toBe(['GET /orgs/' . enhanceOrgId()]);
});

test('testConnection reports bad credentials on an authentication failure', function (): void {
    $client = new MockHttpClient(static fn (): MockResponse => new MockResponse('{"message":"Invalid session"}', ['http_code' => 401]));

    createEnhanceManager($client)->testConnection();
})->throws(Server_Exception::class, 'verify your credentials');

test('testConnection includes the reason the panel gives for refusing the token', function (): void {
    $client = new MockHttpClient(static fn (): MockResponse => new MockResponse('{"message":"Access token not allowed for your IP, 198.51.100.7"}', ['http_code' => 401]));

    createEnhanceManager($client)->testConnection();
})->throws(Server_Exception::class, 'Access token not allowed for your IP, 198.51.100.7');

test('testConnection fails when the token belongs to a different organization', function (): void {
    $client = new MockHttpClient(static fn (): MockResponse => new MockResponse(json_encode(['id' => enhanceCustomerId(), 'status' => 'active'])));

    createEnhanceManager($client)->testConnection();
})->throws(Server_Exception::class, 'verify your credentials');

test('createAccount creates the organization, login, membership, subscription and website in order', function (): void {
    $requests = [];
    $manager = createEnhanceManager(enhanceClient($requests, ['customers' => [], 'members' => [], 'websites' => []]));

    expect($manager->createAccount(createEnhanceAccount(['plan_id' => '7'])))->toBeTrue()
        ->and(enhanceRoutes($requests))->toBe([
            'GET /orgs/' . enhanceOrgId() . '/customers',
            'POST /orgs/' . enhanceOrgId() . '/customers',
            'GET /orgs/' . enhanceCustomerId() . '/members',
            'POST /logins',
            'POST /orgs/' . enhanceCustomerId() . '/members',
            'GET /orgs/' . enhanceCustomerId() . '/websites',
            'POST /orgs/' . enhanceOrgId() . '/customers/' . enhanceCustomerId() . '/subscriptions',
            'POST /orgs/' . enhanceCustomerId() . '/websites',
        ])
        ->and($requests[1]['json'])->toBe(['name' => 'Example Ltd'])
        ->and($requests[3]['json'])->toBe(['email' => 'client@example.com', 'name' => 'Jane Doe', 'password' => 'secret-pass'])
        ->and($requests[3]['query'])->toBe(['orgId' => enhanceCustomerId()])
        ->and($requests[4]['json'])->toBe(['loginId' => enhanceLoginId(), 'roles' => ['Owner']])
        ->and($requests[6]['json'])->toBe(['planId' => 7, 'friendlyName' => 'Business'])
        ->and($requests[7]['json'])->toBe(['domain' => 'example.com', 'subscriptionId' => 42]);
});

test('createAccount reuses a customer organization owned by the client email regardless of case', function (): void {
    $requests = [];
    $manager = createEnhanceManager(enhanceClient($requests, ['websites' => []]));

    $manager->createAccount(createEnhanceAccount(['plan_id' => '7']));

    expect(enhanceRoutes($requests))->not->toContain('POST /orgs/' . enhanceOrgId() . '/customers')
        ->and(enhanceRoutes($requests))->not->toContain('POST /logins')
        ->and(enhanceRoutes($requests))->toContain('POST /orgs/' . enhanceCustomerId() . '/websites');
});

test('createAccount is a no-op when the website already exists', function (): void {
    $requests = [];
    $manager = createEnhanceManager(enhanceClient($requests));

    expect($manager->createAccount(createEnhanceAccount(['plan_id' => '7'])))->toBeTrue()
        ->and(array_filter(enhanceRoutes($requests), static fn (string $route): bool => str_starts_with($route, 'POST')))->toBe([]);
});

test('createAccount recovers from an existing login by looking up its id', function (): void {
    $requests = [];
    $manager = createEnhanceManager(enhanceClient($requests, ['members' => [], 'websites' => [], 'loginConflict' => true]));

    $manager->createAccount(createEnhanceAccount(['plan_id' => '7']));

    $routes = enhanceRoutes($requests);
    expect($routes)->toContain('GET /v2/orgs/' . enhanceOrgId() . '/customers/logins')
        ->and($requests[array_search('POST /orgs/' . enhanceCustomerId() . '/members', $routes, true)]['json'])->toBe(['loginId' => enhanceLoginId(), 'roles' => ['Owner']]);
});

test('createAccount removes the subscription and a freshly created organization when the website cannot be created', function (): void {
    $requests = [];
    $manager = createEnhanceManager(enhanceClient($requests, ['customers' => [], 'members' => [], 'websites' => [], 'websiteCreationFails' => true]));

    expect(fn (): bool => $manager->createAccount(createEnhanceAccount(['plan_id' => '7'])))->toThrow(Server_Exception::class)
        ->and(array_slice(enhanceRoutes($requests), -2))->toBe([
            'DELETE /orgs/' . enhanceCustomerId() . '/subscriptions/42',
            'DELETE /orgs/' . enhanceCustomerId(),
        ]);
});

test('createAccount keeps a pre-existing organization when rolling back', function (): void {
    $requests = [];
    $manager = createEnhanceManager(enhanceClient($requests, ['websites' => [], 'websiteCreationFails' => true]));

    expect(fn (): bool => $manager->createAccount(createEnhanceAccount(['plan_id' => '7'])))->toThrow(Server_Exception::class)
        ->and(enhanceRoutes($requests))->toContain('DELETE /orgs/' . enhanceCustomerId() . '/subscriptions/42')
        ->and(enhanceRoutes($requests))->not->toContain('DELETE /orgs/' . enhanceCustomerId());
});

test('the plan is resolved from the plan_id custom value without listing plans', function (): void {
    $requests = [];
    createEnhanceManager(enhanceClient($requests, ['websites' => []]))->createAccount(createEnhanceAccount(['plan_id' => '9']));

    expect(enhanceRoutes($requests))->not->toContain('GET /orgs/' . enhanceOrgId() . '/plans')
        ->and($requests[array_search('POST /orgs/' . enhanceOrgId() . '/customers/' . enhanceCustomerId() . '/subscriptions', enhanceRoutes($requests), true)]['json']['planId'])->toBe(9);
});

test('the plan falls back to matching the hosting plan name', function (): void {
    $requests = [];
    createEnhanceManager(enhanceClient($requests, ['websites' => [], 'plans' => [['id' => 3, 'name' => 'Starter'], ['id' => 7, 'name' => 'business']]]))->createAccount(createEnhanceAccount());

    expect(enhanceRoutes($requests)[0])->toBe('GET /orgs/' . enhanceOrgId() . '/plans')
        ->and($requests[array_search('POST /orgs/' . enhanceOrgId() . '/customers/' . enhanceCustomerId() . '/subscriptions', enhanceRoutes($requests), true)]['json']['planId'])->toBe(7);
});

test('createAccount fails before touching the panel when no plan matches', function (): void {
    $requests = [];
    $manager = createEnhanceManager(enhanceClient($requests, ['plans' => [['id' => 3, 'name' => 'Starter']]]));

    expect(fn (): bool => $manager->createAccount(createEnhanceAccount()))->toThrow(Server_Exception::class, 'plan_id')
        ->and(enhanceRoutes($requests))->toBe(['GET /orgs/' . enhanceOrgId() . '/plans']);
});

test('the password setup email is only requested when the hosting plan opts in', function (): void {
    $requests = [];
    createEnhanceManager(enhanceClient($requests))->createAccount(createEnhanceAccount(['plan_id' => '7']));
    expect(enhanceRoutes($requests))->not->toContain('PUT /login/password-recovery');

    $requests = [];
    createEnhanceManager(enhanceClient($requests))->createAccount(createEnhanceAccount(['plan_id' => '7', 'send_setup_email' => '1']));
    expect(enhanceRoutes($requests))->toContain('PUT /login/password-recovery')
        ->and(end($requests)['json'])->toBe(['email' => 'client@example.com']);
});

test('a failing password setup email does not fail the activation', function (): void {
    $requests = [];
    $client = new MockHttpClient(function (string $method, string $url) use (&$requests): MockResponse {
        $requests[] = $method . ' ' . parse_url($url, PHP_URL_PATH);
        if (str_contains($url, 'password-recovery')) {
            return new MockResponse('', ['http_code' => 500]);
        }
        if (str_contains($url, '/customers?')) {
            return new MockResponse(json_encode(enhanceListing([enhanceCustomerOrg()])));
        }
        if (str_contains($url, '/members?')) {
            return new MockResponse(json_encode(enhanceListing([enhanceMember()])));
        }

        return new MockResponse(json_encode(enhanceListing([enhanceWebsite()])));
    });

    expect(createEnhanceManager($client)->createAccount(createEnhanceAccount(['plan_id' => '7', 'send_setup_email' => 'yes'])))->toBeTrue()
        ->and($requests)->toContain('PUT /api/login/password-recovery');
});

test('suspendAccount resolves an imported account by owner email and domain and suspends both subscription and website', function (): void {
    $requests = [];
    createEnhanceManager(enhanceClient($requests))->suspendAccount(createEnhanceAccount()->setUsername('whatever-was-generated'));

    expect(enhanceRoutes($requests))->toBe([
        'GET /orgs/' . enhanceOrgId() . '/customers',
        'GET /orgs/' . enhanceCustomerId() . '/websites',
        'PATCH /orgs/' . enhanceCustomerId() . '/subscriptions/42',
        'PATCH /orgs/' . enhanceCustomerId() . '/websites/' . enhanceWebsiteId(),
    ])
        ->and($requests[1]['query']['search'])->toBe('example.com')
        ->and($requests[2]['json'])->toBe(['isSuspended' => true])
        ->and($requests[3]['json'])->toBe(['isSuspended' => true]);
});

test('unsuspendAccount clears the suspension on both subscription and website', function (): void {
    $requests = [];
    createEnhanceManager(enhanceClient($requests))->unsuspendAccount(createEnhanceAccount());

    expect($requests[2]['json'])->toBe(['isSuspended' => false])
        ->and($requests[3]['json'])->toBe(['isSuspended' => false]);
});

test('suspendAccount still suspends the website when its subscription no longer exists', function (): void {
    $requests = [];
    $client = new MockHttpClient(function (string $method, string $url) use (&$requests): MockResponse {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $requests[] = $method . ' ' . $path;
        if ($method === 'PATCH' && str_contains($path, '/subscriptions/')) {
            return new MockResponse('{"message":"not found"}', ['http_code' => 404]);
        }
        if ($method === 'PATCH') {
            return new MockResponse('', ['http_code' => 204]);
        }
        if (str_contains($url, '/customers?')) {
            return new MockResponse(json_encode(enhanceListing([enhanceCustomerOrg()])));
        }

        return new MockResponse(json_encode(enhanceListing([enhanceWebsite()])));
    });

    expect(createEnhanceManager($client)->suspendAccount(createEnhanceAccount()))->toBeTrue()
        ->and(end($requests))->toBe('PATCH /api/orgs/' . enhanceCustomerId() . '/websites/' . enhanceWebsiteId());
});

test('an account is resolved by domain across customers when no organization is owned by the email', function (): void {
    $requests = [];
    $manager = createEnhanceManager(enhanceClient($requests, ['customers' => [enhanceCustomerOrg(['ownerEmail' => 'someone-else@example.com'])], 'resellerWebsites' => [enhanceWebsite()]]));

    $manager->suspendAccount(createEnhanceAccount());

    expect(enhanceRoutes($requests))->toContain('GET /orgs/' . enhanceOrgId() . '/websites')
        ->and($requests[1]['query'])->toMatchArray(['search' => 'example.com', 'recursion' => 'directCustomers'])
        ->and(enhanceRoutes($requests))->toContain('GET /orgs/' . enhanceCustomerId())
        ->and(enhanceRoutes($requests))->toContain('PATCH /orgs/' . enhanceCustomerId() . '/subscriptions/42');
});

test('operations fail with a clear message when neither email nor domain is found on the panel', function (): void {
    $requests = [];
    $manager = createEnhanceManager(enhanceClient($requests, ['customers' => [], 'websites' => []]));

    $manager->suspendAccount(createEnhanceAccount());
})->throws(Server_Exception::class, 'No website for example.com belonging to client@example.com');

test('deleted organizations and websites are ignored when resolving', function (): void {
    $requests = [];
    $manager = createEnhanceManager(enhanceClient($requests, [
        'customers' => [enhanceCustomerOrg(['status' => 'deleted'])],
        'resellerWebsites' => [enhanceWebsite(['status' => 'deleted'])],
    ]));

    $manager->suspendAccount(createEnhanceAccount());
})->throws(Server_Exception::class, 'No website for example.com');

test('cancelAccount deletes the subscription and keeps the customer organization', function (): void {
    $requests = [];
    createEnhanceManager(enhanceClient($requests))->cancelAccount(createEnhanceAccount());

    expect(array_slice(enhanceRoutes($requests), -1))->toBe(['DELETE /orgs/' . enhanceCustomerId() . '/subscriptions/42'])
        ->and(enhanceRoutes($requests))->not->toContain('DELETE /orgs/' . enhanceCustomerId());
});

test('cancelAccount deletes the website directly when it has no subscription', function (): void {
    $requests = [];
    createEnhanceManager(enhanceClient($requests, ['websites' => [enhanceWebsite(['subscriptionId' => null])]]))->cancelAccount(createEnhanceAccount());

    expect(array_slice(enhanceRoutes($requests), -1))->toBe(['DELETE /orgs/' . enhanceCustomerId() . '/websites/' . enhanceWebsiteId()]);
});

test('cancelAccount treats an already removed subscription as cancelled', function (): void {
    $requests = [];
    $client = new MockHttpClient(function (string $method, string $url) use (&$requests): MockResponse {
        $requests[] = $method . ' ' . parse_url($url, PHP_URL_PATH);
        if ($method === 'DELETE') {
            return new MockResponse('', ['http_code' => 404]);
        }
        if (str_contains($url, '/customers?')) {
            return new MockResponse(json_encode(enhanceListing([enhanceCustomerOrg()])));
        }

        return new MockResponse(json_encode(enhanceListing([enhanceWebsite()])));
    });

    expect(createEnhanceManager($client)->cancelAccount(createEnhanceAccount()))->toBeTrue();
});

test('changeAccountPackage moves the subscription to the new plan', function (): void {
    $requests = [];
    createEnhanceManager(enhanceClient($requests))->changeAccountPackage(createEnhanceAccount(), (new Server_Package())->setName('Pro')->setCustomValue('plan_id', '12'));

    expect(array_slice(enhanceRoutes($requests), -1))->toBe(['PATCH /orgs/' . enhanceCustomerId() . '/subscriptions/42'])
        ->and(end($requests)['json'])->toBe(['planId' => 12]);
});

test('changeAccountDomain maps the new domain and makes it primary', function (): void {
    $requests = [];
    createEnhanceManager(enhanceClient($requests))->changeAccountDomain(createEnhanceAccount(), 'example.net');

    expect(array_slice(enhanceRoutes($requests), -2))->toBe([
        'POST /orgs/' . enhanceCustomerId() . '/websites/' . enhanceWebsiteId() . '/domains',
        'PUT /orgs/' . enhanceCustomerId() . '/websites/' . enhanceWebsiteId() . '/domains/primary',
    ])
        ->and($requests[count($requests) - 2]['json'])->toBe(['domain' => 'example.net', 'kind' => 'alias'])
        ->and(end($requests)['json'])->toBe(['domainId' => 'd2']);
});

test('changeAccountPassword sets the password on the owner login', function (): void {
    $requests = [];
    createEnhanceManager(enhanceClient($requests))->changeAccountPassword(createEnhanceAccount(), 'new-secret');

    expect(array_slice(enhanceRoutes($requests), -1))->toBe(['PUT /v2/logins/' . enhanceLoginId() . '/password'])
        ->and(end($requests)['json'])->toBe(['newPassword' => 'new-secret']);
});

test('getLoginUrl returns the one-time SSO link for the client login', function (): void {
    $requests = [];

    expect(createEnhanceManager(enhanceClient($requests))->getLoginUrl(createEnhanceAccount()))->toBe('https://panel.example.com/sso?otp=one-time')
        ->and(array_slice(enhanceRoutes($requests), -1))->toBe(['GET /orgs/' . enhanceCustomerId() . '/members/' . enhanceMemberId() . '/sso']);
});

test('getLoginUrl falls back to the panel address when the account cannot be resolved', function (): void {
    $requests = [];

    expect(createEnhanceManager(enhanceClient($requests, ['customers' => [], 'websites' => []]))->getLoginUrl(createEnhanceAccount()))->toBe('https://panel.example.com/');
});

test('synchronizeAccount reports the client email as username and the primary server IP', function (): void {
    $requests = [];
    $account = createEnhanceAccount();

    $updated = createEnhanceManager(enhanceClient($requests))->synchronizeAccount($account);

    expect($updated)->not->toBe($account)
        ->and($updated->getUsername())->toBe('client@example.com')
        ->and($updated->getIp())->toBe('203.0.113.10')
        ->and($account->getUsername())->toBe('example');
});

test('synchronizeAccount leaves the IP alone when the website reports none', function (): void {
    $requests = [];
    $client = new MockHttpClient(function (string $method, string $url): MockResponse {
        if (str_contains($url, '/customers?')) {
            return new MockResponse(json_encode(enhanceListing([enhanceCustomerOrg()])));
        }
        if (str_ends_with((string) parse_url($url, PHP_URL_PATH), '/websites/' . enhanceWebsiteId())) {
            return new MockResponse(json_encode(enhanceWebsite(['serverIps' => []])));
        }

        return new MockResponse(json_encode(enhanceListing([enhanceWebsite(['serverIps' => []])])));
    });

    expect(createEnhanceManager($client)->synchronizeAccount(createEnhanceAccount())->getIp())->toBe('10.0.0.1');
});

test('listPackages maps Enhance plan resources onto hosting plan limits', function (): void {
    $plans = [
        ['id' => 3, 'name' => 'Basic', 'resources' => [
            ['name' => 'diskspace', 'total' => 7000000000],
            ['name' => 'transfer', 'total' => null],
            ['name' => 'websites', 'total' => 1],
            ['name' => 'addonDomains', 'total' => 2],
            ['name' => 'subdomains', 'total' => 5],
            ['name' => 'domainAliases', 'total' => 0],
            ['name' => 'ftpUsers', 'total' => 3],
            ['name' => 'mysqlDbs', 'total' => 4],
            ['name' => 'mailboxes', 'total' => 10],
        ]],
        ['id' => 4, 'name' => 'Growth', 'resources' => []],
    ];
    $requests = [];
    $manager = createEnhanceManager(enhanceClient($requests, ['plans' => $plans]));

    expect(Server_Manager_Enhance::supportsPackageSync())->toBeTrue()
        ->and($manager->listPackages())->toBe([
            ['id' => '3', 'name' => 'Basic', 'quota' => 7000, 'bandwidth' => null, 'max_addon' => 2, 'max_sub' => 5, 'max_park' => 0, 'max_ftp' => 3, 'max_sql' => 4, 'max_pop' => 10, 'config' => ['plan_id' => '3']],
            ['id' => '4', 'name' => 'Growth', 'quota' => null, 'bandwidth' => null, 'max_addon' => null, 'max_sub' => null, 'max_park' => null, 'max_ftp' => null, 'max_sql' => null, 'max_pop' => null, 'config' => ['plan_id' => '4']],
        ])
        ->and(enhanceRoutes($requests))->toBe(['GET /orgs/' . enhanceOrgId() . '/plans']);
});

test('accountUsage reports the subscription usage against the plan limits', function (): void {
    $requests = [];

    $usage = createEnhanceManager(enhanceClient($requests))->accountUsage(createEnhanceAccount());

    expect($usage)->toBe([
        'plan_name' => 'Business',
        'status' => 'active',
        'suspended' => false,
        'disk_used_mb' => 2500,
        'disk_quota_mb' => 10000,
        'bandwidth_used_mb' => 750,
        'bandwidth_limit_mb' => null,
        'counts' => [
            'websites' => ['used' => 1, 'limit' => 1],
            'addon_domains' => ['used' => 0, 'limit' => 2],
            'subdomains' => ['used' => 3, 'limit' => 5],
            'domain_aliases' => ['used' => 0, 'limit' => 0],
            'mailboxes' => ['used' => 4, 'limit' => 10],
            'databases' => ['used' => 2, 'limit' => null],
            'ftp_users' => ['used' => 1, 'limit' => 3],
        ],
        'websites' => [[
            'domain' => 'example.com',
            'primary' => true,
            'aliases' => ['example.net'],
            'kind' => 'normal',
            'suspended' => false,
            'disk_used_bytes' => 6434000,
            'php_version' => '8.5',
            'server' => 'web01.example.net',
            'created_at' => '2026-09-13T20:10:22Z',
            'last_backup_at' => '2026-09-13T13:44:09Z',
            'stats' => ['days' => 30, 'visitors' => 74, 'requests' => 316, 'bot_requests' => 3, 'bytes_sent' => 333255, 'bytes_received' => 243501],
        ]],
    ])
        ->and(enhanceRoutes($requests))->toBe([
            'GET /orgs/' . enhanceOrgId() . '/customers',
            'GET /orgs/' . enhanceCustomerId() . '/websites',
            'GET /orgs/' . enhanceCustomerId() . '/subscriptions/42',
            'GET /orgs/' . enhanceCustomerId() . '/websites',
            'GET /orgs/' . enhanceCustomerId() . '/websites/' . enhanceWebsiteId() . '/metrics',
            'GET /orgs/' . enhanceCustomerId() . '/websites/' . enhanceWebsiteId() . '/backups',
        ])
        ->and($requests[3]['query'])->toMatchArray(['subscriptionId' => '42'])
        ->and($requests[4]['query'])->toHaveKey('start')
        ->and($requests[4]['query']['granularity'])->toBe('day');
});

test('accountUsage lists the account domain first, skips Enhance-owned websites and tolerates missing metrics and backups', function (): void {
    $requests = [];
    $websites = [
        enhanceWebsite(['id' => 'w-staging', 'kind' => 'staging', 'domain' => ['domain' => 'staging.example.com'], 'status' => 'disabled', 'suspendedBy' => enhanceOrgId(), 'aliases' => []]),
        enhanceWebsite(['id' => 'w-panel', 'kind' => 'controlPanel', 'domain' => ['domain' => 'panel.example.com']]),
        enhanceWebsite(['id' => 'w-gone', 'status' => 'deleted', 'domain' => ['domain' => 'old.example.com']]),
        enhanceWebsite(),
    ];

    $usage = createEnhanceManager(enhanceClient($requests, ['websites' => $websites, 'metricsFails' => true, 'backupsFails' => true]))->accountUsage(createEnhanceAccount());

    expect(array_column($usage['websites'], 'domain'))->toBe(['example.com', 'staging.example.com'])
        ->and(array_column($usage['websites'], 'primary'))->toBe([true, false])
        ->and($usage['websites'][0]['stats'])->toBeNull()
        ->and($usage['websites'][0]['last_backup_at'])->toBeNull()
        ->and($usage['websites'][1])->toMatchArray(['kind' => 'staging', 'suspended' => true, 'aliases' => []]);
});

test('accountUsage flags a suspended subscription and leaves out resources the plan does not track', function (): void {
    $requests = [];
    $subscription = enhanceSubscription(['status' => 'suspended', 'resources' => [['name' => 'diskspace', 'usage' => 0]]]);

    $usage = createEnhanceManager(enhanceClient($requests, ['subscription' => $subscription]))->accountUsage(createEnhanceAccount());

    expect($usage['suspended'])->toBeTrue()
        ->and($usage['status'])->toBe('suspended')
        ->and($usage['disk_used_mb'])->toBe(0)
        ->and($usage['disk_quota_mb'])->toBeNull()
        ->and($usage['bandwidth_used_mb'])->toBe(0)
        ->and($usage['bandwidth_limit_mb'])->toBeNull()
        ->and($usage['counts'])->toBe([]);
});

test('accountUsage flags a suspended website even when its subscription is active', function (): void {
    $requests = [];

    $usage = createEnhanceManager(enhanceClient($requests, ['websites' => [enhanceWebsite(['status' => 'disabled', 'suspendedBy' => enhanceOrgId()])]]))->accountUsage(createEnhanceAccount());

    expect($usage['suspended'])->toBeTrue()
        ->and($usage['status'])->toBe('active');
});

test('accountUsage fails when the website has no subscription', function (): void {
    $requests = [];

    createEnhanceManager(enhanceClient($requests, ['websites' => [enhanceWebsite(['subscriptionId' => null])]]))->accountUsage(createEnhanceAccount());
})->throws(Server_Exception::class, 'read the account usage');

test('accountUsage passes API errors on', function (): void {
    $requests = [];

    createEnhanceManager(enhanceClient($requests, ['subscriptionFails' => true]))->accountUsage(createEnhanceAccount());
})->throws(Server_Exception::class, 'HttpClientException');

test('username and IP changes are reported as unsupported', function (): void {
    $requests = [];
    $manager = createEnhanceManager(enhanceClient($requests));

    expect(fn (): never => $manager->changeAccountUsername(createEnhanceAccount(), 'other'))->toThrow(Server_Exception::class, 'does not support')
        ->and(fn (): never => $manager->changeAccountIp(createEnhanceAccount(), '203.0.113.99'))->toThrow(Server_Exception::class, 'does not support')
        ->and($requests)->toBe([]);
});

test('server errors are wrapped as HttpClientException', function (): void {
    $client = new MockHttpClient(static fn (): MockResponse => new MockResponse('{"message":"boom"}', ['http_code' => 500]));

    createEnhanceManager($client)->testConnection();
})->throws(Server_Exception::class, 'HttpClientException');

test('listings are followed page by page until the total is reached', function (): void {
    $requests = [];
    $client = new MockHttpClient(function (string $method, string $url) use (&$requests): MockResponse {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $requests[] = $query;
        if (str_contains($url, '/customers?')) {
            $page = (int) ($query['offset'] ?? 0) === 0
                ? array_fill(0, 100, enhanceCustomerOrg(['id' => 'other', 'ownerEmail' => 'other@example.com']))
                : [enhanceCustomerOrg()];

            return new MockResponse(json_encode(['items' => $page, 'total' => 101]));
        }

        return new MockResponse(json_encode(enhanceListing([enhanceWebsite()])));
    });

    createEnhanceManager($client)->suspendAccount(createEnhanceAccount());

    expect($requests[0])->toMatchArray(['offset' => '0', 'limit' => '100'])
        ->and($requests[1])->toMatchArray(['offset' => '100', 'limit' => '100']);
});

test('neither the API token nor the account password is ever written to the log', function (): void {
    $logger = new class extends Psr\Log\AbstractLogger {
        /** @var list<string> */
        public array $lines = [];

        public function log($level, string|Stringable $message, array $context = []): void
        {
            $this->lines[] = $message . ' ' . json_encode($context);
        }
    };

    $requests = [];
    $manager = createEnhanceManager(enhanceClient($requests, ['customers' => [], 'members' => [], 'websites' => [], 'websiteCreationFails' => true]));
    $manager->setLog($logger);

    try {
        $manager->createAccount(createEnhanceAccount(['plan_id' => '7']));
    } catch (Server_Exception) {
    }

    expect($logger->lines)->not->toBeEmpty()
        ->and(implode("\n", $logger->lines))->not->toContain('token-secret')
        ->and(implode("\n", $logger->lines))->not->toContain('secret-pass');
});
