<?php

namespace App\Http\Controllers\Webhooks;

use App\Enums\WebhookSource;
use App\Http\Controllers\Controller;
use App\Jobs\CheckSiteAgentChannelJob;
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
        //
        // Deliberately NOT recorded as a rejection: this is a public GET that
        // any scanner reaches, and the rejection counter drives an alert whose
        // one stated cause is a mismatched app secret. A passing crawler must
        // not be able to tell the team their secret is wrong. A real handshake
        // failure is never silent either way — Meta says so on the spot, in the
        // dialog where the admin just clicked Verify.
        if ($token === '' || ! hash_equals($token, $provided)) {
            abort(403);
        }

        return response((string) $request->query('hub_challenge', ''), 200);
    }

    public function receive(Request $request, WhatsAppCloudClient $client): Response
    {
        // Verified against the RAW body: re-encoding a decoded payload changes
        // key order and spacing, and the signature would never match again.
        $signature = $request->header('X-Hub-Signature-256');

        if (! $client->signatureIsValid($request->getContent(), $signature)) {
            // Recorded only when the request CLAIMS a signature we could not
            // verify — the one rejection whose cause is unambiguous, and the
            // only one that means Meta knocked. A POST with no signature header
            // was never a delivery attempt (Meta signs every one of them), and
            // counting it would let any scanner tell the team their app secret
            // is wrong and send them to re-paste a field that is perfectly fine.
            if ($client->carriesSignature($signature)) {
                WebhookRejections::record(CheckSiteAgentChannelJob::CHANNEL);
            }

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
        $ours = trim((string) config('siteagent.whatsapp.phone_number_id'));

        foreach ((array) data_get($payload, 'entry', []) as $entry) {
            foreach ((array) data_get($entry, 'changes', []) as $change) {
                $value = (array) data_get($change, 'value', []);

                if (! $this->addressedToOurNumber($value, $ours)) {
                    continue;
                }

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

    /**
     * Was this delivery addressed to the agent's number, or to a sibling?
     *
     * Meta subscribes webhooks **per WhatsApp Business Account, not per number**.
     * A business whose account holds several numbers — a support line, a sales
     * line, this product's line — therefore gets every message for all of them
     * delivered to the same endpoint. Without this check a customer writing to
     * the support number would be answered by the site agent, and worse, an
     * instruction typed there would be carried out against a site.
     *
     * The rule is deliberately asymmetric:
     *
     *  - A payload naming a DIFFERENT number is refused. That is the sibling
     *    case, and it is the one that actually happens.
     *  - A payload naming no number at all is accepted. Meta always sends
     *    `metadata.phone_number_id`, so its absence means the envelope shape
     *    changed — and failing closed on that would silently drop every
     *    customer message until somebody noticed, which is a far worse outcome
     *    than the case this guard exists for. The delivery is already proven to
     *    be Meta's by its signature before it reaches here.
     *
     * @param  array<string, mixed>  $value
     */
    private function addressedToOurNumber(array $value, string $ours): bool
    {
        $addressed = trim((string) data_get($value, 'metadata.phone_number_id', ''));

        return $ours === '' || $addressed === '' || $addressed === $ours;
    }
}
