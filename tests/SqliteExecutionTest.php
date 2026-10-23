<?php

declare(strict_types=1);

use IllumaLaw\VectorSchema\Tests\Support\VectorTestQueryBuilder;
use IllumaLaw\VectorSchema\VectorHelper;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Pdo\Sqlite;

it('filters sqlite vectors using numeric similarity thresholds', function () {
    config()->set('database.default', 'sqlite');
    config()->set('database.connections.sqlite', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]);
    DB::purge('sqlite');

    $connection = DB::connection('sqlite');
    $pdo = $connection->getPdo();
    if ($pdo instanceof Sqlite) {
        $pdo->createFunction('vec_distance_cosine', function (string $left, string $right): float {
            $a = VectorHelper::fromBlob($left);
            $b = VectorHelper::fromBlob($right);
            $dot = array_sum(array_map(fn (float $x, float $y): float => $x * $y, $a, $b));
            $magnitudeA = sqrt(array_sum(array_map(fn (float $x): float => $x * $x, $a)));
            $magnitudeB = sqrt(array_sum(array_map(fn (float $x): float => $x * $x, $b)));

            return 1 - ($dot / ($magnitudeA * $magnitudeB));
        }, 2);
    }

    Schema::connection('sqlite')->create('vector_rows_sqlite_test', function (Blueprint $table): void {
        $table->id();
        $table->vectorColumn('embedding', 2);
    });

    DB::connection('sqlite')->table('vector_rows_sqlite_test')->insert([
        ['id' => 1, 'embedding' => VectorHelper::toBlob([1.0, 0.0])],
        ['id' => 2, 'embedding' => VectorHelper::toBlob([0.0, 1.0])],
    ]);

    $similarQuery = new VectorTestQueryBuilder($connection, $connection->getQueryGrammar(), $connection->getPostProcessor());
    $similarQuery->from('vector_rows_sqlite_test');
    $similarIds = $similarQuery
        ->whereHybridVectorSimilarTo('embedding', [1.0, 0.0], minSimilarity: 0.9)
        ->pluck('id')
        ->all();

    expect($similarIds)->toBe([1]);

    $nearQuery = new VectorTestQueryBuilder($connection, $connection->getQueryGrammar(), $connection->getPostProcessor());
    $nearQuery->from('vector_rows_sqlite_test');
    $nearIds = $nearQuery
        ->whereHybridVectorDistanceLessThan('embedding', [1.0, 0.0], maxDistance: 0.1)
        ->pluck('id')
        ->all();

    expect($nearIds)->toBe([1]);
});
