<?php

namespace App\Support;

// Writes the database credentials to a private temporary options file so mysqldump and
// mysql never receive the password on the command line (where `ps` would show it).
class MysqlCredentials
{
    /**
     * @param  callable(string): mixed  $callback  receives the options file path
     */
    public static function with(callable $callback): mixed
    {
        $connection = config('database.connections.'.config('backup.connection', config('database.default')));
        $file = tempnam(sys_get_temp_dir(), 'mycnf');
        chmod($file, 0600);
        $escape = fn (?string $value): string => '"'.addcslashes((string) $value, '\\"').'"';
        $lines = ['[client]', 'user='.$escape($connection['username']), 'password='.$escape($connection['password'])];
        if (! empty($connection['unix_socket'])) {
            $lines[] = 'socket='.$escape($connection['unix_socket']);
        } else {
            $lines[] = 'host='.$escape($connection['host']);
            $lines[] = 'port='.(int) $connection['port'];
        }
        file_put_contents($file, implode("\n", $lines)."\n");

        try {
            return $callback($file);
        } finally {
            @unlink($file);
        }
    }
}
