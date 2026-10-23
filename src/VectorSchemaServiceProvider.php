<?php

declare(strict_types=1);

namespace IllumaLaw\VectorSchema;

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ColumnDefinition;
use Illuminate\Database\Schema\Grammars\MariaDbGrammar;
use Illuminate\Database\Schema\Grammars\MySqlGrammar;
use Illuminate\Database\Schema\Grammars\PostgresGrammar;
use Illuminate\Database\Schema\Grammars\SqlServerGrammar;
use Illuminate\Support\Facades\DB;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use UnexpectedValueException;

class VectorSchemaServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-vector-schema');
    }

    public function bootingPackage(): void
    {
        $this->registerGrammarMacros();

        Blueprint::macro('vectorColumn', function (string $column, int $dimensions): ColumnDefinition {
            /** @var string $driver */
            $driver = (string) DB::connection()->getDriverName();

            if (in_array($driver, ['pgsql', 'mysql', 'mariadb', 'sqlsrv'])) {
                /** @var ColumnDefinition $def */
                $def = $this->addColumn('vectorWithDimensions', $column, compact('dimensions'));

                return $def;
            }

            if ($driver === 'singlestore') {
                /** @var ColumnDefinition $res */
                $res = $this->addColumn('vector', $column, ['length' => $dimensions]);

                return $res;
            }

            /** @var ColumnDefinition $res */
            $res = $this->binary($column);

            return $res;
        });

        Blueprint::macro('hnswIndex', function (string $column, int $m = 16, int $efConstruction = 64): void {
            $table = (new \ReflectionClass($this))->getProperty('table')->getValue($this);
            if (! is_string($table)) {
                throw new UnexpectedValueException('Blueprint table name must be a string.');
            }

            VectorSchema::createHnswIndex($table, $column, $m, $efConstruction);
        });

        Blueprint::macro('dropHnswIndex', function (string $column): void {
            $table = (new \ReflectionClass($this))->getProperty('table')->getValue($this);
            if (! is_string($table)) {
                throw new UnexpectedValueException('Blueprint table name must be a string.');
            }

            VectorSchema::dropHnswIndex($table, $column);
        });

        $this->registerSelectHybridVectorDistanceMacro();
        $this->registerWhereHybridVectorSimilarToMacro();
        $this->registerWhereHybridVectorDistanceLessThanMacro();
        $this->registerOrderByHybridVectorDistanceMacro();
    }

    private function registerGrammarMacros(): void
    {
        $grammars = [
            PostgresGrammar::class,
            MySqlGrammar::class,
            MariaDbGrammar::class,
            SqlServerGrammar::class,
        ];

        foreach ($grammars as $grammar) {
            if (class_exists($grammar)) {
                $grammar::macro('typeVectorWithDimensions', function (ColumnDefinition $column): string {
                    $dimensions = $column->getAttributes()['dimensions'] ?? null;
                    $dimensions = is_int($dimensions) || is_string($dimensions) ? (string) $dimensions : '0';

                    return "vector({$dimensions})";
                });
            }
        }
    }

    private function registerSelectHybridVectorDistanceMacro(): void
    {
        Builder::macro('selectHybridVectorDistance',
            /** @param array<int, float> $vector */
            function (string $column, array $vector, ?string $as = null): Builder {
                $vector = VectorSchemaServiceProvider::normalizeVector($vector);
                /** @var string $driver */
                $driver = VectorSchemaServiceProvider::driverName($this);
                $alias = $as ?? "{$column}_distance";

                if ($driver === 'sqlite') {
                    $vectorBlob = VectorHelper::toBlob($vector);
                    $this->addBinding($vectorBlob, 'select');

                    $grammar = $this->getGrammar();

                    /** @var Builder $res */
                    $res = $this->addSelect([
                        $this->raw("vec_distance_cosine({$grammar->wrap($column)}, ?) as {$grammar->wrap($alias)}"),
                    ]);

                    return $res;
                }

                if ($driver === 'mysql') {
                    $grammar = $this->getGrammar();

                    $this->addBinding(json_encode($vector, JSON_THROW_ON_ERROR), 'select');
                    /** @var Builder $res */
                    $res = $this->addSelect([
                        $this->raw("VECTOR_DISTANCE({$grammar->wrap($column)}, STRING_TO_VECTOR(?), 'COSINE') as {$grammar->wrap($alias)}"),
                    ]);

                    return $res;
                }

                if ($driver === 'mariadb') {
                    $grammar = $this->getGrammar();

                    $this->addBinding(json_encode($vector, JSON_THROW_ON_ERROR), 'select');
                    /** @var Builder $res */
                    $res = $this->addSelect([
                        $this->raw("VEC_DISTANCE_COSINE({$grammar->wrap($column)}, VEC_FromText(?)) as {$grammar->wrap($alias)}"),
                    ]);

                    return $res;
                }

                if ($driver === 'sqlsrv') {
                    $grammar = $this->getGrammar();

                    $this->addBinding(json_encode($vector, JSON_THROW_ON_ERROR), 'select');
                    /** @var Builder $res */
                    $res = $this->addSelect([
                        $this->raw("VECTOR_DISTANCE('cosine', {$grammar->wrap($column)}, ?) as {$grammar->wrap($alias)}"),
                    ]);

                    return $res;
                }

                if ($driver === 'singlestore') {
                    $grammar = $this->getGrammar();

                    $this->addBinding(json_encode($vector, JSON_THROW_ON_ERROR), 'select');
                    /** @var Builder $res */
                    $res = $this->addSelect([
                        $this->raw("1 - DOT_PRODUCT({$grammar->wrap($column)}, JSON_ARRAY_PACK(?)) as {$grammar->wrap($alias)}"),
                    ]);

                    return $res;
                }

                $vectorLiteral = '['.implode(',', array_map(fn ($v) => (string) $v, $vector)).']';

                $grammar = $this->getGrammar();

                /** @var Builder $res */
                $res = $this->addSelect([
                    $this->raw("{$column} <=> '{$vectorLiteral}'::vector as {$grammar->wrap($alias)}"),
                ]);

                return $res;
            });
    }

    public static function driverName(Builder $builder): string
    {
        $connection = $builder->getConnection();
        if (! $connection instanceof Connection) {
            throw new \LogicException('The query builder must use a Laravel database connection.');
        }

        return $connection->getDriverName();
    }

    /**
     * @param  array<array-key, mixed>  $vector
     * @return list<float>
     */
    public static function normalizeVector(array $vector): array
    {
        if (! array_is_list($vector)) {
            throw new \InvalidArgumentException('A vector must be a list of numeric values.');
        }

        $normalized = [];
        foreach ($vector as $value) {
            if (! is_int($value) && ! is_float($value)) {
                throw new \InvalidArgumentException('A vector must be a list of numeric values.');
            }

            $normalized[] = (float) $value;
        }

        return $normalized;
    }

    private function registerWhereHybridVectorSimilarToMacro(): void
    {
        Builder::macro('whereHybridVectorSimilarTo',
            /** @param array<int, float> $vector */
            function (string $column, array $vector, float $minSimilarity = 0.6, bool $order = true): Builder {
                $vector = VectorSchemaServiceProvider::normalizeVector($vector);

                /** @var string $driver */
                $driver = VectorSchemaServiceProvider::driverName($this);
                $maxDistance = 1 - $minSimilarity;

                $grammar = $this->getGrammar();

                if ($driver === 'sqlite') {
                    $vectorBlob = VectorHelper::toBlob($vector);
                    $this->whereRaw("vec_distance_cosine({$grammar->wrap($column)}, ?) <= ?", [$vectorBlob, $maxDistance]);
                    if ($order) {
                        $this->orderByRaw("vec_distance_cosine({$grammar->wrap($column)}, ?)", [$vectorBlob]);
                    }

                    return $this;
                }

                if ($driver === 'mysql') {
                    $this->whereRaw("VECTOR_DISTANCE({$grammar->wrap($column)}, STRING_TO_VECTOR(?), 'COSINE') <= ?", [json_encode($vector, JSON_THROW_ON_ERROR), $maxDistance]);
                    if ($order) {
                        $this->orderByRaw("VECTOR_DISTANCE({$grammar->wrap($column)}, STRING_TO_VECTOR(?), 'COSINE')", [json_encode($vector, JSON_THROW_ON_ERROR)]);
                    }

                    return $this;
                }

                if ($driver === 'mariadb') {
                    $this->whereRaw("VEC_DISTANCE_COSINE({$grammar->wrap($column)}, VEC_FromText(?)) <= ?", [json_encode($vector, JSON_THROW_ON_ERROR), $maxDistance]);
                    if ($order) {
                        $this->orderByRaw("VEC_DISTANCE_COSINE({$grammar->wrap($column)}, VEC_FromText(?))", [json_encode($vector, JSON_THROW_ON_ERROR)]);
                    }

                    return $this;
                }

                if ($driver === 'sqlsrv') {
                    $this->whereRaw("VECTOR_DISTANCE('cosine', {$grammar->wrap($column)}, ?) <= ?", [json_encode($vector, JSON_THROW_ON_ERROR), $maxDistance]);
                    if ($order) {
                        $this->orderByRaw("VECTOR_DISTANCE('cosine', {$grammar->wrap($column)}, ?)", [json_encode($vector, JSON_THROW_ON_ERROR)]);
                    }

                    return $this;
                }

                if ($driver === 'singlestore') {
                    $this->whereRaw("DOT_PRODUCT({$grammar->wrap($column)}, JSON_ARRAY_PACK(?)) >= ?", [json_encode($vector, JSON_THROW_ON_ERROR), $minSimilarity]);
                    if ($order) {
                        $this->orderByRaw("DOT_PRODUCT({$grammar->wrap($column)}, JSON_ARRAY_PACK(?)) DESC", [json_encode($vector, JSON_THROW_ON_ERROR)]);
                    }

                    return $this;
                }

                $vectorLiteral = '['.implode(',', array_map(fn ($v) => (string) $v, $vector)).']';
                $this->whereRaw("{$column} <=> '{$vectorLiteral}'::vector <= ?", [$maxDistance]);
                if ($order) {
                    $this->orderByRaw("{$column} <=> '{$vectorLiteral}'::vector");
                }

                return $this;
            });
    }

    private function registerWhereHybridVectorDistanceLessThanMacro(): void
    {
        Builder::macro('whereHybridVectorDistanceLessThan',
            /** @param array<int, float> $vector */
            function (string $column, array $vector, float $maxDistance, string $boolean = 'and'): Builder {
                $vector = VectorSchemaServiceProvider::normalizeVector($vector);

                /** @var string $driver */
                $driver = VectorSchemaServiceProvider::driverName($this);
                $method = $boolean === 'or' ? 'orWhereRaw' : 'whereRaw';

                $grammar = $this->getGrammar();

                if ($driver === 'sqlite') {
                    $vectorBlob = VectorHelper::toBlob($vector);

                    /** @var Builder $res */
                    $res = $this->{$method}("vec_distance_cosine({$grammar->wrap($column)}, ?) < ?", [$vectorBlob, $maxDistance]);

                    return $res;
                }

                if ($driver === 'mysql') {
                    /** @var Builder $res */
                    $res = $this->{$method}("VECTOR_DISTANCE({$grammar->wrap($column)}, STRING_TO_VECTOR(?), 'COSINE') < ?", [json_encode($vector, JSON_THROW_ON_ERROR), $maxDistance]);

                    return $res;
                }

                if ($driver === 'mariadb') {
                    /** @var Builder $res */
                    $res = $this->{$method}("VEC_DISTANCE_COSINE({$grammar->wrap($column)}, VEC_FromText(?)) < ?", [json_encode($vector, JSON_THROW_ON_ERROR), $maxDistance]);

                    return $res;
                }

                if ($driver === 'sqlsrv') {
                    /** @var Builder $res */
                    $res = $this->{$method}("VECTOR_DISTANCE('cosine', {$grammar->wrap($column)}, ?) < ?", [json_encode($vector, JSON_THROW_ON_ERROR), $maxDistance]);

                    return $res;
                }

                if ($driver === 'singlestore') {
                    /** @var Builder $res */
                    $res = $this->{$method}("1 - DOT_PRODUCT({$grammar->wrap($column)}, JSON_ARRAY_PACK(?)) < ?", [json_encode($vector, JSON_THROW_ON_ERROR), $maxDistance]);

                    return $res;
                }

                $vectorLiteral = '['.implode(',', array_map(fn ($v) => (string) $v, $vector)).']';

                /** @var Builder $res */
                $res = $this->{$method}("{$column} <=> '{$vectorLiteral}'::vector < ?", [$maxDistance]);

                return $res;
            });
    }

    private function registerOrderByHybridVectorDistanceMacro(): void
    {
        Builder::macro('orderByHybridVectorDistance',
            /** @param array<int, float> $vector */
            function (string $column, array $vector, string $direction = 'asc'): Builder {
                $vector = VectorSchemaServiceProvider::normalizeVector($vector);

                /** @var string $driver */
                $driver = VectorSchemaServiceProvider::driverName($this);
                $dir = ($direction === 'desc' ? ' DESC' : '');

                $grammar = $this->getGrammar();

                if ($driver === 'sqlite') {
                    $vectorBlob = VectorHelper::toBlob($vector);

                    /** @var Builder $res */
                    $res = $this->orderByRaw("vec_distance_cosine({$grammar->wrap($column)}, ?)".$dir, [$vectorBlob]);

                    return $res;
                }

                if ($driver === 'mysql') {
                    /** @var Builder $res */
                    $res = $this->orderByRaw("VECTOR_DISTANCE({$grammar->wrap($column)}, STRING_TO_VECTOR(?), 'COSINE')".$dir, [json_encode($vector, JSON_THROW_ON_ERROR)]);

                    return $res;
                }

                if ($driver === 'mariadb') {
                    /** @var Builder $res */
                    $res = $this->orderByRaw("VEC_DISTANCE_COSINE({$grammar->wrap($column)}, VEC_FromText(?))".$dir, [json_encode($vector, JSON_THROW_ON_ERROR)]);

                    return $res;
                }

                if ($driver === 'sqlsrv') {
                    /** @var Builder $res */
                    $res = $this->orderByRaw("VECTOR_DISTANCE('cosine', {$grammar->wrap($column)}, ?)".$dir, [json_encode($vector, JSON_THROW_ON_ERROR)]);

                    return $res;
                }

                if ($driver === 'singlestore') {
                    $singlestoreDir = $direction === 'desc' ? ' ASC' : ' DESC';

                    /** @var Builder $res */
                    $res = $this->orderByRaw("DOT_PRODUCT({$grammar->wrap($column)}, JSON_ARRAY_PACK(?))".$singlestoreDir, [json_encode($vector, JSON_THROW_ON_ERROR)]);

                    return $res;
                }

                $vectorLiteral = '['.implode(',', array_map(fn ($v) => (string) $v, $vector)).']';

                /** @var Builder $res */
                $res = $this->orderByRaw("{$column} <=> '{$vectorLiteral}'::vector".$dir);

                return $res;
            });
    }
}
