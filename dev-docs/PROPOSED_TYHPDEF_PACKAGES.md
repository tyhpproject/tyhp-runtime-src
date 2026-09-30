# Proposed Composer packages for tyhpdef wrapping

This is a **candidate list**, not a commitment to generate tyhpdef packages. It exists so later work can pick a packagist name, a version constraint, and the public-API packages that must exist first.

Research date: **2026-08-26**. Versions were checked on Packagist (`repo.packagist.org/p2`) and ecosystem docs the same day.

## Already have

These already exist as Tyhp tyhpdef packages under `packages/` (folder names use the exact Composer version):

| Packagist | Existing tyhpdef versions | Suggested constraint for new work |
| --- | --- | --- |
| `psr/log` | 2.0.0, 3.0.0, 3.0.1, **3.0.2** | `^3.0` (latest patch: 3.0.2) |
| `monolog/monolog` | **3.10.0** | `^3.10` or `^3.0` (latest patch: 3.10.0) |

They still appear in the ranked tables below so prerequisite arrows stay honest. They are **not** new work.

## How versions were chosen

- Constraints are **caret of the current stable major** (`^13.0`, `^8.0`, `^3.0`). For `0.x` packages the caret follows Composer (`^0.19` for brick/math 0.19.1).
- Each row notes the **latest patch** found on 2026-08-26.
- **Laravel: 13** (`^13.0`, latest **13.29.0**, PHP `^8.3`). Laravel 12 is still in security support until 2027-02-24; Laravel 11 reached end of life on 2026-03-12. First-party addons on this list were checked to allow Laravel 13.
- **Symfony: 7.4 LTS** (`^7.4`, typical latest patch **7.4.17**, PHP `>= 8.2`). 8.1.5 is the current non-LTS line (PHP `>= 8.4`) and ends support in January 2027. Laravel 13 requires `symfony/*: ^7.4 || ^8.0`; PHP 8.3 Laravel apps cannot take Symfony 8.1. Contracts packages (`symfony/*-contracts`) and UX/Flex/Maker keep their own majors.
- **Guzzle: `^8.0`** (latest **8.1.0**). 7.15.5 is still maintained; Laravel 13 allows `^7.8.2 || ^8.0`.
- **PHPUnit / Pest:** suggested **PHPUnit `^12.5`** (12.5.33, PHP `>= 8.3`) and **Pest `^4.7`** (4.7.8) so they match Laravel 13’s minimum PHP. PHPUnit **13.3.1** and Pest **5.1.3** are current on PHP `>= 8.4`.

## How public-API prerequisites work

If a developer writing against package A would **type-hint, catch, extend, or implement** a type whose defining package is B, B is a prerequisite of A. Internal-only transitives are omitted (no `sebastian/*` under PHPUnit, no Symfony polyfills, no `phpoption` under phpdotenv). `already-have` means generate-order only: the tyhpdef already exists.

**Package count in this list: 304** (unique packagist names).

## Suggested generation order

Respect prerequisites; do not generate a consumer before the types it names.

1. **PSR contracts** — `psr/log` (already have), `psr/container`, `psr/http-*`, `psr/cache`, `psr/simple-cache`, `psr/event-dispatcher`, `psr/clock`, `psr/link`.
2. **Symfony contracts** — `service`, `event-dispatcher`, `translation`, `cache`, `http-client` contracts.
3. **HTTP implementations** — `guzzlehttp/promises`, `guzzlehttp/psr7`, `nyholm/psr7`, `laminas/laminas-diactoros`, then **`guzzlehttp/guzzle`**.
4. **Log implementation** — `monolog/monolog` (already have).
5. **Shared value types** — `nesbot/carbon`, `ramsey/uuid`, `brick/math`, `nikic/php-parser`, `twig/twig`, `league/flysystem`, `league/uri`.
6. **Illuminate bottom-up** — `illuminate/contracts` → collections/container/support → http/database/routing → **`laravel/framework`**.
7. **Symfony 7.4** — http-foundation, console, http-kernel, routing, then **`symfony/framework-bundle`**.
8. **QA CLIs** — `phpunit/phpunit`, `nikic/php-parser` already done, then phpstan/psalm/rector/php-cs-fixer/phpcs, then Pest.
9. **Laravel addons** — Sanctum/Horizon/… then Livewire, then Filament/Spatie (they name Laravel + Livewire types).
10. **Everything else** — Doctrine, AWS SDK, Predis, Cashier/Stripe, then remaining high-traffic clients.

## Ranked candidate list

Rank **1 = highest value** for typed consumption of PHP libraries. The **Marks** column flags brief-required packages and already-have coverage.

### 1. PSR contracts

| Rank | Package | Constraint | Latest patch | Why | Public-API prerequisites | Marks | Notes |
| ---: | --- | --- | --- | --- | --- | --- | --- |
| 1 | `psr/log` | `^3.0` | 3.0.2 | LoggerInterface and log levels; the type almost every app and library names. | none | already have, required | Existing tyhpdef covers 2.0.0, 3.0.0, 3.0.1, and 3.0.2. |
| 2 | `psr/container` | `^2.0` | 2.0.2 | ContainerInterface; DI containers and frameworks type-hint this. | none | required | — |
| 3 | `psr/http-message` | `^2.0` | 2.0 | Request/Response/Stream/Uri interfaces used across HTTP clients and servers. | none | required | — |
| 4 | `psr/http-client` | `^1.0` | 1.0.3 | ClientInterface and ClientExceptionInterface for PSR-18 clients. | psr/http-message | required | — |
| 5 | `psr/http-factory` | `^1.0` | 1.1.0 | PSR-17 factories for PSR-7 messages. | psr/http-message | required | — |
| 6 | `psr/http-server-handler` | `^1.0` | 1.0.2 | RequestHandlerInterface for PSR-15 kernels and middleware stacks. | psr/http-message | — | — |
| 7 | `psr/http-server-middleware` | `^1.0` | 1.0.2 | MiddlewareInterface wrapping PSR-15 handlers. | psr/http-message, psr/http-server-handler | — | — |
| 8 | `psr/cache` | `^3.0` | 3.0.0 | PSR-6 CacheItemPoolInterface / CacheItemInterface. | none | — | — |
| 9 | `psr/simple-cache` | `^3.0` | 3.0.0 | PSR-16 CacheInterface used by Laravel and many libraries. | none | — | — |
| 10 | `psr/event-dispatcher` | `^1.0` | 1.0.0 | EventDispatcherInterface and ListenerProviderInterface. | none | — | — |
| 11 | `psr/clock` | `^1.0` | 1.0.0 | ClockInterface; Carbon and JWT clocks implement this. | none | — | — |
| 12 | `psr/link` | `^2.0` | 2.0.1 | PSR-13 EvolvableLinkProviderInterface for hypermedia APIs. | none | — | — |

### 2. Foundation (HTTP, log, cache, container, events)

| Rank | Package | Constraint | Latest patch | Why | Public-API prerequisites | Marks | Notes |
| ---: | --- | --- | --- | --- | --- | --- | --- |
| 13 | `symfony/service-contracts` | `^3.0` | 3.7.1 | ResetInterface and service subscriber abstractions used across Symfony and Laravel. | psr/container | — | Own major (not 7.4). Latest 3.7.1. |
| 14 | `symfony/event-dispatcher-contracts` | `^3.0` | 3.7.1 | EventDispatcherInterface facade used when people type-hint Symfony contracts rather than PSR. | psr/event-dispatcher | — | Own major. Latest 3.7.1. |
| 15 | `symfony/translation-contracts` | `^3.0` | 3.7.1 | TranslatorInterface for i18n type-hints. | none | — | Own major. Latest 3.7.1. |
| 16 | `symfony/cache-contracts` | `^3.0` | 3.7.1 | CacheInterface / CacheAdapterInterface beyond PSR-6. | psr/cache | — | Own major. Latest 3.7.1. |
| 17 | `symfony/http-client-contracts` | `^3.0` | 3.7.1 | HttpClientInterface and ResponseInterface for Symfony HTTP clients. | none | — | Own major. Latest 3.7.1. |
| 18 | `guzzlehttp/promises` | `^3.0` | 3.0.2 | PromiseInterface; Guzzle public async API and many AWS/Google clients name these types. | none | required | Guzzle 8 line uses 3.x; 2.5.x still common with Guzzle 7. |
| 19 | `guzzlehttp/psr7` | `^3.0` | 3.1.0 | PSR-7 implementation (Request, Response, Stream, Uri) that Guzzle and Laravel HTTP expose. | psr/http-message, psr/http-factory | required | 3.x current with Guzzle 8; 2.13.x still common with Guzzle 7. |
| 20 | `guzzlehttp/uri-template` | `^2.0` | 2.0.1 | URI template expansion types used by Guzzle and Laravel's HTTP layer. | none | — | Laravel 13 allows ^1.0  or  ^2.0. |
| 21 | `guzzlehttp/guzzle` | `^8.0` | 8.1.0 | The default PHP HTTP client; Client, HandlerStack, and exceptions appear in app code. | psr/http-message, psr/http-client, psr/http-factory, guzzlehttp/promises, guzzlehttp/psr7 | required | Current 8.1.0. 7.15.5 still maintained; Laravel 13 allows ^7.8.2  or  ^8.0. |
| 22 | `nyholm/psr7` | `^1.0` | 1.8.2 | Popular PSR-7/17 implementation used with Symfony HTTP client and slim stacks. | psr/http-message, psr/http-factory | — | — |
| 23 | `nyholm/psr7-server` | `^1.0` | 1.1.0 | ServerRequestCreator for turning PHP globals into PSR-7. | nyholm/psr7, psr/http-message, psr/http-factory | — | — |
| 24 | `laminas/laminas-diactoros` | `^3.0` | 3.8.0 | Laminas/Mezzio PSR-7 implementation (ServerRequest, Uri, uploaded files). | psr/http-message, psr/http-factory | — | PHP 8.2+. |
| 25 | `slim/psr7` | `^1.0` | 1.8.0 | Slim's PSR-7 implementation; Slim apps type-hint these message classes. | psr/http-message, psr/http-factory | — | — |
| 26 | `php-http/promise` | `^1.0` | 1.3.1 | HTTPlug Promise type used on php-http client public APIs. | none | — | — |
| 27 | `php-http/httplug` | `^2.0` | 2.4.1 | HttpClient / HttpAsyncClient interfaces (HTTPlug) still type-hinted in many SDKs. | psr/http-message, php-http/promise | — | — |
| 28 | `php-http/message` | `^1.0` | 1.16.2 | PSR-7 helpers and stream decorators used with HTTPlug clients. | psr/http-message, psr/http-factory | — | — |
| 29 | `php-http/discovery` | `^1.0` | 1.20.0 | Psr18Client / Psr17Factory discovery types used when libraries accept generic HTTP clients. | psr/http-client, psr/http-factory, psr/http-message | — | — |
| 30 | `php-http/client-common` | `^2.0` | 2.7.3 | PluginClient and HTTP plugin types on the HTTPlug public surface. | php-http/httplug, psr/http-message | — | — |
| 31 | `monolog/monolog` | `^3.0` | 3.10.0 | Logger, HandlerInterface, FormatterInterface, processors; the common PSR-3 implementation. | psr/log (already-have) | already have, required | Existing tyhpdef at 3.10.0. |
| 32 | `php-di/php-di` | `^7.0` | 7.1.1 | Container and autowiring types for standalone PSR-11 apps. | psr/container | — | — |
| 33 | `league/container` | `^5.0` | 5.2.0 | League PSR-11 container; common in Slim/league stacks. | psr/container | — | — |
| 34 | `league/event` | `^3.0` | 3.0.3 | League event emitter types as an alternative to PSR-14/Symfony. | none | — | — |
| 35 | `willdurand/negotiation` | `^3.0` | 3.1.0 | Negotiator / AcceptHeader types for content negotiation. | none | — | — |

