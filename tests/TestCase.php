<?php

declare(strict_types=1);

namespace IllumaLaw\VectorSchema\Tests;

use IllumaLaw\VectorSchema\VectorSchemaServiceProvider;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Support\Facades\Event;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            VectorSchemaServiceProvider::class,
        ];
    }

    public function getEnvironmentSetUp($app): void
    {
        $database = $this->environmentValue('DB_CONNECTION', 'testing');
        config()->set('database.default', $database);

        config()->set('database.connections.mysql', [
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'port' => '3306',
            'database' => 'testing',
            'username' => 'root',
            'password' => '',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'strict' => true,
            'engine' => null,
        ]);

        config()->set('database.connections.mariadb', [
            'driver' => 'mariadb',
            'host' => '127.0.0.1',
            'port' => '3307',
            'database' => 'testing',
            'username' => 'root',
            'password' => '',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'strict' => true,
            'engine' => null,
        ]);

        config()->set('database.connections.pgsql', [
            'driver' => 'pgsql',
            'host' => $this->environmentValue('DB_HOST', '127.0.0.1'),
            'port' => $this->environmentValue('DB_PORT', '5432'),
            'database' => $this->environmentValue('DB_DATABASE', 'testing'),
            'username' => $this->environmentValue('DB_USERNAME', 'postgres'),
            'password' => $this->environmentValue('DB_PASSWORD', 'password'),
            'charset' => 'utf8',
            'prefix' => '',
            'schema' => 'public',
            'sslmode' => 'prefer',
        ]);

        Event::listen(ConnectionEstablished::class, function (ConnectionEstablished $event): void {
            $connection = $event->connection;

            if ($connection->getDriverName() !== 'sqlite') {
                return;
            }

            $extensionPath = '/usr/local/lib/sqlite-vec/vec0.so';

            if (! file_exists($extensionPath)) {
                return;
            }

            $pdo = $connection->getPdo();

            try {
                $pdo->exec('SELECT load_extension('.$pdo->quote($extensionPath).')');
            } catch (\PDOException $e) {
                //
            }
        });
    }

    private function environmentValue(string $key, string $default): string
    {
        $value = getenv($key);

        return is_string($value) && $value !== '' ? $value : $default;
    }
}
