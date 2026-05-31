<?php

namespace Aimeos\Prisma\Tools\Adapter;


/**
 * Adapts Symfony #[AsTool] classes to the Adapter interface.
 */
class Symfony extends Base
{
    private object $instance;
    private string $description;
    private string $method;
    private string $name;


    /**
     * Initializes the adapter from a class with an #[AsTool] attribute.
     *
     * @param object|string $tool Symfony tool object or class name
     * @param string|null $name Optional tool name to select a specific #[AsTool] attribute
     * @throws \InvalidArgumentException If the class has no matching #[AsTool] attribute
     */
    public function __construct( object|string $tool, ?string $name = null )
    {
        // @phpstan-ignore argument.type
        $ref = new \ReflectionClass( $tool );
        $attrs = array_filter( $ref->getAttributes(), fn( $a ) => str_ends_with( $a->getName(), 'AsTool' ) );

        if( empty( $attrs ) ) {
            throw new \InvalidArgumentException( sprintf( 'Class "%s" has no #[AsTool] attribute', is_object( $tool ) ? get_class( $tool ) : $tool ) );
        }

        foreach( $attrs as $attr )
        {
            /** @var array<string, string> $args */
            $args = $attr->getArguments();
            $toolName = $args['name'] ?? $args[0] ?? '';

            if( $name === null || $toolName === $name )
            {
                $this->instance = is_object( $tool ) ? $tool : $ref->newInstance();
                $this->name = $toolName;
                $this->description = $args['description'] ?? $args[1] ?? '';
                $this->method = $args['method'] ?? $args[2] ?? '__invoke';

                return;
            }
        }

        throw new \InvalidArgumentException( sprintf( 'Class "%s" has no #[AsTool] attribute with name "%s"', is_object( $tool ) ? get_class( $tool ) : $tool, $name ) );
    }


    protected function execute( array $arguments ) : mixed
    {
        return $this->instance->{$this->method}( ...$arguments );
    }


    /**
     * Returns the tool description.
     *
     * @return string Tool description
     */
    public function description() : string
    {
        return $this->description;
    }


    /**
     * Returns the tool name.
     *
     * @return string Tool name
     */
    public function name() : string
    {
        return $this->name;
    }


    /**
     * Returns the schema definition for the tool parameters.
     *
     * @return \Aimeos\Prisma\Schema\Schema Schema definition
     */
    public function schema() : \Aimeos\Prisma\Schema\Schema
    {
        $method = new \ReflectionMethod( $this->instance, $this->method );
        $properties = [];
        $required = [];
        $typeMap = [
            'string' => 'string',
            'int' => 'integer',
            'float' => 'number',
            'bool' => 'boolean',
            'array' => 'array',
        ];

        $descriptions = [];
        $doc = $method->getDocComment() ?: '';
        preg_match_all( '/@param\s+\S+\s+\$(\w+)\s+(.+)/', $doc, $matches, PREG_SET_ORDER );

        foreach( $matches as $match ) {
            $descriptions[$match[1]] = trim( $match[2] );
        }

        foreach( $method->getParameters() as $param )
        {
            $paramType = $param->getType();
            $typeName = $paramType instanceof \ReflectionNamedType ? $paramType->getName() : 'string';

            if( !isset( $typeMap[$typeName] ) && enum_exists( $typeName ) )
            {
                $properties[$param->getName()] = array_filter( [
                    'type' => 'string',
                    'enum' => array_column( $typeName::cases(), 'value' ),
                    'description' => $descriptions[$param->getName()] ?? null,
                ], fn( $v ) => $v !== null );
            }
            else
            {
                $prop = ['type' => $typeMap[$typeName] ?? 'string'];

                if( isset( $descriptions[$param->getName()] ) ) {
                    $prop['description'] = $descriptions[$param->getName()];
                }

                foreach( $param->getAttributes() as $attr )
                {
                    if( !str_ends_with( $attr->getName(), 'With' ) ) {
                        continue;
                    }

                    $with = $attr->getArguments();

                    if( isset( $with['pattern'] ) ) { $prop['pattern'] = $with['pattern']; }
                    if( isset( $with['minimum'] ) ) { $prop['minimum'] = $with['minimum']; }
                    if( isset( $with['maximum'] ) ) { $prop['maximum'] = $with['maximum']; }
                    if( isset( $with['enum'] ) ) { $prop['enum'] = $with['enum']; }
                }

                $properties[$param->getName()] = $prop;
            }

            if( !$param->isOptional() && !$param->allowsNull() ) {
                $required[] = $param->getName();
            }
        }

        $schema = array_filter( [
            'type' => 'object',
            'properties' => $properties ?: null,
            'required' => $required ?: null,
        ], fn( $v ) => $v !== null );

        return \Aimeos\Prisma\Schema\Schema::fromArray( $this->name, $schema );
    }
}
