<?php

declare(strict_types=1);

namespace IllumaLaw\VectorSchema\Tests\Support;

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\Grammar;
use Illuminate\Database\Query\Grammars\MySqlGrammar;
use Illuminate\Database\Query\Grammars\PostgresGrammar;
use Illuminate\Database\Query\Grammars\SQLiteGrammar;
use Illuminate\Database\Query\Grammars\SqlServerGrammar;
use Illuminate\Database\Query\Processors\Processor;
use PDO;

/**
 * @method $this selectHybridVectorDistance(string $column, array<int, float> $vector, ?string $as = null)
 * @method $this whereHybridVectorSimilarTo(string $column, array<int, float> $vector, float $minSimilarity = 0.6, bool $order = true)
 * @method $this whereHybridVectorDistanceLessThan(string $column, array<int, float> $vector, float $maxDistance, string $boolean = 'and')
 * @method $this orderByHybridVectorDistance(string $column, array<int, float> $vector, string $direction = 'asc')
 * @method string toSql()
 */
final class VectorTestQueryBuilder extends Builder
{
    public static function forDriver(string $driver): self
    {
        $connection = new Connection(new PDO('sqlite::memory:'), 'testing', '', ['driver' => $driver]);
        $grammar = match ($driver) {
            'sqlite' => new SQLiteGrammar($connection),
            'mysql', 'mariadb', 'singlestore' => new MySqlGrammar($connection),
            'sqlsrv' => new SqlServerGrammar($connection),
            'pgsql' => new PostgresGrammar($connection),
            default => new Grammar($connection),
        };
        $connection->setQueryGrammar($grammar);

        return new self($connection, $grammar, new Processor);
    }
}
