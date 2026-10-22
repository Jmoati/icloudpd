<?php

namespace Tests;

use Foundation\Http\ConnectionError;
use Foundation\Http\LWPCookieJar;
use Foundation\Http\NewConnectionError;
use Foundation\Http\Timeout;
use Foundation\Http\TimeoutError;
use Foundation\Patch;
use PyicloudIpd\Exceptions\PyiCloudConnectionErrorException;
use PyicloudIpd\Session\PyiCloudSession;

/**
 * `tests/test_session_connection_errors.py`: integration test for PyiCloudSession connection error handling.
 *
 * The upstream `patch.object(self.session.__class__.__bases__[0], "request", side_effect=...)` is
 * `Patch::set('Foundation\Http\Session::request', ...)`.
 */
class SessionConnectionErrorsTest extends TestCase
{
    private const SESSION_REQUEST = 'Foundation\Http\Session::request';

    private ?HttpServer $server = null;
    private string $base_url = 'http://127.0.0.1:1';
    private object $mock_service;
    private PyiCloudSession $session;

    /** Set up test fixtures. */
    protected function setUp(): void
    {
        parent::setUp();
        $this->server = null;

        // Create a mock service
        $temp_dir = sys_get_temp_dir();
        $this->mock_service = new class($temp_dir) {
            public $password_filter = null;
            public $http_timeout = 1; // 1 second timeout for testing
            public array $session_data = [];

            public function __construct(private string $temp_dir)
            {
            }

            // Use cross-platform temporary directory
            public function session_path(): string
            {
                return $this->temp_dir.DIRECTORY_SEPARATOR.'test_session.json';
            }

            public function cookiejar_path(): string
            {
                return $this->temp_dir.DIRECTORY_SEPARATOR.'test_cookies.jar';
            }

            public function requires_2sa(): bool
            {
                return false;
            }
        };

        $this->session = new PyiCloudSession($this->mock_service);
        // Mock the cookies.save method to avoid file operations
        $this->session->cookies = new class extends LWPCookieJar {
            public function save(?string $filename = null, bool $ignore_discard = false, bool $ignore_expires = false): void
            {
            }
        };
    }

    /** Clean up test fixtures. */
    protected function tearDown(): void
    {
        $this->server?->stop();
        parent::tearDown();
    }

    /** Start the test HTTP server with specified error behavior. */
    private function _start_server(string $error_type = 'normal'): void
    {
        $this->server = new HttpServer($error_type);
        $this->base_url = $this->server->base_url;
    }

    /** the upstream `patch.object(requests.Session, "request", side_effect=...)` */
    private function patch_request(\Throwable $side_effect): void
    {
        Patch::set(self::SESSION_REQUEST, fn (...$args) => throw $side_effect);
    }

    /** Test that normal requests work without connection error handling. */
    public function test_normal_request(): void
    {
        $this->_start_server('normal');

        $response = $this->session->get($this->base_url);
        $this->assertSame(200, $response->status_code);
        $this->assertSame('{"success": true}', $response->text());
        // the (empty) session data is saved as the JSON of an empty dict
        $this->assertSame('{}', file_get_contents($this->mock_service->session_path()));
    }

    /** Test that ConnectionError is wrapped as PyiCloudConnectionErrorException. */
    public function test_connection_error_handling(): void
    {
        $this->_start_server('connection_error');

        try {
            $this->session->get($this->base_url);
            $this->fail('PyiCloudConnectionErrorException expected');
        } catch (PyiCloudConnectionErrorException $context) {
            $this->assertSame('Cannot connect to Apple iCloud service', $context->getMessage());
            $this->assertInstanceOf(ConnectionError::class, $context->getPrevious());
        }
    }

    /** Test that timeout errors are wrapped as PyiCloudConnectionErrorException. */
    public function test_timeout_error_handling(): void
    {
        $this->_start_server('timeout');

        try {
            // Use a short timeout to ensure it times out
            $this->session->get($this->base_url, timeout: 0.5);
            $this->fail('PyiCloudConnectionErrorException expected');
        } catch (PyiCloudConnectionErrorException $context) {
            $this->assertSame('Cannot connect to Apple iCloud service', $context->getMessage());
            // The underlying exception should be a timeout-related error
            $this->assertTrue(
                $context->getPrevious() instanceof TimeoutError || $context->getPrevious() instanceof Timeout
                || str_contains(strtolower($context->getPrevious()->getMessage()), 'timeout'),
            );
        }
    }

    /** Test that built-in TimeoutError is wrapped as PyiCloudConnectionErrorException. */
    public function test_builtin_timeout_error_handling(): void
    {
        // Mock the underlying request to raise TimeoutError directly
        $this->patch_request(new TimeoutError('Test timeout'));
        try {
            $this->session->get($this->base_url);
            $this->fail('PyiCloudConnectionErrorException expected');
        } catch (PyiCloudConnectionErrorException $context) {
            $this->assertSame('Cannot connect to Apple iCloud service', $context->getMessage());
            $this->assertInstanceOf(TimeoutError::class, $context->getPrevious());
        }
    }

    /** Test that NewConnectionError is wrapped as PyiCloudConnectionErrorException. */
    public function test_new_connection_error_handling(): void
    {
        // Mock the underlying request to raise NewConnectionError
        $this->patch_request(new NewConnectionError(null, 'Test new connection error'));
        try {
            $this->session->get($this->base_url);
            $this->fail('PyiCloudConnectionErrorException expected');
        } catch (PyiCloudConnectionErrorException $context) {
            $this->assertSame('Cannot connect to Apple iCloud service', $context->getMessage());
            $this->assertInstanceOf(NewConnectionError::class, $context->getPrevious());
        }
    }

    /** Test that other exceptions are not wrapped. */
    public function test_other_exceptions_pass_through(): void
    {
        // Mock the underlying request to raise ValueError
        $this->patch_request(new \ValueError('Test other error'));
        try {
            $this->session->get($this->base_url);
            $this->fail('ValueError expected');
        } catch (\ValueError $context) {
            $this->assertSame('Test other error', $context->getMessage());
        }
    }

    /** Test that all HTTP methods are covered by the connection error handling. */
    public function test_all_http_methods_covered(): void
    {
        $methods_to_test = ['get', 'post', 'put', 'patch', 'delete', 'head', 'options'];

        foreach ($methods_to_test as $method) {
            $this->patch_request(new ConnectionError("Test $method connection error"));
            try {
                $this->session->$method($this->base_url);
                $this->fail("$method: PyiCloudConnectionErrorException expected");
            } catch (PyiCloudConnectionErrorException $context) {
                $this->assertSame('Cannot connect to Apple iCloud service', $context->getMessage(), $method);
                $this->assertInstanceOf(ConnectionError::class, $context->getPrevious(), $method);
            }
        }
    }
}
