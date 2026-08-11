<?php

namespace Aimeos\Prisma\Files;


/**
 * Audio file content.
 */
class Audio extends File
{
    /**
     * Browser MediaRecorder may label audio-only data with its container's video MIME type.
     */
    protected function acceptsMimeType( ?string $mimeType ) : bool
    {
        return parent::acceptsMimeType( $mimeType ) || in_array( $mimeType, [
            'video/mp4', 'video/ogg', 'video/webm'
        ], true );
    }


    protected function mimePrefix() : string
    {
        return 'audio/';
    }
}
