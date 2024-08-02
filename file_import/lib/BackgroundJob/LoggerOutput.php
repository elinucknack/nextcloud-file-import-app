<?php

declare(strict_types = 1);

namespace OCA\FileImport\BackgroundJob;

use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Formatter\NullOutputFormatter;
use Symfony\Component\Console\Formatter\OutputFormatterInterface;
use Symfony\Component\Console\Output\OutputInterface;

class LoggerOutput implements OutputInterface {
    
    private LoggerInterface $loggerInterface;
    private NullOutputFormatter $formatter;
    private int $verbosity;
    private bool $hasErrors;
    
    public function __construct(LoggerInterface $loggerInterface, ?int $verbosity = self::VERBOSITY_NORMAL) {
        $this->loggerInterface = $loggerInterface;
        $this->verbosity = $verbosity ?? self::VERBOSITY_NORMAL;
        $this->hasErrors = false;
    }
    
    public function setFormatter(OutputFormatterInterface $formatter): void {
    }
    
    public function getFormatter(): OutputFormatterInterface {
        return $this->formatter ??= new NullOutputFormatter();
    }
    
    public function setDecorated(bool $decorated): void {
    }
    
    public function isDecorated(): bool {
        return false;
    }
    
    public function setVerbosity(int $level): void {
        $this->verbosity = $level;
    }
    
    public function getVerbosity(): int {
        return $this->verbosity;
    }
    
    public function isQuiet(): bool {
        return self::VERBOSITY_QUIET === $this->verbosity;
    }
    
    public function isVerbose(): bool {
        return self::VERBOSITY_VERBOSE <= $this->verbosity;
    }
    
    public function isVeryVerbose(): bool {
        return self::VERBOSITY_VERY_VERBOSE <= $this->verbosity;
    }
    
    public function isDebug(): bool {
        return self::VERBOSITY_DEBUG <= $this->verbosity;
    }
    
    public function writeln($messages, int $options = 0): void {
        $this->write($messages, true, $options);
    }
    
    public function write($messages, bool $newline = false, int $options = 0): void {
        if (!is_iterable($messages)) {
            $messages = [$messages];
        }
        
        $verbosities = self::VERBOSITY_QUIET | self::VERBOSITY_NORMAL | self::VERBOSITY_VERBOSE | self::VERBOSITY_VERY_VERBOSE | self::VERBOSITY_DEBUG;
        $verbosity = $verbosities & $options ?: self::VERBOSITY_NORMAL;
        
        if ($verbosity > $this->getVerbosity()) {
            return;
        }
        
        foreach ($messages as $message) {
            $hasErrors = $this->detectErrors($message);
            if ($hasErrors) {
                $this->hasErrors = true;
            }
            $this->doWrite($this->format($message), $hasErrors);
        }
    }
    
    public function hasErrors(): bool {
        return $this->hasErrors;
    }
    
    private function format(?string $message): string {
        if ($message === null) {
            return '';
        }
        return strip_tags($message);
    }
    
    private function detectErrors(?string $message): bool {
        if ($message === null) {
            return false;
        }
        return preg_match('/<error>/', $message) === 1;
    }
    
    private function doWrite(string $message, bool $hasErrors): void {
        if ($hasErrors) {
            $this->loggerInterface->error($message);
            return;
        }
        $this->loggerInterface->info($message);
    }
    
}
