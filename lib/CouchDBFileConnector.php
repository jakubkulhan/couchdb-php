<?php
require_once dirname(__FILE__) . '/CouchDBConnector.php';
require_once dirname(__FILE__) . '/CouchDBBTree.php';

/**
 * CouchDB emulation in PHP
 */
final class CouchDBFileConnector implements CouchDBConnector
{
    /**
     * Not implemented
     */
    const NOT_IMPLEMENTED = 'not_implemented';

    /**
     * Method not allowed
     */
    const METHOD_NOT_ALLOWED = 'method_not_allowed';

    /**
     * Default chmod
     */
    const CHMOD = 0777;

    /**
     * @var string Working directory
     */
    private $dir;

    /**
     * @var array Opened dabases data BTrees
     */
    private $opened = array();

    /**
     * @var CouchDBBTree BTree to be emitted to
     */
    private $emit_btree;

    /**
     * @var string Currently working on document with ID
     */
    private $emit_docid;

    /**
     * Open connector to given directory; if directory does not exists, it is created
     * @param string
     * @return CouchDBFileConnector|bool FALSE on failure
     */
    public static function open($url)
    {
        if (!($dir = @parse_url($url, PHP_URL_PATH))) return FALSE;
        if ((!is_dir($dir) && !mkdir($dir, self::CHMOD, TRUE))) return FALSE;
        $dir = realpath($dir);
        return new self($dir);
    }

    /**
     * Initialize
     * @param string
     */
    private function __construct($dir)
    {
        $this->dir = $dir;
    }

    /**
     * Get welcome message
     * @return stdClass
     */
    private function get_welcome()
    {
        return (object) array(
            'couchdb' => 'Welcome',
            'version' => 'php'
        );
    }

    /**
     * Get stats
     * @return stdClass
     */
    private function get_stats()
    {
        return $this->error(self::NOT_IMPLEMENTED);
    }

    /**
     * Get configuration
     * @return stdClass
     */
    private function get_config()
    {
        return $this->error(self::NOT_IMPLEMENTED);
    }

    /**
     * Generate some uuids
     * @param array
     * @return stdClass
     */
    private function get_uuids($_, $query)
    {
        $count = 1;
        if (isset($query['count'])) $count = intval($query['count']);
        if ($count < 0) return $this->error('unknown_error', 'function_clause');
        $response = array('uuids' => array());
        while($count--) $response['uuids'][] = $this->generate_uuid();
        return (object) $response;
    }

    /**
     * Replicate
     * @param array
     * @retrun stdClass
     */
    private function post_replicate($_, $query)
    {
        return $this->error(self::NOT_IMPLEMENTED);
    }

    /**
     * Get list of all databases
     * @return array
     */
    private function get_all_dbs()
    {
        $dbs = array();
        if (!($glob = glob($this->dir . '/*', GLOB_ONLYDIR))) return $this->error('cannot_read');
        foreach ($glob as $db) $dbs[] = rawurldecode(basename($db));
        return $dbs;
    }

    /**
     * Get database info
     * @return stdClass
     */
    private function get_db($db)
    {
        if (!is_dir($this->dir . '/' . $db)) return $this->error('not_found', 'no_db_file');
        if (!$this->try_open($db . '/data')) return $this->error('cannot_open');
        return (object) array(
            'db_name' => rawurldecode($db),
            'doc_count' => $this->opened[$db . '/data']->total()
        );
    }

    /**
     * Create database
     * @return stdClass
     */
    private function put_db($db)
    {
        $dir = $this->dir . '/' . $db;
        if (is_dir($dir)) return $this->error('file_exists', 'The database could not be created, the file already exists.');
        if (!mkdir($dir, self::CHMOD, FALSE)) return $this->error('cannot_mkdir');
        if (CouchDBBTree::open($dir . '/data') === FALSE || CouchDBBTree::open($dir . '/attachments') === FALSE) {
            @rename($dir, $dir . '.' . $this->generate_uuid());
            return $this->error('cannot_open');
        }
        return (object) array(
            'ok' => true
        );
    }

