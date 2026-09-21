<?php

namespace Aimeos\Prisma\Contracts;


interface Cancel
{
    /**
     * Cancels an asynchronous job, e.g. if its result isn't needed anymore.
     *
     * @param string $jobId Job ID returned unchanged by jobId() of a previous response
     * @return void
     * @throws \Aimeos\Prisma\Exceptions\BadRequestException If the job ID isn't one of the provider, e.g. empty
     */
    public function cancel( string $jobId ) : void;
}
