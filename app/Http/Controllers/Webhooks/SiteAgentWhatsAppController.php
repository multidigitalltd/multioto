<?php

namespace App\Http\Controllers\Webhooks;

use App\Enums\WebhookSource;
use App\Http\Controllers\Controller;
use App\Jobs\HandleSiteAgentMessageJob;
use App\Models\WebhookEvent;
use App\Services\SiteAgent\WhatsAppCloudClient;
use App\Support\WebhookRejections;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The product's own WhatsApp number, on Meta's Cloud API.
 *
 * Two jobs, and nothing else: prove the delivery is really Meta's, and record
 * it. Everything a message MEANS is decided on the queue — Meta retries a
 * delivery it does not get a fast 200 for, and a slow answer here becomes the
 * same instruction arriving twice.
 */
class SiteAgentWhatsAppController extends Controller
{
    /**
     * Meta's one-time handshake: it calls with a challenge and the token we
     * configured, and echoes nothing unless both match.
     */
    public function verify(Request $request): Response
    {
        $token = (string) config('siteagent.whatsapp.verify_token');
        $provided = (string) $request->query('hub_verify_token', '');

        // A blank configured token must never mean "anybody may subscribe".
        if ($token === '' || ! hash_equals($token, $provided)) {
            WebhookRejections::record('site-agent-whatsapp');

            abort(403);
        }

        return response((string) $request->query('hub_challenge', ''), 200);
    }

    public function receive(Request $request, WhatsAppCloudClient $client): Response
    {
        // Verified against the RAW body: re-encoding a decoded payload changes
        // key order and spacing, and the signature would never match again.
        if (! $client->signatureIsValid($request->getContent(), $request->header('X-Hub-Signature-256'))) {
            WebhookRejections::record('site-agent-whatsapp');

            abort(403);
        }

        foreach ($this->messages($request->json()->all()) as $message) {
            $id = (string) ($message['id'] ?? '');

            if ($id === '') {
                continue;
            }

            [$event, $fresh] = WebhookEvent::record(
                WebhookSource::WhatsappCloud,
                'message',
                $id,
                $message,
            );

            // Meta redelivers anything it did not get a 200 for, and a customer
            // whose "תעדכן את המחיר ל-90" ran twice is a customer whose price
            // moved twice. The provider's message id is the identity.
            if ($fresh) {
                HandleSiteAgentMessageJob::dispatch($event->id);
            }
        }

        return response('OK', 200);
    }

    /**
     * The inbound messages inside Meta's envelope.
     *
     * The shape is entry[].changes[].value.messages[], and the same envelope
     * also carries delivery receipts and read markers with no `messages` key at
     * all. Walked defensively: a payload shaped differently than expected is
     * skipped rather than throwing, because throwing here means Meta retries
     * the same delivery forever.
     *
     * @param  array<string, mixed>  $payload
     * @return list<array<string, mixed>>
     */
    private function messages(array $payload): array
    {
        $found = [];

        foreach ((array) data_get($payload, 'entry', []) as $entry) {
            foreach ((array) data_get($entry, 'changes', []) as $change) {
                $value = (array) data_get($change, 'value', []);
                $contact = (array) data_get($value, 'contacts.0', []);

                foreach ((array) data_get($value, 'messages', []) as $message) {
                    if (! is_array($message)) {
                        continue;
                    }

                    // The sender's display name travels beside the messages,
                    // not inside them; carried along so the agent can greet a
                    // person by name without a second API call.
                    $message['_profile_name'] = (string) data_get($contact, 'profile.name', '');
                    $found[] = $message;
                }
            }
        }

        return $found;
    }
}