    /**
     * Create database document
     * @param string
     * @param array
     * @param array
     * @return stdClass
     */
    private function post_db($db, $_, array $body)
    {
        return $this->save_doc($db, NULL, NULL, $body);
    }

    /**
     * Delete database
     * @param string
     * @return stdClass
     */
    private function delete_db($db)
    {
        unset($this->opened[$db . '/data']);
        $dir = $this->dir . '/' . $db;
        if (is_dir($dir) && !@rename($dir, $dir . '.' . $this->generate_uuid())) return $this->error('cannot_delete');
        return (object) array(
            'ok' => true
        );
    }

    /**
     * Compact database
     * @param string
     * @return stdClass
     */
    private function post_db_compact($db)
    {
        if (!$this->try_open($db . '/data')) return (object) array(
            'error' => 'error',
            'reason' => 'cannot_open'
        );
        if (!$this->opened[$db . '/data']->compact()) return (object) array(
            'error' => 'error',
            'reason' => 'cannot_compact'
        );
        return (object) array(
            'ok' => true
        );
    }

    /**
     * Formats view params
     * @param array
     * @return array|stdClass stdClass with error on failure
     */
    private function format_view_params($query)
    {
        // supported parameters
        static $view_params = array(
        //  'name'          => array(type,      default,    encode?),
            'key'           => array('string',  NULL,       TRUE),
            'keys'          => array('array',   array(),    FALSE),
            'startkey'      => array('string',  "\x00",     TRUE),
        //  'startkey_docid'=> array('string',  "\x00",     FALSE),
            'endkey'        => array('string',  "\xff",     TRUE),
        //  'endkey_docid'  => array('string',  "\xff",     FALSE),
            'limit'         => array('integer', -1,         FALSE),
            'stale'         => array('string',  'no',       FALSE),
            'descending'    => array('boolean', FALSE,      FALSE),
            'skip'          => array('integer', -1,         FALSE),
            'reduce'        => array('boolean', TRUE,       FALSE),
            'include_docs'  => array('boolean', FALSE,      FALSE),
        );

        // format them from query
        $ret = array();
        foreach ($view_params as $name => $_) {
            list($type, $default, $encode) = $_;
            if (!isset($query[$name])) $value = $default;
            else {
                if ($encode) $query[$name] = json_encode($query[$name]);
                $value = $query[$name];
                if (!settype($value, $type)) return $this->error($name);
            }
            $ret[$name] = $value;
            unset($query[$name]);
        }

        // anything left?
        if (!empty($query)) return $this->error(implode(', ', array_keys($query)));

        // keys fixes
        if ($ret['key'] !== NULL) {
            $ret['keys'][] = $ret['key'];
        }
        unset($ret['key']);

        // descending key fixes
        if ($ret['descending']) {
            if ($ret['startkey'] === "\x00") $ret['startkey'] = "\xff";
            if ($ret['endkey']   === "\xff") $ret['endkey']   = "\x00";
            list($ret['startkey'], $ret['endkey']) = array($ret['endkey'], $ret['startkey']);
        }

        return $ret;
    }

    /**
     * Get all docs from database
     * @param string
     * @param array
     * @return stdClass
     */
    private function get_db_all_docs($db, $query)
    {
        $params = $this->format_view_params($query);
        if (!is_array($params)) return $params;
        extract($params);
        if ($params['startkey']{0} === '"') $params['startkey'] = json_decode($params['startkey']);
        if ($params['endkey']{0} === '"') $params['endkey'] = json_decode($params['endkey']);
        unset($params['startkey_docid'], $params['endkey_docid']);

        if (!$this->try_open($db . '/data')) return $this->error('cannot_open');
        $btree = $this->opened[$db . '/data'];
        return $this->query_data($btree, $btree, $params, TRUE);
    }

