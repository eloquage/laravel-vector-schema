<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

it('calls createHnswIndex from macro', function () {
    if (Schema::hasTable('direct_macro_table')) {
        Schema::drop('direct_macro_table');
    }

    Schema::create('direct_macro_table', function (Blueprint $table) {
        $table->id();
        $table->vectorColumn('embedding', 3);
    });

    Schema::table('direct_macro_table', function (Blueprint $table) {
        $table->hnswIndex('embedding');
    });

    expect(Schema::hasTable('direct_macro_table'))->toBeTrue();
});

it('calls dropHnswIndex from macro', function () {
    if (Schema::hasTable('direct_macro_table')) {
        Schema::drop('direct_macro_table');
    }

    Schema::create('direct_macro_table', function (Blueprint $table) {
        $table->id();
        $table->vectorColumn('embedding', 3);
    });

    Schema::table('direct_macro_table', function (Blueprint $table) {
        $table->dropHnswIndex('embedding');
    });

    expect(Schema::hasTable('direct_macro_table'))->toBeTrue();
});
