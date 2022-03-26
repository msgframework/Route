<?php

namespace Msgframework\Lib\Route;

use Ramsey\Uuid\UuidInterface;

class RouteMap implements \IteratorAggregate, \ArrayAccess, \Countable
{
    /**
     * @var array Array of all routes (incl. named routes).
     */
    protected array $routes = array();

    /**
     * @var array Array of all named routes.
     */
    protected array $namedRoutes = array();

    /**
     * @var array Array of default match types (regex helpers)
     */
    protected array $matchTypes = array(
        'int' => '[0-9]++',
        'str' => '[0-9A-Za-z]++',
        'uuid4' => '[0-9a-f]{8}\-[0-9a-f]{4}\-4[0-9a-f]{3}\-[89ab][0-9a-f]{3}\-[0-9a-f]{12}',
        'h' => '[0-9A-Fa-f]++',
        '*' => '.+?',
        '**' => '.++',
        '' => '[^/\.]++'
    );

    function __construct()
    {
        $this->routes = array();
    }

    /**
     * Map a route to a target
     *
     * @param Route $route New Route item.
     */
    public function set(Route $route)
    {
        if(!$this->hasPath($route->getPath()))
        {
            $this->add($route, $route->getPath());
        }

        if (!self::hasRoute($route->getId()))
        {
            $this->namedRoutes[(string)$route->getId()] = true;
        }
    }

    public function hasPath($path): bool
    {
        return isset($this->routes[$path]);
    }

    public function hasRoute($id): bool
    {
        return isset($this->namedRoutes[(string)$id]);
    }

    /**
     * Retrieves all routes.
     * Useful if you want to process or display routes.
     * @param UuidInterface $id Route Route id.
     * @param string|null $requestMethod Request method.
     * @return array Routes list.
     */
    public function getRoutes(UuidInterface $id, ?string $requestMethod = null): array
    {
        $routes = array();

        if (!self::hasRoute($id))
        {
            return array();
        }

        foreach ($this->routes as $route)
        {
            if ($route->getId() != $id)
            {
                continue;
            }

            if (null !== $requestMethod)
            {
                if(in_array($requestMethod, $route->getMethods()))
                {
                    $routes[] = clone $route;
                }
            }
            else
            {
                $routes[] = clone $route;
            }
        }

        return $routes;
    }

    /**
     * Add named match types. It uses array_merge so keys can be overwritten.
     *
     * @param array $matchTypes The key is the name and the value is the regex.
     */
    private function addMatchTypes(array $matchTypes)
    {
        $this->matchTypes = array_merge($this->matchTypes, $matchTypes);
    }

    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->routes);
    }

    public function offsetExists($offset): bool
    {
        return isset($this->routes[$offset]);
    }

    public function offsetSet($offset, $value)
    {
        $this->routes[$offset] = $value;
    }

    public function offsetUnset($offset)
    {
        unset($this->routes[$offset]);
    }

    public function offsetGet($offset)
    {
        return clone $this->get($offset);
    }

    public function count(): int
    {
        return count($this->routes);
    }

    public function clear()
    {
        $this->routes = array();
        $this->namedRoutes = array();
    }

    public function isEmpty(): bool
    {
        return empty($this->routes);
    }

    public function toArray(): array
    {
        return $this->routes;
    }

    public function add($value, $offset = null)
    {
        $this->offsetSet($offset, $value);
    }

    public function get($name)
    {
        return isset($this->routes[$name]) ? $this->routes[$name] : null;
    }

    public function remove($offset)
    {
        $this->offsetUnset($offset);
    }
}