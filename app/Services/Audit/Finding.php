<?php

namespace App\Services\Audit;

/**
 * One thing the audit found, in the shape the report and the PDF both read.
 *
 * Built through named constructors rather than assembled by hand at each call
 * site, so a check cannot invent a severity the report does not render or a
 * finding with no instruction attached to it. Every problem carries what to do
 * about it: a list of faults with no remedies is a complaint, not a report.
 */
class Finding
{
    private function __construct(
        public readonly string $severity,
        public readonly string $area,
        public readonly string $title,
        public readonly string $detail,
        public readonly ?string $fix,
        public readonly ?string $evidence,
    ) {}

    /** Costing the business now: broken, unreachable, unsafe. */
    public static function critical(string $area, string $title, string $detail, string $fix, ?string $evidence = null): self
    {
        return new self('critical', $area, $title, $detail, $fix, $evidence);
    }

    /** Working, but it will cost something: slow, exposed, missing. */
    public static function warning(string $area, string $title, string $detail, string $fix, ?string $evidence = null): self
    {
        return new self('warning', $area, $title, $detail, $fix, $evidence);
    }

    /** Worth doing, not urgent. */
    public static function notice(string $area, string $title, string $detail, string $fix, ?string $evidence = null): self
    {
        return new self('notice', $area, $title, $detail, $fix, $evidence);
    }

    /**
     * Checked and fine.
     *
     * Kept, and shown, because a report of only faults reads as an accusation —
     * and because "we checked this and it is in order" is itself worth saying to
     * somebody deciding whether to trust you with the site.
     */
    public static function ok(string $area, string $title, ?string $detail = null): self
    {
        return new self('ok', $area, $title, (string) $detail, null, null);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_filter([
            'severity' => $this->severity,
            'area' => $this->area,
            'title' => $this->title,
            'detail' => $this->detail,
            'fix' => $this->fix,
            'evidence' => $this->evidence,
        ], fn (?string $value): bool => $value !== null && $value !== '');
    }
}
