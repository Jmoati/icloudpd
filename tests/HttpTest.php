<?php

namespace Tests;

use Foundation\Http;
use Foundation\Http\CaseInsensitiveDict;
use Foundation\Http\Request;
use Foundation\Http\Response;
use Foundation\Http\Session;
use Foundation\Py;

/** `tests/test_http.py` (`TestHttpUtils` and `TestHttpIntegration`) */
class HttpTest extends TestCase
{
    private static HttpServer $server;
    private static string $base_url;

    public static function setUpBeforeClass(): void
    {
        // Start test server
        self::$server = new HttpServer('http');
        self::$base_url = self::$server->base_url;
    }

    public static function tearDownAfterClass(): void
    {
        // Stop test server
        self::$server->stop();
    }

    /** a `raw` whose `isclosed()` answers (or throws) */
    private function raw(bool|\Throwable $isclosed): object
    {
        return new class($isclosed) {
            public function __construct(private bool|\Throwable $isclosed)
            {
            }

            public function isclosed(): bool
            {
                return $this->isclosed instanceof \Throwable ? throw $this->isclosed : $this->isclosed;
            }
        };
    }

    private function mock_request(string $method, string $url, array $headers): Request
    {
        $mock_request = new Request();
        $mock_request->method = $method;
        $mock_request->url = $url;
        $mock_request->headers = new CaseInsensitiveDict($headers);
        $mock_request->body = null;

        return $mock_request;
    }

    private function mock_response(Request $request, string $content_type, object $raw, ?string $content = null): Response
    {
        $response = new Response();
        $response->request = $request;
        $response->status_code = 200;
        $response->headers = ["Content-Type" => $content_type];
        $response->cookies = [];
        $response->raw = $raw;
        if ($content !== null) {
            $response->_content = $content;
            $response->_content_consumed = true;
        }

        return $response;
    }

    // TestHttpUtils: Test HTTP utility functions

    /** Test that is_streaming_response returns False for closed connections */
    public function test_is_streaming_response_with_closed_connection(): void
    {
        $response = new Response();
        $response->raw = $this->raw(true);

        $result = Http::is_streaming_response($response);

        $this->assertFalse($result);
    }

    /** Test that is_streaming_response returns True for open connections */
    public function test_is_streaming_response_with_open_connection(): void
    {
        $response = new Response();
        $response->raw = $this->raw(false);

        $result = Http::is_streaming_response($response);

        $this->assertTrue($result);
    }

    /** Test that is_streaming_response returns False when raw attribute is missing */
    public function test_is_streaming_response_with_no_raw_attribute(): void
    {
        $response = new Response();
        // Don't set raw attribute

        $result = Http::is_streaming_response($response);

        $this->assertFalse($result);
    }

    /** Test that is_streaming_response returns False when exception occurs */
    public function test_is_streaming_response_with_exception(): void
    {
        $response = new Response();
        $response->raw = $this->raw(new \Exception('Connection error'));

        $result = Http::is_streaming_response($response);

        $this->assertFalse($result);
    }

    /** Test that response_to_har_entry creates content node for JSON responses */
    public function test_response_to_har_entry_with_json_response(): void
    {
        // Create mock request and response
        $mock_request = $this->mock_request('GET', 'https://example.com/api', ['Accept' => 'application/json']);
        $response = $this->mock_response($mock_request, 'application/json', $this->raw(true), '{"result": "success"}'); // Non-streaming response

        $har_entry = Http::response_to_har_entry($response);

        $this->assertArrayHasKey('response', $har_entry);
        $this->assertArrayHasKey('content', $har_entry['response']);
        $this->assertSame(['result' => 'success'], $har_entry['response']['content']);
    }

    /** Test that response_to_har_entry skips content node for stream responses */
    public function test_response_to_har_entry_with_stream_response(): void
    {
        // Create mock request and response
        $mock_request = $this->mock_request('GET', 'https://example.com/stream', ['Accept' => '*/*']);
        $response = $this->mock_response($mock_request, 'application/octet-stream', $this->raw(false)); // Streaming response

        $har_entry = Http::response_to_har_entry($response);

        $this->assertArrayHasKey('response', $har_entry);
        $this->assertArrayHasKey('content', $har_entry['response']);
        $this->assertNull($har_entry['response']['content']);
    }

    // TestHttpIntegration: Integration tests using real HTTP server

