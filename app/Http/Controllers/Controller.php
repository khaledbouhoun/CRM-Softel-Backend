<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

abstract class Controller
{
    /**
     * Name of the employee making a staff request, from the optional X-Employee-Name header
     * the desktop app sends. The CRM has no staff login (LAN trust), so this is an audit
     * label, never an authorisation input.
     */
    protected function actor(Request $request): ?string
    {
        $name = trim((string) $request->header('X-Employee-Name'));

        return $name === '' ? null : mb_substr($name, 0, 100);
    }
}
