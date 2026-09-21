<?php

declare(strict_types=1);

namespace Honeycomb\Taboola;

use SimpleKuma\Honeycomb\AddonBootstrap;
use SimpleKuma\Honeycomb\HoneycombKernel;

final class Bootstrap implements AddonBootstrap
{
    public function register(HoneycombKernel $kernel): void
    {
        $db = $kernel->db();
        $kernel->addCostSyncJob(new TaboolaCostSyncJob($db));
        $kernel->addSettingsPanel(new TaboolaSettingsPanel($db));
        // Spend overlay uses core HoneycombCostAggregator + honeycomb_campaign_hourly_costs
        // (do not also register CostOverlayProvider for the same spend).
    }
}
