<?php

namespace Aimeos\Prisma\Providers\Audio;

use Aimeos\Prisma\Contracts\Audio\Speak;
use Aimeos\Prisma\Exceptions\PrismaException;
use Aimeos\Prisma\Providers\Base;
use Aimeos\Prisma\Responses\FileResponse;
use Psr\Http\Message\ResponseInterface;


class Alibaba extends Base implements Speak
{
    public function __construct( array $config )
    {
        if( !isset( $config['api_key'] ) ) {
            throw new PrismaException( sprintf( 'No API key' ) );
        }

        $this->header( 'Authorization', 'Bearer ' . $config['api_key'] );
        $this->header( 'Content-Type', 'application/json' );
        $this->baseUrl( $config['url'] ?? 'https://dashscope-intl.aliyuncs.com' );
    }


    public function speak( string $text, ?string $voice = null, array $options = [] ) : FileResponse
    {
        $selected = $voice ?: 'Cherry';
        $model = $this->modelName( 'qwen3-tts-flash' );

        $allowed = $this->allowed( $options, [
            'language_type', 'instructions', 'optimize_instructions'
        ] );

        $request = [
            'model' => $model,
            'input' => [
                'text' => $text,
                'voice' => $selected,
            ] + $allowed
        ];

        $response = $this->client()->post( 'api/v1/services/aigc/multimodal-generation/generation', ['json' => $request] );

        $this->validate( $response );

        $data = $this->fromJson( $response );
        $url = $data['output']['audio']['url'] ?? null;

        if( empty( $url ) ) {
            throw new PrismaException( 'No audio data found in response' );
        }

        return FileResponse::fromUrl( $url, 'audio/mpeg' )
            ->withUsage( $data['usage']['input_tokens'] ?? $data['usage']['characters'] ?? 0, $data['usage'] ?? [] )
            ->withMeta( ['request_id' => $data['request_id'] ?? ''] );
    }


    protected function validate( ResponseInterface $response ) : void
    {
        if( $response->getStatusCode() === 200 ) {
            return;
        }

        $error = $this->fromJson( $response )['message'] ?? $response->getReasonPhrase();

        $this->throw( $response->getStatusCode(), $error );
    }
}
