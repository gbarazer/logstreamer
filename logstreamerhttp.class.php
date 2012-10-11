<?php
/* vim: set ts=8 sw=4 tw=0 syntax=on: */

/**
 * logStreamer on HTTP class
 * @author Olivier Doucet <odoucet@php.net>
 * @author Gabriel Barazer <gabriel@oxeva.fr>
 */
class logStreamerHttp
{
    const VERSION = '1.1 (2012-10-11)';
    protected $_input;
    protected $_stream;
    protected $_buffer;
    protected $_bufferLen;
    
    /**
     * @var bool Show debug informations or not.
     * This variable is voluntarily public.
     */
    public $debug;

    /**
     * @var array "buckets" to send. Already compressed
     */
    protected $_buckets;

    /**
     * @var string buffer containing the bucket being written
     */
    protected $_writeBuffer;

    /**
     * @var string buffer containing the server response after writing a bucket
     */
    protected $_responseBuffer;

    /**
     * @var int offset of the write buffer
     */
    protected $_writePos;
    
    /** 
     * @var int Size of all buffers aggregated
     */
    protected $_bucketsLen;
    
    /**
     * @var array Config options
     */
    protected $_config;
    
    /**
     * @var array Stats array
     */
    protected $_stats;

    /**
     * @var string remote stream descriptor
     */
    protected $_remoteStream;

    /**
     * @var string remote host name
     */
    protected $_remoteHost;

    /**
     * @var string remote URI
     */
    protected $_remoteUri;
    
    public function __construct($config)
    {
        $this->debug = false;
        $this->_stream = false;
        $this->_config = $config;
        $this->_bucketsLen = 0;
        $this->_buckets = array();
        $this->_buffer = '';
        $this->_bufferLen = 0;
        $this->_stats = array (
            'dataDiscarded' => 0, // bytes of data discarded due to memory limit
            'readErrors'         => 0, // errors reading data
            'writeErrors'        => 0, // errors when writing data to distant host
            'outputConnections'  => 0, // total connections to output
            'readBytes'          => 0, // bytes read from input
            'writtenBytes'       => 0, // bytes written to server
            'bucketsCreated'     => 0, // total number of buckets created
            'serverAnsweredNo200'=> 0, // how many times distant server answered != 200
        );

        $this->_config['maxMemory'] = self::humanToBytes($config['maxMemory']);
        $this->_config['readSize'] = self::humanToBytes($config['readSize']);
        $this->_config['writeSize'] = self::humanToBytes($config['writeSize']);

        // @todo handle HTTP authentication ?
        $remote = parse_url($config['remoteUrl']);
        $protocol = (array_key_exists('scheme', $remote) && $remote['scheme'] === 'https') ? 'ssl' : 'tcp';
        $this->_remoteHost = $remote['host'];
        $this->_remoteUri = (array_key_exists('path', $remote)) ? $remote['path'] : '/';
        $this->_remoteUri .= (array_key_exists('query', $remote)) ? '?' . $remote['query'] : '';
        if (array_key_exists('port', $remote)) {
            $port = (int) $remote['port'];
        } else {
            $port = ($protocol === 'ssl') ? 443 : 80;
        }

        $this->_remoteStream = $protocol . '://' . $this->_remoteHost . ':' . $port;

        if (!defined('STDIN')) {
            $this->_input = fopen('php://stdin', 'rb');
        } else {
            $this->_input = STDIN;
        }

        if (array_key_exists('debugSrc', $config) && $config['debugSrc'] !== null) {
            $this->_input = fopen($config['debugSrc'], 'rb');
        }

        stream_set_blocking($this->_input, 0);
        if ($this->debug) echo "Logstreamer Ready. maxMemory: ".$this->_config['maxMemory'].
            " readSize: ".$this->_config['readSize']." writeSize: ".$this->_config['writeSize']."\n";
    }

    /**
     * Check if we have to drop old buckets when we hit the memory limit.
     * @return int number of buckets dropped, should not be > 1
     */

    public function checkBucketLimit()
    {
        $dropCount = 0;
        while ($this->_bucketsLen > $this->_config['maxMemory']) {
            $dropBucket = array_shift($this->_buckets);
            $dropBucketLen = strlen($dropBucket);
            $this->_bucketsLen -= $dropBucketLen;
            $this->_stats['dataDiscarded'] += $dropBucketLen;
            unset($dropBucket, $dropBucketLen);
            $dropCount++;
        }
        return $dropCount;
    }
    
