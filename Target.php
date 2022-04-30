<?php

namespace Msgframework\Lib\Route;

use Msgframework\Lib\Registry\Registry;

class Target implements TargetInterface
{
    protected Registry $params;
    protected Registry $metadata;
    protected string $controller;
    protected string $action;

    public function __construct(string $controller, string $action, $params, $metadata)
    {
        $this->params = new Registry($params);
        $this->controller = $controller;
        $this->action = $action;
        $this->metadata = new Registry($metadata);
    }

    public function setParams($params): void
    {
        $this->params = new Registry($params);
    }

    public function setMetadata($metadata): void
    {
        $this->metadata = new Registry($metadata);
    }

    public function getParams(): Registry
    {
        return $this->params;
    }

    public function getController(): string
    {
        return $this->controller;
    }

    public function getAction(): string
    {
        return $this->action;
    }
}