    /**
     * Get all docs by seq
     * @return stdClass
     */
    private function get_db_all_docs_by_seq()
    {
        return $this->error(self::NOT_IMPLEMENTED);
    }

    /**
     * Bulk documents API
     * @param string
     * @param array
     * @param array
     * @return stdClass
     */
    private function post_db_bulk_docs($db, $_, $body)
    {
        if (isset($body['all_or_nothing']) && $body['all_or_noting']) 
            return $this->error('all_or_nothing not implemented');
        if (!(isset($body['docs']) && is_array($body['docs']))) 
            return $this->error('unknown_error', 'function_clause');
        $ret = array();

        foreach ($body['docs'] as $doc) {
            if (!isset($doc['_id'])) return $this->error('invalid_json', '');
            $save = $this->save_doc($db, $doc['_id'], isset($doc['_rev']) ? $doc['_rev'] : NULL, $doc);
            unset($save->ok);
            $save->id = $doc['_id'];
            $ret[] = $save;
        }

        return $ret;
    }

    /**
     * Permanent view
     * @param array
     * @param array
     * @return stdClass
     */
    private function get_db_design_view($spec, $query)
    {
        list($db, $designname, $viewname) = $spec;
        $doc = $this->get_doc(array($db, '_design/' . $designname), array());
        if (isset($doc->error)) return $this->error('not_found', 'missing');
        if (!(isset($doc->language) && $doc->language === 'php')) return $this->error('language_not_supported');
        if (!isset($doc->views->$viewname)) return $this->error('no_view');
        return $this->post_db_temp_view($db, $query, array_merge(
            array('language' => $doc->language),
            $this->arrayize($doc->views->$viewname)
        ));
    }

    /**
     * Temporary view
     * @param string
     * @param array
     * @param array
     * @return stdClass
     */
    private function post_db_temp_view($db, $query, $body)
    {
        // get functions
        if (!isset($body['language'])) return $this->error('language');
        if ($body['language'] !== 'php') return $this->error('language_not_supported');
        if (!isset($body['map'])) return $this->error('map');

        list($map_params, $map_body) = $this->parse_function($body['map']);
        if ($map_params === NULL || $map_body === NULL) return $this->error('map_code');

        if (isset($body['reduce'])) {
            list($reduce_params, $reduce_body) = $this->parse_function($body['reduce']);
            if ($reduce_params === NULL || $reduce_body === NULL) return $this->error('reduce_code');
        } else list($reduce_params, $reduce_body) = array(NULL, NULL);

        // get params
        $params = $this->format_view_params($query);
        if (!is_array($params)) return $params;
        extract($params);

        // regenerate if neccessary
        if ($stale !== 'ok') {
            $ok = $this->regenerate_view($db, array($map_params, $map_body), array($reduce_params, $reduce_body));
            if ($ok !== TRUE) return $ok;
        }

        // data
        $type = 'map';
        if ($reduce_params !== NULL && $reduce_body !== NULL && $reduce) $type = 'reduce';
        $hash = $this->{$type . 'hash'}(
            array($map_params, $map_body), 
            array($reduce_params, $reduce_body)
        );
        if (!$this->try_open($db . '/data')) return $this->error('cannot_open');
        if (!$this->try_open($db . '/' . $hash)) return $this->error('cannot_open');
        return $this->query_data($this->opened[$db . '/data'], $this->opened[$db . '/' . $hash], $params);
    }

