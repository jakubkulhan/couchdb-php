<?php
require_once dirname(__FILE__) . '/CouchDBConnector.php';

/**
 * Connector using fsockopen() function
 */
class CouchDBFsockConnector implements CouchDBConnector
{
    /**
     * End of line
     */
    const EOL = "\r\n";

    /**
     * User agent
     */
    private $ua = 'CouchDBFsockConnector';

    /**
     * @var string Host
     */
    private $host = 'localhost';

    /**
     * @var int Port
     */
    private $port = 5984;

    /**
     * @var string Username
     */
    private $user = NULL;

    /**
     * @var string Password
     */
    private $pass = NULL;

    /**
     * @var string Base path
     */
    private $path = '/';

    /**
     * Create new instance
     * @pram string
     * @return CouchDBFsockConnector
     */
    public static function open($url)
    {
        return new self($url);
    }

    /**
     * Initialize state
     * @param string
     */
    private function __constuct($url)
    {
        foreach (parse_url($url) as $k => $v) $this->$k = $v;
        $this->path = rtrim($this->path, '/') . '/';
    }

    /**
     * Create socket
     * @param int
     * @param string
     * @return resource
     */
    protected function createSocket(&$errno, &$errstr)
    {
        return @fsockopen($this->host, $this->port, $errno, $errstr);
    }

    /**
     * Create request
     * @param string
     * @param string
     * @param string
     * @param array
     * @return string
     */
    protected function createRequest(&$request, $method, $path, $query = array())
    {
        $path = ltrim($path, '/');
        if (preg_match('~^.+/((_desing/.+/|_temp)_view|_all_docs)~', $path)) {
            foreach (array('key', 'startkey', 'endkey', 'limit', 'descending', 
                'skip', 'group', 'group_level', 'reduce', 'include_docs') as $k) 
            {
                if (!isset($query[$k])) continue;
                $query[$k] = json_encode($query[$k]);
            }
        }

        $request = $method . 
            ' ' . 
            $this->path . $path .
            (empty($query) ? '' : '?' . http_build_query($query, NULL, '&')) .
            ' ' . 
            'HTTP/1.1' . self::EOL;
    }

    /**
     * Add essential headers
     * @param string
     */
    protected function addEssentialHeaders(&$request)
    {
        $request .= 'Host: ' . $this->host . self::EOL;
        $request .= 'User-Agent: ' . $this->ua . self::EOL;
        $request .= 'Accept: */*' . self::EOL;
        $request .= 'Connection: close' . self::EOL;
    }

    /**
     * Add additional headers
     * @param string
     * @param array
     */
    protected function addAdditionalHeaders(&$request, array $headers = array())
    {
        foreach ($headers as $header => $value) 
            $request .= $header . ': ' . $value . self::EOL;    
    }

    /**
     * Authorization headers
     * @param string
     */
    protected function addAuthorizationHeaders(&$request)
    {
        if ($this->user !== NULL) 
            $request .= 'Authorization: Basic ' . 
                base64_encode($this->user . ':' . $this->pass) . 
                self::EOL;
    }

    /**
     * Add content
     * @param string
     * @param string
     * @param bool
     */
    protected function addContent(&$request, $body = NULL, $raw = FALSE)
    {
        if ($body !== NULL) {
            $json = FALSE;
            if (!$raw) {
                $json = TRUE;
                $body = json_encode($body);
            }
            $request .= 'Content-Length: ' . strlen($body) . self::EOL;
            if ($json) $request .= 'Content-Type: application/json' . self::EOL;
        }

        $request .= self::EOL;
        $request .= $body;
    }

    /**
     * Send request
     * @param resource
     * @param string
     */
    protected function sendRequest($socket, $request)
    {
        fwrite($socket, $request);
    }

    /**
     * Get response
     * @param resource
     */
    protected function getResponse($socket)
    {
        // get data
        $response = '';
        while (!feof($socket)) $response .= fread($socket, 4096);

        // get headers
        list($headers, $body) = explode(self::EOL . self::EOL, $response, 2);
        $real_headers = array();
        $return = NULL;
        foreach (explode(self::EOL, $headers) as $header) {
            if ($return === NULL) {
                $return = $header;
                continue;
            }
            list($k, $v) = explode(':', $header, 2);
            $real_headers[strtolower($k)] = trim($v);
        }

        // chunked?
        if (isset($real_headers['transfer-encoding']) && 
            $real_headers['transfer-encoding'] === 'chunked') 
        {
            $real_body = '';
            $tail = $body;
            do {
                list($head, $tail) = explode(self::EOL, $tail, 2);
                $size = hexdec($head);
                if ($size > 0) {
                    $real_body .= substr($tail, 0, $size);
                    $tail = substr($tail, $size + strlen(self::EOL));
                }
            } while ($size);

        } else $real_body = $body;

        return array($real_headers, $return, $real_body);
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
        if (!($s = $this->createSocket($errno, $errstr))) return (object) array(
            'error' => 'fsockopen', 
            'errstr' => $errstr, 
            'errno' => $errno
        );

        $this->createRequest($request, $method, $path, $query);
        $this->addEssentialHeaders($request);
        $this->addAdditionalHeaders($request, $headers);
        $this->addAuthorizationHeaders($request);
        $this->addContent($request, $body, $raw_body);
        $this->sendRequest($s, $request);
        list($headers, $return, $body) = $this->getResponse($s);
        fclose($s);

        if ($raw_body) return (object) array(
            'data' => $body,
            'content_type' => isset($headers['content-type']) ? $header['content-type'] : NULL
        );
        return json_decode($body);
    }
}
