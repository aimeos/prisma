<?php

namespace Aimeos\Prisma\Responses;

use Aimeos\Prisma\Concerns\HasDescription;
use Aimeos\Prisma\Concerns\HasMeta;
use Aimeos\Prisma\Concerns\HasUsage;
use Aimeos\Prisma\Files\File;


class FileResponse extends File
{
    use HasDescription, HasMeta, HasUsage;
}
