<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Filament pages bind their state to typed Livewire properties through URL parameters: `filters` is an array,
 * `search`, `sort`, `tab`, `grouping` and `relation` are strings, and `reordering` is a boolean. Livewire assigns
 * these before any component hook runs, and it JSON-decodes string values first, so a tampered value such as
 * `?filters=x`, `?search[]=x` or `?search=[1]` fails with a TypeError and a 500 response. Any parameter of the
 * wrong shape is removed, and the page opens with its defaults.
 */
class DropTamperedTableQuery
{
    /**
     * @var list<string>
     */
    private const ARRAY_PARAMETERS = ['filters'];

    /**
     * @var list<string>
     */
    private const SCALAR_PARAMETERS = ['search', 'sort', 'tab', 'grouping', 'relation', 'reordering'];

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $query = $request->query->all();

        foreach (self::ARRAY_PARAMETERS as $key) {
            if (array_key_exists($key, $query) && ! is_array($query[$key])) {
                $request->query->remove($key);
            }
        }

        foreach (self::SCALAR_PARAMETERS as $key) {
            if (array_key_exists($key, $query) && self::isArrayValue($query[$key])) {
                $request->query->remove($key);
            }
        }

        return $next($request);
    }

    /**
     * True for an array, or for a string that Livewire would JSON-decode into an array.
     */
    private static function isArrayValue(mixed $value): bool
    {
        return is_array($value) || (is_string($value) && is_array(json_decode($value, true)));
    }
}
