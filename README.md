# Laravel Vector Schema

[![Tests](https://github.com/eloquage/laravel-vector-schema/actions/workflows/run-tests.yml/badge.svg)](https://github.com/eloquage/laravel-vector-schema/actions)
[![Latest Stable Version](https://img.shields.io/packagist/v/illuma-law/laravel-vector-schema?label=Version)](https://packagist.org/packages/illuma-law/laravel-vector-schema)
[![License](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE.md)

![Colored vector embeddings flowing through a schema-aware database query](docs/images/vector-schema-banner.png)

Portable vector columns, casts, and similarity queries for Laravel.

## Requirements

- PHP 8.3 or later
- Laravel 11, 12, or 13
- A supported database with its vector features enabled

| Database | Vector storage and distance support | Notes |
| --- | --- | --- |
| PostgreSQL | `vector` and pgvector cosine distance | Install the pgvector extension. |
| MySQL 9.0+ | Native `VECTOR` and [`VECTOR_DISTANCE`](https://dev.mysql.com/doc/refman/9.7/en/vector-functions.html) | Distance functions require MySQL HeatWave on OCI or MySQL AI. |
| MariaDB 11.7+ | Native `VECTOR` and [`VEC_DISTANCE_COSINE`](https://mariadb.com/docs/server/reference/sql-functions/vector-functions/vec_distance_cosine) | Uses MariaDB's vector functions. |
| SQL Server 2025 / Azure SQL | Native `VECTOR` and [`VECTOR_DISTANCE`](https://learn.microsoft.com/en-us/sql/t-sql/functions/vector-distance-transact-sql?view=sql-server-ver17) | Vector support depends on the server edition and update policy. |
| SingleStore | Vector values and [`DOT_PRODUCT`](https://docs.singlestore.com/db/v9.1/reference/sql-reference/vector-functions/dot-product/) | Cosine similarity is computed from dot products and vector magnitudes. |
| SQLite | BLOB storage and [`vec_distance_cosine`](https://github.com/asg017/sqlite-vec) | Load the `sqlite-vec` extension on each connection. |

CI exercises vector columns and queries on SQLite, PostgreSQL, and MariaDB. Its MySQL Community job verifies vector storage and casts; MySQL distance functions require HeatWave on OCI or MySQL AI. SQL Server and SingleStore are covered by SQL-generation and cast-expression tests, not live database integration tests.

## Install

```bash
composer require illuma-law/laravel-vector-schema
```

Laravel discovers the service provider automatically. It registers the `vectorColumn`, `hnswIndex`, and `dropHnswIndex` schema macros and the vector query macros.

## Define a vector column

Use `vectorColumn` in a migration. Pass the vector dimension required by your embedding model.

```php
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use IllumaLaw\VectorSchema\VectorSchema;

VectorSchema::ensureExtension(); // Creates pgvector when using PostgreSQL.

Schema::create('documents', function (Blueprint $table): void {
    $table->id();
    $table->text('content');
    $table->vectorColumn('embedding', 3)->nullable();
    $table->timestamps();
});

Schema::table('documents', function (Blueprint $table): void {
    $table->hnswIndex('embedding'); // PostgreSQL only; ignored by other drivers.
});
```

## Cast vectors to PHP arrays

```php
use Illuminate\Database\Eloquent\Model;
use IllumaLaw\VectorSchema\Casts\VectorArray;

class Document extends Model
{
    protected $casts = [
        'embedding' => VectorArray::class,
    ];
}

$document = new Document;
$document->content = 'A short example';
$document->embedding = [0.1, 0.5, -0.3];
$document->save();
```

## Search by cosine similarity

```php
$queryVector = [0.1, 0.5, -0.3];

$documents = Document::query()
    ->whereHybridVectorSimilarTo(
        column: 'embedding',
        vector: $queryVector,
        minSimilarity: 0.7,
    )
    ->limit(10)
    ->get();
```

Other query macros are `selectHybridVectorDistance`, `whereHybridVectorDistanceLessThan`, and `orderByHybridVectorDistance`. Distances sort ascending for nearest-first results.

## Run tests

```bash
composer install
composer test
composer analyse
composer format -- --test
```

See the [changelog](CHANGELOG.md) for release notes.
