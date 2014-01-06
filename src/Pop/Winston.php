<?php
namespace Pop;

/**
 * Simple Machine Learning version alternative to A/B testing which tends to use
 * the most popular option 90% of the time.
 *
 * http://stevehanov.ca/blog/index.php?id=132?utm_medium=referral
 */
class Winston {

    /**
     * The confidence interval before machine learnings kicks in. If the value
     * is NULL, FALSE, 0, or empty string, we won't use confidence intervals
     * when determining which variation to choose. If a float, i.e. .90 or .95,
     * we only pick the most popular variation if there's statistically
     * significant data backing it up.
     *
     * @var float
     */
    public $confidenceInterval = .95;

    /**
     * Whether the machine learning algorithm is enabled or not. If enabled, we
     * first check for a confidence interval ($confidenceInterval) and then fall
     * back to picking a random result a user defined percentage of the time
     * ($randomPickPercentage). The random pick handler is not based on statistics
     * and will merely pick the current most popular result the majority of the
     * time and a random result the other percentage.
     *
     * @var bool
     */
    public $enableMachineLearning = true;

    /**
     * The percentage of the time we wish to pick a random variation, if
     * machine learning is turned on.
     *
     * @var float
     */
    public $randomPickPercentage = .10;

    /**
     * Whether or not to detect bots.
     *
     * @var bool
     */
    public $detectBots = false;

    /**
     * Whether the client is a bot or not.
     * @var bool
     */
    public $isBot = null;

    /**
     * The overall array of tests/variations.
     * @var array
     */
    public $tests = array();

    /**
     * An overall mapping of tests and their variations currently active on the page.
     * @var array
     */
    public $activeTests = array();

    /**
     * Contains the storage configuration options.
     * @var array
     */
    public $storageConfig = array();

    /**
     * The current storage adapter for managing data.
     * @var \Pop\Storage\DriverAbstract
     */
    public $storage = null;

    /**
     * Cookie configuration values.
     * @var array
     */
    public $cookie = array(
        'expires'   => 31536000,
        'path'      => '/',
        'domain'    => 'localhost',
        'secure'    => false
    );

    /**
     * API endpoints passed in via config.
     * @var array
     */
    public $endpoints = array(
        'event'     => '/event',
        'pageview'  => '/pageview'
    );

    /**
     * The session token used as a part for authorizing inbound requests.
     * @var string
     */
    public $sessionToken = null;

    /**
     * Default constructor which takes an optional configuration array.
     *
     * @access  public
     * @param   array   $config
     * @return  void
     */
    public function __construct($config = array())
    {
        if (!empty($config)) {
            $this->setConfig($config);
        }

        // basic session implementation
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
    }

    /**
     * Handle picking a test variation. Test variations support a very simple
     * templating scheme so you can add inline events to a variation using the
     * syntax {{EVENT_TYPE}} where EVENT_TYPE is one of the core JavaScript
     * DOM events. Available DOM events are listed in the method getValidEvents.
     *
     * @access  public
     * @param   string  $test_id
     */
    public function variation($test_id)
    {
        // check if user already has a test picked
        $variation = $this->getVariation($test_id);
        if (empty($variation)) {
            return '';
        }

        return $variation['text'];
    }

    /**
     * Generate output which enables a particular type of event triggering
     * a specific case. Returns Javascript event code to insert directly
     * into DOM elements or an empty string on failure.
     *
     * @access  public
     * @param   string  $test_id
     * @param   string  $type
     * @return  string
     */
    public function event($test_id, $type = 'click')
    {
        $variation = $this->getVariation($test_id);
        if (empty($variation)) {
            return '';
        }

        return $this->generateEvent($test_id, $variation['id'], $type);
    }

    /**
     * Handle generating an event handler given the test, variation, and
     * event type.
     *
     * @access  public
     * @param   string  $test_id
     * @param   string  $variation_id
     * @param   string  $type
     */
    public function generateEvent($test_id, $variation_id, $type)
    {
        // check for variation(s)
        $variation = $this->getVariation($test_id);
        if (empty($variation)) {
            return '';
        }

        $trimType = trim(strtolower($type));
        $validEvents = $this->getValidEvents();
        if (!in_array($trimType, $validEvents)) {
            return '';
        }

        // get the token
        $token = $this->generateToken();

        // get the code
        $data = array('test_id' => $test_id, 'variation_id' => $variation_id);
        $data = json_encode($data);
        $code = $this->generateHmac($token, $data);

        // the event binding
        $event = 'POP.Winston.event(' . $data . ', \'' . $code . '\', \'' . $trimType . '\');';

        // special case for manual events
        if ($trimType != 'manual') {
            $event = 'on' . $trimType . '="' . $event . '"';
        }

        return $event;
    }

