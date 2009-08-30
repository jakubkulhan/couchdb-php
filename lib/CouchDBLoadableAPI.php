<?php
/**
 * It's loadable
 */
interface CouchDBLoadableAPI
{
    /**
     * (Re)Load data
     * @return stdClass data; NULL on failure
     */
    public function load();
}
