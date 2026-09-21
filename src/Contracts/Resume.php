<?php

namespace Aimeos\Prisma\Contracts;

use Aimeos\Prisma\Responses\FileResponse;
use Aimeos\Prisma\Responses\TextResponse;


interface Resume
{
    /**
     * Resume polling an asynchronous job, e.g. in a later queue job.
     *
     * @param string $jobId Job ID returned unchanged by jobId() of a previous response
     * @return FileResponse|TextResponse Response of the job, a text response for transcriptions
     * @throws \Aimeos\Prisma\Exceptions\BadRequestException If the job ID isn't one of the provider, e.g. empty
     */
    public function resume( string $jobId ) : FileResponse|TextResponse;
}
