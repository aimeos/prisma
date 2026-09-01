<?php

namespace Aimeos\Prisma\Providers;

use Aimeos\Prisma\Concerns\CallsTools;
use Aimeos\Prisma\Concerns\OpenaiApi;
use Aimeos\Prisma\Exceptions\PrismaException;
use Aimeos\Prisma\Files\Audio;
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
     * Builds OpenRouter chat content with audio and video support.
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
            if( $file instanceof Audio ) {
                $content[] = [
                    'type' => 'input_audio',
                    'input_audio' => [
                        'data' => $file->base64(),
                        'format' => $this->audioFormat( $file ),
                    ],
                ];
            } elseif( $file instanceof Video ) {
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
     * Returns the OpenRouter audio format for an audio input.
     *
     * @param Audio $audio Input audio file
     * @return string Audio format name
     */
    protected function audioFormat( Audio $audio ) : string
    {
        $extension = strtolower( pathinfo( (string) $audio->filename(), PATHINFO_EXTENSION ) );
        $formats = ['aac', 'aiff', 'flac', 'm4a', 'mp3', 'ogg', 'opus', 'pcm16', 'pcm24', 'wav', 'webm'];

        if( in_array( $extension, $formats, true ) ) {
            return $extension;
        }

        return match( $audio->mimeType() ) {
            'audio/aac' => 'aac',
            'audio/aiff', 'audio/x-aiff' => 'aiff',
            'audio/flac', 'audio/x-flac' => 'flac',
            'audio/mp4', 'audio/m4a', 'audio/x-m4a', 'video/mp4' => 'm4a',
            'audio/mpga' => 'mp3',
            'audio/ogg', 'video/ogg' => 'ogg',
            'audio/opus' => 'opus',
            'audio/wav', 'audio/wave', 'audio/x-wav' => 'wav',
            'audio/webm', 'video/webm' => 'webm',
            default => 'mp3',
        };
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
