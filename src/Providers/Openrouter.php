<?php

namespace Aimeos\Prisma\Providers;

use Aimeos\Prisma\Concerns\CallsTools;
use Aimeos\Prisma\Concerns\OpenaiApi;
use Aimeos\Prisma\Exceptions\PrismaException;
use Aimeos\Prisma\Files\Video;


class Openrouter extends Base
{
    use CallsTools;
    use OpenaiApi;


    protected const PROVIDER_TOOL_MAP = [
        'web_search' => ['type' => 'openrouter:web_search', 'options' => [
            'allowed_domains' => 'include_domains',
            'blocked_domains' => 'exclude_domains',
            'search_engine',
        ]],
    ];


    public function __construct( array $config )
    {
        if( !isset( $config['api_key'] ) ) {
            throw new PrismaException( 'No API key' );
        }

        $this->header( 'Authorization', 'Bearer ' . $this->config( $config, 'api_key' ) );
        $this->baseUrl( $this->config( $config, 'url', 'https://openrouter.ai' ) );
    }


    /**
     * Maps the tool choice to the values supported by OpenRouter.
     *
     * OpenRouter only supports "auto"; forcing or disabling tools is omitted.
     *
     * @return string|null Mapped tool_choice value or null to omit
     */
    protected function toolChoiceParam() : ?string
    {
        return $this->toolChoice() === self::AUTO ? 'auto' : null;
    }


    /** @return array<string, mixed> */
    protected function reasoningParams() : array
    {
        return $this->reasoningEnabled() === false ? ['reasoning' => ['exclude' => true]] : [];
    }


    /**
     * Builds OpenRouter chat content with video support.
     *
     * @param string $prompt Text prompt
     * @param array<int, \Aimeos\Prisma\Files\File> $files Input media files
     * @return array<int, array<string, mixed>> Content blocks
     */
    protected function content( string $prompt, array $files ) : array
    {
        $content = [];

        foreach( $files as $file )
        {
            if( $file instanceof Video ) {
                $content[] = [
                    'type' => 'video_url',
                    'video_url' => ['url' => $this->fileUrl( $file )],
                ];
            } else {
                $content[] = [
                    'type' => 'image_url',
                    'image_url' => ['url' => $this->fileUrl( $file )],
                ];
            }
        }

        $content[] = ['type' => 'text', 'text' => $prompt];

        return $content;
    }


    /**
     * Returns a public URL or an inline data URI for an input file.
     *
     * @param \Aimeos\Prisma\Files\File $file Input file
     * @return string URL or data URI
     */
    protected function fileUrl( \Aimeos\Prisma\Files\File $file ) : string
    {
        return $file->url() ?: sprintf(
            'data:%s;base64,%s',
            $file->mimeType() ?? 'application/octet-stream',
            $file->base64()
        );
    }


}
