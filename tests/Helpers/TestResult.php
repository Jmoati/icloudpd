<?php

namespace Tests\Helpers;

/** Mock Result class that mimics Click's Result interface for compatibility */
class TestResult
{
    public int $exit_code;
    public string $output;
    public ?\Throwable $exception;
    public string $stderr_bytes;
    /** Will be set later if needed */
    public string $raw_output = '';

    // the `@property` of upstream
    public string $stdout_bytes {
        get => $this->output;
    }

    public function __construct(
        int $exit_code = 0,
        string $output = '',
        ?\Throwable $exception = null,
        string $stderr = '',
    ) {
        $this->exit_code = $exit_code;
        $this->output = $output;
        $this->exception = $exception;
        $this->stderr_bytes = $stderr;
    }

    public function __toString(): string
    {
        return "<TestResult {$this->exit_code}>";
    }
}
