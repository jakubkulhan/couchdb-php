<?php
/**
 * CouchDB infos
 */
interface CouchDBInfoableAPI
{
    /**
     * Return info
     * @return stdClass
     */
    public function info();
}
