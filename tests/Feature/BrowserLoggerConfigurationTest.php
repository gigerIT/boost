<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

beforeEach(function (): void {
    $this->browserAppPath = __DIR__.'/../../workbench/browser-config-'.bin2hex(random_bytes(8));

    foreach (['bootstrap/cache', 'config', 'storage/logs'] as $directory) {
        mkdir($this->browserAppPath.'/'.$directory, 0755, true);
    }

    file_put_contents($this->browserAppPath.'/bootstrap/app.php', <<<'PHP'
<?php

return Illuminate\Foundation\Application::configure(basePath: dirname(__DIR__))
    ->withProviders([
        Laravel\Mcp\Server\McpServiceProvider::class,
        Laravel\Boost\BoostServiceProvider::class,
    ])
    ->create();
PHP);

    $this->runBrowserApp = function (string $operation): array {
        $process = new Process([PHP_BINARY, '-r', <<<'PHP'
require $argv[1];
$app = require $argv[2].'/bootstrap/app.php';

if ($argv[3] === 'read') {
    // A consuming application may enable Boost after loading cached configuration.
    $app->afterBootstrapping(Illuminate\Foundation\Bootstrap\LoadConfiguration::class,
        fn ($app) => $app->detectEnvironment(fn () => 'local'));
}

$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

if ($argv[3] === 'cache') {
    exit($kernel->call('config:cache'));
}

$logger = Illuminate\Support\Facades\Log::channel('browser')->getLogger();
echo json_encode([
    'level' => config('logging.channels.browser.level'),
    'handles_info' => $logger->isHandling(Monolog\Level::Info),
    'handles_warning' => $logger->isHandling(Monolog\Level::Warning),
    'cached' => $app->configurationIsCached(),
    'env_level' => env('LOG_LEVEL'),
]);
PHP, __DIR__.'/../../vendor/autoload.php', $this->browserAppPath, $operation], null, [
            'APP_ENV' => false,
            'APP_DEBUG' => false,
            'APP_CONFIG_CACHE' => false,
            'LOG_LEVEL' => false,
            'BOOST_ENABLED' => false,
            'BOOST_BROWSER_LOGS_WATCHER' => false,
        ]);

        $process->mustRun();

        return $operation === 'cache' ? [] : json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    };
});

afterEach(function (): void {
    (new Filesystem)->deleteDirectory($this->browserAppPath);
});

it('retains the dotenv log threshold when caching with Boost active or inactive', function (string $environment): void {
    file_put_contents($this->browserAppPath.'/.env', "APP_ENV={$environment}\nAPP_DEBUG=false\nLOG_LEVEL=warning\n");

    $uncached = ($this->runBrowserApp)('read');

    expect($uncached)->toMatchArray([
        'level' => 'warning',
        'handles_info' => false,
        'handles_warning' => true,
        'cached' => false,
        'env_level' => 'warning',
    ]);

    ($this->runBrowserApp)('cache');

    expect(($this->runBrowserApp)('read'))->toMatchArray([
        'level' => 'warning',
        'handles_info' => false,
        'handles_warning' => true,
        'cached' => true,
        'env_level' => null,
    ]);
})->with(['local', 'production']);

it('defaults to debug without LOG_LEVEL before and after caching', function (): void {
    file_put_contents($this->browserAppPath.'/.env', "APP_ENV=production\nAPP_DEBUG=false\n");

    expect(($this->runBrowserApp)('read')['level'])->toBe('debug');

    ($this->runBrowserApp)('cache');

    expect(($this->runBrowserApp)('read')['level'])->toBe('debug');
});

it('honors a published threshold before and after caching', function (): void {
    file_put_contents($this->browserAppPath.'/.env', "APP_ENV=production\nAPP_DEBUG=false\nLOG_LEVEL=debug\n");
    file_put_contents($this->browserAppPath.'/config/boost.php', "<?php return ['browser_log_level' => 'warning'];");

    expect(($this->runBrowserApp)('read')['level'])->toBe('warning');

    ($this->runBrowserApp)('cache');

    expect(($this->runBrowserApp)('read')['level'])->toBe('warning');
});

it('reads the current dotenv threshold when rebuilding the cache', function (): void {
    file_put_contents($this->browserAppPath.'/.env', "APP_ENV=production\nAPP_DEBUG=false\nLOG_LEVEL=warning\n");
    ($this->runBrowserApp)('cache');

    file_put_contents($this->browserAppPath.'/.env', "APP_ENV=production\nAPP_DEBUG=false\nLOG_LEVEL=error\n");
    expect(($this->runBrowserApp)('read')['level'])->toBe('warning');

    ($this->runBrowserApp)('cache');

    expect(($this->runBrowserApp)('read')['level'])->toBe('error');
});
