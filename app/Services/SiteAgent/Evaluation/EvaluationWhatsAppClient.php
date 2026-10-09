<?php

namespace App\Services\SiteAgent\Evaluation;

use App\Services\SiteAgent\WhatsAppCloudClient;
use LogicException;

/** Synthetic image input only; no real WhatsApp receipt, download or send. */
class EvaluationWhatsAppClient extends WhatsAppCloudClient
{
    public function configured(): bool
    {
        return true;
    }

    public function downloadMedia(string $mediaId): ?array
    {
        if (trim($mediaId) === '') {
            return null;
        }

        return ['bytes' => base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true), 'extension' => 'png', 'mime' => 'image/png'];
    }

    public function displayPhoneNumber(): ?string
    {
        return null;
    }

    public function pricingAnalytics(\DateTimeInterface $start, \DateTimeInterface $end): ?array
    {
        throw new LogicException('Meta network access is forbidden during evaluation.');
    }

    public function sendText(string $to, string $body): ?string
    {
        throw new LogicException('WhatsApp sends are forbidden during evaluation.');
    }

    public function sendConfirmation(string $to, string $body, ?int $requestId = null): ?string
    {
        throw new LogicException('WhatsApp sends are forbidden during evaluation.');
    }

    public function sendTemplate(string $to, string $name, array $parameters = [], ?string $copyCode = null): ?string
    {
        throw new LogicException('WhatsApp sends are forbidden during evaluation.');
    }
}