    /**
     * Read data from input
     * @return int|bool  false if EOF, else bytes read
     */
    public function read()
    {
        if (!is_resource($this->_input)) return false;

        if (feof($this->_input)) {
            fclose($this->_input);
            return false;
        }

        if (($str = @fread($this->_input, $this->_config['readSize'])) === false) {
            // WTF happened ?
            $this->_stats['readErrors']++;
            return false;
        }

        if (($len = strlen($str)) === 0) return 0;

        $this->_buffer .= $str;
        $this->_bufferLen += $len;
        $this->_stats['readBytes'] += $len;

        // Try to store data in a bucket after each read. May be optimized to not try after every single read call.
        $this->store();

        return $len;
    }

    /**
     * Store content into HTTP POST requests in a bucket list
     * @param bool force creating a bucket even if the buffer is smaller than the minimum bucket size.
     * @return int number of buckets created
     */
    public function store($force = false)
    {
        $bucketCount = 0;

        while (($force && $this->_bufferLen > 0) || $this->_bufferLen >= $this->_config['writeSize']) {
            if ($this->_config['binary'] === true) {
                $size = $this->_bufferLen;
            } else {
                $size = strrpos($this->_buffer, "\n");

                // Checking maximum line size
                if ($size === false) {
                    $size = $this->_bufferLen;
                } else {
                    // Don't forget to include the \n character in the line !!!
                    $size++;
                }
            }

            if ($this->_config['compression'] === true) {
                $bucket = gzencode(substr($this->_buffer, 0, $size), $this->_config['compressionLevel']);
                $bucketLen = strlen($bucket);
            } else {
                $bucket = substr($this->_buffer, 0, $size);
                $bucketLen = $size;
            }

            $this->_buffer = substr($this->_buffer, $size);
            $this->_bufferLen -= $size;

            $writeBuffer =
                'POST ' . $this->_remoteUri . ' HTTP/1.1' . "\r\n" .
                    'Host: ' . $this->_remoteHost . "\r\n" .
                    'User-Agent: logStreamerHttp ' . self::VERSION . "\r\n".
                    // XXX: Why not use a standard MIME type for log files ? something more like text/* ?
                    'Content-Type: text/x-log' . "\r\n";

            if ($this->_config['compression'] === true) {
                // RFC 2616 14.11 does not prohibit the use of Content-Encoding in HTTP requests
                $writeBuffer .= 'Content-Encoding: gzip' . "\r\n";
            }

            $writeBuffer .=
                'Content-Length: ' . $bucketLen . "\r\n".
                    'Connection: Close' . "\r\n" . // Disable Keep-Alive
                    "\r\n" . // End of HTTP headers
                    $bucket; // POST Data

            $this->_buckets[] = $writeBuffer;
            $this->_bucketsLen += strlen($writeBuffer);
            $this->_stats['bucketsCreated']++;
            $bucketCount++;
        }

        // New buckets have been stored, now check if we don't have too much. If so, drop the older ones.
        if ($bucketCount > 0) $this->checkBucketLimit();  

        return $bucketCount;
    }
    
