# tyhp/async type surface

`package.tyhpdef` is the public type surface of this package. It is generated from `tyhp_src/` when the package is built (see `../base-build-all.sh`); do not edit it by hand. Members marked `@internal` in `tyhp_src/` are left out of it. The compiler loads it through `extra.tyhp.package` in `composer.json`.

The runtime implementation is `src/*.php`, emitted from `tyhp_src/*.tyhp`.

Check that the definition parses and binds:

```bash
dotnet /path/to/tyhp.dll lint package.tyhpdef
```

## Promise&lt;TReturn extends __Awaited&lt;void|mixed&gt; = __Awaited&lt;mixed&gt;&gt;

`Promise` without type arguments is `Promise<mixed>`. `Promise<void>` is the type of async code that returns nothing.

### Settled types

A promise holds a settled value, never another promise. The bound `TReturn extends __Awaited<void|mixed>` requires the type argument to be its own awaited type (`__Awaited<T>` is `T` with every promise layer removed):

- Rejected with TYHP4373: `Promise<Promise<int>>`, `Promise<int|Promise<string>>`, and `Promise<T>` for a type parameter `T` that could itself be a promise (unconstrained, or bounded by `mixed`).
- Accepted: `Promise<mixed>`, `Promise<object>`, `Promise<?int>`, `Promise<T>` for `T extends int|string`, and `Promise<T>` for `T extends __Awaited<void|mixed>`.

Generic helpers that take or return a promise declare their parameter the same way: `function f<T extends __Awaited<void|mixed>>(Promise<T> $p)`. A type parameter that is only `T extends void|mixed` is used as `__Awaited<T>`, which stays deferred until `T` is known; this is why `Promise::run`, `race`, `any`, `whenAny`, and `timeout` are typed through `__Awaited<T>`.

The declared return type `R` of an `async` callable is read as `__Awaited<R>`: `async function f(): int` gives callers a `Promise<int>`, and `await f()` is an `int`. A callable that returns a promise instead (`callable(): Promise<int>`) is awaited by `Promise::run`, so `T` is `int` there as well.

### Language hooks

| Member | Signature |
|--------|-----------|
| constructor | `__construct(callable(): TReturn|Promise<TReturn> $executor, bool $immediateResolve = false)` |
| `_async` | `static _async<T extends void|mixed>(callable(): T $fn): self<__Awaited<T>>`: what `async` compiles to |
| `_await` | `static _await<T extends __Awaited<void|mixed>>(Promise<T> $promise): __Awaited<T>`: what `await` compiles to |

### Factories

| Member | Signature |
|--------|-----------|
| `resolve` | `static resolve<T extends __Awaited<void|mixed>>(?T $value = null): self<T>` (a promise argument is returned unchanged) |
| `resolved` | `static resolved<T extends void|mixed = void>(?T $value = null): self<__Awaited<T>>` (always a new promise; a promise argument is adopted, so `resolved<Promise<int>>($p)` is a `Promise<int>` that settles when `$p` does) |
| `reject` | `static reject<T extends __Awaited<void|mixed> = void>(\Throwable $reason): self<T>` (alias of `rejected`) |
| `rejected` | `static rejected<T extends void|mixed = void>(\Throwable $error): self<__Awaited<T>>` |
| `withResolvers` | `static withResolvers<T extends void|mixed>(): Deferred<__Awaited<T>>` (the way to create a promise settled from outside, including from plain PHP) |
| `yield` | `static yield(): self<void>` |
| `yieldTick` | `static yieldTick(): self<void>` |

Pass the type argument explicitly to `reject`, `rejected`, and `withResolvers` in Tyhp code: it cannot be inferred from their arguments.

### Timing, combinators, and entry points

