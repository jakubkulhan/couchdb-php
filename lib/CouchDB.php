<?php
require_once dirname(__FILE__) . '/CouchDBServerAPI.php';
require_once dirname(__FILE__) . '/CouchDBDatabaseAPI.php';
require_once dirname(__FILE__) . '/CouchDBDocumentAPI.php';
require_once dirname(__FILE__) . '/CouchDBAttachmentAPI.php';

/**
 * Interface to CouchDB
 */
class CouchDB implements CouchDBServerAPI, CouchDBDatabaseAPI, 
    CouchDBDocumentAPI, CouchDBAttachmentAPI
{
    /**
     * Server level
     */
    const SRVLVL = 'srv';

    /**
     * Database level
     */
    const DBLVL = 'db';

    /**
     * Document level
     */
    const DOCLVL = 'doc';

    /**
     * Attachment level
     */
    const ATTACHMENTLVL = 'attachment';

    /**
     * @var CouchDBConnector CouchDB connector
     */
    private $connector;

    /**
     * @var int Level
     */
    private $lvl = self::SRVLVL;

    /**
     * @var string Working database
     */
    private $db = NULL;

    /**
     * @var string Current document ID
     */
    private $doc = NULL;

    /**
     * @var string Current document revision
     */
    private $rev = NULL;

    /**
     * @var bool Load attachments with doc?
     */
    private $attachments = FALSE;

    /**
     * @var string Current attachment
     */
    private $attachment = NULL;

    /**
     * @var string Current document/attachment data
     */
    private $data = NULL;

    /**
     * Creates new instance; connector created accoring to URL scheme
     * @param string
     * @return CouchDB|bool FALSE on failure
     */
    public static function open($url)
    {
        if (!($scheme = parse_url($url, PHP_URL_SCHEME))) return FALSE;

        $connector_name = 'CouchDB' . ucfirst($scheme). 'Connector';
        $connector_file = dirname(__FILE__) . '/' . $connector_name . '.php';
        if (!is_readable($connector_file)) return FALSE;
        require_once $connector_file;

        if (!($connector = call_user_func(array($connector_name, 'open'), $url))) return FALSE;
        return new self($connector);
    }

    /**
     * Construct; use method open() to create instance
     * @param CouchDBConnector
     */
    public function __construct(CouchDBConnector $connector)
    {
        $this->connector = $connector;
    }

    /***** SERVER *****/

    /**
     * Get server info
     * @return stdClass
     */
    protected function srvinfo()
    {
        return $this->connector->request(CouchDBConnector::GET, '/');
    }

    /**
     * Get server stats
     * @retun stdClass
     */
    protected function srvstats()
    {
        return $this->connector->request(CouchDBConnector::GET, '/_stats');
    }

    /**
     * Get server config
     * @return stdClass
     */
    protected function srvconfig()
    {
        return $this->connector->request(CouchDBConnector::GET, '/_config');
    }

    /**
     * Generate some UUIDs
     * @param int
     * @return array
     */
    protected function srvuuids($count = NULL)
    {
        return $this->connector->request(CouchDBConnector::GET, '/_uuids',
            $count === NULL ? array() : array('count' => $count));
    }

    /**
     * Replicate database source to target
     * @param string
     * @param string
     * @return stdClass
     */
    protected function srvreplicate($source, $target)
    {
        return $this->connector->request(
            CouchDBConnector::POST, 
            '/_replicate', 
            array(),
            array('source' => $source, 'target' => $target)
        );
    }

    /**
     * List all databases
     * @return array
     */
    protected function srvdbs()
    {
        return $this->connector->request(CouchDBConnector::GET, '/_all_dbs');
    }

    /**
     * Get database
     * @return CouchDBDatabaseAPI
     */
    protected function srvdb($db)
    {
        $clone = clone $this;
        $clone->db = $db;
        $clone->lvl = self::DBLVL;
        return $clone;
    }

    /***** DATABASE *****/

    /**
     * Get database info
     * @return stdClass
     */
    protected function dbinfo()
    {
        return $this->connector->request(CouchDBConnector::GET, '/' . 
            rawurlencode($this->db));
    }

    /**
     * Compact database
     * @return stdClass
     */
    protected function dbcompact()
    {
        return $this->connector->request(CouchDBConnector::POST, '/' . 
            rawurlencode($this->db) . '/_compact');
    }

    /**
     * Create database
     * @return stdClass
     */
    protected function dbcreate()
    {
        return $this->connector->request(CouchDBConnector::PUT, '/' .
            rawurlencode($this->db));
    }

    /**
     * Drop database
     * @return stdClass
     */
    protected function dbdelete()
    {
        return $this->connector->request(CouchDBConnector::DELETE, '/' .
            rawurlencode($this->db));
    }

    /**
     * Get document
     * @param string
     * @param string
     * @param bool load attachments?
     * @return CouchDBDocumentAPI
     */
    protected function dbdoc($id = NULL, $rev = NULL, $attachments = FALSE)
    {
        $clone = clone $this;
        $clone->doc = $id;
        $clone->rev = $rev;
        $clone->attachments = $attachments;
        $clone->lvl = self::DOCLVL;
        return $clone;
    }

    /**
     * View all documents
     * @param array
     * @return stdClass
     */
    protected function dbdocs(array $params = array())
    {
        return $this->connector->request(CouchDBConnector::GET, '/' .
            rawurlencode($this->db) . '/_all_docs', $params);
    }

    /**
     * Bulk documents API
     * @param array
     * @return stdClass
     */
    protected function dbbulk(array $docs)
    {
        return $this->connector->request(
            CouchDBConnector::POST, 
            '/' .rawurlencode($this->db) . '/_bulk_docs', 
            array(),
            array('docs' => $docs)
        );
    }

    /**
     * Query database (temporary view)
     * @param string map function
     * @param string reduce function
     * @param array
     * @param string
     * @return stdClass
     */
    protected function dbquery($map, $reduce = NULL, array $params = array(), $language = 'javascript')
    {
        $body = array('language' => $language, 'map' => $map);
        if ($reduce !== NULL) $body['reduce'] = $reduce;
        return $this->connector->request(
            CouchDBConnector::POST, 
            '/' . rawurlencode($this->db) . '/_temp_view', 
            $params, 
            $body
        );
    }

    /**
     * Permanent view
     * @param string design document ID
     * @param string view name
     * @param array
     * @return stdClass
     */
    protected function dbview($design, $view, array $params = array())
    {
        return $this->connector->request(
            CouchDBConnector::GET, 
            '/' . rawurlencode($this->db) . 
                '/_design/' . $design . '/_view/' . $view,
            $params
        );
    }

    /***** DOCUMENT *****/

    /**
     * (Re)Load document data
     * @return stdClass data; NULL on failure
     */
    protected function docload()
    {
        $this->data = $this->connector->request(
            CouchDBConnector::GET,
            '/' . rawurlencode($this->db) . '/' . rawurlencode($this->doc),
            array_merge(
                $this->rev === NULL ? array() : array('rev' => $this->rev),
                $this->attachments !== TRUE ? array() : array ('attachments' => TRUE)
            )
        );
        if (isset($this->data->_rev)) $this->rev = $this->data->_rev;
        return $this->data;
    }

    /**
     * Save document
     * @return stdClass
     */
    protected function docsave()
    {
        $body = $this->data;
        if ($this->rev !== NULL) $body->_rev = $this->rev;
        $ret = $this->connector->request(
            $this->doc === NULL ? CouchDBConnector::POST : CouchDBConnector::PUT,
            '/' . rawurlencode($this->db) . 
                ($this->doc !== NULL ? '/' . rawurlencode($this->doc) : ''),
            array(),
            $body
        );

        if (isset($ret->ok) && $ret->ok) {
            $this->id = $this->data->_id = $ret->id;
            $this->rev = $this->data->_rev = $ret->rev;
        }

        return $ret;
    }

    /**
     * Delete document
     * @return stdClass
     */
    protected function docdelete()
    {
        return $this->connector->request(
            CouchDBConnector::DELETE,
            '/' . rawurlencode($this->db) . '/' . rawurlencode($this->doc),
            array('rev' => $this->rev)
        );
    }

    /**
     * Get attachment
     * @param string
     * @return CouchDBAttachmentAPI
     */
    protected function docattachment($attachment)
    {
        $clone = clone $this;
        $clone->attachment = $attachment;
        $clone->lvl = self::ATTACHMENTLVL;
        return $clone;
    }

    /***** ATTACHMENT *****/

    /**
     * (Re)Load attachment data
     * @return stdClass data; NULL on failure
     */
    protected function attachmentload()
    {
        return $this->data = $this->connector->request(
            CouchDBConnector::GET,
            '/' . rawurlencode($this->db) . '/' . rawurlencode($this->doc) . 
                '/' . rawurlencode($this->attachment),
            $this->rev === NULL ? array() : array('rev' => $this->rev),
            NULL,
            TRUE
        );
    }

    /**
     * Save document
     * @return stdClass
     */
    protected function attachmentsave()
    {
        $body = $this->data->data;
        $headers = array('Content-Type' => $this->data->content_type);
        return $this->connector->request(
            CouchDBConnector::PUT,
            '/' . rawurlencode($this->db) . '/' . rawurlencode($this->doc) . 
                '/' . rawurlencode($this->attachment),
            array('rev' => $this->rev),
            $body,
            TRUE,
            $headers
        );
    }

    /**
     * Delete document
     * @return stdClass
     */
    protected function attachmentdelete()
    {
        return $this->connector->request(
            CouchDBConnector::DELETE,
            '/' . rawurlencode($this->db) . '/' . rawurlencode($this->doc) .
                '/' . rawurlencode($this->attachment),
            array('rev' => $this->rev)
        );
    }

    /***** DATA ACCESS *****/

    /**
     * Get data under key
     * @param string 
     * @return mixed
     */
    public function &__get($name)
    {
        if ($this->data === NULL) $this->load();
        return $this->data->$name;
    }

    /**
     * Set data under key
     * @param string
     * @param mixed
     */
    public function __set($name, $value)
    {
        if ($this->data === NULL) $this->data = (object) array();
        $this->data->$name = $value;
        if ($name === '_id') $this->doc = $value;
        if ($name === '_rev') $this->rev = $value;
    }

    /**
     * Isset data
     * @param string
     * @return bool
     */
    public function __isset($name)
    {
        return isset($this->data->$name);
    }

    /**
     * Unset data
     * @param string
     */
    public function __unset($name)
    {
        unset($this->data->$name);
    }

    /***** PUBLIC *****/

    /**
     * Get attachment
     * @param string
     * @return CouchDBAttachmentAPI
     */
    public function attachment($attachment)
    {
        switch ($this->lvl) {
            case self::DOCLVL:
                return $this->docattachment($attachment);
            default:
                trigger_error('attachment() not available in ' . $this->lvl . ' level', E_USER_ERROR);
        }
    }

    /**
     * Bulk documents API
     * @param array
     * @return stdClass
     */
    public function bulk(array $docs)
    {
        switch ($this->lvl) {
            case self::DBLVL:
                return $this->dbbulk($docs);
            default:
                trigger_error('bulk() not available in ' . $this->lvl . ' level', E_USER_ERROR);
        }
    }

    /**
     * Compact database
     * @return stdClass
     */
    public function compact()
    {
        switch ($this->lvl) {
            case self::DBLVL:
                return $this->dbcompact();
            default:
                trigger_error('compact() not available in ' . $this->lvl . ' level', E_USER_ERROR);
        }
    }

    /**
     * Server config
     * @return stdClass
     */
    public function config()
    {
        switch ($this->lvl) {
            case self::SRVLVL:
                return $this->srvconfig();
            default:
                trigger_error('config() not available in ' . $this->lvl . ' level', E_USER_ERROR);
        }
    }

    /**
     * Create database
     * @return stdClass
     */
    public function create()
    {
        switch ($this->lvl) {
            case self::DBLVL:
                return $this->dbcreate();
            default:
                trigger_error('create() not available in ' . $this->lvl . ' level', E_USER_ERROR);
        }
    }

    /**
     * Get database
     * @return CouchDBDatabaseAPI
     */
    public function db($db)
    {
        switch ($this->lvl) {
            case self::SRVLVL:
                return $this->srvdb($db);
            default:
                trigger_error('db() not available in ' . $this->lvl . ' level', E_USER_ERROR);
        }
    }

    /**
     * List all databases
     * @return array
     */
    public function dbs()
    {
        switch ($this->lvl) {
            case self::SRVLVL:
                return $this->srvdbs();
            default:
                trigger_error('dbs() not available in ' . $this->lvl . ' level', E_USER_ERROR);
        }
    }

    /**
     * Delete resource
     * @return stdClass
     */
    public function delete()
    {
        switch ($this->lvl) {
            case self::DBLVL:
                return $this->dbdelete();
            case self::DOCLVL:
                return $this->docdelete();
            case self::ATTACHMENTLVL:
                return $this->attachmentdelete();
            default:
                trigger_error('delete() not available in ' . $this->lvl . ' level', E_USER_ERROR);
        }
    }

    /**
     * Get document
     * @param string
     * @param string
     * @param bool load attachments?
     * @return CouchDBDocumentAPI
     */
    public function doc($id = NULL, $rev = NULL, $attachments = FALSE)
    {
        switch ($this->lvl) {
            case self::DBLVL:
                return $this->dbdoc($id, $rev, $attachments);
            default:
                trigger_error('doc() not available in ' . $this->lvl . ' level', E_USER_ERROR);
        }
    }

    /**
     * View all documents
     * @param array
     * @return stdClass
     */
    public function docs(array $params = array())
    {
        switch ($this->lvl) {
            case self::DBLVL:
                return $this->dbdocs($params);
            default:
                trigger_error('docs() not available in ' . $this->lvl . ' level', E_USER_ERROR);
        }
    }

    /**
     * Return info
     * @return stdClass
     */
    public function info()
    {
        switch ($this->lvl) {
            case self::SRVLVL:
                return $this->srvinfo();
            case self::DBLVL:
                return $this->dbinfo();
            default:
                trigger_error('info() not available in ' . $this->lvl . ' level', E_USER_ERROR);
        }
    }

    /**
     * (Re)Load data
     * @return stdClass data; NULL on failure
     */
    public function load()
    {
        switch ($this->lvl) {
            case self::DOCLVL:
                return $this->docload();
            case self::ATTACHMENTLVL:
                return $this->attachmentload();
            default:
                trigger_error('load() not available in ' . $this->lvl . ' level', E_USER_ERROR);
        }
    }

    /**
     * Query database (temporary view)
     * @param string map function
     * @param string reduce function
     * @param array
     * @param string
     * @return stdClass
     */
    public function query($map, $reduce = NULL, array $params = array(), $language = 'javascript')
    {
        switch ($this->lvl) {
            case self::DBLVL:
                return $this->dbquery($map, $reduce, $params, $language);
            default:
                trigger_error('query() not available in ' . $this->lvl . ' level', E_USER_ERROR);
        }
    }

    /**
     * Replicate database source to target
     * @param string
     * @param string
     * @return stdClass
     */
    public function replicate($source, $target)
    {
        switch ($this->lvl) {
            case self::SRVLVL:
                return $this->srvreplicate($source, $target);
            default:
                trigger_error('replicate() not available in ' . $this->lvl . ' level', E_USER_ERROR);
        }
    }

    /**
     * Save
     * @return stdClass
     */
    public function save()
    {
        switch ($this->lvl) {
            case self::DOCLVL:
                return $this->docsave();
            case self::ATTACHMENTLVL:
                return $this->attachmentsave();
            default:
                trigger_error('save() not available in ' . $this->lvl . ' level', E_USER_ERROR);
        }
    }

    /**
     * Server stats
     * @return stdClass
     */
    public function stats()
    {
        switch ($this->lvl) {
            case self::SRVLVL:
                return $this->srvstats();
            default:
                trigger_error('stats() not available in ' . $this->lvl . ' level', E_USER_ERROR);
        }
    }

    /**
     * Generate some UUIDs
     * @param int
     * @return array
     */
    public function uuids($count = NULL)
    {
        switch ($this->lvl) {
            case self::SRVLVL:
                return $this->srvuuids($count);
            default:
                trigger_error('uuids() not available in ' . $this->lvl . ' level', E_USER_ERROR);
        }
    }

    /**
     * Permanent view
     * @param string design document ID
     * @param string view name
     * @param array
     * @return stdClass
     */
    public function view($design, $view, array $params = array())
    {
        switch ($this->lvl) {
            case self::DBLVL:
                return $this->dbview($design, $view, $params);
            default:
                trigger_error('view() not available in ' . $this->lvl . ' level', E_USER_ERROR);
        }
    }
}
