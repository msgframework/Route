<?php

namespace Msgframework\Lib\Route;

use Msgframework\Lib\Extension\ExtensionAwareInterface;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use Msgframework\Lib\Registry\Registry;

class Route
{
    protected UuidInterface $id;
    protected ExtensionAwareInterface $component;
    protected TargetInterface $target;
    protected string $path = "";
    public Registry $vars;
    protected array $methods = array();
    protected Route $parent;
    protected bool $home = false;

    /**
     * @var array Array of default methods types
     */
    private array $methodsTypes = array(
        'GET',
        'POST',
        'PUT',
        'DELETE'
    );

    public function __construct(ExtensionAwareInterface $component, array $methods, $path, TargetInterface $target, $vars, ?Route $parent = null)
    {
        $this->methods = array_intersect($methods, $this->methodsTypes);

        if($parent instanceof Route) {
            $this->setParent($parent);
        }

        $this->component = $component;
        $this->target = $target;

        $this->id = Uuid::uuid3(Uuid::NAMESPACE_OID, "route/{$this->component->getName()}/{$this->target->getController()}/{$this->target->getAction()}");
        $this->path = ltrim($path, '/');
        $this->vars = new Registry($vars);
    }

    public function getId(): UuidInterface
    {
        return $this->id;
    }

    public function setParent(Route $parent)
    {
        $this->parent = $parent;
    }

    public function getParent(): Route
    {
        if(!isset($this->parent)) {
            throw new \RuntimeException(sprintf('This Route has no parent: %s', $this->getId()));
        }

        return $this->parent;
    }

    public function hasParent(): bool
    {
        return isset($this->parent);
    }

    public function setHome(bool $home)
    {
        $this->home = $home;
    }

    public function isHome(): bool
    {
        return $this->home;
    }

    public function getVars(): Registry
    {
        return $this->vars;
    }

    public function getComponent(): ExtensionAwareInterface
    {
        return $this->component;
    }

    public function setTarget(TargetInterface $target): void
    {
        $this->target = $target;
    }

    public function getTarget(): TargetInterface
    {
        return $this->target;
    }

    public function setPath($path)
    {
        $this->path = $path;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getMethods(): array
    {
        return $this->methods;
    }

    public function __get($name)
    {
        $method = "get" . ucfirst($name);

        if(!isset($this->$name)) {
            throw new \RuntimeException(sprintf('Property %s can not be read from this Route', $name));
        }

        if(!\is_callable(array($this, $method))) {
            throw new \RuntimeException(sprintf('Method %s can\'t be call from this Route', $method));
        }

        return $this->$method();
    }

    function __clone()
    {
        $this->home = false;
        $this->vars = clone $this->vars;
    }
}