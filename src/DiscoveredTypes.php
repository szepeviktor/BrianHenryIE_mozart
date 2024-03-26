<?php

namespace BrianHenryIE\Strauss;

use BrianHenryIE\Strauss\Types\ClassSymbol;
use BrianHenryIE\Strauss\Types\ConstantSymbol;
use BrianHenryIE\Strauss\Types\NamespaceSymbol;

class DiscoveredTypes implements \ArrayAccess
{

    /** @var array<string,DiscoveredType> */
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
     * @param DiscoveredType $symbol
     */
    public function add(DiscoveredType $symbol)
    {
        $this->types[ get_class($symbol)][$symbol->getSymbol() ] = $symbol;
    }

    /**
     * @return DiscoveredType[]
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
     * @return DiscoveredType
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

    public function getNamespaces()
    {
        return $this->types[NamespaceSymbol::class];
    }

    public function getClasses()
    {
        return $this->types[ClassSymbol::class];
    }
}
