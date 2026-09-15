<?php

use Eloquage\Score\Score;

function score_fixture(string $name): string
{
    return __DIR__.'/fixtures/'.$name;
}

/** @param array<string, mixed>|string $document */
function score_with_document(array|string $document, callable $callback): mixed
{
    $path = tempnam(sys_get_temp_dir(), 'eloquage-score-');
    if ($path === false) {
        throw new RuntimeException('Could not create a score test file.');
    }

    file_put_contents($path, is_string($document) ? $document : json_encode($document, JSON_THROW_ON_ERROR));

    try {
        return $callback($path);
    } finally {
        unlink($path);
    }
}

function score_tree_chain(int $internalNodes): array
{
    $node = ['value' => 1.0];

    for ($index = 0; $index < $internalNodes; $index++) {
        $node = [
            'feature' => 'x',
            'threshold' => 0.0,
            'left' => $node,
            'right' => ['value' => 2.0],
        ];
    }

    return $node;
}

it('preserves the identity entrypoint for fresh and loaded scores', function () {
    expect((new Score)->name())->toBe('score')
        ->and(Score::load(score_fixture('linear-value.json'))->name())->toBe('score');
});

it('loads an identity linear model and accepts ordered or named features', function () {
    $score = Score::load(score_fixture('linear-value.json'));

    expect($score->predict([3, 2]))->toBe(4.5)
        ->and($score->predict(['x2' => 2.0, 'x1' => 3.0]))->toBe(4.5);
});

it('uses a stable sigmoid and returns class zero then class one probabilities', function () {
    $score = Score::load(score_fixture('linear-probability.json'));

    expect($score->predict([0, 0]))->toBe(0.5)
        ->and($score->predictProba([0, 0]))->toEqual([0.5, 0.5])
        ->and($score->predict([1000, 0]))->toBeGreaterThan(0.999)
        ->and($score->predict([-1000, 0]))->toBeLessThan(0.001);
});

it('routes tree equality left and aggregates forest means', function () {
    $tree = Score::load(score_fixture('tree-value.json'));
    $forest = Score::load(score_fixture('forest-value.json'));

    expect($tree->predict(['income' => 10, 'age' => 18]))->toBe(0.2)
        ->and($tree->predict([19, 10]))->toBe(0.8)
        ->and($forest->predict([0]))->toBe(0.6);
});

it('rejects prediction before a model is loaded and probability requests for value models', function () {
    expect(fn () => (new Score)->predict([1]))->toThrow(LogicException::class)
        ->and(fn () => (new Score)->predictProba([1]))->toThrow(LogicException::class)
        ->and(fn () => Score::load(score_fixture('linear-value.json'))->predictProba([1, 2]))
        ->toThrow(LogicException::class);
});

it('rejects missing, extra, non-contiguous, non-numeric, and non-finite features', function () {
    $score = Score::load(score_fixture('linear-value.json'));

    foreach ([
        [[3], InvalidArgumentException::class],
        [[0 => 3, 2 => 2], InvalidArgumentException::class],
        [['x1' => 3], InvalidArgumentException::class],
        [['x1' => 3, 'x2' => 2, 'x3' => 1], InvalidArgumentException::class],
        [['x1' => true, 'x2' => 2], InvalidArgumentException::class],
        [['x1' => [3], 'x2' => 2], InvalidArgumentException::class],
        [[NAN, 2], InvalidArgumentException::class],
        [[INF, 2], InvalidArgumentException::class],
        [[3, 2.0, 1], InvalidArgumentException::class],
    ] as [$features, $exception]) {
        expect(fn () => $score->predict($features))->toThrow($exception);
    }
});

