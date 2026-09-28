<?php

/**
 * TypeSafe AI PHP SDK
 * Copyright 2026 Alexey Kopytko <alexey@kopytko.com>
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

declare(strict_types=1);

namespace TypeSafeAI;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\MessageFormatter;
use GuzzleHttp\Middleware;
use GuzzleRetry\GuzzleRetryMiddleware;
use JMS\Serializer\Exception\LogicException;
use JMS\Serializer\SerializationContext;
use JMS\Serializer\SerializerInterface;
use InvalidArgumentException;
use JSONSerializer\Contracts\JsonDeserializer;
use JSONSerializer\Serializer;
use Psr\Log\LoggerInterface;
use ReflectionClass;

use function array_merge;
use function getenv;
use function range;
use function sprintf;

/**
 * TypeSafe AI API Client.
 *
 * @phpstan-import-type ValueType from SystemOneRequest
 */
class TypeSafeClient
{
    public const BASE_URI = 'https://api.typesafe.ai';

    public const API_KEY_ENV = 'TYPESAFE_API_KEY';

    public const BASE_URL_ENV = 'TYPESAFE_BASE_URL';

    /**
     * OpenJEV is a free community gateway to the same Jev model. TypeSafe stays
     * the default; OpenJEV is opt-in via JEV_PROVIDER=openjev or by setting only
     * OPENJEV_API_KEY (no TYPESAFE_API_KEY).
     */
    public const OPENJEV_BASE_URI = 'https://api.openjev.sh';

    public const OPENJEV_API_KEY_ENV = 'OPENJEV_API_KEY';

    public const OPENJEV_BASE_URL_ENV = 'OPENJEV_BASE_URL';

    public const PROVIDER_ENV = 'JEV_PROVIDER';

    public const PROVIDER_TYPESAFE = 'typesafe';

    public const PROVIDER_OPENJEV = 'openjev';

    private const SYSTEM_ONE = '/v1/systemone';

    private const MODELS = '/v1/models';

    private const HTTP_REQUEST_TIMEOUT = 408;

    private const HTTP_TOO_MANY_REQUESTS = 429;

    private const HTTP_SERVER_ERROR_FIRST = 500;

    private const HTTP_SERVER_ERROR_LAST = 599;

    private const MAX_RETRY_ATTEMPTS = 2;

    private const TIMEOUT = 10;

    private const CONNECT_TIMEOUT = 3;

    /**
     * Like MessageFormatter::DEBUG, but without headers
     */
    public const LOG_TEMPLATE = ">>>>>>>>\n{method} {uri} HTTP/{version}\n\n{req_body}\n<<<<<<<<\nHTTP/{version} {code} {phrase}\n\n{res_body}\n--------\n{error}";

    /**
     * Build a new client instance.
     *
     * @param string|null $apiKey TypeSafe API key; read from TYPESAFE_API_KEY when null
     * @param array<string, string> $extraHeaders Additional HTTP headers to send with every request
     * @param array<string, mixed> $retryOptions Options passed to GuzzleRetryMiddleware (retry_on_status, etc.)
     * @param array<string, mixed> $clientOptions Extra Guzzle client options (base_uri, timeout, etc.) merged after defaults
     * @throws InvalidArgumentException When no API key is given and TYPESAFE_API_KEY is not set
     */
    public static function createInstance(
        ?string $apiKey = null,
        array $extraHeaders = [],
        array $retryOptions = [],
        array $clientOptions = [],
    ): self {
        $provider = self::resolveProvider();

        if ($apiKey === null) {
            $apiKey = match ($provider) {
                self::PROVIDER_OPENJEV => getenv(self::OPENJEV_API_KEY_ENV) ?: throw new InvalidArgumentException(
                    sprintf('No API key given and %s is not set.', self::OPENJEV_API_KEY_ENV),
                ),
                default => getenv(self::API_KEY_ENV) ?: throw new InvalidArgumentException(
                    sprintf('No API key given and %s is not set.', self::API_KEY_ENV),
                ),
            };
        }

        $baseUri = match ($provider) {
            self::PROVIDER_OPENJEV => getenv(self::OPENJEV_BASE_URL_ENV) ?: self::OPENJEV_BASE_URI,
            default => getenv(self::BASE_URL_ENV) ?: self::BASE_URI,
        };

        $stack = HandlerStack::create();

        $stack->push(GuzzleRetryMiddleware::factory(array_merge([
            'retry_on_timeout' => true,
            'max_retry_attempts' => self::MAX_RETRY_ATTEMPTS,
            'retry_on_status' => [
                self::HTTP_REQUEST_TIMEOUT,
                self::HTTP_TOO_MANY_REQUESTS,
                ...range(self::HTTP_SERVER_ERROR_FIRST, self::HTTP_SERVER_ERROR_LAST),
            ],
        ], $retryOptions)), 'retry_on_status');

        $httpClient = new Client(array_merge([
            'base_uri' => $baseUri,
            'connect_timeout' => self::CONNECT_TIMEOUT,
            'timeout' => self::TIMEOUT,
            'http_errors' => true,
            'allow_redirects' => false,
            'headers' => array_merge([
                'Authorization' => "Bearer $apiKey",
            ], $extraHeaders),
            'handler' => $stack,
        ], $clientOptions));

        return new self(
            $httpClient,
            $stack,
            Serializer::withJSONOptions(),
            $provider,
        );
    }

