<?php

declare(strict_types=1);

namespace Honeycomb\Ringba;

use SimpleKuma\Honeycomb\AddonBootstrap;
use SimpleKuma\Honeycomb\HoneycombKernel;

final class Bootstrap implements AddonBootstrap
{
    public function register(HoneycombKernel $kernel): void
    {
        $db = $kernel->db();
        $kernel->addSettingsPanel(new RingbaSettingsPanel($db));
        $kernel->addCampaignFieldsProvider(new RingbaCampaignFields($db));
    }
}
