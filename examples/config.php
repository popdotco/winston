<?php

/**
 * Here is an example configuration file to use with Winston containing all
 * options.
 */
return array(

    // TODO: for only allowing a specific list of referrers
    'whitelistedReferers' => array(),

    // TODO: for only allowing a specific list of client IPs
    'whitelistedIps' => array(),

    // whether to detect and reject known bots from any form of variation
    // testing and measuring.. simply serve up the first variation
    'detectBots' => true,

    // redis settings
    'redis' => array(
        'scheme'    => 'tcp',
        'host'      => '127.0.0.1',
        'port'      => '6379'
    ),

    // cookie configuration settings (default 1 year expiry)
    'cookie' => array(
        'path'      => '/',
        'expires'   => 31536000,
        'domain'    => '',
        'secure'    => false
    ),

    // session overrides via ini_set. almost all vars allowed:
    // http://www.php.net/manual/en/session.configuration.php
    'session' => array(
        'save_path' => '/tmp'
    ),

    // the API endpoints you will build to receive requests
    // create your own endpoints on your domain and alter here to match
    'endpoints' => array(
        // where to retrieve current test results
        'results'   => 'https://www.mydomain.co/winston/results',
        // where event actions are tracked
        'event'     => 'https://www.mydomain.co/winston/event',
        // where pageviews are tracked
        'pageview'  => 'https://www.mydomain.co/winston/pageview'
    ),

    // any and all user defined tests and their variations
    'tests' => array(

        // the array keys represent the unique test ids
        'paid-conversion-topbar-upsell-text' => array(
            // a description of the test so you don't forget
            'description'   => 'Text updates to the top subscription bar on the homepage to
                                promote converting to a paid account during the user\'s free
                                trial period.',

            // an array of all variations for the test
            // these can contain text, html, and a basic templating mechanism
            // in the format of {{EVENT_NAME}} which will be replaced by a
            // javascript DOM event to trigger a "win" in it's place
            'variations' => array(
                'upgrade-now-avoid-losing' => array(
                    'text'  => '<span class="highlight">Upgrade now</span> to avoid losing'
                ),
                'subscribe-now-avoid-losing' => array(
                    'text'  => '<span class="highlight">Subscribe now</span> to avoid losing'
                ),
                'pay-now-avoid-losing' => array(
                    'text'  => '<span class="highlight">Pay now</span> to avoid losing'
                ),
                'upgrade-now-keep' => array(
                    'text' => '<span class="highlight">Upgrade now</span> to keep'
                ),
                'subscribe-now-keep' => array(
                    'text'  => '<span class="highlight">Subscribe now</span> to keep'
                ),
                'pay-now-keep' => array(
                    'text'  => '<span class="highlight">Pay now</span> to keep'
                )
            )

        )

    )

);
