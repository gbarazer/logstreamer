<?php
// Mini server
file_put_contents('server.pid', getmypid());

//$argv[1] = 'tcp://127.0.0.1:27010';
if (!isset($argv[1])) {
    exit('argv1 required (server socket) like "tcp://127.0.0.1:27010"');
}

$_readSpeed = 1000; // microsec
$_readSize  = 4096*4;
$_closeLoop = false;
$_sleepLoop = false;
if (isset($argv[2])) {
    for ($i = 2; $i<$argc; $i++) {
        if (strpos($argv[$i], 'readspeed=') !== false) {
            $_readSpeed = (int) substr($argv[$i], strlen('readspeed='));
        }
        if (strpos($argv[$i], 'readsize=') !== false) {
            $_readSize = (int) substr($argv[$i], strlen('readsize='));
        }
        if (strpos($argv[$i], 'closeloop=') !== false) {
            $_closeLoop = (int) substr($argv[$i], strlen('closeloop='));
        }
        if (strpos($argv[$i], 'sleeploop=') !== false) {
            $_sleepLoop = (int) substr($argv[$i], strlen('sleeploop='));
        }
        
    }
}

chdir(dirname(__FILE__));

$socket = stream_socket_server($argv[1], $errno, $errstr);
if (!is_resource($socket)) exit('cannot create socket server on '.$argv[1]);
stream_set_blocking($socket, 0);

while (true) {
    $conn = @stream_socket_accept($socket, 1);
    if ($conn === false) {
        file_put_contents('/tmp/null', ".", FILE_APPEND);
        usleep(1000);
        continue;
    }
    stream_set_blocking($conn, 0);
    $tmpData = '';
    
    // We need header
    debug("--------\nstart ! Feof=".((int) feof($conn))."\n");
    
    while ($conn !== false || !feof($conn)) {
        $tmpData .= @fread($conn, 4096*4);
        debug("(read header) tmpData length=".strlen($tmpData)."\n");
        
        if ($tmpData == '') {
            usleep(1000);
            continue;
        }
        
        // Analyse
        if (($pos = strpos($tmpData, "\r\n\r\n")) !== false) {
            // parse header
            $z = preg_match('@Content-Length: ([0-9]{1,})@', $tmpData, $posL);
            debug("Header. contentLength=".($posL[1])."\n");
            if (!$z) {
                debug("500 returned. Data source: \n".$tmpData."\n\n");
                fwrite($conn, 'HTTP/1.1 500 No-Content-Length'."\r\n");
                fclose($conn);
                break;
            }
            $contentLength = $posL[1];
            
            // Compression
            if (preg_match('@Content-Encoding: ([a-z]{1,})@', $tmpData, $posL)) {
                $compression = trim($posL[1]);
                debug("(COMPRESSION DETECTED !)\n");
            } else {
                $compression = false;
            }
            
            
            $tmpData = substr($tmpData, $pos+4); // get content
            
            $loop = 0;
            while (strlen($tmpData) < $contentLength) {
                $tmpData .= fread($conn, $_readSize);
                usleep($_readSpeed);
                debug("R");
                if (feof($conn)) {
                    debug("feof() while reading\n");
                    fclose($conn);
                    break 2;
                }
                $loop++;
                
                if ($_closeLoop !== false && $loop === $_closeLoop) {
                    // close connection unexpectedly
                    fclose($conn);
                }
                if ($_sleepLoop !== false && $loop === $_sleepLoop) {
                    // sleep for long
                    sleep(10);
                }
            }
            
            // write
            if ($compression === false) {
                $writtenBytes = file_put_contents('resultfile.bin', $tmpData, FILE_APPEND);
            } elseif ($compression == 'gzip') {
                $writtenBytes = file_put_contents('resultfile.bin', gzdecode($tmpData), FILE_APPEND);
            } else {
                debug("\nFinish reading but compression unknown: '".$compression."'\n");
                @fwrite($conn, 'HTTP/1.1 500 Compression unknown'."\r\n");
                fclose($conn);
                break;
            }
            debug(
                "\nFinish reading. Data: ".strlen($tmpData).
                " bytes - Written: ".$writtenBytes."\n"
            );
            
            fwrite($conn, "HTTP/1.1 200 OK\r\n\r\nEverything fine\r\n\r\n");
            fclose($conn);
            break;
        } else {
            debug("did not find header in ".strlen($tmpData)." bytes\n");
            debug("***\n".substr($tmpData, 0, 128)."***\n");
            @fwrite($conn, 'HTTP/1.1 500 missing header'."\r\n");
            @fclose($conn);
            break;
        }
        usleep(1000);
    }
    debug("Closing connection\n");
    usleep(1000);
}
fclose($socket);

function gzdecode($data) 
{ 
   return gzinflate(substr($data, 10, -8)); 
}

function debug($str)
{
    @file_put_contents('/tmp/null', $str, FILE_APPEND);
}
