<?php

namespace Eloquage\Score;

/**
 * Local, inference-only entrypoint for the eloquage/score package.
 */
final class Score
{
    /** @var array<string, mixed>|null */
    private ?array $model = null;

    /**
     * Keep the no-argument identity entrypoint available for package users.
     *
     * @param array<string, mixed>|null $model
     */
    public function __construct(?array $model = null)
    {
        $this->model = $model;
    }

    public function name(): string
    {
        return 'score';
    }

    public static function load(string $path): self
    {
        if ($path === '' || preg_match('/^[a-zA-Z][a-zA-Z0-9+.-]*:/', $path) === 1) {
            throw new \InvalidArgumentException('Score models must be loaded from a local file path.');
        }

        try {
            if (! is_file($path) || ! is_readable($path)) {
                throw new \InvalidArgumentException('Score model path is not a readable file.');
            }

            $contents = file_get_contents($path);
        } catch (\InvalidArgumentException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw new \InvalidArgumentException('Score model path is not a readable file.', 0, $exception);
        }

        if ($contents === false) {
            throw new \InvalidArgumentException('Score model path is not a readable file.');
        }

        try {
            $document = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \InvalidArgumentException('Score model contains invalid JSON.', 0, $exception);
        }

        return new self(self::normalizeDocument($document));
    }

    /**
     * @param array<int|string, int|float> $features
     */
    public function predict(array $features): float
    {
        $model = $this->loadedModel();
        $values = $this->canonicalizeFeatures($features, $model['features']);

        if ($model['type'] === 'linear') {
            $prediction = $this->predictLinear($model, $values);
        } elseif ($model['type'] === 'tree') {
            $prediction = $this->predictTree($model['root'], $values);
        } else {
            $sum = 0.0;

            foreach ($model['trees'] as $tree) {
                $sum += $this->predictTree($tree, $values);

                if (! is_finite($sum)) {
                    throw new \OverflowException('Score forest prediction overflowed.');
                }
            }

            $prediction = $sum / count($model['trees']);
        }

        if (! is_finite($prediction)) {
            throw new \OverflowException('Score prediction overflowed.');
        }

        if ($model['output'] === 'binary_probability' && ($prediction < 0.0 || $prediction > 1.0)) {
            throw new \InvalidArgumentException('Score probability prediction is outside [0, 1].');
        }

        return $prediction;
    }

    /**
     * @param array<int|string, int|float> $features
     * @return list<float>
     */
    public function predictProba(array $features): array
    {
        $model = $this->loadedModel();

        if ($model['output'] !== 'binary_probability') {
            throw new \LogicException('Probability prediction is only available for binary_probability models.');
        }

        $positive = $this->predict($features);

        return [1.0 - $positive, $positive];
    }

    /** @return array<string, mixed> */
    private function loadedModel(): array
    {
        if ($this->model === null) {
            throw new \LogicException('Score has no loaded model.');
        }

        return $this->model;
    }

    /** @param mixed $document */
    private static function normalizeDocument(mixed $document): array
    {
        if (! is_array($document) || array_is_list($document)) {
            throw new \InvalidArgumentException('Score model must be a JSON object.');
        }

        if (($document['format'] ?? null) !== 'eloquage-score') {
            throw new \InvalidArgumentException('Unsupported score model format.');
        }

        if (($document['version'] ?? null) !== 1) {
            throw new \InvalidArgumentException('Unsupported score model version.');
        }

        $type = $document['type'] ?? null;
        if (! is_string($type) || ! in_array($type, ['linear', 'tree', 'forest'], true)) {
            throw new \InvalidArgumentException('Unsupported score model type.');
        }

        $features = self::normalizeFeatureNames($document['features'] ?? null);
        $output = $document['output'] ?? null;
        if (! is_string($output) || ! in_array($output, ['value', 'binary_probability'], true)) {
            throw new \InvalidArgumentException('Unsupported score model output.');
        }

        $model = [
            'format' => 'eloquage-score',
            'version' => 1,
            'type' => $type,
            'features' => $features,
            'output' => $output,
        ];

        if ($type === 'linear') {
            return array_merge($model, self::normalizeLinear($document, count($features), $output));
        }

        if ($type === 'tree') {
            if (! array_key_exists('root', $document)) {
                throw new \InvalidArgumentException('Tree score model is missing root.');
            }

            $model['root'] = self::normalizeNode($document['root'], $features, $output, 0);

            return $model;
        }

        if (($document['aggregation'] ?? null) !== 'mean') {
            throw new \InvalidArgumentException('Unsupported score forest aggregation.');
        }

        $trees = $document['trees'] ?? null;
        if (! is_array($trees) || ! array_is_list($trees) || $trees === [] || count($trees) > 32) {
            throw new \InvalidArgumentException('Score forest must contain between one and 32 trees.');
        }

        $model['trees'] = array_map(
            static fn (mixed $tree): array => self::normalizeNode($tree, $features, $output, 0),
            $trees,
        );

        return $model;
    }

    /** @return list<string> */
    private static function normalizeFeatureNames(mixed $features): array
    {
        if (! is_array($features) || ! array_is_list($features) || $features === []) {
            throw new \InvalidArgumentException('Score model features must be a non-empty ordered list.');
        }

        $normalized = [];
        foreach ($features as $feature) {
            if (! is_string($feature) || trim($feature) === '' || in_array($feature, $normalized, true)) {
                throw new \InvalidArgumentException('Score model features must be unique non-empty strings.');
            }

            $normalized[] = $feature;
        }

        return $normalized;
    }

