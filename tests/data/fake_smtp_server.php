<?php

// Minimal ESMTP server for EmailNotificationsTest: `php fake_smtp_server.php TRANSCRIPT_FILE`.
// Prints the port it listens on, then serves connections one after the other until a QUIT or 20 seconds.
// Advertises STARTTLS (with a throw-away self-signed certificate), SIZE and AUTH CRAM-MD5 PLAIN LOGIN.
// Every line received is appended to the transcript as `[connN] <json string>`.

[$script, $transcript] = $argv;
$log = fopen($transcript, 'w');

$key = openssl_pkey_new(['private_key_bits' => 2048]);
$csr = openssl_csr_new(['commonName' => 'localhost'], $key);
$cert = openssl_csr_sign($csr, null, $key, 1);
$pem = tempnam(sys_get_temp_dir(), 'fake-smtp-');
openssl_x509_export($cert, $cert_pem);
openssl_pkey_export($key, $key_pem);
file_put_contents($pem, $cert_pem.$key_pem);
$context = stream_context_create(['ssl' => ['local_cert' => $pem, 'verify_peer' => false]]);

$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);
echo explode(':', stream_socket_get_name($server, false))[1], "\n";
flush();

$deadline = time() + 20;
$n = 0;
while (time() < $deadline && ($conn = @stream_socket_accept($server, 20))) {
    $n++;
    $tls = false;
    $send = fn (string $s) => fwrite($conn, $s);
    $note = fn (string $s) => fwrite($log, "[conn$n] ".json_encode($s)."\n");
    $send("220 fake ESMTP ready\r\n");
    $in_data = false;
    while (($line = fgets($conn)) !== false) {
        $note($line);
        if ($in_data) {
            if ($line === ".\r\n") {
                $in_data = false;
                $send("250 queued\r\n");
            }
            continue;
        }
        $cmd = strtoupper(trim($line));
        if (str_starts_with($cmd, 'EHLO')) {
            $send("250-fake\r\n250-SIZE 35882577\r\n".($tls ? '' : "250-STARTTLS\r\n")."250 AUTH CRAM-MD5 PLAIN LOGIN\r\n");
        } elseif (str_starts_with($cmd, 'STARTTLS')) {
            $send("220 go ahead\r\n");
            stream_socket_enable_crypto($conn, true, STREAM_CRYPTO_METHOD_TLS_SERVER);
            $tls = true;
        } elseif (str_starts_with($cmd, 'AUTH CRAM-MD5')) {
            $send('334 '.base64_encode('<1234.5678@fake>')."\r\n");
            $note(fgets($conn));
            $send("235 ok\r\n");
        } elseif (str_starts_with($cmd, 'AUTH')) {
            $send("235 ok\r\n");
        } elseif (str_starts_with($cmd, 'MAIL') || str_starts_with($cmd, 'RCPT') || str_starts_with($cmd, 'RSET')) {
            $send("250 ok\r\n");
        } elseif (str_starts_with($cmd, 'DATA')) {
            $in_data = true;
            $send("354 go\r\n");
        } elseif (str_starts_with($cmd, 'QUIT')) {
            $send("221 bye\r\n");
            break 2;
        } else {
            $send("502 no\r\n");
        }
    }
    fclose($conn);
}
unlink($pem);
