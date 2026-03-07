<?php

namespace App\Socket\Callbacks;

use Illuminate\Console\OutputStyle;
use Symfony\Component\Console\Formatter\OutputFormatterStyle;
use Sentry\SentrySdk;
use Sentry\Tracing\SpanStatus;
use Sentry\Tracing\TransactionContext;

abstract class AbstractSocketCallback
{
    protected static int $sentryFlushCounter = 0;
    protected static float $sentryLastFlushAt = 0.0;

    public function __construct(
        protected OutputStyle $output
    ) {
    }

    public function info(string $string)
    {
        $this->line($string, 'info');
    }

    public function error($string)
    {
        $this->line($string, 'error');
    }

    public function warning($string)
    {
        if (! $this->output->getFormatter()->hasStyle('warning')) {
            $style = new OutputFormatterStyle('yellow');

            $this->output->getFormatter()->setStyle('warning', $style);
        }

        $this->line($string, 'warning');
    }

    public function line(string $string, $style = null)
    {
        $title = str_pad(mb_strtoupper($style), 7);
        $styled = $style ? "<$style>[$title] $string</$style>" : $string;

        $this->output->writeln($styled);
    }

    public function __invoke(...$args): void
    {
        if (!function_exists('\\Sentry\\startTransaction')) {
            $this->run(...$args);
            $this->flushSentryIfNeeded();
            return;
        }

        $hub = SentrySdk::getCurrentHub();
        $parentSpan = $hub->getSpan();

        $context = new TransactionContext();
        $context->setName($this->sentryTransactionName(...$args));
        $context->setOp($this->sentryTransactionOp(...$args));

        $transaction = \Sentry\startTransaction($context);
        $hub->setSpan($transaction);

        $this->sentryConfigureScope(...$args);

        try {
            $this->run(...$args);

            if (method_exists($transaction, 'getStatus') && $transaction->getStatus() === null) {
                $transaction->setStatus(SpanStatus::ok());
            }
        } catch (\Throwable $e) {
            $transaction->setStatus(SpanStatus::internalError());
            \Sentry\captureException($e);
            throw $e;
        } finally {
            $transaction->finish();
            $hub->setSpan($parentSpan);
            $this->flushSentryIfNeeded();
        }
    }

    protected function sentryTransactionName(...$args): string
    {
        $class = class_basename(static::class);
        $class = preg_replace('/Callback$/', '', $class) ?? $class;

        return 'socket.' . $class;
    }

    protected function sentryTransactionOp(...$args): string
    {
        return 'socket.callback';
    }

    protected function sentryConfigureScope(...$args): void
    {
        \Sentry\configureScope(static function (\Sentry\State\Scope $scope): void {
            $scope->setTag('socket.layer', 'openswoole');
            $scope->setTag('socket.callback', class_basename(static::class));
        });
    }

    abstract protected function run(...$args): void;

    protected function flushSentryIfNeeded(
        int $flushEveryN = 50,
        float $flushEverySeconds = 10.0,
        float $timeoutSeconds = 2.0,
    ): void {
        if (!function_exists('\\Sentry\\flush')) {
            return;
        }

        self::$sentryFlushCounter++;
        $now = microtime(true);

        $shouldFlushByCount = $flushEveryN > 0 && (self::$sentryFlushCounter % $flushEveryN) === 0;
        $shouldFlushByTime = ($now - self::$sentryLastFlushAt) >= $flushEverySeconds;

        if (!$shouldFlushByCount && !$shouldFlushByTime) {
            return;
        }

        self::$sentryLastFlushAt = $now;

        try {
            \Sentry\flush($timeoutSeconds);
        } catch (\Throwable) {
            // Avoid breaking the socket loop if Sentry transport fails.
        }
    }

}
