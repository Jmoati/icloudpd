<?php

namespace PyicloudIpd;

use Dom\HTMLDocument;
use Foundation\Http\Response;
use PyicloudIpd\Base\TrustedPhoneContextProvider;
use PyicloudIpd\Sms\AuthenticatedSession;
use PyicloudIpd\Sms\InternalRequest;
use PyicloudIpd\Sms\TrustedDevice;

/**
 * Module `pyicloud_ipd/sms.py`: the requests and the parsing for 2FA by SMS.
 *
 * ponytail: the ``Protocol`` classes (``TrustedDevice``, ``Request``, ``_Response``...) are not ported: the concrete
 * classes ``Sms\TrustedDevice``, ``Sms\InternalRequest`` stand for them; ``Response`` is the response.
 */
final class Sms
{
    /**
     * The JSON of the last ``<script type="application/json" class="boot_args">`` of the page (``_SMSParser``).
     *
     * @return array<string, mixed>
     */
    private static function _boot_args(string $content): array
    {
        $sms_data = [];
        $document = HTMLDocument::createFromString($content, LIBXML_NOERROR, 'UTF-8');
        foreach ($document->getElementsByTagName('script') as $script) {
            if ($script->getAttribute('type') === 'application/json' && $script->getAttribute('class') === 'boot_args') {
                $sms_data = json_decode($script->textContent, true, 512, JSON_THROW_ON_ERROR);
            }
        }

        return $sms_data;
    }

    /** @return ?TrustedDevice */
    public static function _map_to_trusted_device(array $device): ?TrustedDevice
    {
        $id = $device['id'] ?? null;
        $number = $device['obfuscatedNumber'] ?? null;
        if ($id === null || $number === null) {
            return null;
        }

        return new TrustedDevice(id: $id, obfuscated_number: str_replace('•', '*', $number));
    }

    /**
     * Parses html response for the list of available trusted phone numbers
     *
     * @return list<TrustedDevice>
     */
    public static function parse_trusted_phone_numbers_response(Response $response): array
    {
        if (in_array($response->status_code, [200, 204], true)) {
            return self::parse_trusted_phone_numbers_payload($response->text());
        }

        return [];
    }

    /**
     * Parses html response for the list of available trusted phone numbers
     *
     * @return list<TrustedDevice>
     */
    public static function parse_trusted_phone_numbers_payload(string $content): array
    {
        $sms_data = self::_boot_args($content);
        $twoSV = $sms_data['direct']['twoSV'] ?? [];
        $numbers = $twoSV['phoneNumberVerification']['trustedPhoneNumbers'] ?? [];
        if (!$numbers) {
            // Apple moved trustedPhoneNumbers into bridgeInitiateData.phoneNumberVerification (2026+)
            $numbers = $twoSV['bridgeInitiateData']['phoneNumberVerification']['trustedPhoneNumbers'] ?? [];
        }

        return array_values(array_filter(array_map(self::_map_to_trusted_device(...), $numbers), static fn ($item) => $item !== null));
    }

    /** @return array<string, string> */
    public static function _oauth_const_headers(): array
    {
        return [
            'X-Apple-OAuth-Client-Id' => 'd39ba9916b7251055b22c7f910e2ea796ee65e98b2ddecea8f5dde8d9d1a815d',
            'X-Apple-OAuth-Client-Type' => 'firstPartyAuth',
            'X-Apple-OAuth-Require-Grant-Code' => 'true',
            'X-Apple-Widget-Key' => 'd39ba9916b7251055b22c7f910e2ea796ee65e98b2ddecea8f5dde8d9d1a815d',
        ];
    }

    /** @return array<string, string> */
    public static function _oauth_redirect_header(string $domain): array
    {
        return [
            'X-Apple-OAuth-Redirect-URI' => $domain === 'cn'
                ? 'https://www.icloud.com.cn'
                : 'https://www.icloud.com',
        ];
    }

    /**
     * Headers with OAuth session
     *
     * @return array<string, string>
     */
    public static function _oauth_headers(AuthenticatedSession $auth_session): array
    {
        return [
            'X-Apple-OAuth-State' => $auth_session->client_id,
            'scnt' => $auth_session->scnt,
            'X-Apple-ID-Session-Id' => $auth_session->session_id,
        ];
    }

    public static function _auth_url(string $domain): string
    {
        return $domain === 'cn'
            ? 'https://idmsa.apple.com.cn/appleauth/auth'
            : 'https://idmsa.apple.com/appleauth/auth';
    }

    /** Builds a request for the list of trusted phone numbers for sms 2fa */
    public static function build_trusted_phone_numbers_request(TrustedPhoneContextProvider $context): InternalRequest
    {
        $url = self::_auth_url($context->domain);

        return new InternalRequest(
            method: 'GET',
            url: $url,
            headers: [
                ...self::_oauth_const_headers(),
                ...self::_oauth_redirect_header($context->domain),
                ...self::_oauth_headers($context->oauth_session),
            ],
        );
    }

    /** Builds a request for the list of trusted phone numbers for sms 2fa */
    public static function build_send_sms_code_request(TrustedPhoneContextProvider $context, int $device_id): InternalRequest
    {
        $url = self::_auth_url($context->domain).'/verify/phone';

        $json = ['phoneNumber' => ['id' => $device_id], 'mode' => 'sms'];

        return new InternalRequest(
            method: 'PUT',
            url: $url,
            headers: [
                ...self::_oauth_const_headers(),
                ...self::_oauth_redirect_header($context->domain),
                ...self::_oauth_headers($context->oauth_session),
                ...['Content-type' => 'application/json; charset=utf-8'],
            ],
            json: $json,
        );
    }

    /** Builds a request for the list of trusted phone numbers for sms 2fa */
    public static function build_verify_sms_code_request(TrustedPhoneContextProvider $context, int $device_id, string $code): InternalRequest
    {
        $url = self::_auth_url($context->domain).'/verify/phone/securitycode';

        $json = ['phoneNumber' => ['id' => $device_id], 'securityCode' => ['code' => $code], 'mode' => 'sms'];

        return new InternalRequest(
            method: 'POST',
            url: $url,
            headers: [
                ...self::_oauth_const_headers(),
                ...self::_oauth_redirect_header($context->domain),
                ...self::_oauth_headers($context->oauth_session),
                ...['Content-type' => 'application/json; charset=utf-8'],
                ...['Accept' => 'application/json; charset=utf-8'],
            ],
            json: $json,
        );
    }
}