    /**
     * Get doc
     * @param array
     * @param array
     * @return stdClass
     */
    private function get_doc($spec, $query)
    {
        list($db, $doc) = $spec;
        if (!$this->try_open($db . '/data')) return $this->error('cannot_open');
        if (($data = $this->opened[$db . '/data']->get($doc)) === NULL) return $this->error('not_found', 'missing');
        if (isset($data['_deleted']) && $data['_deleted']) return $this->error('not_found', 'deleted');
        if (isset($query['attachments']) && $query['attachments']) {
            if (!$this->try_open($db . '/attachments')) return $this->error('cannot_open');
            $attachments = $this->opened[$db . '/attachments']->range($doc . '/', $doc . "/\xff");
            if ($attachments === NULL) return $this->error('cannot_read');
            foreach ($attachments as $k => $v) $data['_attachments'][substr($k, strlen($doc . '/'))] = $v;
        }
        return $this->stdclassize($data);
    }

    /**
     * Save doc
     * @param array
     * @param array
     * @param array
     * @return stdClass
     */
    private function put_doc($spec, $_, $body)
    {
        list($db, $doc) = $spec;
        return $this->save_doc($db, $doc, NULL, $body);
    }

    /**
     * Delete doc
     * @param array
     * @param array
     * @return stdClass
     */
    private function delete_doc($spec, $query)
    {
        list($db, $doc) = $spec;
        if (!$this->try_open($db . '/data')) return $this->error('cannot_open');
        if (($data = $this->opened[$db . '/data']->get($doc)) === NULL) 
            return $this->error('not_found', 'missing');
        if (!isset($query['rev'])) 
            return $this->error('conflict', 'Document update conflict.');
        $data['_deleted'] = TRUE;
        return $this->save_doc($db, $doc, NULL, $data);
    }
    /**
     * Get attachment
     * @param array
     * @return stdClass
     */
    private function get_attachment($spec)
    {
        list($db, $doc, $attachment) = $spec;
        if (!$this->try_open($db . '/attachments')) return $this->error('cannot_open');
        if (($data = $this->opened[$db . '/attachments']->get($doc . '/' . $attachment))
            === NULL) return $this->error('not_found', 'missing');
        return self::stdclassize($data);
    }

    /**
     * Save attachment
     * @param array
     * @param array
     * @param string
     * @return stdClass
     */ 
    private function put_attachment($spec, $_, $body, $headers)
    {
        list($db, $docname, $attachment) = $spec;
        if (!isset($headers['content-type'])) return $this->error('no_content_type');
        if (!$this->try_open($db . '/data')) return $this->error('cannot_open');
        if (!$this->try_open($db . '/attachments')) return $this->error('cannot_open');
        $data = array(
            'content_type' => $headers['content-type'],
            'data' => $body
        );
        if (!$this->opened[$db . '/attachments']->set($docname . '/' . $attachment, $data)) 
            return $this->error('cannot_write');

        $doc = $this->opened[$db . '/data']->get($docname);
        if ($doc === NULL) return $this->error('not_found', 'missing');
        $doc['_attachments'][$attachment] = array(
            'stub' => TRUE,
            'content_type' => $headers['content-type'],
            'length' => strlen($body)
        );
        return $this->save_doc($db, $docname, NULL, $doc);
    }

    /**
     * Delete attachment
     * @param array
     * @param array
     * @param string
     * @return stdClass
     */ 
    private function delete_attachment($spec, $query)
    {
        list($db, $docname, $attachment) = $spec;
        if (!$this->try_open($db . '/data')) return $this->error('cannot_open');
        if (!$this->try_open($db . '/attachments')) return $this->error('cannot_open');

        $doc = $this->opened[$db . '/data']->get($docname);
        if ($doc === NULL) return $this->error('not_found', 'missing');
        if (!(isset($query['rev']) && $query['rev'] === $doc['_rev'])) 
            return $this->error('conflict', 'Document update conflict');
        unset($doc['_attachments'][$attachment]);
        if (empty($doc['_attachments'])) unset($doc['_attachments']);

        $ret = $this->save_doc($db, $docname, $query['rev'], $doc);
        if (!$this->opened[$db . '/attachments']->set($docname . '/' . $attachment, NULL)) 
            return $this->error('cannot_write');
        return $ret;
    }