### 3. Laravel and Illuminate

| Rank | Package | Constraint | Latest patch | Why | Public-API prerequisites | Marks | Notes |
| ---: | --- | --- | --- | --- | --- | --- | --- |
| 36 | `illuminate/contracts` | `^13.0` | 13.29.0 | Illuminate contracts (Container, Auth, Mail, Queue, …) that almost every Laravel type-hint uses. | psr/container, psr/simple-cache | required | Same version as laravel/framework (replace). Independent package for non-framework Illuminate consumers. |
| 37 | `illuminate/macroable` | `^13.0` | 13.29.0 | Macroable trait; support/collections mix it in and packages extend it. | none | required | Same version as laravel/framework (replace). Independent package for non-framework Illuminate consumers. |
| 38 | `illuminate/conditionable` | `^13.0` | 13.29.0 | Conditionable trait on collections, builders, and pending objects. | none | required | Same version as laravel/framework (replace). Independent package for non-framework Illuminate consumers. |
| 39 | `illuminate/collections` | `^13.0` | 13.29.0 | Collection / Enumerable / LazyCollection — the most named Illuminate types outside HTTP. | illuminate/contracts, illuminate/macroable, illuminate/conditionable | required | Same version as laravel/framework (replace). Independent package for non-framework Illuminate consumers. |
| 40 | `illuminate/container` | `^13.0` | 13.29.0 | Container, ContextualBindingBuilder, and PSR-11 implementation. | illuminate/contracts, psr/container | required | Same version as laravel/framework (replace). Independent package for non-framework Illuminate consumers. |
| 41 | `illuminate/support` | `^13.0` | 13.29.0 | Str, Arr, Carbon aliases, helpers, ServiceProvider, Fluent, DefaultProviders. | illuminate/contracts, illuminate/collections, illuminate/macroable, illuminate/conditionable, nesbot/carbon, doctrine/inflector | required | Same version as laravel/framework (replace). Independent package for non-framework Illuminate consumers. |
| 42 | `illuminate/pipeline` | `^13.0` | 13.29.0 | Pipeline type used by middleware and custom pipes. | illuminate/contracts | required | Same version as laravel/framework (replace). Independent package for non-framework Illuminate consumers. |
| 43 | `illuminate/events` | `^13.0` | 13.29.0 | Dispatcher, EventServiceProvider; apps type-hint Dispatcher. | illuminate/contracts, illuminate/support | required | Same version as laravel/framework (replace). Independent package for non-framework Illuminate consumers. |
| 44 | `illuminate/config` | `^13.0` | 13.29.0 | Repository interface implementation for config(). | illuminate/contracts | required | Same version as laravel/framework (replace). Independent package for non-framework Illuminate consumers. |
| 45 | `illuminate/filesystem` | `^13.0` | 13.29.0 | Filesystem, FilesystemAdapter, and Flysystem bridges. | illuminate/contracts, league/flysystem | required | Same version as laravel/framework (replace). Independent package for non-framework Illuminate consumers. |
| 46 | `illuminate/http` | `^13.0` | 13.29.0 | Request, Response, JsonResponse, RedirectResponse (extend Symfony HTTP Foundation). | illuminate/contracts, illuminate/support, illuminate/session, illuminate/cookie, symfony/http-foundation, guzzlehttp/guzzle | required | Same version as laravel/framework (replace). Independent package for non-framework Illuminate consumers. |
| 47 | `illuminate/session` | `^13.0` | 13.29.0 | Session Manager, Store, and middleware types. | illuminate/contracts, illuminate/support, symfony/http-foundation | required | Same version as laravel/framework (replace). Independent package for non-framework Illuminate consumers. |
| 48 | `illuminate/cookie` | `^13.0` | 13.29.0 | Cookie jar types wrapping Symfony cookies. | illuminate/contracts, symfony/http-foundation | required | Same version as laravel/framework (replace). Independent package for non-framework Illuminate consumers. |
| 49 | `illuminate/encryption` | `^13.0` | 13.29.0 | Encrypter / DecryptException used in custom guards and middleware. | illuminate/contracts | required | Same version as laravel/framework (replace). Independent package for non-framework Illuminate consumers. |
| 50 | `illuminate/hashing` | `^13.0` | 13.29.0 | Hasher contract implementation (Bcrypt/Argon). | illuminate/contracts | required | Same version as laravel/framework (replace). Independent package for non-framework Illuminate consumers. |
| 51 | `illuminate/cache` | `^13.0` | 13.29.0 | Cache Manager, Repository, RateLimiter. | illuminate/contracts, psr/simple-cache | required | Same version as laravel/framework (replace). Independent package for non-framework Illuminate consumers. |
| 52 | `illuminate/log` | `^13.0` | 13.29.0 | LogManager; often used with LoggerInterface rather than concrete Monolog. | illuminate/contracts, psr/log (already-have), monolog/monolog (already-have) | required | Same version as laravel/framework (replace). Independent package for non-framework Illuminate consumers. |
| 53 | `illuminate/database` | `^13.0` | 13.29.0 | Eloquent Model, Builder, Query\Builder, Connection, migrations. | illuminate/contracts, illuminate/support, illuminate/container | required | Same version as laravel/framework (replace). Independent package for non-framework Illuminate consumers. |
| 54 | `illuminate/pagination` | `^13.0` | 13.29.0 | LengthAwarePaginator, CursorPaginator, Paginator. | illuminate/contracts, illuminate/support | required | Same version as laravel/framework (replace). Independent package for non-framework Illuminate consumers. |
| 55 | `illuminate/validation` | `^13.0` | 13.29.0 | Validator, ValidationException, Rule, Factory. | illuminate/contracts, illuminate/support, egulias/email-validator | required | Same version as laravel/framework (replace). Independent package for non-framework Illuminate consumers. |
| 56 | `illuminate/translation` | `^13.0` | 13.29.0 | Translator used by validation and __(). | illuminate/contracts, illuminate/support, symfony/translation-contracts | required | Same version as laravel/framework (replace). Independent package for non-framework Illuminate consumers. |
| 57 | `illuminate/auth` | `^13.0` | 13.29.0 | Guard, Authenticatable, AuthManager, notifications of auth events. | illuminate/contracts, illuminate/support, illuminate/http, illuminate/session | required | Same version as laravel/framework (replace). Independent package for non-framework Illuminate consumers. |
| 58 | `illuminate/routing` | `^13.0` | 13.29.0 | Router, Route, UrlGenerator, Middleware, controllers. | illuminate/contracts, illuminate/http, illuminate/support, illuminate/container, symfony/routing | required | Same version as laravel/framework (replace). Independent package for non-framework Illuminate consumers. |
| 59 | `illuminate/view` | `^13.0` | 13.29.0 | Factory, View, Engine; Blade compiler types. | illuminate/contracts, illuminate/support | required | Same version as laravel/framework (replace). Independent package for non-framework Illuminate consumers. |
| 60 | `illuminate/console` | `^13.0` | 13.29.0 | Command (extends Symfony Console), Scheduling, Artisan kernel. | illuminate/contracts, illuminate/support, symfony/console, laravel/prompts, nunomaduro/termwind | required | Same version as laravel/framework (replace). Independent package for non-framework Illuminate consumers. |
| 61 | `illuminate/mail` | `^13.0` | 13.29.0 | Mailable, Mailer, Message wrapping Symfony Mailer. | illuminate/contracts, illuminate/support, symfony/mailer, symfony/mime | required | Same version as laravel/framework (replace). Independent package for non-framework Illuminate consumers. |
| 62 | `illuminate/queue` | `^13.0` | 13.29.0 | Job, Queue Manager, Worker, FailedJobProvider. | illuminate/contracts, illuminate/support, illuminate/bus | required | Same version as laravel/framework (replace). Independent package for non-framework Illuminate consumers. |
| 63 | `illuminate/bus` | `^13.0` | 13.29.0 | Dispatcher, Queueable, Batch, PendingBatch. | illuminate/contracts, illuminate/pipeline | required | Same version as laravel/framework (replace). Independent package for non-framework Illuminate consumers. |
| 64 | `illuminate/notifications` | `^13.0` | 13.29.0 | Notification, ChannelManager, Messages (mail/slack/broadcast). | illuminate/contracts, illuminate/support, illuminate/queue | required | Same version as laravel/framework (replace). Independent package for non-framework Illuminate consumers. |
| 65 | `illuminate/broadcasting` | `^13.0` | 13.29.0 | Broadcaster, Channel, PresenceChannel, UniqueChannel. | illuminate/contracts, illuminate/http | required | Same version as laravel/framework (replace). Independent package for non-framework Illuminate consumers. |
| 66 | `illuminate/redis` | `^13.0` | 13.29.0 | Redis Manager and connections (PhpRedis / Predis). | illuminate/contracts, illuminate/support | required | Same version as laravel/framework (replace). Independent package for non-framework Illuminate consumers. Predis\Client appears only if the Predis connector is used; not a hard public prereq. |
| 67 | `illuminate/testing` | `^13.0` | 13.29.0 | TestResponse, PendingCommand, database helpers used in tests. | illuminate/http, illuminate/support, illuminate/database | required | Same version as laravel/framework (replace). Independent package for non-framework Illuminate consumers. |
| 68 | `illuminate/process` | `^13.0` | 13.29.0 | Process, Factory, FakeProcess wrapping Symfony Process. | illuminate/contracts, symfony/process | required | Same version as laravel/framework (replace). Independent package for non-framework Illuminate consumers. |
| 69 | `illuminate/image` | `^13.0` | 13.29.0 | Image manager / intervention bridge types on the Laravel 13 image surface. | illuminate/contracts, intervention/image | required | Same version as laravel/framework (replace). Independent package for non-framework Illuminate consumers. |
| 70 | `illuminate/json-schema` | `^13.0` | 13.29.0 | JSON Schema builder types added on the Laravel 13 surface. | illuminate/contracts | required | Same version as laravel/framework (replace). Independent package for non-framework Illuminate consumers. |
| 71 | `illuminate/reflection` | `^13.0` | 13.29.0 | Reflector helpers used by container and attributes. | illuminate/contracts | required | Same version as laravel/framework (replace). Independent package for non-framework Illuminate consumers. |
| 72 | `laravel/serializable-closure` | `^2.0` | 2.0.16 | SerializableClosure used in queued closures and some package APIs. | none | required | Laravel 13 requires ^2.0.10. |
| 73 | `laravel/prompts` | `^0.3` | 0.3.24 | Prompt types used from Artisan commands and first-party tools. | none | required | Not Laravel-app-only; used by Pint/installer CLIs too. |
| 74 | `nunomaduro/termwind` | `^2.0` | 2.4.0 | Termwind components used by Laravel console output. | symfony/console | — | Laravel 13 requires ^2.0. |
| 75 | `nesbot/carbon` | `^3.0` | 3.13.2 | Carbon / CarbonImmutable; Laravel and Symfony apps type-hint these everywhere. | psr/clock, symfony/translation-contracts | — | 3.x current. Doctrine types live in a separate package not required on Carbon's public surface. |
| 76 | `doctrine/inflector` | `^2.0` | 2.1.0 | Inflector used via Illuminate\Support\Str (pluralization); also named directly in some packages. | none | — | — |
| 77 | `egulias/email-validator` | `^4.0` | 4.0.4 | EmailValidator / EmailValidation used by Symfony Validator and Illuminate validation. | doctrine/lexer | — | — |
| 78 | `doctrine/lexer` | `^3.0` | 3.0.1 | Token/AbstractLexer; public for custom Doctrine parsers and email-validator. | none | — | — |
| 79 | `dragonmantank/cron-expression` | `^3.0` | 3.6.0 | CronExpression used by Laravel scheduler and some job libraries. | none | — | — |
| 80 | `fruitcake/php-cors` | `^1.0` | 1.4.0 | CorsService used by Laravel CORS middleware. | none | — | — |
| 81 | `league/commonmark` | `^2.0` | 2.10.0 | Markdown converter/environment types; Laravel markdown mail and many CMSs name these. | league/config | — | — |
| 82 | `league/config` | `^1.0` | 1.2.0 | League Configuration / schema types on CommonMark's public Environment API. | nette/schema, nette/utils | — | — |
| 83 | `nette/schema` | `^1.0` | 1.3.6 | Schema/Processor types used by league/config. | nette/utils | — | — |
| 84 | `nette/utils` | `^4.0` | 4.1.5 | Arrays, Strings, Json, Validators; public for Nette and league/config. | none | — | — |
| 85 | `league/flysystem` | `^3.0` | 3.35.3 | FilesystemOperator / FilesystemAdapter — Laravel Storage and many apps type-hint these. | league/mime-type-detection | — | — |
| 86 | `league/flysystem-local` | `^3.0` | 3.35.3 | LocalFilesystemAdapter constructed in app service providers. | league/flysystem | — | — |
| 87 | `league/mime-type-detection` | `^1.0` | 1.17.0 | MimeTypeDetector used when configuring Flysystem adapters. | none | — | — |
| 88 | `league/uri` | `^7.0` | 7.8.1 | League URI value objects; Laravel 13 requires these for URL handling. | league/uri-interfaces | — | — |
| 89 | `league/uri-interfaces` | `^7.0` | 7.8.1 | UriInterface (League) distinct from PSR-7 UriInterface. | none | — | — |
| 90 | `vlucas/phpdotenv` | `^5.0` | 5.7.0 | Dotenv, RepositoryBuilder, Exception types used at bootstrap. | none | — | phpoption is an internal implementation detail — not listed. |
| 91 | `ramsey/uuid` | `^4.0` | 4.9.3 | UuidInterface / Uuid — extremely common domain type-hint. | ramsey/collection, brick/math | — | — |
| 92 | `ramsey/collection` | `^2.0` | 2.1.1 | Collection interfaces used on Ramsey UUID's public types. | none | — | — |
| 93 | `brick/math` | `^0.19` | 0.19.1 | BigInteger/BigDecimal; UUID, money, and Laravel use these types. | none | — | 0.x versioning; Laravel 13 allows ^0.14.2 through ^0.19. |
| 94 | `laravel/framework` | `^13.0` | 13.29.0 | The Laravel application surface: Application, facades' real types, Eloquent, HTTP kernel. | illuminate/contracts, illuminate/support, illuminate/container, illuminate/http, illuminate/routing, illuminate/database, illuminate/validation, illuminate/auth, illuminate/view, illuminate/console, illuminate/cache, illuminate/queue, illuminate/mail, illuminate/events, illuminate/log, illuminate/session, illuminate/cookie, illuminate/filesystem, illuminate/pagination, illuminate/notifications, illuminate/broadcasting, illuminate/bus, illuminate/redis, illuminate/testing, illuminate/process, illuminate/translation, illuminate/hashing, illuminate/encryption, illuminate/config, illuminate/pipeline, illuminate/collections, illuminate/macroable, illuminate/conditionable, illuminate/image, illuminate/json-schema, illuminate/reflection, laravel/serializable-closure, laravel/prompts, nesbot/carbon, guzzlehttp/guzzle, monolog/monolog (already-have), psr/log (already-have), psr/container, psr/simple-cache, psr/http-message, symfony/http-foundation, symfony/http-kernel, symfony/routing, symfony/console, symfony/mailer, symfony/mime, symfony/process, symfony/var-dumper, symfony/error-handler, symfony/finder, symfony/uid, league/flysystem, league/commonmark, league/uri, ramsey/uuid, vlucas/phpdotenv, brick/math, nunomaduro/termwind, doctrine/inflector, dragonmantank/cron-expression, egulias/email-validator, fruitcake/php-cors | required | Current major 13 (latest 13.29.0, PHP ^8.3). Laravel 12 remains in security support until 2027-02-24. Illuminate split packages are composer-replaced by this package. |
| 95 | `laravel/tinker` | `^3.0` | 3.0.2 | Tinker command / PsySH bridge used in every Laravel app. | laravel/framework, psy/psysh | required | — |
| 96 | `psy/psysh` | `^0.12` | 0.12.24 | Shell, Configuration, and command types used by Tinker and standalone REPL. | nikic/php-parser, symfony/console | — | — |
| 97 | `laravel/pint` | `^1.0` | 1.30.5 | Pint CLI configuration types; first-party Laravel formatter. | none | required | PHP ^8.3; not bound to a Laravel major. |
| 98 | `laravel/sail` | `^1.0` | 1.67.0 | Sail Docker services helper used from Laravel apps. | laravel/framework | required | — |
| 99 | `laravel/pail` | `^1.0` | 1.2.7 | Pail log tail command types. | laravel/framework | required | — |

