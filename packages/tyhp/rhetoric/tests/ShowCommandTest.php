<?php

declare(strict_types=1);

namespace Tyhp\Tests\Rhetoric;

use Illuminate\Console\Application;
use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tyhp\Rhetoric\Fixtures\ConventionBlogContext;
use Tyhp\Rhetoric\Laravel\Commands\ShowCommand;
use Tyhp\Rhetoric\Laravel\MappingPrinter;
use Tyhp\Rhetoric\Mapping\MapCache;

final class ShowCommandTest extends TestCase
{
    protected function setUp(): void
    {
        MapCache::clear();
    }

    public function testShowCommandPrintsTheMapping(): void
    {
        $command = new ShowCommand();
        self::assertSame('rhetoric:show', $command->getName());

        $rendered = MappingPrinter::render((new ConventionBlogContext())->mapping());
        self::assertStringContainsString('blogs', $rendered);
        self::assertStringContainsString('blog_id', $rendered);
        self::assertStringContainsString('hasMany', $rendered);
        self::assertStringContainsString('belongsTo', $rendered);

        $config = require \dirname(__DIR__) . '/config/rhetoric.php';
        self::assertIsArray($config);
        self::assertArrayHasKey('contexts', $config);
        self::assertSame([], $config['contexts']);
    }

    public function testShowCommandRunsAgainstAContext(): void
    {
        [$code, $text] = $this->execute(ConventionBlogContext::class);

        self::assertSame(0, $code);
        self::assertSame(
            MappingPrinter::render((new ConventionBlogContext())->mapping()) . "\n",
            $text,
        );
    }

    public function testShowCommandAcceptsALeadingBackslash(): void
    {
        [$code, $text] = $this->execute('\\' . ConventionBlogContext::class);

        self::assertSame(0, $code);
        self::assertStringContainsString('Table: blogs', $text);
    }

    public function testShowCommandRejectsAClassThatIsNotAContext(): void
    {
        [$code, $text] = $this->execute(\stdClass::class);

        self::assertSame(1, $code);
        self::assertStringContainsString('is not a context', $text);

        [$missingCode] = $this->execute('Missing\\NotAContext');
        self::assertSame(1, $missingCode);
    }

    /**
     * @return array{int, string}
     */
    private function execute(string $context): array
    {
        $container = new class extends Container {
            public function runningUnitTests(): bool
            {
                return true;
            }
        };
        $application = new Application($container, new Dispatcher($container), '1');
        $application->setAutoExit(false);
        $application->setCatchExceptions(false);
        $application->add(new ShowCommand());

        $output = new BufferedOutput();
        $code = $application->run(new ArrayInput(['command' => 'rhetoric:show', 'context' => $context]), $output);

        return [$code, $output->fetch()];
    }
}
