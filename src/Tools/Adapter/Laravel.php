<?php

namespace Aimeos\Prisma\Tools\Adapter;


/**
 * Adapts Laravel AI/MCP tools to the Adapter interface.
 */
class Laravel extends Base
{
    private object $tool;
    private ?\Aimeos\Prisma\Schema\Schema $schema = null;


    /**
     * Initializes the adapter with a Laravel AI/MCP tool.
     *
     * @param object|string $tool Laravel AI/MCP tool instance or its fully qualified class name
     * @throws \InvalidArgumentException If the tool is not a valid Laravel AI/MCP tool
     */
    public function __construct( object|string $tool )
    {
        if( !is_a( $tool, '\Laravel\Mcp\Server\Tool', true ) && !is_a( $tool, '\Laravel\Ai\Contracts\Tool', true ) ) {
            throw new \InvalidArgumentException( sprintf( '"%s" is not a valid Laravel AI/MCP tool', is_object( $tool ) ? get_class( $tool ) : $tool ) );
        }

        $this->tool = is_string( $tool ) ? app( $tool ) : $tool; // @phpstan-ignore function.notFound
    }


    protected function execute( array $arguments ) : mixed
    {
        $class = '\Laravel\Mcp\Server\Tool';

        if( class_exists( $class ) && $this->tool instanceof $class ) {
            return $this->unwrap( app()->call( [$this->tool, 'handle'], ['request' => new \Laravel\Mcp\Request( $arguments )] ) ); // @phpstan-ignore class.notFound, function.notFound
        } elseif( method_exists( $this->tool, '__invoke' ) ) {
            return ( $this->tool )( $arguments );
        } elseif( method_exists( $this->tool, 'handle' ) ) {
            return $this->tool->handle( $arguments );
        }

        return '';
    }


    /**
     * Returns the tool description.
     *
     * @return string Tool description
     */
    public function description() : string
    {
        return (string) $this->tool->description(); // @phpstan-ignore method.notFound
    }


    /**
     * Returns the tool name.
     *
     * @return string Tool name
     */
    public function name() : string
    {
        return (string) $this->tool->name(); // @phpstan-ignore method.notFound
    }


    /**
     * Returns the schema definition for the tool parameters.
     *
     * @return \Aimeos\Prisma\Schema\Schema Schema definition
     */
    public function schema() : \Aimeos\Prisma\Schema\Schema
    {
        if( $this->schema !== null ) {
            return $this->schema;
        }

        /** @var array<string, mixed> $arr */
        $arr = $this->tool->toArray(); // @phpstan-ignore method.notFound

        // Laravel MCP/AI tools nest the JSON Schema under "inputSchema" (or "parameters");
        // fall back to the raw array for tools that already return a bare schema.
        $schema = $arr['inputSchema'] ?? $arr['parameters'] ?? $arr;

        return $this->schema = \Aimeos\Prisma\Schema\Schema::fromArray( $this->name(), $schema );
    }


    /**
     * Extracts the payload from a Laravel MCP tool response.
     *
     * Laravel MCP tools return a ResponseFactory from handle(). Structured tools
     * expose their data via getStructuredContent(), while text tools provide one or
     * more Text responses; both are reduced to a value the model can consume.
     *
     * @param mixed $response Result returned by the tool's handle() method
     * @return mixed Structured content array, concatenated text, or the raw value
     */
    protected function unwrap( mixed $response ) : mixed
    {
        $class = '\Laravel\Mcp\ResponseFactory';

        if( $response instanceof $class )
        {
            if( ( $structured = $response->getStructuredContent() ) !== null ) { // @phpstan-ignore class.notFound
                return $structured;
            }

            $texts = [];
            $textClass = '\Laravel\Mcp\Server\Content\Text';

            foreach( $response->responses() as $resp ) // @phpstan-ignore class.notFound
            {
                if( !$resp->isNotification() && ( $content = $resp->content() ) instanceof $textClass ) {
                    $texts[] = (string) $content;
                }
            }

            return implode( "\n", $texts );
        }

        return $response;
    }
}