### 4. Symfony components and framework

| Rank | Package | Constraint | Latest patch | Why | Public-API prerequisites | Marks | Notes |
| ---: | --- | --- | --- | --- | --- | --- | --- |
| 100 | `symfony/finder` | `^7.4` | 7.4.17 | Finder used by Laravel, Composer tools, and app file scans. | none | required | 7.4 LTS target (PHP >= 8.2). Current non-LTS is 8.1.x (PHP >= 8.4). |
| 101 | `symfony/process` | `^7.4` | 7.4.17 | Process / ProcessFailedException named in CLI and Laravel Process. | none | required | 7.4 LTS target (PHP >= 8.2). Current non-LTS is 8.1.x (PHP >= 8.4). |
| 102 | `symfony/string` | `^7.4` | 7.4.15 | UnicodeString / ByteString; Console and Validator expose these. | none | required | 7.4 LTS target (PHP >= 8.2). Current non-LTS is 8.1.x (PHP >= 8.4). |
| 103 | `symfony/event-dispatcher` | `^7.4` | 7.4.17 | EventDispatcher, Event, StoppableEventInterface (PSR-14 implementation). | psr/event-dispatcher, symfony/event-dispatcher-contracts | required | 7.4 LTS target (PHP >= 8.2). Current non-LTS is 8.1.x (PHP >= 8.4). |
| 104 | `symfony/var-dumper` | `^7.4` | 7.4.17 | Dumper / VarDumper used by dump()/dd() and debug bundles. | none | required | 7.4 LTS target (PHP >= 8.2). Current non-LTS is 8.1.x (PHP >= 8.4). |
| 105 | `symfony/error-handler` | `^7.4` | 7.4.17 | ErrorHandler and exception flatteners used by Laravel and Symfony kernels. | psr/log (already-have) | required | 7.4 LTS target (PHP >= 8.2). Current non-LTS is 8.1.x (PHP >= 8.4). |
| 106 | `symfony/http-foundation` | `^7.4` | 7.4.17 | Request, Response, ParameterBag, Session — Laravel HTTP extends these classes. | none | required | 7.4 LTS target (PHP >= 8.2). Current non-LTS is 8.1.x (PHP >= 8.4). |
| 107 | `symfony/http-kernel` | `^7.4` | 7.4.17 | HttpKernelInterface, KernelEvents, ExceptionEvent; Symfony apps and Laravel extend this. | symfony/http-foundation, symfony/event-dispatcher, psr/log (already-have) | required | 7.4 LTS target (PHP >= 8.2). Current non-LTS is 8.1.x (PHP >= 8.4). |
| 108 | `symfony/routing` | `^7.4` | 7.4.17 | Route, RouteCollection, Router, compiled routes. | none | required | 7.4 LTS target (PHP >= 8.2). Current non-LTS is 8.1.x (PHP >= 8.4). |
| 109 | `symfony/mime` | `^7.4` | 7.4.17 | Address, Email, MimeTypes used by mailers. | none | required | 7.4 LTS target (PHP >= 8.2). Current non-LTS is 8.1.x (PHP >= 8.4). |
| 110 | `symfony/mailer` | `^7.4` | 7.4.17 | MailerInterface, Envelope, Transport — Laravel mail and Symfony Mailer. | symfony/mime, psr/event-dispatcher, psr/log (already-have) | required | 7.4 LTS target (PHP >= 8.2). Current non-LTS is 8.1.x (PHP >= 8.4). |
| 111 | `symfony/translation` | `^7.4` | 7.4.17 | Translator, MessageCatalogue, LocaleSwitcher. | symfony/translation-contracts | required | 7.4 LTS target (PHP >= 8.2). Current non-LTS is 8.1.x (PHP >= 8.4). |
| 112 | `symfony/console` | `^7.4` | 7.4.17 | Command, InputInterface, OutputInterface, Application — Laravel Artisan extends these. | symfony/string, psr/log (already-have) | required | 7.4 LTS target (PHP >= 8.2). Current non-LTS is 8.1.x (PHP >= 8.4). |
| 113 | `symfony/filesystem` | `^7.4` | 7.4.17 | Filesystem / Path used by many tools and Symfony bundles. | none | required | 7.4 LTS target (PHP >= 8.2). Current non-LTS is 8.1.x (PHP >= 8.4). |
| 114 | `symfony/yaml` | `^7.4` | 7.4.17 | Yaml parser/dumper types used in Symfony config and many CLIs. | none | required | 7.4 LTS target (PHP >= 8.2). Current non-LTS is 8.1.x (PHP >= 8.4). |
| 115 | `symfony/clock` | `^7.4` | 7.4.8 | Clock / MockClock implementing PSR-20. | psr/clock | required | 7.4 LTS target (PHP >= 8.2). Current non-LTS is 8.1.x (PHP >= 8.4). |
| 116 | `symfony/uid` | `^7.4` | 7.4.17 | Uuid / Ulid value objects (Laravel 13 requires this component). | none | required | 7.4 LTS target (PHP >= 8.2). Current non-LTS is 8.1.x (PHP >= 8.4). |
| 117 | `symfony/options-resolver` | `^7.4` | 7.4.8 | OptionsResolver used by form, serializer, and third-party bundles. | none | required | 7.4 LTS target (PHP >= 8.2). Current non-LTS is 8.1.x (PHP >= 8.4). |
| 118 | `symfony/var-exporter` | `^7.4` | 7.4.16 | VarExporter / Instantiator used by cache and DI proxies. | none | required | 7.4 LTS target (PHP >= 8.2). Current non-LTS is 8.1.x (PHP >= 8.4). |
| 119 | `symfony/css-selector` | `^7.4` | 7.4.17 | CssSelectorConverter used by DomCrawler and Symfony Panther. | none | required | 7.4 LTS target (PHP >= 8.2). Current non-LTS is 8.1.x (PHP >= 8.4). |
| 120 | `symfony/dom-crawler` | `^7.4` | 7.4.17 | Crawler used in functional tests and scrapers. | symfony/css-selector | required | 7.4 LTS target (PHP >= 8.2). Current non-LTS is 8.1.x (PHP >= 8.4). |
| 121 | `symfony/browser-kit` | `^7.4` | 7.4.17 | AbstractBrowser / History used by Symfony Panther and crawler tests. | symfony/dom-crawler, symfony/http-foundation | required | 7.4 LTS target (PHP >= 8.2). Current non-LTS is 8.1.x (PHP >= 8.4). |
| 122 | `symfony/dependency-injection` | `^7.4` | 7.4.17 | ContainerBuilder, Definition, CompilerPassInterface. | psr/container, symfony/service-contracts | required | 7.4 LTS target (PHP >= 8.2). Current non-LTS is 8.1.x (PHP >= 8.4). |
| 123 | `symfony/config` | `^7.4` | 7.4.17 | FileLocator, Definition, ConfigCache — bundle configuration API. | none | required | 7.4 LTS target (PHP >= 8.2). Current non-LTS is 8.1.x (PHP >= 8.4). |
| 124 | `symfony/cache` | `^7.4` | 7.4.17 | AdapterInterface (PSR-6/16) and TagAwareAdapter. | psr/cache, psr/log (already-have), symfony/cache-contracts | required | 7.4 LTS target (PHP >= 8.2). Current non-LTS is 8.1.x (PHP >= 8.4). |
| 125 | `symfony/property-info` | `^7.4` | 7.4.17 | PropertyInfoExtractor used by serializer and validator. | none | required | 7.4 LTS target (PHP >= 8.2). Current non-LTS is 8.1.x (PHP >= 8.4). |
| 126 | `symfony/property-access` | `^7.4` | 7.4.16 | PropertyAccessor used by forms and serializer. | symfony/property-info | required | 7.4 LTS target (PHP >= 8.2). Current non-LTS is 8.1.x (PHP >= 8.4). |
| 127 | `symfony/type-info` | `^7.4` | 7.4.17 | Type / TypeIdentifier used by Serializer 7.4+ property metadata. | none | required | 7.4 LTS target (PHP >= 8.2). Current non-LTS is 8.1.x (PHP >= 8.4). Present on 7.4; more central in 8.x. |
| 128 | `symfony/serializer` | `^7.4` | 7.4.17 | SerializerInterface, NormalizerInterface, DenormalizerInterface. | symfony/property-access, symfony/property-info | required | 7.4 LTS target (PHP >= 8.2). Current non-LTS is 8.1.x (PHP >= 8.4). |
| 129 | `symfony/validator` | `^7.4` | 7.4.17 | ValidatorInterface, Constraint, ConstraintViolationList, ValidationException. | symfony/translation-contracts | required | 7.4 LTS target (PHP >= 8.2). Current non-LTS is 8.1.x (PHP >= 8.4). |
| 130 | `symfony/form` | `^7.4` | 7.4.17 | FormInterface, FormBuilder, AbstractType — Symfony's form component. | symfony/event-dispatcher, symfony/options-resolver, symfony/property-access | required | 7.4 LTS target (PHP >= 8.2). Current non-LTS is 8.1.x (PHP >= 8.4). |
| 131 | `symfony/expression-language` | `^7.4` | 7.4.14 | ExpressionLanguage used by security, validator, and workflows. | none | required | 7.4 LTS target (PHP >= 8.2). Current non-LTS is 8.1.x (PHP >= 8.4). |
| 132 | `symfony/http-client` | `^7.4` | 7.4.17 | HttpClient, ResponseInterface (Symfony), MockHttpClient, EventSourceHttpClient. | symfony/http-client-contracts, psr/log (already-have) | required | 7.4 LTS target (PHP >= 8.2). Current non-LTS is 8.1.x (PHP >= 8.4). |
| 133 | `symfony/psr-http-message-bridge` | `^7.4` | 7.4.8 | PsrHttpFactory / HttpFoundationFactory converting Symfony ↔ PSR-7. | symfony/http-foundation, psr/http-message, psr/http-factory | required | 7.4 LTS target (PHP >= 8.2). Current non-LTS is 8.1.x (PHP >= 8.4). |
| 134 | `symfony/security-core` | `^7.4` | 7.4.17 | TokenInterface, UserInterface, AuthorizationChecker, AuthenticationException. | none | required | 7.4 LTS target (PHP >= 8.2). Current non-LTS is 8.1.x (PHP >= 8.4). |
| 135 | `symfony/security-http` | `^7.4` | 7.4.17 | AbstractAuthenticator, Passport, firewall listeners. | symfony/security-core, symfony/http-kernel, symfony/http-foundation | required | 7.4 LTS target (PHP >= 8.2). Current non-LTS is 8.1.x (PHP >= 8.4). |
| 136 | `symfony/security-csrf` | `^7.4` | 7.4.8 | CsrfTokenManager / CsrfToken. | symfony/security-core | required | 7.4 LTS target (PHP >= 8.2). Current non-LTS is 8.1.x (PHP >= 8.4). |
| 137 | `symfony/password-hasher` | `^7.4` | 7.4.8 | PasswordHasherInterface used with Security. | none | required | 7.4 LTS target (PHP >= 8.2). Current non-LTS is 8.1.x (PHP >= 8.4). |
| 138 | `symfony/security-bundle` | `^7.4` | 7.4.15 | SecurityBundle configuration and Security facade-like helpers. | symfony/security-core, symfony/security-http, symfony/framework-bundle | required | 7.4 LTS target (PHP >= 8.2). Current non-LTS is 8.1.x (PHP >= 8.4). |
| 139 | `symfony/lock` | `^7.4` | 7.4.17 | LockInterface / LockFactory. | none | required | 7.4 LTS target (PHP >= 8.2). Current non-LTS is 8.1.x (PHP >= 8.4). |
| 140 | `symfony/rate-limiter` | `^7.4` | 7.4.16 | RateLimiterFactory / LimiterInterface. | none | required | 7.4 LTS target (PHP >= 8.2). Current non-LTS is 8.1.x (PHP >= 8.4). |
| 141 | `symfony/messenger` | `^7.4` | 7.4.17 | MessageBusInterface, Envelope, Handlers, transports. | psr/log (already-have) | required | 7.4 LTS target (PHP >= 8.2). Current non-LTS is 8.1.x (PHP >= 8.4). |
| 142 | `symfony/notifier` | `^7.4` | 7.4.17 | NotifierInterface, Notification, Recipient, SMS/Chat transports. | psr/log (already-have) | required | 7.4 LTS target (PHP >= 8.2). Current non-LTS is 8.1.x (PHP >= 8.4). |
| 143 | `symfony/workflow` | `^7.4` | 7.4.9 | Workflow, StateMachine, MarkingStore. | symfony/event-dispatcher | required | 7.4 LTS target (PHP >= 8.2). Current non-LTS is 8.1.x (PHP >= 8.4). |
| 144 | `symfony/intl` | `^7.4` | 7.4.17 | Countries, Locales, Currencies helpers. | none | required | 7.4 LTS target (PHP >= 8.2). Current non-LTS is 8.1.x (PHP >= 8.4). |
| 145 | `symfony/runtime` | `^7.4` | 7.4.14 | Runner / RuntimeInterface for Symfony runtime. | none | required | 7.4 LTS target (PHP >= 8.2). Current non-LTS is 8.1.x (PHP >= 8.4). |
| 146 | `symfony/dotenv` | `^7.4` | 7.4.15 | Dotenv loader used by Symfony runtime (separate from vlucas/phpdotenv). | none | required | 7.4 LTS target (PHP >= 8.2). Current non-LTS is 8.1.x (PHP >= 8.4). |
| 147 | `symfony/twig-bridge` | `^7.4` | 7.4.17 | Twig extensions for Symfony form/security/translation. | twig/twig, symfony/translation-contracts | required | 7.4 LTS target (PHP >= 8.2). Current non-LTS is 8.1.x (PHP >= 8.4). |
| 148 | `symfony/twig-bundle` | `^7.4` | 7.4.15 | TwigBundle and Environment configuration. | symfony/twig-bridge, twig/twig, symfony/framework-bundle | required | 7.4 LTS target (PHP >= 8.2). Current non-LTS is 8.1.x (PHP >= 8.4). |
| 149 | `symfony/doctrine-bridge` | `^7.4` | 7.4.17 | Doctrine types in Symfony (EntityValueResolver, form types). | doctrine/persistence, doctrine/event-manager | required | 7.4 LTS target (PHP >= 8.2). Current non-LTS is 8.1.x (PHP >= 8.4). |
| 150 | `symfony/monolog-bridge` | `^7.4` | 7.4.17 | Monolog processors/handlers integrated with Symfony. | monolog/monolog (already-have), psr/log (already-have) | required | 7.4 LTS target (PHP >= 8.2). Current non-LTS is 8.1.x (PHP >= 8.4). |
| 151 | `symfony/phpunit-bridge` | `^7.4` | 7.4.17 | Symfony PHPUnit helpers (clock mock, deprecation reporting). | phpunit/phpunit | required | 7.4 LTS target (PHP >= 8.2). Current non-LTS is 8.1.x (PHP >= 8.4). phpunit-bridge is versioned with the Symfony components. |
| 152 | `symfony/mailgun-mailer` | `^7.4` | 7.4.16 | Mailgun transport types used when that mailer is enabled. | symfony/mailer, symfony/http-client | required | 7.4 LTS target (PHP >= 8.2). Current non-LTS is 8.1.x (PHP >= 8.4). |
| 153 | `symfony/postmark-mailer` | `^7.4` | 7.4.13 | Postmark transport types. | symfony/mailer, symfony/http-client | required | 7.4 LTS target (PHP >= 8.2). Current non-LTS is 8.1.x (PHP >= 8.4). |
| 154 | `symfony/framework-bundle` | `^7.4` | 7.4.17 | Kernel, AbstractController, controller attributes — the Symfony app runtime. | symfony/dependency-injection, symfony/http-kernel, symfony/routing, symfony/config, symfony/error-handler, psr/log (already-have), psr/container | required | 7.4 LTS target (PHP >= 8.2). Current non-LTS is 8.1.x (PHP >= 8.4). |
| 155 | `symfony/monolog-bundle` | `^4.0` | 4.0.2 | MonologBundle configuration and logger channels in Symfony apps. | symfony/monolog-bridge, monolog/monolog (already-have), symfony/framework-bundle | required | Own versioning (4.0.2), not the 7.4 line. |
| 156 | `symfony/flex` | `^2.0` | 2.11.0 | Flex recipe configurator; Symfony project tooling. | composer/composer | required | Own versioning (2.11.0). Composer plugin more than an app type surface. |
| 157 | `symfony/maker-bundle` | `^1.0` | 1.67.0 | Maker commands / Generator types when writing custom makers. | symfony/framework-bundle, nikic/php-parser | required | Own versioning (1.67.0). |
| 158 | `symfony/ux-twig-component` | `^3.0` | 3.4.0 | TwigComponent / AsTwigComponent — widely type-hinted in Symfony UX apps. | twig/twig, symfony/framework-bundle | — | UX 3.4.0. |
| 159 | `symfony/ux-live-component` | `^3.0` | 3.4.0 | LiveComponent / LiveProp types. | symfony/ux-twig-component | — | UX 3.4.0. |
| 160 | `twig/twig` | `^3.0` | 3.28.0 | Environment, Template, ExtensionInterface, Node — Symfony, Drupal, and many CMS type-hint Twig. | none | required | 3.28.0 current. Independent of Symfony major. |
| 161 | `doctrine/doctrine-bundle` | `^3.0` | 3.3.1 | Registry, ManagerRegistry, bundle configuration for Symfony+Doctrine. | doctrine/orm, doctrine/dbal, doctrine/persistence, symfony/framework-bundle | required | 3.3.1 requires PHP ^8.4 — note if targeting PHP 8.3 Symfony 7.4 apps (older bundle majors may apply). |

