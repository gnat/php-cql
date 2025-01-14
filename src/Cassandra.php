<?php

//TODO:
// Implement Tuple
// Implement UDT

namespace CassandraNative;

/**
 * Cassanda Connector
 *
 * A native Cassandra connector for PHP based on the CQL binary protocol v3,
 * without the need for any external extensions.
 *
 * Requires PHP version >5, and Cassandra >1.2.
 *
 * Usage and more information is found on docs/Cassandra.txt
 *
 * The MIT License (MIT)
 *
 * Copyright (c) 2023 Uri Hartmann
 *
 * Permission is hereby granted, free of charge, to any person obtaining a
 * copy of this software and associated documentation files (the "Software"),
 * to deal in the Software without restriction, including without limitation
 * the rights to use, copy, modify, merge, publish, distribute, sublicense,
 * and/or sell copies of the Software, and to permit persons to whom the
 * Software is furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING
 * FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER
 * DEALINGS IN THE SOFTWARE.
 *
 * @category  Database
 * @package   Cassandra
 * @author    Uri Hartmann
 * @copyright 2022 Uri Hartmann
 * @license   http://opensource.org/licenses/MIT The MIT License (MIT)
 * @version   2023.01.31
 * @link      https://www.humancodes.org/projects/php-cql
 */

class BatchStatement
{
    public $data;
    public $queriesCount;
    private $batchType;
    private $cassandraObj;

    public function __construct($cassandraObj, $type)
    {
        $this->batchType = $type;
        $this->cassandraObj = $cassandraObj;
        $this->reset();
    }

    public function reset()
    {
        $this->data = '';
        $this->queriesCount = 0;
    }

    function add_simple($cql)
    {
        $this->data .= $this->cassandraObj->pack_byte(0) . $this->cassandraObj->pack_long_string($cql);
        $this->queriesCount++;
    }

    public function add_prepared($stmt, $values)
    {
        // Prepares the frame's body - <type><id><n><values>
        $frame = $this->cassandraObj->pack_byte(1) . $this->cassandraObj->pack_string(base64_decode($stmt['id'])) . $this->cassandraObj->pack_short(count($values));

        foreach ($stmt['columns'] as $key => $column) {
            $value = $values[$key];

            $data = $this->cassandraObj->pack_value(
                $value,
                $column['type'],
                $column['subtype1'],
                $column['subtype2']
            );

            $frame .= $this->cassandraObj->pack_long_string($data);
        }

        $this->data .= $frame;
        $this->queriesCount++;
    }

    public function get_data()
    {
        return $this->cassandraObj->pack_byte($this->batchType) . $this->cassandraObj->pack_short($this->queriesCount) . $this->data;
    }
}

class Cassandra
{
    const CONSISTENCY_ANY          = 0x0000;
    const CONSISTENCY_ONE          = 0x0001;
    const CONSISTENCY_TWO          = 0x0002;
    const CONSISTENCY_THREE        = 0x0003;
    const CONSISTENCY_QUORUM       = 0x0004;
    const CONSISTENCY_ALL          = 0x0005;
    const CONSISTENCY_LOCAL_QUORUM = 0x0006;
    const CONSISTENCY_EACH_QUORUM  = 0x0007;
    const CONSISTENCY_LOCAL_ONE    = 0x000A;

    const COLUMNTYPE_CUSTOM    = 0x0000;
    const COLUMNTYPE_ASCII     = 0x0001;
    const COLUMNTYPE_BIGINT    = 0x0002;
    const COLUMNTYPE_BLOB      = 0x0003;
    const COLUMNTYPE_BOOLEAN   = 0x0004;
    const COLUMNTYPE_COUNTER   = 0x0005;
    const COLUMNTYPE_DECIMAL   = 0x0006;
    const COLUMNTYPE_DOUBLE    = 0x0007;
    const COLUMNTYPE_FLOAT     = 0x0008;
    const COLUMNTYPE_INT       = 0x0009;
    const COLUMNTYPE_TEXT      = 0x000A;
    const COLUMNTYPE_TIMESTAMP = 0x000B;
    const COLUMNTYPE_UUID      = 0x000C;
    const COLUMNTYPE_VARCHAR   = 0x000D;
    const COLUMNTYPE_VARINT    = 0x000E;
    const COLUMNTYPE_TIMEUUID  = 0x000F;
    const COLUMNTYPE_INET      = 0x0010;
    const COLUMNTYPE_LIST      = 0x0020;
    const COLUMNTYPE_MAP       = 0x0021;
    const COLUMNTYPE_SET       = 0x0022;

    const OPCODE_ERROR          = 0x00;
    const OPCODE_STARTUP        = 0x01;
    const OPCODE_READY          = 0x02;
    const OPCODE_AUTHENTICATE   = 0x03;
    const OPCODE_CREDENTIALS    = 0x04;
    const OPCODE_OPTIONS        = 0x05;
    const OPCODE_SUPPORTED      = 0x06;
    const OPCODE_QUERY          = 0x07;
    const OPCODE_RESULT         = 0x08;
    const OPCODE_PREPARE        = 0x09;
    const OPCODE_EXECUTE        = 0x0A;
    const OPCODE_REGISTER       = 0x0B;
    const OPCODE_EVENT          = 0x0C;
    const OPCODE_BATCH          = 0x0D;
    const OPCODE_AUTH_CHALLENGE = 0x0E;
    const OPCODE_AUTH_RESPONSE  = 0x0F;
    const OPCODE_AUTH_SUCCESS   = 0x10;

    const BATCH_LOGGED          = 0x00;
    const BATCH_UNLOGGED        = 0x01;
    const BATCH_COUNTER         = 0x02;

    const RESULT_KIND_VOID          = 0x001;
    const RESULT_KIND_ROWS          = 0x002;
    const RESULT_KIND_SET_KEYSPACE  = 0x003;
    const RESULT_KIND_PREPARED      = 0x004;
    const RESULT_KIND_SCHEMA_CHANGE = 0x005;

