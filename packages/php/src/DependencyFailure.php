<?php

namespace Srd;

use Symfony\Component\HttpKernel\Exception\HttpException;

final class DependencyFailure extends HttpException
{
    public function __construct()
    {
        parent::__construct(503, 'Dependency unavailable');
    }
}