### 5. Testing and QA tools

| Rank | Package | Constraint | Latest patch | Why | Public-API prerequisites | Marks | Notes |
| ---: | --- | --- | --- | --- | --- | --- | --- |
| 162 | `phpunit/phpunit` | `^12.5` | 12.5.33 (PHPUnit 13.3.1 is current for PHP >= 8.4.1) | TestCase, Assert, attributes, mock objects — the default PHP test type surface. | none | required | Suggested ^12.5 (latest 12.5.33, PHP >= 8.3) for Laravel 13 on PHP 8.3. Current latest is 13.3.1 (PHP >= 8.4.1). Laravel 13 allows ^11.5.50  or  ^12.5.8  or  ^13.0.3. sebastian/* internals omitted. |
| 163 | `hamcrest/hamcrest-php` | `^3.0` | 3.0.0 | Hamcrest matchers; Mockery's matcher API can expose these types. | none | — | — |
| 164 | `mockery/mockery` | `^1.0` | 1.6.15 | Mockery::mock, MockInterface, expectation types used instead of PHPUnit mocks. | hamcrest/hamcrest-php | required | — |
| 165 | `phpspec/prophecy` | `^1.0` | 1.26.1 | ObjectProphecy / ProphecyInterface still used in older PHPUnit suites. | none | required | — |
| 166 | `phpspec/prophecy-phpunit` | `^2.0` | 2.5.0 | ProphecyTrait for PHPUnit integration. | phpspec/prophecy, phpunit/phpunit | — | — |
| 167 | `phpspec/phpspec` | `^8.0` | 8.3.1 | Spec / ObjectBehavior for PhpSpec-style tests. | phpspec/prophecy, nikic/php-parser | — | — |
| 168 | `pestphp/pest` | `^4.7` | 4.7.8 (Pest 5.1.3 is current for PHP ^8.4) | Pest test functions, Expectation, datasets, architecture tests. | phpunit/phpunit | required | Suggested ^4.7 (v4.7.8, PHP ^8.3) to match Laravel 13 min PHP. Current latest is 5.1.3 (PHP ^8.4). |
| 169 | `pestphp/pest-plugin-laravel` | `^4.1` | 4.1.0 with Pest 4; 5.0.1 is current for PHP ^8.4 + Laravel ^13.23 | Pest Laravel helpers (get/post/actingAs, artisan). | pestphp/pest, laravel/framework | required | Use ^4.1 with Pest 4. v5.0.1 requires Pest 5, PHP ^8.4, and Laravel ^13.23. |
| 170 | `pestphp/pest-plugin-arch` | `^4.0` | 4.0.2 (v5.0.0 is current for Pest 5 / PHP ^8.4) | arch() expectations; tests name ArchExpectation types rarely but the plugin is ubiquitous. | pestphp/pest | — | Use ^4.0 with Pest 4 (latest 4.0.2). v5.0.0 is current for Pest 5 / PHP ^8.4. |
| 171 | `brianium/paratest` | `^7.0` | 7.24.1 | ParaTest runner wrapper around PHPUnit. | phpunit/phpunit | — | v7.24.1 wants PHP 8.4+. Laravel suggests ^7  or  ^8. |
| 172 | `infection/infection` | `^0.35` | 0.35.2 | Mutator / InfectionConfig for mutation testing. | nikic/php-parser | required | 0.x line (^0.35, latest 0.35.2). |
| 173 | `codeception/codeception` | `^5.0` | 5.3.5 | Actor, Module, Scenario — Codeception's public test types. | phpunit/phpunit, php-http/httplug | required | — |
| 174 | `behat/gherkin` | `^4.0` | 4.17.0 | Node/FeatureNode AST for custom Behat formatters and transformations. | none | — | — |
| 175 | `behat/behat` | `^3.0` | 3.32.0 | Context, Step definitions, Transformation types. | behat/gherkin, psr/container | required | — |
| 176 | `fakerphp/faker` | `^1.0` | 1.24.1 | Generator / Provider types used in factories and Pest/PHPUnit tests. | psr/container | — | — |
| 177 | `squizlabs/php_codesniffer` | `^4.0` | 4.0.4 | Sniff, File, Tokens — custom PHPCS rules type-hint these. | none | required | 4.0.4 current. |
| 178 | `phpcompatibility/php-compatibility` | `^9.0` | 9.3.5 | PHPCompatibility sniffs extending PHPCS. | squizlabs/php_codesniffer | — | — |
| 179 | `slevomat/coding-standard` | `^8.0` | 8.31.1 | Slevomat sniffs; custom rules extend these classes. | squizlabs/php_codesniffer | — | — |
| 180 | `friendsofphp/php-cs-fixer` | `^3.0` | 3.95.23 | AbstractFixer, Tokens, RuleSet — custom fixers type-hint these. | nikic/php-parser | required | v3.95.23 current. |
| 181 | `phpstan/phpstan` | `^2.0` | 2.2.9 | PHPStan rules/extensions type-hint PHPStan\* from this package (PHAR). | none | required | 2.2.9 current. Extension authors also need phpstan/phpdoc-parser. |
| 182 | `phpstan/phpdoc-parser` | `^2.0` | 2.3.3 | PHPDoc AST nodes used by PHPStan, Psalm, Rector, and CS Fixer ecosystems. | none | — | — |
| 183 | `phpstan/phpstan-phpunit` | `^2.0` | 2.0.18 | PHPUnit-aware PHPStan extensions. | phpstan/phpstan, phpunit/phpunit | — | — |
| 184 | `phpstan/phpstan-doctrine` | `^2.0` | 2.0.28 | Doctrine-aware PHPStan extensions. | phpstan/phpstan, doctrine/orm | — | — |
| 185 | `phpstan/phpstan-symfony` | `^2.0` | 2.0.20 | Symfony-aware PHPStan extensions. | phpstan/phpstan, symfony/framework-bundle | — | — |
| 186 | `vimeo/psalm` | `^6.0` | 6.16.1 | Psalm PluginInterface, Issue, Type API for custom plugins. | nikic/php-parser | required | 6.16.1 current. |
| 187 | `psalm/plugin-laravel` | `^4.0` | 4.15.7 | Laravel Psalm plugin. | vimeo/psalm, laravel/framework | — | — |
| 188 | `rector/rector` | `^2.0` | 2.6.3 | Rector / AbstractRector / Node types for custom rules. | nikic/php-parser, phpstan/phpstan | required | 2.6.3 current. |
| 189 | `driftingly/rector-laravel` | `^2.0` | 2.6.0 | Laravel-specific Rector sets (the maintained Laravel Rector package). | rector/rector, laravel/framework | — | — |
| 190 | `nikic/php-parser` | `^5.0` | 5.8.0 | PhpParser\Node and related AST types; PHPStan, Rector, CS Fixer, Composer, and Psalm all expose or consume these. | none | required | v5.8.0 current. Generate before the QA tools that name Node types. |
| 191 | `phpdocumentor/reflection-common` | `^2.0` | 2.2.0 | Fqsen / Element types shared by reflection-docblock. | none | — | — |
| 192 | `phpdocumentor/type-resolver` | `^2.0` | 2.0.0 | Type / TypeResolver used by phpdocumentor and static analysis. | phpdocumentor/reflection-common, nikic/php-parser | — | — |
| 193 | `phpdocumentor/reflection-docblock` | `^6.0` | 6.0.3 | DocBlock / Tag types used by many annotation/attribute readers. | phpdocumentor/type-resolver, phpdocumentor/reflection-common | — | — |
| 194 | `webmozart/assert` | `^2.0` | 2.4.1 | Assert methods used in domain code (static analysis understands these). | none | — | 2.4.1 current (PHP ^8.2). |
| 195 | `composer/composer` | `^2.0` | 2.10.2 | Composer, IOInterface, PackageInterface, PluginInterface, Script\Event — plugin and script authors type-hint these. | composer/semver, composer/pcre, composer/class-map-generator, psr/log (already-have), psr/http-message, justinrainbow/json-schema, seld/jsonlint, react/promise | required | 2.10.2 current. Public plugin API lives in this package; ca-bundle/spdx/xdebug-handler/metadata-minifier are mostly internals. |
| 196 | `composer/semver` | `^3.0` | 3.4.4 | ConstraintInterface, VersionParser, CompilingMatcher — Composer plugins and version tooling. | none | required | — |
| 197 | `composer/pcre` | `^3.0` | 3.4.0 | Preg typed wrappers; some tools type-hint these. | none | — | — |
| 198 | `composer/class-map-generator` | `^1.0` | 1.7.3 | ClassMapGenerator used by Composer and scoper tools. | none | — | — |
| 199 | `justinrainbow/json-schema` | `^6.0` | 6.11.0 | Schema/Validator types used by Composer and OpenAPI tools. | none | — | — |
| 200 | `seld/jsonlint` | `^1.0` | 1.12.1 | JsonParser / ParsingException used by Composer. | none | — | — |
| 201 | `react/promise` | `^3.0` | 3.3.0 | PromiseInterface (React) used by Composer and ReactPHP HTTP. | none | — | Distinct from guzzlehttp/promises. |

### 6. Laravel addons, Spatie, Livewire, Filament

| Rank | Package | Constraint | Latest patch | Why | Public-API prerequisites | Marks | Notes |
| ---: | --- | --- | --- | --- | --- | --- | --- |
| 202 | `laravel/sanctum` | `^4.0` | 4.3.3 | HasApiTokens, PersonalAccessToken, Guard — SPA/API auth. | laravel/framework | required | v4.3.3; Laravel ^11  or  ^12  or  ^13. |
| 203 | `laravel/horizon` | `^5.0` | 5.48.3 | Horizon Job/metrics types and dashboard controllers. | laravel/framework | required | v5.48.3; Laravel ^13 supported. |
| 204 | `laravel/telescope` | `^5.0` | 5.22.1 | IncomingEntry, Watcher types for custom Telescope watchers. | laravel/framework | required | v5.22.1. |
| 205 | `laravel/cashier` | `^16.0` | 16.7.0 | Billable, Subscription, Stripe webhook types. | laravel/framework, stripe/stripe-php, moneyphp/money | required | v16.7.0. |
| 206 | `laravel/scout` | `^11.0` | 11.6.1 | Searchable, Engine, Builder. | laravel/framework | required | v11.6.1. |
| 207 | `laravel/socialite` | `^5.0` | 5.30.1 | Socialite, AbstractUser, ProviderInterface. | laravel/framework, guzzlehttp/guzzle, league/oauth1-client | required | v5.30.1. |
| 208 | `league/oauth1-client` | `^1.0` | 1.11.0 | OAuth1 Server / Credentials used by Socialite's Twitter-style providers. | guzzlehttp/guzzle | — | — |
| 209 | `laravel/breeze` | `^2.0` | 2.4.2 | Breeze scaffolding (thin type surface; starter kit). | laravel/framework | required | v2.4.2. Installer more than a runtime API. |
| 210 | `laravel/jetstream` | `^5.0` | 5.5.3 | Jetstream actions/teams contracts. | laravel/framework, laravel/fortify | required | v5.5.3. |
| 211 | `laravel/fortify` | `^1.0` | 1.39.0 | Fortify actions/contracts (CreateNewUser, Fortify::). | laravel/framework | required | v1.39.0. |
| 212 | `laravel/passport` | `^13.0` | 13.7.6 | HasApiTokens (Passport), Client, Token, Bridge types. | laravel/framework, league/oauth2-server, psr/http-message, nyholm/psr7 | required | v13.7.6. |
| 213 | `league/oauth2-server` | `^9.0` | 9.4.1 | AuthorizationServer, AccessToken, Repositories — Passport's public OAuth types. | psr/http-message, defuse/php-encryption, lcobucci/jwt | — | — |
| 214 | `laravel/octane` | `^2.0` | 2.19.1 | Octane Request/Response cycle, Contracts, workers (Swoole/RoadRunner). | laravel/framework, laminas/laminas-diactoros, spiral/roadrunner-http | required | v2.19.1. |
| 215 | `laravel/dusk` | `^8.0` | 8.6.0 | Dusk Browser, Element, ChromeProcess. | laravel/framework | required | v8.6.0. php-webdriver types are used internally more than named by app tests. |
| 216 | `laravel/pulse` | `^1.0` | 1.8.1 | Pulse recorders / Livewire dashboard components. | laravel/framework, livewire/livewire | required | v1.8.1. |
| 217 | `laravel/reverb` | `^1.0` | 1.11.1 | Reverb server / channel types (WebSockets). | laravel/framework | required | v1.11.1. |
| 218 | `laravel/pennant` | `^1.0` | 1.26.0 | Feature flags (Feature::, FeatureScopeable). | laravel/framework | required | v1.26.0. |
| 219 | `laravel/ai` | `^0.11` | 0.11.0 | Laravel AI SDK (generation, tools, embeddings). | laravel/framework | required | v0.11.0 pre-1.0; API may still move. Laravel 13 first-party. |
| 220 | `laravel/vapor-core` | `^2.0` | 2.46.0 | Vapor runtime / job queue types for Laravel Vapor. | laravel/framework, aws/aws-sdk-php | required | v2.46.0. |
| 221 | `livewire/livewire` | `^4.0` | 4.4.2 | Component, Attributes, Wireable — Livewire 4 public API. | laravel/framework | required | v4.4.2; Laravel ^10–^13. |
| 222 | `inertiajs/inertia-laravel` | `^3.0` | 3.3.1 | Inertia Response / Middleware / SSR. | laravel/framework | required | v3.3.1; Laravel ^11.35  or  ^12  or  ^13. |
| 223 | `filament/filament` | `^5.0` | 5.7.6 | Panel, Resources, Pages — Filament 5 admin. | laravel/framework, livewire/livewire, filament/forms, filament/tables, illuminate/support | required | v5.7.6. Forms/tables packages are the types apps extend most often. |
| 224 | `filament/forms` | `^5.0` | 5.7.6 | Form, Components, Contracts for Filament forms. | laravel/framework, livewire/livewire | — | — |
| 225 | `filament/tables` | `^5.0` | 5.7.6 | Table, Columns, Filters. | laravel/framework, livewire/livewire | — | — |
| 226 | `spatie/laravel-permission` | `^8.0` | 8.3.0 | HasRoles, Permission, Role, middleware. | laravel/framework | required | 8.3.0; Laravel ^12  or  ^13, PHP ^8.3. |
| 227 | `spatie/laravel-medialibrary` | `^11.0` | 11.23.5 | HasMedia, Media, conversions. | laravel/framework, spatie/image | required | 11.23.5. |
| 228 | `spatie/laravel-query-builder` | `^7.0` | 7.3.3 | QueryBuilder, AllowedFilter, AllowedSort. | laravel/framework | required | 7.3.3; Laravel ^12  or  ^13. |
| 229 | `spatie/laravel-backup` | `^10.0` | 10.3.2 | BackupJob, BackupDestination, notifications. | laravel/framework | required | 10.3.2. Internal dumpers are not listed unless apps type-hint them. |
| 230 | `spatie/laravel-activitylog` | `^5.0` | 5.1.0 | LogsActivity, Activity model. | laravel/framework | required | 5.1.0 requires PHP ^8.4 and Laravel ^13. |
| 231 | `spatie/laravel-data` | `^4.0` | 4.23.0 | Data objects, DataCollection, casts, transformers. | laravel/framework | required | 4.23.0. |
| 232 | `spatie/laravel-ignition` | `^2.0` | 2.12.0 | Ignition error page / solution providers for Laravel. | laravel/framework, spatie/ignition, spatie/error-solutions, spatie/flare-client-php | required | 2.12.0. |
| 233 | `spatie/ignition` | `^1.0` | 1.16.0 | Ignition core (framework-agnostic) ErrorPage / Solution. | spatie/error-solutions, spatie/flare-client-php, spatie/backtrace | — | — |
| 234 | `spatie/error-solutions` | `^2.0` | 2.0.5 | Solution / SolutionProvider types. | none | — | — |
| 235 | `spatie/flare-client-php` | `^3.0` | 3.3.1 | Flare client / Report types. | none | — | — |
| 236 | `spatie/backtrace` | `^1.0` | 1.8.2 | Frame / Backtrace types used by Ignition and Ray. | none | — | — |
| 237 | `spatie/image` | `^3.0` | 3.9.6 | Image manipulations used by medialibrary. | none | — | — |
| 238 | `spatie/laravel-package-tools` | `^1.0` | 1.93.2 | PackageServiceProvider used by almost every Spatie Laravel package. | laravel/framework | — | — |
| 239 | `fruitcake/laravel-debugbar` | `^4.0` | 4.4.2 | Laravel Debugbar service provider / collectors (current fruitcake line). | laravel/framework, php-debugbar/php-debugbar | required | v4.4.2. barryvdh/laravel-debugbar is the historical name at the same version — prefer this continuation. |
| 240 | `php-debugbar/php-debugbar` | `^3.0` | 3.8.0 | DebugBar, DataCollectorInterface — collectors type-hint these. | none | — | — |
| 241 | `barryvdh/laravel-ide-helper` | `^3.0` | 3.7.0 | IDE helper generators / Eloquent mixin docs. | laravel/framework | required | v3.7.0. Laravel ^11.15  or  ^12  or  ^13. |
| 242 | `nunomaduro/collision` | `^8.0` | 8.9.5 | Collision adapter for Laravel/Pest/PHPUnit pretty errors. | filp/whoops, phpunit/phpunit | — | — |
| 243 | `filp/whoops` | `^2.0` | 2.18.4 | Run / HandlerInterface / Inspector used by Collision and Laravel debug pages. | none | — | — |
| 244 | `larastan/larastan` | `^3.0` | 3.10.0 | Larastan extensions (PHPStan for Laravel). | phpstan/phpstan, laravel/framework | — | v3.10.0; Laravel ^11.44  or  ^12.4  or  ^13. |
| 245 | `orchestra/testbench` | `^11.0` | 11.2.0 | TestCase for Laravel package tests. | laravel/framework, orchestra/testbench-core, phpunit/phpunit | — | v11.2.0 for Laravel 13. |
| 246 | `orchestra/testbench-core` | `^11.0` | 11.4.0 | Core application factory for Testbench. | laravel/framework | — | v11.4.0. |
| 247 | `maatwebsite/excel` | `^4.0` | 4.0.2 | Excel / Concern interfaces / Import/Export classes. | laravel/framework, phpoffice/phpspreadsheet | required | 4.0.2; Laravel ^12  or  ^13. |
| 248 | `intervention/image` | `^4.0` | 4.3.1 | ImageManager / Image / Drivers (v3 API is ImageManager). | none | — | 4.3.1. Laravel 13 suggest ^4.0. |
| 249 | `intervention/image-laravel` | `^4.0` | 4.1.1 | Laravel service provider / Image facade for Intervention 4. | intervention/image, laravel/framework | — | — |
| 250 | `tightenco/ziggy` | `^2.0` | 2.6.4 | Ziggy route helper / Blade @routes types. | laravel/framework | — | v2.6.4. |

### 7. Other high-traffic libraries

| Rank | Package | Constraint | Latest patch | Why | Public-API prerequisites | Marks | Notes |
| ---: | --- | --- | --- | --- | --- | --- | --- |
| 251 | `predis/predis` | `^3.0` | 3.6.0 | Client, Pipeline, Transaction, Collection commands — the PHP Redis client. | none | required | v3.6.0 current; Laravel 13 allows ^2.3  or  ^3.0. |
| 252 | `doctrine/orm` | `^3.0` | 3.6.8 | EntityManagerInterface, QueryBuilder, UnitOfWork, Mapping. | doctrine/dbal, doctrine/collections, doctrine/persistence, doctrine/event-manager, doctrine/inflector, psr/cache | required | 3.6.8 current. |
| 253 | `doctrine/dbal` | `^4.0` | 4.4.4 | Connection, QueryBuilder, SchemaManager, Driver, Exception. | doctrine/event-manager, psr/cache, psr/log (already-have) | required | 4.4.4 (PHP ^8.2). |
| 254 | `doctrine/collections` | `^3.0` | 3.1.0 | Collection / Selectable / ArrayCollection — named throughout Doctrine and apps. | none | — | 3.1.0 requires PHP ^8.4; 2.x still used on PHP 8.3. |
| 255 | `doctrine/persistence` | `^4.0` | 4.2.0 | ObjectManager, ObjectRepository, MappingDriver. | doctrine/event-manager, psr/cache | — | — |
| 256 | `doctrine/event-manager` | `^2.0` | 2.1.1 | EventManager / EventArgs. | none | — | — |
| 257 | `doctrine/annotations` | `^2.0` | 2.0.2 | AnnotationReader / DocParser — still on many codebases despite attributes. | doctrine/lexer | — | 2.0.2. Prefer PHP attributes for new code. |
| 258 | `doctrine/migrations` | `^3.0` | 3.9.7 | AbstractMigration, MigrationPlan, DependencyFactory. | doctrine/dbal, psr/log (already-have) | — | — |
| 259 | `ramsey/uuid-doctrine` | `^2.0` | 2.1.0 | UuidType / UuidBinaryType for Doctrine. | ramsey/uuid, doctrine/dbal | — | — |
| 260 | `aws/aws-sdk-php` | `^3.0` | 3.394.1 | Aws\Sdk, S3Client, SqsClient, Result, Exception — huge public client surface. | guzzlehttp/guzzle, guzzlehttp/psr7, guzzlehttp/promises, mtdowling/jmespath.php, psr/http-message | required | 3.394.1. Generate core client/exception types first; service clients are large. |
| 261 | `mtdowling/jmespath.php` | `^2.0` | 2.9.2 | JmesPath Env/search used with AWS result shapes. | none | — | — |
| 262 | `async-aws/core` | `^1.0` | 1.29.2 | AwsClient / Result / Exception for AsyncAws. | symfony/http-client-contracts, psr/log (already-have) | — | — |
| 263 | `async-aws/s3` | `^3.0` | 3.4.1 | S3Client (AsyncAws) public operations. | async-aws/core | — | — |
| 264 | `google/auth` | `^1.0` | 1.53.0 | FetchAuthTokenInterface / credentials used by Google clients. | guzzlehttp/guzzle, guzzlehttp/psr7, psr/http-message, psr/cache | — | — |
| 265 | `google/apiclient` | `^2.0` | 2.19.4 | Google\Client / Service used by Drive/Gmail REST APIs. | google/auth, google/apiclient-services, guzzlehttp/guzzle, psr/http-message, psr/cache, firebase/php-jwt | — | — |
| 266 | `google/apiclient-services` | `^0.456` | 0.456.0 | Generated Google REST service classes (huge). | google/apiclient | — | Very large surface; consider generating only services you need. |
| 267 | `google/cloud-core` | `^1.0` | 1.73.2 | Google Cloud ServiceBuilder / Exception / Batch. | google/auth, guzzlehttp/guzzle, psr/cache, psr/log (already-have) | — | — |
| 268 | `google/cloud-storage` | `^2.0` | 2.5.2 | StorageClient / Bucket / StorageObject. | google/cloud-core | — | — |
| 269 | `mongodb/mongodb` | `^2.0` | 2.4.0 | Client, Database, Collection, BSON documents. | psr/log (already-have) | — | 2.4.0 (PHP ^8.1). Requires ext-mongodb at runtime. |
| 270 | `elasticsearch/elasticsearch` | `^9.0` | 9.5.0 | Client, ClientBuilder, ElasticsearchException (official 9.x). | psr/http-client, psr/http-message, psr/log (already-have) | — | v9.5.0. `elastic/transport` is a lower-level client; add later if apps type-hint it. |
| 271 | `php-amqplib/php-amqplib` | `^3.0` | 3.7.4 | AMQPStreamConnection, AMQPMessage, AMQPChannel. | none | required | v3.7.4. |
| 272 | `pda/pheanstalk` | `^8.0` | 8.0.2 | Pheanstalk client for Beanstalkd (Laravel queue driver). | none | — | v8.0.2; Laravel 13 allows ^7  or  ^8. |
| 273 | `stripe/stripe-php` | `^21.0` | 21.2.1 | StripeClient, Service, Exception, webhook types. | none | — | v21.2.1. Cashier public API returns some of these. |
| 274 | `sentry/sentry` | `^4.0` | 4.30.0 | Hub, ClientBuilder, Event, Severity. | guzzlehttp/guzzle, psr/log (already-have), psr/http-message | — | — |
| 275 | `pusher/pusher-php-server` | `^7.0` | 7.3.0 | Pusher\Pusher used by Laravel Echo server driver. | guzzlehttp/guzzle, psr/log (already-have) | — | — |
| 276 | `twilio/sdk` | `^8.0` | 8.12.0 | Twilio Rest Client / TwiML. | none | — | — |
| 277 | `firebase/php-jwt` | `^7.0` | 7.1.0 | JWT / JWK / Key / ExpiredException — the most common JWT library. | none | required | v7.1.0. |
| 278 | `lcobucci/jwt` | `^5.0` | 5.6.0 | Token, Parser, Validator, Signer — richer JWT object model. | psr/clock | — | 5.6.0 (PHP 8.2+). lcobucci/clock 3.6 is PHP 8.4+; JWT may allow older clock. |
| 279 | `lcobucci/clock` | `^3.0` | 3.6.0 | Clock implementations used with lcobucci/jwt. | psr/clock | — | 3.6.0 requires PHP 8.4+. |
| 280 | `bacon/bacon-qr-code` | `^3.0` | 3.1.1 | QR writer used by 2FA libraries. | dasprid/enum | — | — |
| 281 | `dasprid/enum` | `^1.0` | 1.0.7 | AbstractEnum used by bacon-qr-code. | none | — | — |
| 282 | `defuse/php-encryption` | `^2.0` | 2.4.0 | Crypto / Key used by Passport and encrypting at rest. | none | — | — |
| 283 | `paragonie/constant_time_encoding` | `^3.0` | 3.1.3 | Base64/Base32 constant-time encoders used by JWT/OTP stacks. | none | — | — |
| 284 | `phpseclib/phpseclib` | `^4.0` | 4.0.1 | RSA, AES, SSH2, X509 — phpseclib 3 public API. | paragonie/constant_time_encoding | — | — |
| 285 | `moneyphp/money` | `^4.0` | 4.9.0 | Money / Currency / MoneyParser — the Money pattern for PHP. | none | — | v4.9.0. |
| 286 | `giggsey/libphonenumber-for-php` | `^9.0` | 9.0.37 | PhoneNumber / PhoneNumberUtil. | giggsey/locale | — | — |
| 287 | `giggsey/locale` | `^2.0` | 2.9.0 | Locale mapping used by libphonenumber. | none | — | — |
| 288 | `hashids/hashids` | `^5.0` | 5.0.2 | Hashids encoder/decoder. | none | — | — |
| 289 | `respect/validation` | `^3.0` | 3.1.2 | Validator / Rules / Exceptions (v3). | none | — | 3.1.2 requires PHP >= 8.5. Use 2.x if you need PHP 8.3/8.4. |
| 290 | `phpoffice/phpspreadsheet` | `^5.0` | 5.9.0 | Spreadsheet, Worksheet, IOFactory, Cell. | maennchen/zipstream-php, composer/pcre, psr/simple-cache, psr/http-client, psr/http-factory | — | Markbaker math packages are internals — omitted. |
| 291 | `openspout/openspout` | `^5.0` | 5.11.0 | Reader/Writer for XLSX/CSV (used by Spatie Simple Excel). | none | — | — |
| 292 | `dompdf/dompdf` | `^3.0` | 3.1.6 | Dompdf / Options / Canvas. | masterminds/html5 | — | CSS parsing is internal. |
| 293 | `masterminds/html5` | `^2.0` | 2.11.0 | HTML5 parser (DOMDocument builder). | none | — | — |
| 294 | `ezyang/htmlpurifier` | `^4.0` | 4.19.0 | HTMLPurifier / Config / Context. | none | — | — |
| 295 | `maennchen/zipstream-php` | `^3.0` | 3.2.2 | ZipStream used when streaming XLSX downloads. | psr/http-message | — | — |
| 296 | `league/csv` | `^9.0` | 9.28.0 | Reader / Writer / Statement / TabularData. | none | — | — |
| 297 | `league/oauth2-client` | `^2.0` | 2.9.0 | AbstractProvider, AccessToken, ResourceOwnerInterface. | guzzlehttp/guzzle | — | — |
| 298 | `league/fractal` | `^0.21` | 0.21 | Manager, TransformerAbstract, Resource\Item/Collection. | none | — | — |
| 299 | `erusev/parsedown` | `^1.0` | 1.8.0 | Parsedown markdown parser. | none | — | — |

### 8. Long-tail but valuable

| Rank | Package | Constraint | Latest patch | Why | Public-API prerequisites | Marks | Notes |
| ---: | --- | --- | --- | --- | --- | --- | --- |
| 300 | `cakephp/chronos` | `^3.0` | 3.5.0 | Chronos / ChronosDate (Cake datetime, usable without the framework). | none | — | — |
| 301 | `spiral/roadrunner-worker` | `^3.0` | 3.6.2 | Worker / Payload (RoadRunner). | none | — | — |
| 302 | `spiral/roadrunner-http` | `^4.0` | 4.1.0 | PSR-7 worker for RoadRunner (Laravel Octane). | spiral/roadrunner-worker, psr/http-message, nyholm/psr7 | — | — |
| 303 | `api-platform/core` | `^4.0` | 4.3.17 | ApiResource / State processors / OpenAPI. | psr/http-message, doctrine/orm, symfony/http-foundation | — | v4.3.17. Large surface; Symfony integration is a separate package if you need it later. |
| 304 | `league/flysystem-aws-s3-v3` | `^3.0` | 3.35.3 | AwsS3V3Adapter constructed in service providers. | league/flysystem, aws/aws-sdk-php | — | — |

## Out of scope (intentionally omitted)

- **Polyfills** (`symfony/polyfill-*`, `ralouphie/getallheaders`, `paragonie/random_compat`) — no types apps name.
- **PHPUnit internals** (`sebastian/*`, `phpunit/php-file-iterator`, `phpunit/php-timer`, …) unless a test author would type-hint them (they usually would not).
- **Composer installers and metapackages** (`dealerdirect/phpcodesniffer-composer-installer`, `roave/security-advisories`).
- **WordPress** — not Composer-first; skipped even when a Packagist shim exists.
- **Full CMS/commerce apps** as libraries (Drupal, TYPO3, Magento, Shopware, Sylius) — enormous surfaces; revisit only with a dedicated product need.
- **Abandoned** packages (`zendframework/*`, `facebook/graph-sdk`, `tightenco/collect`, `beyondcode/laravel-websockets`, `container-interop/container-interop`).

## Packages looked up but not version-verified

These names were considered during research and either 404 on Packagist, have no stable tag, or are the wrong identifier. They are **not** in the count above.

| Name tried | Why it was dropped |
| --- | --- |
| `psr/http-message-util` | No such Packagist package |
| `laravel/precognition` | 404; Precognition ships with the framework / related first-party packages |
| `illuminate/concurrency` | 404 as a split package on Packagist |
| `elastic/elasticsearch-php` | Wrong name; official package is `elasticsearch/elasticsearch` (listed) |
| `thephpleague/oauth2-google` | Wrong name; use `league/oauth2-google` if added later |
| `reactphp/http` | Wrong name; official package is `react/http` |
| `clockwork/clockwork` | Wrong name; official package is `itsgoingd/clockwork` |
| `plates/plates` | Wrong name; official package is `league/plates` |
| `gitlab/gitlab` | 404; GitLab’s official PHP API package uses a different packagist name |
| `phpstan/phpstan-src` | Source repo, not an installable Packagist library |
| `roave/security-advisories` | Metapackage with no types |
| `magento/magento2-base`, `magento/framework` | Not publicly installable in the usual Packagist way |
| `yiisoft/yii-core` | 404; Yii3 is split under `yiisoft/*` |
| `honed/validate`, `dotaccess/dotaccess`, `handlebars/handlebars` | 404 or not a real Packagist library |
| `ddtrace/ddtrace`, `prometheus-community/prometheus` | 404 under those names |
| `composer-unused/composer-unused` | 404; `icanhazstring/composer-unused` exists but was left as tooling long-tail |
| `box-project/box` | 404 under that name (`humbug/box` is the usual identifier) |
| `postmark/postmark-php` | 404 under that name |
| `ext-redis` | PHP extension, not a Composer library (tyhpdef would be an ext package, not this list) |

Illuminate `illuminate/*` packages are **composer-replaced** by `laravel/framework` when the framework is installed. They remain separate rows because non-framework apps require them and because generation should start at `illuminate/contracts`.