    const FLAG_COMPRESSION    = 0x01;
    const FLAG_TRACING        = 0x02;
    const FLAG_CUSTOM_PAYLOAD = 0x04;
    const FLAG_WARNING        = 0x08;

    const PROTOCOL_VERSION  = 4;

    /* Set to 1 if blobs should return as raw string */
    public $rawBlobs = 0;

    private $socket = 0;
    public $async_requests = 0;
    private int $timeout_connect = 2;
    private int $timeout_read = 120;
    private string $host = '';
    private bool $persistent = false;
    private string $fullFrame = '';
    private array $warnings = [];

    public function __construct()
    {
    }

    /* Makes sure to close() upon destruct */
    function __destruct()
    {
        if (($this->socket) && (!$this->persistent))
            $this->read_async();
        $this->close();
    }

    public function set_timeout_connect(int $timeout): void
    {
        $this->timeout_connect = $timeout;
    }

    public function set_timeout_read(int $timeout): void
    {
        $this->timeout_read = $timeout;
    }

    // Tries a connection to a Cassandra server
    private function connect_once(string $host, string $user = '', string $passwd = '', string $dbname = '', int $port = 9042)
    {
        // Lookups host name to IP, if needed
        if ($this->socket)
            $this->close();

        $this->host = $host;
        $this->socket = 0;
        $this->persistent = (substr($host, 0, 2) == 'p:');
        if ($this->persistent)
            $host = substr($host, 2);

        $isIPV6 = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6);
        $domain = ($isIPV6 ? AF_INET6 : AF_INET);

        // Connects to server
        if ($this->persistent)
            $connection = @pfsockopen($host, $port, $errno, $errstr, $this->timeout_connect);
        else
            $connection = @fsockopen($host, $port, $errno, $errstr, $this->timeout_connect);

        if ($connection === false) {
            throw new \Exception('Socket connect to ' . $host . ':' . $port . ' failed: ' .
                '(' . $errno . ') ' . $errstr);
            return FALSE;
        }

        $this->socket = $connection;

        // Don't send startup & authentication if we're using a persistent connection
        if (!($this->persistent) || (ftell($connection) == 0)) {
            // Writes a STARTUP frame
            $frameBody = $this->pack_string_map(['CQL_VERSION' => '4.0.0']);
            if (!$this->write_frame(self::OPCODE_STARTUP, $frameBody)) {
                $this->close(true);
                return FALSE;
            }
            stream_set_timeout($this->socket, $this->timeout_connect);

            // Reads incoming frame - should be immediate do we don't
            // wait for longer than connection timeout, in case Cassandra is non responsive
            if (($frame = $this->read_frame()) === 0) {
                $this->close(true);
                return FALSE;
            }

            stream_set_timeout($this->socket, $this->timeout_read);

            // Checks if an AUTHENTICATE frame was received
            if ($frame['opcode'] == self::OPCODE_AUTHENTICATE) {
                // Writes a CREDENTIALS frame
                $body =
                    $this->pack_short(2) .
                    $this->pack_string('username') .
                    $this->pack_string($user) .
                    $this->pack_string('password') .
                    $this->pack_string($passwd);
                if (!$this->write_frame(self::OPCODE_CREDENTIALS, $body)) {
                    $this->close(true);
                    return FALSE;
                }

                // Reads incoming frame
                if (($frame = $this->read_frame()) === 0) {
                    $this->close(true);
                    return FALSE;
                }
            }

            // Checks if a READY frame was received
            if ($frame['opcode'] != self::OPCODE_READY) {
                $this->close(true);
                throw new \Exception('Missing READY packet. Got ' .
                    $frame['opcode'] . ') instead');
                return FALSE;
            }
        }

        // Checks if we need to set initial keyspace
        if ($dbname) {
            // Sends a USE query.
            $res = $this->query('USE ' . $dbname);

            // Checks the validity of the response
            if (!isset($res[0]) || ($res[0]['keyspace'] != $dbname)) {
                $this->close(true);
                return FALSE;
            }
        }

