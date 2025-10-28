# Netlogix.JobQueue.Pool

This package runs jobs (`immplements \Flowpack\JobQueue\Common\Job\JobInterface`)
in `\React\ChildProcess\Process` and provides a `\React\EventLoop\LoopInterface`
to do some work in the parent process while waiting for the children to complete.