    /** Test response_to_har_entry with real JSON response */
    public function test_response_to_har_entry_with_real_json_response(): void
    {
        $response = (new Session())->get(self::$base_url.'/json');

        $har_entry = Http::response_to_har_entry($response);

        // Verify structure
        $this->assertArrayHasKey('request', $har_entry);
        $this->assertArrayHasKey('response', $har_entry);

        // Verify request details
        $this->assertSame('GET', $har_entry['request']['method']);
        $this->assertStringContainsString('/json', $har_entry['request']['url']);

        // Verify response details
        $this->assertSame(200, $har_entry['response']['status_code']);
        $this->assertArrayHasKey('content', $har_entry['response']);

        // Verify JSON content is parsed correctly
        $expected_content = ['message' => 'Hello, World!', 'status' => 'success', 'data' => [1, 2, 3]];
        $this->assertSame($expected_content, $har_entry['response']['content']);

        // Verify it's not a streaming response
        $this->assertFalse(Http::is_streaming_response($response));
    }

    /** Test response_to_har_entry with real text response */
    public function test_response_to_har_entry_with_real_text_response(): void
    {
        $response = (new Session())->get(self::$base_url.'/text');

        $har_entry = Http::response_to_har_entry($response);

        // Verify structure
        $this->assertArrayHasKey('request', $har_entry);
        $this->assertArrayHasKey('response', $har_entry);

        // Verify request details
        $this->assertSame('GET', $har_entry['request']['method']);
        $this->assertStringContainsString('/text', $har_entry['request']['url']);

        // Verify response details
        $this->assertSame(200, $har_entry['response']['status_code']);
        $this->assertArrayHasKey('content', $har_entry['response']);

        // Verify text content is included
        $expected_content = 'This is a plain text response for testing purposes.';
        $this->assertSame($expected_content, $har_entry['response']['content']);

        // Verify it's not a streaming response
        $this->assertFalse(Http::is_streaming_response($response));
    }

    /** Test response_to_har_entry with real streaming binary response */
    public function test_response_to_har_entry_with_real_streaming_response(): void
    {
        $response = (new Session())->get(self::$base_url.'/stream', stream: true);

        $har_entry = Http::response_to_har_entry($response);

        // Verify structure
        $this->assertArrayHasKey('request', $har_entry);
        $this->assertArrayHasKey('response', $har_entry);

        // Verify request details
        $this->assertSame('GET', $har_entry['request']['method']);
        $this->assertStringContainsString('/stream', $har_entry['request']['url']);

        // Verify response details
        $this->assertSame(200, $har_entry['response']['status_code']);
        $this->assertArrayHasKey('content', $har_entry['response']);

        // Verify streaming response has no content in HAR entry
        $this->assertNull($har_entry['response']['content']);

        // Verify it is detected as a streaming response
        $this->assertTrue(Http::is_streaming_response($response));

        // Clean up the response connection
        $response->close();
    }

    /** Test the difference between streaming and non-streaming requests to same endpoint */
    public function test_streaming_vs_non_streaming_behavior(): void
    {
        // Non-streaming request
        $response_normal = (new Session())->get(self::$base_url.'/json');
        $har_normal = Http::response_to_har_entry($response_normal);

        // Streaming request to same endpoint
        $response_stream = (new Session())->get(self::$base_url.'/json', stream: true);
        $har_stream = Http::response_to_har_entry($response_stream);

        // Normal response should have content
        $this->assertNotNull($har_normal['response']['content']);
        $this->assertSame('Hello, World!', $har_normal['response']['content']['message']);
        $this->assertFalse(Http::is_streaming_response($response_normal));

        // Streaming response should have no content
        $this->assertNull($har_stream['response']['content']);
        $this->assertTrue(Http::is_streaming_response($response_stream));

        // Clean up streaming response
        $response_stream->close();
    }

    /** Test response_to_har_entry with multi-GB streaming response to verify no memory leaks */
    public function test_response_to_har_entry_with_large_streaming_response(): void
    {
        // Request a 200.5GB stream (large enough to cause memory issues if not handled properly)
        $size_gb = 200.5;
        $size_mb = Py::str($size_gb * 1024);
        $response = (new Session())->get(self::$base_url."/stream?size_mb=$size_mb", stream: true);

        // This should complete quickly without loading all data into memory
        $start_time = microtime(true);
        $har_entry = Http::response_to_har_entry($response);
        $end_time = microtime(true);

        // Should complete in well under a second since we don't load the content
        $this->assertLessThan(1.0, $end_time - $start_time, 'HAR entry creation took too long, possible memory leak');

        // Verify structure
        $this->assertArrayHasKey('request', $har_entry);
        $this->assertArrayHasKey('response', $har_entry);

        // Verify request details
        $this->assertSame('GET', $har_entry['request']['method']);
        $this->assertStringContainsString('/stream', $har_entry['request']['url']);
        $this->assertStringContainsString("size_mb=$size_mb", $har_entry['request']['url']);

        // Verify response details
        $this->assertSame(200, $har_entry['response']['status_code']);
        $this->assertArrayHasKey('content', $har_entry['response']);

        // Most importantly: streaming response should have NO content in HAR entry
        // This proves we didn't try to load 200.5GB into memory
        $this->assertNull($har_entry['response']['content']);

        // Verify it is detected as a streaming response
        $this->assertTrue(Http::is_streaming_response($response));

        // Clean up the response connection (important for large streams)
        $response->close();
    }

