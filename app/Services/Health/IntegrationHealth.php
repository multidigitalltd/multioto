<?php

namespace App\Services\Health;

use App\Services\Cardcom\CardcomClient;
use App\Services\Linet\LinetClient;
use App\Services\Waha\WahaClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Runs the "test connection" checks for each external integration and returns a
 * uniform ConnectionResult. Each check is an explicit, admin-triggered action,
 * so a short synchronous HTTP call is acceptable here.
 */
class IntegrationHealth
{
    public function __construct(
        private CardcomClient $cardcom,
        private LinetClient $linet,
        private WahaClient $waha,
    ) {}

    /**
     * The integrations that can be tested, keyed by a stable slug used by the
     * UI. label/description are Hebrew for display.
     *
     * @return array<string, array{label: string, description: string}>
     */
    public function integrations(): array
    {
        return [
            'cardcom' => ['label' => 'קארדקום — סליקה', 'description' => 'מסוף וסליקת אשראי'],
            'linet' => ['label' => 'לינט — חשבוניות', 'description' => 'הנפקת חשבוניות מס/קבלה'],
            'waha' => ['label' => 'WAHA — וואטסאפ', 'description' => 'שליחה וקבלה של הודעות'],
            'email' => ['label' => 'Postmark — מייל', 'description' => 'מייל יוצא ונכנס'],
        ];
    }

    public function check(string $key): ConnectionResult
    {
        return match ($key) {
            'cardcom' => $this->cardcom->testConnection(),
            'linet' => $this->linet->testConnection(),
            'waha' => $this->waha->testConnection(),
            'email' => $this->checkEmail(),
            default => ConnectionResult::fail('אינטגרציה לא מוכרת'),
        };
    }

    /**
     * Postmark exposes a clean, side-effect-free auth check: GET /server with
     * the server token returns 200 when the token is valid.
     */
    private function checkEmail(): ConnectionResult
    {
        $token = config('services.postmark.token');

        if (blank($token)) {
            return ConnectionResult::notConfigured('Server Token של Postmark לא הוגדר');
        }

        try {
            $response = Http::withHeaders([
                'X-Postmark-Server-Token' => $token,
                'Accept' => 'application/json',
            ])->timeout(10)->get('https://api.postmarkapp.com/server');

            if ($response->successful()) {
                $name = $response->json('Name');

                return ConnectionResult::ok($name ? "מחובר לשרת Postmark: {$name}" : 'מחובר ל-Postmark');
            }

            if ($response->status() === 401) {
                return ConnectionResult::fail('Postmark דחה את ה-Server Token');
            }

            return ConnectionResult::fail('Postmark החזיר קוד '.$response->status());
        } catch (\Throwable $e) {
            return ConnectionResult::fail('לא ניתן להתחבר ל-Postmark: '.Str::limit(trim($e->getMessage()) ?: class_basename($e), 120));
        }
    }
}
