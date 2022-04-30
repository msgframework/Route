<?php

namespace Msgframework\Lib\Route;

use Msgframework\Lib\Registry\Registry;

interface TargetInterface
{
    public function getParams(): Registry;
    public function getController(): string;
    public function getAction(): string;
}