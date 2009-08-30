<?php
require_once dirname(__FILE__) . '/CouchDBInfoableAPI.php';
require_once dirname(__FILE__) . '/CouchDBDeletableAPI.php';

/**
 * CouchDB database API
 */
interface CouchDBDatabaseAPI extends CouchDBInfoableAPI, CouchDBDeletableAPI
{
    /**
     * Compact database
     * @return stdClass
     */
    public function compact();

    /**
     * Create database
     * @return stdClass
     */
    public function create();

    /**
     * Bulk documents API
     * @param array
     * @return stdClass
     */
    public function bulk(array $docs);

    /**
     * View all documents
     * @param array
     * @return stdClass
     */
    public function docs(array $params = array());

    /**
     * Query database (temporary view)
     * @param string map function
     * @param string reduce function
     * @param array
     * @param string
     * @return stdClass
     */
    public function query($map, $reduce = NULL, array $params = array(), $language = 'javascript');

    /**
     * Permanent view
     * @param string design document ID
     * @param string view name
     * @param array
     * @return stdClass
     */
    public function view($design, $view, array $params = array());

    /**
     * Get document
     * @param string
     * @param string
     * @param bool load attachments?
     * @return CouchDBDocumentAPI
     */
    public function doc($id = NULL, $rev = NULL, $attachments = FALSE);
}
