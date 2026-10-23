<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ColumnDefinition;
use Illuminate\Database\Schema\Grammars\PostgresGrammar;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery\MockInterface;

it('registers the vectorColumn macro on blueprint', function () {
    expect(Blueprint::hasMacro('vectorColumn'))->toBeTrue();
});

it('can create a vector column in a migration on sqlite', function () {
    Config::set('database.default', 'sqlite');
    Config::set('database.connections.sqlite.database', ':memory:');
    DB::purge('sqlite');

    Schema::create('test_vectors_sqlite', function (Blueprint $table) {
        $table->id();
        $table->vectorColumn('embedding', 1536);
    });

    expect(Schema::hasColumn('test_vectors_sqlite', 'embedding'))->toBe(true);
});

it('uses vectorWithDimensions type for pgsql, mysql, mariadb, sqlsrv', function (string $driver) {
    Config::set('database.default', $driver);
    Config::set("database.connections.{$driver}.driver", $driver);
    Config::set("database.connections.{$driver}.database", 'test');

    /** @var Connection&MockInterface $connection */
    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('getDriverName')->andReturn($driver);
    $connection->shouldReceive('getSchemaGrammar')->andReturn(new PostgresGrammar($connection));

    $blueprint = new Blueprint($connection, 'test_table');
    /** @var mixed $column */
    $column = $blueprint->vectorColumn('embedding', 1536);

    if (! $column instanceof ColumnDefinition) {
        throw new RuntimeException('The macro must return a column definition.');
    }

    expect($column->get('type') === 'vectorWithDimensions')->toBeTrue();
    expect($column->get('dimensions') === 1536)->toBeTrue();
})->with(['pgsql', 'mysql', 'mariadb', 'sqlsrv']);

it('uses vector type for singlestore', function () {
    $driver = 'singlestore';
    Config::set('database.default', $driver);
    Config::set("database.connections.{$driver}.driver", $driver);
    Config::set("database.connections.{$driver}.database", 'test');

    /** @var Connection&MockInterface $connection */
    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('getDriverName')->andReturn($driver);
    $connection->shouldReceive('getSchemaGrammar')->andReturn(new PostgresGrammar($connection));

    DB::shouldReceive('connection')->andReturn($connection);

    $blueprint = new Blueprint($connection, 'test_table');
    /** @var mixed $column */
    $column = $blueprint->vectorColumn('embedding', 1536);

    if (! $column instanceof ColumnDefinition) {
        throw new RuntimeException('The macro must return a column definition.');
    }

    expect($column->get('type') === 'vector')->toBeTrue();
    expect($column->get('length') === 1536)->toBeTrue();
});

it('can call hnswIndex macro', function () {
    if (Schema::hasTable('test_hnsw')) {
        Schema::drop('test_hnsw');
    }

    Schema::create('test_hnsw', function (Blueprint $table) {
        $table->id();
        $table->vectorColumn('embedding', 3);
    });

    // HNSW indexes are separate PostgreSQL statements and require the table to exist first.
    Schema::table('test_hnsw', function (Blueprint $table) {
        $table->hnswIndex('embedding');
    });

    expect(Schema::hasTable('test_hnsw'))->toBeTrue();
});

it('can call dropHnswIndex macro', function () {
    Schema::table('test_hnsw', function (Blueprint $table) {
        $table->dropHnswIndex('embedding');
    });

    expect(true)->toBe(true);
});