    /**
     * Generates the code for variation pageviews. Must be called after all page
     * events have been added to a page. Must also be called after the proper
     * Google Analytics tracking code has been added to a page.
     *
     * @access  public
     * @return  string
     */
    public function javascript()
    {
        $token = $this->generateToken();

        $output = '<script type="text/javascript" defer>' . PHP_EOL;
        $output .= 'POP = POP || {};' . PHP_EOL;
        $output .= 'POP.Winston = POP.Winston || {};' . PHP_EOL;
        $output .= 'POP.Winston.token = \'' . $token . '\';' . PHP_EOL;

        // check if disabling winston
        $isDisabled = $this->getDetectBots() && $this->isBot() ? 'true' : 'false';
        $output .= 'POP.Winston.disabled = ' . $isDisabled . ';' . PHP_EOL;

        // generate api endpoint urls
        $output .= 'POP.Winston.endpoints = { ';
        $output .= 'trackEvent: \'' . $this->endpoints['event'] . '\',';
        $output .= 'trackPageview: \'' . $this->endpoints['pageview'] . '\'';
        $output .= ' };' . PHP_EOL;

        error_log('Active tests:');
        error_log(print_r($this->activeTests, true));

        // generate pageviews for active tests
        if (!empty($this->activeTests)) {
            $pageviews = array();
            foreach ($this->activeTests as $test_id => $variation_id) {
                $pageviews[] = array('test_id' => $test_id, 'variation_id' => $variation_id);
            }
            $pageviews = json_encode($pageviews);
            $code = $this->generateHmac($token, $pageviews);
            $output .= PHP_EOL . 'POP.Winston.pageview(' . $pageviews . ', \'' . $code . '\');' . PHP_EOL;
        }

        $output .= '</script>' . PHP_EOL;

        return $output;
    }

    /**
     * TODO: Handle recording an incoming pageview or pageviews. Note that this
     * method is assumed to be tied in manually by the user to their server's
     * API. An endpoing is required that accepts the following POST data from
     * the client:
     *
     * - tests
     * - token
     */
    public function recordPageview($postData = array())
    {
        // don't record if bot detection is enabled and client is a bot
        if ($this->getDetectBots() && $this->isBot()) {
            return false;
        }

        // TODO: validation of data
        if (!$this->isAuthorizedRequest($postData)) {
            return false;
        }

        // trigger pageview recording for each test
        if (empty($postData['data']['tests'])) {
            return false;
        }

        // load up the storage adapter
        $this->loadStorageAdapter('redis');

        // add page view for every test
        foreach ($postData['data']['tests'] as $test) {
            $this->storage->addPageview($test['test_id'], $test['variation_id']);
        }
    }

    /**
     * TODO: Handle recording a success event for a particular test variation.
     * Note that this method is assumed to be tied in manually by the user to
     * their server's API. An endpoing is required that accepts the following
     * POST data from the client:
     *
     *  - test_id
     *  - variation_id
     *  - token
     */
    public function recordEvent($postData = array())
    {
        // don't record if bot detection is enabled and client is a bot
        if ($this->getDetectBots() && $this->isBot()) {
            return false;
        }

        // TODO: validation of data
        if (!$this->isAuthorizedRequest($postData)) {
            return false;
        } else if (empty($postData['data']['test_id']) || empty($postData['data']['variation_id'])) {
            return false;
        }

        // load up the storage adapter
        $this->loadStorageAdapter('redis');

        // pass off the data
        $this->storage->addWin($postData['data']['test_id'], $postData['data']['variation_id']);
    }

    /**
     * Given a string, return the string with any events.
     *
     * @access  public
     * @param   string  $test_id
     * @param   string  $variation_id
     * @param   string  $text
     */
    public function replaceTemplateTags($test_id, $variation_id, $text)
    {
        // return early if no template tags found
        if (strpos($text, '{{') === FALSE) {
            return $text;
        }

        // valid list of events
        $validEvents = $this->getValidEvents();
        foreach ($validEvents as $event) {
            $templateKey = '{{' . $event . '}}';
            if (strpos($text, $templateKey) !== FALSE) {
                // generate event binding
                $eventBinding = $this->generateEvent($test_id, $variation_id, $event);

                // perform a string replace of the key with the binding
                $text = str_replace($templateKey, $eventBinding, $text);
            }
        }

        return $text;
    }