    /**
     * Query data from btree
     * @param CouchDBBTree
     * @param CouchDBBTree
     * @param array formated params (@see format_view_params())
     * @param bool construct row object (_all_docs)
     * @return stdClass
     */ 
    private function query_data($dbbtree, $btree, array $params, $raw = FALSE)
    {
        extract($params);
        $ret = (object) array(
            'total_rows' => $btree->total(),
            'rows' => array()
        );

        $docs = array();
        if (!empty($keys)) foreach ($keys as $key) {
            $doc = $btree->get($key);
            if ($doc !== NULL) $docs[] = $doc;
        } else {
            $docs = $btree->range($startkey, $endkey);
            if ($docs === NULL) return $this->error('cannot_read');
            if ($descending) $docs = array_reverse($docs);
        }

        if ($skip >= 0) $docs = array_slice($docs, $skip);
        if ($limit >= 0) $docs = array_slice($docs, 0, $limit);
        while (($doc = array_shift($docs))) {
            if (isset($doc['_deleted']) && $doc['_deleted']) continue;
            if ($raw) {
                $row = array(
                    'id' => $doc['_id'],
                    'key' => $doc['_id'],
                    'value' => array('rev' => $doc['_rev'])
                );
                if ($include_docs) $row['doc'] = $doc;
            } else {
                $row = $doc;
                if ($include_docs && isset($row['id'])) $row['doc'] = $dbbtree->get($row['id']);
            }
            $ret->rows[] = self::stdclassize($row);
        }

        return $ret;

    }

    /**
     * Regenerates view if neccessary
     * @param array map (params, body) pair
     * @param array reduce (params, body) pair
     * @return bool TRUE if does not need regeneration or regenerated successfully
     */
    private function regenerate_view($db, array $map, array $reduce)
    {
        $data_btree = $db . '/data';
        $data_mtime = filemtime($this->dir . '/' . $data_btree);

        // map
        $map_btree = $db . '/' . $this->maphash($map);
        $map_mtime = (int) @filemtime($this->dir . '/' . $map_btree);
        $force_reduce_regenerate = FALSE;
        if ($data_mtime > $map_mtime) {

            $force_reduce_regenerate = TRUE;
            if (!$this->try_open($map_btree)) return $this->error('cannot_open');
            if (!$this->opened[$map_btree]->lock()) return $this->error('cannot_write');

            $mapfn = create_function($map[0] . ',$__emit', $map[1]);
            if (!$mapfn) return $this->error('map');

            if (!$this->try_open($data_btree)) return $this->error('cannot_open');
            $leaves = $this->opened[$data_btree]->leaves($map_mtime);
            if ($leaves === NULL) return $this->error('cannot_read');

            $this->emit_btree = $this->opened[$map_btree];
            foreach ($leaves as $leaf) {
                list(,$docs) = $this->opened[$data_btree]->node($leaf);
                if ($docs === NULL) return $this->error('cannot_read');
                foreach ($docs as $k => $doc) {
                    if ($k === CouchDBBTree::MODKEY) continue;
                    if (isset($doc['_deleted']) && $doc['_deleted']) continue;
                    $this->emit_docid = $doc['_id'];
                    $mapfn($doc, array($this, 'emit'));
                }
                unset($data); // free resources
            }

            $this->emit_btree = NULL;
            $this->emit_docid = NULL;
            $this->opened[$map_btree]->unlock();
        }

        // reduce
        if ($reduce[0] === NULL || $reduce[1] === NULL) return TRUE;
        $reduce_btree = $db . '/' . $this->reducehash($map, $reduce);
        $reduce_mtime = (int) @filemtime($this->dir . '/' . $reduce_btree);
        if ($map_mtime > $reduce_mtime || $force_reduce_regenerate) {
            if (!$this->try_open($map_btree)) return $this->error('cannot_open');
            if (file_exists($this->dir . '/' . $reduce_btree) && !@unlink($this->dir . '/' . $reduce_btree)) return $this->error('cannot_write');
            if (!$this->try_open($reduce_btree)) return $this->error('cannot_open');
            if (!$this->opened[$reduce_btree]->lock()) return $this->error('cannot_write');

            $redfn = create_function($reduce[0], $reduce[1]);
            if (!$redfn) return $this->error('reduce');

            $leaves = $this->opened[$map_btree]->leaves();
            if ($leaves === NULL) return $this->error('cannot_read');

            foreach ($leaves as $leaf) {

                list(,$docs) = $this->opened[$map_btree]->node($leaf);
                if ($docs === NULL) return $this->error('cannot_read');

                $first = TRUE;
                $last = FALSE;
                $current = NULL;
                $keys = array();
                $values = array();

                do {
                    if (current(array_keys($docs)) === CouchDBBTree::MODKEY) {
                        array_shift($docs);
                        continue;
                    }
                    $_ = array_shift($docs);
                    if ($_ === NULL) {
                        $_['key'] = !((bool) $current);
                        $last = TRUE;
                    }

                    if ($_['key'] !== $current && !$first) {
                        $real_key = json_encode($this->binarizeints($current));

                        $previous = $this->opened[$reduce_btree]->get($real_key);
                        if ($previous !== NULL) {
                            $tmp = $redfn($keys, $values, FALSE);
                            $res = $redfn(NULL, array($tmp, $previous['value']), TRUE);
                        } else $res = $redfn($keys, $values, FALSE);

                        $this->opened[$reduce_btree]->set($real_key, array(
                            'key' => $current,
                            'value' => $res
                        ));

                        $keys = array();
                        $values = array();
                    }

                    if ($last) break;

                    $current = $_['key'];
                    $keys[] = array($_['key'], $_['id']);
                    $values[] = $_['value'];
                    $first = FALSE;
                } while (!empty($data) || !$last);
            }
        }

        return TRUE;
    }

