<?php

declare(strict_types=1);

namespace Netlogix\JobQueue\Pool\Aspect;

use Flowpack\JobQueue\Common\Command\JobCommandController;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Aop\JoinPointInterface;
use Neos\Flow\Cli\Exception\StopCommandException;
use Neos\Flow\Cli\Request;
use Neos\Flow\Mvc\Controller;
use Neos\Flow\Reflection\ClassReflection;
use Symfony\Component\Console\Exception\MissingInputException;

use function fgets;
use function rtrim;
use function stream_set_blocking;

/**
 * The pool keeps preforked worker processes warm by starting
 * "flowpack.jobqueue.common:job:execute" WITHOUT arguments. The worker then
 * blocks until the parent process hands over the queue name and the message
 * cache identifier line by line via STDIN.
 * @see Pool::passQueueNameToWorker
 * @see Pool::passPayloadToWorker
 *
 * This aspect lives in the pool on purpose: the pool is what introduces and owns
 * this prefork-and-hand-off-via-stdin mechanism, so every consumer (FastRabbit,
 * FakeQueue, Scheduled, …) benefits from the fix without changes of their own.
 *
 * If we let Flow fetch the missing required arguments through its interactive
 * prompt, Symfony Console reads STDIN byte by byte and calls
 * TerminalInputHelper::waitForInput() before every byte, which busy-polls STDIN
 * with a 100µs stream_select() loop. With many idle workers that busy-poll
 * saturates the CPU.
 *
 * So we read the missing required arguments here with a plain BLOCKING fgets()
 * (a real blocking read sleeps at 0% CPU until the parent writes) and inject them
 * into the request. The original mapRequestArgumentsToControllerArguments() then
 * finds every argument present and never reaches the interactive prompt.
 *
 * When the parent process restarts (e.g. every 6 hours due to a loop's max wait
 * time) its end of the STDIN pipe closes, so fgets() returns false. That orphaned
 * worker is expected and stops cleanly via StopCommandException.
 */
#[Flow\Aspect]
#[Flow\Proxy(false)]
class BlockingJobArgumentInputAspect
{
    #[Flow\Around('within(' . JobCommandController::class . ') && method(.*->mapRequestArgumentsToControllerArguments())')]
    public function readJobHandoffFromStdinBlocking(JoinPointInterface $joinPoint): void
    {
        $jobCommandController = $joinPoint->getProxy();
        assert($jobCommandController instanceof JobCommandController);

        $reflection = new ClassReflection($jobCommandController);

        $commandMethodName = $reflection
            ->getProperty('commandMethodName')
            ->getValue($jobCommandController);

        if ($commandMethodName !== 'executeCommand') {
            $joinPoint->getAdviceChain()->proceed($joinPoint);
            return;
        }

        $request = $reflection
            ->getProperty('request')
            ->getValue($jobCommandController);
        assert($request instanceof Request);

        $arguments = $reflection
            ->getProperty('arguments')
            ->getValue($jobCommandController);
        assert($arguments instanceof Controller\Arguments);

        // Read blocking instead of Symfony's interactive prompt (busy-poll).
        stream_set_blocking(\STDIN, true);
        foreach ($arguments as $argument) {
            assert($argument instanceof Controller\Argument);
            if (!$argument->isRequired() || $request->hasArgument($argument->getName())) {
                continue;
            }

            $line = fgets(\STDIN);
            if ($line === false) {
                // STDIN closed (orphaned worker after parent restart) -> stop cleanly.
                throw new StopCommandException();
            }

            $request->setArgument($argument->getName(), rtrim($line, "\r\n"));
        }

        try {
            // Now finds every argument in the request -> no ask() -> no busy-poll.
            $joinPoint->getAdviceChain()->proceed($joinPoint);
        } catch (MissingInputException $e) {
            throw new StopCommandException();
        }
    }
}
