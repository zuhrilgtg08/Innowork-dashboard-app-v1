<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verifies inbound ML-service callbacks by recomputing an HMAC-SHA256 over the
 * raw request body using the shared secret. Rejects anything that doesn't match.
 */
class VerifyMlSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('services.ml.secret');
        $provided = (string) $request->header('X-ML-Signature');

        if ($secret === '' || $provided === '') {
            abort(403, 'Missing ML signature.');
        }

        $expected = hash_hmac('sha256', $request->getContent(), $secret);

        if (! hash_equals($expected, $provided)) {
            abort(403, 'Invalid ML signature.');
        }

        return $next($request);
    }
}
