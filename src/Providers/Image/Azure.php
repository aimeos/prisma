<?php

namespace Aimeos\Prisma\Providers\Image;

use Aimeos\Prisma\Contracts\Image\Recognize;
use Aimeos\Prisma\Exceptions\PrismaException;
use Aimeos\Prisma\Files\Image;
use Aimeos\Prisma\Providers\Base;
use Aimeos\Prisma\Responses\TextResponse;


class Azure extends Base implements Recognize
{
    public function __construct( array $config )
    {
        if( !isset( $config['api_key'] ) ) {
            throw new PrismaException( 'No API key' );
        }

        $region = $this->config( $config, 'region', 'eastus' );
        $url = $this->config( $config, 'url', 'https://' . $region . '.api.cognitive.microsoft.com' );

        $this->header( 'Ocp-Apim-Subscription-Key', $this->config( $config, 'api_key' ) );
        $this->baseUrl( rtrim( $url, '/' ) . '/' );
    }


    public function recognize( Image $image, array $options = [] ) : TextResponse
    {
        $query = ['api-version' => '2024-02-01', 'features' => 'read'] + $this->allowed( $options, ['model-version'] );

        $request = $image->url()
            ? ['json' => ['url' => $image->url()]]
            : ['headers' => ['Content-Type' => 'application/octet-stream'], 'body' => $image->binary()];

        $response = $this->client()->post( 'computervision/imageanalysis:analyze', ['query' => $query] + $request );

        $this->validate( $response );

        /** @var array<string, mixed> */
        $data = $this->fromJson( $response );

        /** @var array<int, array<string, mixed>> */
        $blocks = $data['readResult']['blocks'] ?? [];

        return TextResponse::fromText( implode( "\n\n", array_map( fn( $block ) => $this->lines( $block ), $blocks ) ) )
            ->withStructured( $blocks )
            ->withMeta( array_intersect_key( $data, ['metadata' => true, 'modelVersion' => true] ) );
    }


    /**
     * Returns the text lines of a recognized block.
     *
     * @param array<string, mixed> $block Recognized text block
     * @return string Lines separated by newlines
     */
    private function lines( array $block ) : string
    {
        /** @var array<int, array<string, mixed>> */
        $lines = $block['lines'] ?? [];

        return implode( "\n", array_map( fn( $line ) => is_string( $line['text'] ?? null ) ? $line['text'] : '', $lines ) );
    }
}
