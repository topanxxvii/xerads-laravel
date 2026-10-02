<?php

namespace XerAds\Laravel\Content\Contracts;

use XerAds\Laravel\Content\ConfigurationReport;

/**
 * A receiver that can say whether it is set up correctly without storing
 * anything.
 *
 * The connection test in XerAds runs this. Before it existed the test only
 * proved the endpoint answered, so a site pointing at a model that does not
 * exist passed the test and failed on the first real article.
 */
interface ValidatesConfiguration
{
    public function validateConfiguration(): ConfigurationReport;
}
