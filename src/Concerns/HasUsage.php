<?php

namespace Aimeos\Prisma\Concerns;


trait HasUsage
{
    private array $usage = [];


    public function usage() : array
    {
        return $this->usage;
    }


    public function withUsage( ?int $used, ?int $available = null, array $more = [] ) : self
    {
        $this->usage = ['used' => $used, 'available' => $available] + $more;
        return $this;
    }
}