| Member | Signature |
|--------|-----------|
| `delay` | `async static delay(int $ms, ?CancellationToken $token = null): void` |
| `all` | `async static all<T extends __Awaited<void|mixed> = mixed>(array<Promise<T>> $promises, ?CancellationToken $token = null): array<T>` (keys preserved) |
| `whenAll` | `static whenAll<T extends __Awaited<void|mixed> = mixed>(Promise<T> ...$promises): self<array<int|string, T>>` |
| `allSettled` | `async static allSettled<T extends __Awaited<void|mixed> = mixed, TKey extends int|string = int|string>(array<TKey, Promise<T>> $promises): array<TKey, PromiseSettledResult<T>>` |
| `race` | `async static race<T extends __Awaited<void|mixed>>(array<Promise<T>> $promises, ?CancellationToken $token = null): __Awaited<T>` |
| `whenAny` | `static whenAny<T extends __Awaited<void|mixed>>(Promise<T> ...$promises): self<__Awaited<T>>` |
| `any` | `async static any<T extends __Awaited<void|mixed>>(array<Promise<T>> $promises, ?CancellationToken $token = null): __Awaited<T>` |
| `timeout` | `static timeout<T extends __Awaited<void|mixed>>(Promise<T> $promise, int $ms, ?CancellationToken $token = null): self<__Awaited<T>>` |
| `batch` | `async static batch<TItem, TBatchReturn extends __Awaited<void|mixed>>(array<TItem> $items, callable(TItem): Promise<TBatchReturn> $processor, int $concurrency = 5, ?CancellationToken $token = null): array<int, TBatchReturn>` |
| `fromGenerator` | `async static fromGenerator(\Generator $generator): mixed` |
| `run` | `static run<T extends void|mixed>(callable(): T $fn, ?CancellationToken $token = null): __Awaited<T>` (blocking entry point from synchronous code; returns the settled value, awaits a promise that `$fn` returns, rethrows a rejection, and throws `\LogicException` when the loop is already running) |

