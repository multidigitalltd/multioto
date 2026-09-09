<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * How a subscription gets paid.
 *
 * The distinction that matters everywhere else in the system is `isManual()`:
 * a card the scheduler charges by itself, versus money a person has to go and
 * collect. That single question decides whether a subscription belongs on the
 * automatic run, on the "גבייה ידנית" work list, or in the "nobody is going to
 * collect this" net — so it is answered here once rather than by an array
 * literal in each of them.
 */
enum PaymentMethod: string implements HasLabel
{
    case CreditCard = 'credit_card';
    case StandingOrder = 'standing_order';
    case BankTransfer = 'bank_transfer';
    case Checks = 'checks';

    public function getLabel(): string
    {
        return match ($this) {
            self::CreditCard => 'כרטיס אשראי',
            self::StandingOrder => 'הוראת קבע בנקאית',
            self::BankTransfer => 'העברה בנקאית',
            self::Checks => 'צ׳קים',
        };
    }

    /** Collected by a person, not by the scheduler. */
    public function isManual(): bool
    {
        return $this !== self::CreditCard;
    }

    /**
     * The manual methods, as the raw strings the columns hold — for query
     * scopes, which cannot call a method per row.
     *
     * @return list<string>
     */
    public static function manualValues(): array
    {
        return array_values(array_map(
            static fn (self $method): string => $method->value,
            array_filter(self::cases(), static fn (self $method): bool => $method->isManual()),
        ));
    }

    /**
     * Whether a raw stored value means "collect this by hand".
     *
     * A blank or unrecognised value is NOT manual: the default arrangement is a
     * card, and reading an empty column as "somebody will collect it" would
     * quietly move a subscription off the automatic run and onto a work list
     * nobody was told to watch.
     */
    public static function isManualValue(?string $value): bool
    {
        return $value !== null && in_array($value, self::manualValues(), true);
    }

    /** @return array<string, string> value => Hebrew label, for a Filament select. */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $method) {
            $options[$method->value] = $method->getLabel();
        }

        return $options;
    }
}