    /**
     * Emit data
     * @param mixed
     * @param mixed
     */
    public function emit($k, $v)
    {
        $real_key = json_encode($this->binarizeints($k)) . "\xff" . $this->emit_docid;
        $this->emit_btree->set($real_key, array(
            'id' => $this->emit_docid,
            'key' => $k,
            'value' => $v
        ));
    }

    /**
     * Binarize integers
     * @param mixed
     * @return mixed
     */
    private function binarizeints($x)
    {
        if (is_array($x)) foreach ($x as &$_) $_ = $this->binarizeints($_);
        else if (is_int($x)) $x = "\x00i:" . pack('N', $x);
        return $x;
    }

    /**
     * Debinarize integers
     * @param mixed
     * @return mixed
     */
    private function debinarizeints($x)
    {
        if (is_array($x)) foreach ($x as &$_) $_ = $this->debinarizeints($_);
        else if (is_string($x) && strncmp($x, "\x00i:", 3) === 0) list(,$x) = unpack('N', substr($x, 3));
        return $x;
    }

    /**
     * Get hash of map function
     * @param array
     * @return string
     */ 
    private function maphash(array $map)
    {
        return $this->fnhash($map);
    }

    /**
     * Get hash of reduce function
     * @param array
     * @retrun string
     */ 
    private function reducehash(array $map, array $reduce)
    {
        return $this->fnhash(array($map[0] . ', ' . $reduce[0], $map[1] . ' ' . $reduce[1]));
    }

    /**
     * Computes hash of (params, body) pair
     * @param array (params, body)
     * @return string
     */
    private function fnhash(array $fn)
    {
        return md5('function(' . $fn[0] . '){' . $fn[1] . '}');
    }

