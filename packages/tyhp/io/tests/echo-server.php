<?php

declare(strict_types=1);

$server = \stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
if (!\is_resource($server)) {
    \fwrite(\STDERR, $errstr);
    exit(1);
}
$name = (string) \stream_socket_get_name($server, false);
echo \substr($name, (int) \strrpos($name, ':') + 1), "\n";
\fflush(\STDOUT);

$client = \stream_socket_accept($server, 10);
if (!\is_resource($client)) {
    exit(1);
}
while (($line = \fgets($client)) !== false) {
    if (\rtrim($line) === 'QUIT') {
        break;
    }
    \fwrite($client, 'echo:' . $line);
}
\fclose($client);
