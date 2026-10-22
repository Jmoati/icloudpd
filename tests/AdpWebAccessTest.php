<?php

namespace Tests;

use Foundation\Core;
use Foundation\Http\Response;
use Foundation\Patch;
use Foundation\Py;
use Icloudpd\Authentication;
use Icloudpd\Base;
use Icloudpd\Logger;
use Icloudpd\MfaProvider\MFAProvider;
use Icloudpd\Status\StatusExchange;
use PyicloudIpd\Base\PyiCloudService;
use PyicloudIpd\Exceptions\PyiCloudFailedLoginException;
use PyicloudIpd\Session\PyiCloudSession;

/**
 * `tests/test_adp_web_access.py` (both of its test cases): `request_pcs_consent` and the `adp_web_access` argument
 * of `authenticator`. `time.sleep` and `time.monotonic` are patches of `Time::sleep` and `Time::monotonic`,
 * `mock.patch("icloudpd.authentication.PyiCloudService")` one of `Authentication::PyiCloudService`.
 * The `SimpleNamespace` stand-in of the service is a `PyiCloudService` built without its constructor (which
 * authenticates), whose session is a `PyiCloudSession` answering from the list of responses (the `mock.Mock()` session).
 */
class AdpWebAccessTest extends TestCase
{
    private const CONSENTED = ['isWebAccessAllowed' => true, 'isDeviceConsentedForPCS' => true];
    private const NOT_CONSENTED = ['isWebAccessAllowed' => true, 'isDeviceConsentedForPCS' => false];

    /** @var list<float> the `time.sleep` calls */
    private array $sleep = [];

    protected function setUp(): void
    {
        parent::setUp();
        // `no_sleep` fixture
        $this->sleep = [];
        Patch::set('Foundation\Time::sleep', function (float $seconds): void {
            $this->sleep[] = $seconds;
        });
    }

    /** Stand-in for PyiCloudService: only what request_pcs_consent touches. */
    private function make_service(array $responses): PyiCloudService
    {
        $session = new class ($responses) extends PyiCloudSession {
            /** @var list<array{0: string, 1: string, 2: array}> `session.request.call_args_list` */
            public array $calls = [];

            public function __construct(private array $responses)
            {
                parent::__construct(null);
            }

            public function request(string $method, string $url, mixed ...$kwargs): Response
            {
                $this->calls[] = [$method, $url, $kwargs];
                $response = array_shift($this->responses);

                return new Response(200, [], Py::json_dumps($response === [] ? new \stdClass() : $response));
            }
        };
        $service = (new \ReflectionClass(PyiCloudService::class))->newInstanceWithoutConstructor();
        $service->params = ['dsid' => '1'];
        $service->client_id = 'client';
        $service->SETUP_ENDPOINT = 'https://setup.icloud.com/setup/ws/1';
        $service->session = $session;

        return $service;
    }

    /** @return list<string> */
    private function called_endpoints(PyiCloudService $service): array
    {
        return array_map(fn (array $call) => substr(strrchr($call[1], '/'), 1), $service->session->calls);
    }

    public function test_already_consented(): void
    {
        $service = $this->make_service([self::CONSENTED, [], ['status' => 'success']]);

        $service->request_pcs_consent();

        $this->assertSame(
            ['requestWebAccessState', 'generateSessionIdToken', 'requestPCS'],
            $this->called_endpoints($service),
        );
        $this->assertSame([], $this->sleep);
    }

    public function test_web_access_disabled(): void
    {
        $service = $this->make_service([['isWebAccessAllowed' => false]]);

        $context = null;
        try {
            $service->request_pcs_consent();
        } catch (PyiCloudFailedLoginException $e) {
            $context = $e;
        }

        $this->assertNotNull($context);
        $this->assertStringContainsString('Access iCloud Data on the Web', (string) $context->getMessage());
    }

    public function test_waits_for_device_approval(): void
    {
        $service = $this->make_service(
            [self::NOT_CONSENTED, [], self::NOT_CONSENTED, self::CONSENTED, [], ['status' => 'success']],
        );

        $service->request_pcs_consent();

        $this->assertSame(
            [
                'requestWebAccessState',
                'enableDeviceConsentForPCS',
                'requestWebAccessState',
                'requestWebAccessState',
                'generateSessionIdToken',
                'requestPCS',
            ],
            $this->called_endpoints($service),
        );
        $this->assertSame(2, count($this->sleep));
    }

    public function test_approval_timeout(): void
    {
        $service = $this->make_service([self::NOT_CONSENTED, []]);

        $monotonic = [0, 1000];
        Patch::set('Foundation\Time::monotonic', function () use (&$monotonic): float {
            return (float) (array_shift($monotonic) ?? throw new \OutOfRangeException('StopIteration'));
        });
        $context = null;
        try {
            $service->request_pcs_consent();
        } catch (PyiCloudFailedLoginException $e) {
            $context = $e;
        }

        $this->assertNotNull($context);
        $this->assertStringContainsString('Timed out', (string) $context->getMessage());
    }

    public function test_retries_request_pcs_until_success(): void
    {
        $service = $this->make_service(
            [self::CONSENTED, [], ['status' => 'pending'], ['status' => 'pending'], ['status' => 'success']],
        );

        $service->request_pcs_consent();

        $this->assertSame(['requestPCS', 'requestPCS', 'requestPCS'], array_slice($this->called_endpoints($service), 2));
        $this->assertSame(2, count($this->sleep));
    }

    /** @return list<array> the arguments of the calls of `request_pcs_consent` */
    private function authenticate(bool $adp_web_access): array
    {
        // the `service_class.return_value` mock (`func_get_args()` only has the arguments that were passed)
        $service = new class extends PyiCloudService {
            /** @var list<array> */
            public array $calls = [];

            public function __construct()
            {
            }

            public function requires_2fa(): bool
            {
                return false;
            }

            public function requires_2sa(): bool
            {
                return false;
            }

            public function request_pcs_consent(string $app_name = 'photos', int $timeout_seconds = 120): void
            {
                $this->calls[] = func_get_args();
            }
        };
        Patch::set('Icloudpd\Authentication::PyiCloudService', fn (mixed ...$args) => $service);
        Authentication::authenticator(
            Logger::setup_logger(),
            'com',
            ['test' => [Core::constant('dummy'), Base::dummy_password_writter(...)]],
            MFAProvider::CONSOLE,
            new StatusExchange(),
            'user',
            fn () => null,
            adp_web_access: $adp_web_access,
        );

        return $service->calls;
    }

    public function test_requests_consent_when_enabled(): void
    {
        // `assert_called_once_with("photos")`
        $this->assertSame([['photos']], $this->authenticate(true));
    }

    public function test_skips_consent_by_default(): void
    {
        $this->assertSame([], $this->authenticate(false));
    }
}