    /**
     * Returns an array of all valid event types. All events are DOM based
     * except for 'manual', which is a special case that implies the user
     * just wants the line returned so they can add to global javascript and
     * trigger themselves. For example, store the event in a function which
     * they can call from an included JS file:
     *
     * function triggerTestWin() { <?= $winston->event('example-test', 'manual') ?> }
     *
     *
     * @access  public
     * @return  array
     */
    public function getValidEvents()
    {
        return array(
            'click', 'submit', 'focus', 'blur', 'change', 'mouseover', 'mouseout',
            'mousedown', 'mouseup', 'keypress', 'keydown', 'keyup', 'manual'
        );
    }

    /**
     * Retrieve a particular test.
     *
     * @access  public
     * @param   string  $test_id
     * @return  array|false
     */
    public function getTest($test_id)
    {
        return isset($this->tests[$test_id]) ? $this->tests[$test_id] : false;
    }

    /**
     * Retrieve and cache all tests locally. Useful as we often have a separation
     * of including a test variation on a page from it's event.
     *
     * @access  public
     * @param   mixed   $tests
     * @return  void
     */
    public function addTests($tests)
    {
        // ensure all tests and variations are stored in the database
        $this->loadStorageAdapter();
        foreach ($tests as $test_id => $test) {
            $this->storage->createTestIfDne($test_id, $test);
            foreach ($test['variations'] as $variation_id => $variation) {
                $this->storage->createVariationIfDne($test_id, $variation_id);
            }
        }

        // get stored tests and merge with config tests
        // we can't just used stored tests because some may no longer exist in config
        $storedTests = $this->storage->getTests();
        foreach ($storedTests as $storedTest) {
            // skip over tests not found in config
            if (!isset($tests[$storedTest['id']])) {
                continue;
            }

            // add pageviews and id
            $tests[$storedTest['id']]['id'] = $storedTest['id'];
            $tests[$storedTest['id']]['pageviews'] = $storedTest['pageviews'];

            // check for variation matches
            if (empty($storedTest['variations'])) {
                continue;
            }

            foreach ($storedTest['variations'] as $variation) {
                if (!isset($tests[$storedTest['id']]['variations'][$variation['id']])) {
                    continue;
                }

                $tests[$storedTest['id']]['variations'][$variation['id']]['id'] = $variation['id'];
                $tests[$storedTest['id']]['variations'][$variation['id']]['test_id'] = $variation['test_id'];
                $tests[$storedTest['id']]['variations'][$variation['id']]['pageviews'] = $variation['pageviews'];
                $tests[$storedTest['id']]['variations'][$variation['id']]['wins'] = $variation['wins'];
            }
        }

        error_log('TESTS:');
        error_log(print_r($tests, true));

        // merge stored tests with existing tests
        $this->tests = $tests;
    }

    /**
     * Handle picking a variation with some machine learning. Essentially a
     * wrapper around either picking a random variation 10% of the time or an
     * optimal variation the other 90% of the time. In the event no optimal
     * variation can be found (contains 0 views/clicks), we resort to a
     * random variation.
     *
     * @access  public
     * @param   array   $test
     * @return  array
     */
    public function pickVariation($test)
    {
        // if machine learning is disabled, just pick random
        if (!$this->enableMachineLearning) {
            $variation = $this->randomVariation($test);
        } else {
            // check confidence intervals for optimal variation
            if (!empty($this->confidenceInterval)) {
                $variation = $this->optimalVariationCI($test);
            }

            // if no variation was found, we likely don't have a statistically
            // significant result so fall back to the optimal variation method
            if (empty($variation)) {
                // generate a random float between 0 and 1
                $rand = mt_rand() / mt_getrandmax();
                if ($rand < $this->randomPickPercentage) {
                    $variation = $this->randomVariation($test);
                } else {
                    $variation = $this->optimalVariation($test);
                }
            }
        }

        // if still no variation, we simply don't have any
        if (empty($variation)) {
            error_log('No variation found in pickVariation.');
            return false;
        }

        // handle find and replace on variation text for templating
        $variation['text'] = $this->replaceTemplateTags(
            $test['id'],
            $variation['id'],
            $variation['text']
        );

        // set the selected variation as active
        $this->activeTests[$test['id']] = $variation['id'];

        return $variation;
    }

    /**
     * Handle picking a random variation.
     *
     * @access  public
     * @param   array   $test
     * @return  array
     */
    public function randomVariation($test)
    {
        error_log('Inside of randomVariation.');

        if (empty($test['variations'])) {
            return false;
        }

        $variation_id = array_rand($test['variations']);
        error_log('Variation key picked: ' . $variation_id);
        $variation = $test['variations'][$variation_id];
        $variation['id'] = $variation_id;

        return $variation;
    }

