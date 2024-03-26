<?php

namespace BrianHenryIE\Strauss;

use ArrayAccess;
use BrianHenryIE\Strauss\Types\ClassSymbol;
use BrianHenryIE\Strauss\Types\ConstantSymbol;
use BrianHenryIE\Strauss\Types\NamespaceSymbol;

class DiscoveredSymbols implements ArrayAccess
{

    /** @var array<string,DiscoveredSymbol> */
    protected array $types = [];

    public function __construct()
    {
        $this->types = [
            ClassSymbol::class => [],
            ConstantSymbol::class => [],
            NamespaceSymbol::class => [],
        ];
    }

    /**
     * @param DiscoveredSymbol $symbol
     */
    public function add(DiscoveredSymbol $symbol)
    {
        $this->types[ get_class($symbol)][$symbol->getSymbol() ] = $symbol;
    }

    /**
     * @return DiscoveredSymbol[]
     */
    public function getTypes(): array
    {
        return $this->types;
    }

    /**
     * @return bool
     */
    #[\ReturnTypeWillChange]
    public function offsetExists($offset)
    {
        return isset($this->types[ $offset ]);
    }

    /**
     * @return DiscoveredSymbol
     */
    #[\ReturnTypeWillChange]
    public function offsetGet($offset)
    {
        return $this->types[ $offset ];
    }

    /**
     * @inheritDoc
     */
    #[\ReturnTypeWillChange]
    public function offsetSet($offset, $value)
    {
        if (is_null($offset)) {
            $this->types[] = $value;
        } else {
            $this->types[ $offset ] = $value;
        }
    }

    /**
     * @inheritDoc
     */
    #[\ReturnTypeWillChange]
    public function offsetUnset($offset)
    {
        unset($this->types[ $offset ]);
    }

    /**
     * @return array<string, ConstantSymbol>
     */
    public function getConstants()
    {
        return $this->types[ConstantSymbol::class];
    }

    /**
     * @return array<string, NamespaceSymbol>
     */
    public function getNamespaces(): array
    {
        return $this->types[NamespaceSymbol::class];
    }

    public function getClasses()
    {
        return $this->types[ClassSymbol::class];
    }


    /**
     * TODO: Order by longest string first. (or instead, record classnames with their namespaces)
     *
     * @return array<string, NamespaceSymbol>
     */
    public function getDiscoveredNamespaces(?string $namespacePrefix = ''): array
    {
        $discoveredNamespaceReplacements = [];

        // When running subsequent times, try to discover the original namespaces.
        // This is naive: it will not work where namespace replacement patterns have been used.
        foreach ($this->getNamespaces() as $key => $value) {
            $discoveredNamespaceReplacements[ $value->getSymbol() ] = $value;
        }

        uksort($discoveredNamespaceReplacements, function ($a, $b) {
            return strlen($a) <=> strlen($b);
        });

        return $discoveredNamespaceReplacements;
    }

    /**
     * @return string[]
     */
    public function getDiscoveredClasses(?string $classmapPrefix = ''): array
    {
        $discoveredClasses = $this->getClasses();

        $discoveredClasses = array_filter(
            array_keys($discoveredClasses),
            function (string $replacement) use ($classmapPrefix) {
                return empty($classmapPrefix) || ! str_starts_with($replacement, $classmapPrefix);
            }
        );

        return $discoveredClasses;
    }

    /**
     * @return string[]
     */
    public function getDiscoveredConstants(?string $constantsPrefix = ''): array
    {
        $discoveredConstants = $this->getConstants();
        $discoveredConstants = array_filter(
            array_keys($discoveredConstants),
            function (string $replacement) use ($constantsPrefix) {
                return empty($constantsPrefix) || ! str_starts_with($replacement, $constantsPrefix);
            }
        );

        return $discoveredConstants;
    }
}
