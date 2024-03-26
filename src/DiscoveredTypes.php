<?php

namespace BrianHenryIE\Strauss;

class DiscoveredTypes implements \ArrayAccess
{

    /** @var array<string,DiscoveredType> */
    protected array $types = [];

    /**
     * @param DiscoveredType $type
     */
    public function add(DiscoveredType $type)
    {
        $this->types[] = $type;
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
    public function offsetExists($offset)
    {
        return isset($this->types[$offset]);
    }

    /**
     * @return DiscoveredType
     */
    public function offsetGet($offset)
    {
        return $this->types[$offset];
    }

    /**
     * @inheritDoc
     */
    public function offsetSet($offset, $value)
    {
        if (is_null($offset)) {
            $this->types[] = $value;
        } else {
            $this->types[$offset] = $value;
        }
    }

    /**
     * @inheritDoc
     */
    public function offsetUnset($offset)
    {
        unset($this->types[$offset]);
    }
}
