<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Dauerhafte Weiterleitung einer Alt-Adresse auf ihre kanonische Route.
 *
 * Die Zielroute steht als Routen-Default "canonical". Pfadparameter der
 * Alt-Route werden unverändert übergeben, der Querystring (z. B.
 * ?profileTab=documents) bleibt erhalten.
 */
class CanonicalRouteRedirectController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $route = $request->route();
        $target = (string) $route->defaults['canonical'];
        $parameters = $route->parametersWithoutNulls();
        unset($parameters['canonical']);

        $url = route($target, $parameters);
        // Roh übernehmen, damit Reihenfolge und Schreibweise erhalten bleiben.
        $query = (string) $request->server->get('QUERY_STRING', '');

        return new RedirectResponse($query ? $url.'?'.$query : $url, 301);
    }
}
