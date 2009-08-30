<?php
require_once dirname(__FILE__) . '/CouchDBSavableAPI.php';
require_once dirname(__FILE__) . '/CouchDBDeletableAPI.php';
require_once dirname(__FILE__) . '/CouchDBLoadableAPI.php';

/**
 * CouchDB savable, deletable and loadable resources
 */
interface CouchDBResourceAPI extends CouchDBSavableAPI, CouchDBDeletableAPI, CouchDBLoadableAPI
{
}
