<?php
/**
 * Connects to CouchDB
 */
interface CouchDBConnector
{
    /**
     * Method GET
     */
    const GET = 'GET';

    /**
     * Method POST
     */
    const POST = 'POST';

    /**
     * Method PUT
     */
    const PUT = 'PUT';

    /**
     * Method DELETE
     */
    const DELETE = 'DELETE';

    /**
     * Send request to database
     * @param string
     * @param string
     * @param array
     * @param mixed
     * @param bool whether body should be sent as raw
     * @param array additional headers
     * @return array
     */
    public function request($method, $path, array $query = array(), $body = NULL, 
        $raw_body = FALSE, $headers = array());
}
