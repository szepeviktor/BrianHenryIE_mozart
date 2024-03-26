<?php
/**
 * A namespace, class, interface or trait discovered in the project.
 */

namespace BrianHenryIE\Strauss;

class DiscoveredType
{

    const TYPE_NAMESPACE = 'namespace';
    const TYPE_CLASS = 'class';
    const TYPE_INTERFACE = 'interface';
    const TYPE_TRAIT = 'trait';

    const TYPE_CONSTANT = 'constant';

    protected string $fqdn;

    protected ?File $file;

    public function getFqdn(): string
    {
        return $this->fqdn;
    }

    public function setFqdn(string $fqdn): void
    {
        $this->fqdn = $fqdn;
    }

    public function getFile(): ?File
    {
        return $this->file;
    }

    public function setFile(File $file): void
    {
        $this->file = $file;
    }
}
