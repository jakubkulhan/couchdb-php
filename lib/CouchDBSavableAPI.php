<?php
/**
 * It is savable
 */
interface CouchDBSavableAPI
{
    /**
     * Save
     * @return stdClass
     */
    public function save();
}