    /**
     * Pick the optimal variation. An optimal variation is the one with the
     * highest likelihood of success.
     *
     * @access  public
     * @param   array   &$test
     * @return  null|array
     */
    public function optimalVariation($test)
    {
        $optimal = NULL;
        $highestPercentage = 0.00;

        error_log('Inside of optimalVariation.');

        foreach ($test['variations'] as $variation_id => $variation) {
            // skip if no wins or views
            if ($variation['pageviews'] == 0 || $variation['wins'] == 0) {
                continue;
            }

            // calculate percentage successes
            $percentage = $variation['wins'] / $variation['pageviews'];

            // determine if setting a new optimal
            if ($percentage > $highestPercentage) {
                $optimal = $variation;
                $optimal['id'] = $variation_id;
            }
        }

        // if we still have no optimal variation, pick one at random
        if (empty($optimal)) {
            return $this->randomVariation($test);
        }

        return $optimal;
    }

    /**
     * Check if we can say that with a certain amount of statistical significance
     * that a particular variation is best. We use a bayesian average algorithm.
     *
     * http://codepad.org/ljXecKqa
     *
     * @access  public
     * @return  mixed
     */
    public function optimalVariationCI($test)
    {
        if (empty($test['variations'])) {
            return false;
        }

        $optimalVariation = false;
        $optimalBayes = 0.00;
        $totalPageviews = 0.00;
        $totalWins = 0.00;

        // calculate the average pageviews and wins
        foreach ($test['variations'] as $variation_id => $variation) {
            $totalPageviews += $variation['pageviews'];
            $totalWins += $variation['wins'] * $variation['pageviews'];
        }

        $avgPageviews = $totalPageviews / count($test['variations']);
        $avgWins = $totalWins / count($test['variations']);

        // store the calculated bayes avg. for determining CI
        $avgBayes = array();

        // calculate average bayes for each variation
        foreach ($test['variations'] as $variation_id => $variation) {
            if (($test['variations'][$variation_id]['pageviews'] + $avgPageviews) == 0) {
                $bayes = 0.00;
            } else {
                $bayes =
                    (($avgPageviews * $avgWins) + $test['variations'][$variation_id]['wins'])
                    / ($test['variations'][$variation_id]['pageviews'] + $avgPageviews);
            }

            // add to the calculated bayes values
            $avgBayes[] = $bayes;

            // add bayes value to the variation
            $variation['bayes'] = $bayes;

            // check for a new optimal variation
            if ($bayes > $optimalBayes) {
                $optimalBayes = $bayes;
                $optimalVariation = $variation;
                $optimalVariation['id'] = $variation_id;
            }
        }

        error_log('Tests w/ variations and bayes averages calculated:');
        error_log(print_r($test, true));

        // calculate confidence interval for the best overall
        $confidence = $optimalBayes > 0 ? min($avgBayes) / max($avgBayes) : 0;

        error_log('Confidence: ' . $confidence);

        // return how confident we are
        if ($confidence < $this->confidenceInterval) {
            return false;
        }

        return $optimalVariation;
    }

    /**
     * TODO: For individuals who want to manually prune their tests without
     * having to touch the storage engine directly.
     *
     * @access  public
     * @param   string  $test_id
     * @return  bool
     */
    public function deleteTestById($test_id)
    {

    }

    /**
     * TODO: For individuals who want to manually prune their test variations
     * without having to touch the storage engine directly.
     *
     * @access  public
     * @param   string  $test_id
     * @return  bool
     */
    public function deleteTestVariationById($test_id, $variation_id)
    {

    }

    /**
     * Set config values with defaults.
     *
     * @access  public
     * @param   array   $config
     */
    public function setConfig($config)
    {
        // handle cookie settings
        $keys = array('expires', 'path', 'domain', 'secure');
        foreach ($keys as $key) {
            if (!empty($config['cookie'][$key])) {
                $this->cookie[$key] = $config['cookie'][$key];
            }
        }

        // handle overriding session vars
        if (!empty($config['session'])) {
            $this->setSession($config['session']);
        }

        // handle whether to detect and avoid bots
        $this->setDetectBots(isset($config['detectBots']) && $config['detectBots'] == true);

        // TODO: remove hardcoding
        // handle setting the adapter config (currently hardcoded to redis)
        $this->setStorageConfig($config['redis']);

        // set api endpoints
        $this->setApiEndpoints(!empty($config['endpoints']) ? $config['endpoints'] : array());

        // add tests
        if (!empty($config['tests'])) {
            $this->addTests($config['tests']);
        }
    }

