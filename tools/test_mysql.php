<?php
$passwords = ['root', 'opencart', 'admin', 'password', '', '123456', '1234', 'mysql'];
$connected = false;

foreach ($passwords as $p) {
    try {
        $m = @new mysqli('127.0.0.1', 'root', $p, '', 3306);
        if (!$m->connect_error) {
            echo "Connected successfully with password: [$p]\n";
            $connected = true;
            $res = $m->query("SHOW DATABASES;");
            echo "Databases:\n";
            while ($row = $res->fetch_row()) {
                echo " - " . $row[0] . "\n";
            }
            break;
        }
    } catch (Throwable $e) {
        // continue
    }
}

if (!$connected) {
    echo "Could not connect with common passwords.\n";
}
