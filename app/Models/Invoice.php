<?php

namespace App\Models;

use App\Enums\DocumentType;
use App\Enums\VatCategory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Invoice extends Model
{
    use HasFactory;

    protected $fillable = [
        'charge_id', 'customer_id', 'linet_document_id', 'document_type',
        'allocation_number', 'vat_category', 'amount_agorot', 'vat_agorot',
        'total_agorot', 'pdf_url', 'issued_at',
    ];

    protected function casts(): array
    {
        return [
            'document_type' => DocumentType::class,
            'vat_category' => VatCategory::class,
            'amount_agorot' => 'integer',
            'vat_agorot' => 'integer',
            'total_agorot' => 'integer',
            'issued_at' => 'datetime',
        ];
    }

    public function charge(): BelongsTo
    {
        return $this->belongsTo(Charge::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
