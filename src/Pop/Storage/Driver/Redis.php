<?php
namespace Pop\Storage\Driver;

use \Pop\Storage\DriverAbstract;

/**
 * An implementation using redis as a database/backend storage driver for the
 * winston a/b testing library.
 */
class Redis extends DriverAbstract {

    /**
     * Maximum consecutive attempts to retry a failed redis connection.
     * @var int
     */
    const MAX_RETRIES = 5;

    /**
     * Redis client configuration options.
     * @var array
     */
    public $config = array();

    /**
     * The Predis client.
     * @var Predis
     */
    public $client;

    /**
     * Default constructor.
     *
     * @access  public
     * @return  void
     */
    public function __construct($config = array())
    {
        // set the config
        if (!empty($config)) {
            $this->config = $config;
        }
    }

    /**
     * Handle loading the redis client.
     *
     * @access  public
     * @return  void
     */
    public function getClient($retries = 1)
    {
        try {

            if (!empty($this->client) && $this->client->isConnected()) {
                return true;
            }

            $scheme = empty($this->config['scheme']) ? 'tcp' : $this->config['scheme'];
            $host   = empty($this->config['host']) ? '127.0.0.1' : $this->config['host'];
            $port   = empty($this->config['port']) ? '6379' : $this->config['port'];
            $prefix = empty($this->config['prefix']) ? NULL : $this->config['prefix'];

            $this->client = new \Predis\Client(array(
                'prefix'    => rtrim($prefix, ':') . ':',
                'scheme'    => $scheme,
                'host'      => $host,
                'port'      => $port
            ));

            // TODO: handle authentication if necessary
            if (!empty($this->config['password'])) {
                $this->client->auth($this->config['password']);
            }

            return true;

        } catch (Exception $e) {
            // TODO: better error handling
            error_log($e->getMessage());
            error_log(print_r($e, true));
        }

        // handle retries on failure
        if ($retries < self::MAX_RETRIES) {
            sleep($retries + 1);
            return $this->getClient($retries + 1);
        }

        return false;
    }

    /**
     * Retrieves all tests and their associated variations.
     *
     * @access  public
     * @return  array
     */
    public function getTests()
    {
        $this->getClient();

        $test_ids = $this->client->lrange('test.ids', 0, -1);
        if (!empty($test_ids)) {
            $tests = $this->client->pipeline(function($pipe) use ($test_ids) {
                foreach ($test_ids as $id) {
                    $pipe->hgetall('test:' . $id);
                }
            });

            // now that we have all tests, get all associated variations
            foreach ($tests as $k => $test) {
                $tests[$k]['variations'] = $this->getVariations($test['id']);
            }

            return $tests;
        }

        return false;
    }

    /**
     * Retrieves all variations by test id.
     *
     * @access  public
     * @param   string  $test_id
     * @return  array
     */
    public function getVariations($test_id)
    {
        $this->getClient();

        // find all variation ids by test id
        $variation_ids = $this->client->lrange('test:' . $test_id . ':variation.ids', 0, -1);
        if (!empty($variation_ids)) {
            $variations = $this->client->pipeline(function ($pipe) use ($variation_ids) {
                foreach ($variation_ids as $id) {
                    $pipe->hgetall('variation:' . $id);
                }
            });

            return $variations;
        }

        return false;
    }

    /**
     * Create the test object hash if it doesnt already exist.
     *
     * @access  public
     * @param   string  $test_id
     * @param   array   $test
     * @return  void
     */
    public function createTestIfDne($test_id, $test)
    {
        $this->getClient();

        // create a timestamp
        $now = new \DateTime('now', new \DateTimeZone('UTC'));
        $now = $now->format('U');

        // create test if DNE
        if (!$this->client->hexists('test:' . $test_id, 'pageviews')) {
            // add test hash key to list
            $this->client->rpush('test.ids', $test_id);

            // create test hash
            $this->client->hmset('test:' . $test_id, array(
                'id'            => $test_id,
                'pageviews'     => 0,
                'description'   => $test['description'],
                'timestamp'     => $now
            ));
        }
    }

    /**
     * Create the variation object hash if it doesnt already exist.
     *
     * @access  public
     * @param   string  $test_id
     * @param   string  $variation_id
     * @return  void
     */
    public function createVariationIfDne($test_id, $variation_id)
    {
        $this->getClient();

        // create a timestamp
        $now = new \DateTime('now', new \DateTimeZone('UTC'));
        $now = $now->format('U');

        // create variation if DNE
        if (!$this->client->hexists('variation:' . $variation_id, 'pageviews')) {
            // add variation hash key to list
            $this->client->rpush('variation.ids', $variation_id);

            // create variation hash
            $this->client->hmset('variation:' . $variation_id, array(
                'id'            => $variation_id,
                'test_id'       => $test_id,
                'pageviews'     => 0,
                'wins'          => 0,
                'timestamp'     => $now
            ));

            // associate variation to test
            $this->client->rpush('test:' . $test_id . ':variation.ids', $variation_id);
        }
    }

    /**
     * Record a pageview on a particular test and variation.
     *
     * @access  public
     * @param   string  $test_id
     * @param   array   $variation_id
     * @return  mixed
     */
    public function addPageview($test_id, $variation_id)
    {
        $this->getClient();

        // transaction timestamp
        $now = new \DateTime('now', new \DateTimeZone('UTC'));
        $now = $now->format('U');

        // begin a transaction
        $responses = $this->client->multiExec(function($tx) use($test_id, $variation_id) {
            // increment the object hash counts
            $this->client->hincrby('test:' . $test_id, 'pageviews', 1);
            $this->client->hincrby('variation:' . $variation_id, 'pageviews', 1);

            // increment the sorted set counts for pageview rankings
            $testPageviews = $this->client->zincrby('tests:sorted_by_views', 1, $test_id);
            $variationPageviews = $this->client->zincrby('variations:sorted_by_views', 1, $variation_id);

            // retrieve the hash wins
            $variationWins = $this->client->hget('variation:' . $variation_id, 'wins');

            // calculate ranking change
            $rank = 0.00;
            if ($variationPageviews > 0) {
                $rank = $variationWins / $variationPageviews;
            }

            // update the variation rankings
            $this->client->zadd('variations:sorted_by_rank', $rank, $variation_id);
        });
    }

    /**
     * Record success/completion of a test variation.
     *
     * @access  public
     * @param   string  $test_id
     * @return  mixed
     */
    public function addWin($test_id, $variation_id)
    {
        $this->getClient();

        // increment the object hash count
        $wins = $this->client->hincrby('variation:' . $variation_id, 'wins', 1);

        // retrieve the variation views
        $pageviews = $this->client->hget('variation:' . $variation_id, 'pageviews');

        // calculate ranking change
        $rank = 0.00;
        if ($pageviews > 0) {
            $rank = $wins / $pageviews;
        }

        // update the variation rankings
        $this->client->zadd('variations:sorted_by_rank', $rank, $variation_id);
    }

}
