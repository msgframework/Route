<?php

namespace Msgframework\Lib\Route;

interface RouteMapBuilderInterface
{
    public function buildRules(): RouteMap;
}