<?php

namespace Aimeos\Prisma\Providers\Text;

use Aimeos\Prisma\Contracts\Text\Write;
use Aimeos\Prisma\Exceptions\PrismaException;
use Aimeos\Prisma\Files\File;
use Aimeos\Prisma\Providers\Base;
use Aimeos\Prisma\Responses\TextResponse;
use Psr\Http\Message\ResponseInterface;


class Bedrock extends Base implements Write
{
    private string $baseUrl;


    public function __construct( array $config )
    {
        if( !isset( $config['api_key'] ) ) {
            throw new PrismaException( sprintf( 'No API key' ) );
        }

        $this->baseUrl = 'https://bedrock-runtime.us-east-1.amazonaws.com';

        $this->header( 'Content-Type', 'application/json' );
        $this->header( 'Authorization', 'Bearer ' . $config['api_key'] );
        $this->baseUrl( $config['url'] ?? $this->baseUrl );
    }


    public function write( string $prompt, array $files = [], array $options = [] ) : TextResponse
    {
        $model = $this->modelName( 'amazon.nova-pro-v1:0' );

        $content = [];

        foreach( $files as $file )
        {
            $content[] = [
                'image' => [
                    'format' => explode( '/', (string) $file->mimeType() )[1] ?? 'png',
                    'source' => [
                        'bytes' => $file->base64()
                    ]
                ]
            ];
        }

        $content[] = ['text' => $prompt];

        $messages = [['role' => 'user', 'content' => $content]];

        $request = [
            'messages' => $messages,
        ];

        if( $system = $this->systemPrompt() ) {
            $request['system'] = [['text' => $system]];
        }

        $config = $this->allowed( $options, ['temperature', 'maxTokens', 'topP'] );

        if( !empty( $config ) ) {
            $request['inferenceConfig'] = $config;
        }

        $response = $this->client()->post( $this->baseUrl . '/model/' . $model . '/converse', ['json' => $request] );

        $this->validate( $response );

        $result = $this->fromJson( $response );
        $texts = [];

        foreach( $result['output']['message']['content'] ?? [] as $block )
        {
            if( $text = $block['text'] ?? null ) {
                $texts[] = $text;
            }
        }

        $meta = $result;
        unset( $meta['output'], $meta['usage'] );

        $usage = $result['usage'] ?? [];

        return TextResponse::fromTexts( $texts )
            ->withUsage(
                ( $usage['inputTokens'] ?? 0 ) + ( $usage['outputTokens'] ?? 0 ),
                $usage,
            )
            ->withMeta( $meta );
    }


    protected function validate( ResponseInterface $response ) : void
    {
        if( $response->getStatusCode() === 200 ) {
            return;
        }

        $error = @$this->fromJson( $response )['message'] ?: $response->getReasonPhrase();

        switch( $response->getStatusCode() )
        {
            case 400:
            case 409:
            case 413: throw new \Aimeos\Prisma\Exceptions\BadRequestException( $error );
            case 401: throw new \Aimeos\Prisma\Exceptions\UnauthorizedException( $error );
            case 402: throw new \Aimeos\Prisma\Exceptions\PaymentRequiredException( $error );
            case 403: throw new \Aimeos\Prisma\Exceptions\ForbiddenException( $error );
            case 404: throw new \Aimeos\Prisma\Exceptions\NotFoundException( $error );
            case 429: throw new \Aimeos\Prisma\Exceptions\RateLimitException( $error );
            case 502:
            case 503:
            case 504: throw new \Aimeos\Prisma\Exceptions\OverloadedException( $error );
            default: throw new \Aimeos\Prisma\Exceptions\PrismaException( $error );
        }
    }
}
