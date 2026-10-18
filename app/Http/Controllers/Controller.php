<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

abstract class Controller
{
    /**
     * Column sorting of a desktop table (UX-REDESIGN §13): ?sort= one of the allowed columns, ?dir=asc|desc.
     *
     * @param  list<string>  $allowed
     * @return array{0: string, 1: 'asc'|'desc'}
     */
    protected static function sorting(Request $request, array $allowed, string $default, string $defaultDir = 'asc'): array
    {
        $sort = (string) $request->query('sort', $default);
        $dir = $request->query('dir') === 'desc' ? 'desc' : ($request->query('dir') === 'asc' ? 'asc' : $defaultDir);

        return in_array($sort, $allowed, true) ? [$sort, $dir] : [$default, $defaultDir];
    }
}
