<?php

declare(strict_types=1);

use IllumaLaw\VectorSchema\Casts\VectorArray;
use IllumaLaw\VectorSchema\VectorHelper;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Facades\DB;

class TestVectorModel extends Model
{
    protected $casts = [
        'embedding' => VectorArray::class,
    ];

    private string $testDriver = 'sqlite';

    public function setTestDriver(string $driver): void
    {
        $this->testDriver = $driver;
    }

    public function getConnection(): Connection
    {
        return new Connection(new PDO('sqlite::memory:'), 'testing', '', ['driver' => $this->testDriver]);
    }
}

it('can cast a vector array to a blob for sqlite', function () {
    $vector = [1.0, 2.0, 3.0];

    $cast = new VectorArray;
    $model = new TestVectorModel;
    $model->setTestDriver('sqlite');

    $result = $cast->set($model, 'embedding', $vector, []);

    expect($result)->toBe(VectorHelper::toBlob($vector));
});

it('can cast a blob back to a vector array', function () {
    $vector = [1.0, 2.0, 3.0];
    $blob = VectorHelper::toBlob($vector);

    $cast = new VectorArray;
    $model = new TestVectorModel;
    $result = $cast->get($model, 'embedding', $blob, []);

    expect($result)->toBe($vector);
});

it('can cast a postgres vector string back to a vector array', function () {
    $vector = [1.0, 2.0, 3.0];
    $pgString = '[1.0,2.0,3.0]';

    $cast = new VectorArray;
    $model = new TestVectorModel;
    $result = $cast->get($model, 'embedding', $pgString, []);

    expect($result)->toBe($vector);
});

it('returns null for null value in get', function () {
    $cast = new VectorArray;
    $model = new TestVectorModel;
    $result = $cast->get($model, 'embedding', null, []);

    expect($result)->toBeNull();
});

it('validates vector if it is already an array in get', function () {
    $cast = new VectorArray;
    $model = new TestVectorModel;
    $result = $cast->get($model, 'embedding', [1, 'invalid', 3], []);

    expect($result)->toBe([1.0, 0.0, 3.0]);
});

it('handles invalid json string in get', function () {
    $cast = new VectorArray;
    $model = new TestVectorModel;

    $result = $cast->get($model, 'embedding', '[1.0, invalid]', []);
    expect($result)->toBeNull();

    $result = $cast->get($model, 'embedding', '{"invalid":}', []);
    expect($result)->toBeNull();

    $tiny = str_repeat("\x01", 16);
    $result = $cast->get($model, 'embedding', $tiny, []);
    expect($result)->toBeNull();
});

it('handles non-array json in get', function () {
    $cast = new VectorArray;
    $model = new TestVectorModel;
    $result = $cast->get($model, 'embedding', '"just a string"', []);

    expect($result)->toBeNull();
});

it('handles invalid blob length in get', function () {
    $cast = new VectorArray;
    $model = new TestVectorModel;
    $result = $cast->get($model, 'embedding', 'abc', []);

    expect($result)->toBeNull();
});

it('returns null for other types in get', function () {
    $cast = new VectorArray;
    $model = new TestVectorModel;
    $result = $cast->get($model, 'embedding', 123, []);

    expect($result)->toBeNull();
});

it('returns null for null value in set', function () {
    $cast = new VectorArray;
    $model = new TestVectorModel;
    $result = $cast->set($model, 'embedding', null, []);

    expect($result)->toBeNull();
});

it('throws exception for non-array value in set', function () {
    $cast = new VectorArray;
    $model = new TestVectorModel;

    expect(fn () => $cast->set($model, 'embedding', 'not an array', []))
        ->toThrow(InvalidArgumentException::class, 'The embedding attribute must be an array of floats.');
});

it('returns postgres literal for pgsql driver in set', function () {
    $cast = new VectorArray;
    $model = new TestVectorModel;
    $model->setTestDriver('pgsql');

    $vector = [1.0, 2.0];
    $result = $cast->set($model, 'embedding', $vector, []);

    expect($result)->toBe(VectorHelper::toPostgresLiteral($vector));
});

it('converts vectors using each native driver function when setting a cast', function (string $driver, string $expectedSql) {
    $cast = new VectorArray;
    $model = new TestVectorModel;
    $model->setTestDriver($driver);

    $result = $cast->set($model, 'embedding', [1.0, 2.0], []);
    if (! $result instanceof Expression) {
        throw new RuntimeException('The cast must return a driver expression.');
    }

    expect($result->getValue(DB::connection()->getQueryGrammar()))->toBe($expectedSql);
})->with([
    ['mysql', "STRING_TO_VECTOR('[1,2]')"],
    ['mariadb', "VEC_FromText('[1,2]')"],
    ['sqlsrv', "CAST('[1,2]' AS VECTOR(2))"],
    ['singlestore', "JSON_ARRAY_PACK('[1,2]')"],
]);

it('returns raw array for unknown drivers in set', function () {
    $cast = new VectorArray;
    $model = new TestVectorModel;
    $model->setTestDriver('unknown');

    $vector = [1.0, 2.0];
    $result = $cast->set($model, 'embedding', $vector, []);

    expect($result)->toBe($vector);
});
