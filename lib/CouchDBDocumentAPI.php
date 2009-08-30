<?php
require_once dirname(__FILE__) . '/CouchDBResourceAPI.php';

/**
 * CouchDB document API
 */
interface CouchDBDocumentAPI extends CouchDBResourceAPI
{
    /**
     * Get attachment
     * @param string
     * @return CouchDBAttachmentAPI
     */
    public function attachment($attachment);
}
