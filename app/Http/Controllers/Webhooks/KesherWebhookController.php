<?php

namespace App\Http\Controllers\Webhooks;

use App\Enums\WebhookSource;
use App\Http\Controllers\Controller;
use App\Models\WebhookEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Receives Kesher (קשר) push notifications about standing-order collections.
 *
 * **This endpoint records and does nothing else — on purpose.**
 *
 * Kesher's own documentation describes the payloads only in outline, and the
 * whole API is case sensitive: a field name guessed one letter wrong is not an
 * error, it is a value that silently never arrives. Writing the collection
 * logic against guessed names would produce the exact failure this system keeps
 * being built to prevent — money code that reports success and did nothing.
 *
 * So the endpoint goes live first and listens. Every delivery lands in
 * `webhook_events` exactly as sent, `kesher:payloads` prints them, and the real
 * notifications become the specification the processing is written against.
 * Nothing is charged, invoiced or advanced until that is written.
 */
class KesherWebhookController extends Controller
{
    use VerifiesWebhookSecret;

    public function __invoke(Request $request): Response
    {
        // Fail closed. Kesher does not sign its callbacks, so this shared secret
        // is the only thing standing between the endpoint and anybody who
        // learns the address — which is also why processing, when it is
        // written, will verify each notification against Kesher's own API
        // rather than trusting the body of the request.
        $this->abortUnlessSecretMatches($request, (string) config('billing.kesher.webhook_secret'), 'kesher');

        $payload = $request->except('secret');

        [, $fresh] = WebhookEvent::record(
            WebhookSource::Kesher,
            $this->eventType($payload),
            $this->externalId($payload),
            $payload,
        );

        // 200 either way: a duplicate delivery is not the sender's problem to
        // solve, and an error would only make Kesher retry something we already
        // hold.
        return response($fresh ? 'OK' : 'DUPLICATE', 200);
    }

    /**
     * Which kind of notification this is, from the object Kesher wrapped it in.
     *
     * @param  array<string, mixed>  $payload
     */
    private function eventType(array $payload): string
    {
        return match (true) {
            isset($payload['CrmTranObject']) => 'transaction',
            isset($payload['obligation_obj']) => 'obligation',
            default => 'unknown',
        };
    }

    /**
     * A stable identity for this delivery.
     *
     * Kesher's documented payloads carry no explicit delivery id, and until the
     * real ones prove otherwise a hash of the body is the honest answer: an
     * identical redelivery collapses onto the same row, while anything that
     * differs by even one field is treated as a separate event. That is the
     * right way round — recording the same collection twice is a duplicate
     * charge, and dropping a genuine second one is money that never appears.
     *
     * @param  array<string, mixed>  $payload
     */
    private function externalId(array $payload): string
    {
        foreach ([
            ['CrmTranObject', 'TranId'],
            ['CrmTranObject', 'Id'],
            ['obligation_obj', 'ObligationReference'],
        ] as [$object, $key]) {
            $value = trim((string) data_get($payload, "{$object}.{$key}", ''));

            if ($value !== '') {
                // Prefixed with the object, so a transaction id and an
                // obligation reference that happen to share a number stay two
                // different events.
                return mb_strtolower($object).':'.$value;
            }
        }

        return 'sha:'.hash('sha256', (string) json_encode($payload));
    }
}
