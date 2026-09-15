# eloquage/score

Local, inference-only scoring for small exported linear models and bounded
binary trees/forests. The package is framework-agnostic and runs from pure
PHP without Python, ONNX, pickle, HTTP, or a model runtime.

## Installation

```bash
composer require eloquage/score
```

## The owned JSON contract

`Score::load()` accepts one local JSON file in the versioned
`eloquage-score` envelope. The package owns this schema; scikit-learn,
XGBoost, pickle, and ONNX documents are not runtime inputs.

Every document contains:

```json
{
  "format": "eloquage-score",
  "version": 1,
  "type": "linear",
  "features": ["age", "income"],
  "output": "value",
  "weights": [0.04, 0.002],
  "bias": -2.0,
  "activation": "identity"
}
```

The supported model types are `linear`, `tree`, and `forest`. Feature names
are ordered and unique. Linear models are single-output and use a list of
finite `weights`, a finite `bias`, and either `identity` or `sigmoid`
activation. `sigmoid` is valid only with `output: "binary_probability"`.
Trees use named binary threshold splits, route equality to the left child,
allow at most eight decisions on a path, and use finite leaf values. Forests
contain one to 32 trees and use `aggregation: "mean"`.

## Usage

```php
use Eloquage\Score\Score;

$model = Score::load(__DIR__.'/model.json');

// Ordered vectors follow the declaration in `features`.
$value = $model->predict([42.0, 80000.0]);

// Named maps are normalized to that same declaration order.
$sameValue = $model->predict([
    'income' => 80000.0,
    'age' => 42.0,
]);
```

Inputs must contain exactly the declared features, either as a contiguous
ordered list or as a named map. Values must be finite integers or floats;
missing, extra, non-contiguous, non-numeric, and non-finite values are
rejected. There is no imputation or preprocessing.

For an explicit `binary_probability` model, `predict()` returns `p` and
`predictProba()` returns `[1 - p, p]` in fixed class order `[0, 1]`:

```php
$probability = $model->predict([42.0, 80000.0]);
$distribution = $model->predictProba([42.0, 80000.0]);
// [$distribution[0], $distribution[1]] === [1 - $probability, $probability]
```

Value models do not expose guessed class labels and throw `LogicException`
when `predictProba()` is requested. A fresh `new Score()` remains available
for the package identity call (`name() === 'score'`) but has no model to
predict until `Score::load()` is used.

## Exporting a supported linear model

The following is a concise exporter for a fitted binary or single-output
scikit-learn estimator. It writes the Eloquage-owned schema; it does not make
pickle, ONNX, sklearn runtime parity, multiclass output, or model loading in
PHP part of the contract.

```python
import json

def export_eloquage_score(estimator, feature_names, path, *, probability=False):
    coefficients = estimator.coef_
    if coefficients.ndim != 2 or coefficients.shape[0] != 1:
        raise ValueError("only one binary/single-output coefficient row is supported")

    document = {
        "format": "eloquage-score",
        "version": 1,
        "type": "linear",
        "features": list(feature_names),
        "output": "binary_probability" if probability else "value",
        "weights": coefficients[0].tolist(),
        "bias": float(estimator.intercept_[0]),
        "activation": "sigmoid" if probability else "identity",
    }

    with open(path, "w", encoding="utf-8") as handle:
        json.dump(document, handle, allow_nan=False, indent=2)
```

## Testing

```bash
composer test
vendor/bin/pest --coverage --min=90
```

The package source of truth is pure PHP under `src/`. TypePHP remains an
optional maintainer experiment; JSON parsing, dynamic validation, and
recursive trees are intentionally not presented as a proven native build.
See [AGENTS.md](AGENTS.md) and [TYPEPHP.md](TYPEPHP.md) for the package
workflow.

## License

The MIT License (MIT). See [LICENSE.md](LICENSE.md).
