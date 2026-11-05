<?php
namespace SrdFiles;

/** A short, read-only readiness probe. It never scans or exposes file content. */
final class AntivirusStatus
{
    public function __construct(private string $marker, private string $address) {}

    public function ready(): bool
    {
        $value = @file_get_contents($this->marker);
        $updated = is_string($value) && preg_match('/^[0-9]{10}\n?$/D', $value) ? (int) $value : 0;
        if ($updated < time() - 172800 || $updated > time() + 300) return false;

        $socket = @stream_socket_client($this->address, $errno, $error, 1);
        if (!$socket) return false;
        try {
            stream_set_timeout($socket, 1);
            $deadline = microtime(true) + 1;
            if (@fwrite($socket, "zPING\0") !== 6) return false;
            $reply = '';
            while (strlen($reply) < 5) {
                $remaining = $deadline - microtime(true);
                if ($remaining <= 0) return false;
                stream_set_timeout($socket, (int)$remaining, (int)(($remaining - (int)$remaining) * 1000000));
                $part = @fread($socket, 5 - strlen($reply));
                if (!is_string($part) || $part === '') return false;
                $reply .= $part;
            }
            return $reply === "PONG\0";
        } finally {
            fclose($socket);
        }
    }
}
