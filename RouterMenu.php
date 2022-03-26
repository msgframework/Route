<?php

namespace Msgframework\Lib\Route;
use Joomla\Database\ParameterType;
use Msgframework\Lib\Registry\Registry;

class RouterMenu
{
    protected array $items = array();
    protected int $application_id;

    function __construct($application_id)
    {
        $this->application_id = $application_id;
        $this->items = $this->getItems();
    }

    public function getRoutes($route_id)
    {
        return $this->items[(string)$route_id] ?? array();
    }

    public function recursiveTree($items, $item, &$tree = null)
    {
        $tree[] = $item->alias;
        if ($item->parent_id)
        {
            self::recursiveTree($items, $items[$item->parent_id], $tree);
        }
        return $tree;
    }

    private function getItems(): array
    {
        $result = array();
        $items = array();

        $db = \Cms::getContainer()->get('db');
        $applicationId = $this->application_id;

        $query = $db->getQuery(true)
            ->select(array('menu.id', 'menu.menutype', 'menu.type', 'menu.component_id', 'menu.route_id', 'menu.alias', 'menu.component', 'menu.action', 'menu.vars', 'menu.title', 'menu.params', 'menu.metadata', 'menu.permission', 'menu.parent_id', 'menu.position', 'menu.home', 'menu.status', 'menutype.application_id'))
            ->from($db->quoteName('#__menu', 'menu'))
            ->join(
                'INNER',
                $db->quoteName('#__menutype', 'menutype'),
                $db->quoteName('menutype.id') . ' = ' . $db->quoteName('menu.menutype')
            )
            ->where($db->quoteName('menu.status') . ' = 1')
            ->where($db->quoteName('menutype.application_id') . ' = :applicationId',)
            ->bind(':applicationId', $applicationId, ParameterType::INTEGER);

        $db->setQuery($query);

        foreach ($db->loadObjectList() as $row) {
            $row->params = new Registry(json_decode($row->params, JSON_OBJECT_AS_ARRAY));
            $row->metadata = json_decode($row->metadata);
            $row->vars = json_decode($row->vars, JSON_OBJECT_AS_ARRAY);

            $items[$row->id] = $row;
        }

        foreach ($items as $item)
        {
            if($item->type != "component")
            {
                continue;
            }

            if (!$item->home)
            {
                $item->tree = array_reverse(self::recursiveTree($items, $item));
                $item->path = ltrim(implode("/", $item->tree), '/');
            }
            else
            {
                $item->tree = ['/'];
                $item->path = "/";
            }

            $result[(string)$item->route_id][$item->id] = $item;
        }

        return $result;
    }
}