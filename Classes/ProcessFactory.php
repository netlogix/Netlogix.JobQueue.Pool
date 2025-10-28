<?php

declare(strict_types=1);

namespace Netlogix\JobQueue\Pool;

use Neos\Flow\Annotations as Flow;
use Neos\Flow\Configuration\ConfigurationManager;
use Neos\Flow\Core\Booting\Scripts;
use Neos\Flow\Reflection\MethodReflection;
use React\ChildProcess\Process;

final class ProcessFactory
{
    #[Flow\Inject]
    protected ConfigurationManager $configurationManager;

    public function __construct(
        private ?string $command = null
    ) {
    }

    public function build(bool $withOutputStreams): Process
    {
        $process = new Process(
            cmd: $this->buildSubprocessCommand(),
            fds: [
                ['pipe', 'r'],
                $withOutputStreams ? ['pipe', 'w'] : ['file', '/dev/null', 'w'],
                $withOutputStreams ? ['pipe', 'w'] : ['file', '/dev/null', 'w'],
            ]
        );
        return $process;
    }

    public function buildSubprocessCommand(): string
    {
        $this->command = $this->command
            ?? (new MethodReflection(Scripts::class, 'buildSubprocessCommand'))
                ->invokeArgs(
                    object: null,
                    args: [
                        'commandIdentifier' => 'flowpack.jobqueue.common:job:execute',
                        'settings' => $this->configurationManager->getConfiguration(
                            ConfigurationManager::CONFIGURATION_TYPE_SETTINGS,
                            'Neos.Flow'
                        ),
                        'commandArguments' => [],
                    ]
                );
        return $this->command;
    }
}
