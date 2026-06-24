<?php

namespace Aimeos\Prisma\Providers;

use Aimeos\Prisma\Concerns\CallsTools;
use Aimeos\Prisma\Concerns\OpenaiApi;
use Aimeos\Prisma\Exceptions\PrismaException;


class Groq extends Base
{
    use CallsTools;
    use OpenaiApi {
        toolCalls as openaiToolCalls;
        toolsParam as openaiToolsParam;
    }

    public function __construct( array $config )
    {
        if( !isset( $config['api_key'] ) ) {
            throw new PrismaException( 'No API key' );
        }

        $this->header( 'authorization', 'Bearer ' . $this->config( $config, 'api_key' ) );
        $this->baseUrl( $this->config( $config, 'url', 'https://api.groq.com' ) );
    }


    /**
     * Parses tool calls from the Groq API response.
     *
     * @param array<string, mixed> $result API response data
     * @return array<int, array{id: string|null, name: string, arguments: array<string, mixed>}> Parsed tool calls
     */
    protected function toolCalls( array $result ) : array
    {
        $toolCalls = $this->openaiToolCalls( $result );

        foreach( $toolCalls as &$call )
        {
            // Some Llama models on Groq join the tool name and its JSON arguments into the
            // function name ("tool_name,{...}"); split them back apart so the name matches a
            // registered tool and the inline arguments are recovered.
            if( is_string( $call['name'] ) && str_contains( $call['name'], ',{' ) )
            {
                [$name, $inline] = explode( ',', $call['name'], 2 );

                if( is_array( $args = json_decode( $inline, true ) ) ) {
                    $call['name'] = $name;
                    $call['arguments'] = $args;
                }
            }
        }

        return $toolCalls;
    }


    /**
     * Relaxes boolean, number and integer tool parameters to also accept string values.
     *
     * Llama models on Groq return numeric and boolean arguments as JSON strings, which
     * Groq's server-side schema validation then rejects. Wrapping each scalar type in an
     * "anyOf" with a string branch keeps the request valid.
     *
     * @param array<string, mixed> $properties Tool parameter properties
     * @return array<string, mixed> Relaxed properties
     */
    protected function relaxTypes( array $properties ) : array
    {
        return array_map( function( array $prop ) {
            $type = $prop['type'] ?? null;

            if( is_string( $type ) && in_array( $type, ['boolean', 'number', 'integer'], true ) ) {
                unset( $prop['type'] );
                $prop['anyOf'] = [['type' => $type], ['type' => 'string']];
            }

            return $prop;
        }, $properties );
    }


    /**
     * Maps the tool choice to the values supported by Groq.
     *
     * Groq supports "auto" and "required" but not "none", which is omitted.
     *
     * @return string|null Mapped tool_choice value or null to omit
     */
    protected function toolChoiceParam() : ?string
    {
        return match( $this->toolChoice() ) {
            self::AUTO => 'auto',
            self::REQUIRED => 'required',
            default => null,
        };
    }


    /**
     * Builds the tools parameter, relaxing scalar parameter types for Groq.
     *
     * @return array<int, array<string, mixed>> Formatted tools definition
     */
    protected function toolsParam() : array
    {
        $tools = $this->openaiToolsParam();

        foreach( $tools as &$tool )
        {
            if( isset( $tool['function']['parameters']['properties'] ) && is_array( $tool['function']['parameters']['properties'] ) ) {
                $tool['function']['parameters']['properties'] = $this->relaxTypes( $tool['function']['parameters']['properties'] );
            }
        }

        return $tools;
    }
}
