<?php
/**
 * It is deletable
 */
interface CouchDBDeletableAPI
{
    /**
     * Delete resource
     * @return stdClass
     */
    public function delete();
}
