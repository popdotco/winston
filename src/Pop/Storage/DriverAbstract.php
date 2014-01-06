<?php
namespace Pop\Storage;

abstract class DriverAbstract {

    /**
     * Default constructor with optional config param required.
     *
     * @access  public
     * @param   array   $config
     * @return  void
     */
    abstract public function __construct($config = array());

    /**
     * Handle loading the storage client.
     *
     * @access  public
     * @return  void
     */
    abstract public function getClient($retries = 1);

    /**
     * Retrieves all tests and their associated variations.
     *
     * @access  public
     * @return  array
     */
    abstract public function getTests();

    /**
     * Retrieves all variations by test id.
     *
     * @access  public
     * @param   string  $test_id
     * @return  array
     */
    abstract public function getVariations($test_id);

    /**
     * Create/store the test object if it doesnt already exist.
     *
     * @access  public
     * @param   string  $test_id
     * @param   array   $test
     * @return  void
     */
    abstract public function createTestIfDne($test_id, $test);

    /**
     * Create/store the variation object if it doesnt already exist.
     *
     * @access  public
     * @param   string  $test_id
     * @param   string  $variation_id
     * @return  void
     */
    abstract public function createVariationIfDne($test_id, $variation_id);

    /**
     * Record a pageview on a particular test and variation.
     *
     * @access  public
     * @param   string  $test_id
     * @param   array   $variation
     * @return  mixed
     */
    abstract public function addPageview($test_id, $variation);

    /**
     * Record success/completion of a test variation.
     *
     * @access  public
     * @param   string  $test_id
     * @return  mixed
     */
    abstract public function addWin($test_id, $variation_id);

}
