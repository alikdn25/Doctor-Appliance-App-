<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pages of a signed-in person are never stored by the browser or a proxy: after a sign-out, or when someone else
 * signs in on the same phone, Back or a cached copy cannot show the previous person's customers and jobs.
 */
class PrivatePages
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($request->user() !== null && $request->isMethod('GET') && ! $response->headers->has('Content-Disposition')) {
            $response->headers->set('Cache-Control', 'no-store, private');
            $response->headers->set('Vary', trim($response->headers->get('Vary', '').', Cookie', ', '));
        }

        return $response;
    }
}
