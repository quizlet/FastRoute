<?php

namespace FastRoute\Dispatcher;

class GroupPosBased extends RegexBasedAbstract {
    public function __construct($data) {
        list($this->staticRouteMap, $this->variableRouteData) = $data;
    }

    protected function dispatchVariableRoute($routeData, $uri) {
        foreach ($routeData as $data) {
            if (!preg_match($data['regex'], $uri, $matches, PREG_OFFSET_CAPTURE)) {
                continue;
            }

            // find first participating group; a placeholder may match an empty string
            for ($i = 1; -1 === $matches[$i][1]; ++$i);

            list($handler, $varNames) = $data['routeMap'][$i];

            $vars = [];
            foreach ($varNames as $varName) {
                $vars[$varName] = $matches[$i++][0];
            }
            return [self::FOUND, $handler, $vars, $uri];
        }

        return [self::NOT_FOUND];
    }
}