    /**
     * @return bool|int false if error, else bytes written into writeBuffer
     */
    public function write()
    {
        // transform 'buckets' into HTTP post requests in the write queue

        if ($this->_writeBuffer === null) {
            $this->_writeBuffer = array_shift($this->_buckets);
            if ($this->_writeBuffer === null) return 0;
            $this->_writePos = 0;
            $this->_responseBuffer = null;
            $this->_bucketsLen -= strlen($this->_writeBuffer);
            if ($this->debug) echo "Inserting bucket in the write buffer (".strlen($this->_writeBuffer).
                " bytes), ".count($this->_buckets)." buckets remaining (".$this->_bucketsLen." bytes)\n";
        }

        if (is_resource($this->_stream) && feof($this->_stream)) {
            $this->_stats['writeErrors']++;
            fclose($this->_stream);
            $this->_stream = null;
        }

        if (!is_resource($this->_stream)) {

            // @see SSL over async sockets won't work because of bug #48182, see https://bugs.php.net/bug.php?id=48182

            if ($this->debug) echo "\nConnection to $this->_remoteStream\n";
            $this->_stream = stream_socket_client(
                $this->_remoteStream,
                $errno = null,
                $errstr = null,
                0,
                STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT
            );
            stream_set_blocking($this->_stream, 0);
            $this->_stats['outputConnections']++;
            $this->_writePos = 0;
            $this->_responseBuffer = null;
        }



        if ($this->_writePos < strlen($this->_writeBuffer) &&
            stream_select($r = array(), $w = array($this->_stream), $e = array(), 0) > 0) {

            $pos = fwrite($this->_stream, substr($this->_writeBuffer, $this->_writePos), $this->_config['writeSize']);
            if ($this->debug) echo "Wrote $pos bytes\n";

            if ($pos === 0) {
                $this->_stats['writeErrors']++;
                fclose($this->_stream);
                $this->_stream = null;
                return 0;
            }
            $this->_stats['writtenBytes'] += $pos;
            $this->_writePos += $pos;
        }

        if ($this->_writePos === strlen($this->_writeBuffer)) {
            if ($this->debug) echo "Write complete, now wait for server ACK before processing the next bucket...\n";

            if (stream_select($r = array($this->_stream), $w = array(), $e = array(), 0) > 0) {
                $this->_responseBuffer .= fread($this->_stream, 8192);
            }
            if ($responsePos = strpos($this->_responseBuffer, "\r\n\r\n")) {
                // HTTP response header found, ignoring the body
                $response = explode("\r\n", substr($this->_responseBuffer, 0, $responsePos));
                if (preg_match('/^HTTP\/[0-9]\.[0-9] 200 .*/', $response[0])) {
                    // We got our server positive ACK, moving on to the next bucket
                    if ($this->debug) echo "Got server ACK, processing next bucket...\n";
                    $this->_writeBuffer = null;
                } else {
                    // Server responded with an error, trying again the same bucket
                    $this->_stats['serverAnsweredNo200']++;
                    $this->_writePos = 0;
                    $this->_responseBuffer = null;
                }
                fclose($this->_stream);
                $this->_stream = null;
                return $this->_writePos;
            }
        }
    }

    /**
     * Return synchronously when the buckets list and the write queue are flushed
     * Note: we could also set the socket to blocking mode now to save a few CPU cycles.
     */
    public function flush()
    {
        $this->store(true);
        while ($this->_bucketsLen > 0 || count($this->_buckets) > 0 || $this->_writeBuffer !== null) {
            $this->write();
            usleep(1000);
        }
    }

    /** 
     * Returns statistics array
     * @return array
     **/
    public function getStats()
    {
        $this->_stats['bufferLen'] = $this->_bufferLen;
        $this->_stats['bucketsLen'] = $this->_bucketsLen;
        $this->_stats['writeBufferLen'] = strlen(
            substr($this->_writeBuffer, strpos($this->_writeBuffer, "\r\n\r\n")+4)
        );
        $this->_stats['inputFeof']  = (is_resource($this->_input))?feof($this->_input):'closed';
        $this->_stats['buckets'] = count($this->_buckets);
        return $this->_stats;
    }

    /**
     * Convert Human-readable units into bytes
     * Note: we use the PHP's integer so values over 2^31 can be unexpected on 32-bit platforms
     *
     * @param string value Human-readable value
     *
     * @return int Bytes
     */
    public static function humanToBytes($value)
    {
        $bytes = (int) 0;
        $decimal = 1;
        $pos = 0;

        $units = array(
            'b' => (int) 1,
            'k' => (int) 1024,
            'm' => (int) 1024 * 1024,
            'g' => (int) 1024 * 1024 * 1024
        );
        $value = strtolower($value);

        for ($i = 0; $i < strlen($value); $i++) {
            $digit = $value{$i};

            if (array_key_exists($digit, $units)) {
                $bytes *= $units[$digit];
                break;
            } elseif ($digit === '.') {
                $decimal = -1;
                $pos = 0;
            } else {
                if ($decimal > 0) {
                    $bytes *= 10;
                    $bytes += (int) $digit;
                } else {
                    $bytes += $digit * pow(10, $pos * $decimal);
                }
            }

            $pos++;
        }

        return (int) round($bytes, 0);
    }
}
