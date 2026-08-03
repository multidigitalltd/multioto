<?php

namespace App\Services\Audit\Checks;

/**
 * A check whose answer comes from the page itself.
 *
 * Marked so the auditor can stand them down when what came back is a firewall's
 * block page rather than the site. A block page has no title, no H1, no alt
 * text and no accessibility statement — and a report that lists all of that as
 * faults is describing the firewall while blaming the site.
 *
 * Standing them down is not the same as staying quiet about them: each one gets
 * a finding saying it could not be run, because a section missing from a report
 * reads exactly like a section that was fine.
 */
interface ReadsPage {}