`run` with a `$token`: an already-cancelled token throws `OperationCancelledException` before `$fn` is called. If the token is cancelled while the work is still pending, the whole event loop is shut down (see [EventLoop](#eventloop)) and `run` throws `OperationCancelledException`; work that has already settled keeps its own result or error.

### Instance members

| Member | Signature |
|--------|-----------|
| `then` (no handlers) | `async then(null $onFulfilled = null, null $onRejected = null): TReturn` |
| `then` (rejection handler only) | `async then(null $onFulfilled, callable(\Throwable): TReturn $onRejected): TReturn` |
| `then` (fulfillment handler) | `async then<TThenReturn>(callable(TReturn): TThenReturn $onFulfilled, ?callable(\Throwable): TThenReturn $onRejected = null): TThenReturn` |
| `catch` | `catch(callable(\Throwable): TReturn $onRejected): self<TReturn>` |
| `finally` | `async finally(callable(): void|mixed $onFinally): TReturn` |
| `continueWith` | `async continueWith<TContinueReturn>(callable(?TReturn, ?\Throwable): TContinueReturn $continuation): TContinueReturn` |
| `wait` | `wait(int $timeoutMs = -1): mixed` |
| state | `getResult(): TReturn`, `getState(): PromiseState`, `isCompleted()`, `isFulfilled()`, `isFaulted()`, `getError(): ?\Throwable` |

`then` is an overload set; the three signatures above are the ones in `package.tyhpdef`.

Methods declared `async` return a `Promise` of their declared (settled) return type.

## Supporting types

| Type | Purpose |
|------|---------|
| `PromiseState` | Backed enum: `Pending`, `Fulfilled`, `Rejected` |
| `PromiseSettledResult<T>` | One outcome from `allSettled`: `status`, and `value` or `reason` |
| `Deferred<T extends __Awaited<void|mixed> = void>` | `getPromise()`, `resolve(?T $value = null)`, `reject(\Throwable $reason)`; settles once, a second `resolve` or `reject` throws `InvalidPromiseStateException` |
| `CancellationTokenSource` | `__construct(?int $cancelAfterMs = null)`, `getToken()`, `cancel()`, `cancelAfter(int $ms)`, `isCancellationRequested()`, `dispose()` |
| `CancellationToken` | `isCancellationRequested()`, `throwIfCancellationRequested()`, `register(callable(): void)`, `static none()` |
| `DisposableScope` | `static create()`, `using(...)`, `dispose()`, `release(...)`, `__destruct()` |
| `DisposableHelper` | `async static dispose(IsDisposable|AsyncIsDisposable ...$disposables): void` |

`EventLoopTimerItem` and `EventLoopFiberItem` are the typed queue entries used by `EventLoop`.

## EventLoop

- `getInstance()`, `resetInstance()` (discard the shared loop, for forked children and tests)
- Fibers: `scheduleFiber`, `scheduleFiberResume`, `scheduleFiberThrow`
- Timers: `delay`, `interval`, `cancelTimer`
- Streams: `addReadStream`, `addWriteStream`, `removeReadStream`, `removeWriteStream`
- Microtasks: `defer`, `queueMicrotask`
- Loop control: `run<T extends __Awaited<void|mixed> = mixed>(Promise<T> $rootPromise, ?CancellationToken $token = null): T`, `runUntilSettled(Promise $promise, int $timeoutMs = -1)`, `tick()`, `stop()`, `isRunning()`

### Cancelling `run`

With a token, `run` checks it once per loop iteration, after the microtask, timer, and I/O phases. If the token is cancelled while the root promise is still pending, the loop is shut down and `run` throws `OperationCancelledException`. If the root promise settled in the same iteration, its own result or error is returned or thrown and nothing is torn down.

The shutdown stops all of the loop's work, not only the root's:

- Queued microtasks and queued fiber starts are dropped; the promise of a fiber that never started is rejected with `OperationCancelledException`.
- Every timer and interval is cancelled and every stream watcher is removed (the streams are not closed).
- Every fiber suspended on a pending promise gets `OperationCancelledException` thrown into it, so its `finally` blocks run, and its promise is rejected with that exception. A fiber that catches the exception and suspends again is rejected and abandoned. No fiber the loop gave up on is started or resumed again, even if the promise it awaited settles later.
- A promise from a `Deferred` that nothing has settled is rejected with `OperationCancelledException` as well, because its executor is a suspended fiber. Settling the `Deferred` afterwards does not change that promise.
- An error thrown by cleanup code is attached as the `previous` exception of the one `run` throws.

The loop is reusable afterwards. A single callback that never returns control to the loop is not interrupted.

## Contracts (`Tyhp\Contracts`)

| Interface | Members |
|-----------|---------|
| `AsyncIterable<T extends __Awaited<void|mixed>>` | `getAsyncIterator(): AsyncIterator<T>` |
| `AsyncIterator<T extends __Awaited<void|mixed>>` | `next(): Promise<bool>`, `current(): Promise<T>` |
| `AsyncKeyValueIterator<TKey extends __Awaited<void|mixed>, TValue extends __Awaited<void|mixed>>` | extends `AsyncIterator<TValue>`; adds `currentKey(): Promise<TKey>`, `currentValue(): Promise<TValue>` |
| `AsyncIsDisposable` | `disposeAsync(): Promise<void>` |

`foreach (await $source as $item)` compiles to a loop that awaits `next()` and `current()` on the iterator from `getAsyncIterator()`. The key-value form awaits `currentKey()` and `currentValue()`; it needs `getAsyncIterator()` declared as returning `AsyncKeyValueIterator<TKey, TValue>` so the key type is known.

## Exceptions (`Tyhp\Exceptions`)

`TimeoutException` (extends `\RuntimeException`, `getTimeoutMs()`), `OperationCancelledException` (extends `\RuntimeException`, `getToken()`), `InvalidPromiseStateException` (extends `\LogicException`), `AsyncContextException` (extends `\RuntimeException`).

`AggregateException` lives in `tyhp/core` (`Tyhp\Exceptions`); the async runtime imports it from there.

## Async generators

Tyhp has no async generators. Use a `Generator` that yields promises and adapt it with `Promise::fromGenerator()`.

## Generic factory methods

`_async`, `resolve`, `reject`, and friends return `self<…>` both in `tyhp_src/Promise.tyhp` and in the generated tyhpdef. Parameterized `static<...>` is not allowed in a tyhpdef; a bare `static` is valid for a late-bound, non-parameterized return.

## Callable shapes

Every `callable(...): R` parameter in this package is a callable shape: a parameter list followed by a return type. A `void` or `never` return type parameter needs an explicit constraint opt-in.
