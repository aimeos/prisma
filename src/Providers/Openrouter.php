<?php

namespace Aimeos\Prisma\Providers;

use Aimeos\Prisma\Concerns\CallsTools;
use Aimeos\Prisma\Concerns\OpenaiApi;
use Aimeos\Prisma\Exceptions\PrismaException;


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
