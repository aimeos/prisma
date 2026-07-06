<?php

namespace Aimeos\Prisma\Providers\Audio;

use Aimeos\Prisma\Contracts\Audio\Transcribe;
use Aimeos\Prisma\Files\Audio;
use Aimeos\Prisma\Providers\Z as Base;
use Aimeos\Prisma\Responses\TextResponse;


class Z extends Base implements Transcribe
{
    public function transcribe( Audio $audio, ?string $lang = null, array $options = [] ) : TextResponse
    {
        $allowed = $this->allowed( $options, ['hotwords', 'prompt', 'request_id', 'stream', 'user_id'] );
        $request = $this->payload( ['model' => $this->modelName( 'glm-asr-2512' )] + $allowed, ['file' => $audio] );

        $response = $this->client()->post( 'api/paas/v4/audio/transcriptions', ['multipart' => $request] );

        $this->validate( $response );

        /** @var array<string, mixed> */
        $data = $this->fromJson( $response );

        /** @var array<string, mixed> */
        $usage = $data['usage'] ?? [];
        $used = $usage['total_tokens'] ?? null;

        $text = $data['text'] ?? '';

        return TextResponse::fromText( is_string( $text ) ? $text : '' )
            ->withUsage( is_numeric( $used ) ? (float) $used : null, $usage );
    }
}