    /**
     * Parses parameters list and function body from given string
     * @param string
     * @return array 0 => params, 1 => body
     */
    private function parse_function($fn)
    {
        if (!defined('T_DOC_COMMENT')) define('T_DOC_COMMENT', -1);
        if (!defined('T_ML_COMMENT')) define('T_ML_COMMENT', -1);
        $set = array_flip(preg_split('//', '!"#$&\'()*+,-./:;<=>?@[\]^`{|}'));
        $space = $output = $null = $params = $body = '';
        $in_def = $in_params = $in_body = $emit = $done = FALSE;
        $level = 0;
        $next =& $null;
        foreach (token_get_all('<?php ' . $fn) as $token) {
            if ($done) break;
            if (!is_array($token)) $token = array(0, $token);
            switch ($token[0]) {
                case T_COMMENT: 
                case T_ML_COMMENT: 
                case T_DOC_COMMENT: 
                case T_WHITESPACE:
                    $space = ' ';
                break;
                default:
                    $output =& $next;
                    if ($token[0] === T_FUNCTION) $in_def = TRUE;
                    if ($in_def && empty($params) && $token[1] === '(') {
                        $in_params = TRUE;
                        $next =& $params;
                    }
                    if ($in_params && $token[1] === ')') {
                        $in_params = FALSE;
                        $next =& $null;
                        $output =& $next;
                    }
                    if ($in_def && !$in_params && empty($body) && $token[1] === '{') {
                        $in_body = TRUE;
                        $next =& $body;
                        $level++;
                    }
                    if ($in_body && !empty($body) && $token[1] === '{') $level++;
                    if ($in_body && $token[1] === '}') $level--;
                    if ($in_body && $level < 1) {
                        $done = TRUE;
                        break;
                    }
                    if ($in_body && $token[1] === 'emit') {
                        $emit = TRUE;
                        $token[1] = 'call_user_func($__emit,';
                    }
                    if ($emit && $token[1] === '(') {
                        $emit = FALSE;
                        $token[1] = '';
                    }
                    if (isset($set[substr($output, -1)]) ||
                        (isset($token[1]{0}) && isset($set[$token[1]{0}])) ||
                        $in_body && empty($body)) $space = '';
                    $output .= $space . $token[1];
                    $space = '';
            }
        }

        return array($params, $body);
    }

    /**
     * Save document into database
     * @param string
     * @param string
     * @param string
     * @param array
     * @return stdClass
     */
    private function save_doc($db, $id = NULL, $rev = NULL, array $body = array())
    {
        // init
        if ($id === NULL) $id = $this->generate_uuid();
        if ($rev === NULL) {
            if (isset($body['_rev'])) $rev = $body['_rev'];
            else $rev = '0';
        }

        // check
        if ($id{0} === "\xff") return $this->error('bad_id');

        // get db
        if (!$this->try_open($db . '/data')) return $this->error('cannot_open');
        $db = $this->opened[$db . '/data'];
        if (!$db->lock()) return $this->error('cannot_write');

        // conflict
        if (($doc = $db->get($id)) !== NULL && $doc['_rev'] !== $rev) {
            $db->unlock();
            return $this->error('conflict', 'Document update conflict.');
        }

        // save
        unset($body['_id'], $body['_rev']);
        $body = array_merge(array(
            '_id' => $id,
            '_rev' => ($rev = ((string) (intval($rev) + 1)))
        ), $body);
        $ok = $db->set($id, $body);
        $db->unlock();

        if (!$ok) return $this->error('cannot_write');

        return (object) array(
            'ok' => 'true',
            'id' => $id,
            'rev' => $rev
        );
    }

    /**
     * Returns formated error
     * @return stdClass
     */
    public function error()
    {
        if (func_num_args() === 0) list($error, $reason) = array('error', '');
        else if (func_num_args() === 1) list($error, $reason) = array('error', func_get_arg(0));
        else list($error, $reason) = func_get_args();
        return (object) array(
            'error' => $error,
            'reason' => $reason
        );
    }

