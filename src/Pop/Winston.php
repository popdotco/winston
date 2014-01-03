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
     * The percentage of the time we wish to pick a random variation, if
     * machine learning is turned on.
     * @var float
     */
    public $randomPickPercentage = .10;

    /**
     * Whether the machine learning algorithm is enabled or not.
     * @var bool
     */
    public $enableMachineLearning = true;

    /**
     * The confidence interval before machine learnings kicks in.
     * @var float
     */
    public $confidenceInterval = .95;

    /**
     * Whether or not to detect bots.
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
     * Default constructor which takes an optional configuration array.
     *
     * @access  public
     * @param   array   $config
     * @return  void
     */
    public function __construct($config = array())
    {
        // basic session implementation
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        if (!empty($config)) {
            $this->setConfig($config);
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

        // return tracking event
        return 'on' . $trimType . '="POP.Winston.event(\''
            . $test_id . '\', \''
            . $variation_id . '\', \''
            . $trimType
            . '\');"';
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
        $output .= 'POP.Winston.disabled = ' . $isDisabled . ';';

        // generate api endpoint urls
        $output .= 'POP.Winston.endpoints = {' . PHP_EOL;
        $output .= 'trackEvent: \'' . $this->config['endpoints']['event'] . '\',';
        $output .= 'trackPageview: \'' . $this->config['endpoints']['pageview'] . '\'';
        $output .= '}' . PHP_EOL . PHP_EOL;

        // generate pageviews for active tests
        if (!empty($this->activeTests)) {
            $pageviews = array();
            foreach ($this->activeTests as $test_id => $variation_id) {
                $pageviews[] = '{ test_id: \'' . $test_id . '\', variation_id: \'' . $variation_id . '\' }';
            }

            $output .= 'var __abTests = [' . PHP_EOL;
            $output .= implode(', ' . PHP_EOL, $pageviews) . PHP_EOL;
            $output .= ']' . PHP_EOL;
            $output .= 'POP.Winston.pageview(__abTests);' . PHP_EOL;
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
    public function recordPageview($pageviews = array(), $token)
    {

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
        // valid list of events
        $validEvents = $this->getValidEvents();
        foreach ($validEvents as $event) {
            $templateKey = '{{' . $event . '}}';
            if (strpos($text, $templateKey) !== FALSE) {
                // generate event binding
                $eventBinding = $this->generateEvent($test_id, $variation_id, $event);

                $text = str_replace($templateKey, $eventBinding, $text);
            }
        }

        return $text;
    }

    /**
     * Returns an array of all valid event types.
     *
     * @access  public
     * @return  array
     */
    public function getValidEvents()
    {
        return array(
            'click', 'submit', 'focus', 'blur', 'change', 'mouseover', 'mouseout',
            'mousedown', 'mouseup', 'keypress', 'keydown', 'keyup'
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
        if (empty($this->tests)) {
            $this->getTests();
        }

        return isset($this->tests[$test_id]) ? $this->tests[$test_id] : false;
    }

    /**
     * Retrieve and cache locally all tests.
     *
     * @access  public
     * @return  void
     */
    public function getTests()
    {
        $this->tests = Config::get(NULL, 'ab');
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
        // TODO: check confidence intervals for optimal variation
        // $variation = $this->optimalVariationCI($test);

        // if machine learning is disabled, always pick random
        if (!$this->enableMachineLearning) {
            $variation = $this->randomVariation($test);
        } else {
            // generate a random float between 0 and 1
            $rand - mt_rand() / mt_getrandmax();
            if ($rand < $this->randomPickPercentage) {
                $variation = $this->randomVariation($test);
            } else {
                $variation = $this->optimalVariation($test);
            }
        }

        if (empty($variation)) {
            return false;
        }

        // set the active variation
        $this->activeTests[$test['id']] = $variation['id'];

        // handle find and replace on variation text for templating
        $variation['text'] = $this->replaceTemplateTags(
            $test_id,
            $variation['id'],
            $variation['text']
        );

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
        $numVariations = count($test['variations']);
        if ($numVariations == 0) {
            return false;
        }

        return $test['variations'][rand(0, $numVariations - 1)];
    }

    /**
     * Pick the optimal variation. An optimal variation is the one with the
     * highest likelihood of success.
     *
     * @access  public
     * @param   array   &$test
     * @return  null|array
     */
    public function optimalVariation(&$test)
    {
        $optimal = NULL;
        $highestPercentage = 0.00;

        foreach ($test['variations'] as $variation) {
            if ($variation['pageviews'] == 0 || $variation['wins'] == 0) {
                continue;
            }

            // calculate percentage successes
            $percentage = $variation['wins'] / $variation['pageviews'];
            $test['variations'][$variation]['success_rate'] = $percentage;

            // determine if setting a new optimal
            if ($percentage > $highestPercentage) {
                $optimal = $test['variations'][$variation];
            }
        }

        // if we still have no optimal variation, pick one at random
        if (is_null($optimal)) {
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
        $totalPageviews = 0.00;
        $totalWins = 0.00;

        // calculate the average pageviews and wins
        foreach ($test['variations'] as $variation) {
            $totalPageviews += $variation['pageviews'];
            $totalWins += $variation['wins'] * $variation['pageviews'];
        }

        $avgPageviews = $totalPageviews / count($test['variations']);
        $avgWins = $totalWins / count($test['variations']);

        // calculate average bayes for each variation
        foreach ($test['variations'] as $k => $variation) {
            $bayes =
                (($avgPageviews * $avgWins) + $test['variations'][$k]['wins'])
                / $test['variations'][$k]['pageviews'] + $avgPageviews;

            $test['variations'][$k]['bayes'] = $bayes;

            // check for a new optimal variation
            if ($bayes > $optimalBayes) {
                $optimalBayes = $bayes;
                $optimalVariation = $variation;
            }
        }

        // calculate confidence interval for the best overall
        $confidence = min($a, $b) / max($a, $b);

        // return how confident we are
        if ($confidence < $this->confidenceInterval) {
            return false;
        }

        return $optimalVariation;
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

        // handle whether to detect and avoid bots
        $this->setDetectBots(isset($config['detectBots']) && $config['detectBots'] == true);
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
     * @param   access  $public
     * @param   string  $test_id
     * @return  string|false
     */
    public function getCookieVariation($test_id)
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

        // pick a random variation
        $variation = $this->pickVariation($test);
        if (empty($variation)) {
            return false;
        }

        // set the active variation
        $this->tests[$test_id]['variation'] = $variation;

        // set as the user's variation
        $this->setCookieVariation($variation['id']);
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
     * Handle generating a new session token.
     *
     * @access  public
     * @return  string
     */
    public function generateToken()
    {
        $c = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $l = strlen($c) - 1;
        $s = '';
        for ($i = 0; $i < 32; $i++) {
            $s .= $c[rand(0, $l)];
        }

        // set session token
        $_SESSION['pop-winston-token'] = $s;

        return $s;
    }

    /**
     * Check if a given request token is valid and matches the current session
     * token for the user. This simply checks for existance and an exact string
     * match. The token is re-generated on each call to Pop\Ab::javascript(),
     * so one of our only fears is that a man-in-the-middle attack could lead
     * to false inflation of our ab testing results.
     *
     * @access  public
     * @param   string  $token
     * @return  bool
     */
    public function isValidToken($token)
    {
        if (empty($_SESSION['pop-winston-token'])) {
            return false;
        }

        return $token === $_SESSION['pop-winston-token'];
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

}
