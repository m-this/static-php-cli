<?php

declare(strict_types=1);

namespace StaticPHP\Util;

use Symfony\Component\Console\Helper\ProgressIndicator;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\OutputInterface;
use ZM\Logger\ConsoleColor;

class InteractiveTerm
{
    private static ?ProgressIndicator $indicator = null;

    private static ?float $startedAt = null;

    private static ?OutputInterface $output = null;

    /**
     * Initialize with a real Symfony Console output (called from ConsoleApplication::doRun()).
     * After this call, all output goes through the configured Console. Color usage follows
     * the output's decoration state, which Symfony's configureIO() derives from --ansi,
     * --no-ansi and TTY detection.
     */
    public static function init(OutputInterface $output): void
    {
        self::$output = $output;
    }

    public static function notice(string $message, bool $indent = false): void
    {
        $no_ansi = self::noAnsi();
        $output = self::output();
        if ($output->isVerbose()) {
            logger()->notice(strip_ansi_colors($message));
        } else {
            $output->writeln(($no_ansi ? 'strip_ansi_colors' : 'strval')(ConsoleColor::cyan(($indent ? '  ' : '') . '▶ ') . $message));
            logger()->debug(strip_ansi_colors($message));
        }
    }

    public static function success(string $message, bool $indent = false): void
    {
        $no_ansi = self::noAnsi();
        $output = self::output();
        if ($output->isVerbose()) {
            logger()->info(strip_ansi_colors($message));
        } else {
            $output->writeln(($no_ansi ? 'strip_ansi_colors' : 'strval')(ConsoleColor::green(($indent ? '  ' : '') . '✔ ') . $message));
            logger()->debug(strip_ansi_colors($message));
        }
    }

    public static function plain(string $message, string $level = 'info'): void
    {
        $no_ansi = self::noAnsi();
        $output = self::output();
        if ($output->isVerbose()) {
            match ($level) {
                'debug' => logger()->debug(strip_ansi_colors($message)),
                'notice' => logger()->notice(strip_ansi_colors($message)),
                'warning' => logger()->warning(strip_ansi_colors($message)),
                'error' => logger()->error(strip_ansi_colors($message)),
                default => logger()->info(strip_ansi_colors($message)),
            };
        } else {
            $output = $level === 'error' && $output instanceof ConsoleOutput ? $output->getErrorOutput() : $output;
            $output->writeln(($no_ansi ? 'strip_ansi_colors' : 'strval')($message));
        }
    }

    public static function info(string $message): void
    {
        $no_ansi = self::noAnsi();
        $output = self::output();
        if (!$output->isVerbose()) {
            $output->writeln(($no_ansi ? 'strip_ansi_colors' : 'strval')(ConsoleColor::green('▶ ') . $message));
        }
        logger()->info(strip_ansi_colors($message));
    }

    public static function error(string $message, bool $indent = true): void
    {
        $no_ansi = self::noAnsi();
        $output = self::output();
        if ($output->isVerbose()) {
            logger()->error(strip_ansi_colors($message));
        } else {
            $output->writeln(($no_ansi ? 'strip_ansi_colors' : 'strval')(ConsoleColor::red(($indent ? '  ' : '') . '✘ ' . $message)));
            logger()->debug(strip_ansi_colors($message));
        }
    }

    public static function advance(): void
    {
        self::$indicator?->advance();
    }

    public static function setMessage(string $message): void
    {
        $no_ansi = self::noAnsi();
        self::$indicator?->setMessage(($no_ansi ? 'strip_ansi_colors' : 'strval')($message));
        logger()->debug(strip_ansi_colors($message));
    }

    public static function finish(string $message, bool $status = true): void
    {
        $output = self::output();
        $plain = strip_ansi_colors($message);
        if ($output->isVerbose()) {
            if (self::$startedAt !== null) {
                $plain .= sprintf(' (%.1fs)', microtime(true) - self::$startedAt);
                self::$startedAt = null;
            }
            if ($status) {
                logger()->info($plain);
            } else {
                logger()->error($plain);
            }
            return;
        }
        if (self::$indicator !== null) {
            $no_ansi = self::noAnsi();
            $marker = $status ? ConsoleColor::green(' ✔') : ConsoleColor::red(' ✘');
            self::$indicator->finish($no_ansi ? $plain : $message, $no_ansi ? strip_ansi_colors($marker) : $marker);
            self::$indicator = null;
        }
        self::$startedAt = null;
    }

    public static function indicateProgress(string $message): void
    {
        self::$startedAt ??= microtime(true);
        $no_ansi = self::noAnsi();
        $output = self::output();
        if ($output->isVerbose()) {
            logger()->info(strip_ansi_colors($message));
            return;
        }
        if (self::$indicator !== null) {
            // just reuse existing indicator, change
            self::setMessage($message);
            self::$indicator->advance();
            return;
        }
        logger()->debug(strip_ansi_colors($message));
        // Symfony's built-in %elapsed% is a time()-based integer diff, which is misleading
        // for short steps (a 0.9s step renders as '< 1 ms'). Render it from the microtime
        // recorded at the start of the current progress cycle instead.
        ProgressIndicator::setPlaceholderFormatterDefinition('elapsed', static fn () => sprintf('%.1fs', microtime(true) - (self::$startedAt ?? microtime(true))));
        // if no ansi, use a dot instead of spinner
        if ($no_ansi) {
            self::$indicator = new ProgressIndicator(self::output(), 'verbose', 100, [' •', ' •']);
            self::$indicator->start(strip_ansi_colors($message));
            return;
        }
        self::$indicator = new ProgressIndicator(self::output(), 'verbose', 100, [' ⠏', ' ⠛', ' ⠹', ' ⢸', ' ⣰', ' ⣤', ' ⣆', ' ⡇']);
        self::$indicator->start($message);
    }

    private static function noAnsi(): bool
    {
        return !self::output()->isDecorated();
    }

    /**
     * Returns the configured output, lazily creating a plain STDERR-capable fallback when
     * init() was never called (early-boot errors, tests, programmatic usage).
     */
    private static function output(): OutputInterface
    {
        return self::$output ??= new ConsoleOutput(ConsoleOutput::VERBOSITY_NORMAL, false);
    }
}
