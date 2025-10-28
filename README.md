# Netlogix.JobQueue.Pool

This package runs jobs (`immplements \Flowpack\JobQueue\Common\Job\JobInterface`)
in `\React\ChildProcess\Process` and provides a `\React\EventLoop\LoopInterface`
to do some work in the parent process while waiting for the children to complete.

```php
<?php
use Netlogix\JobQueue\Pool\Pool;

Pool::create(
    outputResults: true,
    async: false,
    preforkSize: 1
)
    ->runLoop(function(Pool $pool) {

        $process = $pool->runJob(new VeryLongRunningJob());

        $process->on('success', function(int $exitCode) {
            echo 'success' . PHP_EOL;
        });

        $process->on('error', function(int $exitCode, $errorMessageStream) {
            echo 'failed: ' . $exitCode . PHP_EOL;
            echo stream_get_contents($errorMessageStream);
        });

        $process->on('exit', function() use ($pool) {
            if (count($pool) === 0) {
                $pool->eventLoop->stop();
            }
        });

        $pool->eventLoop->addTimer(5, function() use ($process) {
            $process->stdin->write('hello!' . PHP_EOL);
        });

        $pool->eventLoop->addPeriodicTimer(function() {
            // ping parent database connections while children are working
        });
    });

```

## Arguments:

### Output results:

```php
<?php
use Netlogix\JobQueue\Pool\Pool;

Pool::create(outputResults: true)
    ->runLoop(function(Pool $pool) {
        $process = $pool->runJob($job);
        $process->on('exit', fn() => $pool->eventLoop->stop());
    });
```

Links stdout of the child process to stdout of the parent process and
stderr of the child process to stderr of the parent process.

This is close to what `\Neos\Flow\Core\Booting\Scripts::executeCommand()`
does, with our implementation actually differentiate between stdout and
stderr while Neos\Flow passes everything to stdout.

This can be done manually to do some processing of the given streams:

```php
<?php
use Netlogix\JobQueue\Pool\Pool;

Pool::create(outputResults: false)
    ->runLoop(function(Pool $pool) {
        $process = $pool->runJob($job);
        $process->stdout->on('data', fn($chunk) => fputs(\STDOUT, $chunk));
        $process->stderr->on('data', fn($chunk) => fputs(\STDOUT, $chunk));
        $process->on('exit', fn() => $pool->eventLoop->stop());
    });
```

### Running all child processes asynchronously:

```php
<?php
use Netlogix\JobQueue\Pool\Pool;

Pool::create(async: true)
    ->runLoop(function(Pool $pool) {
        $pool->runJob($job);
    });
```

It is possible to run child processes asynchronously, although this means
there is no stdout and stderr connection from the child to the parent.

### Start worker children although no task is available

```php
<?php
use Netlogix\JobQueue\Pool\Pool;

Pool::create(preforkSize: 3)
    ->runLoop();
```

Creates a set number of worker children in advanced without assigning a
queue name or a job payload to them.

This will allow for the children to boot up and e.g. warm singleton instances
up.

We don't provide any useful warmup code here, so add e.g. advices yourself.

### Custom loop object

```php
<?php
use Netlogix\JobQueue\Pool\Pool;

$loop = \React\EventLoop\Loop::get();

Pool::create(eventLoop: $loop)
    ->runLoop();
```

Just in case there other code already created a running event loop, which
is possible in theory but not very likely.
