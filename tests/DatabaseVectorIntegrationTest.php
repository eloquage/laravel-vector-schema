<?php

declare(strict_types=1);

use IllumaLaw\VectorSchema\Casts\VectorArray;
use IllumaLaw\VectorSchema\Tests\Support\VectorTestQueryBuilder;
use IllumaLaw\VectorSchema\VectorHelper;
use IllumaLaw\VectorSchema\VectorSchema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Pdo\Sqlite;

/**
 * @property int $id
 * @property array<int, float>|null $embedding
 */
class DatabaseVectorRecord extends Model
{
    protected $table = 'vector_schema_integration_rows';

    protected $casts = [
        'embedding' => VectorArray::class,
    ];

    public $timestamps = false;

    protected $guarded = [];
}

beforeEach(function (): void {
    $connection = DB::connection();
    $driver = $connection->getDriverName();

    if ($driver === 'pgsql') {
        VectorSchema::ensureExtension();
    }

    if ($driver === 'sqlite' && $connection->getPdo() instanceof Sqlite) {
        $connection->getPdo()->createFunction('vec_distance_cosine', function (string $left, string $right): float {
            $a = VectorHelper::fromBlob($left);
            $b = VectorHelper::fromBlob($right);
            $dot = array_sum(array_map(fn (float $x, float $y): float => $x * $y, $a, $b));
            $magnitudeA = sqrt(array_sum(array_map(fn (float $x): float => $x * $x, $a)));
            $magnitudeB = sqrt(array_sum(array_map(fn (float $x): float => $x * $x, $b)));

            return 1 - ($dot / ($magnitudeA * $magnitudeB));
        }, 2);
    }

    Schema::dropIfExists('vector_schema_integration_rows');
    Schema::create('vector_schema_integration_rows', function (Blueprint $table): void {
        $table->id();
        $table->vectorColumn('embedding', 2);
    });

    DatabaseVectorRecord::query()->create(['embedding' => [1.0, 0.0]]);
    DatabaseVectorRecord::query()->create(['embedding' => [0.0, 1.0]]);
});

it('round trips vectors through the active database column type', function (): void {
    $vectors = DatabaseVectorRecord::query()
        ->orderBy('id')
        ->get()
        ->map(fn (DatabaseVectorRecord $record): ?array => $record->embedding)
        ->all();

    expect($vectors)->toBe([
        [1.0, 0.0],
        [0.0, 1.0],
    ]);
});

it('filters and orders vectors by cosine distance on supported engines', function (): void {
    if (DB::connection()->getDriverName() === 'mysql') {
        $this->markTestSkipped('VECTOR_DISTANCE requires MySQL HeatWave on OCI or MySQL AI.');
    }

    $connection = DB::connection();
    $distanceQuery = new VectorTestQueryBuilder($connection, $connection->getQueryGrammar(), $connection->getPostProcessor());
    $distanceQuery->from('vector_schema_integration_rows');
    $distances = $distanceQuery
        ->select('id')
        ->selectHybridVectorDistance('embedding', [1.0, 0.0], 'distance')
        ->orderBy('distance')
        ->get();

    $first = $distances->first();
    $second = $distances->get(1);
    if ($first === null || $second === null || ! is_numeric($first->id) || ! is_numeric($first->distance) || ! is_numeric($second->distance)) {
        throw new RuntimeException('The vector distance query returned unexpected rows.');
    }

    expect((int) $first->id)->toBe(1)
        ->and((float) $first->distance)->toBe(0.0)
        ->and((float) $second->distance)->toBe(1.0);

    $similarQuery = new VectorTestQueryBuilder($connection, $connection->getQueryGrammar(), $connection->getPostProcessor());
    $similarQuery->from('vector_schema_integration_rows');
    $similarIds = $similarQuery
        ->whereHybridVectorSimilarTo('embedding', [1.0, 0.0], minSimilarity: 0.9)
        ->orderBy('id')
        ->pluck('id')
        ->all();

    expect($similarIds)->toBe([1]);
});
