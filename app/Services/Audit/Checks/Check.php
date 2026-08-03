<?php

namespace App\Services\Audit\Checks;

use App\Services\Audit\AuditContext;
use App\Services\Audit\Finding;

/**
 * One family of questions asked of a site.
 *
 * Each check answers only about its own area and never stops the audit: a check
 * that throws is reported as a check that could not be completed, because a
 * report which silently omits a question is indistinguishable from one where
 * the answer was fine.
 */
interface Check
{
    /** @return list<Finding> */
    public function run(AuditContext $site): array;

    /** The heading this check's findings appear under. */
    public function area(): string;
}