    /**
     * Handles setting the session config values if any overrides are needed.
     *
     * @access  public
     * @param   array   $config
     * @return  void
     */
    public function setSession($config)
    {
        $validSessionKeys = array(
            'save_path', 'name', 'save_handler', 'auto_start', 'gc_probability', 'gc_divisor',
            'gc_maxlifetime', 'serialize_handler', 'cookie_lifetime',
            'cookie_path', 'cookie_domain', 'cookie_secure', 'cookie_httponly',
            'use_strict_mode', 'use_cookies', 'use_only_cookies',
            'referer_check', 'entropy_file', 'entropy_length', 'cache_limiter',
            'cache_expire', 'use_trans_sid', 'bug_compat_42', 'bug_compat_warn',
            'hash_function', 'hash_bits_per_character'
        );

        foreach ($config as $key => $val) {
            if (!array_key_exists($key, $validSessionKeys)) {
                continue;
            }

            ini_set('session.' . $key, $val);
        }
    }

    /**
     * Set if we are detecting bots.
     *
     * @access  public
     * @param   bool    $detectBots
     * @return  void
     */
    public function setDetectBots($detectBots)
    {
        $this->detectBots = (bool) $detectBots;
    }

    /**
     * Handle setting the storage configuration options.
     *
     * @access  public
     * @param   string  $array
     * @return  void
     */
    public function setStorageConfig($config)
    {
        $this->storageConfig = $config;
    }

    /**
     * Handles setting API endpoints as we need to pass them to the frontend
     * javascript for generating AJAX requests.
     *
     * @access  public
     * @param   array   $endpoints
     * @return  void
     */
    public function setApiEndpoints($endpoints)
    {
        foreach ($endpoints as $k => $v) {
            if (isset($this->endpoints[$k])) {
                $this->endpoints[$k] = $v;
            }
        }
    }

    /**
     * Whether bots are being detected.
     *
     * @access  public
     * @return  bool
     */
    public function getDetectBots()
    {
        return $this->detectBots;
    }

    /**
     * Return the storage configuration options.
     *
     * @access  public
     * @return  bool
     */
    public function getStorageConfig()
    {
        return $this->storageConfig;
    }

    /**
     * Attempt to retrieve the currently selected variation via our local
     * cached copy.
     *
     * @access  public
     * @param   string  $test_id
     * @return  mixed
     */
    public function getVariation($test_id)
    {
        if (isset($this->tests[$test_id]['variation'])) {
            return $this->tests[$test_id]['variation'];
        }

        // fallback to attempting to retrieve the cookie variation
        return $this->getCookieVariation($test_id);
    }

    /**
     * Handle retrieving a user test variation. If none is found, we first
     * find one and then set it and return.
     *
     * @access  $public
     * @param   string  $test_id
     * @param   bool    $cookieOnly If we only return whether the cookie is properly set
     * @return  string|false
     */
    public function getCookieVariation($test_id, $cookieOnly = false)
    {
        // get the test values in question from the config
        $test = $this->getTest($test_id);
        if ($test === FALSE) {
            return false;
        }

        // check if a cookie variation is already set and if so, just use that
        if (isset($_COOKIE[$test_id])) {
            if (!isset($this->tests[$test_id]['variations'][$_COOKIE[$test_id]])) {
                $this->unsetCookieVariation($test_id);
            } else {
                // set the active variation
                $this->activeTests[$test_id] = $_COOKIE[$test_id];

                // store a local cache of the active variation and return
                return $this->tests[$test_id]['variation'] = $this->tests[$test_id]['variations'][$_COOKIE[$test_id]];
            }
        }

        // if we made it this far with no test, no cookie available
        if ($cookieOnly) {
            error_log('Cookie only variation requested and no cookie found.');
            return false;
        }

        // pick a random variation
        $variation = $this->pickVariation($test);
        if (empty($variation)) {
            return false;
        }

        // set the active variation
        $this->tests[$test_id]['variation'] = $variation;

        // set as the user's variation
        $this->setCookieVariation($test_id, $variation['id']);
    }

    /**
     * Set a user test for a period of one year given a particular key.
     *
     * @access  public
     * @param   string  $test_id
     * @param   string  $variation_id
     */
    public function setCookieVariation($test_id, $variation_id)
    {
        return setcookie(
            $test_id,
            $variation_id,
            time() + $this->cookie['expires'],
            $this->cookie['path'],
            $this->cookie['domain'],
            $this->cookie['secure']
        );
    }

