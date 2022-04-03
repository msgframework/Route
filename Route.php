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
    protected string $controller;
    protected string $action;
    protected bool $home = false;
    protected $menu;
    protected string $path = "";
    protected Registry $vars;
    protected Registry $params;
    protected array $methods = array();

    /**
     * @var array Array of default methods types
     */
    private array $methodsTypes = array(
        'GET',
        'POST',
        'PUT',
        'DELETE'
    );

    public function __construct(ExtensionAwareInterface $component, array $methods, $path, $target)
    {
        foreach ($methods as $method)
        {
            if(in_array($method, $this->methodsTypes))
            {
                $this->methods[] = $method;
            }
        }

        $this->component = $component;
        $this->controller = $target->controller;
        $this->action = $target->action;

        $this->id = Uuid::uuid3(Uuid::NAMESPACE_OID, "route/{$this->component->getName()}/{$this->controller}/{$this->action}");
        $this->path = ltrim($path, '/');

        $this->vars = new Registry($target->vars);
        $this->params = new Registry($target->params);
    }

    public function getId(): UuidInterface
    {
        return $this->id;
    }

    public function setMenu($menu)
    {
        $this->menu = $menu;
    }

    public function getMenu()
    {
        return $this->menu;
    }

    public function isMenu(): bool
    {
        return isset($this->menu);
    }

    public function setHome()
    {
        $this->home = true;
    }

    public function isHome(): bool
    {
        return $this->home == true;
    }

    public function getComponent(): ExtensionAwareInterface
    {
        return $this->component;
    }

    public function getController()
    {
        return $this->controller;
    }

    public function getAction()
    {
        return $this->action;
    }

    public function setPath($path)
    {
        $this->path = $path;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getVars(): Registry
    {
        return clone $this->vars;
    }

    public function setVars($vars)
    {
        $this->vars = new Registry($vars);
    }

    public function getParams(): Registry
    {
        return clone $this->params;
    }

    public function setParams($params)
    {
        $this->params = new Registry($params);
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
        $this->params = clone $this->params;
    }
}