<?php

namespace FastRoute\DataGenerator;

class GroupPosBased extends RegexBasedAbstract {
    protected function getApproxChunkSize() {
        return 10;
    }

    protected function processChunk($regexToRoutesMap) {
        $routeMap = [];
        $regexes = [];
        $offset = 1;
        foreach ($regexToRoutesMap as $regex => $route) {
            $regexes[] = $regex;
            $routeMap[$offset] = [$route->handler, $route->variables];

            $offset += $this->countCapturingGroups($regex);
        }

        $regex = '~^(?:' . implode('|', $regexes) . ')$~';
        return ['regex' => $regex, 'routeMap' => $routeMap];
    }

    /**
     * Placeholder regexes may contain capturing groups of their own, so the
     * number of variables does not tell where the next route's groups start.
     */
    private function countCapturingGroups($regex) {
        // The trailing empty group always participates, so every group of
        // $regex is reported, numbered 1 to N, followed by the marker N + 1.
        preg_match('~(?:' . $regex . ')?()~', '', $matches);
        return count(array_filter(array_keys($matches), 'is_int')) - 2;
    }
}
