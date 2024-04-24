<?php
/**
 * A namespace, class, interface or trait discovered in the project.
 */

namespace BrianHenryIE\Strauss;

abstract class DiscoveredSymbol
{
    protected ?File $file;

    protected string $symbol;

    protected string $replacement;

    public function __construct(string $symbol, File $file)
    {
        $this->symbol = $symbol;
        $this->file = $file;

        $file->addDiscoveredSymbol($this);
    }

    public function getOriginalSymbol(): string
    {
        return $this->symbol;
    }

    public function setSymbol(string $symbol): void
    {
        $this->symbol = $symbol;
    }

    public function getFile(): ?File
    {
        return $this->file;
    }

    public function setFile(File $file): void
    {
        $this->file = $file;
    }

    public function getReplacement(): string
    {
        return $this->replacement ?? $this->symbol;
    }

    public function setReplacement(string $replacement): void
    {
        $this->replacement = $replacement;
    }
}