    /**
     * Handles unsetting any potentially invalid test variations which
     * were set as active by a user. Either the user was attempting to be
     * malicious or the variation no longer exists.
     *
     * @access  public
     * @param   string  $test_id
     * @param   string  $variation_id
     */
    public function unsetCookieVariation($test_id, $variation_id)
    {
        return setcookie(
            $test_id,
            $variation_id,
            time() - 3600,
            $this->cookie['path'],
            $this->cookie['domain'],
            $this->cookie['secure']
        );
    }

    /**
     * Handle generating a new session token. Uses the one already set if found.
     *
     * @access  public
     * @return  string
     */
    public function generateToken()
    {
        if (!empty($this->sessionToken)) {
            return $this->sessionToken;
        }

        $c = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $l = strlen($c) - 1;
        $s = '';
        for ($i = 0; $i < 32; $i++) {
            $s .= $c[rand(0, $l)];
        }

        // set session token
        return $_SESSION['winston-token'] = $this->sessionToken = $s;
    }

    /**
     * Generate a message authentication string to accompany the data and
     * encrypted with the token. The frontend will need to pass back this
     * value as well as the token so we can verify the data hasn't been
     * tampered with.
     *
     * @access  public
     * @param   string  $token
     * @param   string  $data
     * @return  string
     */
    public function generateHmac($token, $data)
    {
        if (is_array($data)) {
            $data = json_encode($data);
        }

        return base64_encode(hash_hmac('sha1', $data, $token));
    }

    /**
     * Check if a given request token is valid and matches the current session
     * token for the user. This simply checks for existance and an exact string
     * match. The token is re-generated on each call to Pop\Ab::javascript(),
     * so one of our only fears is that a man-in-the-middle attack could lead
     * to false inflation of our ab testing results.
     *
     * @access  public
     * @param   array   $post
     * @return  bool
     */
    public function isAuthorizedRequest($postData)
    {
        if (empty($postData['token'])
            || empty($postData['code'])
            || empty($postData['data'])
            || empty($_SESSION['winston-token'])
        ) {
            error_log('Unauthorized request. Missing required authorization value.');
            return false;
        }

        // check if the token matches
        if ($postData['token'] !== $_SESSION['winston-token']) {
            error_log('Unauthorized request. Passed in token doesnt match session token.');
            return false;
        }

        $data = $postData['data'];
        if (is_array($data) || is_object($data)) {
            $data = json_encode($data);
        }

        $dataHmac = base64_encode(hash_hmac('sha1', $data, $postData['token']));
        if ($dataHmac !== $postData['code']) {
            error_log('Unauthorized request. The passed in HMAC was incorrect.');
            return false;
        }

        error_log('Authorized request.');
        return true;
    }

