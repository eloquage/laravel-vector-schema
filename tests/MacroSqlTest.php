<?php

declare(strict_types=1);

use IllumaLaw\VectorSchema\Tests\Support\VectorTestQueryBuilder;

dataset('drivers', [
    'sqlite',
    'mysql',
    'mariadb',
    'sqlsrv',
    'singlestore',
    'pgsql',
]);

function getMockBuilder(string $driver): VectorTestQueryBuilder
{
    return VectorTestQueryBuilder::forDriver($driver);
}

it('generates correct distance SQL for each driver', function (string $driver) {
    $builder = getMockBuilder($driver);
    $builder->from('test');

    $vector = [0.1, 0.2, 0.3];

    $builder->selectHybridVectorDistance('embedding', $vector, 'dist');
    $sql = $builder->toSql();

    if ($driver === 'sqlite') {
        expect($sql)->toContain('vec_distance_cosine("embedding", ?)');
        expect($builder->getRawBindings()['select'])->toHaveCount(1);
    } elseif ($driver === 'mysql') {
        expect($sql)->toContain('VECTOR_DISTANCE(`embedding`, STRING_TO_VECTOR(?), \'COSINE\')');
    } elseif ($driver === 'mariadb') {
        expect($sql)->toContain('VEC_DISTANCE_COSINE(`embedding`, VEC_FromText(?))');
    } elseif ($driver === 'sqlsrv') {
        expect($sql)->toContain("VECTOR_DISTANCE('cosine', [embedding], CAST(? AS VECTOR(3)))");
    } elseif ($driver === 'singlestore') {
        expect($sql)->toContain('1 - (DOT_PRODUCT(`embedding`, JSON_ARRAY_PACK(?)) / (SQRT(DOT_PRODUCT(`embedding`, `embedding`)) * SQRT(DOT_PRODUCT(JSON_ARRAY_PACK(?), JSON_ARRAY_PACK(?)))))');
    } else {
        expect($sql)->toContain('embedding <=> \'[0.1,0.2,0.3]\'::vector');
    }
})->with('drivers');

it('generates correct similarity where SQL for each driver', function (string $driver) {
    $builder = getMockBuilder($driver);
    $builder->from('test');

    $vector = [0.1, 0.2, 0.3];

    $builder->whereHybridVectorSimilarTo('embedding', $vector, 0.7);
    $sql = strtolower($builder->toSql());

    if ($driver === 'sqlite') {
        expect($sql)->toContain('vec_distance_cosine("embedding", ?) <= cast(? as real)');
        expect($sql)->toContain('order by vec_distance_cosine("embedding", ?)');
    } elseif ($driver === 'mysql') {
        expect($sql)->toContain('vector_distance(`embedding`, string_to_vector(?), \'cosine\') <= ?');
        expect($sql)->toContain('order by vector_distance(`embedding`, string_to_vector(?), \'cosine\')');
    } elseif ($driver === 'mariadb') {
        expect($sql)->toContain('vec_distance_cosine(`embedding`, vec_fromtext(?)) <= ?');
        expect($sql)->toContain('order by vec_distance_cosine(`embedding`, vec_fromtext(?))');
    } elseif ($driver === 'sqlsrv') {
        expect($sql)->toContain("vector_distance('cosine', [embedding], cast(? as vector(3))) <= ?");
        expect($sql)->toContain("order by vector_distance('cosine', [embedding], cast(? as vector(3)))");
    } elseif ($driver === 'singlestore') {
        expect($sql)->toContain('(dot_product(`embedding`, json_array_pack(?)) / (sqrt(dot_product(`embedding`, `embedding`)) * sqrt(dot_product(json_array_pack(?), json_array_pack(?))))) >= ?');
        expect($sql)->toContain('order by (dot_product(`embedding`, json_array_pack(?)) / (sqrt(dot_product(`embedding`, `embedding`)) * sqrt(dot_product(json_array_pack(?), json_array_pack(?))))) desc');
        expect($builder->getBindings())->toHaveCount(7);
    } else {
        expect($sql)->toContain('embedding <=> \'[0.1,0.2,0.3]\'::vector <= ?');
        expect($sql)->toContain('order by embedding <=> \'[0.1,0.2,0.3]\'::vector');
    }
})->with('drivers');

it('generates correct distance less than SQL for each driver', function (string $driver) {
    $builder = getMockBuilder($driver);
    $builder->from('test');

    $vector = [0.1, 0.2, 0.3];

    $builder->whereHybridVectorDistanceLessThan('embedding', $vector, 0.3);
    $sql = strtolower($builder->toSql());

    if ($driver === 'sqlite') {
        expect($sql)->toContain('vec_distance_cosine("embedding", ?) < cast(? as real)');
    } elseif ($driver === 'mysql') {
        expect($sql)->toContain('vector_distance(`embedding`, string_to_vector(?), \'cosine\') < ?');
    } elseif ($driver === 'mariadb') {
        expect($sql)->toContain('vec_distance_cosine(`embedding`, vec_fromtext(?)) < ?');
    } elseif ($driver === 'sqlsrv') {
        expect($sql)->toContain("vector_distance('cosine', [embedding], cast(? as vector(3))) < ?");
    } elseif ($driver === 'singlestore') {
        expect($sql)->toContain('1 - (dot_product(`embedding`, json_array_pack(?)) / (sqrt(dot_product(`embedding`, `embedding`)) * sqrt(dot_product(json_array_pack(?), json_array_pack(?))))) < ?');
        expect($builder->getBindings())->toHaveCount(4);
    } else {
        expect($sql)->toContain('embedding <=> \'[0.1,0.2,0.3]\'::vector < ?');
    }
})->with('drivers');

it('generates correct order by distance SQL for each driver', function (string $driver) {
    $builder = getMockBuilder($driver);
    $builder->from('test');

    $vector = [0.1, 0.2, 0.3];

    $builder->orderByHybridVectorDistance('embedding', $vector, 'desc');
    $sql = strtolower($builder->toSql());

    if ($driver === 'sqlite') {
        expect($sql)->toContain('order by vec_distance_cosine("embedding", ?) desc');
    } elseif ($driver === 'mysql') {
        expect($sql)->toContain('order by vector_distance(`embedding`, string_to_vector(?), \'cosine\') desc');
    } elseif ($driver === 'mariadb') {
        expect($sql)->toContain('order by vec_distance_cosine(`embedding`, vec_fromtext(?)) desc');
    } elseif ($driver === 'sqlsrv') {
        expect($sql)->toContain("order by vector_distance('cosine', [embedding], cast(? as vector(3))) desc");
    } elseif ($driver === 'singlestore') {
        expect($sql)->toContain('order by 1 - (dot_product(`embedding`, json_array_pack(?)) / (sqrt(dot_product(`embedding`, `embedding`)) * sqrt(dot_product(json_array_pack(?), json_array_pack(?))))) desc');
        expect($builder->getBindings())->toHaveCount(3);
    } else {
        expect($sql)->toContain('order by embedding <=> \'[0.1,0.2,0.3]\'::vector desc');
    }
})->with('drivers');
