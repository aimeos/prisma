<?php

namespace Aimeos\Prisma\Exceptions;


/**
 * Failed exception.
 *
 * The provider reported a failed or canceled job, finished a job without results or returned no
 * job ID, retrying it won't succeed.
 */
class FailedException extends PrismaException
{
}