    /** @return array<string, mixed> */
    private static function normalizeLinear(array $document, int $featureCount, string $output): array
    {
        $weights = $document['weights'] ?? null;
        if (! is_array($weights) || ! array_is_list($weights) || count($weights) !== $featureCount) {
            throw new \InvalidArgumentException('Linear score weights must match the feature count.');
        }

        $normalizedWeights = [];
        foreach ($weights as $weight) {
            $normalizedWeights[] = self::finiteNumber($weight, 'Linear score weights must be finite numbers.');
        }

        if (! array_key_exists('bias', $document)) {
            throw new \InvalidArgumentException('Linear score model is missing bias.');
        }

        $activation = $document['activation'] ?? 'identity';
        if (! is_string($activation) || ! in_array($activation, ['identity', 'sigmoid'], true)) {
            throw new \InvalidArgumentException('Unsupported linear score activation.');
        }

        if (($activation === 'sigmoid' && $output !== 'binary_probability')
            || ($activation === 'identity' && $output !== 'value')) {
            throw new \InvalidArgumentException('Linear activation and output do not agree.');
        }

        return [
            'weights' => $normalizedWeights,
            'bias' => self::finiteNumber($document['bias'], 'Linear score bias must be a finite number.'),
            'activation' => $activation,
        ];
    }

    /**
     * @param mixed $node
     * @param list<string> $features
     * @return array<string, mixed>
     */
    private static function normalizeNode(mixed $node, array $features, string $output, int $depth): array
    {
        if (! is_array($node) || array_is_list($node)) {
            throw new \InvalidArgumentException('Score tree nodes must be JSON objects.');
        }

        if (array_key_exists('value', $node)) {
            if (count($node) !== 1) {
                throw new \InvalidArgumentException('Score tree leaves must contain only value.');
            }

            $value = self::finiteNumber($node['value'], 'Score tree leaves must be finite numbers.');
            if ($output === 'binary_probability' && ($value < 0.0 || $value > 1.0)) {
                throw new \InvalidArgumentException('Score probability leaves must be within [0, 1].');
            }

            return ['value' => $value];
        }

        if ($depth >= 8 || count($node) !== 4 || array_diff(['feature', 'threshold', 'left', 'right'], array_keys($node)) !== []) {
            throw new \InvalidArgumentException('Score tree nodes must be complete and no deeper than eight decisions.');
        }

        $feature = $node['feature'];
        $position = is_string($feature) ? array_search($feature, $features, true) : false;
        if ($position === false) {
            throw new \InvalidArgumentException('Score tree node references an unknown feature.');
        }

        return [
            'feature' => $position,
            'threshold' => self::finiteNumber($node['threshold'], 'Score tree thresholds must be finite numbers.'),
            'left' => self::normalizeNode($node['left'], $features, $output, $depth + 1),
            'right' => self::normalizeNode($node['right'], $features, $output, $depth + 1),
        ];
    }

    private static function finiteNumber(mixed $value, string $message): float
    {
        if ((! is_int($value) && ! is_float($value)) || ! is_finite((float) $value)) {
            throw new \InvalidArgumentException($message);
        }

        return (float) $value;
    }

    /**
     * @param array<int|string, int|float> $features
     * @param list<string> $declaredFeatures
     * @return list<float>
     */
    private function canonicalizeFeatures(array $features, array $declaredFeatures): array
    {
        if (array_is_list($features)) {
            if (count($features) !== count($declaredFeatures)) {
                throw new \InvalidArgumentException('Score feature vector has the wrong dimension.');
            }

            return array_map(
                static fn (mixed $value): float => self::finiteNumber($value, 'Score feature values must be finite numbers.'),
                $features,
            );
        }

        if (count($features) !== count($declaredFeatures)) {
            throw new \InvalidArgumentException('Score named features must match the model feature set.');
        }

        foreach ($features as $name => $_value) {
            if (! is_string($name) || ! in_array($name, $declaredFeatures, true)) {
                throw new \InvalidArgumentException('Score named features must match the model feature set.');
            }
        }

        $values = [];
        foreach ($declaredFeatures as $name) {
            if (! array_key_exists($name, $features)) {
                throw new \InvalidArgumentException('Score named features are missing a declared feature.');
            }

            $values[] = self::finiteNumber($features[$name], 'Score feature values must be finite numbers.');
        }

        return $values;
    }

    /**
     * @param array<string, mixed> $model
     * @param list<float> $values
     */
    private function predictLinear(array $model, array $values): float
    {
        $sum = $model['bias'];
        foreach ($model['weights'] as $index => $weight) {
            $sum += $weight * $values[$index];

            if (! is_finite($sum)) {
                throw new \OverflowException('Score linear prediction overflowed.');
            }
        }

        if ($model['activation'] === 'identity') {
            return $sum;
        }

        if ($sum >= 0.0) {
            return 1.0 / (1.0 + exp(-$sum));
        }

        $exponent = exp($sum);

        return $exponent / (1.0 + $exponent);
    }

    /** @param array<string, mixed> $node */
    private function predictTree(array $node, array $values): float
    {
        if (array_key_exists('value', $node)) {
            return $node['value'];
        }

        if ($values[$node['feature']] <= $node['threshold']) {
            return $this->predictTree($node['left'], $values);
        }

        return $this->predictTree($node['right'], $values);
    }
}