    /**
     * Try to open database data file
     * @param string
     * @return bool
     */
    private function try_open($dbname)
    {
        if (isset($this->opened[$dbname])) return TRUE;
        if (($db = CouchDBBTree::open($this->dir . '/' . $dbname)) === FALSE) return FALSE;
        $this->opened[$dbname] = $db;
        return TRUE;
    }

    /**
     * Generate one random UUID
     * @return string
     */
    private function generate_uuid()
    {
        return md5(uniqid(mt_rand(), TRUE));
    }

    /**
     * Convert stdClasses to arrays
     * @param mixed
     * @return mixed
     */
    private function arrayize($x)
    {
        return json_decode(json_encode($x), TRUE);
    }

    /**
     * Convert associative arrays to stdClasses
     * @param mixed
     * @return mixed
     */
    private function stdclassize($x)
    {
        return json_decode(json_encode($x));
    }

    /**
     * Clear all nodecaches
     */
    public function clearcache()
    {
        foreach ($this->opened as $btree) $btree->clearcache();
    }

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
        $raw_body = FALSE, $headers = array())
    {
        // init
        $path = trim($path, '/');
        if ($body !== NULL && !$raw_body) $body = $this->arrayize($body);
        $new_headers = array();
        foreach ($headers as $k => $h) $new_headers[strtolower($k)] = $h;
        $headers = $new_headers;

        // welcome message
        if (empty($path)) {
            if ($method === CouchDBConnector::GET) return $this->get_welcome();
            return (object) array(
                'error' => self::METHOD_NOT_ALLOWED,
                'reason' => ''
            );
        }

        // route request
        $parts = explode('/', $path, 2);
        if (count($parts) === 1) {
            if ($parts[0]{0} === '_') { // server API
                $fn = $method . $parts[0];
                if (!method_exists($this, $fn)) {
                    if ($method === CouchDBConnector::GET || $method === CouchDBConnector::PUT) {
                        return $this->error('illegal_database_name');
                    } else return $this->error(self::METHOD_NOT_ALLOWED, '');
                }
            } else {
                $fn = $method . '_db';
                if (!method_exists($this, $fn)) return $this->error(self::METHOD_NOT_ALLOWED, '');
                if (!preg_match('~^[a-z][a-z0-9_$()+-/]+$~', rawurldecode($path))) 
                    return $this->error('illegal_database_name');
            }
            return $this->{$fn}($path, $query, $body, $headers);
        }

        $db = array_shift($parts);
        $parts = explode('/', array_shift($parts), 2);
        $doc = array_shift($parts);

        if (in_array($doc, array('_compact', '_all_docs', '_all_docs_by_seq', '_bulk_docs', '_temp_view'))) {
            $fn = $method . '_db' . $doc;
            if (!method_exists($this, $fn)) return $this->error(self::METHOD_NOT_ALLOWED, '');
            return $this->{$fn}($db, $query, $body, $headers);
        }

        if ($doc === '_design') {
            $action = explode('/', end($parts), 3);
            if (count($action) === 3 && $action[1]{0} === '_') {
                $fn = $method . '_db_design' . $action[1];
                if (!method_exists($this, $fn)) return $this->error(self::METHOD_NOT_ALLOWED, '');
                return $this->{$fn}(array($db, $action[0], $action[2]), $query, $body, $headers);
            } else {
                $doc = $doc . '%2F' . array_shift($action);
                $parts = array(implode('/', $action));
            }
        }

        $attachment = array_shift($parts);
        $doc = rawurldecode($doc);
        $attachment = $attachment === NULL ? NULL : rawurldecode($attachment);

        $fn = $method . '_' . ($attachment === NULL ? 'doc' : 'attachment');
        if (!method_exists($this, $fn)) return $this->error(self::METHOD_NOT_ALLOWED, '');
        return $this->{$fn}(array($db, $doc, $attachment), $query, $body, $headers);

        return $this->error(self::NOT_IMPLEMENTED, '');
    }
}