    /**
     * Resolves the provider following the selection rule: an explicit
     * JEV_PROVIDER wins; otherwise TypeSafe when its key is set; otherwise
     * OpenJEV when only OPENJEV_API_KEY is set; otherwise TypeSafe (the
     * original default, which will throw if no key is configured).
     */
    private static function resolveProvider(): string
    {
        $explicit = getenv(self::PROVIDER_ENV);

        if ($explicit !== false && $explicit !== '') {
            $explicit = strtolower(trim($explicit));

            if ($explicit === self::PROVIDER_OPENJEV) {
                return self::PROVIDER_OPENJEV;
            }

            return self::PROVIDER_TYPESAFE;
        }

        if (getenv(self::API_KEY_ENV)) {
            return self::PROVIDER_TYPESAFE;
        }

        if (getenv(self::OPENJEV_API_KEY_ENV)) {
            return self::PROVIDER_OPENJEV;
        }

        return self::PROVIDER_TYPESAFE;
    }

    public function __construct(
        private readonly Client $client,
        private readonly HandlerStack $stack,
        private readonly SerializerInterface&JsonDeserializer $serializer,
        private readonly string $provider = self::PROVIDER_TYPESAFE,
    ) {}

    /**
     * The model id the client defaults to for the configured provider:
     * `jev-latest` for TypeSafe, `openjev` for the OpenJEV gateway.
     */
    public function defaultModel(): string
    {
        return match ($this->provider) {
            self::PROVIDER_OPENJEV => SystemOneRequest::MODEL_OPENJEV,
            default => SystemOneRequest::MODEL_LATEST,
        };
    }

    /**
     * The provider this client was built for: `typesafe` or `openjev`.
     */
    public function provider(): string
    {
        return $this->provider;
    }

    public function setLogger(LoggerInterface $logger, string $template = self::LOG_TEMPLATE): self
    {
        $this->stack->push(Middleware::log(
            $logger,
            new MessageFormatter($template),
        ));

        return $this;
    }

    /**
     * Answers each question about the state.
     *
     * @throws GuzzleException On 401 (bad API key), 422 (invalid request), or when the retries are exhausted
     * @throws LogicException When an answer has a type that the SDK does not know
     */
    public function systemOne(SystemOneRequest $request): SystemOneResult
    {
        $response = $this->client->post(self::SYSTEM_ONE, [
            'body' => $this->serializer->serialize($request, 'json', self::serializationContext()),
            'headers' => ['Content-Type' => 'application/json'],
        ]);

        return $this->serializer->deserializeJson(
            (string) $response->getBody(),
            SystemOneResult::class,
        );
    }

    /**
     * Evaluates the questions declared by a class and returns an instance with the answers.
     *
     * @template TResult of object
     * @param ValueType $state
     * @param class-string<TResult> $class
     * @return TResult
     */
    public function evaluate(string|array|object $state, string $class, ?string $model = null): object
    {
        $model ??= $this->defaultModel();
        $reader = new AttributeReader(new ReflectionClass($class));
        $request = new SystemOneRequest($state, $model, [...$reader->questions()]);

        return $reader->hydrate($this->systemOne($request));
    }

    /**
     * Lists the models available to the account.
     *
     * @throws GuzzleException On 401 (bad API key), or when rate limited
     */
    public function models(): ModelsResponse
    {
        $response = $this->client->get(self::MODELS);

        return $this->serializer->deserializeJson(
            (string) $response->getBody(),
            ModelsResponse::class,
        );
    }

    /**
     * Sends null values, such as a choice option without a description.
     */
    private static function serializationContext(): SerializationContext
    {
        return SerializationContext::create()->setSerializeNull(true);
    }
}
