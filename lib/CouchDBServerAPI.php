<?php
require_once dirname(__FILE__) . '/CouchDBInfoableAPI.php';

/**
 * CouchDB server API
 */
interface CouchDBServerAPI extends CouchDBInfoableAPI
{
    /**
     * Server config
     * @return stdClass
     */
    public function config();

    /**
     * List all databases
     * @return array
     */
    public function dbs();

    /**
     * Replicate database source to target
     * @param string
     * @param string
     * @return stdClass
     */
    public function replicate($source, $target);

    /**
     * Server stats
     * @return stdClass
     */
    public function stats();

    /**
     * Generate some UUIDs
     * @param int
     * @return array
     */
    public function uuids($count = NULL);

    /**
     * Get database
     * @return CouchDBDatabaseAPI
     */
    public function db($db);
}