it('rejects malformed and unsupported model documents', function () {
    expect(fn () => Score::load(score_fixture('malformed-model.json')))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Score::load(score_fixture('invalid-model.json')))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Score::load('/definitely/missing/score-model.json'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Score::load('https://example.test/model.json'))->toThrow(InvalidArgumentException::class);

    foreach ([
        ['format' => 'other', 'version' => 1, 'type' => 'linear'],
        ['format' => 'eloquage-score', 'version' => 2, 'type' => 'linear'],
        ['format' => 'eloquage-score', 'version' => 1, 'type' => 'unknown'],
        ['format' => 'eloquage-score', 'version' => 1, 'type' => 'linear', 'features' => ['x', 'x']],
        ['format' => 'eloquage-score', 'version' => 1, 'type' => 'linear', 'features' => ['x'], 'output' => 'value', 'weights' => [[1]], 'bias' => 0],
        ['format' => 'eloquage-score', 'version' => 1, 'type' => 'linear', 'features' => ['x'], 'output' => 'binary_probability', 'weights' => [1], 'bias' => 0, 'activation' => 'identity'],
    ] as $document) {
        expect(fn () => score_with_document($document, fn (string $path) => Score::load($path)))
            ->toThrow(InvalidArgumentException::class);
    }
});

it('rejects malformed, deep, oversized, and out-of-range tree shapes', function () {
    $base = [
        'format' => 'eloquage-score',
        'version' => 1,
        'type' => 'tree',
        'features' => ['x'],
        'output' => 'value',
    ];

    foreach ([
        [...$base, 'root' => ['feature' => 'missing', 'threshold' => 0, 'left' => ['value' => 0], 'right' => ['value' => 1]]],
        [...$base, 'root' => ['feature' => 'x', 'threshold' => 'not-a-number', 'left' => ['value' => 0], 'right' => ['value' => 1]]],
        [...$base, 'root' => ['feature' => 'x', 'threshold' => 0, 'left' => ['value' => 0]]],
        [...$base, 'root' => score_tree_chain(9)],
    ] as $document) {
        expect(fn () => score_with_document($document, fn (string $path) => Score::load($path)))
            ->toThrow(InvalidArgumentException::class);
    }

    $probabilityTree = [...$base, 'output' => 'binary_probability', 'root' => ['value' => 1.1]];
    expect(fn () => score_with_document($probabilityTree, fn (string $path) => Score::load($path)))
        ->toThrow(InvalidArgumentException::class);

    $forest = [
        'format' => 'eloquage-score',
        'version' => 1,
        'type' => 'forest',
        'features' => ['x'],
        'output' => 'value',
        'aggregation' => 'sum',
        'trees' => [['value' => 1]],
    ];
    expect(fn () => score_with_document($forest, fn (string $path) => Score::load($path)))
        ->toThrow(InvalidArgumentException::class);

    $forest['aggregation'] = 'mean';
    $forest['trees'] = array_fill(0, 33, ['value' => 1]);
    expect(fn () => score_with_document($forest, fn (string $path) => Score::load($path)))
        ->toThrow(InvalidArgumentException::class);
});

it('rejects non-finite calculation results without exposing parser classes', function () {
    $document = [
        'format' => 'eloquage-score',
        'version' => 1,
        'type' => 'linear',
        'features' => ['x'],
        'output' => 'value',
        'weights' => [1.0e308],
        'bias' => 0.0,
    ];

    score_with_document($document, function (string $path): void {
        $score = Score::load($path);

        expect(fn () => $score->predict([1.0e308]))->toThrow(OverflowException::class);
    });

    expect(get_class_methods(Score::class))->toEqualCanonicalizing(['__construct', 'name', 'load', 'predict', 'predictProba']);
});

it('supports probability tree outputs through the same public interface', function () {
    $document = [
        'format' => 'eloquage-score',
        'version' => 1,
        'type' => 'tree',
        'features' => ['x'],
        'output' => 'binary_probability',
        'root' => [
            'feature' => 'x',
            'threshold' => 0,
            'left' => ['value' => 0.2],
            'right' => ['value' => 0.8],
        ],
    ];

    score_with_document($document, function (string $path): void {
        $score = Score::load($path);

        expect($score->predictProba([-1]))->toEqual([0.8, 0.2]);

        $distribution = $score->predictProba([1]);
        expect(abs($distribution[0] - 0.2))->toBeLessThan(0.0000001)
            ->and(abs($distribution[1] - 0.8))->toBeLessThan(0.0000001);
    });
});
