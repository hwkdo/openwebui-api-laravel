<?php

namespace Hwkdo\OpenwebuiApiLaravel\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \Hwkdo\OpenwebuiApiLaravel\OpenwebuiApiLaravel
 */
class OpenwebuiApiLaravel extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Hwkdo\OpenwebuiApiLaravel\OpenwebuiApiLaravel::class;
    }
}
