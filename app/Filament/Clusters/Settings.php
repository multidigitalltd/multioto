<?php

namespace App\Filament\Clusters;

use Filament\Clusters\Cluster;
use Filament\Pages\SubNavigationPosition;

/**
 * One "הגדרות" entry in the sidebar instead of seven. Every settings screen —
 * mail, integrations, the AI agent, the signup form, templates, team members
 * and system/updates — lives inside this cluster, so the main menu stays short
 * and the settings appear as internal tabs once you're in the section.
 *
 * The cluster itself isn't access-gated: each child keeps its own canAccess
 * (most are admin-only via the AdminOnly concern; message templates stay open
 * to agents), so the sub-navigation only ever shows what the viewer may open.
 */
class Settings extends Cluster
{
    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static SubNavigationPosition $subNavigationPosition = SubNavigationPosition::Top;

    protected static ?string $navigationLabel = 'הגדרות';

    protected static ?string $title = 'הגדרות';

    protected static ?string $clusterBreadcrumb = 'הגדרות';

    // Sit inside the last nav group (ניהול) with a high sort, so "הגדרות" is the
    // very last item in the sidebar rather than a stray entry near the top.
    protected static ?string $navigationGroup = 'ניהול';

    protected static ?int $navigationSort = 99;
}