        // Returns the socket on success
        return $this->socket;
    }

    /**
     * Connects to a Cassandra node.
     *
     * @param string $host    Host name/IP to connect to use 'p:' as prefix for persistent connections.
     * @param string $user    Username in case authentication is needed.
     * @param string $passwd  Password in case authentication is needed.
     * @param string $dbname  Keyspace to use upon connection.
     * @param int    $port    Destination port (default: 9042).
     * @param int    $retries Number of connection retries (default: 3, useful for persistent connections in case of timeouts).
     *
     * @return int The socket descriptor used. FALSE if unable to connect.
     * @access public
     */
    public function connect(string $host, string $user = '', string $passwd = '', string $dbname = '', int $port = 9042, int $retries = 3)
    {
        $result = false;
        $lastException = null;
        for ($i = 0; $i < $retries; $i++) {
            try {
                $result = $this->connect_once($host, $user, $passwd, $dbname, $port);
            } catch (Exception $e) {
                $lastException = $e;
            }
            if ($result)
                break;
            usleep(rand(1000000, 2000000));
        }

        if ($result)
            return $result;
        else if ($lastExecption)
            throw $lastException;
        else
            return false;
    }

    /**
     * Closes an opened connection.
     *
     * @param bool    $closePersistent Set to TRUE to close persistent conections as well
     *
     * @return int 1
     * @access public
     */
    public function close(bool $closePersistent = false)
    {
        if (($this->socket) && ($closePersistent || (!$this->persistent))) {
            fclose($this->socket);
            $this->socket = 0;
        }
        return 1;
    }

    /**
     * Queries the database using the given CQL.
     *
     * @param string $cql         The query to run.
     * @param int    $consistency Consistency level for the operation.
     *
     * @return array Result of the query. Might be an array of rows (for
     *               SELECT), or the operation's result (for USE, CREATE,
     *               ALTER, UPDATE).
     *               NULL on error.
     * @access public
     */
    public function query($cql, $consistency = self::CONSISTENCY_ALL)
    {
        if ($this->async_requests) {
            throw new \Exception('Cannot query while async requests are pending. Call read_async() first');
            return NULL;
        }

        // Prepares the frame's body
        // TODO: Support the new <flags> byte
        $frame = $this->pack_long_string($cql) .
            $this->pack_short($consistency) .
            $this->pack_byte(0);

        // Writes a QUERY frame and return the result
        return $this->request_result(self::OPCODE_QUERY, $frame);
    }

    /**
     * Prepares a query statement.
     *
     * @param string $cql The query to prepare.
     *
     * @return array The statement's information to be used with the execute
     *               method. NULL on error.
     * @access public
     */
    public function prepare($cql)
    {
        if ($this->async_requests) {
            throw new \Exception('Cannot prepare while async requests are pending. Call read_async() first');
            return NULL;
        }

        // Prepares the frame's body
        $frame = $this->pack_long_string($cql);

        // Writes a PREPARE frame and return the result
        return $this->request_result(self::OPCODE_PREPARE, $frame);
    }

    /**
     * Executes a prepared statement.
     *
     * @param array $stmt        The prepared statement as returned from the
     *                           prepare method.
     * @param array $values      Values to bind in key=>value format where key is
     *                           the column's name.
     * @param int   $consistency Consistency level for the operation.
     *
     * @param bool  $async       Use 1 if you wish to perform the query asynchronously.
     *
     * @return array Result of the execution. Might be an array of rows (for
     *               SELECT), or the operation's result (for USE, CREATE,
     *               ALTER, UPDATE).
     *               NULL on error.
     * @access public
     */
    public function execute($stmt, $values, $consistency = self::CONSISTENCY_ALL, $async = 0)
    {
        if ((!$async) && ($this->async_requests)) {
            throw new \Exception('Cannot execute while async requests are pending. Call read_async() first');
            return NULL;
        }

        // Prepares the frame's body - <id><count><values map>
        $frame = base64_decode($stmt['id']);
        $frame = $this->pack_string($frame) .
            $this->pack_short($consistency) .
            $this->pack_byte(0x01) . // values only
            $this->pack_short(count($values));

        foreach ($stmt['columns'] as $key => $column) {
            $value = $values[$key];

            $data = $this->pack_value(
                $value,
                $column['type'],
                $column['subtype1'],
                $column['subtype2']
            );

            $frame .= $this->pack_long_string($data);
        }

        if ($async) {
            $this->async_requests++;
            return $this->write_frame(self::OPCODE_EXECUTE, $frame, 0, 1);
        } else {
            // Writes a EXECUTE frame and return the result
            return $this->request_result(self::OPCODE_EXECUTE, $frame);
        }
    }

    /**
     * Read all pending async results.
     *
     * @return array Result of the executions. Might be an array of rows (for
     *               SELECT), or the operation's result (for USE, CREATE,
     *               ALTER, UPDATE).
     *               NULL on error.
     * @access public
     */
    public function read_async()
    {
        $retval = [];
        while ($this->async_requests) {
            // Reads incoming frame
            $frame = $this->read_frame();
            if (!$frame)
                return NULL;

            // Parses the incoming frame
            if ($frame['opcode'] == self::OPCODE_RESULT) {
                $retval[] = $this->parse_result($frame['body']);
            } else {
                $this->close(true);
                throw new \Exception('Unknown opcode ' . $frame['opcode']);
                return NULL;
            }

            $this->async_requests--;
        }
        return $retval;
    }

    /**
     * Executes a batch of statements.
     *
     * @param CassandraBatch $batchObj    A batch of statements to execute.
     * @param int            $consistency Consistency level for the operation.
     *
     * @return array Result of the execution. Might be an array of rows (for
     *               SELECT), or the operation's result (for USE, CREATE,
     *               ALTER, UPDATE).
     *               NULL on error.
     * @access public
     */
    public function batch($batchObj, $consistency = self::CONSISTENCY_ALL)
    {
        // Prepares the frame's body - <id><count><values map>
        // TODO: Add support for the new flags byte
        $frame = $batchObj->get_data() . $this->pack_short($consistency) . $this->pack_byte(0);

        // Writes a EXECUTE frame and return the result
        return $this->request_result(self::OPCODE_BATCH, $frame);
    }

    /**
     * Writes a (QUERY/PREPARE/EXCUTE) frame, reads the result, and parses it.
     *
     * @param int    $opcode Frame's opcode.
     * @param string $body   Frame's body.
     *
     * @return array Result of the request. Might be an array of rows (for
     *               SELECT), or the operation's result (for USE, CREATE,
     *               ALTER, UPDATE).
     *               NULL on error.
     * @access private
     */
    private function request_result($opcode, $body)
    {
        // Writes the frame
        if (!$this->write_frame($opcode, $body))
            return NULL;

        // Reads incoming frame
        $frame = $this->read_frame();
        if (!$frame)
            return NULL;

        // Parses the incoming frame
        if ($frame['opcode'] == self::OPCODE_RESULT) {
            return $this->parse_result($frame['body']);
        } else {
            $this->close(true);
            throw new \Exception('Unknown opcode ' . $frame['opcode']);
            return NULL;
        }
    }

    /**
     * Packs and writes a frame to the socket.
     *
     * @param int    $opcode   Frame's opcode.
     * @param string $body     Frame's body.
     * @param int    $response Frame's response flag.
     * @param int    $stream   Frame's stream id.
     *
     * @return bool true on success, false on error.
     * @access private
     */
    private function write_frame($opcode, $body, $response = 0, $stream = 0)
    {
        // Prepares the outgoing packet
        $frame = $this->pack_frame($opcode, $body, $response, $stream);

        // Writes frame to socket
        if (@fwrite($this->socket, $frame) === false) {
            $this->close(true);
            throw new \Exception('Socket write failed');
            return false;
        }

        return true;
    }

    /**
     * Reads data with a specific size from socket.
     *
     * @param int $size Requested data size.
     *
     * @return string Incoming data, false on error.
     * @access private
     */
    private function read_size($size, $header = false)
    {
        if (!$this->socket)
            return false;

        $data = '';
        while (strlen($data) < $size) {
            $readSize = $size - strlen($data);
            $buff = @fread($this->socket, $readSize);
            if (($buff === false) || (stream_get_meta_data($this->socket)['timed_out'])) {
                $this->close(true);
                throw new \Exception('Read error');
                return false;
            }
            $data .= $buff;
        }
        return $data;
    }

    private function parse_incoming_frame($header, $body)
    {
        $flags = ord($header[1]);

        // Unpack the header to its contents:
        // <byte version><byte flags><uint16 stream><byte opcode><int length>
        $opcode = ord($header[4]);

        $this->fullFrame = $header . $body;

        $this->warnings = [];
        if ($flags & self::FLAG_WARNING) {
            $iPos = 0;
            $warningCount = $this->pop_short($body, $iPos);
            for ($i = 0; $i < $warningCount; $i++) {
                $value = $this->pop_string($body, $iPos);
                $this->warnings[] = $value;
            }
            myWarningHandler('Cassandra (' . $this->host . '): ' . json_encode($this->warnings));
            $body = substr($body, $iPos);
        }

        // If we got an error - trigger it and return an error
        if ($opcode == self::OPCODE_ERROR) {
            // ERROR: <int code><string msg>
            $errCode = $this->int_from_bin($body, 0, 4);
            $bodyOffset = 4;  // Must be passed by reference
            $errMsg = $this->pop_string($body, $bodyOffset);

            $this->close(true);
            throw new \Exception('Error 0x' . sprintf('%04X', $errCode) .
                ' received from server: ' . $errMsg);
            return false;
        }

        return ['opcode' => $opcode, 'body' => $body];
    }

    /**
     * Reads pending frame from the socket.
     *
     * @return string Incoming data, false on error.
     * @access private
     */
    private function read_frame()
    {
        // Read the 9 bytes header
        if (!($header = $this->read_size(9, true))) {
            throw new \Exception('Missing header (' . strlen($header) . ')');
            return false;
        }

        $length = $this->int_from_bin($header, 5, 4, 0);

        // Read frame body, if exists
        if ($length) {
            if (!($body = $this->read_size($length))) {
                return false;
            }
        } else {
            $body = '';
        }

        return $this->parse_incoming_frame($header, $body);
    }

    /**
     * Parses a RESULT frame.
     *
     * @param string $body Frame's body
     *
     * @return array Parsed frame. Might be an array of rows (for SELECT),
     *               or the operation's result (for USE, CREATE, ALTER,
     *               UPDATE).
     *               NULL on error.
     * @access private
     */
    private function parse_result($body)
    {
        // Parse RESULTS opcode
        $bodyOffset = 0;
        $kind = $this->pop_int($body, $bodyOffset);

        switch ($kind) {
            case self::RESULT_KIND_VOID:
                return [['result' => 'success']];
            case self::RESULT_KIND_ROWS:
                return $this->parse_rows($body, $bodyOffset);
            case self::RESULT_KIND_SET_KEYSPACE:
                $keyspace = $this->pop_string($body, $bodyOffset);
                return [['keyspace' => $keyspace]];
            case self::RESULT_KIND_PREPARED:
                // <string id><metadata>
                $id = base64_encode($this->pop_string($body, $bodyOffset));
                $metadata = $this->parse_rows_metadata($body, $bodyOffset, true);
                $columns = [];

                foreach ($metadata as $column) {
                    $columns[$column['name']] = [
                        'type' => $column['type'],
                        'subtype1' => $column['subtype1'],
                        'subtype2' => $column['subtype2']
                    ];
                }

                return ['id' => $id, 'columns' => $columns];
            case self::RESULT_KIND_SCHEMA_CHANGE:
                // <string change><string keyspace><string table>
                $change = $this->pop_string($body, $bodyOffset);
                $target = $this->pop_string($body, $bodyOffset);
                $options = $this->pop_string($body, $bodyOffset);
                return [[
                    'change' => $change, 'target' => $target,
                    'options' => $options
                ]];
        }

        throw new \Exception('Unknown result kind ' . $kind . ' full frame: ' . bin2hex($this->fullFrame));
    }

    /**
     * Parses a RESULT Rows metadata (also used for RESULT Prepared), starting
     * from the offset, and advancing it in the process.
     *
     * @param string $body       Metadata body.
     * @param string $bodyOffset Metadata body offset to start from.
     *
     * @return array Columns list
     * @access private
     */
    private function parse_rows_metadata(string $body, int &$bodyOffset, bool $readPk = false)
    {
        $flags = $this->pop_int($body, $bodyOffset);
        $columns_count = $this->pop_int($body, $bodyOffset);

        if ($readPk) {
            $pk_count = $this->pop_int($body, $bodyOffset);

            for ($i = 0; $i < $pk_count; $i++)
                $this->pop_short($body, $bodyOffset);
        }

        $global_table_spec = ($flags & 0x0001);
        if ($global_table_spec) {
            $keyspace = $this->pop_string($body, $bodyOffset);
            $table = $this->pop_string($body, $bodyOffset);
        }

        $columns = [];

        for ($i = 0; $i < $columns_count; $i++) {
            if (!$global_table_spec) {
                $keyspace = $this->pop_string($body, $bodyOffset);
                $table = $this->pop_string($body, $bodyOffset);
            }

            $column_name = $this->pop_string($body, $bodyOffset);
            $column_type = $this->pop_short($body, $bodyOffset);
            if ($column_type == self::COLUMNTYPE_CUSTOM) {
                $column_type = $this->pop_string($body, $bodyOffset);
                $column_subtype1 = 0;
                $column_subtype2 = 0;
            } elseif (($column_type == self::COLUMNTYPE_LIST) ||
                ($column_type == self::COLUMNTYPE_SET)
            ) {
                $column_subtype1 = $this->pop_short($body, $bodyOffset);
                if ($column_subtype1 == self::COLUMNTYPE_CUSTOM)
                    $column_subtype1 = $this->pop_string($body, $bodyOffset);
                $column_subtype2 = 0;
            } elseif ($column_type == self::COLUMNTYPE_MAP) {
                $column_subtype1 = $this->pop_short($body, $bodyOffset);
                if ($column_subtype1 == self::COLUMNTYPE_CUSTOM)
                    $column_subtype1 = $this->pop_string($body, $bodyOffset);

                $column_subtype2 = $this->pop_short($body, $bodyOffset);
                if ($column_subtype2 == self::COLUMNTYPE_CUSTOM)
                    $column_subtype2 = $this->pop_string($body, $bodyOffset);
            } else {
                $column_subtype1 = 0;
                $column_subtype2 = 0;
            }
            $columns[] = [
                'keyspace' => $keyspace,
                'table' => $table,
                'name' => $column_name,
                'type' => $column_type,
                'subtype1' => $column_subtype1,
                'subtype2' => $column_subtype2
            ];
        }
        return $columns;
    }

    /**
     * Parses a RESULT Rows kind.
     *
     * @param string $body       Frame body to parse.
     * @param string $bodyOffset Offset to start from.
     *
     * @return array Rows with associative array of the records.
     * @access private
     */
    private function parse_rows($body, $bodyOffset)
    {
        // <metadata><int count><rows_content>
        $columns = $this->parse_rows_metadata($body, $bodyOffset);
        $columns_count = count($columns);

        $rows_count = $this->pop_int($body, $bodyOffset);

        $retval = [];
        for ($i = 0; $i < $rows_count; $i++) {
            $row = [];
            foreach ($columns as $col) {
                $content = $this->pop_bytes($body, $bodyOffset);
                $value = $this->unpack_value(
                    $content,
                    $col['type'],
                    $col['subtype1'],
                    $col['subtype2']
                );

                $row[$col['name']] = $value;
            }
            $retval[] = $row;
        }

        return $retval;
    }

    /**
     * Packs a value to its binary form based on a column type. Used for
     * prepared statement.
     *
     * @param mixed $value    Value to pack.
     * @param int   $type     Column type.
     * @param int   $subtype1 Sub column type for list/set or key for map.
     * @param int   $subtype2 Sub column value type for map.
     *
     * @return string Binary form of the value.
     * @access public
     */
    public function pack_value($value, $type, $subtype1 = 0, $subtype2 = 0)
    {
        switch ($type) {
            case self::COLUMNTYPE_CUSTOM:
            case self::COLUMNTYPE_BLOB:
                return $this->pack_blob($value);
            case self::COLUMNTYPE_ASCII:
            case self::COLUMNTYPE_TEXT:
            case self::COLUMNTYPE_VARCHAR:
                return $value;
            case self::COLUMNTYPE_BIGINT:
            case self::COLUMNTYPE_COUNTER:
            case self::COLUMNTYPE_TIMESTAMP:
                return $this->pack_bigint($value);
            case self::COLUMNTYPE_BOOLEAN:
                return $this->pack_boolean($value);
            case self::COLUMNTYPE_DECIMAL:
                return $this->pack_decimal($value);
            case self::COLUMNTYPE_DOUBLE:
                return $this->pack_double($value);
            case self::COLUMNTYPE_FLOAT:
                return $this->pack_float($value);
            case self::COLUMNTYPE_INT:
                return $this->pack_int($value);
            case self::COLUMNTYPE_UUID:
            case self::COLUMNTYPE_TIMEUUID:
                return $this->pack_uuid($value);
            case self::COLUMNTYPE_VARINT:
                return $this->pack_varint($value);
            case self::COLUMNTYPE_INET:
                return $this->pack_inet($value);
            case self::COLUMNTYPE_LIST:
            case self::COLUMNTYPE_SET:
                return $this->pack_list($value, $subtype1);
            case self::COLUMNTYPE_MAP:
                return $this->pack_map($value, $subtype1, $subtype2);
        }

        throw new \Exception('Unknown column type ' . $type);
        return NULL;
    }

    /**
     * Unpacks a value from its binary form based on a column type. Used for
     * parsing rows.
     *
     * @param string $content  Content to unpack.
     * @param int    $type     Column type.
     * @param int    $subtype1 Sub column type for list/set or key for map.
     * @param int    $subtype2 Sub column value type for map.
     *
     * @return mixed Unpacked value.
     * @access private
     */
    private function unpack_value($content, $type, $subtype1 = 0, $subtype2 = 0)
    {
        if ($content === NULL)
            return NULL;

        switch ($type) {
            case self::COLUMNTYPE_CUSTOM:
            case self::COLUMNTYPE_BLOB:
                return $this->unpack_blob($content);
            case self::COLUMNTYPE_ASCII:
            case self::COLUMNTYPE_TEXT:
            case self::COLUMNTYPE_VARCHAR:
                return $content;
            case self::COLUMNTYPE_BIGINT:
            case self::COLUMNTYPE_COUNTER:
            case self::COLUMNTYPE_TIMESTAMP:
                return $this->unpack_bigint($content);
            case self::COLUMNTYPE_BOOLEAN:
                return $this->unpack_boolean($content);
            case self::COLUMNTYPE_DECIMAL:
                return $this->unpack_decimal($content);
            case self::COLUMNTYPE_DOUBLE:
                return $this->unpack_double($content);
            case self::COLUMNTYPE_FLOAT:
                return $this->unpack_float($content);
            case self::COLUMNTYPE_INT:
                return $this->unpack_int($content);
            case self::COLUMNTYPE_UUID:
            case self::COLUMNTYPE_TIMEUUID:
                return $this->unpack_uuid($content);
            case self::COLUMNTYPE_VARINT:
                return $this->unpack_varint($content);
            case self::COLUMNTYPE_INET:
                return $this->unpack_inet($content);
            case self::COLUMNTYPE_LIST:
            case self::COLUMNTYPE_SET:
                return $this->unpack_list($content, $subtype1);
            case self::COLUMNTYPE_MAP:
                return $this->unpack_map($content, $subtype1, $subtype2);
        }

        throw new \Exception('Unknown column type ' . $type);
        return NULL;
    }

    /**
     * Packs a COLUMNTYPE_BLOB value to its binary form.
     *
     * @param string $value Value to pack.
     *
     * @return string Binary form of the value.
     * @access private
     */
    private function pack_blob($value)
    {
        if (substr($value, 0, 2) == '0x')
            $value = pack('H*', substr($value, 2));
        return $value;
    }

    /**
     * Unpacks a COLUMNTYPE_BLOB value from its binary form.
     *
     * @param string $content Content to unpack.
     *
     * @return string Unpacked value in hexadecimal representation.
     * @access private
     */
    private function unpack_blob($content, $prefix = '0x')
    {
        if ($this->rawBlobs)
            return $content;

        $value = unpack('H*', $content);
        if ($value[1])
            $value[1] = $prefix . $value[1];
        return $value[1];
    }

    /**
     * Packs a COLUMNTYPE_BIGINT value to its binary form.
     *
     * @param int $value Value to pack.
     *
     * @return string Binary form of the value.
     * @access private
     */
    private function pack_bigint($value)
    {
        return $this->bin_from_int($value, 8, 1);
    }

    /**
     * Unpacks a COLUMNTYPE_BIGINT value from its binary form.
     *
     * @param string $content Content to unpack.
     *
     * @return int Unpacked value.
     * @access private
     */
    private function unpack_bigint($content)
    {
        return $this->int_from_bin($content, 0, 8, 1);
    }

    /**
     * Packs a COLUMNTYPE_BOOLEAN value to its binary form.
     *
     * @param boolean $value Value to pack.
     *
     * @return string Binary form of the value.
     * @access private
     */
    private function pack_boolean($value)
    {
        if ($value === NULL)
            return '';
        if ($value == TRUE)
            return chr(1);
        else
            return chr(0);
    }

    /**
     * Unpacks a COLUMNTYPE_BOOLEAN value from its binary form.
     *
     * @param string $content Content to unpack.
     *
     * @return bool Unpacked value.
     * @access private
     */
    private function unpack_boolean($content)
    {
        if (strlen($content) > 0) {
            $c = ord($content[0]);
            if ($c == 1)
                return TRUE;
            elseif ($c == 0)
                return FALSE;
            else
                return NULL;
        }

        return NULL;
    }

    /**
     * Packs a COLUMNTYPE_DECIMAL value to its binary form.
     *
     * @param decimal $value Value to pack.
     *
     * @return string Binary form of the value.
     * @access private
     */
    private function pack_decimal($value)
    {
        // Based on http://docs.oracle.com/javase/7/docs/api/java/math/BigDecimal.html

        // Find the scale
        $value1 = abs($value);
        $positiveScale = 0;
        while (floor($value1) && (fmod($value1, 10) == 0)) {
            $value1 /= 10;
            $positiveScale++;
        }

        $value1 = $value;
        $negativeScale = 0;
        while (fmod($value1, 1)) {
            $value1 *= 10;
            $negativeScale--;
        }

        $scale = $negativeScale - $positiveScale;
        if ($negativeScale)
            $scale = -$negativeScale;
        else
            $scale = -$positiveScale;

        $unscaledValue = $value / pow(10, -$scale);

        return $this->pack_int($scale) . $this->pack_varint($unscaledValue);
    }

    /**
     * Unpacks a COLUMNTYPE_DECIMAL value from its binary form.
     *
     * @param string $content Content to unpack.
     *
     * @return decimal Unpacked value.
     * @access private
     */
    private function unpack_decimal($content)
    {
        // Based on http://docs.oracle.com/javase/7/docs/api/java/math/BigDecimal.html

        $len = strlen($content);
        if ($len < 5)
            return 0;

        $data = unpack('N', $content);
        $scale = $data[1];
        $unscaledValue = $this->unpack_varint(substr($content, 4));

        return $unscaledValue * pow(10, -$scale);
    }

    /**
     * Packs a COLUMNTYPE_DOUBLE value to its binary form.
     *
     * @param double $value Value to pack.
     *
     * @return string Binary form of the value.
     * @access private
     */
    private function pack_double($value)
    {
        $littleEndian = pack('d', $value);
        $retval = '';
        for ($i = 7; $i >= 0; $i--)
            $retval .= $littleEndian[$i];
        return $retval;
    }

    /**
     * Unpacks a COLUMNTYPE_DOUBLE value from its binary form.
     *
     * @param string $content Content to unpack.
     *
     * @return double Unpacked value.
     * @access private
     */
    private function unpack_double($content)
    {
        $bigEndian = '';
        for ($i = 7; $i >= 0; $i--)
            $bigEndian .= $content[$i];

        $value = unpack('d', $bigEndian);
        return $value[1];
    }

    /**
     * Packs a COLUMNTYPE_FLOAT value to its binary form.
     *
     * @param float $value Value to pack.
     *
     * @return string Binary form of the value.
     * @access private
     */
    private function pack_float($value)
    {
        $littleEndian = pack('f', $value);
        $retval = '';
        for ($i = 3; $i >= 0; $i--)
            $retval .= $littleEndian[$i];
        return $retval;
    }

    /**
     * Unpacks a COLUMNTYPE_FLOAT value from its binary form.
     *
     * @param string $content Content to unpack.
     *
     * @return float Unpacked value.
     * @access private
     */
    private function unpack_float($content)
    {
        $bigEndian = '';
        for ($i = 3; $i >= 0; $i--)
            $bigEndian .= $content[$i];

        $value = unpack('f', $bigEndian);
        return $value[1];
    }

    /**
     * Packs a COLUMNTYPE_INT value to its binary form.
     *
     * @param int $value Value to pack.
     *
     * @return string Binary form of the value.
     * @access private
     */
    private function pack_int($value)
    {
        return $this->bin_from_int($value, 4, 1);
    }

    /**
     * Unpacks a COLUMNTYPE_INT value from its binary form.
     *
     * @param string $content Content to unpack.
     *
     * @return int Unpacked value.
     * @access private
     */
    private function unpack_int($content)
    {
        return $this->int_from_bin($content, 0, 4, 1);
    }

    /**
     * Packs a COLUMNTYPE_UUID value to its binary form.
     *
     * @param string $value Value to pack.
     *
     * @return string Binary form of the value.
     * @access private
     */
    private function pack_uuid($value)
    {
        return pack('H*', str_replace('-', '', $value));
    }

    /**
     * Unpacks a COLUMNTYPE_UUID value from its binary form.
     *
     * @param string $content Content to unpack.
     *
     * @return string Unpacked value.
     * @access private
     */
    private function unpack_uuid($content)
    {
        $value = unpack('H*', $content);
        if ($value[1]) {
            return substr($value[1], 0, 8) . '-' . substr($value[1], 8, 4) . '-' .
                substr($value[1], 12, 4) . '-' . substr($value[1], 16, 4) . '-' .
                substr($value[1], 20);
        }

        return NULL;
    }

    /**
     * Packs a COLUMNTYPE_VARINT value to its binary form.
     *
     * @param int $value Value to pack.
     *
     * @return string Binary form of the value.
     * @access private
     */
    private function pack_varint($content)
    {
        return $this->bin_from_int($content, 0xFFFF, 1);
    }

    /**
     * Unpacks a COLUMNTYPE_VARINT value from its binary form.
     *
     * @param string $content Content to unpack.
     *
     * @return int Unpacked value.
     * @access private
     */
    private function unpack_varint($content)
    {
        return $this->int_from_bin($content, 0, strlen($content), 1);
    }

    /**
     * Packs a COLUMNTYPE_INET value to its binary form.
     *
     * @param string $value Value to pack.
     *
     * @return string Binary form of the value.
     * @access private
     */
    private function pack_inet($value)
    {
        return inet_pton($value);
    }

    /**
     * Unpacks a COLUMNTYPE_INET value from its binary form.
     *
     * @param string $content Content to unpack.
     *
     * @return int Unpacked value.
     * @access private
     */
    private function unpack_inet($content)
    {
        return inet_ntop($content);
    }

    /**
     * Packs a COLUMNTYPE_LIST value to its binary form.
     *
     * @param array $value   Value to pack.
     * @param int   $subtype Values' Column type.
     *
     * @return string Binary form of the value.
     * @access private
     */
    private function pack_list($value, $subtype)
    {
        $retval = $this->pack_int(count($value));

        foreach ($value as $item) {
            $itemPacked = $this->pack_value($item, $subtype);
            $retval .= $this->pack_long_string($itemPacked);
        }

        return $retval;
    }

    /**
     * Unpacks a COLUMNTYPE_LIST value from its binary form.
     *
     * @param string $content Content to unpack.
     * @param int    $subtype Values' Column type.
     *
     * @return array Unpacked value.
     * @access private
     */
    private function unpack_list($content, $subtype)
    {
        $contentOffset = 0;
        $itemsCount = $this->pop_int($content, $contentOffset);
        $retval = [];
        for (; $itemsCount; $itemsCount--) {
            $subcontent = $this->pop_long_string($content, $contentOffset);
            $retval[] = $this->unpack_value($subcontent, $subtype);
        }

        return $retval;
    }

    /**
     * Packs a COLUMNTYPE_MAP value to its binary form.
     *
     * @param array $value    Value to pack.
     * @param int   $subtype1 Keys' column type.
     * @param int   $subtype2 Values' column type.
     *
     * @return string Binary form of the value.
     * @access private
     */
    private function pack_map($value, $subtype1, $subtype2)
    {
        $retval = $this->pack_int(count($value));

        foreach ($value as $key => $item) {
            $keyPacked = $this->pack_value($key, $subtype1);
            $itemPacked = $this->pack_value($item, $subtype2);
            $retval .= $this->pack_long_string($keyPacked) .
                $this->pack_long_string($itemPacked);
        }

        return $retval;
    }

    /**
     * Unpacks a COLUMNTYPE_MAP value from its binary form.
     *
     * @param string $content  Content to unpack.
     * @param int    $subtype1 Keys' column type.
     * @param int    $subtype2 Values' column type.
     *
     * @return array Unpacked value.
     * @access private
     */
    private function unpack_map($content, $subtype1, $subtype2)
    {
        $contentOffset = 0;
        $itemsCount = $this->pop_int($content, $contentOffset);
        $retval = [];
        for (; $itemsCount; $itemsCount--) {
            $subKeyRaw = $this->pop_long_string($content, $contentOffset);
            $subValueRaw = $this->pop_long_string($content, $contentOffset);

            $subKey = $this->unpack_value($subKeyRaw, $subtype1);
            $subValue = $this->unpack_value($subValueRaw, $subtype2);
            $retval[$subKey] = $subValue;
        }

        return $retval;
    }

    /**
     * Pops a [bytes] value from the body, starting from the offset, and
     * advancing it in the process.
     *
     * @param string $body    Content's body.
     * @param string &$offset Offset to start from.
     *
     * @return string Bytes content.
     * @access private
     */
    private function pop_bytes($body, &$offset)
    {
        $string_length = $this->int_from_bin($body, $offset, 4, 0);

        if ($string_length == 0xFFFFFFFF) {
            $actual_length = 0;
            $retval = NULL;
        } else {
            $actual_length = $string_length;
            $retval = substr($body, $offset + 4, $actual_length);
            $offset += $actual_length + 4;
        }

        return $retval;
    }

    /**
     * Pops a [string] value from the body, starting from the offset, and
     * advancing it in the process.
     *
     * @param string $body    Content's body.
     * @param string &$offset Offset to start from.
     *
     * @return string String content.
     * @access private
     */
    private function pop_string($body, &$offset)
    {
        $len = substr($body, $offset, 2);
        if (strlen($len) < 2) {
            myWarningHandler('POP error: ' . bin2hex($body) . ' - ' . $offset);
            return NULL;
        }
        $string_length = unpack('n', substr($body, $offset, 2));
        if ($string_length[1] == 0xFFFF) {
            $offset += 2;
            return NULL;
        }
        $retval = substr($body, $offset + 2, $string_length[1]);
        $offset += $string_length[1] + 2;
        return $retval;
    }

    /**
     * Pops a [long string] value from the body, starting from the offset, and
     * advancing it in the process.
     *
     * @param string $body    Content's body.
     * @param string &$offset Offset to start from.
     *
     * @return string Long String content.
     * @access private
     */
    private function pop_long_string($body, &$offset)
    {
        $string_length = unpack('N', substr($body, $offset, 4));
        if ($string_length[1] == 0xFFFFFFFF) {
            $offset += 4;
            return NULL;
        }
        $retval = substr($body, $offset + 4, $string_length[1]);
        $offset += $string_length[1] + 4;
        return $retval;
    }

    /**
     * Pops a [int] value from the body, starting from the offset, and
     * advancing it in the process.
     *
     * @param string $body    Content's body.
     * @param string &$offset Offset to start from.
     *
     * @return int Int content.
     * @access private
     */
    private function pop_int($body, &$offset)
    {
        $retval = $this->int_from_bin($body, $offset, 4, 1);
        $offset += 4;
        return $retval;
    }

    /**
     * Pops a [short] value from the body, starting from the offset, and
     * advancing it in the process.
     *
     * @param string $body    Content's body.
     * @param string &$offset Offset to start from.
     *
     * @return short Short content.
     * @access private
     */
    private function pop_short($body, &$offset)
    {
        $retval = $this->int_from_bin($body, $offset, 2, 1);
        $offset += 2;
        return $retval;
    }

    /**
     * Packs an outgoing frame.
     *
     * @param int    $opcode   Frame's opcode.
     * @param string $body     Frame's body.
     * @param int    $response Frame's response flag.
     * @param int    $stream   Frame's stream id.
     *
     * @return string Frame's content.
     * @access private
     */
    private function pack_frame($opcode, $body = '', $response = 0, $stream = 0)
    {
        $version = ($response << 0x07) | self::PROTOCOL_VERSION;
        $flags = 0;
        $opcode = $opcode;

        $frame = pack(
            'CCnCNa*',
            $version,
            $flags,
            $stream,
            $opcode,
            strlen($body),
            $body
        );

        return $frame;
    }

    /**
     * Packs a [long string] notation (section 3)
     *
     * @param int $data String content.
     *
     * @return string Data content.
     * @access public
     */
    public function pack_long_string($data)
    {
        return pack('Na*', strlen($data), $data);
    }

    /**
     * Packs a [string] notation (section 3)
     *
     * @param int $data String content.
     *
     * @return string Data content.
     * @access public
     */
    public function pack_string($data)
    {
        return pack('na*', strlen($data), $data);
    }

    /**
     * Packs a [short] notation (section 3)
     *
     * @param int $data Short content.
     *
     * @return string Data content.
     * @access public
     */
    public function pack_short($data)
    {
        return chr($data >> 0x08) . chr($data & 0xFF);
    }

    /**
     * Packs a [short] notation (missing from specs)
     *
     * @param char $data Byte content.
     *
     * @return string Data content.
     * @access public
     */
    public function pack_byte($data)
    {
        return chr($data);
    }

    /**
     * Packs a [string map] notation (section 3)
     *
     * @param array $dataArr Associative array of the map.
     *
     * @return string Data content.
     * @access public
     */
    public function pack_string_map($dataArr)
    {
        $retval = pack('n', count($dataArr));
        foreach ($dataArr as $key => $value)
            $retval .= $this->pack_string($key) . $this->pack_string($value);
        return $retval;
    }

    /**
     * Converts binary format to a varint.
     *
     * @param string  $data   Binary content.
     * @param int     $offset Starting data offset.
     * @param int     $length Data length.
     * @param boolean $signed Whether the returned data can be signed.
     *
     * @return int Parsed varint.
     * @access private
     */
    private function int_from_bin($data, $offset, $length, $signed = false)
    {
        $len = strlen($data);

        if ((!$length) || ($offset >= $len))
            return 0;

        $signed = $signed && (ord($data[$offset]) & 0x80);

        $value = 0;
        for ($i = 0; $i < $length; $i++) {
            $v = ord($data[$i + $offset]);
            if ($signed)
                $v ^= 0xFF;
            $value = $value * 256 + $v;
        }

        if ($signed)
            $value = - ($value + 1);

        return $value;
    }

    /**
     * Converts varint to its binary format.
     *
     * @param int     $value  Binary content.
     * @param int     $offset Starting data offset.
     * @param int     $length Data length.
     * @param boolean $signed Whether the returned data can be signed.
     *
     * @return String Binary content.
     * @access private
     */
    private function bin_from_int($value, $length, $signed = false)
    {
        $negative = (($signed) && ($value < 0));
        if ($negative)
            $value = - ($value + 1);

        $retval = '';
        for ($i = 0; $i < $length; $i++) {
            $v = $value % 256;
            if ($negative)
                $v ^= 0xFF;
            $retval = chr($v) . $retval;
            $value = floor($value / 256);

            if (($length == 0xFFFF) && ($value == 0))
                break;
        }

        return $retval;
    }
}
