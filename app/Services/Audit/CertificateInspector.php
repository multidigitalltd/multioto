<?php

namespace App\Services\Audit;

/**
 * What a browser would make of the site's certificate.
 *
 * Two handshakes, and the pair is the point. One verifies the chain, the name
 * and the validity window exactly as a browser does; the other accepts anything
 * in order to read the certificate that was offered. A certificate that only
 * the second one accepts is precisely the case the report must not call valid —
 * self-signed, issued for another name, from an authority nobody trusts, or not
 * yet in force — and reading the expiry date alone says all of those are fine.
 *
 * Both failing is a different answer again: unreachable is not untrusted, and
 * the report says so rather than accusing anybody.
 */
class CertificateInspector
{
    private const TIMEOUT = 8;

    public function __construct(private PublicTarget $target) {}

    /**
     * @return array{reachable: bool, trusted: bool, days_left: ?int, error: ?string}
     */
    public function inspect(string $host, int $port = 443): array
    {
        $offered = $this->handshake($host, verify: false, port: $port);

        if (! $offered['connected']) {
            return ['reachable' => false, 'trusted' => false, 'days_left' => null, 'error' => $offered['error']];
        }

        $verified = $this->handshake($host, verify: true, port: $port);

        return [
            'reachable' => true,
            'trusted' => $verified['connected'],
            'days_left' => $offered['days_left'],
            'error' => $verified['connected'] ? null : $verified['error'],
        ];
    }

    /**
     * @return array{connected: bool, days_left: ?int, error: ?string}
     */
    protected function handshake(string $host, bool $verify, int $port = 443): array
    {
        // The socket goes to an address the guard approved, while the hostname
        // stays the TLS peer name — so SNI, the name check and the report are all
        // about the site. Looking the name up again here would reopen precisely
        // the hole the fetcher closed: the second answer can point inwards, and
        // this connection would follow it.
        try {
            $address = $this->target->addresses($host)[0] ?? null;
        } catch (\Throwable $e) {
            return ['connected' => false, 'days_left' => null, 'error' => mb_substr($e->getMessage(), 0, 200)];
        }

        if ($address === null) {
            return ['connected' => false, 'days_left' => null, 'error' => 'לא נמצאה כתובת לדומיין.'];
        }

        $context = stream_context_create(['ssl' => [
            'capture_peer_cert' => true,
            'verify_peer' => $verify,
            'verify_peer_name' => $verify,
            'peer_name' => $host,
            'SNI_enabled' => true,
        ]]);

        $client = $this->connect(self::endpoint($address, $port), $context, $error);

        if ($client === false) {
            return ['connected' => false, 'days_left' => null, 'error' => mb_substr((string) $error, 0, 200) ?: null];
        }

        $params = stream_context_get_params($client);
        fclose($client);

        $certificate = openssl_x509_parse($params['options']['ssl']['peer_certificate'] ?? null);
        $expires = $certificate['validTo_time_t'] ?? null;

        return [
            'connected' => true,
            'days_left' => $expires ? (int) ceil(($expires - time()) / 86400) : null,
            'error' => null,
        ];
    }

    /**
     * The one call that touches the network — its own method so a test can watch
     * what is dialled without a certificate, a server or a way out.
     *
     * @return resource|false
     */
    protected function connect(string $endpoint, mixed $context, ?string &$error = null)
    {
        return @stream_socket_client(
            $endpoint,
            $errno,
            $error,
            (float) self::TIMEOUT,
            STREAM_CLIENT_CONNECT,
            $context,
        );
    }

    private static function endpoint(string $address, int $port): string
    {
        $literal = filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false
            ? '['.$address.']'
            : $address;

        return 'ssl://'.$literal.':'.$port;
    }
}
