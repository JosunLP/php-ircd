<?php
echo "Testing socket creation...\n";

// Create socket
$socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
if ($socket === false) {
    $errorCode = socket_last_error();
    $errorMsg = socket_strerror($errorCode);
    echo "Socket creation failed: {$errorCode} - {$errorMsg}\n";
    exit(1);
}
echo "Socket created successfully\n";

// Set socket options
socket_set_option($socket, SOL_SOCKET, SO_REUSEADDR, 1);
echo "Socket options set\n";

// Bind socket
if (!socket_bind($socket, '127.0.0.1', 6667)) {
    $errorCode = socket_last_error();
    $errorMsg = socket_strerror($errorCode);
    echo "Socket bind failed: {$errorCode} - {$errorMsg}\n";
    socket_close($socket);
    exit(1);
}
echo "Socket bound successfully\n";

// Set socket to listen
if (!socket_listen($socket, 50)) {
    $errorCode = socket_last_error();
    $errorMsg = socket_strerror($errorCode);
    echo "Socket listen failed: {$errorCode} - {$errorMsg}\n";
    socket_close($socket);
    exit(1);
}
echo "Socket listening successfully\n";

// Set non-blocking mode
socket_set_nonblock($socket);
echo "Socket set to non-blocking mode\n";

echo "Socket test completed successfully!\n";
echo "You can now try connecting with: telnet 127.0.0.1 6667\n";

// Keep the socket open for a few seconds to test
sleep(5);

socket_close($socket);
echo "Socket closed\n";
?>
