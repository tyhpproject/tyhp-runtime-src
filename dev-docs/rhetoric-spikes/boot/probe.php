<?php

declare(strict_types=1);

require '/Users/anthonyrainer/repos/tyhp-runtime-src/dev-docs/rhetoric-spikes/illuminate/vendor/autoload.php';

use Illuminate\Config\Repository;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Events\Dispatcher;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\BootProviders;
use Illuminate\Foundation\Exceptions\Handler;
use Illuminate\Foundation\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

$base = '/tmp/rhetoric-spike-boot';
if (!\is_dir($base)) {
    \mkdir($base, 0777, true);
}

function httpKernel(Application $app): HttpKernel
{
    $events = new Dispatcher($app);
    $router = new Router($events, $app);
    return new class($app, $router) extends HttpKernel {
        protected $bootstrappers = [
            BootProviders::class,
        ];
        protected $middleware = [];
    };
}

function bootApp(): Application
{
    $app = new Application('/tmp/rhetoric-spike-boot');
    $app->instance('config', new Repository([
        'app' => ['debug' => false, 'name' => 'rhetoric-spike', 'editor' => null],
        'view' => [
            'paths' => ['/tmp/rhetoric-spike-boot/views'],
            'compiled' => '/tmp/rhetoric-spike-boot/compiled',
        ],
        'logging' => [
            'default' => 'errorlog',
            'channels' => [
                'errorlog' => ['driver' => 'errorlog', 'level' => 'debug'],
            ],
        ],
    ]));
    $app->instance('files', new \Illuminate\Filesystem\Filesystem());
    $app->singleton(ExceptionHandler::class, fn ($app) => new Handler($app));
    $app->register(\Illuminate\View\ViewServiceProvider::class);
    $app->register(\Illuminate\Routing\RoutingServiceProvider::class);
    return $app;
}

$pathSeen = null;
$app = bootApp();
$app->booting(function () use ($app, &$pathSeen) {
    $pathSeen = $app->make('request')->path();
    if ($pathSeen === 'up') {
        return;
    }
    throw new ServiceUnavailableHttpException(15, 'rhetoric lock');
});

$kernel = httpKernel($app);
$up = Request::create('/up', 'GET');
$up->headers->set('Accept', 'application/json');
try {
    $response = $kernel->handle($up);
    echo 'except path status=' . $response->getStatusCode() . ' retry=' . \var_export($response->headers->get('Retry-After'), true) . ' pathSeen=' . \var_export($pathSeen, true) . "\n";
} catch (\Throwable $e) {
    echo 'except path threw ' . $e::class . ' ' . $e->getMessage() . ' pathSeen=' . \var_export($pathSeen, true) . "\n";
}

$pathSeen = null;
$app2 = bootApp();
$app2->booting(function () use ($app2, &$pathSeen) {
    $pathSeen = $app2->make('request')->path();
    throw new ServiceUnavailableHttpException(15, 'rhetoric lock');
});
$kernel2 = httpKernel($app2);
$locked = Request::create('/posts', 'GET');
$locked->headers->set('Accept', 'application/json');
echo 'locked expectsJson=' . ($locked->expectsJson() ? 'yes' : 'no') . "\n";
try {
    $response = $kernel2->handle($locked);
    echo 'locked status=' . $response->getStatusCode()
        . ' retry=' . \var_export($response->headers->get('Retry-After'), true)
        . ' pathSeen=' . \var_export($pathSeen, true) . "\n";
} catch (\Throwable $e) {
    echo 'locked threw ' . $e::class . ' ' . $e->getMessage() . ' pathSeen=' . \var_export($pathSeen, true) . "\n";
}

$_SERVER['argv'] = ['artisan', 'rhetoric:auto-migrate'];
$commandSeen = 'unset';
$app3 = bootApp();
$app3->booting(function () use ($app3, &$commandSeen) {
    $commandSeen = 'argv1=' . \var_export($_SERVER['argv'][1] ?? null, true)
        . ' artisan-bound=' . ($app3->bound('artisan') ? 'yes' : 'no')
        . ' console-kernel-bound=' . ($app3->bound(\Illuminate\Contracts\Console\Kernel::class) ? 'yes' : 'no');
    echo 'console during-boot ' . $commandSeen . "\n";
});

$console = new class($app3, new Dispatcher($app3)) extends \Illuminate\Foundation\Console\Kernel {
    protected function bootstrappers()
    {
        return [BootProviders::class];
    }

    protected function commands()
    {
    }
};

$input = new ArrayInput(['command' => 'rhetoric:auto-migrate']);
$output = new BufferedOutput();
try {
    $code = $console->handle($input, $output);
    echo 'console exit=' . $code . "\n";
    echo 'console output=' . \trim($output->fetch()) . "\n";
} catch (\Throwable $e) {
    echo 'console after boot ' . $e::class . ' ' . $e->getMessage() . "\n";
}
