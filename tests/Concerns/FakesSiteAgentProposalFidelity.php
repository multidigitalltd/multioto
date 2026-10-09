<?php

namespace Tests\Concerns;

use App\Services\SiteAgent\SiteAgentProposalFidelity;
use Mockery\MockInterface;

/**
 * Isolate the independent AI review in existing transport and domain tests.
 * Call explicitly from each test class; real fidelity decisions and integration
 * are exercised separately by the proposal fidelity tests, without this trait.
 * Proposal validation, saved approval, execution and restore remain real.
 */
trait FakesSiteAgentProposalFidelity
{
    protected function fakeProposalFidelity(): MockInterface
    {
        return $this->mock(SiteAgentProposalFidelity::class, function (MockInterface $mock): void {
            $mock->shouldReceive('review')->andReturn([
                'verdict' => 'allow', 'reason' => 'matched', 'feedback' => '', 'reply' => '',
            ]);
        });
    }
}
