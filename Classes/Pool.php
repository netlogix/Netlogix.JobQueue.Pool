<?php

declare(strict_types=1);

namespace Netlogix\JobQueue\Pool;

use Countable;
use Flowpack\JobQueue\Common\Job\JobInterface;
use Flowpack\JobQueue\Common\Queue\Message;
use Neos\Cache\Frontend\VariableFrontend;
use Neos\Flow\Configuration\ConfigurationManager;
use Neos\Flow\Utility\Algorithms;
use React\ChildProcess\Process;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;

use function file_put_contents;
use function fputs;
use function is_callable;
use function max;
use function rewind;
use function serialize;
use function sha1;

class Pool implements Countable
{
    public const string EVENT_ERROR = 'error';
    public const string EVENT_SUCCESS = 'success';
    public const string EVENT_EXIT = 'exit';

    protected ConfigurationManager $configurationManager;

    protected VariableFrontend $messageCache;

    public function injectConfigurationManager(ConfigurationManager $configurationManager): void
    {
        $this->configurationManager = $configurationManager;
    }

    public function injectMessageCache(VariableFrontend $messageCache): void
    {
        $this->messageCache = $messageCache;
    }

    /**
     * Preforked worker processes waiting to receive queue name and job payload
     *
     * @var Process[]
     */
    private array $preforked = [];

    /**
     * Processes currently working on jobs
     *
     * @var Process[]
     */
    private array $running = [];

    public function __construct(
        public readonly ?string $queueName,
        public readonly bool $outputResults,
        public readonly bool $async,
        public readonly int $preforkSize,
        public readonly ?string $command,
        public readonly LoopInterface $eventLoop
    ) {
        $this->fillPool(size: $this->preforkSize);
    }

    public static function create(
        ?string $queueName = null,
        bool $outputResults = false,
        bool $async = false,
        int $preforkSize = 0,
        ?string $command = null,
        ?LoopInterface $eventLoop = null
    ): self {
        return new static(
            $queueName,
            $outputResults,
            $async,
            max($preforkSize, 0),
            $command,
            $eventLoop ?? Loop::get()
        );
    }

    public function shutdownObject()
    {
        foreach ([... $this->preforked, ... $this->running] as $process) {
            assert($process instanceof Process);
            $process->terminate();
            $process->stdin?->close();
        }
    }

    public function runJob(JobInterface $job, ?string $queueName = null): Process
    {
        return $this->runPayload(serialize($job), $queueName);
    }

    public function runPayload(string $payload, ?string $queueName = null): Process
    {
        $this->fillPool(size: $this->preforkSize + 1);
        $process = array_shift($this->preforked);
        assert($process instanceof Process);

        $this->connectOutputResults(process: $process);
        $this->setupResultEvents(process: $process);

        $this->passQueueNameToWorker(process: $process, queueName: $queueName);
        $this->passPayloadToWorker(process: $process, payload: $payload);

        $this->markRunning(process: $process);

        return $process;
    }

    public function futureTick(callable $callback): static
    {
        $this->eventLoop->futureTick(fn () => $callback($this));
        return $this;
    }

    public function runLoop(?callable $callable = null): mixed
    {
        $result = null;
        if (is_callable($callable)) {
            $this->futureTick(function (Pool $pool) use ($callable, &$result): void {
                $result = $callable($pool);
            });
        }
        $this->eventLoop->run();
        return $result;
    }

    public function count(): int
    {
        return count($this->running);
    }

    private function connectOutputResults(Process $process): void
    {
        if (!$this->outputResults) {
            return;
        }
        $fputs = function ($stream, $data) {
            // FIXME: Find a better way to filter out the first two lines of stdout
            switch ($data) {
                case 'Please specify the required argument "queue": ':
                case 'Please specify the required argument "messageCacheIdentifier": ':
                    return null;
                default:
                    file_put_contents($stream, $data);
            }
        };
        // "php://output" allows for PHP output buffering, \STDOUT does not. Mandatory for testing.
        $process->stdout->on('data', fn ($chunk) => $fputs('php://output', $chunk));
        $process->stderr->on('data', fn ($chunk) => $fputs('php://stderr', $chunk));
    }

    private function setupResultEvents(Process $process)
    {
        $errorMessage = fopen('php://temp', 'r+');
        if (!$this->async) {
            $process->stdout->on('data', fn ($chunk) => fputs($errorMessage, $chunk));
        }
        $process->on(self::EVENT_EXIT, function (int $exitCode) use ($process, &$errorMessage) {
            if ($exitCode === 0) {
                $process->emit(self::EVENT_SUCCESS);
            } else {
                rewind($errorMessage);
                $process->emit(self::EVENT_ERROR, [$exitCode, $errorMessage]);
            }
        });
    }

    private function markRunning(Process $process): void
    {
        $filter = fn (Process $runningProcess) => $runningProcess !== $process;
        $this->running[] = $process;
        $process->on(self::EVENT_EXIT, function () use ($filter) {
            $this->running = array_filter($this->running, $filter);
        });
    }

    private function passQueueNameToWorker(Process $process, ?string $queueName): void
    {
        if ($this->queueName === null && $queueName === null) {
            throw new \InvalidArgumentException('No queue name provided', 1761228530);
        }
        if ($this->queueName !== null && $queueName !== null && $this->queueName !== $queueName) {
            throw new \InvalidArgumentException(
                'Cannot run job for queue ' . $queueName . ' in queue ' . $this->queueName, 1761228561
            );
        }

        $queueName = ($this->queueName ?? $queueName);
        $process->stdin->write($queueName . PHP_EOL);
    }

    private function passPayloadToWorker(Process $process, string $payload): void
    {
        $messageId = Algorithms::generateUUID();
        $message = new Message($messageId, $payload);

        $messageCacheIdentifier = sha1(serialize($message));

        $this->messageCache->set($messageCacheIdentifier, $message);
        $process->on('exit', fn () => $this->messageCache->remove($messageCacheIdentifier));

        $this->eventLoop->futureTick(function () use ($process, $messageCacheIdentifier) {
            $process->emit('messageCacheIdentifier', [$messageCacheIdentifier]);
        });

        $process->stdin->write($messageCacheIdentifier . PHP_EOL);
    }

    private function fillPool(int $size): void
    {
        $size = max($size, 0);
        $this->preforked = array_filter(
            $this->preforked,
            fn (Process $process) => $process->isRunning()
        );
        while (count($this->preforked) < $size) {
            $this->preforked[] = $this->createProcess();
        }
    }

    private function createProcess(): Process
    {
        $process = (new ProcessFactory($this->command))->build(
            withOutputStreams: $this->outputResults || !$this->async
        );
        $process->start(loop: $this->eventLoop, interval: 0.01);
        return $process;
    }
}
