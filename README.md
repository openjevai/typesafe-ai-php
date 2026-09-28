# PHP SDK for TypeSafe's Jev

A PHP client for the [TypeSafe AI](https://docs.typesafe.ai) evaluation API: provide a state and a set of typed questions, get back one typed answer for each question. As easy as that.

```bash
composer require sanmai/typesafe-ai-php
```

Requires PHP 8.2 or newer.

> **OpenJEV support:** Jev is built by [TypeSafe](https://typesafe.ai). This fork keeps TypeSafe as the default and adds optional support for [OpenJEV](https://openjev.sh), a free community gateway to the same Jev model — set `OPENJEV_API_KEY` (or `JEV_PROVIDER=openjev`) to use it. Original project: https://github.com/sanmai/typesafe-ai-php by @sanmai.

**Something amiss?** [Open an issue](https://github.com/sanmai/typesafe-ai-php/issues/new), or, even better, send a PR!

## Overview

There are three kinds of objects you work with:

- `TypeSafeClient` sends requests. Build it with the static `createInstance()` factory.
- `SystemOneRequest` retains the state/questions to evaluate. Easy to build using a fluent interface.
- `SystemOneResult` retains one answer for each question, mapped to the ID of the original question.

## Usage

To get started, point an agent at this README. If you are an agent or a curious human, read on.

### Building a Client

```php
use TypeSafeAI\TypeSafeClient;

$client = TypeSafeClient::createInstance($apiKey);
```

The API key is optional: without one the client reads `TYPESAFE_API_KEY`, and throws `InvalidArgumentException` when that is not set either. `TYPESAFE_BASE_URL` overrides the API root the same way, following in the steps of JS and Python SDKs.

```php
$client = TypeSafeClient::createInstance();
```

#### OpenJEV provider

OpenJEV is a free community gateway to the same Jev model. The client selects the provider at `createInstance()` time, in this order:

1. `JEV_PROVIDER=openjev` (explicit choice wins) → OpenJEV.
2. `TYPESAFE_API_KEY` is set → TypeSafe (the unchanged default).
3. Only `OPENJEV_API_KEY` is set → OpenJEV.

Anyone with a TypeSafe key sees zero behaviour change. When OpenJEV is selected, the client reads `OPENJEV_API_KEY` and `OPENJEV_BASE_URL` (defaults to `https://api.openjev.sh`), and `evaluate()` defaults to the `openjev` model id.

```bash
OPENJEV_API_KEY=your-openjev-key php examples/urgency.php
# or force it explicitly:
JEV_PROVIDER=openjev TYPESAFE_API_KEY=your-typesafe-key php examples/urgency.php
```

### Using Result Classes

Annotate any class with attributes, add `Answer` subclasses as parameter types, fire up `evaluate()` that will send the question, and return an instance of the class with all parameters assigned their respective answers. This is the recommended way to use the SDK.

```php
use TypeSafeAI\DTO\ChoiceAnswer;
use TypeSafeAI\DTO\NoulAnswer;
use TypeSafeAI\Question\Choice;
use TypeSafeAI\Question\Noul;
use TypeSafeAI\Question\NoulCriteria;

class TicketDecision
{
    public function __construct(
        #[Noul('Does this convey urgency?', new NoulCriteria(true: 'Explicitly time-sensitive'))]
        public readonly NoulAnswer $is_urgent,
        #[Choice('Which team should handle this?', [
            'billing' => 'Payments, invoicing, refunds',
            'technical' => 'Bugs, outages, integrations',
            'sales' => null,
        ])]
        public readonly ChoiceAnswer $department,
    ) {}
}

$decision = $client->evaluate('Help! My payouts have been failing for 3 days.', TicketDecision::class);

$decision->is_urgent->noul;      // 0.95
$decision->department->choice;   // 'billing'
```

Provide the third argument to select the model:

```php
$decision = $client->evaluate($ticket, TicketDecision::class, 'jev-preview');
```

For the token counts or the resolved model version, send a `SystemOneRequest` with `systemOne()` as in the examples below.

### Custom Requests

There are three question types:

- **Noul** is a yes/no question. The answer is the probability of yes, from 0 to 1.
- **Choice** picks one option from the set. Each option can have an optional description.
- **Score** evaluates the state against the ordered levels, from the lowest to the highest.

```php
use TypeSafeAI\SystemOneRequest;

$request = SystemOneRequest::build('Help! My payouts have been failing for 3 days.')
    ->noul(
        'is_urgent',
        'Does this convey urgency?',
        true: 'Explicitly time-sensitive',
        false: 'No urgency expressed',
    )
    ->choice('department', 'Which team should handle this?', [
        'billing' => 'Payments, invoicing, refunds',
        'technical' => 'Bugs, outages, integrations',
        'sales' => null,
    ])
    ->score('frustration', 'How frustrated is the customer?', [
        'Calm',
        'Frustrated',
        'Very angry',
    ]);

$response = $client->systemOne($request);
```

The state and the instructions can be a string, or structured data such as an array or an object. Objects are sent with their private properties: if you need full control, convert the object to an array first.

Every description takes the same range of values: text, a JSON object, an array, or `null`.

```php
$request = SystemOneRequest::build($ticket)
    ->choice('department', 'Which team should handle this?', [
        'billing' => [
            'what' => 'Charges, invoices, refunds, or subscriptions',
            'not_for' => 'Order tracking or account access',
            'examples' => ['I was charged twice', 'Where is my refund?'],
        ],
        'sales' => null,
    ]);
```

`instructions` is optional, so a question can lean on its criteria alone. The API requires at least one of the two.

```php
use TypeSafeAI\Question\Noul;
use TypeSafeAI\Question\NoulCriteria;

$request = new SystemOneRequest($state, questions: [
    'is_urgent' => new Noul(
        'Does this convey urgency?',
        new NoulCriteria(false: 'No urgency expressed'),
    ),
]);
```

Requests use the `jev-latest` model by default; to use a different model, provide it as the second argument to `build()` or the constructor.

### Reading Answers

Each answer type has its own accessor, so the IDE will tip you on the available fields:

```php
$urgent = $response->noul('is_urgent');
$urgent->noul;                   // 0.95

$department = $response->choice('department');
$department->choice;             // 'billing'
$department->probabilities;      // ['sales' => 0.0, 'technical' => 0.11, 'billing' => 0.89]
$department->confidence;         // 0.82

$frustration = $response->score('frustration');
$frustration->score;             // 1.04
$frustration->legend;            // [0 => 'Calm', 1 => 'Frustrated', 2 => 'Very angry']
$frustration->probabilities;     // [0 => 0.0, 1 => 0.96, 2 => 0.04]
$frustration->confidence;        // 0.94

$response->model;                // 'jev-N.NN', the actual model version
$response->usage->input_tokens;  // 414
```

An accessor throws `UnexpectedValueException` if there is no answer with that ID, or if the answer has a different type.

All answers are also available in `$response->answers`, keyed by question ID.

Each answer also has a type field, useful when you walk `$response->answers`:

```php
$urgent->type;                   // 'noul'
```

### Listing Models

`models()` returns the models available to your account:

```php
foreach ($client->models()->models as $model) {
    echo "{$model->name}: {$model->description} ({$model->release_date})\n";
}
```

### Custom Question Types

`SystemOneRequest::ask()` extends the request with any object that implements the `TypeSafeAI\Question\Question` marker interface. The `noul()`, `choice()`, and `score()` methods are shortcuts for `ask()` with the bundled `Noul`, `Choice`, and `Score` classes.

Questions are plain data objects. The client serializes their properties to JSON, null values included.

## Examples

Check out the [examples](examples/) directory. Examples use the API key from the `TYPESAFE_API_KEY` environment variable:

```bash
TYPESAFE_API_KEY=your-api-key php examples/urgency.php
```

- [urgency.php](examples/urgency.php): a yes/no question.
- [routing.php](examples/routing.php): a choice between teams, with the probability of each option.
- [frustration.php](examples/frustration.php): a score along ordered levels.
- [chat-log.php](examples/chat-log.php): questions of all three types about a chat log, in one request.
- [attributes.php](examples/attributes.php): a result declaring its own questions using attributes.
- [errors.php](examples/errors.php): an invalid request, and the validation error as returned by the API.

## Errors and Retries

The client retries `408 Request Timeout`, `429 Too Many Requests`, every `5xx` response, and connection timeouts, twice at most. Other errors throw Guzzle exceptions: a `ClientException` for `401 Unauthorized` (check your API key), for `400 Bad Request` (e.g. for choices without options), and for `422 Unprocessable Entity` (the error will hint at the failed field).

```php
use GuzzleHttp\Exception\ClientException;

try {
    $response = $client->systemOne($request);
} catch (ClientException $e) {
    $error = $e->getResponse();
    echo "Request failed (HTTP {$error->getStatusCode()}): {$error->getBody()}\n";
}
```

## Client Configuration

Requests time out after 10 seconds by default, with an option to raise the `timeout` through `$clientOptions`.

`createInstance()` takes optional `$extraHeaders`, `$retryOptions`, and `$clientOptions` arrays after the API key. Use them to send extra headers (a custom User-Agent, for example), to tune the bundled [retry middleware](https://github.com/caseyamcl/guzzle_retry_middleware), or to override defaults such as `base_uri`, `timeout`, or `connect_timeout`.

## Debug Logging

`TypeSafeClient` accepts any PSR-3 logger through `setLogger()`.

```php
$client->setLogger($psrLogger);
```

## Development

To run all tests:

```bash
make -j -k
```