    // The rest of the HTTP layer: what the upstream tests get from `requests` itself

    public function test_stream_is_read_in_chunks_and_closed_at_the_end(): void
    {
        $response = (new Session())->get(self::$base_url.'/stream', stream: true);
        $length = 0;
        foreach ($response->iter_content(chunk_size: 64) as $chunk) {
            $this->assertLessThanOrEqual(64, strlen($chunk));
            $length += strlen($chunk);
        }
        $this->assertSame(500, $length);
        $this->assertFalse(Http::is_streaming_response($response));
    }

    public function test_response_properties(): void
    {
        $response = (new Session())->get(self::$base_url.'/json');
        $this->assertTrue($response->ok());
        $this->assertTrue($response->ok);
        $this->assertSame('OK', $response->reason);
        $this->assertSame('application/json', $response->headers['content-type']);
        $this->assertSame('utf-8', $response->encoding);
        $this->assertSame('Hello, World!', $response->json()['message']);

        $not_found = (new Session())->get(self::$base_url.'/nope');
        $this->assertFalse($not_found->ok());
        $this->assertSame('Not Found', $not_found->text());
        $this->expectException(Http\HTTPError::class);
        $not_found->raise_for_status();
    }

    public function test_a_consumed_stream_cannot_be_read_again(): void
    {
        $response = (new Session())->get(self::$base_url.'/stream', stream: true);
        iterator_to_array($response->iter_content(chunk_size: 128));
        $this->expectException(Http\StreamConsumedError::class);
        $response->iter_content(chunk_size: 128);
    }

    public function test_redirects_are_followed_with_the_cookies_of_each_hop(): void
    {
        $session = new Session();
        $response = $session->get(self::$base_url.'/redirect');
        $this->assertSame(200, $response->status_code);
        $this->assertSame(self::$base_url.'/echo-cookie', $response->url);
        // the cookie set by the redirect is sent to its target
        $this->assertSame('hop=1', $response->text());
        $this->assertCount(1, $response->history);
        $this->assertSame(302, $response->history[0]->status_code);
        $this->assertCount(1, $session->cookies);

        // not followed: the response says where it goes
        $response = $session->get(self::$base_url.'/redirect', allow_redirects: false);
        $this->assertSame(302, $response->status_code);
        $this->assertTrue($response->is_redirect);
        $this->assertSame(self::$base_url.'/echo-cookie', $response->next->url);
    }

    public function test_compressed_body_is_decoded(): void
    {
        $response = (new Session())->get(self::$base_url.'/gzip');
        $this->assertSame('compressed hello', $response->text());
        $this->assertSame('gzip', $response->headers['Content-Encoding']);
    }

    public function test_post_body_and_arguments(): void
    {
        $session = new Session();
        // named arguments, an array of keyword arguments, and the positional data of requests
        $by_name = $session->post(self::$base_url.'/post', data: 'a=b', headers: ['Content-Type' => 'text/plain'], params: ['q' => 'x y']);
        $this->assertSame('POST /post?q=x+y text/plain a=b', $by_name->text());
        $by_array = $session->post(self::$base_url.'/post', ['data' => 'a=b', 'headers' => ['Content-Type' => 'text/plain'], 'params' => ['q' => 'x y']]);
        $this->assertSame($by_name->text(), $by_array->text());
        $positional = $session->post(self::$base_url.'/post', 'a=b', headers: ['Content-Type' => 'text/plain'], params: ['q' => 'x y']);
        $this->assertSame($by_name->text(), $positional->text());

        // a dict is a form, `json` is JSON (json.dumps separators)
        $this->assertSame('POST /post application/x-www-form-urlencoded a=1&b=x+y', $session->post(self::$base_url.'/post', data: ['a' => 1, 'b' => 'x y'])->text());
        $this->assertSame('POST /post application/json {"a": 1, "b": [true, null]}', $session->post(self::$base_url.'/post', json: ['a' => 1, 'b' => [true, null]])->text());
        $this->assertSame('PUT /post  ', $session->put(self::$base_url.'/post')->text());
    }

    public function test_session_headers_are_merged_and_none_removes_one(): void
    {
        $session = new Session();
        $session->headers->update(['X-Session' => '1']);
        $request = $session->prepare_request('GET', self::$base_url.'/', headers: ['x-session' => '2', 'X-Own' => 'yes', 'Accept' => null]);
        $this->assertSame('2', $request->headers['X-Session']);
        $this->assertSame('yes', $request->headers['X-Own']);
        $this->assertArrayNotHasKey('Accept', $request->headers->items());
        $this->assertSame('python-requests/2.32.3', $request->headers['User-Agent']);
    }
}
