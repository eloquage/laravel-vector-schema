<?php

declare(strict_types=1);

use IllumaLaw\VectorSchema\Tests\Support\VectorTestQueryBuilder;

it('executes selectHybridVectorDistance macro', function () {
    $builder = VectorTestQueryBuilder::forDriver('sqlite');
    $vector = [0.1, 0.2, 0.3];

    $builder->from('test')->selectHybridVectorDistance('embedding', $vector, 'dist');
    $sql = $builder->toSql();

    expect($sql)->toContain('vec_distance_cosine');
});
