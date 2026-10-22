<?php

namespace PyicloudIpd\Session;

use Foundation\Http;
use Foundation\Http\JSONDecodeError;
use Foundation\Http\Response;
use Foundation\Http\Session;
use Foundation\Logging;
use Foundation\Py;
use PyicloudIpd\Exceptions\PyiCloud2SARequiredException;
use PyicloudIpd\Exceptions\PyiCloudAPIResponseException;
use PyicloudIpd\Exceptions\PyiCloudServiceNotActivatedException;
use PyicloudIpd\Session as SessionModule;
use PyicloudIpd\Utils;

/** iCloud session. */
class PyiCloudSession extends Session
{
    public mixed $service;
    /** @var ?callable(array<string, mixed>): void */
    public $response_observer;

    /**
     * @param mixed $service the ``PyiCloudService`` (anything with ``password_filter``, ``http_timeout``,
     *        ``session_data``, ``session_path()``, ``cookiejar_path()``, ``requires_2sa()``)
     * @param ?callable(array<string, mixed>): void $response_observer
     */
    public function __construct(mixed $service, ?callable $response_observer = null)
    {
        $this->service = $service;
        $this->response_observer = $response_observer;
        parent::__construct();
    }

    public function observe(Response $response): Response
    {
        if ($this->response_observer) {
            ($this->response_observer)(Http::response_to_har_entry($response));
        }

        return $response;
    }

    public function request(string $method, string $url, mixed ...$kwargs): Response
    {
        $kwargs = self::kwargs($kwargs);
        // Charge logging to the right service endpoint
        // (frame 0 is this method, 1 the get/post/... of the session, 2 the caller)
        $callee = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3)[2] ?? [];
        $request_logger = Logging::getLogger(SessionModule::module_name($callee['class'] ?? $callee['function'] ?? '__main__'))->getChild('http');
        if (
            $this->service->password_filter
            && !in_array($this->service->password_filter, $request_logger->filters, true)
        ) {
            $request_logger->addFilter($this->service->password_filter);
        }

        $request_logger->debug('%s %s %s', $method, $url, $kwargs['data'] ?? '');

        if (!array_key_exists('timeout', $kwargs) && $this->service->http_timeout !== null) {
            $kwargs['timeout'] = $this->service->http_timeout;
        }
        $response = Utils::throw_on_503(
            $this->observe(Utils::handle_connection_error(fn (mixed ...$args) => parent::request(...$args))($method, $url, ...$kwargs)),
        );

        $content_type = explode(';', $response->headers->get('Content-Type', ''))[0];
        $json_mimetypes = ['application/json', 'text/json'];

        $request_logger->debug($response->headers->items());

        foreach (SessionModule::HEADER_DATA as $header => $value) {
            if (Py::truthy($response->headers->get($header))) {
                $session_arg = $value;
                $this->service->session_data[$session_arg] = $response->headers->get($header);
            }
        }

        // Save session_data to file
        // an empty dict is `{}`, not `[]`
        file_put_contents($this->service->session_path(), Py::json_dumps($this->service->session_data ?: new \stdClass()));
        SessionModule::LOGGER()->debug('Saved session data to file');

        // Save cookies to file
        $this->cookies->save(ignore_discard: true, ignore_expires: true);
        SessionModule::LOGGER()->debug('Cookies saved to %s', $this->service->cookiejar_path());

        if (!$response->ok() && (
            !in_array($content_type, $json_mimetypes, true) || in_array($response->status_code, [421, 450, 500], true)
        )) {
            $this->_raise_error((string) $response->status_code, $response->reason);
        }

        if (!in_array($content_type, $json_mimetypes, true)) {
            if (($this->service->session_data['apple_rscd'] ?? null) === '401') {
                $code = '401';
                $reason = 'Invalid username/password combination.';
                $this->_raise_error($code ?: 'Unknown', $reason ?: 'Unknown');
            }

            return $response;
        }

        try {
            $data = $response->status_code !== 204 ? $response->json() : [];
        } catch (\ValueError) {
            $request_logger->warning('Failed to parse response with JSON mimetype');

            return $response;
        }

        $request_logger->debug($data);

        // a JSON object is a PHP array (an empty one is `[]`, like an empty list): a dict has string keys
        if (is_array($data) && !array_is_list($data)) {
            if (Py::truthy($data['hasError'] ?? null)) {
                // service_errors returns a list of dict
                //    dict includes the keys: code, title, message, supressDismissal
                // Assuming a single error for now
                // May need to revisit to capture and handle multiple errors
                $errors = $data['service_errors'] ?? null;
                // ponytail: Python raises UnboundLocalError when there are no errors; these are then null
                $code = null;
                $reason = null;
                if ($errors) {
                    $code = $errors[0]['code'] ?? null;
                    $reason = $errors[0]['message'] ?? null;
                }
                $this->_raise_error(Py::truthy($code) ? $code : 'Unknown', Py::truthy($reason) ? $reason : 'Unknown');
            } elseif (!Py::truthy($data['success'] ?? null)) {
                $reason = $data['errorMessage'] ?? null;
                $reason = Py::truthy($reason) ? $reason : ($data['reason'] ?? null);
                $reason = Py::truthy($reason) ? $reason : ($data['errorReason'] ?? null);
                if (!Py::truthy($reason) && is_string($data['error'] ?? null)) {
                    $reason = $data['error'];
                }
                if (!Py::truthy($reason) && Py::truthy($data['error'] ?? null)) {
                    $reason = 'Unknown reason';
                }

                $code = $data['errorCode'] ?? null;
                if (!Py::truthy($code) && Py::truthy($data['serverErrorCode'] ?? null)) {
                    $code = $data['serverErrorCode'];
                }
                if (!Py::truthy($code) && Py::truthy($data['error'] ?? null)) {
                    $code = $data['error'];
                }

                if (Py::truthy($reason)) {
                    $this->_raise_error(Py::truthy($code) ? $code : 'Unknown', $reason);
                }
            }
        }

        return $response;
    }

    public function _raise_error(string $code, string $reason): never
    {
        if ($this->service->requires_2sa() && $reason === 'Missing X-APPLE-WEBAUTH-TOKEN cookie') {
            throw new PyiCloud2SARequiredException($this->service->user['accountName']);
        }
        if (in_array($code, ['ZONE_NOT_FOUND', 'AUTHENTICATION_FAILED'], true)) {
            $reason = 'Apple iCloud setup is not complete. Please log into https://icloud.com/ to manually '
                .'finish setting up your iCloud service';
            $api_error = new PyiCloudServiceNotActivatedException($reason, $code);
            SessionModule::LOGGER()->error($api_error);

            throw $api_error;
        }
        if ($code === 'ACCESS_DENIED') {
            $reason = $reason.'.  Please wait a few minutes then try again.'
                .'The remote servers might be trying to throttle requests.';
        }
        if (in_array($code, ['421', '450', '500'], true)) {
            $reason = 'Authentication required for Account.';
        }

        $api_error = new PyiCloudAPIResponseException($reason, $code);
        SessionModule::LOGGER()->error($api_error);
        throw $api_error;
    }
}
