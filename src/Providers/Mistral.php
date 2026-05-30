<?php

namespace Aimeos\Prisma\Providers;

use Aimeos\Prisma\Concerns\CallsTools;
use Aimeos\Prisma\Concerns\OpenaiApi;
use Aimeos\Prisma\Exceptions\PrismaException;
use Psr\Http\Message\ResponseInterface;


class Mistral extends Base
{
    use CallsTools;
    use OpenaiApi { toolsParam as openaiToolsParam; }


    /** @var array<string, array<string, mixed>> */
    private static array $providerToolMap = [
        'web_search' => ['type' => 'web_search', 'options' => []],
        'web_search_premium' => ['type' => 'web_search_premium', 'options' => []],
        'code_execution' => ['type' => 'code_interpreter', 'options' => []],
        'image_generation' => ['type' => 'image_generation', 'options' => []],
        'document_library' => ['type' => 'document_library', 'options' => ['library_ids']],
    ];


    public function __construct( array $config )
    {
        if( !isset( $config['api_key'] ) ) {
            throw new PrismaException( 'No API key' );
        }

        $this->header( 'Authorization', 'Bearer ' . $this->cfg( $config, 'api_key' ) );
        $this->baseUrl( $this->cfg( $config, 'url', 'https://api.mistral.ai' ) );
    }


    /**
     * Maps the tool choice to the values supported by Mistral.
     *
     * Mistral accepts "auto", "none" and forces tool use with either "any" or
     * "required"; unsupported choices are omitted.
     *
     * @return string|null Mapped tool_choice value or null to omit
     */
    protected function toolChoiceParam() : ?string
    {
        return in_array( $choice = $this->toolChoice(), ['auto', 'required', 'any', 'none'], true ) ? $choice : null;
    }


    /**
     * Builds the tools parameter in Mistral format.
     *
     * @return array<int, array<string, mixed>> Formatted tools definition
     */
    protected function toolsParam() : array
    {
        return array_merge( $this->openaiToolsParam(), $this->mapProviderTools( self::$providerToolMap ) );
    }


    protected function validate( ResponseInterface $response ) : void
    {
        if( ( $status = $response->getStatusCode() ) !== 200 )
        {
            $this->throw( match( $status ) {
                422 => 400,
                default => $status
            }, ( $msg = @$this->fromJson( $response )['message'] ?? null ) && is_string( $msg ) ? $msg : $response->getReasonPhrase() );
        }
    }
}
