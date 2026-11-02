<?php

declare(strict_types=1);

namespace Hwkdo\OpenwebuiApiLaravel\Prism\Providers\Maps;

use Prism\Prism\Tool;
use stdClass;

class ToolMap
{
    /**
     * Chat-Completions-Tools als OpenAI-JSON-Schema.
     *
     * `parameters` muss `{type, properties, required}` sein. Eine flache
     * Property-Map kollidiert in Bifrost mit Schema-Feldern wie `description`.
     *
     * @param  array<int, Tool>  $tools
     * @return array<int, array<string, mixed>>
     */
    public static function map(array $tools): array
    {
        return array_map(fn (Tool $tool): array => [
            'type' => 'function',
            'function' => [
                'name' => $tool->name(),
                'description' => $tool->description(),
                'parameters' => [
                    'type' => 'object',
                    'properties' => $tool->parameters() !== []
                        ? $tool->parametersAsArray()
                        : new stdClass,
                    'required' => $tool->requiredParameters(),
                ],
            ],
        ], $tools);
    }
}
