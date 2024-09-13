<?php

namespace Wave\Http\Middleware;

use Closure;
use Illuminate\Contracts\Routing\Registrar;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class HubSpotValidation
{
    /**
     * The router instance.
     *
     * @var \Illuminate\Contracts\Routing\Registrar
     */
    protected $router;

    /**
     * Create a new bindings substitutor.
     *
     * @param  \Illuminate\Contracts\Routing\Registrar  $router
     * @return void
     */
    public function __construct(Registrar $router)
    {
        $this->router = $router;
    }

    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */

    public function handle($request, Closure $next)
    {
        // return request for testing purpose
        return $next($request);

        $client_id   = env('HUBSPOT_CLIENT_SECRET'); // HubSpot Client Secret.
        $http_method = 'POST'; // Method used in endpoint.
        $url         = $request['X-Forwarded-Proto'] . $request['Host'] . '/hubspot/contact';
        $req_body    =  (isset($request) && !empty($request->getContent())) ? $request->getContent() : ''; // Body need for POST request.
        $hash_code   = '';
        $req_header = $request->header();
        if ($req_header['x-hubspot-signature-version'][0] == 'v1') {
            $hash_body = $client_id . $req_body;
            $hash_code = hash("sha256", $hash_body);
            if ($hash_code == $req_header['x-hubspot-signature'][0]) {
                return $next($request);
            }
        }
        if ($req_header['x-hubspot-signature-version'][0] == 'v2') {
            $hash_body = $client_id . $http_method . $url . $req_body;
            $hash_code = hash("sha256", $hash_body);
            if ($hash_code == $req_header['x-hubspot-signature'][0]) {
                return $next($request);
            }
        }
        return false;
    }
}
