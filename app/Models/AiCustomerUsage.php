<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * AI tokens spent on one customer, per day and model. Written alongside the
 * daily total whenever an AI call runs on a customer's behalf (see
 * AiUsageAttribution); priced at read time like AiUsage.
 */
class AiCustomerUsage extends Model
{
    protected $table = 'ai_usage_customers';

    protected $fillable = ['date', 'customer_id', 'provider', 'model', 'input_tokens', 'output_tokens', 'requests'];

    // `date` stays a plain 'Y-m-d' string, as on AiUsage: a cast would break
    // firstOrCreate() on the unique key.

    /** Best-effort, like AiUsage::record — accounting never breaks an AI call. */
    public static function record(int $customerId, string $provider, string $model, int $inputTokens, int $outputTokens): void
    {
        try {
            $row = static::query()->firstOrCreate([
                'date' => now()->toDateString(),
                'customer_id' => $customerId,
                'provider' => $provider,
                'model' => $model ?: 'unknown',
            ]);

            $row->increment('requests');

            if ($inputTokens > 0) {
                $row->increment('input_tokens', $inputTokens);
            }

            if ($outputTokens > 0) {
                $row->increment('output_tokens', $outputTokens);
            }
        } catch (\Throwable) {
            // Deliberately ignored.
        }
    }

    /** USD cost of this row, from its model's price. */
    public function costUsd(): float
    {
        [$in, $out] = AiUsage::priceFor((string) $this->model);

        return ($this->input_tokens / 1_000_000) * $in + ($this->output_tokens / 1_000_000) * $out;
    }
}
