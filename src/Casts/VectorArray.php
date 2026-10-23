<?php

declare(strict_types=1);

namespace IllumaLaw\VectorSchema\Casts;

use IllumaLaw\VectorSchema\VectorHelper;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Expression;
use InvalidArgumentException;

/**
 * @implements CastsAttributes<array<int, float>|null, mixed>
 */
class VectorArray implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     * @return array<int, float>|null
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?array
    {
        if ($value === null) {
            return null;
        }

        if (is_array($value)) {
            return $this->validateVector($value);
        }

        if (is_string($value)) {
            $trimmed = trim($value);

            if (str_starts_with($trimmed, '[') && str_ends_with($trimmed, ']')) {
                try {
                    $decoded = json_decode($trimmed, true, 512, JSON_THROW_ON_ERROR);
                    if (is_array($decoded)) {
                        return $this->validateVector($decoded);
                    }
                } catch (\JsonException) {
                }
            }

            $isProbablyBinary = ! mb_check_encoding($value, 'UTF-8');

            if (! $isProbablyBinary) {
                try {
                    $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
                    if (is_array($decoded)) {
                        return $this->validateVector($decoded);
                    }
                } catch (\JsonException) {
                }
            }

            if (strlen($value) > 0 && strlen($value) % 4 === 0) {
                try {
                    $vector = VectorHelper::fromBlob($value);
                    if (count($vector) > 0) {
                        if (! $isProbablyBinary) {
                            foreach ($vector as $val) {
                                if (abs((float) $val) > 1e10 || (abs((float) $val) < 1e-10 && $val != 0)) {
                                    return null;
                                }
                            }
                        }

                        return $this->validateVector($vector);
                    }
                } catch (\Throwable) {
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        if ($value === null) {
            return null;
        }

        if (! is_array($value)) {
            throw new InvalidArgumentException("The {$key} attribute must be an array of floats.");
        }

        $vector = $this->validateVector($value);

        $connection = $model->getConnection();
        $driver = $connection->getDriverName();

        if ($driver === 'sqlite') {
            return VectorHelper::toBlob($vector);
        }

        if ($driver === 'pgsql') {
            return VectorHelper::toPostgresLiteral($vector);
        }

        if ($driver === 'mysql') {
            return new Expression('STRING_TO_VECTOR('.self::vectorLiteral($vector).')');
        }

        if ($driver === 'mariadb') {
            return new Expression('VEC_FromText('.self::vectorLiteral($vector).')');
        }

        if ($driver === 'sqlsrv') {
            return new Expression('CAST('.self::vectorLiteral($vector).' AS VECTOR('.count($vector).'))');
        }

        if ($driver === 'singlestore') {
            return new Expression('JSON_ARRAY_PACK('.self::vectorLiteral($vector).')');
        }

        return $vector;
    }

    /**
     * @param  array<mixed, mixed>  $vector
     * @return array<int, float>
     */
    private function validateVector(array $vector): array
    {
        return array_values(array_map(
            fn ($v) => is_numeric($v) ? (float) $v : 0.0,
            $vector
        ));
    }

    /**
     * The validated vector is encoded as numeric JSON, so it can safely be
     * embedded as a SQL string literal for engines that require conversion
     * functions around vector values.
     *
     * @param  array<int, float>  $vector
     */
    private static function vectorLiteral(array $vector): string
    {
        return "'".json_encode($vector, JSON_THROW_ON_ERROR)."'";
    }
}
