<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Support\Request;
use App\Support\Response;

interface Middleware
{
    /** @param callable(Request): Response $proximo */
    public function process(Request $request, callable $proximo): Response;
}
