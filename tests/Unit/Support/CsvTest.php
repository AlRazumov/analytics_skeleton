<?php

use App\Support\Csv;

it('formats numbers for Russian Excel and leaves missing values empty', function () {
    expect(Csv::number(1234567.891))->toBe('1234567,89')
        ->and(Csv::number(-0.05, 1))->toBe('-0,1')
        ->and(Csv::number(7, 0))->toBe('7')
        ->and(Csv::number(null))->toBe('')
        ->and(Csv::number(INF, 1))->toBe('');
});

it('builds a BOM-prefixed semicolon CSV and quotes cells that need it', function () {
    $csv = Csv::build([['#', 'Название'], ['1', 'Товар; "А"']]);

    expect($csv)->toBe("\xEF\xBB\xBF#;Название\n1;\"Товар; \"\"А\"\"\"\n");
});
