<?php

declare(strict_types=1);

namespace PdfBlock\Laravel;

/**
 * Wrapper around a rendered PDF binary string.
 */
class PdfResult
{
    public function __construct(
        private readonly string $content,
    ) {}

    /**
     * Get the raw PDF binary content.
     */
    public function content(): string
    {
        return $this->content;
    }

    /**
     * Save the PDF to a file on disk.
     */
    public function save(string $path): self
    {
        $dir = dirname($path);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($path, $this->content);

        return $this;
    }

    /**
     * Get the size in bytes.
     */
    public function size(): int
    {
        return strlen($this->content);
    }

    /**
     * Create a download response.
     */
    public function toResponse(string $filename = 'document.pdf'): \Illuminate\Http\Response
    {
        return response($this->content)
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', "attachment; filename=\"{$filename}\"")
            ->header('Content-Length', (string) $this->size());
    }

    /**
     * Create an inline (preview) response.
     */
    public function toInlineResponse(string $filename = 'document.pdf'): \Illuminate\Http\Response
    {
        return response($this->content)
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', "inline; filename=\"{$filename}\"")
            ->header('Content-Length', (string) $this->size());
    }
}
