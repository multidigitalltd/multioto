<?php

namespace App\Services\Mail;

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

/**
 * Make "track this message's opens" actually reach Postmark.
 *
 * `X-PM-TrackOpens` is an SMTP instruction. Over Postmark's HTTP API — which is
 * how Laravel sends — the field is `TrackOpens` in the request body, and the
 * transport has no idea our header means anything: it copies it into the
 * message's headers like any other, where Postmark reads it as ordinary text.
 * The mail goes out looking tracked and is not.
 *
 * That leaves open tracking depending entirely on a checkbox inside the Postmark
 * account, which nobody can see from here — so "no opens" and "opens turned off
 * somewhere we cannot look" become the same silence. This decorator closes that:
 * a message that asked for tracking gets `TrackOpens` in the body, and one that
 * did not is untouched. Transactional mail — invoices, ticket replies — never
 * carries the header and is never tracked.
 *
 * It sits on the HTTP client rather than on the transport because the transport
 * builds its payload in a private method: subclassing would mean copying that
 * whole method and inheriting every future change to it silently.
 */
class PostmarkTracking implements HttpClientInterface
{
    /** The SMTP-style request the mailable makes. */
    public const HEADER = 'X-PM-TrackOpens';

    public function __construct(private HttpClientInterface $inner) {}

    /** The decorated client Laravel should hand to the Postmark transport. */
    public static function client(array $options = []): HttpClientInterface
    {
        return new self(HttpClient::create($options));
    }

    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        return $this->inner->request($method, $url, $this->withTracking($url, $options));
    }

    /**
     * Turn the header into the field, for the send call only.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function withTracking(string $url, array $options): array
    {
        $payload = $options['json'] ?? null;

        if (! is_array($payload) || ! str_contains($url, '/email')) {
            return $options;
        }

        foreach ((array) ($payload['Headers'] ?? []) as $header) {
            if (strcasecmp((string) ($header['Name'] ?? ''), self::HEADER) !== 0) {
                continue;
            }

            // Whatever the mailable asked for, including an explicit "no" — the
            // header is the message's own instruction and this only carries it.
            $options['json']['TrackOpens'] = filter_var(
                (string) ($header['Value'] ?? ''), FILTER_VALIDATE_BOOL
            );

            break;
        }

        return $options;
    }

    public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface
    {
        return $this->inner->stream($responses, $timeout);
    }

    public function withOptions(array $options): static
    {
        return new self($this->inner->withOptions($options));
    }
}