    /**
     * Check if the client is a bot. Crawler lists found from:
     *
     * http://www.useragentstring.com/pages/Crawlerlist/
     * http://user-agent-string.info/list-of-ua/bots
     *
     * (Ended on SemrushBot)
     *
     * @access  public
     * @return  bool
     */
    public function isBot()
    {
        if (!is_null($this->isBot)) {
            return $this->isBot;
        }

        // get the user agent
        $userAgent = getenv('HTTP_USER_AGENT');
        if (empty($userAgent)) {
            return false;
        }

        $this->isBot = false;

        $bots = array(
            // popular crawl related words
            'crawl', 'robot', 'search', 'spider',

            // popular
            'googlebot', 'baiduspider', 'bingbot', 'bingpreview', 'ia_archiver',
            'msnbot', 'slurp', 'yahooseeker', 'yandexbot', 'yandeximages',
            'ask jeeves', 'duckduckbot', 'facebookexternalhit', 'alexa',
            'facebookplatform',

            // 0-9
            '^nail', '192.com', '200pleasebot', '360spider', '4seohuntbot', '50.nu',
            '80legs',

            // A
            'a6-indexer', 'abachobot', 'abby', 'aboundexbot', 'aboutusbot',
            'abrave spider', 'accelobot', 'accoona-ai-agent', 'acoonbot',
            'admantx', 'adsbot-google', 'addsugarspiderbot', 'ahrefsbot',
            'aihitbot', 'akula', 'amagit', 'amibot', 'amznkassocbot', 'antbot',
            'anyapexbot', 'apercite', 'aportworm', 'arabot', 'arachmo',
            'arachnode', 'archive.org_bot', 'automattic',
            // B
            'b-l-i-t-z-b-o-t', 'babaloospider', 'backlinkcrawler', 'bad-neighborhood',
            'baypup', 'becomebot', 'bdfetch',
            'begunadvertising', 'beslistbot', 'billybobbot', 'bimbot', 'bitlybot',
            'biwec', 'bixocrawler', 'bl.uk', 'blekkobot', 'blexbot', 'blinkacrawler',
            'blitzbot', 'blogpulse', 'bnf.fr_bot', 'boitho.com-dc', 'boitho.com-robot',
            'bot.wsowner.com', 'botmobi', 'botonparade', 'bot-pge', 'browsershots',
            'btbot', 'bubing', 'butteryfly',
            // C
            'camontspider', 'careerbot', 'castabot', 'catchbot', 'ccbot',
            'ccresearchbot', 'cerberian drtrs', 'changedetection', 'charlotte/0',
            'charlotte/1', 'cirrusexplorer', 'cityreview', 'cligoorobot', 'cliqzbot',
            'cloudservermarketspider', 'coccoc', 'compspybot', 'converacrawler',
            'copyright sherrif', 'corpuscrawler', 'cosmos', 'covario-ids',
            'crawler4j', 'crowsnest', 'curious george',
            // D
            'daumoa', 'dataparksearch', 'dblbot', 'dcpbot', 'dealgates',
            'diamondbot', 'discobot', 'discoverybot', 'divr.it', 'dkimrepbot',
            'dns-digger-explorer', 'domaindb', 'dot tk', 'dotbot', 'dotsemantic',
            'dripfeedbot', 'drupact',
            // E
            'easouspider', 'easybib', 'ecairn-grabber', 'edisterbot', 'emefgebot',
            'emeraldshield', 'envolk', 'esperanzabot', 'esribot', 'euripbot',
            'eurobot', 'exabot', 'ezooms', 'eventgurubot', 'evrinid',
            // F
            'factbot', 'fairshare', 'falconsbot', 'fastbot', 'fast enterprise crawler',
            'fast-webcrawler', 'faubot', 'fdse robot', 'feedcatbot', 'feedfinder',
            'fetch-guess', 'findlinks', 'firmilybot', 'flatland industries',
            'flightdeck', 'flipboardproxy', 'flocke bot', 'followsite bot',
            'fooooo_web_video_crawl', 'furlbot', 'fyberspider',
            // G
            'g2crawler', 'gaisbot', 'galaxybot', 'garlikcrawler', 'geliyoobot',
            'genieo', 'geniebot', 'gigabot', 'gingercrawler', 'girafabot', 'gonzo',
            'grapeshotcrawler', 'gurujibot',
            // H
            'hailoobot', 'happyfunbot', 'hatenascreenshot', 'heartrails',
            'heritrix', 'hl_ftien_spider', 'holmes', 'hometags', 'hosttracker',
            'htdig', 'hubspot', 'huaweisymantecspider',
            // I
            'iaskspider', 'icc-crawler', 'ichiro', 'icjobs', 'igdespider', 'imbot',
            'influencebot', 'infohelfer', 'integromedb', 'irlbot', 'istellabot', 'ixebot',
            // J
            'jabse', 'jadynavebot', 'jaxified', 'jikespider', 'job roboter spider',
            'just-crawler', 'jyxobot',
            // K
            'kalooga', 'karneval-bot', 'keyworddensityrobot', 'koepabot', 'kongulo',
            'krowler',
            // L
            'l.webis', 'lapozzbot', 'larbin', 'ldspider', 'lemurwebcrawler',
            'lexxebot', 'lijit', 'linguabot', 'linguee bot', 'link valet',
            'linkaider', 'linkdexbot', 'linkwalker', 'livedoor', 'lmspider',
            'luminatebot', 'lwp-trivial',
            // M
            'mabontland', 'magpie-crawler', 'meanpathbot', 'memonewsbot',
            'metaheadersbot', 'metajobbot', 'metageneratorcrawler',
            'metamojicrawler', 'metaspinner', 'metauri', 'miadev', 'mia bot',
            'mj12bot', 'mlbot', 'mnogosearch', 'moba-crawler', 'mogimogi',
            'mojeekbot', 'moreoverbot', 'morning paper', 'mp3bot', 'msrbot',
            'mvaclient', 'mxbot',
            // N
            'naverbot', 'nerdbynature.bot', 'netcraftsurveyagent', 'netopian',
            'netresearchserver', 'netseer', 'newsgator', 'nextgensearchbot',
            'nigma.ru', 'ng-search', 'nicebot', 'nlnz_iaharvester', 'nodestackbot',
            'noxtrumbot', 'nuhk', 'nusearch spider', 'nutch', 'nworm', 'nymesis',
            // O
            'ocelli', 'oegp', 'omgilibot', 'omniexplorer_bot', 'open web analytics bot',
            'oozbot', 'opencalais', 'openindexspider', 'openwebspider', 'orbiter',
            'orgbybot', 'osobot', 'owncloud',
            // P
            'page_verifier', 'page2rss', 'pagebitshyperbot', 'pageseeker',
            'panscient', 'paperlibot', 'parchbot', 'parsijoo', 'peeplo',
            'peepowbot', 'peerindex', 'peew', 'percbotspider', 'pingdom',
            'piplbot', 'pixray-seeker', 'plukkie', 'pmoz.info', 'polybot',
            'pompos', 'postpost', 'procogseobot', 'proximic', 'psbot', 'pycurl',
            // Q
            'qirana', 'qualidator', 'queryseekerspider', 'quickobot', 'qseero',
            // R
            'r6 bot', 'radar-bot', 'radian6', 'rampybot', 'rankurbot', 'readability',
            'robots_tester', 'robozilla', 'rogerbot', 'ronzoobot', 'rssmicro',
            'rufusbot', 'ruky-roboter', 'ryzecrawler',
            // S
            'sai crawler', 'sandcrawler', 'sanszbot', 'sbider', 'sbsearch',
            'scarlet', 'scooter', 'scoutjet', 'screenerbot', 'scrubby', 'search17bot',
            'searchmetricsbot', 'searchsight', 'seekbot', 'semager', 'semanticdiscovery',
            'semantifire', 'semrushbot', 'seochat', 'seocheckbot', 'seodat', 'seoengbot',
            'seokicks-robot', 'setoozbot', 'seznambot', 'shareaholicbot', 'shelob',
            'shim-crawler', 'shopwiki', 'shoula robot', 'showyoubot', 'silk',
            'sistrix', 'sitedomain-bot', 'smart.apnoti.com', 'snapbot', 'snappy',
            'sniffrss', 'socialbm_bot', 'sogou spider', 'solomonobot', 'sosospider',
            'spbot', 'speedy', 'spinn3r', 'sqworm', 'sslbot', 'stackrambler',
            'statoolsbot', 'steeler', 'strokebot', 'suggybot', 'surphace scout',
            'surveybot', 'swebot', 'sygolbot', 'synoobot', 'szukacz',
            // T
            'tagoobot', 'taptubot', 'teoma', 'technoratibot', 'terrawizbot',
            'thesubot', 'thumbnail.cz', 'thumbshots', 'tineye', 'topicbot',
            'toread-crawler', 'touche', 'trendictionbot', 'truwogps', 'turnitinbot',
            'tweetedtimes', 'twengabot', 'twiceler', 'twikle',
            // U
            'uaslinkchecker', 'umbot', 'unisterbot', 'unwindfetcher', 'updownerbot',
            'uptimedog', 'uptimerobot', 'urlappendbot', 'urlfan-bot', 'urlfilebot',
            // V
            'vagabondo', 'vedma', 'videosurf_bot', 'visbot', 'voilabot', 'vortex',
            'voyager', 'vyu2',
            // W
            'wasalive-bot', 'watchmouse', 'wbsearchbot', 'web-sniffer', 'webcollage',
            'webinatorbot', 'webmastercoffee', 'webnl', 'webimages', 'webrankspider',
            'websquash', 'webthumbnail', 'wf84', 'whoismindbot', 'wikiofeedbot',
            'wikiwix-bot', 'willow', 'willybot', 'winwebbot', 'wofindeich',
            'wmcai_robot', 'woko', 'womlpefactory', 'woriobot', 'wotbox',
            'wsanalyzer', 'wscheck.com',
            // X
            'xaldon_webspider', 'xmarksfetch', 'xml sitemaps generator',
            // Y
            'yaanb', 'yacybot', 'yanga', 'yasaklibot', 'yeti', 'yioopbot',
            'yodaobot', 'yooglifetchagent', 'youdaobot', 'yowedobot', 'yrspider',
            // Z
            'zealbot', 'zookabot', 'zspider', 'zumbot', 'zyborg'
        );

        // check for a matching bot
        foreach ($bots as $bot) {
            if (stripos($userAgent, $bot) !== FALSE) {
                $this->isBot = true;
                break;
            }
        }

        return $this->isBot;
    }

    /**
     * Handle loading/setting the current storage adapter.
     */
    public function loadStorageAdapter($adapter = 'redis')
    {
        if (!empty($this->storage)) {
            return $this->storage;
        }

        if ($adapter == 'redis') {
            return $this->storage = new \Pop\Storage\Driver\Redis($this->getStorageConfig());
        }

        return false;
    }

}
