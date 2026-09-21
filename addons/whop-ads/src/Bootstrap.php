<?php

declare(strict_types=1);

namespace Honeycomb\Whop;

use SimpleKuma\Honeycomb\AddonBootstrap;
use SimpleKuma\Honeycomb\HoneycombKernel;

final class Bootstrap implements AddonBootstrap
{
    public function register(HoneycombKernel $kernel): void
    {
        $db = $kernel->db();
        $kernel->addConversionExportProvider(new WhopConversionExporter($db));
        $kernel->addCostSyncJob(new WhopCostSyncJob($db));
        $kernel->addSettingsPanel(new WhopSettingsPanel($db));
        // Spend overlay uses core HoneycombCostAggregator + honeycomb_campaign_hourly_costs.
    }
}
